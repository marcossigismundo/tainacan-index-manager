<?php
/**
 * Uninstall: drop options, transients, log table, and clear scheduled hooks.
 *
 * @package TainacanIndexManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The main plugin file is not loaded on uninstall, and the autoloader needs this.
defined( 'TAINACAN_INDEX_MANAGER_DIR' ) || define( 'TAINACAN_INDEX_MANAGER_DIR', __DIR__ . '/' );

require_once __DIR__ . '/includes/class-autoloader.php';
\TainacanIndexManager\Autoloader::register();

/**
 * Remove everything the plugin stored on the current site.
 */
function tainacan_idxmgr_uninstall_site(): void {
	global $wpdb;

	delete_option( \TainacanIndexManager\Settings::OPTION_KEY );
	delete_option( 'tainacan_idxmgr_alerts' );
	delete_option( 'tainacan_idxmgr_queue' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall: drop the plugin's own queue table; $wpdb->prefix is trusted.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tnc_idxmgr_queue" );
	delete_option( 'tainacan_idxmgr_failures' );
	delete_option( 'tainacan_idxmgr_indexer_state' );
	delete_option( 'tainacan_idxmgr_metrics' );
	delete_option( \TainacanIndexManager\Elasticsearch_Client::LIVE_BREAKER_OPTION );
	delete_option( \TainacanIndexManager\Plugin::DB_VERSION_OPTION );
	delete_option( \TainacanIndexManager\Index_Manager::INDEX_SCHEMA_OPTION );
	delete_option( \TainacanIndexManager\Search_Vocabulary::OPTION );
	delete_option( \TainacanIndexManager\Traffic_Light::OPTION );

	delete_transient( 'tainacan_idxmgr_health_snapshot' );
	delete_transient( 'tainacan_idxmgr_collections_report' );
	delete_transient( 'tainacan_idxmgr_fallback_active' );
	delete_transient( 'tainacan_idxmgr_vocab_lock' );
	delete_transient( 'tainacan_idxmgr_batch_lock' );

	\TainacanIndexManager\Logger::uninstall();

	wp_clear_scheduled_hook( \TainacanIndexManager\Cron::HOOK_HEALTH );
	wp_clear_scheduled_hook( \TainacanIndexManager\Cron::HOOK_INDEX );
	wp_clear_scheduled_hook( \TainacanIndexManager\Cron::HOOK_CLEANUP );

	// Prefixed transients (email cooldowns, oversized-facet markers, count probes).
	foreach ( array( 'tainacan_idxmgr_alert_email_sent_', 'tim_facet_oversized_', 'tim_probe_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall: bulk delete of the plugin's own transients by prefix; no WP API deletes transients by pattern; $wpdb->options is trusted and the prefixes are bound via %s after esc_like().
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $tainacan_idxmgr_blog_id ) {
		switch_to_blog( (int) $tainacan_idxmgr_blog_id );
		tainacan_idxmgr_uninstall_site();
		restore_current_blog();
	}
} else {
	tainacan_idxmgr_uninstall_site();
}
