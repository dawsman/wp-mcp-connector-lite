<?php
defined( 'ABSPATH' ) || exit;

/**
 * Webhook event system for WP MCP Connect.
 *
 * Fires HTTP webhooks on plugin events and manages webhook subscriptions
 * via REST API endpoints.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Webhooks {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class.
	 *
	 * @since 1.0.0
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		// Register once: the filter short-circuits only requests we flagged
		// via the `cwp_ssrf_guard` arg, so it never touches unrelated traffic.
		if ( ! has_filter( 'pre_http_request', array( __CLASS__, 'pre_http_ssrf_guard' ) ) ) {
			add_filter( 'pre_http_request', array( __CLASS__, 'pre_http_ssrf_guard' ), 10, 3 );
		}
	}

	/**
	 * Fire a webhook event.
	 *
	 * @since 1.0.0
	 * @param string $event Event name (e.g., 'task.created', 'sync.completed').
	 * @param array  $data  Event payload.
	 */
	public static function fire( $event, $data = array() ) {
		$webhooks = get_option( 'cwp_webhooks', array() );
		if ( empty( $webhooks ) ) {
			return;
		}

		$payload = array(
			'event'     => $event,
			'timestamp' => gmdate( 'c' ),
			'site_url'  => home_url(),
			'data'      => $data,
		);

		$secret = self::get_signing_secret();
		if ( '' === $secret ) {
			// Without a signing key we cannot guarantee webhook authenticity.
			// Refuse to dispatch rather than sending unsigned events.
			return;
		}
		$body      = wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $body, $secret );

		foreach ( $webhooks as $webhook ) {
			if ( ! empty( $webhook['events'] ) && ! in_array( $event, $webhook['events'], true ) ) {
				continue;
			}

			$url = isset( $webhook['url'] ) ? (string) $webhook['url'] : '';
			if ( ! self::is_safe_webhook_url( $url ) ) {
				// Defence-in-depth: refuse to dispatch to an address that would
				// have been blocked at registration time. A URL that was valid
				// when stored could become unsafe later (DNS change, admin of a
				// newer plugin rewriting the option, etc.).
				continue;
			}

			wp_remote_post( $url, array(
				'body'    => $body,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'X-CWP-Signature' => $signature,
					'X-CWP-Event'     => $event,
				),
				'timeout'        => 5,
				'blocking'       => false,
				// Flag for pre_http_ssrf_guard: re-resolve at transport time to
				// close (but not eliminate) the DNS-rebinding window between
				// is_safe_webhook_url() and the actual socket connect.
				'cwp_ssrf_guard' => true,
			) );
		}
	}

	/**
	 * Last-chance SSRF guard fired from inside WP's HTTP API.
	 *
	 * Applies only to requests that opted in via the `cwp_ssrf_guard` arg so
	 * legitimate outbound traffic from other plugin code (updater, etc.)
	 * is unaffected. Re-resolves the host immediately before the socket is
	 * opened to narrow the DNS-rebinding window.
	 *
	 * @param false|array|WP_Error $preempt Passthrough sentinel.
	 * @param array                $args    Parsed HTTP args.
	 * @param string               $url     Target URL.
	 * @return false|WP_Error              WP_Error to abort, false to continue.
	 */
	public static function pre_http_ssrf_guard( $preempt, $args, $url ) {
		if ( empty( $args['cwp_ssrf_guard'] ) ) {
			return $preempt;
		}
		if ( ! self::is_safe_webhook_url( $url ) ) {
			return new WP_Error(
				'ssrf_blocked',
				__( 'Blocked webhook dispatch to a non-public address.', 'wp-mcp-connect' )
			);
		}
		return $preempt;
	}

	/**
	 * Context string used to derive the encryption key for the stored
	 * webhook signing secret.
	 *
	 * @var string
	 */
	const SECRET_CONTEXT = 'cwp_webhook_signing_secret_v1';

	/**
	 * Get the HMAC signing secret for outbound webhooks.
	 *
	 * A dedicated 32-char random secret, stored encrypted in wp_options and
	 * decrypted just-in-time. AUTH_KEY is deliberately NOT used: it is never
	 * exposed to the site owner, so receivers could never verify signatures,
	 * and it is also key material for this plugin's own encryption, so it
	 * must never be handed to a third-party service.
	 *
	 * Readable via GET /mcp/v1/webhooks/secret (manage_options) so the
	 * receiving end can be configured; POST /mcp/v1/webhooks/secret/rotate
	 * replaces it.
	 *
	 * @return string Signing secret, or '' if no secret can be established.
	 */
	private static function get_signing_secret() {
		if ( ! class_exists( 'WP_MCP_Connect_Crypto' ) ) {
			return '';
		}

		$stored = (string) get_option( 'cwp_webhook_secret', '' );
		if ( '' !== $stored ) {
			$decrypted = WP_MCP_Connect_Crypto::decrypt( $stored, self::SECRET_CONTEXT );
			if ( is_string( $decrypted ) && '' !== $decrypted ) {
				return $decrypted;
			}
			// Decryption failed — salt rotation or corruption. Fall through and
			// generate a fresh secret below. Receivers will need to re-sync.
		}

		$plain     = wp_generate_password( 32, true, true );
		$encrypted = WP_MCP_Connect_Crypto::encrypt( $plain, self::SECRET_CONTEXT );
		if ( is_wp_error( $encrypted ) ) {
			return '';
		}
		update_option( 'cwp_webhook_secret', $encrypted, false );

		return $plain;
	}

	/**
	 * REST: return the current signing secret so a receiver can verify
	 * X-CWP-Signature. Generates one if none exists yet.
	 *
	 * @since 1.0.5
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_secret_endpoint() {
		$secret = self::get_signing_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'no_secret', 'Unable to establish a webhook signing secret (encryption unavailable).', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array(
			'secret'    => $secret,
			'algorithm' => 'hmac-sha256',
			'header'    => 'X-CWP-Signature',
		) );
	}

	/**
	 * REST: rotate the signing secret. Existing receivers must be updated.
	 *
	 * @since 1.0.5
	 * @return WP_REST_Response|WP_Error
	 */
	public function rotate_secret_endpoint() {
		delete_option( 'cwp_webhook_secret' );
		$secret = self::get_signing_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'no_secret', 'Unable to generate a new webhook signing secret.', array( 'status' => 500 ) );
		}
		if ( class_exists( 'WP_MCP_Connect_Audit_Log' ) && method_exists( 'WP_MCP_Connect_Audit_Log', 'log' ) ) {
			WP_MCP_Connect_Audit_Log::log( 'webhook_secret_rotated', 'Webhook signing secret rotated via REST.' );
		}
		return rest_ensure_response( array(
			'secret'    => $secret,
			'algorithm' => 'hmac-sha256',
			'header'    => 'X-CWP-Signature',
			'rotated'   => true,
		) );
	}

	/**
	 * Validate a webhook target URL against SSRF risks.
	 *
	 * Requires HTTPS, rejects loopback/private/reserved addresses both by name
	 * and by resolved IP. A residual DNS-rebinding window remains between this
	 * check and the connect-time resolve; HTTPS-only blocks the AWS/GCP IMDS
	 * exfiltration case since those services don't serve TLS.
	 *
	 * @param string $url Candidate webhook URL.
	 * @return bool True if the URL is safe to dispatch to.
	 */
	public static function is_safe_webhook_url( $url ) {
		if ( '' === $url || ! is_string( $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		$blocked_names = array( 'localhost', 'ip6-localhost', 'ip6-loopback' );
		if ( in_array( $host, $blocked_names, true ) ) {
			return false;
		}

		// If host is a bare IP (including IPv6 inside brackets wp_parse_url strips),
		// validate it against public ranges directly.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// IPv4-mapped IPv6 (::ffff:0:0/96) aliases an IPv4 address at the
			// socket layer but PHP's FILTER_FLAG_NO_PRIV_RANGE/_NO_RES_RANGE
			// only checks the IPv4 and pure-IPv6 private/reserved ranges, not
			// the mapped space. `::ffff:7f00:1` == 127.0.0.1 but slips through
			// without an explicit reject. Also reject the all-zeros address.
			$lower = strtolower( $host );
			if ( '::' === $lower || 0 === strpos( $lower, '::ffff:' ) ) {
				return false;
			}
			return (bool) filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		// Otherwise resolve the hostname and check every record. gethostbynamel
		// returns false on failure; treat that as unsafe so DNS tricks can't
		// pass a lookup that then fails to connect.
		$resolved = gethostbynamel( $host );
		if ( ! is_array( $resolved ) || empty( $resolved ) ) {
			return false;
		}

		foreach ( $resolved as $ip ) {
			if ( ! filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/webhooks', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_webhooks' ),
				'permission_callback' => function () {
					return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
				},
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_webhook' ),
				'permission_callback' => function () {
					return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
				},
				'args'                => array(
					'url'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'events' => array(
						'type'    => 'array',
						'default' => array(),
					),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/webhooks/(?P<index>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_webhook' ),
			'permission_callback' => function () {
				return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
			},
		) );

		register_rest_route( 'mcp/v1', '/webhooks/secret', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_secret_endpoint' ),
			'permission_callback' => function () {
				return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
			},
		) );

		register_rest_route( 'mcp/v1', '/webhooks/secret/rotate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rotate_secret_endpoint' ),
			'permission_callback' => function () {
				return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
			},
		) );
	}

	/**
	 * List all registered webhooks.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public function list_webhooks() {
		return rest_ensure_response( array(
			'webhooks'         => get_option( 'cwp_webhooks', array() ),
			'available_events' => array(
				'task.created',
				'task.resolved',
				'sync.completed',
				'sync.failed',
				'redirect.created',
				'redirect.updated',
				'settings.changed',
				'audit.completed',
			),
		) );
	}

	/**
	 * Add a new webhook subscription.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function add_webhook( $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );

		if ( ! self::is_safe_webhook_url( $url ) ) {
			return new WP_Error(
				'invalid_webhook_url',
				__( 'Webhook URL must be an HTTPS URL pointing to a publicly routable host.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		$webhooks   = get_option( 'cwp_webhooks', array() );
		$webhooks[] = array(
			'url'     => $url,
			'events'  => $request->get_param( 'events' ),
			'created' => gmdate( 'c' ),
		);
		update_option( 'cwp_webhooks', $webhooks );

		if ( class_exists( 'WP_MCP_Connect_Audit_Log' ) ) {
			WP_MCP_Connect_Audit_Log::log( 'webhook_added', 'Webhook added: ' . $url );
		}

		return rest_ensure_response( array( 'success' => true, 'webhooks' => $webhooks ) );
	}

	/**
	 * Delete a webhook by index.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_webhook( $request ) {
		$index    = (int) $request->get_param( 'index' );
		$webhooks = get_option( 'cwp_webhooks', array() );

		if ( ! isset( $webhooks[ $index ] ) ) {
			return new WP_Error( 'not_found', 'Webhook not found.', array( 'status' => 404 ) );
		}

		$removed = $webhooks[ $index ];
		array_splice( $webhooks, $index, 1 );
		update_option( 'cwp_webhooks', $webhooks );

		if ( class_exists( 'WP_MCP_Connect_Audit_Log' ) ) {
			WP_MCP_Connect_Audit_Log::log( 'webhook_removed', 'Webhook removed: ' . $removed['url'] );
		}

		return rest_ensure_response( array( 'success' => true, 'webhooks' => $webhooks ) );
	}
}
