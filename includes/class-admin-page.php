<?php
/**
 * Admin pages registered as Tainacan submenus.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the dashboard ("Saúde da Busca") and the settings page
 * as submenus of the Tainacan top-level menu when present, falling back
 * to a top-level menu only if Tainacan isn't active.
 *
 * The UI is a Vue 3 SPA mounted into a single root div; the PHP page
 * is just the mount point.
 */
final class Admin_Page {

	public const DASHBOARD_SLUG = 'tainacan_index_manager';
	public const SETTINGS_SLUG  = 'tainacan_index_manager_settings';

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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu(): void {
		$parent = $this->detect_tainacan_parent();
		$icon   = 'dashicons-chart-line';

		if ( $parent ) {
			add_submenu_page(
				$parent,
				__( 'Saúde da Busca', 'tainacan-index-manager' ),
				__( 'Saúde da Busca', 'tainacan-index-manager' ),
				'manage_options',
				self::DASHBOARD_SLUG,
				array( $this, 'render_dashboard' )
			);
			add_submenu_page(
				$parent,
				__( 'Configurações de Indexação', 'tainacan-index-manager' ),
				__( 'Configurações de Indexação', 'tainacan-index-manager' ),
				'manage_options',
				self::SETTINGS_SLUG,
				array( $this, 'render_settings' )
			);
		} else {
			add_menu_page(
				__( 'Saúde da Busca (Tainacan)', 'tainacan-index-manager' ),
				__( 'Saúde da Busca', 'tainacan-index-manager' ),
				'manage_options',
				self::DASHBOARD_SLUG,
				array( $this, 'render_dashboard' ),
				$icon,
				58
			);
			add_submenu_page(
				self::DASHBOARD_SLUG,
				__( 'Configurações de Indexação', 'tainacan-index-manager' ),
				__( 'Configurações', 'tainacan-index-manager' ),
				'manage_options',
				self::SETTINGS_SLUG,
				array( $this, 'render_settings' )
			);
		}
	}

	/**
	 * Look for the Tainacan top-level menu slug.
	 * Tainacan registers its menu under `tainacan_admin` (current versions);
	 * older versions used `tainacan`. We probe both.
	 */
	private function detect_tainacan_parent(): ?string {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return null;
		}
		$candidates = array( 'tainacan_admin', 'tainacan', 'tainacan-admin' );
		foreach ( $menu as $entry ) {
			if ( is_array( $entry ) && isset( $entry[2] ) && in_array( (string) $entry[2], $candidates, true ) ) {
				return (string) $entry[2];
			}
		}
		// Tainacan might register later; if its main class exists, assume slug.
		if ( class_exists( '\\Tainacan\\Admin' ) ) {
			return 'tainacan_admin';
		}
		return null;
	}

	public function render_dashboard(): void {
		echo '<div class="wrap tainacan-idxmgr-wrap"><div id="tainacan-idxmgr-app" data-view="dashboard"></div></div>';
	}

	public function render_settings(): void {
		echo '<div class="wrap tainacan-idxmgr-wrap"><div id="tainacan-idxmgr-app" data-view="settings"></div></div>';
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_plugin_page( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'tainacan-idxmgr-admin',
			TAINACAN_INDEX_MANAGER_URL . 'assets/css/admin.css',
			array(),
			TAINACAN_INDEX_MANAGER_VERSION
		);

		// Vue 3 from local vendor folder (no CDN per CLAUDE.md).
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

		wp_localize_script( 'tainacan-idxmgr-admin', 'TIMConfig', array(
			'restRoot'   => esc_url_raw( rest_url( REST_Controller::NAMESPACE ) ),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'pluginUrl'  => esc_url_raw( TAINACAN_INDEX_MANAGER_URL ),
			'dashUrl'    => esc_url_raw( admin_url( 'admin.php?page=' . self::DASHBOARD_SLUG ) ),
			'settingsUrl' => esc_url_raw( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			'i18n'       => $this->i18n_strings(),
		) );

		wp_enqueue_script( 'tainacan-idxmgr-vue' );
		wp_enqueue_script( 'tainacan-idxmgr-admin' );
		wp_set_script_translations( 'tainacan-idxmgr-admin', 'tainacan-index-manager' );
	}

	private function is_plugin_page( string $hook ): bool {
		// Hook suffix is parent-page_page_subslug; we don't pin it because the parent
		// can be either Tainacan's slug or the plugin's standalone slug.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) ) {
			if ( false !== strpos( $screen->id, self::DASHBOARD_SLUG ) ) {
				return true;
			}
			if ( false !== strpos( $screen->id, self::SETTINGS_SLUG ) ) {
				return true;
			}
		}
		// Last-resort fallback: GET page= matches.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page-slug check; no state mutation.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, array( self::DASHBOARD_SLUG, self::SETTINGS_SLUG ), true );
	}

	/**
	 * Strings exposed to the Vue app (so they can be translated and reused).
	 */
	private function i18n_strings(): array {
		return array(
			'dashboard'          => __( 'Saúde da Busca', 'tainacan-index-manager' ),
			'settings'           => __( 'Configurações de Indexação', 'tainacan-index-manager' ),
			'logs'               => __( 'Logs', 'tainacan-index-manager' ),
			'alerts'             => __( 'Alertas', 'tainacan-index-manager' ),
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
		);
	}
}
