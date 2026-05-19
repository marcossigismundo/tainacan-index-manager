<?php
/**
 * Plugin Name:       Tainacan Index Manager
 * Plugin URI:        https://github.com/marcossigismundo/tainacan-index-manager
 * Description:       Monitora a saúde da busca, integra Tainacan ao Elasticsearch/OpenSearch (via ElasticPress quando disponível, com indexador próprio em fallback) e oferece painel de integridade do índice integrado ao Tainacan.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:      6.9
 * Author:            Marcos Sigismundo
 * Author URI:        https://github.com/marcossigismundo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tainacan-index-manager
 * Domain Path:       /languages
 *
 * @package TainacanIndexManager
 */

defined( 'ABSPATH' ) || exit;

define( 'TAINACAN_INDEX_MANAGER_VERSION', '1.1.1' );
define( 'TAINACAN_INDEX_MANAGER_FILE', __FILE__ );
define( 'TAINACAN_INDEX_MANAGER_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAINACAN_INDEX_MANAGER_URL', plugin_dir_url( __FILE__ ) );
define( 'TAINACAN_INDEX_MANAGER_BASENAME', plugin_basename( __FILE__ ) );
define( 'TAINACAN_INDEX_MANAGER_SLUG', 'tainacan-index-manager' );

require_once TAINACAN_INDEX_MANAGER_DIR . 'includes/class-autoloader.php';
\TainacanIndexManager\Autoloader::register();

register_activation_hook( __FILE__, array( '\TainacanIndexManager\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\TainacanIndexManager\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
	\TainacanIndexManager\Plugin::instance()->boot();
} );
