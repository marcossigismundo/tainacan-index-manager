<?php
/**
 * Search integration: route Tainacan item queries through ES with SQL fallback.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Answers Tainacan item queries from the Elasticsearch index.
 *
 * This intercepts `posts_pre_query`, which short-circuits WP_Query *before* it
 * executes SQL. That matters because the expensive part of a Tainacan listing is
 * not the keyword match — it is the postmeta/term JOINs that back collection
 * browsing and facet filtering over tens of thousands of items.
 *
 * The previous implementation only handled `is_search()` queries carrying a
 * non-empty `s`, so plain collection browsing and facet filtering — the actual
 * hot path — always fell through to SQL.
 *
 * Fidelity rules:
 *  - Translation is all-or-nothing: {@see ES_Query_Builder} returns null for
 *    anything it cannot reproduce exactly, and we then let SQL run untouched.
 *  - Any ES failure degrades to SQL and raises the fallback flag.
 *  - When the engine is `elasticpress`, this class stands down completely.
 */
final class Search_Integration {

	private const FLAG_FALLBACK_ACTIVE = 'tainacan_idxmgr_fallback_active';

	/** ES refuses `from + size` beyond `index.max_result_window` (10k by default). */
	private const MAX_RESULT_WINDOW = 10000;

	private Settings $settings;
	private Logger $logger;
	private ElasticPress_Integration $elasticpress;
	private Elasticsearch_Client $client;

	/** Totals reported by ES, keyed by WP_Query object id, awaiting set_found_posts(). */
	private array $pending_totals = array();

	public function __construct( Settings $settings, Logger $logger, ElasticPress_Integration $elasticpress ) {
		$this->settings     = $settings;
		$this->logger       = $logger;
		$this->elasticpress = $elasticpress;
		$this->client       = new Elasticsearch_Client( $settings, $logger );
	}

	public function register(): void {
		add_filter( 'posts_pre_query', array( $this, 'maybe_answer_from_index' ), 10, 2 );
	}

	/**
	 * Answer a Tainacan item query from ES, or return null to let SQL run.
	 *
	 * @param array|null $posts Short-circuit value (null = WP runs its query).
	 * @param \WP_Query  $query Query being executed.
	 * @return array|null
	 */
	public function maybe_answer_from_index( $posts, $query ) {
		// Someone ahead of us already short-circuited; do not fight over it.
		if ( null !== $posts ) {
			return $posts;
		}
		if ( ! ( $query instanceof \WP_Query ) ) {
			return null;
		}

		try {
			return $this->answer( $query );
		} catch ( \Throwable $e ) {
			// A bug here must never take down a collection page.
			$this->mark_fallback( 'exception', $e->getMessage() );
			return null;
		}
	}

	/**
	 * @return array|null
	 */
	private function answer( \WP_Query $query ): ?array {
		if ( ! $this->should_handle( $query ) ) {
			return null;
		}

		$post_types = $this->query_post_types( $query );
		if ( empty( $post_types ) ) {
			return null;
		}

		$es_query = ES_Query_Builder::build( $query, $post_types );
		if ( null === $es_query ) {
			return null;
		}

		$sort = ES_Query_Builder::build_sort( $query );
		if ( null === $sort ) {
			return null;
		}

		$window = $this->resolve_window( $query );
		if ( null === $window ) {
			return null;
		}

		$payload = array(
			'from'             => $window['from'],
			'size'             => $window['size'],
			'_source'          => false,
			'track_total_hits' => true,
			'query'            => $es_query,
		);
		if ( ! empty( $sort ) ) {
			$payload['sort'] = $sort;
		}

		$index = (string) $this->settings->get( 'index_name' );
		$res   = $this->client->search( $index, $payload );
		if ( is_wp_error( $res ) ) {
			$this->mark_fallback( 'es_query_error', $res->get_error_message() );
			return null;
		}

		$hits = $res['hits']['hits'] ?? null;
		if ( ! is_array( $hits ) ) {
			$this->mark_fallback( 'es_malformed_response' );
			return null;
		}

		$ids = array();
		foreach ( $hits as $hit ) {
			if ( isset( $hit['_id'] ) ) {
				$ids[] = (int) $hit['_id'];
			}
		}

		$total = $res['hits']['total']['value'] ?? count( $ids );
		$total = (int) $total;

		$this->apply_found_posts( $query, $total, $window['size'] );
		$this->clear_fallback();

		if ( empty( $ids ) ) {
			return array();
		}

		// WP_Query maps whatever we return through get_post(). Priming the cache
		// here turns that into one query instead of one per row.
		_prime_post_caches( $ids, true, true );

		return $ids;
	}

	/**
	 * Gate: should this particular query be answered from the index?
	 */
	private function should_handle( \WP_Query $query ): bool {
		if ( $query->get( 'suppress_filters' ) ) {
			return false;
		}

		// Tainacan builds facet subqueries by short-circuiting posts_pre_query with
		// an empty array and reading only $query->request (the SQL string). Running
		// an ES query there would be wasted work, and the SQL string is what the
		// caller actually consumes.
		if ( has_filter( 'posts_pre_query', '__return_empty_array' ) ) {
			return false;
		}

		// `id=>parent` expects objects with a post_parent column; not served from ES.
		if ( 'id=>parent' === $query->get( 'fields' ) ) {
			return false;
		}

		if ( ! $this->engine_allows_routing() ) {
			return false;
		}
		if ( ! (bool) $this->settings->get( 'route_item_lists', true ) ) {
			// Keyword search may still be routed; plain browsing is opted out.
			if ( '' === trim( (string) $query->get( 's' ) ) ) {
				return false;
			}
		}
		if ( ! $this->client->is_configured() ) {
			$this->mark_fallback( 'es_not_configured' );
			return false;
		}

		return true;
	}

	/**
	 * Engine setting gate, shared by search and facet routing.
	 */
	public function engine_allows_routing(): bool {
		$engine = (string) $this->settings->get( 'engine', 'auto' );

		if ( 'elasticpress' === $engine ) {
			return false;
		}
		if ( 'auto' === $engine && $this->elasticpress->is_active() ) {
			return false;
		}
		if ( 'disabled' === $engine ) {
			$this->mark_fallback( 'engine_disabled' );
			return false;
		}

		return true;
	}

	/**
	 * Resolve which Tainacan item post types this query targets.
	 *
	 * @return string[]
	 */
	private function query_post_types( \WP_Query $query ): array {
		$requested = $query->get( 'post_type' );
		$known     = $this->collect_tainacan_post_types();
		if ( empty( $known ) ) {
			return array();
		}

		if ( empty( $requested ) || 'any' === $requested || ( is_array( $requested ) && in_array( 'any', $requested, true ) ) ) {
			// An unscoped query also covers pages, posts and Tainacan's own CPTs
			// (collections, taxonomies, metadata), which are not in the index.
			return array();
		}

		$requested = array_values( array_map( 'strval', (array) $requested ) );
		foreach ( $requested as $pt ) {
			if ( ! in_array( $pt, $known, true ) ) {
				// Mixed or non-item post types: the index cannot answer completely.
				return array();
			}
		}

		return $requested;
	}

	/**
	 * Translate WP pagination into an ES from/size window.
	 *
	 * @return array{from: int, size: int}|null
	 */
	private function resolve_window( \WP_Query $query ): ?array {
		$per_page = $query->get( 'posts_per_page' );
		$per_page = ( '' === $per_page || null === $per_page ) ? (int) get_option( 'posts_per_page', 10 ) : (int) $per_page;

		// `-1` / `nopaging` means "everything", which deep-paging in ES cannot serve
		// safely. Bulk exports are rare and better left to SQL.
		if ( $per_page <= 0 || $query->get( 'nopaging' ) ) {
			return null;
		}

		$offset = $query->get( 'offset' );
		if ( '' !== $offset && null !== $offset ) {
			$from = max( 0, (int) $offset );
		} else {
			$paged = max( 1, (int) $query->get( 'paged' ) );
			$from  = ( $paged - 1 ) * $per_page;
		}

		if ( $from + $per_page > self::MAX_RESULT_WINDOW ) {
			// Deep pagination beyond the result window: let SQL handle it rather than
			// returning an ES error to the visitor.
			return null;
		}

		return array(
			'from' => $from,
			'size' => $per_page,
		);
	}

	/**
	 * Publish the ES total onto the query.
	 *
	 * For default `fields`, WP_Query never calls set_found_posts() once
	 * posts_pre_query returns, so the values are assigned directly. For
	 * `fields => 'ids'` it *does* call it — and would run `SELECT FOUND_ROWS()`
	 * against a query that never ran — so the two filters below neutralise that
	 * and hand back the ES total instead.
	 */
	private function apply_found_posts( \WP_Query $query, int $total, int $per_page ): void {
		if ( $query->get( 'no_found_rows' ) ) {
			return;
		}

		$query->found_posts   = $total;
		$query->max_num_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$this->pending_totals[ spl_object_id( $query ) ] = $total;

		add_filter( 'found_posts_query', array( $this, 'filter_found_posts_query' ), 10, 2 );
		add_filter( 'found_posts', array( $this, 'filter_found_posts' ), 10, 2 );
	}

	/**
	 * Suppress the FOUND_ROWS() lookup for queries we answered ourselves.
	 *
	 * @param string    $sql   Query WP intends to run.
	 * @param \WP_Query $query Query instance.
	 * @return string
	 */
	public function filter_found_posts_query( $sql, $query ) {
		if ( $query instanceof \WP_Query && isset( $this->pending_totals[ spl_object_id( $query ) ] ) ) {
			return '';
		}
		return $sql;
	}

	/**
	 * Return the ES total for queries we answered ourselves.
	 *
	 * @param int       $found_posts WP's own count.
	 * @param \WP_Query $query       Query instance.
	 * @return int
	 */
	public function filter_found_posts( $found_posts, $query ) {
		if ( ! ( $query instanceof \WP_Query ) ) {
			return $found_posts;
		}
		$key = spl_object_id( $query );
		if ( ! isset( $this->pending_totals[ $key ] ) ) {
			return $found_posts;
		}

		$total = (int) $this->pending_totals[ $key ];
		unset( $this->pending_totals[ $key ] );

		return $total;
	}

	/**
	 * @return string[]
	 */
	private function collect_tainacan_post_types(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$types = array();
		if ( class_exists( '\\Tainacan\\Repositories\\Collections' ) ) {
			try {
				$repo = call_user_func( array( '\\Tainacan\\Repositories\\Collections', 'get_instance' ) );
				$cols = $repo->fetch( array( 'posts_per_page' => -1 ), 'OBJECT' );
				if ( is_array( $cols ) ) {
					foreach ( $cols as $c ) {
						if ( is_object( $c ) && method_exists( $c, 'get_db_identifier' ) ) {
							$types[] = (string) $c->get_db_identifier();
						}
					}
				}
			} catch ( \Throwable $e ) {
				// best effort.
			}
		}

		$cache = array_values( array_filter( array_unique( $types ) ) );
		return $cache;
	}

	private function mark_fallback( string $reason, string $detail = '' ): void {
		set_transient( self::FLAG_FALLBACK_ACTIVE, array(
			'since'  => time(),
			'reason' => $reason,
			'detail' => $detail,
		), HOUR_IN_SECONDS );
		$this->logger->warning( Logger::CHAN_FALLBACK, 'Busca degradada para SQL.', array(
			'reason' => $reason,
			'detail' => $detail,
		) );
	}

	private function clear_fallback(): void {
		if ( false !== get_transient( self::FLAG_FALLBACK_ACTIVE ) ) {
			delete_transient( self::FLAG_FALLBACK_ACTIVE );
		}
	}

	public function is_fallback_active(): bool {
		return false !== get_transient( self::FLAG_FALLBACK_ACTIVE );
	}
}
