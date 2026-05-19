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

	/**
	 * Return defaults for every setting key the plugin understands.
	 */
	public static function defaults(): array {
		return array(
			'engine'                    => 'auto',
			'es_url'                    => '',
			'es_username'               => '',
			'es_password'               => '',
			'es_api_key'                => '',
			'es_timeout'                => 5,
			'index_name'                => 'tainacan_items',
			'batch_size'                => 50,
			'batch_interval_seconds'    => 1,
			'auto_indexing_enabled'     => false,
			'auto_check_frequency'      => 'hourly',
			'divergence_threshold_pct'  => 5,
			'max_retries'               => 3,
			'alert_email_enabled'       => false,
			'alert_email_address'       => '',
			'alert_dashboard_enabled'   => true,
			'fallback_enabled'          => true,
			'log_retention_days'        => 30,
			'last_index_run_ts'         => 0,
			'last_health_check_ts'      => 0,
		);
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
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
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
	 * @param array $partial Partial array of settings.
	 */
	public function update( array $partial ): bool {
		$defaults = self::defaults();
		$current  = $this->cache;

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
				$allowed = array( 'auto', 'elasticpress', 'own_indexer', 'disabled' );
				$v       = is_string( $v ) ? strtolower( $v ) : 'auto';
				return in_array( $v, $allowed, true ) ? $v : 'auto';

			case 'es_url':
				return esc_url_raw( is_string( $v ) ? trim( $v ) : '' );

			case 'es_username':
			case 'es_password':
			case 'es_api_key':
			case 'alert_email_address':
				return is_string( $v ) ? sanitize_text_field( $v ) : '';

			case 'index_name':
				$v = is_string( $v ) ? strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $v ) ) : 'tainacan_items';
				return '' !== $v ? $v : 'tainacan_items';

			case 'es_timeout':
				return max( 1, min( 60, (int) $v ) );

			case 'batch_size':
				return max( 1, min( 1000, (int) $v ) );

			case 'batch_interval_seconds':
				return max( 0, min( 600, (int) $v ) );

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

			case 'auto_indexing_enabled':
			case 'alert_email_enabled':
			case 'alert_dashboard_enabled':
			case 'fallback_enabled':
				return (bool) $v;

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
		return $all;
	}
}
