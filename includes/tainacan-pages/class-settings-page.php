<?php
/**
 * Settings page extending \Tainacan\Pages.
 *
 * @package TainacanIndexManager
 */

namespace Tainacan;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\Tainacan\\Pages' ) ) {
	return;
}

/**
 * Configurações de Indexação — settings page in Tainacan admin.
 */
class TIM_Settings_Page extends \Tainacan\Pages {

	use \Tainacan\Traits\Singleton_Instance;

	public const SLUG = 'tainacan_idxmgr_settings';

	protected function init() {
		parent::init();
	}

	protected function get_page_slug(): string {
		return self::SLUG;
	}

	public function add_admin_menu() {
		$icon_svg = method_exists( $this, 'get_svg_icon' ) ? $this->get_svg_icon( 'settings' ) : '';

		$label = '<span class="icon">' . $icon_svg . '</span>'
			. '<span class="menu-text">' . esc_html__( 'Indexação', 'tainacan-index-manager' ) . '</span>';

		$page_suffix = add_submenu_page(
			$this->tainacan_other_links_slug,
			__( 'Configurações de Indexação', 'tainacan-index-manager' ),
			$label,
			'manage_options',
			$this->get_page_slug(),
			array( &$this, 'render_page' )
		);

		if ( $page_suffix ) {
			add_action( 'load-' . $page_suffix, array( &$this, 'load_page' ) );
		}
	}

	public function admin_enqueue_css() {
		wp_enqueue_style(
			'tainacan-idxmgr-admin',
			TAINACAN_INDEX_MANAGER_URL . 'assets/css/admin.css',
			array(),
			TAINACAN_INDEX_MANAGER_VERSION
		);
	}

	public function admin_enqueue_js() {
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

		wp_localize_script( 'tainacan-idxmgr-admin', 'TIMConfig', \TainacanIndexManager\Admin_Page::js_config( 'settings' ) );

		wp_enqueue_script( 'tainacan-idxmgr-vue' );
		wp_enqueue_script( 'tainacan-idxmgr-admin' );
		wp_set_script_translations( 'tainacan-idxmgr-admin', 'tainacan-index-manager' );
	}

	public function render_page_content() {
		echo '<div class="wrap tainacan-page-container-content tainacan-idxmgr-wrap">';
		echo '<div class="tainacan-fixed-subheader"><h1 class="tainacan-page-title">'
			. esc_html__( 'Configurações de Indexação', 'tainacan-index-manager' )
			. '</h1></div>';
		echo '<div id="tainacan-idxmgr-app" data-view="settings"></div>';
		echo '</div>';
	}
}

TIM_Settings_Page::get_instance();
