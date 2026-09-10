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

							$entry['value_text']    = implode( ' | ', $parsed['texts'] );
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

		$batch      = max( 100, min( 5000, $batch ) );
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
			$sql          = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from a counted int array.
				"SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_status IN ('publish','private','draft')",
				$ids
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reconciliation task, must bypass caches.
			$alive = $wpdb->get_col( $sql );
			$alive = array_flip( array_map( 'intval', (array) $alive ) );

			$lines = array();
			foreach ( $ids as $id ) {
				if ( ! isset( $alive[ $id ] ) ) {
					$lines[] = array( 'delete' => array( '_index' => $index, '_id' => (string) $id ) );
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

		$this->logger->info( Logger::CHAN_INDEXER, 'Reconciliação do índice concluída.', array(
			'scanned' => $scanned,
			'deleted' => $deleted,
		) );

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
	 * @param mixed  $value Raw value from Item_Metadata::get_value().
	 * @param string $mtype Fully qualified Tainacan metadata type, when known.
	 * @return array{texts: string[], ids: int[]}
	 */
	private function flatten_metadata_value( $value, string $mtype = '' ): array {
		$texts = array();
		$ids   = array();

		$is_entity_backed = ( false !== strpos( $mtype, 'Taxonomy' ) || false !== strpos( $mtype, 'Relationship' ) );

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

		return array(
			'texts' => array_values( array_unique( $texts ) ),
			'ids'   => array_values( array_unique( $ids ) ),
		);
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
