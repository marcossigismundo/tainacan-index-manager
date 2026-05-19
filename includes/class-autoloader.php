<?php
/**
 * PSR-4-like autoloader for plugin classes.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader for TainacanIndexManager namespace.
 *
 * Maps `TainacanIndexManager\Foo_Bar` to `includes/class-foo-bar.php`.
 */
final class Autoloader {

	/**
	 * Register the SPL autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve and load a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'TainacanIndexManager\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( 'TainacanIndexManager\\' ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$relative = strtolower( str_replace( '_', '-', $relative ) );

		$file = TAINACAN_INDEX_MANAGER_DIR . 'includes' . DIRECTORY_SEPARATOR . 'class-' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
