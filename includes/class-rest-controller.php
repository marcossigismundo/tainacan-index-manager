<?php
/**
 * REST API endpoints used by the admin panel (Vue) to drive the plugin.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin REST routes under the `tainacan-index-manager/v1` namespace.
 *
 * Authentication: every route uses cookie auth + WP-REST nonce
 * (X-WP-Nonce). Permission callback requires `manage_options`.
 */
final class REST_Controller {

	public const NAMESPACE = 'tainacan-index-manager/v1';

	private Settings $settings;
	private Health_Service $health;
	private Indexer $indexer;
	private Index_Manager $index_manager;
	private Collections_Monitor $collections;
	private ElasticPress_Integration $elasticpress;
	private Logger $logger;
	private Alerts $alerts;
	private Indexer_Metrics $metrics;

	public function __construct(
		Settings $settings,
		Health_Service $health,
		Indexer $indexer,
		Index_Manager $index_manager,
		Collections_Monitor $collections,
		ElasticPress_Integration $elasticpress,
		Logger $logger,
		Alerts $alerts,
		Indexer_Metrics $metrics
	) {
		$this->settings      = $settings;
		$this->health        = $health;
		$this->indexer       = $indexer;
		$this->index_manager = $index_manager;
		$this->collections   = $collections;
		$this->elasticpress  = $elasticpress;
		$this->logger        = $logger;
		$this->alerts        = $alerts;
		$this->metrics       = $metrics;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$auth = array( $this, 'auth' );

		register_rest_route( self::NAMESPACE, '/health', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'rest_get_health' ),
			'permission_callback' => $auth,
			'args'                => array(
				'refresh' => array(
					'required'          => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/collections', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'rest_get_collections' ),
			'permission_callback' => $auth,
			'args'                => array(
				'refresh' => array(
					'required'          => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/settings', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_settings' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_update_settings' ),
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( self::NAMESPACE, '/test-connection', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_test_connection' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/index/create', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_index_create' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/index/delete', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_index_delete' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/index/recreate', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_index_recreate' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/index/reindex-all', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_reindex_all' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/index/reindex-collection', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_reindex_collection' ),
			'permission_callback' => $auth,
			'args'                => array(
				'collection_id' => array(
					'required'          => true,
					'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/index/enqueue-pending', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_enqueue_pending' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/index/process-batch', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_process_batch' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/index/state', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'rest_index_state' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/index/pause', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_index_pause' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/index/resume', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_index_resume' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/index/cancel', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_index_cancel' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/logs', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'rest_get_logs' ),
			'permission_callback' => $auth,
			'args'                => array(
				'page'     => array( 'required' => false, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
				'level'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'channel'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/logs/clear', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_clear_logs' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/logs/export', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'rest_export_logs' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/alerts', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'rest_get_alerts' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/alerts/clear', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_clear_alerts' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/metrics', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'rest_get_metrics' ),
			'permission_callback' => $auth,
			'args'                => array(
				'window' => array(
					'required'          => false,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/metrics/reset', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_reset_metrics' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/elasticpress', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'rest_get_elasticpress' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE, '/elasticpress/sync', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_elasticpress_sync' ),
			'permission_callback' => $auth,
		) );
	}

	public function auth(): bool {
		return current_user_can( 'manage_options' );
	}

	public function rest_get_health( \WP_REST_Request $req ): \WP_REST_Response {
		$refresh = (bool) $req->get_param( 'refresh' );
		$snap    = $refresh ? $this->health->refresh_snapshot() : $this->health->get_snapshot();
		return rest_ensure_response( $snap );
	}

	public function rest_get_collections( \WP_REST_Request $req ): \WP_REST_Response {
		$refresh = (bool) $req->get_param( 'refresh' );
		return rest_ensure_response( $this->collections->get_report( $refresh ) );
	}

	public function rest_get_settings(): \WP_REST_Response {
		return rest_ensure_response( $this->settings->to_public_array() );
	}

	public function rest_update_settings( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $req->get_params();
		}

		// Skip secret fields when the client sent the masked placeholder.
		foreach ( array( 'es_password', 'es_api_key' ) as $secret ) {
			if ( isset( $body[ $secret ] ) && '__set__' === $body[ $secret ] ) {
				unset( $body[ $secret ] );
			}
		}

		$ok = $this->settings->update( is_array( $body ) ? $body : array() );
		$this->logger->info( Logger::CHAN_ADMIN, 'Configurações atualizadas.' );

		return rest_ensure_response( array(
			'ok'       => $ok,
			'settings' => $this->settings->to_public_array(),
		) );
	}

	public function rest_test_connection(): \WP_REST_Response {
		$res = $this->index_manager->test_connection();
		$this->logger->info( Logger::CHAN_ADMIN, 'Teste de conexão executado.', array(
			'ok'   => $res['ok'],
			'ms'   => $res['ms'],
			'code' => $res['code'],
		) );
		return rest_ensure_response( $res );
	}

	public function rest_index_create(): \WP_REST_Response {
		$res = $this->index_manager->create_index();
		if ( is_wp_error( $res ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => $res->get_error_message() ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function rest_index_delete(): \WP_REST_Response {
		$res = $this->index_manager->delete_index();
		if ( is_wp_error( $res ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => $res->get_error_message() ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function rest_index_recreate(): \WP_REST_Response {
		$res = $this->index_manager->recreate_index();
		if ( is_wp_error( $res ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => $res->get_error_message() ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function rest_reindex_all(): \WP_REST_Response {
		$count = $this->indexer->enqueue_all();
		return rest_ensure_response( array( 'ok' => true, 'enqueued' => $count ) );
	}

	public function rest_reindex_collection( \WP_REST_Request $req ): \WP_REST_Response {
		$id    = (int) $req->get_param( 'collection_id' );
		$count = $this->indexer->enqueue_collection( $id );
		return rest_ensure_response( array( 'ok' => true, 'enqueued' => $count, 'collection_id' => $id ) );
	}

	public function rest_enqueue_pending(): \WP_REST_Response {
		$count = $this->indexer->enqueue_missing();
		return rest_ensure_response( array( 'ok' => true, 'enqueued' => $count ) );
	}

	public function rest_process_batch(): \WP_REST_Response {
		$res = $this->indexer->process_batch();
		return rest_ensure_response( $res );
	}

	public function rest_index_state(): \WP_REST_Response {
		return rest_ensure_response( array(
			'state'       => $this->indexer->get_state(),
			'queue_size'  => $this->indexer->queue_size(),
			'failures'    => $this->indexer->failure_count(),
		) );
	}

	public function rest_index_pause(): \WP_REST_Response {
		$this->indexer->pause();
		return rest_ensure_response( array( 'ok' => true, 'state' => $this->indexer->get_state() ) );
	}

	public function rest_index_resume(): \WP_REST_Response {
		$this->indexer->resume();
		return rest_ensure_response( array( 'ok' => true, 'state' => $this->indexer->get_state() ) );
	}

	public function rest_index_cancel(): \WP_REST_Response {
		$this->indexer->cancel();
		return rest_ensure_response( array( 'ok' => true, 'state' => $this->indexer->get_state() ) );
	}

	public function rest_get_logs( \WP_REST_Request $req ): \WP_REST_Response {
		$args = array(
			'page'     => max( 1, (int) $req->get_param( 'page' ) ?: 1 ),
			'per_page' => max( 1, min( 200, (int) $req->get_param( 'per_page' ) ?: 50 ) ),
			'level'    => (string) $req->get_param( 'level' ),
			'channel'  => (string) $req->get_param( 'channel' ),
		);
		$rows  = $this->logger->fetch( $args );
		$total = $this->logger->count( $args );
		return rest_ensure_response( array(
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $args['page'],
			'per_page' => $args['per_page'],
		) );
	}

	public function rest_clear_logs(): \WP_REST_Response {
		$ok = $this->logger->truncate();
		return rest_ensure_response( array( 'ok' => $ok ) );
	}

	public function rest_export_logs(): \WP_REST_Response {
		$rows = $this->logger->fetch( array( 'per_page' => 1000, 'page' => 1 ) );
		return rest_ensure_response( array(
			'generated_at' => time(),
			'rows'         => $rows,
		) );
	}

	public function rest_get_alerts(): \WP_REST_Response {
		return rest_ensure_response( array(
			'alerts' => array_values( $this->alerts->all() ),
			'count'  => $this->alerts->count(),
		) );
	}

	public function rest_clear_alerts(): \WP_REST_Response {
		$this->alerts->clear_all();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function rest_get_metrics( \WP_REST_Request $req ): \WP_REST_Response {
		$window = (int) $req->get_param( 'window' );
		if ( $window <= 0 ) {
			$window = 10;
		}
		return rest_ensure_response( array(
			'state'      => $this->indexer->get_state(),
			'queue_size' => $this->indexer->queue_size(),
			'failures'   => $this->indexer->failure_count(),
			'summary'    => $this->metrics->summary( $this->indexer->queue_size(), $window ),
		) );
	}

	public function rest_reset_metrics(): \WP_REST_Response {
		$this->metrics->reset();
		$this->logger->info( Logger::CHAN_ADMIN, 'Métricas da indexação zeradas pelo administrador.' );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function rest_get_elasticpress(): \WP_REST_Response {
		return rest_ensure_response( $this->elasticpress->snapshot() );
	}

	public function rest_elasticpress_sync(): \WP_REST_Response {
		$ok = $this->elasticpress->trigger_sync();
		return rest_ensure_response( array( 'ok' => $ok ) );
	}
}
