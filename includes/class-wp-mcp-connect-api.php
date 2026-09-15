<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles core API functionality for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_API {

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
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string                   $plugin_name    The name of the plugin.
	 * @param    string                   $version        The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Register REST API routes.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/system', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_system_info' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/search', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'advanced_search' ),
			'permission_callback' => array( $this, 'check_edit_permission' ),
			'args'                => array(
				'term' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_type' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'per_page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 20,
					'sanitize_callback' => 'absint',
				),
				'page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/health', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'health_check' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'mcp/v1', '/seo/suggest', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'suggest_meta' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'post_ids' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/health-score/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_health_score' ),
			'permission_callback' => array( $this, 'check_edit_permission' ),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'recalculate' => array(
					'required' => false,
					'type'     => 'string',
					'default'  => 'false',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/health-score/bulk', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_health_score' ),
			'permission_callback' => array( $this, 'check_edit_permission' ),
			'args'                => array(
				'post_ids' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/health-score/summary', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'health_score_summary' ),
			'permission_callback' => array( $this, 'check_edit_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/repair-rankmath-schema', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'repair_rankmath_schema' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'post_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => __( 'Optional: repair single post by ID. If omitted, repairs all affected posts.', 'wp-mcp-connect' ),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/links/smart-suggest/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_smart_link_suggestions' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'limit' => array( 'type' => 'integer', 'default' => 10 ),
			),
		) );

		register_rest_route( 'mcp/v1', '/links/insert', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'insert_link' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'post_id'     => array( 'required' => true, 'type' => 'integer' ),
				'anchor_text' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'target_url'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
			),
		) );

		register_rest_route( 'mcp/v1', '/audit/summary', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_audit_summary' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/batch', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_batch' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'requests' => array(
					'required' => true,
					'type'     => 'array',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/content/clusters', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_content_clusters' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/content/thin', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_thin_content' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'threshold' => array(
					'type'    => 'integer',
					'default' => 300,
				),
				'post_type' => array(
					'type'    => 'string',
					'default' => 'post',
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 50,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/content/duplicates', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_duplicate_content' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'threshold' => array(
					'type'    => 'number',
					'default' => 0.6,
				),
				'post_type' => array(
					'type'    => 'string',
					'default' => 'post',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/api-access', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_api_access_info' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );
	}

	/**
	 * Check if user has admin permission.
	 *
	 * @since    1.0.0
	 * @return   bool|WP_Error    True if permitted, WP_Error on rate limit or IP blocked.
	 */
	public function check_admin_permission() {
		return WP_MCP_Connect_Auth::check_admin_permission();
	}

	/**
	 * Check if user has edit permission.
	 *
	 * @since    1.0.0
	 * @return   bool|WP_Error    True if permitted, WP_Error on rate limit or IP blocked.
	 */
	public function check_edit_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'edit_posts' );
	}

	/**
	 * Get system information.
	 *
	 * By default, sensitive fields (php_version, full plugin list) are omitted.
	 * Pass ?details=true to include them.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request|null    $request    The REST request object.
	 * @return   WP_REST_Response                    System information.
	 */
	public function get_system_info( $request = null ) {
		$result = array(
			'site_name'           => get_bloginfo( 'name' ),
			'site_url'            => home_url(),
			'wp_version'          => get_bloginfo( 'version' ),
			'timezone'            => wp_timezone_string(),
			'active_theme'        => get_stylesheet(),
			'active_plugin_count' => count( get_option( 'active_plugins', array() ) ),
			'seo_plugin'          => WP_MCP_Connect_SEO_Plugins::get_plugin_info(),
			'plugin_version'      => $this->version,
		);

		if ( $request && $request->get_param( 'details' ) === 'true' ) {
			$result['php_version'] = phpversion();
			$result['plugins']     = $this->get_active_plugins();
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get list of active plugins (simplified).
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   array    Array of active plugin names.
	 */
	private function get_active_plugins() {
		$active_plugins = get_option( 'active_plugins', array() );
		$all_plugins = get_plugins();
		$result = array();

		foreach ( $active_plugins as $plugin_path ) {
			if ( isset( $all_plugins[ $plugin_path ] ) ) {
				$result[] = array(
					'name'    => $all_plugins[ $plugin_path ]['Name'],
					'version' => $all_plugins[ $plugin_path ]['Version'],
				);
			}
		}

		return $result;
	}

	/**
	 * Perform advanced search.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   array|WP_Error                 Search results or error.
	 */
	public function advanced_search( $request ) {
		$term = $request->get_param( 'term' );
		$post_type = $request->get_param( 'post_type' );
		$per_page = $request->get_param( 'per_page' );
		$page = $request->get_param( 'page' );

		$per_page = min( max( $per_page, 1 ), 100 );
		$page = max( $page, 1 );

		$args = array(
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => 'publish',
		);

		if ( ! empty( $term ) ) {
			$args['s'] = $term;
		}

		if ( ! empty( $post_type ) ) {
			$public_post_types = get_post_types( array( 'public' => true ), 'names' );
			if ( in_array( $post_type, $public_post_types, true ) ) {
				$args['post_type'] = $post_type;
			} else {
				return new WP_Error(
					'invalid_post_type',
					__( 'Invalid or non-public post type specified.', 'wp-mcp-connect' ),
					array( 'status' => 400 )
				);
			}
		} else {
			$args['post_type'] = get_post_types( array( 'public' => true ), 'names' );
		}

		$query = new WP_Query( $args );
		$results = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$results[] = array(
					'id'      => get_the_ID(),
					'title'   => get_the_title(),
					'url'     => get_permalink(),
					'type'    => get_post_type(),
					'excerpt' => get_the_excerpt(),
					'date'    => get_the_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'results'     => $results,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Repair RankMath schema by removing corrupted data.
	 *
	 * When schema is written via MCP in a format incompatible with RankMath's
	 * internal structure, it can break the RankMath UI in the post editor.
	 * This endpoint removes the corrupted data, allowing RankMath to regenerate
	 * its default schema.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   array|WP_Error                 Repair results or error.
	 */
	public function repair_rankmath_schema( $request ) {
		$post_id = $request->get_param( 'post_id' );

		// Check if RankMath is active.
		$seo_plugin = WP_MCP_Connect_SEO_Plugins::get_plugin_info();
		if ( 'rank_math' !== $seo_plugin['slug'] ) {
			return new WP_Error(
				'rankmath_not_active',
				__( 'RankMath is not the active SEO plugin. This repair tool only applies to RankMath.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		if ( $post_id ) {
			// Repair single post.
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					__( 'Post not found.', 'wp-mcp-connect' ),
					array( 'status' => 404 )
				);
			}

			$result = WP_MCP_Connect_SEO_Plugins::repair_rank_math_schema( $post_id );

			return array(
				'success'  => true,
				'message'  => $result
					? sprintf( __( 'Successfully repaired schema for post %d.', 'wp-mcp-connect' ), $post_id )
					: sprintf( __( 'No corrupted schema found for post %d.', 'wp-mcp-connect' ), $post_id ),
				'post_id'  => $post_id,
				'repaired' => (bool) $result,
			);
		}

		// Repair all affected posts.
		$affected_posts = WP_MCP_Connect_SEO_Plugins::get_posts_with_corrupted_schema();
		$affected_count = count( $affected_posts );

		if ( 0 === $affected_count ) {
			return array(
				'success'        => true,
				'message'        => __( 'No posts with corrupted RankMath schema found.', 'wp-mcp-connect' ),
				'posts_repaired' => 0,
				'post_ids'       => array(),
			);
		}

		$rows_deleted = WP_MCP_Connect_SEO_Plugins::repair_rank_math_schema();

		return array(
			'success'        => true,
			'message'        => sprintf(
				/* translators: %d: number of posts repaired */
				__( 'Successfully repaired RankMath schema for %d posts.', 'wp-mcp-connect' ),
				$affected_count
			),
			'posts_repaired' => $affected_count,
			'rows_deleted'   => $rows_deleted,
			'post_ids'       => $affected_posts,
		);
	}

	/**
	 * Handle a batch of REST API sub-requests.
	 *
	 * Accepts an array of sub-requests (method, path, body) and dispatches
	 * each one internally via rest_do_request(). Capped at 25 sub-requests.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   WP_REST_Response|WP_Error      Batch results.
	 */
	public function handle_batch( $request ) {
		$requests = $request->get_param( 'requests' );

		if ( ! is_array( $requests ) ) {
			return new WP_Error( 'invalid_requests', 'Requests must be an array.', array( 'status' => 400 ) );
		}

		// Cap at 25 sub-requests.
		$requests = array_slice( $requests, 0, 25 );

		$results = array();

		foreach ( $requests as $i => $sub ) {
			if ( ! isset( $sub['method'] ) || ! isset( $sub['path'] ) ) {
				$results[] = array(
					'status' => 400,
					'body'   => array( 'error' => 'Each request must have method and path.' ),
				);
				continue;
			}

			$method = strtoupper( sanitize_text_field( $sub['method'] ) );
			$path   = sanitize_text_field( $sub['path'] );
			$body   = isset( $sub['body'] ) ? $sub['body'] : array();

			// Create an internal REST request.
			$sub_request = new WP_REST_Request( $method, $path );

			if ( ! empty( $body ) && is_array( $body ) ) {
				foreach ( $body as $key => $value ) {
					$sub_request->set_param( $key, $value );
				}
			}

			// Dispatch internally.
			$response = rest_do_request( $sub_request );
			$server   = rest_get_server();
			$data     = $server->response_to_data( $response, false );

			$results[] = array(
				'status' => $response->get_status(),
				'body'   => $data,
			);
		}

		return rest_ensure_response( array(
			'results' => $results,
			'count'   => count( $results ),
		) );
	}

	/**
	 * Get content clusters grouped by taxonomy.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response    Clusters data.
	 */
	public function get_content_clusters() {
		$clusters = WP_MCP_Connect_Clusters::build_clusters();
		return rest_ensure_response( array( 'clusters' => $clusters, 'count' => count( $clusters ) ) );
	}

	/**
	 * Suggest SEO meta title and description for given posts.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   WP_REST_Response               Suggestions array.
	 */
	public function suggest_meta( $request ) {
		$post_ids = array_map( 'absint', $request->get_param( 'post_ids' ) );
		$suggestions = WP_MCP_Connect_Meta_Suggest::suggest_bulk( $post_ids );
		return rest_ensure_response( array( 'suggestions' => $suggestions ) );
	}

	/**
	 * Health check endpoint.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response    Health status.
	 */
	public function health_check() {
		global $wpdb;
		$db_ok = (bool) $wpdb->get_var( 'SELECT 1' );
		$response = array(
			'status'    => $db_ok ? 'ok' : 'degraded',
			'timestamp' => gmdate( 'c' ),
		);
		// Only disclose the plugin version to authenticated users. The route
		// itself is unauthenticated for liveness probes, and a bare version
		// string in the public response is gratuitous fingerprinting fuel.
		if ( is_user_logged_in() ) {
			$response['version'] = $this->version;
		}
		return rest_ensure_response( $response );
	}

	/**
	 * Get health score for a single post.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   WP_REST_Response|WP_Error      Health score data.
	 */
	public function get_health_score( $request ) {
		$post_id     = (int) $request->get_param( 'id' );
		$recalculate = 'true' === $request->get_param( 'recalculate' );

		if ( $recalculate ) {
			$result = WP_MCP_Connect_Health_Score::calculate( $post_id );
		} else {
			$result = WP_MCP_Connect_Health_Score::get_score( $post_id );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Bulk calculate health scores.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   WP_REST_Response|WP_Error      Bulk health score results.
	 */
	public function bulk_health_score( $request ) {
		$post_ids = array_map( 'absint', $request->get_param( 'post_ids' ) );

		if ( count( $post_ids ) > 50 ) {
			return new WP_Error(
				'too_many_posts',
				__( 'Maximum 50 post IDs per request.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		$results = WP_MCP_Connect_Health_Score::calculate_bulk( $post_ids );

		return rest_ensure_response( array(
			'results' => $results,
			'total'   => count( $results ),
		) );
	}

	/**
	 * Get health score distribution summary across the site.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response    Distribution summary.
	 */
	public function health_score_summary() {
		global $wpdb;

		$statuses = array(
			'healthy'         => 0,
			'good'            => 0,
			'needs_attention' => 0,
			'declining'       => 0,
			'critical'        => 0,
		);

		$rows = $wpdb->get_results(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_cwp_health_score'"
		);

		$total     = 0;
		$sum_score = 0;

		foreach ( $rows as $row ) {
			$score  = (int) $row->meta_value;
			$status = WP_MCP_Connect_Health_Score::score_to_status( $score );
			if ( isset( $statuses[ $status ] ) ) {
				$statuses[ $status ]++;
			}
			$sum_score += $score;
			$total++;
		}

		return rest_ensure_response( array(
			'total'         => $total,
			'average_score' => $total > 0 ? round( $sum_score / $total, 1 ) : 0,
			'distribution'  => $statuses,
		) );
	}

	/**
	 * Get smart internal link suggestions for a post.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   WP_REST_Response               Suggestions data.
	 */
	public function get_smart_link_suggestions( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$limit = min( 20, (int) $request->get_param( 'limit' ) );
		$suggestions = WP_MCP_Connect_Link_Suggest::suggest( $post_id, $limit );
		return rest_ensure_response( array( 'suggestions' => $suggestions, 'post_id' => $post_id ) );
	}

	/**
	 * Insert a link into post content.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   WP_REST_Response|WP_Error      Result or error.
	 */
	public function insert_link( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to edit this post.', 'wp-mcp-connect' ),
				array( 'status' => 403 )
			);
		}
		$anchor = sanitize_text_field( $request->get_param( 'anchor_text' ) );
		$url = esc_url_raw( $request->get_param( 'target_url' ) );
		$result = WP_MCP_Connect_Link_Suggest::insert_link( $post_id, $anchor, $url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'message' => 'Link inserted successfully.' ) );
	}

	/**
	 * Get cross-audit summary aggregating issue counts from all audit types.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response    Aggregated audit summary.
	 */
	public function get_audit_summary() {
		$summary = array();

		// SEO audit counts.
		$summary['seo'] = $this->get_seo_audit_counts();

		// Media issues.
		$summary['media'] = $this->get_media_audit_counts();

		// Link issues.
		$summary['links'] = $this->get_link_audit_counts();

		// Health score distribution.
		$summary['health'] = array();
		if ( class_exists( 'WP_MCP_Connect_Health_Score' ) ) {
			global $wpdb;
			$counts = $wpdb->get_results(
				"SELECT
					CASE
						WHEN CAST(meta_value AS UNSIGNED) >= 80 THEN 'healthy'
						WHEN CAST(meta_value AS UNSIGNED) >= 60 THEN 'good'
						WHEN CAST(meta_value AS UNSIGNED) >= 40 THEN 'needs_attention'
						ELSE 'critical'
					END AS status,
					COUNT(*) AS count
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = '_cwp_health_score'
				 GROUP BY status",
				ARRAY_A
			);
			foreach ( $counts as $row ) {
				$summary['health'][ $row['status'] ] = (int) $row['count'];
			}
		}

		// 404 count.
		global $wpdb;
		$log_table = $wpdb->prefix . 'cwp_404_log';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$log_table}'" ) === $log_table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$summary['404_count'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$log_table} WHERE status = 'open'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		} else {
			$summary['404_count'] = 0;
		}

		// Topology stats.
		if ( class_exists( 'WP_MCP_Connect_Topology' ) ) {
			$topo_table = WP_MCP_Connect_Topology::table_name();
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$topo_table}'" ) === $topo_table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_types   = get_post_types( array( 'public' => true ), 'names' );
				$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
				$summary['orphan_pages'] = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					 WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})
					 AND p.ID NOT IN (SELECT DISTINCT target_post_id FROM {$topo_table})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...array_values( $post_types )
				) );
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
				$summary['dead_ends'] = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					 WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})
					 AND p.ID NOT IN (SELECT DISTINCT source_post_id FROM {$topo_table})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...array_values( $post_types )
				) );
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		// Total issues.
		$total = ( $summary['seo']['total_missing'] ?? 0 )
			   + ( $summary['media']['missing_alt'] ?? 0 )
			   + ( $summary['404_count'] ?? 0 )
			   + ( $summary['orphan_pages'] ?? 0 )
			   + ( $summary['dead_ends'] ?? 0 );
		$summary['total_issues'] = $total;

		return rest_ensure_response( $summary );
	}

	/**
	 * Get SEO audit counts for the summary.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   array    SEO audit count data.
	 */
	private function get_seo_audit_counts() {
		global $wpdb;
		$post_types   = get_post_types( array( 'public' => true ), 'names' );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...array_values( $post_types )
		) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$with_title = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})
			 AND pm.meta_key = '_cwp_seo_title' AND pm.meta_value != ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...array_values( $post_types )
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$with_desc = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})
			 AND pm.meta_key = '_cwp_seo_description' AND pm.meta_value != ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...array_values( $post_types )
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$missing_title = $total - $with_title;
		$missing_desc  = $total - $with_desc;

		return array(
			'total_posts'   => $total,
			'missing_title' => $missing_title,
			'missing_desc'  => $missing_desc,
			'total_missing' => $missing_title + $missing_desc,
		);
	}

	/**
	 * Get media audit counts for the summary.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   array    Media audit count data.
	 */
	private function get_media_audit_counts() {
		global $wpdb;
		$missing_alt = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
			 AND p.ID NOT IN (
				 SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attachment_image_alt' AND meta_value != ''
			 )"
		);
		return array( 'missing_alt' => $missing_alt );
	}

	/**
	 * Get link audit counts for the summary.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   array    Link audit count data.
	 */
	private function get_link_audit_counts() {
		global $wpdb;
		$table = $wpdb->prefix . 'cwp_internal_links';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array( 'total_links' => 0 );
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( 'total_links' => $total );
	}

	/**
	 * Track last API access time for security monitoring.
	 *
	 * Hooked to rest_request_after_callbacks so the route's permission_callback
	 * has already resolved authentication; calling wp_get_current_user() here
	 * reliably returns the app-password-authenticated user. Hooking earlier
	 * (e.g. rest_api_init) misses requests when $current_user has been set to
	 * anonymous by an earlier hook before our app password auth had a chance
	 * to run. Throttled to once per minute to avoid excessive writes.
	 *
	 * @since    1.0.0
	 * @param    mixed              $response Response from dispatch_request (passed through unchanged).
	 * @param    array              $handler  Route handler (unused).
	 * @param    WP_REST_Request    $request  REST request object (unused).
	 * @return   mixed                        Unchanged $response.
	 */
	public function track_api_access( $response, $handler = null, $request = null ) {
		// This filter fires for every REST namespace (wp/v2 block-editor
		// traffic included) and also after a failed permission_callback, so
		// scope it to successful mcp/v1 requests only.
		if ( ! $request instanceof WP_REST_Request || 0 !== strpos( $request->get_route(), '/mcp/v1/' ) ) {
			return $response;
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response instanceof WP_REST_Response && $response->is_error() ) {
			return $response;
		}

		$user = wp_get_current_user();
		if ( ! $user->ID ) {
			return $response;
		}

		// Only update once per minute to avoid excessive writes.
		$last = get_option( 'cwp_api_last_access', array() );
		$now  = time();
		if ( isset( $last['timestamp'] ) && ( $now - $last['timestamp'] ) < 60 ) {
			return $response;
		}

		update_option( 'cwp_api_last_access', array(
			'timestamp'  => $now,
			'user_id'    => $user->ID,
			'user_login' => $user->user_login,
			'ip'         => class_exists( 'WP_MCP_Connect_Auth' ) ? WP_MCP_Connect_Auth::get_client_ip() : '',
		), false );

		return $response;
	}

	/**
	 * Get API access info and credential security guidance.
	 *
	 * @since    1.0.0
	 * @return   WP_REST_Response    Last access data and recommendations.
	 */
	public function get_api_access_info() {
		$last = get_option( 'cwp_api_last_access', array() );
		return rest_ensure_response( array(
			'last_access'    => $last,
			'recommendation' => 'Create a dedicated WordPress user with only the required capabilities for MCP access, rather than using a full administrator account.',
		) );
	}

	/**
	 * GET /mcp/v1/content/thin
	 * Returns published posts with word count below threshold.
	 */
	public function get_thin_content( $request ) {
		$threshold = max( 1, (int) $request->get_param( 'threshold' ) );
		$post_type = sanitize_key( $request->get_param( 'post_type' ) );
		$per_page  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$posts = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'all',
		) );

		$results = array();
		foreach ( $posts as $post ) {
			$text       = wp_strip_all_tags( do_shortcode( $post->post_content ) );
			$word_count = str_word_count( $text );
			if ( $word_count < $threshold ) {
				$results[] = array(
					'post_id'    => $post->ID,
					'post_title' => $post->post_title,
					'word_count' => $word_count,
					'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
				);
			}
		}

		usort( $results, function( $a, $b ) { return $a['word_count'] - $b['word_count']; } );

		return array(
			'results'   => array_slice( $results, 0, $per_page ),
			'total'     => count( $results ),
			'threshold' => $threshold,
		);
	}

	/**
	 * GET /mcp/v1/content/duplicates
	 * Returns pairs of posts with Jaccard similarity >= threshold.
	 * Results cached for 1 hour.
	 */
	public function get_duplicate_content( $request ) {
		$threshold = (float) $request->get_param( 'threshold' );
		$threshold = max( 0.1, min( 1.0, $threshold ) );
		$post_type = sanitize_key( $request->get_param( 'post_type' ) );

		$cache_key = 'mcp_duplicate_content_' . $post_type;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$pairs = array_filter( $cached, function( $p ) use ( $threshold ) {
				return $p['similarity'] >= $threshold;
			} );
			return array(
				'pairs'     => array_values( $pairs ),
				'total'     => count( $pairs ),
				'threshold' => $threshold,
			);
		}

		$posts = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'all',
		) );

		// Build word sets per post (normalised: lowercase, unique words)
		$word_sets = array();
		foreach ( $posts as $post ) {
			$text   = strtolower( wp_strip_all_tags( do_shortcode( $post->post_content ) ) );
			$words  = preg_split( '/\W+/', $text, -1, PREG_SPLIT_NO_EMPTY );
			$word_sets[ $post->ID ] = array_unique( $words );
		}

		// Pairwise Jaccard similarity
		$all_pairs = array();
		$keys      = array_keys( $word_sets );
		for ( $i = 0; $i < count( $keys ); $i++ ) {
			for ( $j = $i + 1; $j < count( $keys ); $j++ ) {
				$id_a         = $keys[ $i ];
				$id_b         = $keys[ $j ];
				$intersection = count( array_intersect( $word_sets[ $id_a ], $word_sets[ $id_b ] ) );
				$union        = count( array_unique( array_merge( $word_sets[ $id_a ], $word_sets[ $id_b ] ) ) );
				if ( $union === 0 ) continue;
				$similarity = round( $intersection / $union, 3 );
				if ( $similarity >= 0.1 ) {
					$post_a = get_post( $id_a );
					$post_b = get_post( $id_b );
					$all_pairs[] = array(
						'post_a'     => array(
							'post_id'    => $id_a,
							'post_title' => $post_a->post_title,
							'edit_url'   => get_edit_post_link( $id_a, 'raw' ),
						),
						'post_b'     => array(
							'post_id'    => $id_b,
							'post_title' => $post_b->post_title,
							'edit_url'   => get_edit_post_link( $id_b, 'raw' ),
						),
						'similarity' => $similarity,
					);
				}
			}
		}

		// Sort by similarity descending
		usort( $all_pairs, function( $a, $b ) {
			return $b['similarity'] <=> $a['similarity'];
		} );

		set_transient( $cache_key, $all_pairs, HOUR_IN_SECONDS );

		$pairs = array_filter( $all_pairs, function( $p ) use ( $threshold ) {
			return $p['similarity'] >= $threshold;
		} );

		return array(
			'pairs'     => array_values( $pairs ),
			'total'     => count( $pairs ),
			'threshold' => $threshold,
		);
	}
}
