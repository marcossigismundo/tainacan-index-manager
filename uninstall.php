<?php
/**
 * Uninstall: drop options, transients, log table, and clear scheduled hooks.
 *
 * @package TainacanIndexManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-autoloader.php';
\TainacanIndexManager\Autoloader::register();

global $wpdb;

delete_option( \TainacanIndexManager\Settings::OPTION_KEY );
delete_option( 'tainacan_idxmgr_alerts' );
delete_option( 'tainacan_idxmgr_queue' );
delete_option( 'tainacan_idxmgr_failures' );
delete_option( 'tainacan_idxmgr_indexer_state' );
delete_option( 'tainacan_idxmgr_metrics' );

delete_transient( 'tainacan_idxmgr_health_snapshot' );
delete_transient( 'tainacan_idxmgr_collections_report' );
delete_transient( 'tainacan_idxmgr_fallback_active' );

\TainacanIndexManager\Logger::uninstall();

wp_clear_scheduled_hook( \TainacanIndexManager\Cron::HOOK_HEALTH );
wp_clear_scheduled_hook( \TainacanIndexManager\Cron::HOOK_INDEX );
wp_clear_scheduled_hook( \TainacanIndexManager\Cron::HOOK_CLEANUP );

// Best-effort cleanup of email cooldown transients (prefix lookup).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall: bulk delete of plugin's transient cooldowns; not expressible via WP_Query.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_tainacan_idxmgr_alert_email_sent_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_tainacan_idxmgr_alert_email_sent_' ) . '%'
	)
);
