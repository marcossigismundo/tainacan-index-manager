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

	/**
	 * ElasticPress is considered active when at least one of its public
	 * footprints is present on PHP-level. We cast a wide net so older and
	 * newer ElasticPress builds (4.x, 5.x) all match — see detection_report()
	 * for the full breakdown surfaced to the UI.
	 */
	public function is_active(): bool {
		if ( defined( 'EP_VERSION' ) ) {
			return true;
		}
		if ( class_exists( '\\ElasticPress\\Elasticsearch' ) ) {
			return true;
		}
		if ( class_exists( '\\ElasticPress\\Indexables' ) ) {
			return true;
		}
		if ( function_exists( '\\ElasticPress\\Utils\\get_host' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Itemized list of "is EP here?" signals we look for, so the dashboard
	 * can render a checklist that explains the decision to the admin.
	 *
	 * Useful when the manager swears EP is installed and we say it isn't —
	 * the table shows exactly which signal is missing.
	 */
	public function detection_report(): array {
		$plugin_active_main   = $this->is_plugin_file_active( 'elasticpress/elasticpress.php' );
		$plugin_active_dev    = $this->is_plugin_file_active( 'ElasticPress/elasticpress.php' );
		$ep_dir_present       = is_dir( WP_PLUGIN_DIR . '/elasticpress' );
		return array(
			array(
				'key'    => 'EP_VERSION_defined',
				'label'  => __( 'Constante PHP `EP_VERSION` definida', 'tainacan-index-manager' ),
				'pass'   => defined( 'EP_VERSION' ),
				'detail' => defined( 'EP_VERSION' ) ? (string) EP_VERSION : __( 'não definida — o ElasticPress não chegou a carregar este request.', 'tainacan-index-manager' ),
			),
			array(
				'key'    => 'class_Elasticsearch',
				'label'  => __( 'Classe `\\ElasticPress\\Elasticsearch` carregada', 'tainacan-index-manager' ),
				'pass'   => class_exists( '\\ElasticPress\\Elasticsearch' ),
				'detail' => '',
			),
			array(
				'key'    => 'class_Indexables',
				'label'  => __( 'Classe `\\ElasticPress\\Indexables` carregada', 'tainacan-index-manager' ),
				'pass'   => class_exists( '\\ElasticPress\\Indexables' ),
				'detail' => '',
			),
			array(
				'key'    => 'fn_get_host',
				'label'  => __( 'Função `\\ElasticPress\\Utils\\get_host` declarada', 'tainacan-index-manager' ),
				'pass'   => function_exists( '\\ElasticPress\\Utils\\get_host' ),
				'detail' => '',
			),
			array(
				'key'    => 'plugin_active_main',
				'label'  => __( 'Plugin ativo: `elasticpress/elasticpress.php`', 'tainacan-index-manager' ),
				'pass'   => $plugin_active_main,
				'detail' => '',
			),
			array(
				'key'    => 'plugin_active_dev',
				'label'  => __( 'Plugin ativo: `ElasticPress/elasticpress.php` (build de dev)', 'tainacan-index-manager' ),
				'pass'   => $plugin_active_dev,
				'detail' => '',
			),
			array(
				'key'    => 'plugin_dir_exists',
				'label'  => __( 'Diretório `wp-content/plugins/elasticpress/` existe', 'tainacan-index-manager' ),
				'pass'   => $ep_dir_present,
				'detail' => $ep_dir_present ? '' : __( 'pasta não encontrada — o plugin não foi instalado neste site.', 'tainacan-index-manager' ),
			),
		);
	}

	/**
	 * Wrapper around is_plugin_active() that handles the late-loading of
	 * wp-admin/includes/plugin.php on the front + multisite checks.
	 */
	private function is_plugin_file_active( string $basename ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $basename ) ) {
			return true;
		}
		return is_plugin_active( $basename );
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
			'active'           => $this->is_active(),
			'version'          => $this->get_version(),
			'host'             => $this->get_host(),
			'index_names'      => $this->get_index_names(),
			'last_sync_ts'     => $this->get_last_sync_ts(),
			'sync_state'       => $this->get_sync_state(),
			'detection_report' => $this->detection_report(),
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
