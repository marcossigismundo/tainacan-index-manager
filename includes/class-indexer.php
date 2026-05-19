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
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ) );
	}

	/**
	 * Hook: on save_post, enqueue Tainacan items for re-index.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether existing or new.
	 */
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
		$this->enqueue( array( (int) $post_id ) );
	}

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
		$index = (string) $this->settings->get( 'index_name' );
		$res   = $this->client->delete_document( $index, (string) $post_id );
		if ( is_wp_error( $res ) ) {
			$this->logger->warning( Logger::CHAN_INDEXER, 'Falha ao apagar documento do índice.', array(
				'item_id' => (int) $post_id,
				'error'   => $res->get_error_message(),
			) );
		}
	}

	/**
	 * Enqueue a list of item IDs.
	 *
	 * @param int[] $ids Item IDs.
	 */
	public function enqueue( array $ids ): int {
		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return 0;
		}

		$queue   = $this->load_queue();
		$before  = count( $queue );
		$queue   = array_values( array_unique( array_merge( $queue, $ids ) ) );
		$added   = count( $queue ) - $before;

		update_option( self::QUEUE_OPTION, $queue, false );
		$this->metrics->observe_queue_size( count( $queue ) );
		return $added;
	}

	/**
	 * Replace the entire queue with all Tainacan items (full reindex bootstrap).
	 */
	public function enqueue_all(): int {
		$ids   = $this->fetch_all_tainacan_item_ids();
		$queue = array_values( array_unique( $ids ) );
		update_option( self::QUEUE_OPTION, $queue, false );
		$this->set_state( self::STATE_RUNNING );
		$this->metrics->observe_queue_size( count( $queue ) );
		$this->logger->info( Logger::CHAN_INDEXER, 'Fila de reindexação total preenchida.', array( 'count' => count( $queue ) ) );
		return count( $queue );
	}

	/**
	 * Replace the queue with all items from a specific collection.
	 */
	public function enqueue_collection( int $collection_id ): int {
		$ids   = $this->fetch_collection_item_ids( $collection_id );
		$queue = array_values( array_unique( $ids ) );
		update_option( self::QUEUE_OPTION, $queue, false );
		$this->set_state( self::STATE_RUNNING );
		$this->metrics->observe_queue_size( count( $queue ) );
		$this->logger->info( Logger::CHAN_INDEXER, 'Fila de reindexação por coleção preenchida.', array(
			'collection_id' => $collection_id,
			'count'         => count( $queue ),
		) );
		return count( $queue );
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
				'processed' => 0,
				'remaining' => count( $this->load_queue() ),
				'state'     => $this->get_state(),
				'message'   => __( 'Elasticsearch não configurado.', 'tainacan-index-manager' ),
			);
		}

		$queue = $this->load_queue();
		if ( empty( $queue ) ) {
			$this->set_state( self::STATE_FINISHED );
			return array(
				'ok'        => true,
				'processed' => 0,
				'remaining' => 0,
				'state'     => self::STATE_FINISHED,
				'message'   => __( 'Fila vazia.', 'tainacan-index-manager' ),
			);
		}

		$this->set_state( self::STATE_RUNNING );
		$batch_size   = (int) $this->settings->get( 'batch_size', 50 );
		$batch        = array_slice( $queue, 0, $batch_size );
		$queue_before = count( $queue );
		$start_ts     = microtime( true );

		$index    = (string) $this->settings->get( 'index_name' );
		$lines    = array();
		$built    = 0;
		$skipped  = array();

		foreach ( $batch as $item_id ) {
			$doc = $this->build_document( (int) $item_id );
			if ( null === $doc ) {
				$skipped[] = (int) $item_id;
				continue;
			}
			$lines[] = array( 'index' => array( '_index' => $index, '_id' => (string) $item_id ) );
			$lines[] = $doc;
			++$built;
		}

		$indexed         = 0;
		$failed          = array();
		$error_summary   = array(); // type => array('count' => N, 'sample_reason' => string, 'sample_id' => int)

		if ( ! empty( $lines ) ) {
			$res = $this->client->bulk( $lines );
			if ( is_wp_error( $res ) ) {
				$this->logger->error( Logger::CHAN_INDEXER, 'Falha no _bulk.', array(
					'count' => $built,
					'error' => $res->get_error_message(),
				) );
				return array(
					'ok'        => false,
					'processed' => 0,
					'remaining' => count( $queue ),
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

						$err_obj = is_array( $op['error'] ) ? $op['error'] : array( 'type' => 'unknown', 'reason' => (string) $op['error'] );
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
		$queue         = array_values( array_diff( $queue, $processed_ids ) );

		// Re-queue failures that haven't exceeded max retries.
		$failures   = $this->load_failures();
		$max_retry  = (int) $this->settings->get( 'max_retries', 3 );
		$dropped    = 0;

		foreach ( $failed as $fid ) {
			$failures[ $fid ] = ( $failures[ $fid ] ?? 0 ) + 1;
			if ( $failures[ $fid ] <= $max_retry ) {
				$queue[] = $fid;
			} else {
				++$dropped;
			}
		}
		update_option( self::QUEUE_OPTION, array_values( array_unique( $queue ) ), false );
		update_option( self::FAILURES_OPTION, $failures, false );

		$remaining = count( $queue );
		if ( 0 === $remaining ) {
			$this->set_state( self::STATE_FINISHED );
		}

		$duration_ms = (int) round( ( microtime( true ) - $start_ts ) * 1000 );
		$this->settings->mark_timestamp( 'last_index_run_ts' );
		$this->metrics->record_run( array(
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
		) );
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

		$taxes = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxes as $tax_slug => $tax_obj ) {
			$terms = wp_get_post_terms( $post->ID, $tax_slug, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$doc['taxonomies'][] = array(
					'slug'  => $tax_slug,
					'terms' => array_values( array_map( 'strval', $terms ) ),
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
							$entry = array(
								'slug'          => method_exists( $metadatum, 'get_slug' ) ? (string) $metadatum->get_slug() : '',
								'label'         => method_exists( $metadatum, 'get_name' ) ? (string) $metadatum->get_name() : '',
								'value_text'    => '',
								'value_keyword' => '',
							);

							if ( is_array( $value ) ) {
								$flat = array();
								foreach ( $value as $v ) {
									$flat[] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
								}
								$entry['value_text']    = implode( ' | ', $flat );
								$entry['value_keyword'] = $flat[0] ?? '';
							} elseif ( is_scalar( $value ) ) {
								$entry['value_text']    = (string) $value;
								$entry['value_keyword'] = (string) $value;
								if ( is_numeric( $value ) ) {
									$entry['value_number'] = (float) $value;
								}
							}

							$doc['metadata'][] = $entry;
						}
					}
				}
			} catch ( \Throwable $e ) {
				$this->logger->warning( Logger::CHAN_INDEXER, 'Falha ao montar metadados Tainacan para item; usando fallback.', array(
					'item_id' => (int) $post->ID,
					'error'   => $e->getMessage(),
				) );
			}
		}

		// Heuristic identifier: GUID or slug.
		$doc['identifier'] = (string) ( get_post_meta( $post->ID, 'identifier', true ) ?: $post->post_name );

		return $doc;
	}

	/**
	 * Detect whether $post_type matches a Tainacan collection item post type.
	 */
	public function is_tainacan_item_post_type( string $post_type ): bool {
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
					// best effort; cache stays empty.
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
				$query = $repo->fetch( array(
					'posts_per_page' => -1,
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'fields'         => 'ids',
				) );
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
				$ids = get_posts( array(
					'post_type'      => $types,
					'posts_per_page' => -1,
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'fields'         => 'ids',
					'no_found_rows'  => true,
				) );
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
			$ids       = get_posts( array(
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			) );
			return array_map( 'intval', (array) $ids );
		} catch ( \Throwable $e ) {
			$this->logger->warning( Logger::CHAN_INDEXER, 'Falha ao listar itens da coleção.', array(
				'collection_id' => $collection_id,
				'error'         => $e->getMessage(),
			) );
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
			$res = $this->client->search( $index, array(
				'size'    => count( $chunk ),
				'_source' => false,
				'query'   => array(
					'terms' => array( 'item_id' => array_map( 'intval', $chunk ) ),
				),
			) );

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

	public function load_queue(): array {
		$q = get_option( self::QUEUE_OPTION, array() );
		return is_array( $q ) ? array_map( 'intval', $q ) : array();
	}

	public function load_failures(): array {
		$f = get_option( self::FAILURES_OPTION, array() );
		return is_array( $f ) ? $f : array();
	}

	public function queue_size(): int {
		return count( $this->load_queue() );
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
		update_option( self::QUEUE_OPTION, array(), false );
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
				// best effort.
			}
		}
		return array_filter( array_unique( $types ) );
	}
}
