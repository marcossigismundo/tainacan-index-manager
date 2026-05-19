<?php
/**
 * Minimal HTTP client for Elasticsearch (compatible with OpenSearch).
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Thin Elasticsearch/OpenSearch HTTP client built on top of wp_remote_*.
 *
 * Only the calls the plugin actually uses are exposed. The OpenSearch_Client
 * subclass intentionally adds nothing: both engines share the wire protocol
 * for everything we do (cluster health, indices stats, _doc, _bulk, _search).
 */
class Elasticsearch_Client {

	protected Settings $settings;
	protected Logger $logger;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Whether credentials are configured (URL present).
	 */
	public function is_configured(): bool {
		$url = (string) $this->settings->get( 'es_url', '' );
		return '' !== $url;
	}

	/**
	 * Cluster health (_cluster/health). Returns assoc array or WP_Error.
	 *
	 * @return array|\WP_Error
	 */
	public function cluster_health() {
		return $this->request( 'GET', '/_cluster/health' );
	}

	/**
	 * Get a specific index's stats. Returns assoc or WP_Error.
	 *
	 * @return array|\WP_Error
	 */
	public function index_stats( string $index ) {
		$index = $this->sanitize_index_name( $index );
		return $this->request( 'GET', '/' . rawurlencode( $index ) . '/_stats' );
	}

	/**
	 * Check if an index exists. Returns bool or WP_Error.
	 *
	 * @return bool|\WP_Error
	 */
	public function index_exists( string $index ) {
		$index = $this->sanitize_index_name( $index );
		$res   = $this->raw_request( 'HEAD', '/' . rawurlencode( $index ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return 200 === $code;
	}

	/**
	 * Create an index with the given body (settings/mappings).
	 *
	 * @return array|\WP_Error
	 */
	public function create_index( string $index, array $body ) {
		$index = $this->sanitize_index_name( $index );
		return $this->request( 'PUT', '/' . rawurlencode( $index ), $body );
	}

	/**
	 * Delete an index.
	 *
	 * @return array|\WP_Error
	 */
	public function delete_index( string $index ) {
		$index = $this->sanitize_index_name( $index );
		return $this->request( 'DELETE', '/' . rawurlencode( $index ) );
	}

	/**
	 * Index a single document.
	 *
	 * @return array|\WP_Error
	 */
	public function index_document( string $index, string $id, array $doc ) {
		$index = $this->sanitize_index_name( $index );
		$path  = '/' . rawurlencode( $index ) . '/_doc/' . rawurlencode( $id );
		return $this->request( 'PUT', $path, $doc );
	}

	/**
	 * Delete a single document.
	 *
	 * @return array|\WP_Error
	 */
	public function delete_document( string $index, string $id ) {
		$index = $this->sanitize_index_name( $index );
		$path  = '/' . rawurlencode( $index ) . '/_doc/' . rawurlencode( $id );
		return $this->request( 'DELETE', $path );
	}

	/**
	 * Bulk index/delete documents. `$lines` must be an array of arrays already
	 * formatted as ES bulk lines (alternating action/doc).
	 *
	 * @return array|\WP_Error
	 */
	public function bulk( array $lines ) {
		if ( empty( $lines ) ) {
			return array( 'errors' => false, 'items' => array() );
		}

		$ndjson = '';
		foreach ( $lines as $line ) {
			$ndjson .= wp_json_encode( $line ) . "\n";
		}

		$url = $this->base_url() . '/_bulk';
		$res = wp_remote_request(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => $this->timeout(),
				'headers' => array_merge(
					$this->auth_headers(),
					array( 'Content-Type' => 'application/x-ndjson' )
				),
				'body'    => $ndjson,
			)
		);

		return $this->parse_response( $res, 'POST', '/_bulk' );
	}

	/**
	 * Run a _search query. Returns body or WP_Error.
	 *
	 * @return array|\WP_Error
	 */
	public function search( string $index, array $query ) {
		$index = $this->sanitize_index_name( $index );
		return $this->request( 'POST', '/' . rawurlencode( $index ) . '/_search', $query );
	}

	/**
	 * Count documents in an index (with optional query).
	 *
	 * @return int|\WP_Error
	 */
	public function count( string $index, array $query = array() ) {
		$index = $this->sanitize_index_name( $index );
		$body  = empty( $query ) ? null : $query;
		$res   = $this->request( 'POST', '/' . rawurlencode( $index ) . '/_count', $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! is_array( $res ) || ! isset( $res['count'] ) ) {
			return new \WP_Error( 'tim_invalid_count_response', 'Resposta inesperada do _count.' );
		}
		return (int) $res['count'];
	}

	/**
	 * Refresh an index (force visibility of pending writes).
	 *
	 * @return array|\WP_Error
	 */
	public function refresh( string $index ) {
		$index = $this->sanitize_index_name( $index );
		return $this->request( 'POST', '/' . rawurlencode( $index ) . '/_refresh' );
	}

	/**
	 * Issue an arbitrary JSON request. Returns decoded body on success, WP_Error on failure.
	 *
	 * @return array|\WP_Error
	 */
	protected function request( string $method, string $path, $body = null ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'tim_es_not_configured', __( 'Elasticsearch/OpenSearch não está configurado.', 'tainacan-index-manager' ) );
		}

		$url     = $this->base_url() . $path;
		$args    = array(
			'method'  => $method,
			'timeout' => $this->timeout(),
			'headers' => array_merge(
				$this->auth_headers(),
				array( 'Content-Type' => 'application/json' )
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$res = wp_remote_request( $url, $args );
		return $this->parse_response( $res, $method, $path );
	}

	/**
	 * Issue an arbitrary HEAD/GET request without JSON decoding.
	 *
	 * @return array|\WP_Error
	 */
	protected function raw_request( string $method, string $path ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'tim_es_not_configured', __( 'Elasticsearch/OpenSearch não está configurado.', 'tainacan-index-manager' ) );
		}

		$url  = $this->base_url() . $path;
		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout(),
			'headers' => $this->auth_headers(),
		);

		return wp_remote_request( $url, $args );
	}

	/**
	 * Convert wp_remote_request response into structured array or WP_Error.
	 *
	 * @param mixed  $res    Result from wp_remote_request.
	 * @param string $method For logging.
	 * @param string $path   For logging.
	 * @return array|\WP_Error
	 */
	protected function parse_response( $res, string $method, string $path ) {
		if ( is_wp_error( $res ) ) {
			$this->logger->error( Logger::CHAN_ELASTIC, 'Falha de transporte ao chamar Elasticsearch.', array(
				'method'  => $method,
				'path'    => $path,
				'wp_code' => $res->get_error_code(),
				'wp_msg'  => $res->get_error_message(),
			) );
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );

		if ( $code >= 400 ) {
			// Extract structured ES error info from the body when present
			// (it ships type + reason + sometimes caused_by — far more useful
			// in the log than the raw body blob).
			$decoded   = json_decode( $body, true );
			$err_type  = '';
			$err_reason = '';
			if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
				if ( is_array( $decoded['error'] ) ) {
					$err_type   = (string) ( $decoded['error']['type'] ?? '' );
					$err_reason = (string) ( $decoded['error']['reason'] ?? '' );
					if ( isset( $decoded['error']['caused_by']['reason'] ) ) {
						$err_reason .= ' [caused_by: ' . (string) $decoded['error']['caused_by']['reason'] . ']';
					}
				} else {
					$err_reason = (string) $decoded['error'];
				}
			}

			$short_message = sprintf(
				/* translators: %1$d HTTP code, %2$s path */
				__( 'Elasticsearch HTTP %1$d em %2$s', 'tainacan-index-manager' ),
				$code,
				$path
			);
			if ( '' !== $err_type ) {
				$short_message .= ' — ' . $err_type;
			}

			$this->logger->error( Logger::CHAN_ELASTIC, $short_message, array(
				'method'     => $method,
				'path'       => $path,
				'code'       => $code,
				'error_type' => $err_type,
				'reason'     => $err_reason,
			) );

			$wp_error_message = '' !== $err_reason
				? sprintf(
					/* translators: %1$d HTTP code, %2$s ES type, %3$s ES reason */
					__( 'Elasticsearch HTTP %1$d (%2$s): %3$s', 'tainacan-index-manager' ),
					$code,
					'' !== $err_type ? $err_type : '—',
					$err_reason
				)
				: $short_message;

			return new \WP_Error(
				'tim_es_http_' . $code,
				$wp_error_message,
				array(
					'code'       => $code,
					'error_type' => $err_type,
					'reason'     => $err_reason,
					'body'       => $body,
				)
			);
		}

		if ( '' === $body ) {
			return array();
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			$this->logger->error( Logger::CHAN_ELASTIC, 'Resposta não-JSON do Elasticsearch.', array(
				'method' => $method,
				'path'   => $path,
				'sample' => substr( $body, 0, 200 ),
			) );
			return new \WP_Error( 'tim_es_invalid_json', __( 'Resposta do Elasticsearch não é JSON válido.', 'tainacan-index-manager' ) );
		}
		return $decoded;
	}

	/**
	 * Measure round-trip latency for a GET / (engine info).
	 *
	 * @return array{ok:bool, ms:int, code:int, error:string}
	 */
	public function ping(): array {
		if ( ! $this->is_configured() ) {
			return array( 'ok' => false, 'ms' => 0, 'code' => 0, 'error' => 'not_configured' );
		}
		$start = microtime( true );
		$res   = wp_remote_get(
			$this->base_url() . '/',
			array(
				'timeout' => $this->timeout(),
				'headers' => $this->auth_headers(),
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'ms' => $ms, 'code' => 0, 'error' => $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return array(
			'ok'    => 200 === $code,
			'ms'    => $ms,
			'code'  => $code,
			'error' => 200 === $code ? '' : 'http_' . $code,
		);
	}

	protected function base_url(): string {
		$raw    = (string) $this->settings->get( 'es_url', '' );
		$parsed = self::parse_inline_credentials( $raw );
		return untrailingslashit( $parsed['url'] );
	}

	protected function timeout(): int {
		return (int) $this->settings->get( 'es_timeout', 5 );
	}

	/**
	 * Build authentication headers.
	 *
	 * Precedence:
	 *   1. Explicit API Key in settings
	 *   2. Explicit Basic Auth in settings (es_username/es_password)
	 *   3. Inline `user:pass@host` extracted from es_url (handles URLs the
	 *      user pasted with credentials baked in, including passwords that
	 *      themselves contain `@`)
	 *
	 * WP_Http does NOT translate URL userinfo into an Authorization header,
	 * so without (3) requests would silently go out unauthenticated.
	 */
	protected function auth_headers(): array {
		$headers = array();
		$apikey  = (string) $this->settings->get( 'es_api_key', '' );

		if ( '' !== $apikey ) {
			$headers['Authorization'] = 'ApiKey ' . $apikey;
			return $headers;
		}

		$user = (string) $this->settings->get( 'es_username', '' );
		$pass = (string) $this->settings->get( 'es_password', '' );

		if ( '' === $user && '' === $pass ) {
			$inline = self::parse_inline_credentials( (string) $this->settings->get( 'es_url', '' ) );
			$user   = (string) $inline['user'];
			$pass   = (string) $inline['pass'];
		}

		if ( '' !== $user || '' !== $pass ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $user . ':' . $pass );
		}
		return $headers;
	}

	/**
	 * Describe which auth method (if any) would be used right now.
	 * Read-only — handy for the "Test connection" feedback in the UI.
	 *
	 * @return array{method:string, user:string}
	 */
	public function describe_auth(): array {
		$apikey = (string) $this->settings->get( 'es_api_key', '' );
		if ( '' !== $apikey ) {
			return array( 'method' => 'api_key', 'user' => '' );
		}
		$user = (string) $this->settings->get( 'es_username', '' );
		$pass = (string) $this->settings->get( 'es_password', '' );
		if ( '' !== $user || '' !== $pass ) {
			return array( 'method' => 'basic_auth', 'user' => $user );
		}
		$inline = self::parse_inline_credentials( (string) $this->settings->get( 'es_url', '' ) );
		if ( '' !== $inline['user'] || '' !== $inline['pass'] ) {
			return array( 'method' => 'basic_auth_inline', 'user' => $inline['user'] );
		}
		return array( 'method' => 'none', 'user' => '' );
	}

	/**
	 * Public helper to obtain the URL the client would actually hit (with
	 * any embedded `user:pass@` stripped) — useful for diagnostic UI.
	 */
	public function effective_base_url(): string {
		return $this->base_url();
	}

	/**
	 * Extract embedded `user:pass@` credentials from a URL.
	 *
	 * Handles the awkward case where the password itself contains an `@`
	 * (e.g. `http://elastic:Elastic@Tainacan@host:9200`). parse_url() is
	 * unreliable on multi-`@` userinfo, so we use a greedy regex that takes
	 * everything up to the LAST `@` before the host as userinfo.
	 *
	 * Returns the URL stripped of userinfo plus the decoded user/pass.
	 *
	 * @return array{url:string, user:string, pass:string}
	 */
	public static function parse_inline_credentials( string $url ): array {
		$out = array( 'url' => $url, 'user' => '', 'pass' => '' );
		if ( '' === $url ) {
			return $out;
		}
		// scheme://...@host[:port][/...]
		if ( ! preg_match( '#^(https?://)(.*)@([^@/]+(?::\d+)?)(/.*)?$#i', $url, $m ) ) {
			return $out;
		}
		$userinfo = $m[2];
		$colon    = strpos( $userinfo, ':' );
		if ( false === $colon ) {
			$out['user'] = rawurldecode( $userinfo );
		} else {
			$out['user'] = rawurldecode( substr( $userinfo, 0, $colon ) );
			$out['pass'] = rawurldecode( substr( $userinfo, $colon + 1 ) );
		}
		$out['url'] = $m[1] . $m[3] . ( isset( $m[4] ) ? $m[4] : '' );
		return $out;
	}

	/**
	 * Strict whitelist of allowed characters for an index name.
	 */
	protected function sanitize_index_name( string $index ): string {
		$index = strtolower( $index );
		$index = preg_replace( '/[^a-z0-9_\-]/', '', $index );
		return '' !== $index ? $index : 'tainacan_items';
	}
}
