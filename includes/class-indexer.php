<?php
/**
 * Tainacan -> Elasticsearch/OpenSearch indexer.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Indexes Tainacan items into the plugin's own ES/OS index, in batches.
 *
 * Queue model: a list of post IDs persisted in a single option
 * (`tainacan_idxmgr_queue`). Each batch consumes BATCH_SIZE IDs, indexes them
 * via _bulk, and removes them from the queue. Cron drains the queue between
 * runs; admin actions can also trigger immediate flushes.
 *
 * Per-item failures are counted (`tainacan_idxmgr_failures`) so the dashboard
 * can surface them; items with too many failures are dropped from the queue
 * to avoid blocking the pipeline.
 */
final class Indexer {

	private const QUEUE_OPTION    = 'tainacan_idxmgr_queue';
	private const FAILURES_OPTION = 'tainacan_idxmgr_failures';
	private const STATE_OPTION    = 'tainacan_idxmgr_indexer_state';

	public const STATE_IDLE     = 'idle';
	public const STATE_RUNNING  = 'running';
	public const STATE_PAUSED   = 'paused';
	public const STATE_FINISHED = 'finished';

	private Settings $settings;
	private Logger $logger;
	private Index_Manager $index_manager;
	private Elasticsearch_Client $client;
	private Indexer_Metrics $metrics;

	public function __construct( Settings $settings, Logger $logger, Index_Manager $index_manager, Indexer_Metrics $metrics ) {
		$this->settings      = $settings;
		$this->logger        = $logger;
		$this->index_manager = $index_manager;
		$this->client        = $index_manager->client();
		$this->metrics       = $metrics;

		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ) );
	}

	/** Most items requeued because a record they point to changed (ES max_result_window). */
	private const RELATED_REQUEUE_MAX = 10000;

	/**
	 * Hook: an item renamed or (un)published changes the searchable text of every
	 * item pointing to it through a Relationship metadatum — the works of an
	 * author whose authority record was edited.
	 *
	 * An item leaving `auto-draft` is new: nothing can point to it yet, and
	 * skipping it keeps imports from paying one search per created item.
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_after  Post after the update.
	 * @param \WP_Post $post_before Post before the update.
	 */
	public function on_post_updated( $post_id, $post_after, $post_before ): void {
		if ( ! $post_after instanceof \WP_Post || ! $post_before instanceof \WP_Post ) {
			return;
		}
		if ( 'auto-draft' === $post_before->post_status ) {
			return;
		}
		if ( $post_after->post_title === $post_before->post_title && $post_after->post_status === $post_before->post_status ) {
			return;
		}
		if ( ! $this->is_tainacan_item_post_type( $post_after->post_type ) ) {
			return;
		}
		$this->requeue_referrers( (int) $post_id );
	}

	/**
	 * Requeue the items whose Relationship metadata point to $post_id, found in
	 * the index itself (`metadata.value_ids`, no SQL).
	 *
	 * `value_ids` also holds taxonomy term IDs, so a term with the same number
	 * requeues a few items for nothing; reindexing them is harmless. Runs in the
	 * editor's request, hence the visitor timeout and circuit breaker: with the
	 * cluster slow or down the names wait for the next full reindex.
	 *
	 * @param int $post_id Item that was renamed, (un)published or deleted.
	 */
	private function requeue_referrers( int $post_id ): void {
		if ( ! $this->client->is_configured() || $this->client->live_breaker_open() ) {
			return;
		}
		$index = (string) $this->settings->get( 'index_name' );
		$res   = $this->client->search_live(
			$index,
			array(
				'size'             => self::RELATED_REQUEUE_MAX,
				'_source'          => false,
				'track_total_hits' => true,
				'query'            => array(
					'nested' => array(
						'path'  => 'metadata',
						'query' => array( 'term' => array( 'metadata.value_ids' => $post_id ) ),
					),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			$this->logger->warning(
				Logger::CHAN_INDEXER,
				'Falha ao procurar itens que apontam para o item alterado.',
				array(
					'item_id' => $post_id,
					'error'   => $res->get_error_message(),
				)
			);
			return;
		}
		$ids = array();
		foreach ( (array) ( $res['hits']['hits'] ?? array() ) as $hit ) {
			if ( isset( $hit['_id'] ) && (int) $hit['_id'] !== $post_id ) {
				$ids[] = (int) $hit['_id'];
			}
		}
		$total = (int) ( $res['hits']['total']['value'] ?? 0 );
		if ( $total > self::RELATED_REQUEUE_MAX ) {
			$this->logger->warning(
				Logger::CHAN_INDEXER,
				'Mais itens apontam para o item alterado do que o limite reenfileirado; os demais esperam a próxima reindexação completa.',
				array(
					'item_id'  => $post_id,
					'total'    => $total,
					'requeued' => count( $ids ),
				)
			);
		}
		if ( ! empty( $ids ) ) {
			$this->enqueue( $ids );
		}
	}

	/**
	 * Hook: on save_post, enqueue Tainacan items for re-index.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether existing or new.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- save_post signature; new and updated items are enqueued alike.
	public function on_save_post( $post_id, $post, $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! $this->is_tainacan_item_post_type( $post->post_type ) ) {
			return;
		}
		// Without an index to write to, queued IDs only make the option grow —
		// one serialized array rewritten on every save, forever. Routing being
		// off (engine=sql, ElasticPress) is not a reason to stop: the index
		// still has to be current the day routing is switched back on.
		if ( ! $this->client->is_configured() ) {
			return;
		}
		$this->enqueue( array( (int) $post_id ) );
	}

	/** Upper bound for the queue option: beyond this, enqueueing waits for the drain. */
	private const QUEUE_HARD_CAP = 500000;

	/**
	 * Hook: on delete, remove the document immediately if possible.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_before_delete_post( $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! $this->is_tainacan_item_post_type( $post->post_type ) ) {
			return;
		}
		if ( ! $this->client->is_configured() ) {
			return;
		}
		// Synchronous HTTP in the editor's request: with the cluster down each
		// permanently deleted item would hold the worker for es_timeout seconds.
		// purge_orphans() reconciles the index later.
		if ( $this->client->live_breaker_open() ) {
			return;
		}
		// Items naming this one (an authority record being deleted) are rebuilt
		// later, when get_post() no longer finds it and its title drops out.
		$this->requeue_referrers( (int) $post_id );
		$index = (string) $this->settings->get( 'index_name' );
		$res   = $this->client->delete_document( $index, (string) $post_id );
		if ( is_wp_error( $res ) ) {
			$this->logger->warning(
				Logger::CHAN_INDEXER,
				'Falha ao apagar documento do índice.',
				array(
					'item_id' => (int) $post_id,
					'error'   => $res->get_error_message(),
				)
			);
		}
	}

	/**
	 * Enqueue a list of item IDs.
	 *
	 * @param int[] $ids Item IDs.
	 */
	public function enqueue( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$size = $this->queue_size();
		if ( $size >= self::QUEUE_HARD_CAP ) {
			$this->logger->error(
				Logger::CHAN_INDEXER,
				'Fila de indexação cheia; itens não enfileirados.',
				array(
					'cap'   => self::QUEUE_HARD_CAP,
					'count' => count( $ids ),
				)
			);
			return 0;
		}

		// The queue is the plugin's own table (see install_queue_table()): one
		// INSERT IGNORE per chunk, O(1) per item, no lock, duplicates dropped by
		// the primary key. It used to be a single serialized option rewritten
		// whole on every save_post — importing 40,000 items meant ~80,000
		// rewrites of an option that grew to ~560 KB (O(n²) bytes), under a
		// GET_LOCK the cron drain was fighting for.
		$table = self::queue_table();
		$now   = current_time( 'mysql', true );
		$added = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $id ) {
				$values[] = '(%d, %s)';
				$args[]   = (int) $id;
				$args[]   = $now;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Plugin's own queue table (not a post type, not available via WP_Query); $wpdb->prefix is trusted; the VALUES list is a list of (%d, %s) placeholders matching the chunk, which the sniff cannot count; every value goes through prepare(); write path, nothing to cache.
			$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (item_id, enqueued_at) VALUES " . implode( ',', $values ), $args ) );
			// phpcs:enable
			if ( false !== $result ) {
				$added += (int) $result;
			}
		}

		$this->metrics->observe_queue_size( $size + $added );
		return $added;
	}

	/**
	 * Remove IDs from the queue (processed, or cancelled).
	 *
	 * @param int[] $ids Item IDs.
	 */
	private function dequeue( array $ids ): void {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$table = self::queue_table();
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own queue table; IN-clause built with array_fill of %d placeholders matching $chunk count (the sniff cannot count them); write path.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE item_id IN ({$in})", $chunk ) );
			// phpcs:enable
		}
	}

	/**
	 * The next IDs to index, oldest enqueue first (a re-queued failure gets a
	 * new timestamp and goes to the back instead of blocking the head).
	 *
	 * @param int $limit Batch size.
	 * @return int[]
	 */
	private function next_batch( int $limit ): array {
		global $wpdb;
		$table = self::queue_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own queue table; the head changes with every batch, so caching would return stale IDs; LIMIT via %d.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT item_id FROM {$table} ORDER BY enqueued_at ASC, item_id ASC LIMIT %d", max( 1, $limit ) ) );
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Name of the queue table (`{prefix}tnc_idxmgr_queue`).
	 */
	public static function queue_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tnc_idxmgr_queue';
	}

	/**
	 * Create or upgrade the queue table (activation and version upgrade).
	 */
	public static function install_queue_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::queue_table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			item_id BIGINT(20) UNSIGNED NOT NULL,
			enqueued_at DATETIME NOT NULL,
			PRIMARY KEY  (item_id),
			KEY enqueued_idx (enqueued_at)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Move the pre-1.4.2 option-based queue into the table, once.
	 */
	public static function migrate_queue_option(): void {
		global $wpdb;
		$old = get_option( self::QUEUE_OPTION, null );
		if ( null === $old ) {
			return;
		}
		$ids = is_array( $old ) ? array_values( array_unique( array_filter( array_map( 'absint', $old ) ) ) ) : array();
		if ( $ids ) {
			$table = self::queue_table();
			$now   = current_time( 'mysql', true );
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$values = array();
				$args   = array();
				foreach ( $chunk as $id ) {
					$values[] = '(%d, %s)';
					$args[]   = (int) $id;
					$args[]   = $now;
				}
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One-off migration into the plugin's own table; VALUES is a list of (%d, %s) placeholders matching the chunk, which the sniff cannot count.
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (item_id, enqueued_at) VALUES " . implode( ',', $values ), $args ) );
				// phpcs:enable
			}
		}
		delete_option( self::QUEUE_OPTION );
	}

	/**
	 * Add every Tainacan item to the queue (full reindex bootstrap).
	 */
	public function enqueue_all(): int {
		$ids = $this->fetch_all_tainacan_item_ids();
		$this->enqueue( $ids );
		$size = $this->queue_size();
		$this->set_state( self::STATE_RUNNING );
		$this->metrics->observe_queue_size( $size );
		$this->logger->info( Logger::CHAN_INDEXER, 'Fila de reindexação total preenchida.', array( 'count' => $size ) );
		return $size;
	}

	/**
	 * Add every item of one collection to the queue.
	 */
	public function enqueue_collection( int $collection_id ): int {
		$ids = $this->fetch_collection_item_ids( $collection_id );
		$this->enqueue( $ids );
		$size = $this->queue_size();
		$this->set_state( self::STATE_RUNNING );
		$this->metrics->observe_queue_size( $size );
		$this->logger->info(
			Logger::CHAN_INDEXER,
			'Fila de reindexação por coleção preenchida.',
			array(
				'collection_id' => $collection_id,
				'count'         => $size,
			)
		);
		return $size;
	}

	/**
	 * Process one batch from the queue. Returns array with progress info.
	 */
	public function process_batch(): array {
		if ( self::STATE_PAUSED === $this->get_state() ) {
			return array(
				'ok'        => true,
				'processed' => 0,
				'remaining' => count( $this->load_queue() ),
				'state'     => self::STATE_PAUSED,
				'message'   => __( 'Indexador pausado.', 'tainacan-index-manager' ),
			);
		}

		if ( ! $this->client->is_configured() ) {
			return array(
				'ok'        => false,
				'skipped'   => 'es_not_configured',
				'processed' => 0,
				'remaining' => $this->queue_size(),
				'state'     => $this->get_state(),
				'message'   => __( 'Elasticsearch não configurado.', 'tainacan-index-manager' ),
			);
		}

		// Cluster known to be down (visitor breaker open) or a _bulk that just
		// failed: wait instead of holding a worker for the 60 s bulk timeout
		// every minute and logging three lines per tick for a day-long outage.
		if ( $this->client->live_breaker_open() || false !== get_transient( self::BACKOFF_TRANSIENT ) ) {
			return array(
				'ok'        => false,
				'skipped'   => 'es_backoff',
				'processed' => 0,
				'remaining' => $this->queue_size(),
				'state'     => $this->get_state(),
				'message'   => __( 'Elasticsearch indisponível há pouco; o lote espera.', 'tainacan-index-manager' ),
			);
		}

		if ( 0 === $this->queue_size() ) {
			$this->set_state( self::STATE_FINISHED );
			return array(
				'ok'        => true,
				'processed' => 0,
				'remaining' => 0,
				'state'     => self::STATE_FINISHED,
				'message'   => __( 'Fila vazia.', 'tainacan-index-manager' ),
			);
		}

		// One batch at a time. The minute tick, the "process batch" button and a
		// late cron run can all arrive together; two of them building the same
		// head of the queue index every document twice and double the _bulk load.
		if ( ! $this->acquire_batch_lock() ) {
			return array(
				'ok'        => true,
				'processed' => 0,
				'remaining' => $this->queue_size(),
				'state'     => $this->get_state(),
				'message'   => __( 'Outro lote está em andamento.', 'tainacan-index-manager' ),
			);
		}

		try {
			// Read under the lock: another process may have drained this head of
			// the queue between the size check and the lock.
			$batch_size = (int) $this->settings->get( 'batch_size', 50 );
			$before     = $this->queue_size();
			$batch      = $this->next_batch( $batch_size );
			if ( empty( $batch ) ) {
				$this->set_state( self::STATE_FINISHED );
				return array(
					'ok'        => true,
					'processed' => 0,
					'remaining' => 0,
					'state'     => self::STATE_FINISHED,
					'message'   => __( 'Fila vazia.', 'tainacan-index-manager' ),
				);
			}
			return $this->run_batch( $batch, $before );
		} finally {
			$this->release_batch_lock();
		}
	}

	/** Transient set after a failed _bulk; ticks skip while it lives. */
	private const BACKOFF_TRANSIENT = 'tim_index_backoff';

	/**
	 * Named MySQL lock for the batch (same mechanism as the queue): atomic, no
	 * TTL to tune — a bulk of 1,000 documents can outlive any fixed TTL — and
	 * released by the server if the PHP process dies with the connection.
	 */
	private function batch_lock_name(): string {
		global $wpdb;
		return 'tim_batch_' . md5( DB_NAME . '|' . $wpdb->options );
	}

	private function acquire_batch_lock(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock; nothing to cache.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->batch_lock_name(), 0 ) );
	}

	private function release_batch_lock(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock; nothing to cache.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->batch_lock_name() ) );
	}

	/**
	 * The body of process_batch(), run under the batch lock.
	 */
	private function run_batch( array $batch, int $queue_before ): array {
		$this->set_state( self::STATE_RUNNING );
		$start_ts = microtime( true );

		$index   = (string) $this->settings->get( 'index_name' );
		$lines   = array();
		$built   = 0;
		$skipped = array();

		foreach ( $batch as $item_id ) {
			$doc = $this->build_document( (int) $item_id );
			if ( null === $doc ) {
				$skipped[] = (int) $item_id;
				continue;
			}
			$lines[] = array(
				'index' => array(
					'_index' => $index,
					'_id'    => (string) $item_id,
				),
			);
			$lines[] = $doc;
			++$built;
		}

		$indexed       = 0;
		$failed        = array();
		$error_summary = array(); // Keyed by ES error type: count, sample reason and sample item id.

		if ( ! empty( $lines ) ) {
			$res = $this->client->bulk( $lines );
			if ( is_wp_error( $res ) ) {
				$this->logger->error(
					Logger::CHAN_INDEXER,
					'Falha no _bulk.',
					array(
						'count' => $built,
						'error' => $res->get_error_message(),
					)
				);
				// Back off for 5 minutes: the next ticks skip instead of repeating
				// a 60 s timeout per minute (see process_batch()).
				set_transient( self::BACKOFF_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
				return array(
					'ok'        => false,
					'processed' => 0,
					'remaining' => $queue_before,
					'state'     => $this->get_state(),
					'message'   => $res->get_error_message(),
				);
			}

			if ( ! empty( $res['errors'] ) && ! empty( $res['items'] ) && is_array( $res['items'] ) ) {
				foreach ( $res['items'] as $item ) {
					$op = is_array( $item ) ? reset( $item ) : array();
					if ( isset( $op['error'] ) ) {
						$failed_id = isset( $op['_id'] ) ? (int) $op['_id'] : 0;
						if ( $failed_id > 0 ) {
							$failed[] = $failed_id;
						}

						$err_obj = is_array( $op['error'] ) ? $op['error'] : array(
							'type'   => 'unknown',
							'reason' => (string) $op['error'],
						);
						$type    = isset( $err_obj['type'] ) ? (string) $err_obj['type'] : 'unknown';
						$reason  = isset( $err_obj['reason'] ) ? (string) $err_obj['reason'] : '';
						// Drill into caused_by if present — that's where the actual diagnostic lives.
						if ( isset( $err_obj['caused_by']['reason'] ) ) {
							$reason .= ' [caused_by: ' . (string) $err_obj['caused_by']['reason'] . ']';
						}
						if ( ! isset( $error_summary[ $type ] ) ) {
							$error_summary[ $type ] = array(
								'count'         => 0,
								'sample_reason' => $reason,
								'sample_id'     => $failed_id,
							);
						}
						++$error_summary[ $type ]['count'];
					} else {
						++$indexed;
					}
				}
			} else {
				$indexed = $built;
			}
		}

		// Surface the actual ES error to logs so the dashboard isn't a black box.
		// One log line per error type per batch — bounded volume even at scale.
		if ( ! empty( $error_summary ) ) {
			foreach ( $error_summary as $type => $info ) {
				$this->logger->error(
					Logger::CHAN_INDEXER,
					sprintf(
						/* translators: %1$d count, %2$s ES error type */
						__( '%1$d itens rejeitados pelo Elasticsearch (%2$s).', 'tainacan-index-manager' ),
						(int) $info['count'],
						$type
					),
					array(
						'count'         => (int) $info['count'],
						'error_type'    => $type,
						'sample_reason' => $info['sample_reason'],
						'sample_id'     => (int) $info['sample_id'],
					)
				);
			}
		}

		// Remove processed (built+skipped) from the queue regardless of indexing outcome;
		// failures are tracked separately and re-tried by future runs/cron up to max_retries.
		$processed_ids = array_merge( $batch, $skipped );

		// Re-queue failures that haven't exceeded max retries.
		$failures  = $this->load_failures();
		$max_retry = (int) $this->settings->get( 'max_retries', 3 );
		$dropped   = 0;
		$requeue   = array();

		foreach ( $failed as $fid ) {
			$failures[ $fid ] = ( $failures[ $fid ] ?? 0 ) + 1;
			if ( $failures[ $fid ] <= $max_retry ) {
				$requeue[] = $fid;
			} else {
				++$dropped;
			}
		}

		// Row-level DELETE/INSERT: items saved while the batch was being built
		// and sent live in their own rows and are untouched by this write.
		$this->dequeue( $processed_ids );
		if ( $requeue ) {
			$this->enqueue( $requeue );
		}
		update_option( self::FAILURES_OPTION, $failures, false );

		$remaining = $this->queue_size();
		if ( 0 === $remaining ) {
			$this->set_state( self::STATE_FINISHED );
		}

		$duration_ms = (int) round( ( microtime( true ) - $start_ts ) * 1000 );
		$this->settings->mark_timestamp( 'last_index_run_ts' );
		$this->metrics->record_run(
			array(
				'ts'            => time(),
				'duration_ms'   => $duration_ms,
				'built'         => $built,
				'indexed'       => $indexed,
				'failed'        => count( $failed ),
				'skipped'       => count( $skipped ),
				'dropped'       => $dropped,
				'queue_before'  => $queue_before,
				'queue_after'   => $remaining,
				'failed_ids'    => $failed,
				'error_summary' => $error_summary,
			)
		);
		$this->logger->info(
			Logger::CHAN_INDEXER,
			'Lote de indexação processado.',
			array(
				'built'       => $built,
				'indexed'     => $indexed,
				'failed'      => count( $failed ),
				'skipped'     => count( $skipped ),
				'dropped'     => $dropped,
				'remaining'   => $remaining,
				'duration_ms' => $duration_ms,
			)
		);

		return array(
			'ok'        => true,
			'processed' => $indexed,
			'failed'    => count( $failed ),
			'skipped'   => count( $skipped ),
			'dropped'   => $dropped,
			'remaining' => $remaining,
			'state'     => $this->get_state(),
			'message'   => sprintf(
				/* translators: %1$d indexed, %2$d remaining */
				__( '%1$d itens indexados, %2$d restantes.', 'tainacan-index-manager' ),
				$indexed,
				$remaining
			),
		);
	}

	/**
	 * Build the ES document from a Tainacan item / WP post.
	 *
	 * Uses Tainacan repositories when available for richer metadata; falls
	 * back to WP_Post/postmeta for environments where the repository call
	 * isn't reachable.
	 *
	 * @return array|null Null when post no longer exists or isn't indexable.
	 */
	private function build_document( int $item_id ): ?array {
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( ! $this->is_tainacan_item_post_type( $post->post_type ) ) {
			return null;
		}

		// Every value below is coerced to the type declared in the index
		// mapping. WordPress helpers like get_permalink() and
		// get_the_post_thumbnail_url() can return `false` for drafts/no
		// thumbnail, which would be rejected by ES as
		// `mapper_parsing_exception` on `keyword`/`date` fields.
		$permalink     = get_permalink( $post );
		$thumbnail_url = get_the_post_thumbnail_url( $post, 'medium' );
		$date_created  = get_post_time( 'c', true, $post );
		$date_modified = get_post_modified_time( 'c', true, $post );

		$doc = array(
			'item_id'         => (int) $post->ID,
			'post_type'       => (string) $post->post_type,
			'post_status'     => (string) $post->post_status,
			'title'           => (string) $post->post_title,
			'description'     => wp_strip_all_tags( (string) $post->post_excerpt ),
			'content'         => wp_strip_all_tags( (string) $post->post_content ),
			'author_id'       => (int) $post->post_author,
			'author_name'     => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'permalink'       => is_string( $permalink ) ? $permalink : '',
			'thumbnail'       => is_string( $thumbnail_url ) ? $thumbnail_url : '',
			'taxonomies'      => array(),
			'metadata'        => array(),
			'collection_id'   => 0,
			'collection_name' => '',
			'identifier'      => '',
		);

		// Date fields with `"type": "date"` reject non-strings. Omit entirely
		// when WP couldn't compute a valid date, rather than emitting null/false.
		if ( is_string( $date_created ) && '' !== $date_created ) {
			$doc['date_created'] = $date_created;
		}
		if ( is_string( $date_modified ) && '' !== $date_modified ) {
			$doc['date_modified'] = $date_modified;
		}
		$title_sort = self::title_sort_key( (string) $post->post_title );
		if ( null !== $title_sort ) {
			$doc['title_sort'] = $title_sort;
		}

		$taxes = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxes as $tax_slug => $tax_obj ) {
			// Full term objects (not just names): Tainacan's tax_query filters by
			// term_id, so the index needs the IDs to be able to answer those queries.
			$terms = wp_get_post_terms( $post->ID, $tax_slug );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$names = array();
				$ids   = array();
				foreach ( $terms as $term ) {
					if ( ! is_object( $term ) ) {
						continue;
					}
					if ( isset( $term->name ) ) {
						$names[] = (string) $term->name;
					}
					if ( isset( $term->term_id ) ) {
						$ids[] = (int) $term->term_id;
					}
				}
				$doc['taxonomies'][] = array(
					'slug'     => $tax_slug,
					'terms'    => $names,
					'term_ids' => $ids,
				);
			}
		}

		if ( class_exists( '\\Tainacan\\Repositories\\Items' ) && class_exists( '\\Tainacan\\Repositories\\Item_Metadata' ) ) {
			try {
				$items_repo = call_user_func( array( '\\Tainacan\\Repositories\\Items', 'get_instance' ) );
				$item       = $items_repo->fetch( (int) $post->ID );

				if ( is_object( $item ) ) {
					if ( method_exists( $item, 'get_collection' ) ) {
						$coll = $item->get_collection();
						if ( is_object( $coll ) ) {
							$doc['collection_id']   = (int) $coll->get_id();
							$doc['collection_name'] = (string) $coll->get_name();
						}
					}

					$meta_repo = call_user_func( array( '\\Tainacan\\Repositories\\Item_Metadata', 'get_instance' ) );
					$mlist     = $meta_repo->fetch( $item, 'OBJECT' );
					if ( is_array( $mlist ) ) {
						foreach ( $mlist as $im ) {
							if ( ! is_object( $im ) || ! method_exists( $im, 'get_metadatum' ) ) {
								continue;
							}
							$metadatum = $im->get_metadatum();
							if ( ! is_object( $metadatum ) ) {
								continue;
							}
							$value = method_exists( $im, 'get_value' ) ? $im->get_value() : null;
							$mtype = method_exists( $metadatum, 'get_metadata_type' ) ? (string) $metadatum->get_metadata_type() : '';
							$entry = array(
								'slug'          => method_exists( $metadatum, 'get_slug' ) ? (string) $metadatum->get_slug() : '',
								'metadatum_id'  => method_exists( $metadatum, 'get_id' ) ? (int) $metadatum->get_id() : 0,
								'label'         => method_exists( $metadatum, 'get_name' ) ? (string) $metadatum->get_name() : '',
								'value_text'    => '',
								'value_keyword' => array(),
							);

							// Every value is kept as its own keyword entry. Collapsing a
							// multivalued metadatum into `$flat[0]` (the previous behaviour)
							// made facets and exact filters silently lose all values but
							// the first.
							$parsed = $this->flatten_metadata_value( $value, $mtype );

							$entry['value_text']    = implode( ' | ', $parsed['labels'] );
							$entry['value_keyword'] = $parsed['texts'];
							if ( ! empty( $parsed['ids'] ) ) {
								$entry['value_ids'] = $parsed['ids'];
							}
							if ( 1 === count( $parsed['texts'] ) && is_numeric( $parsed['texts'][0] ) ) {
								$entry['value_number'] = (float) $parsed['texts'][0];
							}

							$doc['metadata'][] = $entry;
						}
					}
				}
			} catch ( \Throwable $e ) {
				$this->logger->warning(
					Logger::CHAN_INDEXER,
					'Falha ao montar metadados Tainacan para item; usando fallback.',
					array(
						'item_id' => (int) $post->ID,
						'error'   => $e->getMessage(),
					)
				);
			}
		}

		// Heuristic identifier: GUID or slug.
		$identifier        = (string) get_post_meta( $post->ID, 'identifier', true );
		$doc['identifier'] = '' !== $identifier ? $identifier : (string) $post->post_name;

		return $doc;
	}

	/**
	 * Remove documents whose underlying WordPress item no longer exists (or is no
	 * longer in an indexable status).
	 *
	 * Deletions that happen while ES is unreachable — or before the delete hook was
	 * fixed — leave the document behind forever. Those orphans are not just dead
	 * weight: they are returned by searches, so users see items that were deleted.
	 * This walks the whole index with `search_after` and bulk-deletes what WordPress
	 * no longer has.
	 *
	 * @param int $batch      Documents to examine per round trip.
	 * @param int $max_batches Safety stop so a single run cannot loop forever.
	 * @return array{ok: bool, scanned: int, deleted: int, message: string}
	 */
	public function purge_orphans( int $batch = 1000, int $max_batches = 200 ): array {
		global $wpdb;

		$index   = (string) $this->settings->get( 'index_name' );
		$scanned = 0;
		$deleted = 0;

		if ( ! $this->client->is_configured() ) {
			return array(
				'ok'      => false,
				'scanned' => 0,
				'deleted' => 0,
				'message' => __( 'Elasticsearch não está configurado.', 'tainacan-index-manager' ),
			);
		}

		$batch        = max( 100, min( 5000, $batch ) );
		$search_after = null;

		for ( $round = 0; $round < $max_batches; $round++ ) {
			$payload = array(
				'size'    => $batch,
				'_source' => false,
				'sort'    => array( array( 'item_id' => 'asc' ) ),
			);
			if ( null !== $search_after ) {
				$payload['search_after'] = array( $search_after );
			}

			$res = $this->client->search( $index, $payload );
			if ( is_wp_error( $res ) ) {
				return array(
					'ok'      => false,
					'scanned' => $scanned,
					'deleted' => $deleted,
					'message' => $res->get_error_message(),
				);
			}

			$hits = $res['hits']['hits'] ?? array();
			if ( ! is_array( $hits ) || empty( $hits ) ) {
				break;
			}

			$ids = array();
			foreach ( $hits as $hit ) {
				if ( isset( $hit['_id'] ) ) {
					$ids[] = (int) $hit['_id'];
				}
				if ( isset( $hit['sort'][0] ) ) {
					$search_after = $hit['sort'][0];
				}
			}
			$ids = array_values( array_filter( array_unique( $ids ) ) );
			if ( empty( $ids ) ) {
				break;
			}
			$scanned += count( $ids );

			// One query per batch, by primary key: cheap even for large indexes.
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN-clause built with array_fill of %d placeholders matching $ids count; $wpdb->posts is trusted.
			$sql = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_status IN ('publish','private','draft')",
				$ids
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql prepared above; reconciliation against the index must read the database, not a cache.
			$alive = $wpdb->get_col( $sql );
			$alive = array_flip( array_map( 'intval', (array) $alive ) );

			$lines = array();
			foreach ( $ids as $id ) {
				if ( ! isset( $alive[ $id ] ) ) {
					$lines[] = array(
						'delete' => array(
							'_index' => $index,
							'_id'    => (string) $id,
						),
					);
				}
			}

			if ( ! empty( $lines ) ) {
				$bulk = $this->client->bulk( $lines );
				if ( is_wp_error( $bulk ) ) {
					return array(
						'ok'      => false,
						'scanned' => $scanned,
						'deleted' => $deleted,
						'message' => $bulk->get_error_message(),
					);
				}
				$deleted += count( $lines );
			}

			if ( count( $ids ) < $batch ) {
				break;
			}
		}

		$this->client->refresh( $index );

		$this->logger->info(
			Logger::CHAN_INDEXER,
			'Reconciliação do índice concluída.',
			array(
				'scanned' => $scanned,
				'deleted' => $deleted,
			)
		);

		return array(
			'ok'      => true,
			'scanned' => $scanned,
			'deleted' => $deleted,
			'message' => sprintf(
				/* translators: %1$d documents examined, %2$d documents removed */
				__( '%1$d documentos verificados, %2$d órfãos removidos.', 'tainacan-index-manager' ),
				$scanned,
				$deleted
			),
		);
	}

	/**
	 * Normalize any metadatum value into display texts plus the entity IDs behind it.
	 *
	 * Tainacan returns wildly different shapes depending on the metadatum type:
	 * scalars for text/numeric, `WP_Term` objects (or bare term IDs) for Taxonomy,
	 * item IDs or `Entities\Item` objects for Relationship. Facet aggregations and
	 * filter translation need the IDs, so they are extracted here rather than being
	 * flattened away into strings.
	 *
	 * `labels` is what the text search and LIKE filters read (`value_text`): the
	 * same as `texts`, plus, for a Relationship stored as a bare item ID, the
	 * title of that item — the author's name next to the number of the authority
	 * record. The ID stays, so a LIKE filter by ID matches as it did in SQL.
	 *
	 * @param mixed  $value Raw value from Item_Metadata::get_value().
	 * @param string $mtype Fully qualified Tainacan metadata type, when known.
	 * @return array{texts: string[], labels: string[], ids: int[]}
	 */
	private function flatten_metadata_value( $value, string $mtype = '' ): array {
		$texts     = array();
		$ids       = array();
		$relations = array();

		$is_relationship  = false !== strpos( $mtype, 'Relationship' );
		$is_entity_backed = ( false !== strpos( $mtype, 'Taxonomy' ) || $is_relationship );

		$items = is_array( $value ) ? $value : ( null === $value ? array() : array( $value ) );

		foreach ( $items as $v ) {
			if ( is_object( $v ) ) {
				// WP_Term.
				if ( isset( $v->term_id ) ) {
					$ids[] = (int) $v->term_id;
					if ( isset( $v->name ) && '' !== (string) $v->name ) {
						$texts[] = (string) $v->name;
					}
					continue;
				}
				// Tainacan entity (Term, Item, ...).
				if ( method_exists( $v, 'get_id' ) ) {
					$ids[] = (int) $v->get_id();
					if ( method_exists( $v, 'get_name' ) ) {
						$texts[] = (string) $v->get_name();
					} elseif ( method_exists( $v, 'get_title' ) ) {
						$texts[] = (string) $v->get_title();
					}
					continue;
				}
				$encoded = wp_json_encode( $v );
				if ( is_string( $encoded ) ) {
					$texts[] = $encoded;
				}
				continue;
			}

			if ( is_scalar( $v ) ) {
				$sv = trim( (string) $v );
				if ( '' === $sv ) {
					continue;
				}
				$texts[] = $sv;
				// For Taxonomy/Relationship metadata a bare numeric value *is* the entity ID.
				if ( $is_entity_backed && ctype_digit( $sv ) ) {
					$ids[] = (int) $sv;
					if ( $is_relationship ) {
						$relations[] = $sv;
					}
				}
				continue;
			}

			if ( is_array( $v ) ) {
				$encoded = wp_json_encode( $v );
				if ( is_string( $encoded ) ) {
					$texts[] = $encoded;
				}
			}
		}

		$labels = $texts;
		if ( ! empty( $relations ) ) {
			foreach ( array_unique( $relations ) as $related_id ) {
				$title = $this->related_item_label( (int) $related_id );
				if ( '' !== $title ) {
					$labels[] = $title;
				}
			}
		}

		return array(
			'texts'  => array_values( array_unique( $texts ) ),
			'labels' => array_values( array_unique( $labels ) ),
			'ids'    => array_values( array_unique( $ids ) ),
		);
	}

	/**
	 * Title of the item a Relationship value points to, for the text search.
	 *
	 * Only a published Tainacan item of a published collection lends its title:
	 * the index must not let a visitor find a work by the name of a record they
	 * cannot see (a private collection keeps its items as `publish`). When that
	 * item is renamed, unpublished or deleted, requeue_referrers() requeues the
	 * items pointing to it.
	 *
	 * @param int $related_id Item ID stored as the Relationship value.
	 */
	private function related_item_label( int $related_id ): string {
		// One get_post() per distinct record: with a persistent object cache the
		// records shared by many works come from memory; without one, a cold
		// full reindex pays one primary-key SELECT per distinct record.
		$related = get_post( $related_id );
		if ( ! $related instanceof \WP_Post || 'publish' !== $related->post_status ) {
			return '';
		}
		if ( ! preg_match( '/^tnc_col_(\d+)_item$/', $related->post_type, $m ) || 'publish' !== get_post_status( (int) $m[1] ) ) {
			return '';
		}
		return (string) $related->post_title;
	}

	/**
	 * Collation sort key of a title, hex-encoded.
	 *
	 * An ES keyword sort compares bytes; the hex of the ICU sort key keeps the
	 * key's byte order, which is the collation order. The collator is the one
	 * the facets already use to reproduce MySQL's utf8mb4_unicode_520_ci order,
	 * and trailing spaces are dropped because that collation pads them away.
	 *
	 * @param string $title Item title.
	 * @return string|null Null without the `intl` extension.
	 */
	public static function title_sort_key( string $title ): ?string {
		$collator = Facets_Integration::collator();
		if ( ! $collator instanceof \Collator ) {
			return null;
		}
		$key = $collator->getSortKey( rtrim( mb_substr( $title, 0, 500 ), ' ' ) );
		return is_string( $key ) ? bin2hex( $key ) : null;
	}

	/**
	 * Detect whether $post_type matches a Tainacan collection item post type.
	 */
	public function is_tainacan_item_post_type( string $post_type ): bool {
		// Cheap pre-filter: saving a page, menu or attachment must not cost a
		// WP_Query over all collections just to answer "no".
		if ( ! preg_match( '/^tnc_col_\d+_item$/', $post_type ) ) {
			return false;
		}
		static $cache = null;
		if ( null === $cache ) {
			$cache = array();
			if ( class_exists( '\\Tainacan\\Repositories\\Collections' ) ) {
				try {
					$repo = call_user_func( array( '\\Tainacan\\Repositories\\Collections', 'get_instance' ) );
					$cols = $repo->fetch( array( 'posts_per_page' => -1 ), 'OBJECT' );
					if ( is_array( $cols ) ) {
						foreach ( $cols as $c ) {
							if ( is_object( $c ) && method_exists( $c, 'get_db_identifier' ) ) {
								$cache[] = (string) $c->get_db_identifier();
							}
						}
					}
				} catch ( \Throwable $e ) {
					// Cache stays empty: nothing is enqueued until the next request.
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG; a silent failure here means items never reach the index.
						error_log( 'tainacan-index-manager: Collections::fetch failed: ' . $e->getMessage() );
					}
				}
			}
		}
		return in_array( $post_type, $cache, true );
	}

	/**
	 * Fetch all Tainacan item IDs across all collections.
	 *
	 * @return int[]
	 */
	public function fetch_all_tainacan_item_ids(): array {
		$ids = array();
		if ( class_exists( '\\Tainacan\\Repositories\\Items' ) ) {
			try {
				$repo  = call_user_func( array( '\\Tainacan\\Repositories\\Items', 'get_instance' ) );
				$query = $repo->fetch(
					array(
						'posts_per_page' => -1,
						'post_status'    => array( 'publish', 'private', 'draft' ),
						'fields'         => 'ids',
					)
				);
				if ( is_object( $query ) && isset( $query->posts ) && is_array( $query->posts ) ) {
					$ids = array_map( 'intval', $query->posts );
				}
			} catch ( \Throwable $e ) {
				$this->logger->warning( Logger::CHAN_INDEXER, 'Falha ao listar itens via repositório.', array( 'error' => $e->getMessage() ) );
			}
		}

		if ( empty( $ids ) ) {
			$types = $this->get_known_post_types();
			if ( ! empty( $types ) ) {
				$ids = get_posts(
					array(
						'post_type'      => $types,
						'posts_per_page' => -1,
						'post_status'    => array( 'publish', 'private', 'draft' ),
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);
				$ids = array_map( 'intval', (array) $ids );
			}
		}

		return $ids;
	}

	/**
	 * Fetch all item IDs from a specific collection.
	 *
	 * @return int[]
	 */
	public function fetch_collection_item_ids( int $collection_id ): array {
		if ( $collection_id <= 0 ) {
			return array();
		}
		if ( ! class_exists( '\\Tainacan\\Repositories\\Collections' ) || ! class_exists( '\\Tainacan\\Repositories\\Items' ) ) {
			return array();
		}
		try {
			$coll_repo  = call_user_func( array( '\\Tainacan\\Repositories\\Collections', 'get_instance' ) );
			$collection = $coll_repo->fetch( $collection_id );
			if ( ! is_object( $collection ) || ! method_exists( $collection, 'get_db_identifier' ) ) {
				return array();
			}
			$post_type = $collection->get_db_identifier();
			$ids       = get_posts(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => -1,
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			return array_map( 'intval', (array) $ids );
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				Logger::CHAN_INDEXER,
				'Falha ao listar itens da coleção.',
				array(
					'collection_id' => $collection_id,
					'error'         => $e->getMessage(),
				)
			);
			return array();
		}
	}

	/**
	 * Find Tainacan items not yet indexed.
	 *
	 * Strategy: pull every Tainacan item ID, query ES for which of these IDs
	 * exist (using terms query), enqueue the difference. For large repos this
	 * is chunked into batches of 1000 IDs per ES query.
	 */
	public function enqueue_missing(): int {
		if ( ! $this->client->is_configured() ) {
			return 0;
		}
		$all_ids = $this->fetch_all_tainacan_item_ids();
		if ( empty( $all_ids ) ) {
			return 0;
		}

		$index   = (string) $this->settings->get( 'index_name' );
		$missing = array();
		$chunks  = array_chunk( $all_ids, 1000 );

		foreach ( $chunks as $chunk ) {
			$res = $this->client->search(
				$index,
				array(
					'size'    => count( $chunk ),
					'_source' => false,
					'query'   => array(
						'terms' => array( 'item_id' => array_map( 'intval', $chunk ) ),
					),
				)
			);

			$found = array();
			if ( is_array( $res ) && isset( $res['hits']['hits'] ) && is_array( $res['hits']['hits'] ) ) {
				foreach ( $res['hits']['hits'] as $hit ) {
					if ( isset( $hit['_id'] ) ) {
						$found[ (int) $hit['_id'] ] = true;
					}
				}
			}

			foreach ( $chunk as $id ) {
				if ( ! isset( $found[ $id ] ) ) {
					$missing[] = (int) $id;
				}
			}
		}

		return $this->enqueue( $missing );
	}

	/**
	 * Every queued ID, oldest first (diagnostics; the batch uses next_batch()).
	 *
	 * @return int[]
	 */
	public function load_queue(): array {
		global $wpdb;
		$table = self::queue_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own queue table; contents change with every save and batch.
		$ids = $wpdb->get_col( "SELECT item_id FROM {$table} ORDER BY enqueued_at ASC, item_id ASC" );
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	public function load_failures(): array {
		$f = get_option( self::FAILURES_OPTION, array() );
		return is_array( $f ) ? $f : array();
	}

	public function queue_size(): int {
		global $wpdb;
		$table = self::queue_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own queue table; COUNT changes with every save and batch.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable
	}

	public function failure_count(): int {
		return count( array_filter( $this->load_failures(), static fn( $v ) => (int) $v > 0 ) );
	}

	public function clear_failures(): void {
		update_option( self::FAILURES_OPTION, array(), false );
	}

	public function metrics(): Indexer_Metrics {
		return $this->metrics;
	}

	public function get_state(): string {
		$s = (string) get_option( self::STATE_OPTION, self::STATE_IDLE );
		return in_array( $s, array( self::STATE_IDLE, self::STATE_RUNNING, self::STATE_PAUSED, self::STATE_FINISHED ), true )
			? $s
			: self::STATE_IDLE;
	}

	public function set_state( string $state ): void {
		$allowed = array( self::STATE_IDLE, self::STATE_RUNNING, self::STATE_PAUSED, self::STATE_FINISHED );
		if ( in_array( $state, $allowed, true ) ) {
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	public function pause(): void {
		$this->set_state( self::STATE_PAUSED );
		$this->logger->info( Logger::CHAN_INDEXER, 'Indexador pausado pelo administrador.' );
	}

	public function resume(): void {
		$this->set_state( self::STATE_RUNNING );
		$this->logger->info( Logger::CHAN_INDEXER, 'Indexador retomado pelo administrador.' );
	}

	public function cancel(): void {
		global $wpdb;
		$table = self::queue_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own queue table; write path.
		$wpdb->query( "DELETE FROM {$table}" );
		// phpcs:enable
		delete_transient( self::BACKOFF_TRANSIENT );
		$this->set_state( self::STATE_IDLE );
		$this->logger->info( Logger::CHAN_INDEXER, 'Fila de indexação cancelada.' );
	}

	/**
	 * Cached list of Tainacan collection post types.
	 *
	 * @return string[]
	 */
	private function get_known_post_types(): array {
		$types = array();
		if ( class_exists( '\\Tainacan\\Repositories\\Collections' ) ) {
			try {
				$repo = call_user_func( array( '\\Tainacan\\Repositories\\Collections', 'get_instance' ) );
				$cols = $repo->fetch( array( 'posts_per_page' => -1 ), 'OBJECT' );
				if ( is_array( $cols ) ) {
					foreach ( $cols as $c ) {
						if ( is_object( $c ) && method_exists( $c, 'get_db_identifier' ) ) {
							$types[] = (string) $c->get_db_identifier();
						}
					}
				}
			} catch ( \Throwable $e ) {
				// Best effort: the SQL fallback for listing items takes over.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG; otherwise a repository failure is invisible.
					error_log( 'tainacan-index-manager: collections lookup failed: ' . $e->getMessage() );
				}
			}
		}
		return array_filter( array_unique( $types ) );
	}
}
