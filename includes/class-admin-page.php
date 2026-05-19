<?php
/**
 * Admin pages bootstrap.
 *
 * Two integration paths:
 *
 * 1) Tainacan 1.0.0+ is present (\Tainacan\Pages exists):
 *    we load the two \Tainacan\TIM_*_Page classes (Dashboard + Settings).
 *    Both extend \Tainacan\Pages, get rendered inside Tainacan's native
 *    page chrome (sidebar + header + theme), and register themselves
 *    in the Tainacan admin sidebar via add_submenu_page() under
 *    $tainacan_root_menu_slug / $tainacan_other_links_slug.
 *
 * 2) Tainacan is absent or pre-1.0.0:
 *    we fall back to a standalone top-level menu. The plugin still works,
 *    but visual integration with Tainacan is limited.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {

	public const DASHBOARD_SLUG = 'tainacan_idxmgr_dashboard';
	public const SETTINGS_SLUG  = 'tainacan_idxmgr_settings';

	private Settings $settings;
	private Health_Service $health;
	private Logger $logger;
	private Alerts $alerts;

	public function __construct( Settings $settings, Health_Service $health, Logger $logger, Alerts $alerts ) {
		$this->settings = $settings;
		$this->health   = $health;
		$this->logger   = $logger;
		$this->alerts   = $alerts;
	}

	public function register(): void {
		if ( $this->tainacan_pages_available() ) {
			// Tainacan 1.0.0+ — load native page classes. They self-register via Singleton_Instance.
			require_once TAINACAN_INDEX_MANAGER_DIR . 'includes/tainacan-pages/class-dashboard-page.php';
			require_once TAINACAN_INDEX_MANAGER_DIR . 'includes/tainacan-pages/class-settings-page.php';
			return;
		}

		// Fallback path: standalone WP menu + warning notice.
		add_action( 'admin_menu', array( $this, 'register_fallback_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_fallback_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_compat_notice' ) );
	}

	private function tainacan_pages_available(): bool {
		return class_exists( '\\Tainacan\\Pages' )
			&& trait_exists( '\\Tainacan\\Traits\\Singleton_Instance' );
	}

	/**
	 * JS config passed to the Vue SPA. Shared between Tainacan and fallback paths.
	 *
	 * @param string $view 'dashboard' or 'settings'.
	 */
	public static function js_config( string $view ): array {
		return array(
			'restRoot'   => esc_url_raw( rest_url( REST_Controller::NAMESPACE ) ),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'pluginUrl'  => esc_url_raw( TAINACAN_INDEX_MANAGER_URL ),
			'view'       => $view,
			'dashUrl'    => esc_url_raw( admin_url( 'admin.php?page=' . self::DASHBOARD_SLUG ) ),
			'settingsUrl' => esc_url_raw( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			'tainacanIntegrated' => class_exists( '\\Tainacan\\Pages' ),
			'i18n'       => self::i18n_strings(),
		);
	}

	/* --------- Fallback path (Tainacan absent) --------- */

	public function register_fallback_menu(): void {
		add_menu_page(
			__( 'Saúde da Busca', 'tainacan-index-manager' ),
			__( 'Saúde da Busca', 'tainacan-index-manager' ),
			'manage_options',
			self::DASHBOARD_SLUG,
			array( $this, 'render_fallback_dashboard' ),
			'dashicons-chart-line',
			58
		);
		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Configurações de Indexação', 'tainacan-index-manager' ),
			__( 'Configurações', 'tainacan-index-manager' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_fallback_settings' )
		);
	}

	public function render_fallback_dashboard(): void {
		echo '<div class="wrap tainacan-idxmgr-wrap is-standalone">';
		echo '<h1 class="tim-title">' . esc_html__( 'Saúde da Busca', 'tainacan-index-manager' ) . '</h1>';
		echo '<div id="tainacan-idxmgr-app" data-view="dashboard"></div>';
		echo '</div>';
	}

	public function render_fallback_settings(): void {
		echo '<div class="wrap tainacan-idxmgr-wrap is-standalone">';
		echo '<h1 class="tim-title">' . esc_html__( 'Configurações de Indexação', 'tainacan-index-manager' ) . '</h1>';
		echo '<div id="tainacan-idxmgr-app" data-view="settings"></div>';
		echo '</div>';
	}

	public function enqueue_fallback_assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page-slug check; no state mutation.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( self::DASHBOARD_SLUG, self::SETTINGS_SLUG ), true ) ) {
			return;
		}
		$view = self::SETTINGS_SLUG === $page ? 'settings' : 'dashboard';

		wp_enqueue_style(
			'tainacan-idxmgr-admin',
			TAINACAN_INDEX_MANAGER_URL . 'assets/css/admin.css',
			array(),
			TAINACAN_INDEX_MANAGER_VERSION
		);
		wp_register_script(
			'tainacan-idxmgr-vue',
			TAINACAN_INDEX_MANAGER_URL . 'assets/vendor/vue/vue.global.prod.js',
			array(),
			'3.4.27',
			true
		);
		wp_register_script(
			'tainacan-idxmgr-admin',
			TAINACAN_INDEX_MANAGER_URL . 'assets/js/admin.js',
			array( 'tainacan-idxmgr-vue' ),
			TAINACAN_INDEX_MANAGER_VERSION,
			true
		);
		wp_localize_script( 'tainacan-idxmgr-admin', 'TIMConfig', self::js_config( $view ) );
		wp_enqueue_script( 'tainacan-idxmgr-vue' );
		wp_enqueue_script( 'tainacan-idxmgr-admin' );
		wp_set_script_translations( 'tainacan-idxmgr-admin', 'tainacan-index-manager' );
	}

	public function render_compat_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::DASHBOARD_SLUG ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'Tainacan Index Manager', 'tainacan-index-manager' )
			. ':</strong> '
			. esc_html__( 'O plugin Tainacan 1.0.0+ não foi detectado. Estamos exibindo a interface em modo standalone; para integração total com o admin do Tainacan, atualize/ative o Tainacan.', 'tainacan-index-manager' )
			. '</p></div>';
	}

	/**
	 * Translation strings exposed to the Vue SPA.
	 */
	private static function i18n_strings(): array {
		return array(
			'dashboard'          => __( 'Saúde da Busca', 'tainacan-index-manager' ),
			'settings'           => __( 'Configurações de Indexação', 'tainacan-index-manager' ),
			'logs'               => __( 'Logs', 'tainacan-index-manager' ),
			'alerts'             => __( 'Alertas', 'tainacan-index-manager' ),
			'metrics'            => __( 'Métricas da Indexação', 'tainacan-index-manager' ),
			'overview'           => __( 'Visão geral', 'tainacan-index-manager' ),
			'cluster'            => __( 'Cluster', 'tainacan-index-manager' ),
			'index'              => __( 'Índice', 'tainacan-index-manager' ),
			'coverage'           => __( 'Cobertura', 'tainacan-index-manager' ),
			'divergence'         => __( 'Divergência', 'tainacan-index-manager' ),
			'collections'        => __( 'Coleções', 'tainacan-index-manager' ),
			'response_time'      => __( 'Tempo de resposta', 'tainacan-index-manager' ),
			'tainacan_total'     => __( 'Itens no Tainacan', 'tainacan-index-manager' ),
			'indexed_total'      => __( 'Documentos indexados', 'tainacan-index-manager' ),
			'last_check'         => __( 'Última verificação', 'tainacan-index-manager' ),
			'last_index'         => __( 'Última indexação', 'tainacan-index-manager' ),
			'effective_engine'   => __( 'Mecanismo ativo', 'tainacan-index-manager' ),
			'elasticpress'       => __( 'ElasticPress', 'tainacan-index-manager' ),
			'own_indexer'        => __( 'Indexador próprio', 'tainacan-index-manager' ),
			'sql_fallback'       => __( 'Fallback SQL', 'tainacan-index-manager' ),
			'engine_disabled'    => __( 'Desativado', 'tainacan-index-manager' ),
			'refresh'            => __( 'Atualizar', 'tainacan-index-manager' ),
			'test_connection'    => __( 'Testar conexão', 'tainacan-index-manager' ),
			'create_index'       => __( 'Criar índice', 'tainacan-index-manager' ),
			'delete_index'       => __( 'Apagar índice', 'tainacan-index-manager' ),
			'recreate_index'     => __( 'Recriar índice', 'tainacan-index-manager' ),
			'reindex_all'        => __( 'Indexar tudo', 'tainacan-index-manager' ),
			'reindex_pending'    => __( 'Indexar pendentes', 'tainacan-index-manager' ),
			'reindex_collection' => __( 'Indexar coleção', 'tainacan-index-manager' ),
			'process_batch'      => __( 'Processar lote', 'tainacan-index-manager' ),
			'pause'              => __( 'Pausar', 'tainacan-index-manager' ),
			'resume'             => __( 'Retomar', 'tainacan-index-manager' ),
			'cancel'             => __( 'Cancelar', 'tainacan-index-manager' ),
			'save'               => __( 'Salvar', 'tainacan-index-manager' ),
			'clear_logs'         => __( 'Limpar logs', 'tainacan-index-manager' ),
			'export_logs'        => __( 'Exportar logs', 'tainacan-index-manager' ),
			'clear_alerts'       => __( 'Limpar alertas', 'tainacan-index-manager' ),
			'connection_ok'      => __( 'Conexão OK', 'tainacan-index-manager' ),
			'connection_failed'  => __( 'Falha na conexão', 'tainacan-index-manager' ),
			'never'              => __( 'Nunca', 'tainacan-index-manager' ),
			'sync_now'           => __( 'Sincronizar agora', 'tainacan-index-manager' ),
			'throughput'         => __( 'Itens/segundo', 'tainacan-index-manager' ),
			'eta'                => __( 'Tempo restante estimado', 'tainacan-index-manager' ),
			'success_rate'       => __( 'Taxa de sucesso', 'tainacan-index-manager' ),
			'avg_batch_ms'       => __( 'Lote médio (ms)', 'tainacan-index-manager' ),
			'avg_batch_size'     => __( 'Tamanho médio do lote', 'tainacan-index-manager' ),
			'queue_size'         => __( 'Tamanho da fila', 'tainacan-index-manager' ),
			'queue_peak'         => __( 'Pico da fila', 'tainacan-index-manager' ),
			'lifetime_indexed'   => __( 'Total indexado', 'tainacan-index-manager' ),
			'lifetime_failed'    => __( 'Total falhas', 'tainacan-index-manager' ),
			'lifetime_batches'   => __( 'Total de lotes', 'tainacan-index-manager' ),
			'history'            => __( 'Histórico de execuções', 'tainacan-index-manager' ),
			'failure_top'        => __( 'Itens com mais falhas', 'tainacan-index-manager' ),
			'reset_metrics'      => __( 'Zerar métricas', 'tainacan-index-manager' ),
		);
	}
}
