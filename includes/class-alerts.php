<?php
/**
 * Admin alerts (panel + e-mail).
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight alert store. Active alerts live in a single option keyed by code;
 * raising the same code twice updates the existing record (no duplicates).
 * Sends e-mail at most once per hour per code to avoid flooding.
 */
final class Alerts {

	public const SEV_INFO     = 'info';
	public const SEV_WARNING  = 'warning';
	public const SEV_CRITICAL = 'critical';

	private const OPTION_KEY        = 'tainacan_idxmgr_alerts';
	private const EMAIL_TRANSIENT   = 'tainacan_idxmgr_alert_email_sent_';
	private const EMAIL_COOLDOWN_S  = HOUR_IN_SECONDS;

	private Logger $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Raise (or update) an alert by code.
	 *
	 * Idempotent: when the alert already exists with the same severity and
	 * message, we only refresh `last_seen` and skip logging / email. Logs
	 * and emails are reserved for *transitions* (new alert, severity bump,
	 * message change) so a steady-state condition stops flooding the log
	 * even when the dashboard polls /alerts every few seconds.
	 *
	 * `count` therefore tracks the number of transitions, not raise calls.
	 */
	public function raise( string $code, string $severity, string $message, array $context = array() ): void {
		$allowed = array( self::SEV_INFO, self::SEV_WARNING, self::SEV_CRITICAL );
		if ( ! in_array( $severity, $allowed, true ) ) {
			$severity = self::SEV_INFO;
		}

		$alerts   = $this->all();
		$existing = $alerts[ $code ] ?? null;
		$changed  = ! $existing
			|| ( $existing['severity'] ?? '' ) !== $severity
			|| ( $existing['message'] ?? '' ) !== $message;

		$alerts[ $code ] = array(
			'code'       => $code,
			'severity'   => $severity,
			'message'    => $message,
			'context'    => Logger::scrub_context( $context ),
			'first_seen' => $existing['first_seen'] ?? time(),
			'last_seen'  => time(),
			'count'      => ( $existing['count'] ?? 0 ) + ( $changed ? 1 : 0 ),
		);
		update_option( self::OPTION_KEY, $alerts, false );

		if ( ! $changed ) {
			return;
		}

		$this->logger->log( $severity, Logger::CHAN_ALERT, $message, array_merge( $context, array( 'alert_code' => $code ) ) );

		if ( in_array( $severity, array( self::SEV_WARNING, self::SEV_CRITICAL ), true ) ) {
			$this->maybe_send_email( $code, $severity, $message );
		}
	}

	/**
	 * Clear an alert (e.g., once the underlying condition resolves).
	 */
	public function clear( string $code ): void {
		$alerts = $this->all();
		if ( isset( $alerts[ $code ] ) ) {
			unset( $alerts[ $code ] );
			update_option( self::OPTION_KEY, $alerts, false );
		}
	}

	public function clear_all(): void {
		update_option( self::OPTION_KEY, array(), false );
	}

	/**
	 * Get all active alerts. Returns an empty array when none.
	 */
	public function all(): array {
		$opt = get_option( self::OPTION_KEY, array() );
		return is_array( $opt ) ? $opt : array();
	}

	public function count(): int {
		return count( $this->all() );
	}

	/**
	 * Send an e-mail notification when enabled, throttled per code.
	 */
	private function maybe_send_email( string $code, string $severity, string $message ): void {
		$plugin   = Plugin::instance();
		$settings = $plugin->settings();

		if ( ! $settings->get( 'alert_email_enabled', false ) ) {
			return;
		}
		$to = (string) $settings->get( 'alert_email_address', '' );
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}
		$transient = self::EMAIL_TRANSIENT . md5( $code );
		if ( false !== get_transient( $transient ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %1$s severity, %2$s alert code */
			__( '[Tainacan Index Manager] %1$s: %2$s', 'tainacan-index-manager' ),
			strtoupper( $severity ),
			$code
		);
		$body = sprintf(
			/* translators: %1$s message, %2$s site URL */
			__( "Alerta gerado pelo Tainacan Index Manager.\n\n%1\$s\n\nVerifique o painel em %2\$s", 'tainacan-index-manager' ),
			$message,
			admin_url( 'admin.php?page=tainacan_index_manager' )
		);

		$sent = wp_mail( $to, $subject, $body );
		set_transient( $transient, 1, self::EMAIL_COOLDOWN_S );

		if ( ! $sent ) {
			$this->logger->warning( Logger::CHAN_ALERT, 'Falha ao enviar e-mail de alerta.', array( 'code' => $code ) );
		}
	}

	/**
	 * Render dashboard notices on every admin page.
	 */
	public function render_notices(): void {
		$plugin   = Plugin::instance();
		$settings = $plugin->settings();
		if ( ! $settings->get( 'alert_dashboard_enabled', true ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$alerts = $this->all();
		if ( empty( $alerts ) ) {
			return;
		}

		// Show only the highest severity in admin notices to avoid clutter.
		$by_severity = array(
			self::SEV_CRITICAL => array(),
			self::SEV_WARNING  => array(),
			self::SEV_INFO     => array(),
		);
		foreach ( $alerts as $alert ) {
			$sev = $alert['severity'] ?? self::SEV_INFO;
			if ( isset( $by_severity[ $sev ] ) ) {
				$by_severity[ $sev ][] = $alert;
			}
		}

		foreach ( $by_severity as $sev => $list ) {
			if ( empty( $list ) ) {
				continue;
			}
			$class = self::SEV_CRITICAL === $sev ? 'notice-error' : ( self::SEV_WARNING === $sev ? 'notice-warning' : 'notice-info' );
			echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>' . esc_html__( 'Tainacan Index Manager', 'tainacan-index-manager' ) . ':</strong></p><ul style="margin-left:1.5em;list-style:disc;">';
			foreach ( $list as $alert ) {
				echo '<li>' . esc_html( $alert['message'] ?? '' ) . '</li>';
			}
			echo '</ul></div>';
		}
	}
}
