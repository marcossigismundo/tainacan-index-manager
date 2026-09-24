<?php
/**
 * Serve Tainacan facet values from Elasticsearch aggregations.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces Tainacan's SQL facet computation with a single ES aggregation.
 *
 * Tainacan builds a facet by running `SELECT DISTINCT meta_value` over the whole
 * postmeta table and then — for every single value it found — running another
 * full `Items::fetch()` just to read `found_posts`. On a collection with tens of
 * thousands of items that is one expensive JOIN per facet option, on every page
 * load, which is the dominant cost of opening a collection.
 *
 * One ES `terms` aggregation returns the same values *with* their item counts in
 * a single round trip.
 *
 * Scope: only metadata whose value is stored literally (Text, Textarea, Numeric,
 * Date, Selectbox and the core title/description). Taxonomy, Relationship, User
 * and Control metadata resolve their labels through other tables and carry
 * hierarchy semantics, so those are handed back to Tainacan untouched.
 */
final class Facets_Integration {

	/** Metadata types whose facet values can be reproduced from the index alone. */
	private const SUPPORTED_TYPES = array(
		'Tainacan\\Metadata_Types\\Text',
		'Tainacan\\Metadata_Types\\Textarea',
		'Tainacan\\Metadata_Types\\Numeric',
		'Tainacan\\Metadata_Types\\Date',
		'Tainacan\\Metadata_Types\\Selectbox',
		'Tainacan\\Metadata_Types\\Core_Title',
		'Tainacan\\Metadata_Types\\Core_Description',
	);

	private Settings $settings;
	private Logger $logger;
	private Search_Integration $search;
	private Elasticsearch_Client $client;

	public function __construct( Settings $settings, Logger $logger, Search_Integration $search ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->search   = $search;
		$this->client   = new Elasticsearch_Client( $settings, $logger );
	}

	public function register(): void {
		add_filter( 'tainacan-fetch-all-metadatum-values', array( $this, 'maybe_serve_from_index' ), 10, 3 );
	}

	/**
	 * @param mixed  $result    Null until someone short-circuits.
	 * @param object $metadatum Tainacan metadatum entity.
	 * @param array  $args      Facet arguments assembled by Tainacan.
	 * @return array|null Facet payload, or null to let Tainacan run its SQL.
	 */
	public function maybe_serve_from_index( $result, $metadatum, $args ) {
		if ( null !== $result ) {
			return $result;
		}

		try {
			return $this->serve( $metadatum, is_array( $args ) ? $args : array() );
		} catch ( \Throwable $e ) {
			$this->logger->warning( Logger::CHAN_FALLBACK, 'Faceta degradada para SQL.', array(
				'error' => $e->getMessage(),
			) );
			return null;
		}
	}

	/**
	 * @return array|null
	 */
	private function serve( $metadatum, array $args ): ?array {
		if ( ! is_object( $metadatum ) || ! method_exists( $metadatum, 'get_id' ) ) {
			return null;
		}
		if ( ! (bool) $this->settings->get( 'route_facets', true ) ) {
			return null;
		}
		if ( ! $this->search->engine_allows_routing() || ! $this->client->is_configured() ) {
			return null;
		}

		// Facet values are ordered alphabetically under MySQL's collation, and the
		// only faithful way to reproduce that here is ICU. Without `intl` an
		// approximation would reorder values — and therefore change which ones land
		// in the requested page — so hand the whole thing back to SQL instead.
		if ( ! self::collator() instanceof \Collator ) {
			return null;
		}

		$type = method_exists( $metadatum, 'get_metadata_type' ) ? (string) $metadatum->get_metadata_type() : '';
		if ( ! in_array( $type, self::SUPPORTED_TYPES, true ) ) {
			return null;
		}

		// A metadatum with more distinct values than the cap can never be served
		// from here (see the sum_other_doc_count check below). Some are effectively
		// unique per item — a registration number, say — so skip the aggregation
		// rather than run one whose result is unusable.
		if ( false !== get_transient( self::oversized_key( (int) $metadatum->get_id() ) ) ) {
			return null;
		}

		$items_filter = $args['items_filter'] ?? array();
		// `false` means "list every value, even those with no matching item"
		// (hideempty=0). The index only knows values that are actually on an item.
		if ( false === $items_filter ) {
			return null;
		}
		if ( ! is_array( $items_filter ) ) {
			return null;
		}

		// `include` forces specific values to the top of the list; reproducing that
		// ordering exactly is not worth the risk.
		if ( ! empty( $args['include'] ) ) {
			return null;
		}

		$post_types = $this->collection_post_types( $args['collection_id'] ?? null );
		if ( empty( $post_types ) ) {
			return null;
		}

		$es_query = ES_Query_Builder::build_from_args( $items_filter, $post_types, true );
		if ( null === $es_query ) {
			return null;
		}

		$offset = isset( $args['offset'] ) && $args['offset'] >= 0 ? (int) $args['offset'] : 0;
		$number = isset( $args['number'] ) && $args['number'] >= 1 ? (int) $args['number'] : 0;
		$cap    = (int) $this->settings->get( 'facet_max_terms', 300 );

		// A `terms` aggregation has no offset, so the window is emulated by asking
		// for offset+number buckets and slicing. Beyond the configured cap we defer
		// to SQL rather than pulling an unbounded number of buckets into memory.
		$needed = $number > 0 ? $offset + $number : $cap;
		if ( $needed > $cap ) {
			return null;
		}

		$search_term = trim( (string) ( $args['search'] ?? '' ) );
		$count_items = ! empty( $args['count_items'] );

		// Ask for the configured ceiling, not just the requested window.
		//
		// Tainacan orders facet values by `meta_value` under MySQL's
		// utf8mb4_unicode_520_ci collation; an ES `terms` aggregation orders keyword
		// buckets by raw UTF-8 byte value. Those two orders disagree, so slicing the
		// ES order would return a *different set* of values, not merely a different
		// arrangement. Pulling every distinct value and re-sorting it here in PHP
		// sidesteps that entirely — and is only valid while the whole value list fits
		// in one aggregation, which is checked against `sum_other_doc_count` below.
		$terms_agg = array(
			'field' => 'metadata.value_keyword',
			'size'  => max( 1, $cap ),
			// Order by count so the cap, if ever hit, keeps the most relevant values;
			// the final ordering is applied in PHP.
			'order' => array( '_count' => 'desc' ),
		);
		if ( '' !== $search_term ) {
			// SQL uses `meta_value LIKE %term%`; the keyword equivalent is a
			// case-insensitive contains-wildcard.
			$terms_agg['include'] = '.*' . self::escape_regex( $search_term ) . '.*';
		}

		$payload = array(
			'size'  => 0,
			'query' => $es_query,
			'aggs'  => array(
				'md' => array(
					'nested' => array( 'path' => 'metadata' ),
					'aggs'   => array(
						'f' => array(
							'filter' => array( 'term' => array( 'metadata.metadatum_id' => (int) $metadatum->get_id() ) ),
							'aggs'   => array(
								'vals' => array(
									'terms' => $terms_agg,
									'aggs'  => array(
										// Counts must be *items*, not nested metadata rows.
										'back' => array( 'reverse_nested' => new \stdClass() ),
									),
								),
							),
						),
					),
				),
			),
		);

		$index = (string) $this->settings->get( 'index_name' );
		$res   = $this->client->search( $index, $payload );
		if ( is_wp_error( $res ) ) {
			$this->logger->warning( Logger::CHAN_FALLBACK, 'Faceta degradada para SQL (erro no ES).', array(
				'metadatum_id' => (int) $metadatum->get_id(),
				'error'        => $res->get_error_message(),
			) );
			return null;
		}

		$agg = $res['aggregations']['md']['f'] ?? null;
		if ( ! is_array( $agg ) || ! isset( $agg['vals']['buckets'] ) || ! is_array( $agg['vals']['buckets'] ) ) {
			return null;
		}

		$buckets = $agg['vals']['buckets'];

		// If the aggregation had to leave values out, the list we can sort is not the
		// whole list, so any window we cut from it could differ from SQL's. Refuse —
		// and remember it, so the next request skips straight to SQL instead of
		// paying for an aggregation whose result we already know we cannot use.
		if ( ! empty( $agg['vals']['sum_other_doc_count'] ) ) {
			set_transient( self::oversized_key( (int) $metadatum->get_id() ), 1, HOUR_IN_SECONDS );
			return null;
		}

		// Every distinct value is present, so the count is exact.
		$total = count( $buckets );

		// Reproduce MySQL's `ORDER BY meta_value` under a case/accent-insensitive
		// Unicode collation, then cut the requested window from that order.
		usort(
			$buckets,
			static function ( $a, $b ) {
				return self::compare_values( (string) ( $a['key'] ?? '' ), (string) ( $b['key'] ?? '' ) );
			}
		);

		if ( $offset > 0 || $number > 0 ) {
			$buckets = array_slice( $buckets, $offset, $number > 0 ? $number : null );
		}

		$values = array();
		foreach ( $buckets as $bucket ) {
			if ( ! isset( $bucket['key'] ) ) {
				continue;
			}
			$key      = (string) $bucket['key'];
			$values[] = array(
				'label'       => $key,
				'value'       => $key,
				'total_items' => $count_items ? (int) ( $bucket['back']['doc_count'] ?? $bucket['doc_count'] ?? 0 ) : null,
				'type'        => 'Text',
			);
		}

		$pages = $number > 0 ? (int) ceil( $total / $number ) : 1;

		return array(
			'total'     => $total,
			'pages'     => max( 1, $pages ),
			'values'    => $values,
			'last_term' => $args['last_term'] ?? '',
		);
	}

	/**
	 * Post types covered by the facet's collection scope.
	 *
	 * @param mixed $collection_id Collection ID, or null for "all collections".
	 * @return string[]
	 */
	private function collection_post_types( $collection_id ): array {
		if ( ! class_exists( '\\Tainacan\\Repositories\\Collections' ) ) {
			return array();
		}

		try {
			// These fetches run WP_Query internally. Suspending routing keeps them
			// from re-entering Search_Integration through posts_pre_query, which
			// would recurse until PHP's memory limit is exhausted.
			return Search_Integration::without_routing( static function () use ( $collection_id ) {
				$repo = call_user_func( array( '\\Tainacan\\Repositories\\Collections', 'get_instance' ) );

				if ( ! empty( $collection_id ) && is_numeric( $collection_id ) ) {
					$collection = $repo->fetch( (int) $collection_id );
					if ( is_object( $collection ) && method_exists( $collection, 'get_db_identifier' ) ) {
						return array( (string) $collection->get_db_identifier() );
					}
					return array();
				}

				$types = array();
				$cols  = $repo->fetch( array( 'posts_per_page' => -1 ), 'OBJECT' );
				if ( is_array( $cols ) ) {
					foreach ( $cols as $c ) {
						if ( is_object( $c ) && method_exists( $c, 'get_db_identifier' ) ) {
							$types[] = (string) $c->get_db_identifier();
						}
					}
				}
				return array_values( array_filter( array_unique( $types ) ) );
			} );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Transient key marking a metadatum whose value list exceeds `facet_max_terms`.
	 */
	private static function oversized_key( int $metadatum_id ): string {
		return 'tim_facet_oversized_' . $metadatum_id;
	}

	/**
	 * Compare two facet values the way MySQL's collation would.
	 *
	 * `wp_postmeta.meta_value` is utf8mb4_unicode_520_ci, so ordering is
	 * case-insensitive and derives from the Unicode Collation Algorithm. PHP's
	 * `Collator` implements the same algorithm through ICU, which is what
	 * unicode_520 is built on, so it is used whenever `intl` is available.
	 *
	 * Without `intl` we fall back to comparing accent-folded, case-folded strings.
	 * That reproduces the collation for ordinary Latin text — which is what these
	 * facets hold — and ties are broken on the raw value so the order stays stable.
	 */
	private static function compare_values( string $a, string $b ): int {
		$collator = self::collator();
		if ( ! $collator instanceof \Collator ) {
			// serve() refuses to route without a collator, so this is unreachable;
			// kept so the comparator is never silently wrong if that changes.
			return strcmp( $a, $b );
		}

		$result = $collator->compare( $a, $b );
		if ( false === $result ) {
			return strcmp( $a, $b );
		}
		// Equal primary weights (e.g. "SAO" vs "São"): break the tie deterministically
		// so pagination cannot shuffle between requests.
		return 0 !== $result ? (int) $result : strcmp( $a, $b );
	}

	/**
	 * ICU collator for pt_BR, or null when the `intl` extension is unavailable.
	 *
	 * @return \Collator|null
	 */
	private static function collator() {
		static $collator = false;

		if ( false === $collator ) {
			$collator = null;
			if ( class_exists( '\\Collator' ) ) {
				try {
					$candidate = new \Collator( 'pt_BR' );
					// A broken ICU build returns a collator that fails on use.
					if ( false !== $candidate->compare( 'a', 'b' ) ) {
						$collator = $candidate;
					}
				} catch ( \Throwable $e ) {
					$collator = null;
				}
			}
		}

		return $collator;
	}

	/**
	 * Build a case-insensitive Lucene regexp fragment matching $term literally.
	 *
	 * Tainacan's SQL uses `meta_value LIKE %term%`, which is case-insensitive under
	 * the usual MySQL collation. Lucene regexps have no case-insensitive flag, so
	 * each cased letter becomes a two-option character class to keep the facet
	 * search behaving the same way.
	 */
	private static function escape_regex( string $term ): string {
		$specials = array( '\\', '.', '?', '+', '*', '|', '{', '}', '[', ']', '(', ')', '"', '#', '@', '&', '<', '>', '~' );
		$out      = '';

		// Multibyte-safe: accented characters must not be split into bytes.
		$chars = preg_split( '//u', $term, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			$chars = array();
		}

		foreach ( $chars as $char ) {
			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $char, 'UTF-8' ) : strtolower( $char );
			$upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $char, 'UTF-8' ) : strtoupper( $char );

			if ( $lower !== $upper && ! in_array( $char, $specials, true ) ) {
				$out .= '[' . $lower . $upper . ']';
				continue;
			}

			$out .= in_array( $char, $specials, true ) ? '\\' . $char : $char;
		}

		return $out;
	}
}
