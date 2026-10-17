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
	private Search_Vocabulary $vocabulary;
	private Traffic_Light $light;
	private Diagnostics $diagnostics;
	private Search_Integration $search;
	private Facets_Integration $facets;
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
	 *
	 * Defense in depth: catches any Throwable raised during wiring so that
	 * a bug in this plugin can never take down the whole WordPress admin.
	 * Catchable errors (Error + Exception in PHP 7+) get surfaced as an
	 * admin notice; parse-time fatals are not catchable here but `php -l`
	 * and CI prevent them from shipping.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		try {
			// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Distributed via GitHub, not WordPress.org: translations ship in /languages and are not served by translate.wordpress.org, so just-in-time loading does not cover them.
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
			$this->vocabulary    = new Search_Vocabulary( $this->settings, $this->logger, $this->index_manager->client() );
			$this->search        = new Search_Integration( $this->settings, $this->logger );
			$this->facets        = new Facets_Integration( $this->settings, $this->logger, $this->search );
			$this->light         = new Traffic_Light( $this->settings, $this->health, $this->indexer, $this->vocabulary, $this->search );
			$this->diagnostics   = new Diagnostics( $this->settings, $this->health, $this->indexer, $this->metrics, $this->collections, $this->vocabulary, $this->logger );
			$this->cron          = new Cron( $this->settings, $this->health, $this->indexer, $this->collections, $this->logger, $this->light );
			$this->rest          = new REST_Controller( $this->settings, $this->health, $this->indexer, $this->index_manager, $this->collections, $this->vocabulary, $this->light, $this->logger, $this->alerts, $this->metrics, $this->diagnostics );
			$this->admin         = new Admin_Page( $this->settings, $this->health, $this->logger, $this->alerts );

			$this->cron->register();
			$this->rest->register();
			$this->admin->register();
			$this->search->register();
			$this->facets->register();
			$this->alerts->register();

			add_filter( 'plugin_action_links_' . TAINACAN_INDEX_MANAGER_BASENAME, array( $this, 'plugin_action_links' ) );
			add_action( 'admin_notices', array( $this, 'elasticpress_notice' ) );
			add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_site' ), 20 );

			$this->booted = true;
			$this->maybe_upgrade();
		} catch ( \Throwable $e ) {
			$msg = $e->getMessage();
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG; surfaces only in dev.
				error_log( '[Tainacan Index Manager] boot() falhou: ' . $msg );
			}
			add_action(
				'admin_notices',
				static function () use ( $msg ) {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p><strong>Tainacan Index Manager:</strong> '
					. esc_html(
						sprintf(
						/* translators: %s = error message */
							__( 'Falha ao inicializar (%s). O plugin foi parcialmente desativado para proteger o site.', 'tainacan-index-manager' ),
							$msg
						)
					)
					. '</p></div>';
				}
			);
		}
	}

	public function plugin_action_links( array $links ): array {
		$url      = admin_url( 'admin.php?page=' . Admin_Page::DASHBOARD_SLUG );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Gestão da Indexação', 'tainacan-index-manager' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Did boot() complete? Sibling plugins (tainacan-indicators, tainacan-
	 * segmented-search, brasiliana-lod) call the getters below; before the
	 * boot, or after a failed one, those return null instead of raising
	 * "typed property accessed before initialization".
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	public function settings(): ?Settings {
		return $this->booted ? $this->settings : null; }
	public function logger(): ?Logger {
		return $this->booted ? $this->logger : null; }
	public function alerts(): ?Alerts {
		return $this->booted ? $this->alerts : null; }
	public function health(): ?Health_Service {
		return $this->booted ? $this->health : null; }
	public function indexer(): ?Indexer {
		return $this->booted ? $this->indexer : null; }
	public function metrics(): ?Indexer_Metrics {
		return $this->booted ? $this->metrics : null; }
	public function index_manager(): ?Index_Manager {
		return $this->booted ? $this->index_manager : null; }
	public function collections(): ?Collections_Monitor {
		return $this->booted ? $this->collections : null; }
	public function vocabulary(): ?Search_Vocabulary {
		return $this->booted ? $this->vocabulary : null; }
	public function light(): ?Traffic_Light {
		return $this->booted ? $this->light : null; }

	/**
	 * Is this plugin routing visitor queries right now? One call for siblings
	 * that must agree with the item list on which engine answered it.
	 */
	public function is_routing(): bool {
		return $this->booted
			&& $this->search->engine_allows_routing()
			&& (bool) $this->settings->get( 'route_item_lists', true )
			&& $this->index_manager->client()->is_configured();
	}

	/**
	 * Warn once per screen when ElasticPress is also active: routing stands
	 * down (see Search_Integration::engine_allows_routing) but indexing goes on.
	 */
	public function elasticpress_notice(): void {
		if ( ! Search_Integration::elasticpress_active() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( Settings::ENGINE_SQL === $this->settings->engine() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'tainacan_idxmgr' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Tainacan Index Manager:</strong> '
			. esc_html__( 'O ElasticPress está ativo. Para não haver dois mecanismos respondendo a mesma consulta, as listagens e facetas do Tainacan ficam com o ElasticPress/SQL; este plugin continua indexando os itens e volta a responder quando o ElasticPress for desativado.', 'tainacan-index-manager' )
			. '</p></div>';
	}

	/** Option holding the schema/settings version last installed. */
	public const DB_VERSION_OPTION = 'tainacan_idxmgr_db_version';

	/**
	 * Run install steps the activation hook would have run, when the plugin
	 * arrived by copy/deploy (no activation) or the stored version is older.
	 */
	private function maybe_upgrade(): void {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );
		if ( TAINACAN_INDEX_MANAGER_VERSION === $installed ) {
			return;
		}
		Logger::install();
		Settings::install_defaults();
		Cron::schedule_default();
		update_option( self::DB_VERSION_OPTION, TAINACAN_INDEX_MANAGER_VERSION, false );
	}

	/**
	 * Activation: create custom tables and seed defaults.
	 *
	 * @param bool $network_wide True when activated for the whole network.
	 */
	public static function activate( $network_wide = false ): void {
		require_once TAINACAN_INDEX_MANAGER_DIR . 'includes/class-autoloader.php';
		Autoloader::register();

		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
	}

	/**
	 * New site in a network where the plugin is network-active: install there too.
	 *
	 * @param \WP_Site $site The site just created.
	 */
	public static function on_new_site( $site ): void {
		if ( ! is_multisite() || ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_multisite() || ! is_plugin_active_for_network( TAINACAN_INDEX_MANAGER_BASENAME ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install_site();
		restore_current_blog();
	}

	private static function install_site(): void {
		Logger::install();
		Settings::install_defaults();
		Cron::schedule_default();
		update_option( self::DB_VERSION_OPTION, TAINACAN_INDEX_MANAGER_VERSION, false );
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
