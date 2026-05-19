<?php
/**
 * WP-Cron scheduling: health checks, batch indexing, log purge.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Registers custom intervals + hooks for periodic work.
 *
 * Three jobs:
 * - `tainacan_idxmgr_health_tick`   — every $auto_check_frequency, refreshes
 *   the health snapshot (also re-evaluates alerts).
 * - `tainacan_idxmgr_index_tick`    — every minute when auto-indexing is on,
 *   drains one batch from the queue.
 * - `tainacan_idxmgr_cleanup_tick`  — daily, purges old log rows.
 */
final class Cron {

	public const HOOK_HEALTH  = 'tainacan_idxmgr_health_tick';
	public const HOOK_INDEX   = 'tainacan_idxmgr_index_tick';
	public const HOOK_CLEANUP = 'tainacan_idxmgr_cleanup_tick';

	private Settings $settings;
	private Health_Service $health;
	private Indexer $indexer;
	private Collections_Monitor $collections;
	private Logger $logger;

	public function __construct( Settings $settings, Health_Service $health, Indexer $indexer, Collections_Monitor $collections, Logger $logger ) {
		$this->settings    = $settings;
		$this->health      = $health;
		$this->indexer     = $indexer;
		$this->collections = $collections;
		$this->logger      = $logger;
	}

	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
		add_action( self::HOOK_HEALTH, array( $this, 'run_health_tick' ) );
		add_action( self::HOOK_INDEX, array( $this, 'run_index_tick' ) );
		add_action( self::HOOK_CLEANUP, array( $this, 'run_cleanup_tick' ) );

		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Add custom WP-Cron recurrences.
	 *
	 * @param array $schedules Existing schedules.
	 */
	public function add_schedules( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$schedules['tim_15min']  = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'A cada 15 minutos (TIM)', 'tainacan-index-manager' ) );
		$schedules['tim_30min']  = array( 'interval' => 30 * MINUTE_IN_SECONDS, 'display' => __( 'A cada 30 minutos (TIM)', 'tainacan-index-manager' ) );
		$schedules['tim_6hours'] = array( 'interval' => 6 * HOUR_IN_SECONDS,    'display' => __( 'A cada 6 horas (TIM)', 'tainacan-index-manager' ) );
		$schedules['tim_minute'] = array( 'interval' => MINUTE_IN_SECONDS,      'display' => __( 'A cada minuto (TIM)', 'tainacan-index-manager' ) );
		return $schedules;
	}

	public function ensure_scheduled(): void {
		$freq = (string) $this->settings->get( 'auto_check_frequency', 'hourly' );
		if ( ! wp_next_scheduled( self::HOOK_HEALTH ) ) {
			wp_schedule_event( time() + 60, $freq, self::HOOK_HEALTH );
		}

		$auto_index = (bool) $this->settings->get( 'auto_indexing_enabled', false );
		$has_index  = wp_next_scheduled( self::HOOK_INDEX );

		if ( $auto_index && ! $has_index ) {
			wp_schedule_event( time() + 60, 'tim_minute', self::HOOK_INDEX );
		} elseif ( ! $auto_index && $has_index ) {
			wp_clear_scheduled_hook( self::HOOK_INDEX );
		}

		if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + 300, 'daily', self::HOOK_CLEANUP );
		}
	}

	/**
	 * Called on plugin activation: schedule default ticks.
	 */
	public static function schedule_default(): void {
		if ( ! wp_next_scheduled( self::HOOK_HEALTH ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK_HEALTH );
		}
		if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + 300, 'daily', self::HOOK_CLEANUP );
		}
	}

	/**
	 * Clear all scheduled events (on deactivation).
	 */
	public static function clear_all(): void {
		wp_clear_scheduled_hook( self::HOOK_HEALTH );
		wp_clear_scheduled_hook( self::HOOK_INDEX );
		wp_clear_scheduled_hook( self::HOOK_CLEANUP );
	}

	public function run_health_tick(): void {
		$snapshot = $this->health->refresh_snapshot();
		$this->collections->invalidate();
		// Single combined log entry per tick — health channel carries the
		// useful columns (cluster status, latency) and a small context blob,
		// so we don't need a separate cron-channel "tick" line.
		$this->logger->info(
			Logger::CHAN_HEALTH,
			'Cron: snapshot de saúde atualizado.',
			array(
				'overall'        => $snapshot['overall_status'] ?? null,
				'coverage_pct'   => $snapshot['coverage_pct'] ?? null,
				'cluster_status' => $snapshot['cluster_status'] ?? null,
			),
			array(
				'cluster_status'   => $snapshot['cluster_status'] ?? null,
				'response_time_ms' => $snapshot['es_ping_ms'] ?? null,
			)
		);
	}

	public function run_index_tick(): void {
		$this->logger->info( Logger::CHAN_CRON, 'Cron: tick de indexação.' );
		$res = $this->indexer->process_batch();
		if ( ! $res['ok'] ) {
			$this->logger->warning( Logger::CHAN_INDEXER, 'Tick de indexação falhou.', array( 'message' => $res['message'] ?? '' ) );
		}
	}

	public function run_cleanup_tick(): void {
		$days  = (int) $this->settings->get( 'log_retention_days', 30 );
		$count = $this->logger->purge_older_than( $days );
		$this->logger->info( Logger::CHAN_CRON, 'Logs antigos purgados.', array( 'deleted' => $count, 'days' => $days ) );
	}
}
