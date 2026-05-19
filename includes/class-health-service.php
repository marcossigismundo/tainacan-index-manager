<?php
/**
 * Health service: aggregates cluster, index and coverage info into a single snapshot.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and caches the health snapshot consumed by the dashboard, cron and
 * alert subsystems. Snapshots are short-lived (60s) to keep page loads cheap.
 */
final class Health_Service {

	private const SNAPSHOT_TRANSIENT = 'tainacan_idxmgr_health_snapshot';
	private const SNAPSHOT_TTL       = 60;

	private Settings $settings;
	private Logger $logger;
	private Alerts $alerts;
	private Elasticsearch_Client $client;

	public function __construct( Settings $settings, Logger $logger, Alerts $alerts ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->alerts   = $alerts;
		$this->client   = new Elasticsearch_Client( $settings, $logger );
	}

	/**
	 * Get the (possibly cached) snapshot.
	 *
	 * @param bool $force_refresh Bypass the transient.
	 */
	public function get_snapshot( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::SNAPSHOT_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$snapshot = $this->build_snapshot();
		set_transient( self::SNAPSHOT_TRANSIENT, $snapshot, self::SNAPSHOT_TTL );
		$this->settings->mark_timestamp( 'last_health_check_ts' );
		$this->evaluate_alerts( $snapshot );
		return $snapshot;
	}

	/**
	 * Force a recompute and return the fresh snapshot.
	 */
	public function refresh_snapshot(): array {
		delete_transient( self::SNAPSHOT_TRANSIENT );
		return $this->get_snapshot( true );
	}

	/**
	 * Build a fresh snapshot from live sources.
	 */
	private function build_snapshot(): array {
		$snapshot = array(
			'generated_at'             => time(),
			'engine_choice'            => (string) $this->settings->get( 'engine' ),
			'elasticpress_active'      => $this->is_elasticpress_active(),
			'elasticpress_version'     => $this->get_elasticpress_version(),
			'es_configured'            => $this->client->is_configured(),
			'es_reachable'             => false,
			'es_ping_ms'                => null,
			'es_version'                => null,
			'cluster_status'            => null,
			'cluster'                   => null,
			'index_name'                => (string) $this->settings->get( 'index_name' ),
			'index_exists'              => false,
			'index_doc_count'           => null,
			'index_size_bytes'          => null,
			'tainacan_item_count'       => $this->count_tainacan_items(),
			'coverage_pct'              => null,
			'divergence_pct'            => null,
			'divergence_threshold_pct'  => (int) $this->settings->get( 'divergence_threshold_pct', 5 ),
			'fallback_active'           => false,
			'last_health_check_ts'      => (int) $this->settings->get( 'last_health_check_ts', 0 ),
			'last_index_run_ts'         => (int) $this->settings->get( 'last_index_run_ts', 0 ),
			'effective_engine'          => 'sql_fallback',
			'overall_status'            => 'unknown',
			'overall_message'           => '',
			'tainacan_active'           => $this->is_tainacan_active(),
		);

		if ( ! $snapshot['es_configured'] ) {
			$snapshot['overall_status']   = 'critical';
			$snapshot['overall_message']  = __( 'Elasticsearch/OpenSearch não está configurado. A busca opera em modo SQL.', 'tainacan-index-manager' );
			$snapshot['fallback_active'] = true;
			return $snapshot;
		}

		$ping                     = $this->client->ping();
		$snapshot['es_ping_ms']   = $ping['ms'];
		$snapshot['es_reachable'] = (bool) $ping['ok'];

		if ( ! $snapshot['es_reachable'] ) {
			$snapshot['overall_status']  = 'critical';
			$snapshot['overall_message'] = __( 'Elasticsearch/OpenSearch indisponível. Busca em modo fallback.', 'tainacan-index-manager' );
			$snapshot['fallback_active'] = true;
			return $snapshot;
		}

		$health = $this->client->cluster_health();
		if ( is_array( $health ) ) {
			$snapshot['cluster_status'] = isset( $health['status'] ) ? sanitize_text_field( (string) $health['status'] ) : null;
			$snapshot['cluster']        = array(
				'status'                            => $snapshot['cluster_status'],
				'number_of_nodes'                   => isset( $health['number_of_nodes'] ) ? (int) $health['number_of_nodes'] : null,
				'active_primary_shards'             => isset( $health['active_primary_shards'] ) ? (int) $health['active_primary_shards'] : null,
				'active_shards'                     => isset( $health['active_shards'] ) ? (int) $health['active_shards'] : null,
				'relocating_shards'                 => isset( $health['relocating_shards'] ) ? (int) $health['relocating_shards'] : null,
				'initializing_shards'               => isset( $health['initializing_shards'] ) ? (int) $health['initializing_shards'] : null,
				'unassigned_shards'                 => isset( $health['unassigned_shards'] ) ? (int) $health['unassigned_shards'] : null,
				'active_shards_percent_as_number'   => isset( $health['active_shards_percent_as_number'] ) ? (float) $health['active_shards_percent_as_number'] : null,
			);
		}

		$exists                   = $this->client->index_exists( $snapshot['index_name'] );
		$snapshot['index_exists'] = is_bool( $exists ) ? $exists : false;

		if ( $snapshot['index_exists'] ) {
			$stats = $this->client->index_stats( $snapshot['index_name'] );
			if ( is_array( $stats ) ) {
				$idx_data = $stats['indices'][ $snapshot['index_name'] ] ?? null;
				if ( is_array( $idx_data ) ) {
					$snapshot['index_doc_count']  = isset( $idx_data['total']['docs']['count'] ) ? (int) $idx_data['total']['docs']['count'] : null;
					$snapshot['index_size_bytes'] = isset( $idx_data['total']['store']['size_in_bytes'] ) ? (int) $idx_data['total']['store']['size_in_bytes'] : null;
				}
			}
		}

		if ( null !== $snapshot['index_doc_count'] && $snapshot['tainacan_item_count'] > 0 ) {
			$snapshot['coverage_pct']   = round( ( $snapshot['index_doc_count'] / max( 1, $snapshot['tainacan_item_count'] ) ) * 100, 2 );
			$snapshot['divergence_pct'] = max( 0, round( 100 - $snapshot['coverage_pct'], 2 ) );
		}

		// Decide effective engine.
		$choice = (string) $this->settings->get( 'engine', 'auto' );
		if ( 'elasticpress' === $choice && $snapshot['elasticpress_active'] ) {
			$snapshot['effective_engine'] = 'elasticpress';
		} elseif ( 'own_indexer' === $choice ) {
			$snapshot['effective_engine'] = 'own_indexer';
		} elseif ( 'disabled' === $choice ) {
			$snapshot['effective_engine'] = 'sql_fallback';
			$snapshot['fallback_active'] = true;
		} elseif ( 'auto' === $choice ) {
			$snapshot['effective_engine'] = $snapshot['elasticpress_active'] ? 'elasticpress' : 'own_indexer';
		}

		// Final classification.
		if ( 'red' === $snapshot['cluster_status'] ) {
			$snapshot['overall_status']  = 'critical';
			$snapshot['overall_message'] = __( 'Cluster em estado RED: ação imediata necessária.', 'tainacan-index-manager' );
		} elseif ( 'yellow' === $snapshot['cluster_status'] ) {
			$snapshot['overall_status']  = 'warning';
			$snapshot['overall_message'] = __( 'Cluster em estado YELLOW: shards não totalmente alocados.', 'tainacan-index-manager' );
		} elseif ( ! $snapshot['index_exists'] ) {
			$snapshot['overall_status']  = 'warning';
			$snapshot['overall_message'] = __( 'O índice ainda não foi criado. Inicialize-o em Configurações.', 'tainacan-index-manager' );
		} elseif ( null !== $snapshot['divergence_pct'] && $snapshot['divergence_pct'] > $snapshot['divergence_threshold_pct'] ) {
			$snapshot['overall_status']  = 'warning';
			$snapshot['overall_message'] = sprintf(
				/* translators: %1$s = divergência %, %2$s = limite % */
				__( 'Cobertura do índice abaixo do limite (divergência %1$s%% > %2$s%%).', 'tainacan-index-manager' ),
				number_format_i18n( (float) $snapshot['divergence_pct'], 2 ),
				number_format_i18n( $snapshot['divergence_threshold_pct'] )
			);
		} else {
			$snapshot['overall_status']  = 'ok';
			$snapshot['overall_message'] = __( 'Busca operacional. O índice está atualizado.', 'tainacan-index-manager' );
		}

		return $snapshot;
	}

	/**
	 * Public wrapper to re-evaluate alerts against the current snapshot,
	 * useful from REST callbacks that need to dodge the cache-miss race
	 * with /health?refresh=1 (alerts would otherwise be served stale).
	 */
	public function reevaluate_alerts(): void {
		$this->evaluate_alerts( $this->get_snapshot() );
	}

	/**
	 * Convert WordPress error/snapshot fields into a list of human alerts.
	 *
	 * @param array $snapshot Built snapshot.
	 */
	private function evaluate_alerts( array $snapshot ): void {
		if ( ! $snapshot['es_configured'] ) {
			$this->alerts->raise( 'es_not_configured', Alerts::SEV_CRITICAL, __( 'Elasticsearch/OpenSearch não está configurado.', 'tainacan-index-manager' ) );
			return;
		}
		if ( ! $snapshot['es_reachable'] ) {
			$this->alerts->raise( 'es_unreachable', Alerts::SEV_CRITICAL, __( 'Elasticsearch/OpenSearch indisponível. A busca está em modo fallback.', 'tainacan-index-manager' ) );
			return;
		}
		$this->alerts->clear( 'es_unreachable' );
		$this->alerts->clear( 'es_not_configured' );

		if ( 'red' === $snapshot['cluster_status'] ) {
			$this->alerts->raise( 'cluster_red', Alerts::SEV_CRITICAL, __( 'Cluster em estado RED.', 'tainacan-index-manager' ) );
		} else {
			$this->alerts->clear( 'cluster_red' );
		}

		if ( 'yellow' === $snapshot['cluster_status'] ) {
			$this->alerts->raise( 'cluster_yellow', Alerts::SEV_WARNING, __( 'Cluster em estado YELLOW (shards não totalmente alocados).', 'tainacan-index-manager' ) );
		} else {
			$this->alerts->clear( 'cluster_yellow' );
		}

		if ( null !== $snapshot['divergence_pct'] && $snapshot['divergence_pct'] > $snapshot['divergence_threshold_pct'] ) {
			$this->alerts->raise(
				'index_divergence',
				Alerts::SEV_WARNING,
				sprintf(
					/* translators: %1$s divergência, %2$s limite */
					__( 'Divergência do índice em %1$s%% (limite %2$s%%).', 'tainacan-index-manager' ),
					number_format_i18n( (float) $snapshot['divergence_pct'], 2 ),
					number_format_i18n( $snapshot['divergence_threshold_pct'] )
				)
			);
		} else {
			$this->alerts->clear( 'index_divergence' );
		}

		if ( null !== $snapshot['es_ping_ms'] && $snapshot['es_ping_ms'] > 2000 ) {
			$this->alerts->raise(
				'es_slow',
				Alerts::SEV_WARNING,
				sprintf(
					/* translators: %d = latência em ms */
					__( 'Tempo de resposta do Elasticsearch elevado: %d ms.', 'tainacan-index-manager' ),
					(int) $snapshot['es_ping_ms']
				)
			);
		} else {
			$this->alerts->clear( 'es_slow' );
		}
	}

	/**
	 * Count Tainacan items using the Items repository when available, else WP_Query.
	 */
	public function count_tainacan_items(): int {
		if ( class_exists( '\\Tainacan\\Repositories\\Items' ) ) {
			try {
				$repo  = call_user_func( array( '\\Tainacan\\Repositories\\Items', 'get_instance' ) );
				$query = $repo->fetch( array(
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				) );
				if ( is_object( $query ) && property_exists( $query, 'found_posts' ) ) {
					return (int) $query->found_posts;
				}
			} catch ( \Throwable $e ) {
				$this->logger->warning( Logger::CHAN_HEALTH, 'Falha ao consultar Items via repositório Tainacan.', array( 'error' => $e->getMessage() ) );
			}
		}

		$post_types  = $this->discover_tainacan_post_types();
		$total       = 0;
		foreach ( $post_types as $pt ) {
			$counts = wp_count_posts( $pt );
			if ( $counts ) {
				$total += (int) ( $counts->publish ?? 0 ) + (int) ( $counts->private ?? 0 ) + (int) ( $counts->draft ?? 0 );
			}
		}
		return $total;
	}

	/**
	 * Discover Tainacan item post types (one per collection).
	 *
	 * @return string[]
	 */
	public function discover_tainacan_post_types(): array {
		$types = array();
		if ( class_exists( '\\Tainacan\\Repositories\\Collections' ) ) {
			try {
				$repo        = call_user_func( array( '\\Tainacan\\Repositories\\Collections', 'get_instance' ) );
				$collections = $repo->fetch( array(
					'posts_per_page' => -1,
					'post_status'    => array( 'publish', 'private', 'draft' ),
				), 'OBJECT' );

				if ( is_array( $collections ) ) {
					foreach ( $collections as $coll ) {
						if ( is_object( $coll ) && method_exists( $coll, 'get_db_identifier' ) ) {
							$types[] = (string) $coll->get_db_identifier();
						}
					}
				}
			} catch ( \Throwable $e ) {
				$this->logger->warning( Logger::CHAN_HEALTH, 'Falha ao listar coleções do Tainacan.', array( 'error' => $e->getMessage() ) );
			}
		}
		return array_filter( array_unique( $types ) );
	}

	public function is_tainacan_active(): bool {
		return defined( 'TAINACAN_VERSION' ) || class_exists( '\\Tainacan\\Theme_Helper' ) || class_exists( '\\Tainacan\\Repositories\\Items' );
	}

	public function is_elasticpress_active(): bool {
		return defined( 'EP_VERSION' ) || class_exists( '\\ElasticPress\\Elasticsearch' );
	}

	public function get_elasticpress_version(): ?string {
		if ( defined( 'EP_VERSION' ) ) {
			return (string) EP_VERSION;
		}
		return null;
	}
}
