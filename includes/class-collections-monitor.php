<?php
/**
 * Per-collection coverage and divergence monitor.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Compares item counts per collection between Tainacan and the search index.
 * Caches results in a transient (5 min) to keep dashboard cheap.
 */
final class Collections_Monitor {

	private const TRANSIENT_KEY = 'tainacan_idxmgr_collections_report';
	private const TTL           = 300;

	private Settings $settings;
	private Logger $logger;
	private Elasticsearch_Client $client;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->client   = new Elasticsearch_Client( $settings, $logger );
	}

	/**
	 * Get the (cached) per-collection report.
	 *
	 * @param bool $force_refresh Bypass cache.
	 */
	public function get_report( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$report = $this->build_report();
		set_transient( self::TRANSIENT_KEY, $report, self::TTL );
		return $report;
	}

	private function build_report(): array {
		$threshold = (int) $this->settings->get( 'divergence_threshold_pct', 5 );
		$index     = (string) $this->settings->get( 'index_name' );
		$es_ok     = $this->client->is_configured();

		$rows = array();

		if ( ! class_exists( '\\Tainacan\\Repositories\\Collections' ) ) {
			return array(
				'generated_at' => time(),
				'threshold'    => $threshold,
				'rows'         => array(),
				'message'      => __( 'Tainacan não está disponível.', 'tainacan-index-manager' ),
			);
		}

		try {
			$repo = call_user_func( array( '\\Tainacan\\Repositories\\Collections', 'get_instance' ) );
			$cols = $repo->fetch( array(
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'private' ),
			), 'OBJECT' );

			if ( ! is_array( $cols ) ) {
				$cols = array();
			}

			foreach ( $cols as $coll ) {
				if ( ! is_object( $coll ) || ! method_exists( $coll, 'get_db_identifier' ) ) {
					continue;
				}
				$collection_id = method_exists( $coll, 'get_id' ) ? (int) $coll->get_id() : 0;
				$post_type     = (string) $coll->get_db_identifier();
				$name          = method_exists( $coll, 'get_name' ) ? (string) $coll->get_name() : $post_type;

				$tainacan_count = $this->count_posts_in_post_type( $post_type );

				$indexed_count = null;
				$indexed_error = '';
				if ( $es_ok ) {
					$res = $this->client->count( $index, array(
						'query' => array(
							'bool' => array(
								'must' => array(
									array( 'term' => array( 'collection_id' => $collection_id ) ),
								),
							),
						),
					) );
					if ( is_wp_error( $res ) ) {
						$indexed_error = $res->get_error_message();
						$this->logger->warning( Logger::CHAN_HEALTH, 'Falha ao contar itens indexados da coleção.', array(
							'collection_id' => $collection_id,
							'error'         => $indexed_error,
						) );
					} else {
						$indexed_count = (int) $res;
					}
				}

				$coverage_pct   = null;
				$divergence_pct = null;
				if ( $tainacan_count > 0 && null !== $indexed_count ) {
					$coverage_pct   = round( ( $indexed_count / max( 1, $tainacan_count ) ) * 100, 2 );
					$divergence_pct = max( 0, round( 100 - $coverage_pct, 2 ) );
				}

				$rows[] = array(
					'collection_id'  => $collection_id,
					'collection_name' => $name,
					'post_type'      => $post_type,
					'tainacan_count' => $tainacan_count,
					'indexed_count'  => $indexed_count,
					'coverage_pct'   => $coverage_pct,
					'divergence_pct' => $divergence_pct,
					'over_threshold' => ( null !== $divergence_pct && $divergence_pct > $threshold ),
					'error'          => $indexed_error,
				);
			}
		} catch ( \Throwable $e ) {
			$this->logger->warning( Logger::CHAN_HEALTH, 'Falha ao construir relatório de coleções.', array( 'error' => $e->getMessage() ) );
		}

		return array(
			'generated_at' => time(),
			'threshold'    => $threshold,
			'rows'         => $rows,
		);
	}

	private function count_posts_in_post_type( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		if ( ! $counts ) {
			return 0;
		}
		return (int) ( $counts->publish ?? 0 ) + (int) ( $counts->private ?? 0 ) + (int) ( $counts->draft ?? 0 );
	}

	public function invalidate(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}
