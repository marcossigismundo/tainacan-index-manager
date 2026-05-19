<?php
/**
 * Custom log table and writer.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Logger backed by a custom table created via dbDelta.
 *
 * Why a custom table: the plugin emits many records per minute (cron checks,
 * indexing batches, REST actions). Using post meta or options would balloon
 * autoload size and trash WP performance. WP_Comment / WP_Post are wrong
 * shapes for structured events.
 */
final class Logger {

	public const TABLE_SUFFIX = 'tainacan_idxmgr_logs';

	public const LEVEL_INFO     = 'info';
	public const LEVEL_WARNING  = 'warning';
	public const LEVEL_ERROR    = 'error';
	public const LEVEL_CRITICAL = 'critical';

	public const CHAN_HEALTH      = 'health';
	public const CHAN_INDEXER     = 'indexer';
	public const CHAN_SEARCH      = 'search';
	public const CHAN_FALLBACK    = 'fallback';
	public const CHAN_ADMIN       = 'admin';
	public const CHAN_REST        = 'rest';
	public const CHAN_CRON        = 'cron';
	public const CHAN_ELASTIC     = 'elastic';
	public const CHAN_ELASTICPRSS = 'elasticpress';
	public const CHAN_ALERT       = 'alert';

	/**
	 * Fully qualified log table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Create or upgrade the log table.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			level VARCHAR(20) NOT NULL,
			channel VARCHAR(40) NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			cluster_status VARCHAR(20) NULL,
			response_time_ms INT(11) NULL,
			collection_id BIGINT(20) UNSIGNED NULL,
			item_id BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY level_idx (level),
			KEY channel_idx (channel),
			KEY created_idx (created_at),
			KEY collection_idx (collection_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop the table on uninstall.
	 */
	public static function uninstall(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin uninstall; drops own table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Insert a log row. Strips secret-shaped keys from context defensively.
	 *
	 * @param string     $level   One of the LEVEL_* constants.
	 * @param string     $channel One of the CHAN_* constants.
	 * @param string     $message Short message.
	 * @param array      $context Structured context (must NOT contain credentials).
	 * @param array|null $extras  Optional dedicated columns: cluster_status, response_time_ms, collection_id, item_id.
	 */
	public function log( string $level, string $channel, string $message, array $context = array(), ?array $extras = null ): void {
		global $wpdb;

		$context = self::scrub_context( $context );
		$row     = array(
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'level'      => $level,
			'channel'    => $channel,
			'message'    => wp_strip_all_tags( $message ),
			'context'    => wp_json_encode( $context ),
		);

		if ( is_array( $extras ) ) {
			foreach ( array( 'cluster_status', 'response_time_ms', 'collection_id', 'item_id' ) as $col ) {
				if ( isset( $extras[ $col ] ) && null !== $extras[ $col ] ) {
					$row[ $col ] = $extras[ $col ];
				}
			}
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own log table; writes only; no caching applicable.
		$wpdb->insert( $table, $row );
	}

	public function info( string $channel, string $message, array $context = array(), ?array $extras = null ): void {
		$this->log( self::LEVEL_INFO, $channel, $message, $context, $extras );
	}

	public function warning( string $channel, string $message, array $context = array(), ?array $extras = null ): void {
		$this->log( self::LEVEL_WARNING, $channel, $message, $context, $extras );
	}

	public function error( string $channel, string $message, array $context = array(), ?array $extras = null ): void {
		$this->log( self::LEVEL_ERROR, $channel, $message, $context, $extras );
	}

	public function critical( string $channel, string $message, array $context = array(), ?array $extras = null ): void {
		$this->log( self::LEVEL_CRITICAL, $channel, $message, $context, $extras );
	}

	/**
	 * Fetch logs paginated, with optional filters.
	 *
	 * @param array $args page, per_page, level, channel, since (epoch)
	 */
	public function fetch( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'page'     => 1,
			'per_page' => 50,
			'level'    => '',
			'channel'  => '',
			'since'    => 0,
		);
		$args     = array_merge( $defaults, $args );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['level'] ) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}
		if ( '' !== $args['channel'] ) {
			$where[]  = 'channel = %s';
			$params[] = $args['channel'];
		}
		if ( $args['since'] > 0 ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', (int) $args['since'] );
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
		$table     = self::table();

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own log table; $where_sql built from internal literals + %s placeholders; user values bound via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count logs matching filters (for pagination totals).
	 */
	public function count( array $args = array() ): int {
		global $wpdb;

		$defaults = array(
			'level'   => '',
			'channel' => '',
			'since'   => 0,
		);
		$args     = array_merge( $defaults, $args );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['level'] ) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}
		if ( '' !== $args['channel'] ) {
			$where[]  = 'channel = %s';
			$params[] = $args['channel'];
		}
		if ( $args['since'] > 0 ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', (int) $args['since'] );
		}

		$where_sql = implode( ' AND ', $where );
		$table     = self::table();

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own log table; aggregate COUNT with no user input.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own log table; aggregate COUNT; $where_sql built from internal literals + %s placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );
	}

	/**
	 * Purge log rows older than $days.
	 */
	public function purge_older_than( int $days ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, $days ) . ' days' ) );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own log table; delete by cutoff; user input via prepare placeholder.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Delete all log rows (admin action).
	 */
	public function truncate(): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin's own log table; explicit admin truncate action.
		return false !== $wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Remove keys that look like secrets from a context array before logging.
	 */
	public static function scrub_context( array $context ): array {
		$blocked = array( 'password', 'secret', 'token', 'authorization', 'api_key', 'apikey', 'es_password', 'es_api_key' );
		foreach ( $context as $k => $v ) {
			$lk = strtolower( (string) $k );
			foreach ( $blocked as $b ) {
				if ( false !== strpos( $lk, $b ) ) {
					$context[ $k ] = '***redacted***';
					continue 2;
				}
			}
			if ( is_array( $v ) ) {
				$context[ $k ] = self::scrub_context( $v );
			}
		}
		return $context;
	}
}
