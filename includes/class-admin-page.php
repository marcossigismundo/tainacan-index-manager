<?php
/**
 * Admin pages bootstrap.
 *
 * Two integration paths:
 *
 * 1) Tainacan 1.0.0+ is present (\Tainacan\Pages exists):
 *    we load the three \Tainacan\TIM_*_Page classes (Dashboard, Vocabulary,
 *    Settings). They extend \Tainacan\Pages, get rendered inside Tainacan's
 *    native page chrome (sidebar + header + theme), and register themselves
 *    in the Tainacan admin sidebar via add_submenu_page() — by default under
 *    "Outros" ($tainacan_other_links_slug); the `menu_location` setting can
 *    move the panel to the root menu instead.
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

	public const DASHBOARD_SLUG  = 'tainacan_idxmgr_dashboard';
	public const VOCABULARY_SLUG = 'tainacan_idxmgr_vocabulary';
	public const SETTINGS_SLUG   = 'tainacan_idxmgr_settings';

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
		add_action( 'admin_head', array( __CLASS__, 'print_menu_dot_style' ) );

		if ( $this->tainacan_pages_available() ) {
			// Tainacan 1.0.0+ — load native page classes. They self-register via Singleton_Instance.
			try {
				require_once TAINACAN_INDEX_MANAGER_DIR . 'includes/tainacan-pages/class-dashboard-page.php';
				require_once TAINACAN_INDEX_MANAGER_DIR . 'includes/tainacan-pages/class-vocabulary-page.php';
				require_once TAINACAN_INDEX_MANAGER_DIR . 'includes/tainacan-pages/class-settings-page.php';
				return;
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG; surfaces only in dev.
					error_log( '[Tainacan Index Manager] Falha ao carregar Tainacan Pages, caindo em fallback: ' . $e->getMessage() );
				}
				// Fall through to the standalone path so the plugin keeps working.
			}
		}

		// Fallback path: standalone WP menu + warning notice.
		add_action( 'admin_menu', array( $this, 'register_fallback_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_fallback_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_compat_notice' ) );
	}

	/**
	 * Confirms the Tainacan native page system is actually usable in the
	 * current request, not just declared somewhere in the codebase.
	 *
	 * We require:
	 * - the abstract class \Tainacan\Pages
	 * - the trait \Tainacan\Traits\Singleton_Instance
	 * - the Pages::init() method, kept `public` upstream (we override it
	 *   in subclasses and PHP raises a fatal if visibility ever changes)
	 *
	 * Checking via Reflection here means a future upstream rename or
	 * visibility change degrades us gracefully into the standalone menu
	 * instead of fataling.
	 */
	private function tainacan_pages_available(): bool {
		if ( ! class_exists( '\\Tainacan\\Pages' ) ) {
			return false;
		}
		if ( ! trait_exists( '\\Tainacan\\Traits\\Singleton_Instance' ) ) {
			return false;
		}
		try {
			$ref = new \ReflectionMethod( '\\Tainacan\\Pages', 'init' );
			if ( ! $ref->isPublic() ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}

	/**
	 * JS config passed to the Vue SPA. Shared between Tainacan and fallback paths.
	 *
	 * @param string $view 'dashboard', 'vocabulary' or 'settings'.
	 */
	public static function js_config( string $view ): array {
		return array(
			'restRoot'   => esc_url_raw( rest_url( REST_Controller::NAMESPACE ) ),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'pluginUrl'  => esc_url_raw( TAINACAN_INDEX_MANAGER_URL ),
			'view'       => $view,
			'dashUrl'    => esc_url_raw( admin_url( 'admin.php?page=' . self::DASHBOARD_SLUG ) ),
			'vocabularyUrl' => esc_url_raw( admin_url( 'admin.php?page=' . self::VOCABULARY_SLUG ) ),
			'settingsUrl' => esc_url_raw( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			'tainacanIntegrated' => class_exists( '\\Tainacan\\Pages' ),
			'i18n'       => self::i18n_strings(),
		);
	}

	/**
	 * Enqueue CSS + Vue + the SPA for one view. Used by every page, Tainacan or not.
	 */
	public static function enqueue_assets( string $view ): void {
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
	}

	/**
	 * Parent menu for the panel pages inside Tainacan's sidebar.
	 *
	 * "Outros" by default: the panel is a maintenance tool, like the importers
	 * and exporters that already live there. The root menu is an opt-in.
	 *
	 * @param object $page A \Tainacan\Pages subclass (exposes the two slugs).
	 */
	public static function tainacan_parent_slug( $page ): string {
		$all = Settings::all();
		if ( 'root' === ( $all['menu_location'] ?? 'other' ) && ! empty( $page->tainacan_root_menu_slug ) ) {
			return (string) $page->tainacan_root_menu_slug;
		}
		return (string) $page->tainacan_other_links_slug;
	}

	/**
	 * Menu label with the status light's last colour as a small dot.
	 *
	 * Reads the remembered colour only — building the admin menu must never wait
	 * on Elasticsearch.
	 */
	public static function menu_label_with_light( string $icon_svg, string $text ): string {
		$light = Traffic_Light::remembered();
		$color = $light ? $light['color'] : 'unknown';
		$title = $light ? $light['title'] : __( 'Ainda não verificado', 'tainacan-index-manager' );

		return '<span class="icon">' . $icon_svg . '</span>'
			. '<span class="menu-text">' . esc_html( $text )
			. ' <span class="tim-menu-dot is-' . esc_attr( $color ) . '" title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $title ) . '"></span>'
			. '</span>';
	}

	/**
	 * Tiny inline style for the menu dot, printed on every admin page (the menu
	 * is everywhere, the plugin stylesheet is not).
	 */
	public static function print_menu_dot_style(): void {
		echo '<style id="tim-menu-dot">'
			. '.tim-menu-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-left:4px;vertical-align:middle;background:#9aa0a6}'
			. '.tim-menu-dot.is-green{background:#2ec27e;box-shadow:0 0 5px #2ec27e}'
			. '.tim-menu-dot.is-yellow{background:#f5c211;box-shadow:0 0 5px #f5c211}'
			. '.tim-menu-dot.is-red{background:#e01b24;box-shadow:0 0 5px #e01b24}'
			. '</style>';
	}

	/* --------- Fallback path (Tainacan absent) --------- */

	public function register_fallback_menu(): void {
		add_menu_page(
			__( 'Gestão da Indexação', 'tainacan-index-manager' ),
			__( 'Gestão da Indexação', 'tainacan-index-manager' ),
			'manage_options',
			self::DASHBOARD_SLUG,
			array( $this, 'render_fallback_dashboard' ),
			'dashicons-chart-line',
			58
		);
		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Vocabulário da busca', 'tainacan-index-manager' ),
			__( 'Vocabulário da busca', 'tainacan-index-manager' ),
			'manage_options',
			self::VOCABULARY_SLUG,
			array( $this, 'render_fallback_vocabulary' )
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
		echo '<h1 class="tim-title">' . esc_html__( 'Gestão da Indexação', 'tainacan-index-manager' ) . '</h1>';
		echo '<div id="tainacan-idxmgr-app" data-view="dashboard"></div>';
		echo '</div>';
	}

	public function render_fallback_vocabulary(): void {
		echo '<div class="wrap tainacan-idxmgr-wrap is-standalone">';
		echo '<h1 class="tim-title">' . esc_html__( 'Vocabulário da busca', 'tainacan-index-manager' ) . '</h1>';
		echo '<div id="tainacan-idxmgr-app" data-view="vocabulary"></div>';
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
		$views = array(
			self::DASHBOARD_SLUG  => 'dashboard',
			self::VOCABULARY_SLUG => 'vocabulary',
			self::SETTINGS_SLUG   => 'settings',
		);
		if ( ! isset( $views[ $page ] ) ) {
			return;
		}
		self::enqueue_assets( $views[ $page ] );
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
			'dashboard'          => __( 'Gestão da Indexação', 'tainacan-index-manager' ),
			'vocabulary'         => __( 'Vocabulário da busca', 'tainacan-index-manager' ),
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
			'engine_elasticsearch' => __( 'Elasticsearch', 'tainacan-index-manager' ),
			'engine_sql'           => __( 'SQL', 'tainacan-index-manager' ),
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
