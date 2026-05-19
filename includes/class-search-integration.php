<?php
/**
 * Search integration: route Tainacan/WP searches through ES with SQL fallback.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Optional search routing.
 *
 * When the configured engine is `own_indexer` (or `auto` and ElasticPress is
 * absent), this filter intercepts the main WP_Query search for Tainacan post
 * types, queries the plugin's ES index, and rewrites the query's `post__in`
 * to the matched IDs in the matched order.
 *
 * If ES fails for any reason, we silently let the original SQL search run
 * (fallback) and log + raise an alert.
 *
 * When the engine is `elasticpress`, this class stands down completely —
 * ElasticPress already owns the WP_Query plumbing and we MUST NOT interfere.
 */
final class Search_Integration {

	private const FLAG_FALLBACK_ACTIVE = 'tainacan_idxmgr_fallback_active';

	private Settings $settings;
	private Logger $logger;
	private ElasticPress_Integration $elasticpress;
	private Elasticsearch_Client $client;

	public function __construct( Settings $settings, Logger $logger, ElasticPress_Integration $elasticpress ) {
		$this->settings     = $settings;
		$this->logger       = $logger;
		$this->elasticpress = $elasticpress;
		$this->client       = new Elasticsearch_Client( $settings, $logger );
	}

	public function register(): void {
		add_filter( 'pre_get_posts', array( $this, 'maybe_route_search' ), 20 );
	}

	/**
	 * Decide whether to handle the current search; if so, rewrite the query.
	 *
	 * @param \WP_Query $query Main query.
	 */
	public function maybe_route_search( $query ): void {
		if ( ! ( $query instanceof \WP_Query ) ) {
			return;
		}
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( ! $query->is_search() ) {
			return;
		}

		$s = (string) $query->get( 's' );
		if ( '' === trim( $s ) ) {
			return;
		}

		$engine_choice = (string) $this->settings->get( 'engine', 'auto' );

		// Defer entirely to ElasticPress when chosen.
		if ( 'elasticpress' === $engine_choice ) {
			return;
		}
		if ( 'auto' === $engine_choice && $this->elasticpress->is_active() ) {
			return;
		}
		if ( 'disabled' === $engine_choice ) {
			$this->mark_fallback( 'engine_disabled' );
			return;
		}

		if ( ! $this->client->is_configured() ) {
			$this->mark_fallback( 'es_not_configured' );
			return;
		}

		$post_types = (array) $query->get( 'post_type' );
		if ( empty( $post_types ) || in_array( 'any', $post_types, true ) ) {
			$post_types = $this->collect_tainacan_post_types();
		}
		if ( empty( $post_types ) ) {
			return;
		}

		$index   = (string) $this->settings->get( 'index_name' );
		$payload = array(
			'size'    => 200,
			'_source' => false,
			'query'   => array(
				'bool' => array(
					'must'   => array(
						array(
							'multi_match' => array(
								'query'    => $s,
								'fields'   => array( 'title^3', 'description^2', 'content', 'metadata.value_text', 'taxonomies.terms' ),
								'operator' => 'and',
							),
						),
					),
					'filter' => array(
						array( 'terms' => array( 'post_type' => array_values( array_map( 'strval', $post_types ) ) ) ),
					),
				),
			),
		);

		$res = $this->client->search( $index, $payload );
		if ( is_wp_error( $res ) ) {
			$this->mark_fallback( 'es_query_error', $res->get_error_message() );
			return;
		}

		$hits = isset( $res['hits']['hits'] ) && is_array( $res['hits']['hits'] ) ? $res['hits']['hits'] : array();
		$ids  = array();
		foreach ( $hits as $hit ) {
			if ( isset( $hit['_id'] ) ) {
				$ids[] = (int) $hit['_id'];
			}
		}

		if ( empty( $ids ) ) {
			// Force no-results: empty post__in returns nothing, which matches user intent.
			$query->set( 'post__in', array( 0 ) );
			$query->set( 's', '' );
			return;
		}

		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( 's', '' );
		$this->clear_fallback();
	}

	private function collect_tainacan_post_types(): array {
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
		return array_filter( array_unique( $types ) );
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
