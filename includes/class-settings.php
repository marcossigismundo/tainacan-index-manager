<?php
/**
 * Plugin settings. Wraps WP options and credential storage.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage. All credentials are stored in a single autoloaded option
 * so we keep a tight surface for sanitization and access control.
 */
final class Settings {

	public const OPTION_KEY = 'tainacan_index_manager_settings';

	private array $cache = array();

	public function __construct() {
		$this->cache = self::all();
	}

	/** Queries are answered from the Elasticsearch index this plugin maintains. */
	public const ENGINE_ELASTICSEARCH = 'elasticsearch';

	/** No routing: WordPress answers everything from the database. */
	public const ENGINE_SQL = 'sql';

	/**
	 * Reduce any stored engine value to one of the two supported modes.
	 *
	 * Earlier versions offered more values, which described *who builds the
	 * index* rather than *which engine answers the query* — a distinction that
	 * reliably confused people. Anything that is not explicitly SQL means
	 * "search served by Elasticsearch".
	 *
	 * Legacy values are mapped here rather than in the sanitizer alone, so a
	 * stored value keeps working without waiting for the option to be rewritten.
	 *
	 * @param mixed $value Raw stored value.
	 */
	public static function normalize_engine( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		switch ( $value ) {
			case self::ENGINE_SQL:
			case 'disabled':
				return self::ENGINE_SQL;

			case self::ENGINE_ELASTICSEARCH:
			case 'own_indexer':
			case 'auto':
			default:
				return self::ENGINE_ELASTICSEARCH;
		}
	}

	/**
	 * The engine actually in force, with legacy values already normalized.
	 */
	public function engine(): string {
		return self::normalize_engine( $this->get( 'engine', self::ENGINE_ELASTICSEARCH ) );
	}

	/**
	 * Return defaults for every setting key the plugin understands.
	 */
	public static function defaults(): array {
		return array(
			'engine'                   => self::ENGINE_ELASTICSEARCH,
			'es_url'                   => '',
			'es_username'              => '',
			'es_password'              => '',
			'es_api_key'               => '',
			'es_timeout'               => 5,
			// Visitor-facing queries (list, facets): short, with a circuit breaker. See Elasticsearch_Client::search_live().
			'search_timeout'           => 1.5,
			'index_name'               => self::default_index_name(),
			// Stemmer/stopword language of the index analyzers (Elasticsearch language names).
			'analysis_language'        => 'brazilian',
			'batch_size'               => 50,
			'auto_indexing_enabled'    => false,
			'auto_check_frequency'     => 'hourly',
			'divergence_threshold_pct' => 5,
			'max_retries'              => 3,
			'alert_email_enabled'      => false,
			'alert_email_address'      => '',
			'alert_dashboard_enabled'  => true,
			'route_item_lists'         => true,
			'route_facets'             => true,
			'facet_max_terms'          => 300,
			'log_retention_days'       => 30,
			// Where the panel lives in Tainacan's admin sidebar: under "Outros"
			// (the default, alongside the other maintenance tools) or in the root menu.
			'menu_location'            => 'other',
			// Accept one or two wrong letters in free-text search (ES fuzziness AUTO).
			'search_typo_tolerance'    => false,
			// Complete the last word when it was typed incomplete ("fotogr" → fotografia).
			'search_prefix'            => true,
			'last_index_run_ts'        => 0,
			'last_health_check_ts'     => 0,
		);
	}

	/**
	 * Index name a fresh installation gets.
	 *
	 * Derived from the site URL so two WordPress sites pointed at the same
	 * (shared) Elasticsearch never write to — or delete — each other's index
	 * just because both kept the default. Existing installations keep whatever
	 * name is stored.
	 */
	public static function default_index_name(): string {
		$home = function_exists( 'home_url' ) ? (string) home_url() : '';
		if ( '' === $home ) {
			return 'tainacan_items';
		}
		return 'tnc_' . substr( md5( $home ), 0, 8 ) . '_items';
	}

	/**
	 * Stemmer languages the index may be created with (Elasticsearch names).
	 *
	 * @return string[]
	 */
	public static function analysis_languages(): array {
		return array( 'brazilian', 'portuguese', 'english', 'spanish', 'french', 'italian', 'german', 'galician', 'catalan' );
	}

	/**
	 * Seed default values on activation if no option exists yet.
	 */
	public static function install_defaults(): void {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults(), '', false );
		}
	}

	/**
	 * Return all settings merged with defaults.
	 *
	 * Secrets may be supplied by `wp-config.php` constants instead of the
	 * database: `TIM_ES_PASSWORD` and `TIM_ES_API_KEY` (and optionally
	 * `TIM_ES_URL`, `TIM_ES_USERNAME`) take precedence over the stored option,
	 * so the credential never has to live in `wp_options` at all.
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$all = array_merge( self::defaults(), $stored );

		foreach ( self::constant_overrides() as $key => $constant ) {
			if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
				$all[ $key ] = constant( $constant );
			}
		}

		return $all;
	}

	/**
	 * Setting key => wp-config constant that overrides it.
	 *
	 * @return array<string,string>
	 */
	public static function constant_overrides(): array {
		return array(
			'es_url'      => 'TIM_ES_URL',
			'es_username' => 'TIM_ES_USERNAME',
			'es_password' => 'TIM_ES_PASSWORD',
			'es_api_key'  => 'TIM_ES_API_KEY',
		);
	}

	/**
	 * Whether a setting is currently forced by a wp-config constant.
	 */
	public static function is_overridden( string $key ): bool {
		$map = self::constant_overrides();
		return isset( $map[ $key ] ) && defined( $map[ $key ] ) && '' !== (string) constant( $map[ $key ] );
	}

	/**
	 * Everything another plugin needs to talk to the same Elasticsearch index.
	 *
	 * This is the supported way for sibling plugins to reuse the connection;
	 * reading the option directly bypasses the constant overrides above and
	 * breaks whenever the storage format changes.
	 *
	 * @return array{url:string,username:string,password:string,api_key:string,index_name:string,mapping_version:int}
	 */
	public function connection(): array {
		return array(
			'url'             => (string) $this->get( 'es_url', '' ),
			'username'        => (string) $this->get( 'es_username', '' ),
			'password'        => (string) $this->get( 'es_password', '' ),
			'api_key'         => (string) $this->get( 'es_api_key', '' ),
			'index_name'      => (string) $this->get( 'index_name', '' ),
			'mapping_version' => Index_Manager::MAPPING_VERSION,
		);
	}

	/**
	 * Read a single setting value with default fallback.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if absent.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		if ( array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}
		return $default;
	}

	/**
	 * Update settings atomically. Only known keys are written.
	 *
	 * Side effect: when `es_url` arrives with inline `user:pass@` credentials,
	 * we extract them into `es_username` / `es_password` and store the URL
	 * stripped of userinfo. This:
	 *  - makes the saved fields reflect reality (the manager can see what's
	 *    being used for auth);
	 *  - avoids relying on `wp_remote_*` to honour URL userinfo (it doesn't);
	 *  - handles passwords containing `@` correctly via the client's regex.
	 *
	 * Explicit values on the same update take precedence — if the caller
	 * sends both an inline-credentialed URL and an explicit es_password, the
	 * explicit one wins.
	 *
	 * @param array $partial Partial array of settings.
	 */
	public function update( array $partial ): bool {
		$defaults = self::defaults();

		// Re-read instead of trusting the in-memory copy. This object may have been
		// constructed early in the request — or in a long-lived process — and the
		// whole array is written back below, so a stale copy silently reverts every
		// key that changed in the meantime. That is not theoretical: a cron tick
		// stamping `last_index_run_ts` was reverting `engine` and `index_name` to
		// whatever they were when its Settings instance was built.
		$current = self::all();

		// Pre-process es_url for inline credentials before normal sanitization.
		if ( isset( $partial['es_url'] ) && is_string( $partial['es_url'] ) && '' !== trim( $partial['es_url'] ) ) {
			$parsed = Elasticsearch_Client::parse_inline_credentials( trim( $partial['es_url'] ) );
			if ( '' !== $parsed['user'] || '' !== $parsed['pass'] ) {
				$partial['es_url'] = $parsed['url'];
				if ( ! isset( $partial['es_username'] ) || '' === (string) $partial['es_username'] ) {
					$partial['es_username'] = $parsed['user'];
				}
				if ( ! isset( $partial['es_password'] ) || '' === (string) $partial['es_password'] || '__set__' === $partial['es_password'] ) {
					$partial['es_password'] = $parsed['pass'];
				}
			}
		}

		foreach ( $partial as $k => $v ) {
			if ( ! array_key_exists( $k, $defaults ) ) {
				continue;
			}
			$current[ $k ] = $this->sanitize_value( $k, $v );
		}

		$ok          = update_option( self::OPTION_KEY, $current, false );
		$this->cache = $current;
		return (bool) $ok;
	}

	/**
	 * Type-coerce and sanitize a single setting value.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $v   Raw value.
	 * @return mixed
	 */
	private function sanitize_value( string $key, $v ) {
		switch ( $key ) {
			case 'engine':
				return self::normalize_engine( $v );

			case 'es_url':
				$v = esc_url_raw( is_string( $v ) ? trim( $v ) : '', array( 'http', 'https' ) );
				// Only absolute http(s) URLs reach wp_remote_*; anything else would
				// make the client knock on a scheme/host the admin did not mean.
				return ( '' !== $v && wp_http_validate_url( $v ) ) || '' === $v ? $v : '';

			case 'es_username':
			case 'es_password':
			case 'es_api_key':
			case 'alert_email_address':
				return is_string( $v ) ? sanitize_text_field( $v ) : '';

			case 'index_name':
				$v = is_string( $v ) ? strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $v ) ) : '';
				return '' !== $v ? $v : self::default_index_name();

			case 'analysis_language':
				$v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
				return in_array( $v, self::analysis_languages(), true ) ? $v : 'brazilian';

			case 'es_timeout':
				return max( 1, min( 60, (int) $v ) );

			case 'search_timeout':
				return max( 0.5, min( 10.0, round( (float) $v, 1 ) ) );

			case 'batch_size':
				return max( 1, min( 1000, (int) $v ) );

			case 'divergence_threshold_pct':
				return max( 0, min( 100, (int) $v ) );

			case 'max_retries':
				return max( 0, min( 10, (int) $v ) );

			case 'log_retention_days':
				return max( 1, min( 365, (int) $v ) );

			case 'auto_check_frequency':
				$allowed = array( 'tim_15min', 'tim_30min', 'hourly', 'tim_6hours', 'daily' );
				$v       = is_string( $v ) ? $v : 'hourly';
				return in_array( $v, $allowed, true ) ? $v : 'hourly';

			case 'menu_location':
				return 'root' === $v ? 'root' : 'other';

			case 'search_typo_tolerance':
			case 'search_prefix':
			case 'auto_indexing_enabled':
			case 'alert_email_enabled':
			case 'alert_dashboard_enabled':
			case 'route_item_lists':
			case 'route_facets':
				return (bool) $v;

			case 'facet_max_terms':
				// Each bucket carries a reverse_nested sub-aggregation; past ~1,000
				// distinct values the aggregation costs more than the SQL it replaces.
				return max( 10, min( 1000, (int) $v ) );

			case 'last_index_run_ts':
			case 'last_health_check_ts':
				return max( 0, (int) $v );
		}
		return $v;
	}

	/**
	 * Persist a single internal counter/timestamp without touching other keys.
	 */
	public function mark_timestamp( string $key ): void {
		$this->update( array( $key => time() ) );
	}

	/**
	 * Return a safe (no credential) representation suitable for the admin UI.
	 */
	public function to_public_array(): array {
		$all = $this->cache;
		foreach ( array( 'es_password', 'es_api_key' ) as $secret ) {
			$all[ $secret ] = ! empty( $all[ $secret ] ) ? '__set__' : '';
		}
		$all['overridden_by_constant'] = array_values(
			array_filter(
				array_keys( self::constant_overrides() ),
				array( __CLASS__, 'is_overridden' )
			)
		);
		$all['analysis_languages']     = self::analysis_languages();
		return $all;
	}
}
