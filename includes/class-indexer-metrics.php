<?php
/**
 * Indexer metrics: throughput, ETA, success rate, run history.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Collects metrics from each indexer batch and exposes derived KPIs.
 *
 * Storage model: a single non-autoloaded option (`tainacan_idxmgr_metrics`)
 * holds a bounded ring of the most recent runs plus rolling counters. Reads
 * are cheap; writes happen once per batch, so this stays out of the hot path.
 *
 * The data shape is deliberately compact (no timestamps as strings, IDs as
 * ints) so even 200 runs sit well under 50 KB.
 */
final class Indexer_Metrics {

	private const OPTION_KEY    = 'tainacan_idxmgr_metrics';
	private const MAX_HISTORY   = 200;
	private const FAILURE_TOP_N = 20;

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Default metrics document.
	 */
	private function defaults(): array {
		return array(
			'runs'              => array(),
			'lifetime_indexed'  => 0,
			'lifetime_failed'   => 0,
			'lifetime_skipped'  => 0,
			'lifetime_dropped'  => 0,
			'lifetime_batches'  => 0,
			'peak_queue_size'   => 0,
			'failure_top'       => array(),
			'last_error_summary' => array(),
			'last_error_ts'      => 0,
			'first_run_ts'       => 0,
			'last_run_ts'        => 0,
		);
	}

	/**
	 * Load the current metrics document.
	 */
	public function load(): array {
		$opt = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		return array_merge( $this->defaults(), $opt );
	}

	/**
	 * Persist a batch run. Called by the Indexer at the end of process_batch().
	 *
	 * @param array $run Keyed:
	 *   - ts            (int)
	 *   - duration_ms   (int)
	 *   - built         (int)
	 *   - indexed       (int)
	 *   - failed        (int)
	 *   - skipped       (int)
	 *   - dropped       (int)
	 *   - queue_before  (int)
	 *   - queue_after   (int)
	 *   - failed_ids    (int[])
	 */
	public function record_run( array $run ): void {
		$doc = $this->load();

		$entry = array(
			'ts'           => (int) ( $run['ts'] ?? time() ),
			'duration_ms'  => max( 0, (int) ( $run['duration_ms'] ?? 0 ) ),
			'built'        => max( 0, (int) ( $run['built'] ?? 0 ) ),
			'indexed'      => max( 0, (int) ( $run['indexed'] ?? 0 ) ),
			'failed'       => max( 0, (int) ( $run['failed'] ?? 0 ) ),
			'skipped'      => max( 0, (int) ( $run['skipped'] ?? 0 ) ),
			'dropped'      => max( 0, (int) ( $run['dropped'] ?? 0 ) ),
			'queue_before' => max( 0, (int) ( $run['queue_before'] ?? 0 ) ),
			'queue_after'  => max( 0, (int) ( $run['queue_after'] ?? 0 ) ),
		);

		$doc['runs'][] = $entry;
		if ( count( $doc['runs'] ) > self::MAX_HISTORY ) {
			$doc['runs'] = array_slice( $doc['runs'], -self::MAX_HISTORY );
		}

		$doc['lifetime_indexed'] += $entry['indexed'];
		$doc['lifetime_failed']  += $entry['failed'];
		$doc['lifetime_skipped'] += $entry['skipped'];
		$doc['lifetime_dropped'] += $entry['dropped'];
		++$doc['lifetime_batches'];
		$doc['last_run_ts'] = $entry['ts'];
		if ( 0 === $doc['first_run_ts'] ) {
			$doc['first_run_ts'] = $entry['ts'];
		}
		if ( $entry['queue_before'] > (int) $doc['peak_queue_size'] ) {
			$doc['peak_queue_size'] = $entry['queue_before'];
		}

		if ( ! empty( $run['failed_ids'] ) && is_array( $run['failed_ids'] ) ) {
			$top = is_array( $doc['failure_top'] ?? null ) ? $doc['failure_top'] : array();
			foreach ( $run['failed_ids'] as $id ) {
				$id        = (int) $id;
				$top[ $id ] = ( $top[ $id ] ?? 0 ) + 1;
			}
			arsort( $top, SORT_NUMERIC );
			$doc['failure_top'] = array_slice( $top, 0, self::FAILURE_TOP_N, true );
		}

		// Latest bulk error breakdown (ES "type" → count, sample reason).
		// Replaced on every run — the dashboard wants "what's failing now",
		// not a historical roll-up.
		if ( ! empty( $run['error_summary'] ) && is_array( $run['error_summary'] ) ) {
			$doc['last_error_summary'] = $run['error_summary'];
			$doc['last_error_ts']      = $entry['ts'];
		} elseif ( 0 === $entry['failed'] ) {
			// All-clear: wipe stale error info so the panel reflects the new run.
			$doc['last_error_summary'] = array();
		}

		update_option( self::OPTION_KEY, $doc, false );
	}

	/**
	 * Reset metrics history (admin action).
	 */
	public function reset(): void {
		update_option( self::OPTION_KEY, $this->defaults(), false );
	}

	/**
	 * Observe the current queue size and bump peak if exceeded.
	 *
	 * Called whenever the queue grows (enqueue, enqueue_all, enqueue_collection)
	 * so the dashboard "Peak queue" indicator reflects the truth even before
	 * any batch has run.
	 */
	public function observe_queue_size( int $size ): void {
		if ( $size <= 0 ) {
			return;
		}
		$doc = $this->load();
		if ( $size > (int) $doc['peak_queue_size'] ) {
			$doc['peak_queue_size'] = $size;
			update_option( self::OPTION_KEY, $doc, false );
		}
	}

	/**
	 * Derived KPIs + windowed views suitable for the dashboard.
	 *
	 * @param int $queue_size  Current queue size from the Indexer.
	 * @param int $window_runs Number of recent runs used for rolling averages.
	 */
	public function summary( int $queue_size, int $window_runs = 10 ): array {
		$doc  = $this->load();
		$runs = is_array( $doc['runs'] ) ? $doc['runs'] : array();

		$recent       = array_slice( $runs, -max( 1, $window_runs ) );
		$total_recent = count( $recent );

		$sum_indexed  = 0;
		$sum_failed   = 0;
		$sum_built    = 0;
		$sum_duration = 0;
		$min_ts       = PHP_INT_MAX;
		$max_ts       = 0;

		foreach ( $recent as $r ) {
			$sum_indexed  += (int) $r['indexed'];
			$sum_failed   += (int) $r['failed'];
			$sum_built    += (int) $r['built'];
			$sum_duration += (int) $r['duration_ms'];
			$min_ts        = min( $min_ts, (int) $r['ts'] );
			$max_ts        = max( $max_ts, (int) $r['ts'] );
		}

		// Throughput: prefer wall-clock window if available; fall back to total duration.
		$throughput_ips = 0.0;
		$elapsed_s      = max( 1, $max_ts - $min_ts );
		if ( $total_recent >= 2 && $elapsed_s > 0 ) {
			$throughput_ips = $sum_indexed / $elapsed_s;
		} elseif ( $sum_duration > 0 ) {
			$throughput_ips = $sum_indexed / ( $sum_duration / 1000 );
		}

		$success_rate = null;
		if ( ( $sum_indexed + $sum_failed ) > 0 ) {
			$success_rate = round( ( $sum_indexed / ( $sum_indexed + $sum_failed ) ) * 100, 2 );
		}

		$avg_batch_ms = $total_recent > 0 ? (int) round( $sum_duration / $total_recent ) : 0;
		$avg_batch_sz = $total_recent > 0 ? round( $sum_built / $total_recent, 1 ) : 0.0;

		$eta_seconds = null;
		if ( $queue_size > 0 && $throughput_ips > 0.0 ) {
			$eta_seconds = (int) round( $queue_size / $throughput_ips );
		}

		// Build sparkline-friendly arrays (chronological, indexed per run).
		$sparkline = array(
			'ts'       => array(),
			'indexed'  => array(),
			'failed'   => array(),
			'duration' => array(),
			'queue'    => array(),
		);
		foreach ( array_slice( $runs, -50 ) as $r ) {
			$sparkline['ts'][]       = (int) $r['ts'];
			$sparkline['indexed'][]  = (int) $r['indexed'];
			$sparkline['failed'][]   = (int) $r['failed'];
			$sparkline['duration'][] = (int) $r['duration_ms'];
			$sparkline['queue'][]    = (int) $r['queue_after'];
		}

		return array(
			'lifetime' => array(
				'indexed' => (int) $doc['lifetime_indexed'],
				'failed'  => (int) $doc['lifetime_failed'],
				'skipped' => (int) $doc['lifetime_skipped'],
				'dropped' => (int) $doc['lifetime_dropped'],
				'batches' => (int) $doc['lifetime_batches'],
			),
			'window'   => array(
				'runs'              => $total_recent,
				'indexed'           => $sum_indexed,
				'failed'            => $sum_failed,
				'throughput_ips'    => round( $throughput_ips, 2 ),
				'avg_batch_ms'      => $avg_batch_ms,
				'avg_batch_size'    => $avg_batch_sz,
				'success_rate_pct'  => $success_rate,
			),
			'queue'        => array(
				'size'          => (int) $queue_size,
				'peak_observed' => (int) $doc['peak_queue_size'],
				'eta_seconds'   => $eta_seconds,
				'eta_human'     => $eta_seconds !== null ? $this->humanize_seconds( $eta_seconds ) : null,
			),
			'failure_top'        => $doc['failure_top'] ?? array(),
			'last_error_summary' => $doc['last_error_summary'] ?? array(),
			'last_error_ts'      => (int) ( $doc['last_error_ts'] ?? 0 ),
			'first_run_ts'       => (int) $doc['first_run_ts'],
			'last_run_ts'        => (int) $doc['last_run_ts'],
			'sparkline'          => $sparkline,
		);
	}

	/**
	 * Compact, human-friendly duration formatter (PT-BR).
	 */
	private function humanize_seconds( int $s ): string {
		if ( $s < 60 ) {
			return $s . 's';
		}
		if ( $s < 3600 ) {
			return floor( $s / 60 ) . 'min ' . ( $s % 60 ) . 's';
		}
		if ( $s < 86400 ) {
			return floor( $s / 3600 ) . 'h ' . floor( ( $s % 3600 ) / 60 ) . 'min';
		}
		return floor( $s / 86400 ) . 'd ' . floor( ( $s % 86400 ) / 3600 ) . 'h';
	}
}
