<?php
/**
 * Detection and read-only integration with ElasticPress.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only adapter around ElasticPress.
 *
 * Important: this class never modifies ElasticPress core, never duplicates
 * its mappings, and never overrides its index settings. It only:
 * - detects if EP is present and active;
 * - reads its index health via its own public APIs (when available);
 * - exposes triggers (sync, status query) that delegate to EP's CLI/queue.
 */
final class ElasticPress_Integration {

	private Settings $settings;
	private Logger $logger;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function is_active(): bool {
		return defined( 'EP_VERSION' ) || class_exists( '\\ElasticPress\\Elasticsearch' );
	}

	public function get_version(): ?string {
		return defined( 'EP_VERSION' ) ? (string) EP_VERSION : null;
	}

	/**
	 * Returns the EP host URL if EP exposes it (read-only).
	 */
	public function get_host(): ?string {
		if ( function_exists( '\\ElasticPress\\Utils\\get_host' ) ) {
			try {
				$host = call_user_func( '\\ElasticPress\\Utils\\get_host' );
				return is_string( $host ) ? $host : null;
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Returns the EP active index name(s) if introspectable.
	 *
	 * @return string[]
	 */
	public function get_index_names(): array {
		$names = array();
		if ( class_exists( '\\ElasticPress\\Indexables' ) && method_exists( '\\ElasticPress\\Indexables', 'factory' ) ) {
			try {
				$factory = call_user_func( array( '\\ElasticPress\\Indexables', 'factory' ) );
				if ( is_object( $factory ) && method_exists( $factory, 'get_all' ) ) {
					$indexables = $factory->get_all();
					if ( is_array( $indexables ) ) {
						foreach ( $indexables as $idx ) {
							if ( is_object( $idx ) && method_exists( $idx, 'get_index_name' ) ) {
								$names[] = (string) $idx->get_index_name();
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				$this->logger->warning( Logger::CHAN_ELASTICPRSS, 'Falha ao listar índices do ElasticPress.', array( 'error' => $e->getMessage() ) );
			}
		}
		return array_filter( array_unique( $names ) );
	}

	/**
	 * Snapshot EP status. Always returns an array; absent keys mean unknown.
	 */
	public function snapshot(): array {
		return array(
			'active'       => $this->is_active(),
			'version'      => $this->get_version(),
			'host'         => $this->get_host(),
			'index_names'  => $this->get_index_names(),
			'last_sync_ts' => $this->get_last_sync_ts(),
			'sync_state'   => $this->get_sync_state(),
		);
	}

	/**
	 * EP stores a 'ep_last_sync' option in some versions. Best effort.
	 */
	public function get_last_sync_ts(): ?int {
		$opt = get_option( 'ep_last_sync', null );
		if ( is_numeric( $opt ) ) {
			return (int) $opt;
		}
		if ( is_string( $opt ) ) {
			$ts = strtotime( $opt );
			return false === $ts ? null : $ts;
		}
		return null;
	}

	/**
	 * EP sync state, when readable. Returns 'unknown' otherwise.
	 */
	public function get_sync_state(): string {
		$opt = get_option( 'ep_index_meta', null );
		if ( is_array( $opt ) && ! empty( $opt ) ) {
			return 'in_progress';
		}
		return 'idle';
	}

	/**
	 * Trigger an EP sync via WP-CLI when available. Returns true on success.
	 */
	public function trigger_sync(): bool {
		if ( ! $this->is_active() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			try {
				\WP_CLI::runcommand( 'elasticpress sync', array( 'launch' => false, 'return' => false ) );
				$this->logger->info( Logger::CHAN_ELASTICPRSS, 'Sincronização do ElasticPress acionada via WP-CLI.' );
				return true;
			} catch ( \Throwable $e ) {
				$this->logger->warning( Logger::CHAN_ELASTICPRSS, 'Falha ao acionar EP sync via WP-CLI.', array( 'error' => $e->getMessage() ) );
				return false;
			}
		}
		// Without CLI, signal that the admin should run sync from EP UI.
		$this->logger->info( Logger::CHAN_ELASTICPRSS, 'Sincronização do ElasticPress deve ser executada manualmente (WP-CLI indisponível).' );
		return false;
	}
}
