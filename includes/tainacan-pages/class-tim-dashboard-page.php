<?php
/**
 * Dashboard page extending \Tainacan\Pages.
 *
 * Lives in the \Tainacan namespace because the base class lives there;
 * this file is loaded conditionally from Plugin::boot() ONLY when
 * \Tainacan\Pages is available, so the global autoloader does not see it.
 *
 * @package TainacanIndexManager
 */

namespace Tainacan;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\Tainacan\\Pages' ) ) {
	return;
}

/**
 * Saúde da Busca — dashboard page in Tainacan admin.
 *
 * Renders the Vue 3 mount point inside Tainacan's native page chrome
 * (`tainacan-page-container-content` + `tainacan-fixed-subheader`) so the
 * sidebar, header and theme of the Tainacan admin remain consistent.
 */
class TIM_Dashboard_Page extends \Tainacan\Pages {

	use \Tainacan\Traits\Singleton_Instance;

	public const SLUG = 'tainacan_idxmgr_dashboard';

	/**
	 * Must remain `public` to match \Tainacan\Pages::init() visibility — PHP
	 * raises a fatal "Access level must be public" if we narrow it to protected.
	 */
	public function init() {
		parent::init();
	}

	protected function get_page_slug(): string {
		return self::SLUG;
	}

	public function add_admin_menu() {
		$icon_svg = \TainacanIndexManager\Tainacan_Icon::svg( $this, array( 'reports', 'chart', 'activities' ) );

		// The label carries the status light's last colour, so the state of the
		// search is visible from anywhere in Tainacan's admin.
		$label = \TainacanIndexManager\Admin_Page::menu_label_with_light( $icon_svg, __( 'Gestão da Indexação', 'tainacan-index-manager' ) );

		$page_suffix = add_submenu_page(
			\TainacanIndexManager\Admin_Page::tainacan_parent_slug( $this ),
			__( 'Gestão da Indexação', 'tainacan-index-manager' ),
			$label,
			'manage_options',
			$this->get_page_slug(),
			array( &$this, 'render_page' ),
			60
		);

		if ( $page_suffix ) {
			add_action( 'load-' . $page_suffix, array( &$this, 'load_page' ) );
		}
	}

	public function admin_enqueue_css() {
		// Everything is enqueued together in admin_enqueue_js().
	}

	public function admin_enqueue_js() {
		\TainacanIndexManager\Admin_Page::enqueue_assets( 'dashboard' );
	}

	public function render_page_content() {
		echo '<div class="wrap tainacan-page-container-content tainacan-idxmgr-wrap">';
		echo '<div class="tainacan-fixed-subheader"><h1 class="tainacan-page-title">'
			. esc_html__( 'Gestão da Indexação', 'tainacan-index-manager' )
			. '</h1></div>';
		echo '<div id="tainacan-idxmgr-app" data-view="dashboard"></div>';
		echo '</div>';
	}
}

TIM_Dashboard_Page::get_instance();
