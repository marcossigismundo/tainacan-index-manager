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

		$type = method_exists( $metadatum, 'get_metadata_type' ) ? (string) $metadatum->get_metadata_type() : '';
		if ( ! in_array( $type, self::SUPPORTED_TYPES, true ) ) {
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

		$terms_agg = array(
			'field' => 'metadata.value_keyword',
			'size'  => max( 1, $needed ),
			// Tainacan orders facet values by value ascending.
			'order' => array( '_key' => 'asc' ),
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
								'vals'     => array(
									'terms' => $terms_agg,
									'aggs'  => array(
										// Counts must be *items*, not nested metadata rows.
										'back' => array( 'reverse_nested' => new \stdClass() ),
									),
								),
								'distinct' => array(
									'cardinality' => array(
										'field'               => 'metadata.value_keyword',
										'precision_threshold' => 40000,
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

		// Exact when every distinct value fit in the requested window; otherwise fall
		// back to the cardinality estimate.
		$total = count( $buckets ) < $terms_agg['size']
			? count( $buckets )
			: (int) ( $agg['distinct']['value'] ?? count( $buckets ) );

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
