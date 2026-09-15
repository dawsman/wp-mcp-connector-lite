<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared authentication, rate-limiting, and IP filtering utilities.
 *
 * Extracted from WP_MCP_Connect_API and WP_MCP_Connect_Settings to
 * eliminate duplicated permission/rate-limit logic.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Auth {

	/**
	 * Rate limit: maximum requests per minute (authenticated).
	 *
	 * @var int
	 */
	private static $rate_limit = 60;

	/**
	 * Rate limit window in seconds.
	 *
	 * @var int
	 */
	private static $rate_window = 60;

	/**
	 * Rate limit for unauthenticated requests (per IP).
	 *
	 * @var int
	 */
	private static $unauthenticated_rate_limit = 30;

	/**
	 * Check if user has admin permission (with IP + rate-limit checks).
	 *
	 * @since    1.0.0
	 * @return   bool|WP_Error    True if permitted, WP_Error on rate limit or IP blocked.
	 */
	public static function check_admin_permission() {
		return self::check_capability( 'manage_options' );
	}

	/**
	 * Shared REST permission check: capability, then IP allow/block list,
	 * then rate limit.
	 *
	 * Every mcp/v1 permission_callback must go through this so the IP
	 * filtering and rate limiting configured in settings apply uniformly.
	 * A bare current_user_can() silently bypasses both.
	 *
	 * @since    1.0.5
	 * @param    string    $capability    WordPress capability required.
	 * @return   bool|WP_Error            True if permitted, false if the
	 *                                    capability is missing, WP_Error on
	 *                                    IP block or rate limit.
	 */
	public static function check_capability( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			return false;
		}

		$ip_check = self::check_ip_filtering();
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		return self::check_rate_limit();
	}

	/**
	 * Check rate limit for current user or IP.
	 *
	 * Authenticated users are rate-limited by user ID.
	 * Unauthenticated requests are rate-limited by IP address.
	 *
	 * @since    1.0.0
	 * @param    bool    $authenticated    Unused, kept for backward compatibility.
	 * @return   bool|WP_Error    True if within limit, WP_Error if exceeded.
	 */
	public static function check_rate_limit( $authenticated = false ) {
		if ( wp_doing_cron() ) {
			return true; // Don't rate limit cron requests.
		}

		$user_id = get_current_user_id();

		if ( $user_id ) {
			$transient_key = 'cwp_rate_limit_user_' . $user_id;
			$limit = (int) get_option( 'cwp_rate_limit', self::$rate_limit );
		} else {
			$ip = self::get_client_ip();
			if ( empty( $ip ) ) {
				return true;
			}
			$ip_hash = md5( $ip );
			$transient_key = 'cwp_rate_limit_ip_' . $ip_hash;
			$limit = (int) get_option( 'cwp_rate_limit', self::$unauthenticated_rate_limit );
		}

		$rate_window = (int) get_option( 'cwp_rate_limit_window', self::$rate_window );
		$request_count = get_transient( $transient_key );

		if ( false === $request_count ) {
			set_transient( $transient_key, 1, $rate_window );
			return true;
		}

		if ( $request_count >= $limit ) {
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Rate limit exceeded. Please wait before making more requests.', 'wp-mcp-connect' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $transient_key, $request_count + 1, $rate_window );
		return true;
	}

	/**
	 * Check IP against whitelist/blacklist settings.
	 *
	 * @since    1.0.0
	 * @return   bool|WP_Error    True if permitted, WP_Error if blocked.
	 */
	public static function check_ip_filtering() {
		$ip = self::get_client_ip();

		if ( empty( $ip ) ) {
			return true;
		}

		$whitelist = get_option( 'cwp_ip_whitelist', '' );
		$blacklist = get_option( 'cwp_ip_blacklist', '' );

		if ( ! empty( $whitelist ) ) {
			if ( ! self::ip_in_list( $ip, $whitelist ) ) {
				return new WP_Error(
					'ip_not_whitelisted',
					__( 'Your IP address is not authorized to access this API.', 'wp-mcp-connect' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( ! empty( $blacklist ) ) {
			if ( self::ip_in_list( $ip, $blacklist ) ) {
				return new WP_Error(
					'ip_blacklisted',
					__( 'Your IP address has been blocked from accessing this API.', 'wp-mcp-connect' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Get the current client IP address with trusted-proxy support.
	 *
	 * @since    1.0.0
	 * @return   string    The client IP address.
	 */
	public static function get_client_ip() {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$trusted_proxies = get_option( 'cwp_trusted_proxies', '' );
		if ( empty( $trusted_proxies ) ) {
			return $remote_addr;
		}

		$proxy_list = array_values( array_filter( array_map( 'trim', explode( ',', $trusted_proxies ) ) ) );
		if ( ! in_array( $remote_addr, $proxy_list, true ) ) {
			// Request didn't arrive via a trusted proxy — forwarded headers are
			// client-controlled and must not be honoured.
			return $remote_addr;
		}

		// X-Forwarded-For is a comma-separated chain of the form
		// "client, proxy1, proxy2". The leftmost entry is attacker-controlled.
		// Walk from the right — the last entry was written by the nearest proxy
		// (REMOTE_ADDR), the one before it is what that proxy observed, etc.
		// The first IP that is *not* one of our trusted proxies is the real client.
		//
		// We intentionally do NOT consult single-value headers like
		// CF-Connecting-IP or X-Real-IP here: they're attacker-settable upstream
		// of any non-Cloudflare / non-NGINX proxy in the trusted list, so once
		// a site admin lists a generic proxy they'd inadvertently trust those
		// headers too. XFF's proxy-aware right-walk is the only reliable source.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain = array_map(
				'trim',
				explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) )
			);
			foreach ( array_reverse( $chain ) as $candidate ) {
				if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					continue;
				}
				if ( in_array( $candidate, $proxy_list, true ) ) {
					continue;
				}
				return $candidate;
			}
		}

		return $remote_addr;
	}

	/**
	 * Check if IP is in a comma-separated list (supports CIDR notation).
	 *
	 * @since    1.0.0
	 * @param    string    $ip      IP address.
	 * @param    string    $list    Comma-separated list.
	 * @return   bool               True if in list.
	 */
	public static function ip_in_list( $ip, $list ) {
		if ( empty( $list ) ) {
			return false;
		}

		$ips = array_map( 'trim', explode( ',', $list ) );

		foreach ( $ips as $check_ip ) {
			if ( empty( $check_ip ) ) {
				continue;
			}

			if ( strpos( $check_ip, '/' ) !== false ) {
				if ( self::ip_in_cidr( $ip, $check_ip ) ) {
					return true;
				}
			} elseif ( $ip === $check_ip ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if IP is in CIDR range.
	 *
	 * @since    1.0.0
	 * @param    string    $ip      IP address.
	 * @param    string    $cidr    CIDR notation.
	 * @return   bool               True if in range.
	 */
	public static function ip_in_cidr( $ip, $cidr ) {
		if ( strpos( $cidr, '/' ) === false ) {
			return false;
		}

		list( $subnet, $mask ) = explode( '/', $cidr );
		$mask = (int) $mask;

		$ip_bin = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );

		if ( false === $ip_bin || false === $subnet_bin ) {
			return false;
		}

		if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$is_ipv6 = strlen( $ip_bin ) === 16;
		$max_mask = $is_ipv6 ? 128 : 32;

		if ( $mask < 0 || $mask > $max_mask ) {
			return false;
		}

		$mask_bin = str_repeat( "\xff", (int) ( $mask / 8 ) );
		if ( $mask % 8 ) {
			$mask_bin .= chr( 0xff << ( 8 - ( $mask % 8 ) ) );
		}
		$mask_bin = str_pad( $mask_bin, strlen( $ip_bin ), "\x00" );

		return ( $ip_bin & $mask_bin ) === ( $subnet_bin & $mask_bin );
	}
}
