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

	/**
	 * Routing suspended while we are resolving our own dependencies.
	 *
	 * Answering a query needs the list of Tainacan collection post types, and
	 * fetching that list runs a WP_Query of its own — which fires
	 * `posts_pre_query` right back into this class. Without this guard that is an
	 * unbounded recursion that exhausts PHP's memory limit before anything is
	 * returned. Static because a nested query may cross object boundaries
	 * (facets -> search).
	 */
	private static bool $suspended = false;

	/**
	 * Run $fn with ES routing suspended, so any WP_Query it triggers goes to SQL.
	 *
	 * Restores the previous state rather than clearing it, so nested calls are safe.
	 *
	 * @param callable $fn Work to run.
	 * @return mixed Whatever $fn returns.
	 */
	public static function without_routing( callable $fn ) {
		$previous        = self::$suspended;
		self::$suspended = true;
		try {
			return $fn();
		} finally {
			self::$suspended = $previous;
		}
	}

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
		// Already answering a query: this one is our own lookup, let SQL serve it.
		if ( self::$suspended ) {
			return null;
		}

		try {
			return self::without_routing( function () use ( $query ) {
				return $this->answer( $query );
			} );
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
	 * A query pede um post específico (por slug, por ID ou por lista de slugs)?
	 *
	 * Vale tanto para a query principal de um permalink quanto para queries
	 * secundárias montadas à mão, que não têm as flags is_singular definidas.
	 */
	private function is_single_post_lookup( \WP_Query $query ): bool {
		if ( $query->is_singular() || $query->is_attachment() ) {
			return true;
		}

		if ( '' !== trim( (string) $query->get( 'name' ) ) ) {
			return true;
		}

		if ( '' !== trim( (string) $query->get( 'pagename' ) ) ) {
			return true;
		}

		if ( (int) $query->get( 'p' ) > 0 || (int) $query->get( 'page_id' ) > 0 ) {
			return true;
		}

		$name_in = $query->get( 'post_name__in' );
		if ( ! empty( $name_in ) ) {
			return true;
		}

		return false;
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

		// Um permalink de item resolve por post_name (ou por ID). O índice responde
		// por relevância e ordenação — ele não conhece esses filtros, então
		// respondia a lista inteira da coleção e o WP ficava com o primeiro
		// resultado. Na prática qualquer slug abaixo de /{colecao}/ devolvia sempre
		// o mesmo item, e o permalink correto do item nunca era honrado.
		if ( $this->is_single_post_lookup( $query ) ) {
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
		if ( Settings::ENGINE_SQL === $this->settings->engine() ) {
			return false;
		}

		// Safety, not a preference: ElasticPress rewrites the same queries, and two
		// plugins short-circuiting one WP_Query produce whichever result happens to
		// run first. Deliberately silent — this is evaluated on every query, so
		// logging it would flood the log. The health snapshot surfaces the state.
		if ( $this->elasticpress->is_active() ) {
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

		if ( empty( $requested ) || 'any' === $requested || ( is_array( $requested ) && in_array( 'any', $requested, true ) ) ) {
			// An unscoped query also covers pages, posts and Tainacan's own CPTs
			// (collections, taxonomies, metadata), which are not in the index.
			return array();
		}

		$requested = array_values( array_map( 'strval', (array) $requested ) );

		// Cheap shape test first. Collection item post types are always
		// `tnc_col_{id}_item`, so anything else (posts, pages, attachments,
		// `tainacan-collection`, ...) is rejected without touching the database.
		foreach ( $requested as $pt ) {
			if ( ! preg_match( '/^tnc_col_\d+_item$/', $pt ) ) {
				return array();
			}
		}

		$known = $this->collect_tainacan_post_types();
		if ( empty( $known ) ) {
			return array();
		}

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

		// The entry keeps a reference to the query object itself, for two reasons:
		// it pins the object so `spl_object_id()` cannot hand the same id to a later
		// query, and it lets the filters below confirm identity before answering.
		// Without that, a leaked entry (see below) would feed one query's total to
		// an unrelated one.
		//
		// Entries do leak: for the default `fields`, WP_Query never calls
		// set_found_posts() once posts_pre_query returns, so filter_found_posts()
		// never fires to clean up. That is harmless as long as identity is checked.
		$this->pending_totals[ spl_object_id( $query ) ] = array(
			'total' => $total,
			'query' => $query,
		);

		add_filter( 'found_posts_query', array( $this, 'filter_found_posts_query' ), 10, 2 );
		add_filter( 'found_posts', array( $this, 'filter_found_posts' ), 10, 2 );
	}

	/**
	 * Total we recorded for exactly this query object, or null.
	 *
	 * @param \WP_Query $query Query instance.
	 */
	private function pending_total_for( \WP_Query $query ): ?int {
		$key = spl_object_id( $query );
		if ( ! isset( $this->pending_totals[ $key ] ) ) {
			return null;
		}

		$entry = $this->pending_totals[ $key ];
		// Identity, not just a matching id — ids are recycled.
		if ( ! isset( $entry['query'] ) || $entry['query'] !== $query ) {
			return null;
		}

		return (int) $entry['total'];
	}

	/**
	 * Suppress the FOUND_ROWS() lookup for queries we answered ourselves.
	 *
	 * @param string    $sql   Query WP intends to run.
	 * @param \WP_Query $query Query instance.
	 * @return string
	 */
	public function filter_found_posts_query( $sql, $query ) {
		if ( $query instanceof \WP_Query && null !== $this->pending_total_for( $query ) ) {
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

		$total = $this->pending_total_for( $query );
		if ( null === $total ) {
			return $found_posts;
		}

		unset( $this->pending_totals[ spl_object_id( $query ) ] );

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
				// This fetch runs a WP_Query; without_routing() keeps it from
				// re-entering this class through posts_pre_query.
				$types = self::without_routing( static function () {
					$found = array();
					$repo  = call_user_func( array( '\\Tainacan\\Repositories\\Collections', 'get_instance' ) );
					$cols  = $repo->fetch( array( 'posts_per_page' => -1 ), 'OBJECT' );
					if ( is_array( $cols ) ) {
						foreach ( $cols as $c ) {
							if ( is_object( $c ) && method_exists( $c, 'get_db_identifier' ) ) {
								$found[] = (string) $c->get_db_identifier();
							}
						}
					}
					return $found;
				} );
			} catch ( \Throwable $e ) {
				// best effort.
				$types = array();
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
