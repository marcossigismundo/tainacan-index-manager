<?php
/**
 * Search vocabulary page extending \Tainacan\Pages.
 *
 * @package TainacanIndexManager
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- \Tainacan\Pages subclasses live in the core namespace (the Pages registry resolves them there); this file is loaded only when \Tainacan\Pages exists.
namespace Tainacan;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\Tainacan\\Pages' ) ) {
	return;
}

/**
 * Vocabulário da busca — synonyms, variants, corrections and ignored words.
 */
class TIM_Vocabulary_Page extends \Tainacan\Pages {

	use \Tainacan\Traits\Singleton_Instance;

	public const SLUG = 'tainacan_idxmgr_vocabulary';

	protected function get_page_slug(): string {
		return self::SLUG;
	}

	public function add_admin_menu() {
		$icon_svg = \TainacanIndexManager\Tainacan_Icon::svg( $this, array( 'taxonomies', 'terms', 'search', 'metadata' ) );

		$label = '<span class="icon">' . $icon_svg . '</span>'
			. '<span class="menu-text">' . esc_html__( 'Vocabulário da busca', 'tainacan-index-manager' ) . '</span>';

		$page_suffix = add_submenu_page(
			\TainacanIndexManager\Admin_Page::tainacan_parent_slug( $this ),
			__( 'Vocabulário da busca', 'tainacan-index-manager' ),
			$label,
			'manage_options',
			$this->get_page_slug(),
			array( &$this, 'render_page' ),
			61
		);

		if ( $page_suffix ) {
			add_action( 'load-' . $page_suffix, array( &$this, 'load_page' ) );
		}
	}

	public function admin_enqueue_css() {
		// Everything is enqueued together in admin_enqueue_js().
	}

	public function admin_enqueue_js() {
		\TainacanIndexManager\Admin_Page::enqueue_assets( 'vocabulary' );
	}

	public function render_page_content() {
		echo '<div class="wrap tainacan-page-container-content tainacan-idxmgr-wrap">';
		echo '<div class="tainacan-fixed-subheader"><h1 class="tainacan-page-title">'
			. esc_html__( 'Vocabulário da busca', 'tainacan-index-manager' )
			. '</h1></div>';
		echo '<div id="tainacan-idxmgr-app" data-view="vocabulary"></div>';
		echo '</div>';
	}
}

TIM_Vocabulary_Page::get_instance();
