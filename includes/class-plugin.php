<?php
/**
 * Plugin bootstrap. Wires services together at plugins_loaded.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton. Boots all subsystems.
 */
final class Plugin {

	private static ?Plugin $instance = null;
	private bool $booted             = false;

	private Settings $settings;
	private Logger $logger;
	private Alerts $alerts;
	private Health_Service $health;
	private Index_Manager $index_manager;
	private Indexer $indexer;
	private Indexer_Metrics $metrics;
	private Collections_Monitor $collections;
	private ElasticPress_Integration $elasticpress;
	private Search_Integration $search;
	private Cron $cron;
	private REST_Controller $rest;
	private Admin_Page $admin;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Boot the plugin. Idempotent.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		load_plugin_textdomain(
			'tainacan-index-manager',
			false,
			dirname( TAINACAN_INDEX_MANAGER_BASENAME ) . '/languages'
		);

		$this->settings      = new Settings();
		$this->logger        = new Logger();
		$this->alerts        = new Alerts( $this->logger );
		$this->health        = new Health_Service( $this->settings, $this->logger, $this->alerts );
		$this->collections   = new Collections_Monitor( $this->settings, $this->logger );
		$this->index_manager = new Index_Manager( $this->settings, $this->logger );
		$this->metrics       = new Indexer_Metrics( $this->settings );
		$this->indexer       = new Indexer( $this->settings, $this->logger, $this->index_manager, $this->metrics );
		$this->elasticpress  = new ElasticPress_Integration( $this->settings, $this->logger );
		$this->search        = new Search_Integration( $this->settings, $this->logger, $this->elasticpress );
		$this->cron          = new Cron( $this->settings, $this->health, $this->indexer, $this->collections, $this->logger );
		$this->rest          = new REST_Controller( $this->settings, $this->health, $this->indexer, $this->index_manager, $this->collections, $this->elasticpress, $this->logger, $this->alerts, $this->metrics );
		$this->admin         = new Admin_Page( $this->settings, $this->health, $this->logger, $this->alerts );

		$this->cron->register();
		$this->rest->register();
		$this->admin->register();
		$this->search->register();
		$this->alerts->register();

		add_filter( 'plugin_action_links_' . TAINACAN_INDEX_MANAGER_BASENAME, array( $this, 'plugin_action_links' ) );

		$this->booted = true;
	}

	public function plugin_action_links( array $links ): array {
		$url      = admin_url( 'admin.php?page=tainacan_index_manager' );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Painel', 'tainacan-index-manager' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public function settings(): Settings           { return $this->settings; }
	public function logger(): Logger               { return $this->logger; }
	public function alerts(): Alerts               { return $this->alerts; }
	public function health(): Health_Service       { return $this->health; }
	public function indexer(): Indexer             { return $this->indexer; }
	public function metrics(): Indexer_Metrics     { return $this->metrics; }
	public function index_manager(): Index_Manager { return $this->index_manager; }
	public function collections(): Collections_Monitor { return $this->collections; }
	public function elasticpress(): ElasticPress_Integration { return $this->elasticpress; }

	/**
	 * Activation: create custom tables and seed defaults.
	 */
	public static function activate(): void {
		require_once TAINACAN_INDEX_MANAGER_DIR . 'includes/class-autoloader.php';
		Autoloader::register();

		Logger::install();
		Settings::install_defaults();
		Cron::schedule_default();
	}

	/**
	 * Deactivation: clear scheduled events.
	 */
	public static function deactivate(): void {
		require_once TAINACAN_INDEX_MANAGER_DIR . 'includes/class-autoloader.php';
		Autoloader::register();

		Cron::clear_all();
	}
}
