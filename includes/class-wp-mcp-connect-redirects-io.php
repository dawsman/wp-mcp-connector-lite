<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles redirect import/export functionality for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Redirects_IO {

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
	 * Register REST API routes for import/export.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/redirects/export', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'export_redirects' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/redirects/import', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'import_redirects' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'redirects' => array(
					'required'    => true,
					'type'        => 'array',
					'maxItems'    => self::MAX_IMPORT_ROWS,
					'description' => 'Array of redirect objects to import',
				),
				'mode' => array(
					'required'    => false,
					'type'        => 'string',
					'default'     => 'merge',
					'enum'        => array( 'merge', 'replace' ),
					'description' => 'Import mode: merge (add new, skip existing) or replace (delete all existing first)',
				),
			),
		) );
	}

	/**
	 * Hard cap on redirect rows accepted per import request. Protects against
	 * unbounded wp_insert_post/update_post_meta loops and downstream option
	 * bloat when the cache is rebuilt into cwp_redirect_rules.
	 */
	const MAX_IMPORT_ROWS = 5000;

	/**
	 * Check if user has permission to access import/export endpoints.
	 *
	 * @since    1.0.0
	 * @return   bool    True if user can manage options.
	 */
	public function check_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
	}

	/**
	 * Export all redirects.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   array                          Array of redirect data.
	 */
	public function export_redirects( $request ) {
		$args = array(
			'post_type'      => 'cwp_redirect',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		$query = new WP_Query( $args );
		$redirects = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();

				$redirects[] = array(
					'from_url'    => get_post_meta( $id, '_cwp_from_url', true ),
					'to_url'      => get_post_meta( $id, '_cwp_to_url', true ),
					'status_code' => intval( get_post_meta( $id, '_cwp_status_code', true ) ) ?: 301,
					'enabled'     => (int) ( get_post_meta( $id, '_cwp_enabled', true ) !== '' ? get_post_meta( $id, '_cwp_enabled', true ) : 1 ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'redirects' => $redirects,
			'total'     => count( $redirects ),
			'exported'  => current_time( 'c' ),
		);
	}

	/**
	 * Import redirects.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   array|WP_Error                 Import results or error.
	 */
	public function import_redirects( $request ) {
		$redirects = $request->get_param( 'redirects' );
		$mode = $request->get_param( 'mode' );

		if ( ! is_array( $redirects ) || empty( $redirects ) ) {
			return new WP_Error(
				'invalid_data',
				__( 'No redirects provided for import.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		// Defence-in-depth cap: the REST schema enforces maxItems but a future
		// refactor or filter could bypass that. A post-arrival slice keeps the
		// invariant local.
		if ( count( $redirects ) > self::MAX_IMPORT_ROWS ) {
			$redirects = array_slice( $redirects, 0, self::MAX_IMPORT_ROWS );
		}

		$results = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		$previous_state = $this->get_all_redirects();
		if ( class_exists( 'WP_MCP_Connect_Ops' ) ) {
			WP_MCP_Connect_Ops::log_operation( 'redirects_import', array( 'mode' => $mode, 'count' => count( $redirects ) ), $previous_state );
		}

		if ( 'replace' === $mode ) {
			$deleted = $this->delete_all_redirects();
			$results['deleted'] = $deleted;
		}

		$existing_urls = $this->get_existing_from_urls();

		foreach ( $redirects as $index => $redirect ) {
			$validation = $this->validate_redirect( $redirect, $index );
			if ( is_wp_error( $validation ) ) {
				$results['errors'][] = $validation->get_error_message();
				continue;
			}

			$from_url = '/' . ltrim( sanitize_text_field( $redirect['from_url'] ), '/' );

			if ( 'merge' === $mode && isset( $existing_urls[ $from_url ] ) ) {
				$results['skipped']++;
				continue;
			}

			$to_url = esc_url_raw( $redirect['to_url'] );
			$status_code = isset( $redirect['status_code'] ) ? intval( $redirect['status_code'] ) : 301;
			$enabled = isset( $redirect['enabled'] ) ? (int) (bool) $redirect['enabled'] : 1;

			if ( ! in_array( $status_code, $this->allowed_status_codes, true ) ) {
				$status_code = 301;
			}

			$post_id = wp_insert_post( array(
				'post_type'   => 'cwp_redirect',
				'post_status' => 'publish',
				'post_title'  => sprintf( 'Redirect: %s', $from_url ),
			) );

			if ( is_wp_error( $post_id ) ) {
				$results['errors'][] = sprintf(
					__( 'Failed to create redirect for %s: %s', 'wp-mcp-connect' ),
					$from_url,
					$post_id->get_error_message()
				);
				continue;
			}

			update_post_meta( $post_id, '_cwp_from_url', $from_url );
			update_post_meta( $post_id, '_cwp_to_url', $to_url );
			update_post_meta( $post_id, '_cwp_status_code', $status_code );
			update_post_meta( $post_id, '_cwp_enabled', $enabled );

			$results['imported']++;
			$existing_urls[ $from_url ] = true;
		}

		$this->rebuild_redirect_cache();

		return $results;
	}

	/**
	 * Validate a redirect object.
	 *
	 * @since    1.0.0
	 * @param    array    $redirect    The redirect data.
	 * @param    int      $index       The index in the import array.
	 * @return   bool|WP_Error         True if valid, WP_Error if invalid.
	 */
	private function validate_redirect( $redirect, $index ) {
		if ( ! is_array( $redirect ) ) {
			return new WP_Error(
				'invalid_format',
				sprintf( __( 'Item %d: Invalid redirect format.', 'wp-mcp-connect' ), $index + 1 )
			);
		}

		if ( empty( $redirect['from_url'] ) ) {
			return new WP_Error(
				'missing_from_url',
				sprintf( __( 'Item %d: Missing from_url.', 'wp-mcp-connect' ), $index + 1 )
			);
		}

		if ( empty( $redirect['to_url'] ) ) {
			return new WP_Error(
				'missing_to_url',
				sprintf( __( 'Item %d: Missing to_url.', 'wp-mcp-connect' ), $index + 1 )
			);
		}

		$from = '/' . ltrim( sanitize_text_field( $redirect['from_url'] ), '/' );
		$to = esc_url_raw( $redirect['to_url'] );
		$to_path = wp_parse_url( $to, PHP_URL_PATH );
		$to_path = '/' . ltrim( (string) $to_path, '/' );
		if ( $from === $to_path ) {
			return new WP_Error(
				'redirect_loop',
				sprintf( __( 'Item %d: Redirect loop detected.', 'wp-mcp-connect' ), $index + 1 )
			);
		}

		// Reject external destinations at write time. perform_redirect() also
		// re-checks at serve time, but we don't want corrupt open-redirect
		// rows sitting in the DB waiting for a future refactor to trust them.
		if ( class_exists( 'WP_MCP_Connect_Redirects' ) &&
			! WP_MCP_Connect_Redirects::is_internal_url( $to ) ) {
			return new WP_Error(
				'external_redirect_blocked',
				sprintf( __( 'Item %d: Redirects to external URLs are not allowed.', 'wp-mcp-connect' ), $index + 1 )
			);
		}

		return true;
	}

	/**
	 * Get existing from_urls as a lookup array.
	 *
	 * @since    1.0.0
	 * @return   array    Associative array of from_urls.
	 */
	private function get_existing_from_urls() {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE p.post_type = %s AND p.post_status = %s AND pm.meta_key = %s",
				'cwp_redirect',
				'publish',
				'_cwp_from_url'
			),
			ARRAY_A
		);

		$urls = array();
		foreach ( $results as $row ) {
			$urls[ $row['meta_value'] ] = true;
		}

		return $urls;
	}

	/**
	 * Delete all existing redirects.
	 *
	 * @since    1.0.0
	 * @return   int    Number of deleted redirects.
	 */
	private function delete_all_redirects() {
		$args = array(
			'post_type'      => 'cwp_redirect',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		);

		$post_ids = get_posts( $args );
		$deleted = 0;

		foreach ( $post_ids as $post_id ) {
			if ( wp_delete_post( $post_id, true ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Get all redirects for backup/rollback.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_all_redirects() {
		$args = array(
			'post_type'      => 'cwp_redirect',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);
		$query = new WP_Query( $args );
		$redirects = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();
				$redirects[] = array(
					'from_url'    => get_post_meta( $id, '_cwp_from_url', true ),
					'to_url'      => get_post_meta( $id, '_cwp_to_url', true ),
					'status_code' => intval( get_post_meta( $id, '_cwp_status_code', true ) ) ?: 301,
					'enabled'     => (int) ( get_post_meta( $id, '_cwp_enabled', true ) !== '' ? get_post_meta( $id, '_cwp_enabled', true ) : 1 ),
				);
			}
			wp_reset_postdata();
		}
		return $redirects;
	}

	/**
	 * Rebuild the redirect cache.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	private function rebuild_redirect_cache() {
		$redirects = new WP_MCP_Connect_Redirects( $this->plugin_name, $this->version );
		$redirects->update_redirect_cache();
	}
}
