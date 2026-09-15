<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles redirect functionality for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Redirects {

	/**
	 * The plugin name.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The plugin version.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Allowed HTTP status codes for redirects.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array    $allowed_status_codes    Valid redirect status codes.
	 */
	private $allowed_status_codes = array( 301, 302, 307, 308 );

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string    $plugin_name    The name of the plugin.
	 * @param    string    $version        The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Register the redirect custom post type.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_redirect_cpt() {
		$labels = array(
			'name'                  => _x( 'Redirects', 'Post Type General Name', 'wp-mcp-connect' ),
			'singular_name'         => _x( 'Redirect', 'Post Type Singular Name', 'wp-mcp-connect' ),
			'menu_name'             => __( 'Redirects', 'wp-mcp-connect' ),
			'all_items'             => __( 'All Redirects', 'wp-mcp-connect' ),
			'add_new_item'          => __( 'Add New Redirect', 'wp-mcp-connect' ),
			'edit_item'             => __( 'Edit Redirect', 'wp-mcp-connect' ),
			'update_item'           => __( 'Update Redirect', 'wp-mcp-connect' ),
		);
		$args = array(
			'label'                 => __( 'Redirect', 'wp-mcp-connect' ),
			'labels'                => $labels,
			'supports'              => array( 'title' ),
			'public'                => false,
			'show_ui'               => true,
			'show_in_menu'          => false,
			'show_in_rest'          => true,
			'rest_base'             => 'redirects',
			'rewrite'               => false,
			'capability_type'       => array( 'cwp_redirect', 'cwp_redirects' ),
			'map_meta_cap'          => true,
			'capabilities'          => array(
				'edit_post'              => 'edit_cwp_redirect',
				'read_post'              => 'read_cwp_redirect',
				'delete_post'            => 'delete_cwp_redirect',
				'edit_posts'             => 'manage_cwp_redirects',
				'edit_others_posts'      => 'manage_cwp_redirects',
				'publish_posts'          => 'manage_cwp_redirects',
				'read_private_posts'     => 'manage_cwp_redirects',
				'delete_posts'           => 'manage_cwp_redirects',
				'delete_private_posts'   => 'manage_cwp_redirects',
				'delete_published_posts' => 'manage_cwp_redirects',
				'delete_others_posts'    => 'manage_cwp_redirects',
				'edit_private_posts'     => 'manage_cwp_redirects',
				'edit_published_posts'   => 'manage_cwp_redirects',
				'create_posts'           => 'manage_cwp_redirects',
			),
		);
		register_post_type( 'cwp_redirect', $args );

		if ( get_transient( 'cwp_flush_rewrite_rules' ) ) {
			delete_transient( 'cwp_flush_rewrite_rules' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Register REST API fields for the redirect CPT.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_api_fields() {
		$fields = array( 'from_url', 'to_url', 'status_code', 'enabled' );

		foreach ( $fields as $field ) {
			$schema_type = 'string';
			if ( 'enabled' === $field ) {
				$schema_type = 'boolean';
			}

			register_rest_field( 'cwp_redirect', $field, array(
				'get_callback'    => array( $this, 'get_meta_field' ),
				'update_callback' => array( $this, 'update_meta_field' ),
				'schema'          => array(
					'description' => 'Redirect ' . $field,
					'type'        => $schema_type,
					'context'     => array( 'view', 'edit' ),
				),
			) );
		}
	}

	/**
	 * Get meta field callback for REST API.
	 *
	 * @since    1.0.0
	 * @param    array     $object       The post object array.
	 * @param    string    $field_name   The field name.
	 * @param    object    $request      The REST request object.
	 * @return   mixed                   The meta value.
	 */
	public function get_meta_field( $object, $field_name, $request ) {
		$value = get_post_meta( $object['id'], '_cwp_' . $field_name, true );
		if ( 'enabled' === $field_name ) {
			return ( '' === $value ) ? true : (bool) $value;
		}
		return $value;
	}

	/**
	 * Validate that a URL is internal to the site (prevents open redirects).
	 *
	 * Exposed as a public static helper so the import/export handler can
	 * enforce the same check at write time without duplicating the logic.
	 *
	 * @since    1.0.0
	 * @param    string    $url    The URL to validate.
	 * @return   bool              True if internal, false if external.
	 */
	public static function is_internal_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return false;
		}

		// Backslashes are normalised to forward slashes by several browsers,
		// so "/\\evil.com" would leave the site despite looking relative.
		if ( false !== strpos( $url, '\\' ) ) {
			return false;
		}

		// Control characters (including encoded newlines/tabs) are stripped by
		// browsers before the URL is resolved, so they can hide a host.
		if ( preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return false;
		}

		// Site-relative paths are internal, but protocol-relative ones
		// ("//evil.com") are not.
		if ( '/' === $url[0] ) {
			return 0 !== strpos( $url, '//' );
		}

		$parsed = wp_parse_url( $url );

		// Anything that is not a plain relative path must parse to an absolute
		// http(s) URL on this host. A missing host is NOT treated as internal:
		// malformed input such as "://evil.com" or "https:evil.com" parses to a
		// bare path and would otherwise sail through this check.
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return strtolower( $parsed['host'] ) === strtolower( $site_host );
	}

	/**
	 * Update meta field callback for REST API.
	 *
	 * @since    1.0.0
	 * @param    mixed     $value        The value to save.
	 * @param    object    $object       The post object.
	 * @param    string    $field_name   The field name.
	 * @return   bool|int|WP_Error       True on success, false on failure, WP_Error for validation errors.
	 */
	public function update_meta_field( $value, $object, $field_name ) {
		$sanitized = sanitize_text_field( $value );

		if ( $field_name === 'from_url' ) {
			$sanitized = '/' . ltrim( esc_url_raw( $value ), '/' );

			$duplicate_id = $this->find_duplicate_redirect( $sanitized, $object->ID );
			if ( $duplicate_id ) {
				return new WP_Error(
					'duplicate_redirect',
					__( 'A redirect for this source URL already exists.', 'wp-mcp-connect' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( $field_name === 'to_url' ) {
			$sanitized = esc_url_raw( $value );

			// Prevent open redirects by validating the URL is internal.
			if ( ! self::is_internal_url( $sanitized ) ) {
				return new WP_Error(
					'external_redirect_blocked',
					__( 'Redirects to external URLs are not allowed for security. Use an internal path or URL on this domain.', 'wp-mcp-connect' ),
					array( 'status' => 400 )
				);
			}

			$from_url = get_post_meta( $object->ID, '_cwp_from_url', true );
			if ( $this->creates_redirect_loop( $from_url, $sanitized ) ) {
				return new WP_Error(
					'redirect_loop',
					__( 'This redirect would create a loop.', 'wp-mcp-connect' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( $field_name === 'status_code' ) {
			$code = intval( $value );
			$sanitized = in_array( $code, $this->allowed_status_codes, true ) ? $code : 301;
		}

		if ( $field_name === 'enabled' ) {
			$sanitized = (int) (bool) $value;
		}

		return update_post_meta( $object->ID, '_cwp_' . $field_name, $sanitized );
	}

	/**
	 * Cache redirects in a single option for performance.
	 *
	 * @since    1.0.0
	 * @param    int    $post_id    The post ID (optional, from save_post hook).
	 * @return   void
	 */
	public function update_redirect_cache( $post_id = null ) {
		$args = array(
			'post_type'      => 'cwp_redirect',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		);
		$query = new WP_Query( $args );
		$rules = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();
				$from = get_post_meta( $id, '_cwp_from_url', true );
				$to = get_post_meta( $id, '_cwp_to_url', true );
				$code = get_post_meta( $id, '_cwp_status_code', true );
				$enabled = get_post_meta( $id, '_cwp_enabled', true );

				if ( $from && $to && ( '' === $enabled || (int) $enabled === 1 ) ) {
					$from = '/' . ltrim( $from, '/' );
					$code_int = intval( $code );
					$rules[ $from ] = array(
						'to'   => $to,
						'code' => in_array( $code_int, $this->allowed_status_codes, true ) ? $code_int : 301,
					);
				}
			}
			wp_reset_postdata();
		}

		update_option( 'cwp_redirect_rules', $rules, true );
		wp_cache_delete( 'cwp_redirect_rules', 'options' );
		wp_cache_set( 'cwp_redirect_rules', $rules, 'cwp_mcp_connect' );
	}

	/**
	 * Schedule a debounced cache rebuild.
	 *
	 * Prevents N cache rebuilds during bulk imports by using a transient lock
	 * and a WP-Cron single event scheduled 5 seconds in the future.
	 *
	 * @since    1.0.0
	 * @param    int|null    $post_id    The post ID (from save_post hook).
	 * @return   void
	 */
	public function schedule_cache_rebuild( $post_id = null ) {
		if ( get_transient( 'cwp_redirect_cache_pending' ) ) {
			return;
		}
		set_transient( 'cwp_redirect_cache_pending', true, 30 );

		if ( ! wp_next_scheduled( 'cwp_rebuild_redirect_cache' ) ) {
			wp_schedule_single_event( time() + 5, 'cwp_rebuild_redirect_cache' );
		}
	}

	/**
	 * Execute the debounced cache rebuild.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function do_cache_rebuild() {
		delete_transient( 'cwp_redirect_cache_pending' );
		$this->update_redirect_cache();
	}

	/**
	 * Schedule a cache rebuild when a redirect is deleted, trashed or restored.
	 *
	 * Hooked to before_delete_post / trashed_post / untrashed_post, which fire
	 * for every post type, so the post type is checked here. Without this the
	 * serving cache only refreshed on save, and a deleted redirect kept firing.
	 *
	 * @since    1.0.5
	 * @param    int    $post_id    Post ID.
	 * @return   void
	 */
	public function maybe_schedule_cache_rebuild( $post_id ) {
		if ( 'cwp_redirect' !== get_post_type( $post_id ) ) {
			return;
		}
		$this->schedule_cache_rebuild( $post_id );
	}

	/**
	 * Perform the redirect if current path matches a rule.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function perform_redirect() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$rules = wp_cache_get( 'cwp_redirect_rules', 'cwp_mcp_connect' );
		if ( false === $rules ) {
			$rules = get_option( 'cwp_redirect_rules' );
			if ( ! empty( $rules ) ) {
				wp_cache_set( 'cwp_redirect_rules', $rules, 'cwp_mcp_connect' );
			}
		}

		if ( empty( $rules ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		
		if ( empty( $request_uri ) ) {
			return;
		}

		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$path = '/' . ltrim( $path, '/' );

		if ( isset( $rules[ $path ] ) ) {
			$rule = $rules[ $path ];
			$status_code = isset( $rule['code'] ) && in_array( intval( $rule['code'] ), $this->allowed_status_codes, true ) 
				? intval( $rule['code'] ) 
				: 301;
			$target_url = esc_url_raw( $rule['to'] );
			if ( ! self::is_internal_url( $target_url ) ) {
				return;
			}
			wp_safe_redirect( $target_url, $status_code );
			exit;
		}
	}

	/**
	 * Find duplicate redirect by source URL.
	 *
	 * @since 1.0.0
	 * @param string $from_url Source URL.
	 * @param int    $current_id Current post ID.
	 * @return int Duplicate ID or 0.
	 */
	private function find_duplicate_redirect( $from_url, $current_id ) {
		$args = array(
			'post_type'      => 'cwp_redirect',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_key'       => '_cwp_from_url',
			'meta_value'     => $from_url,
			'fields'         => 'ids',
		);
		$query = new WP_Query( $args );
		if ( $query->have_posts() ) {
			$found = (int) $query->posts[0];
			if ( $found && $found !== (int) $current_id ) {
				return $found;
			}
		}
		return 0;
	}

	/**
	 * Detect simple redirect loops.
	 *
	 * @since 1.0.0
	 * @param string $from_url From URL.
	 * @param string $to_url   To URL.
	 * @return bool
	 */
	private function creates_redirect_loop( $from_url, $to_url ) {
		$from_url = '/' . ltrim( (string) $from_url, '/' );
		$to_path = wp_parse_url( $to_url, PHP_URL_PATH );
		$to_path = '/' . ltrim( (string) $to_path, '/' );

		if ( $from_url === $to_path ) {
			return true;
		}

		$args = array(
			'post_type'      => 'cwp_redirect',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_key'       => '_cwp_from_url',
			'meta_value'     => $to_path,
			'fields'         => 'ids',
		);
		$query = new WP_Query( $args );
		if ( $query->have_posts() ) {
			$other_id = (int) $query->posts[0];
			$other_to = get_post_meta( $other_id, '_cwp_to_url', true );
			$other_to_path = wp_parse_url( $other_to, PHP_URL_PATH );
			$other_to_path = '/' . ltrim( (string) $other_to_path, '/' );
			if ( $other_to_path === $from_url ) {
				return true;
			}
		}

		return false;
	}
}
