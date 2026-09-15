<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles media functionality for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Media {

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
	 * Cache key for missing alt images.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $cache_key    The transient cache key.
	 */
	private $cache_key = 'cwp_missing_alt_images';

	/**
	 * Cache expiration in seconds (5 minutes).
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      int    $cache_expiration    Cache expiration time.
	 */
	private $cache_expiration = 300;

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
	 * Register REST API routes.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/media/missing-alt', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_missing_alt_images' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 20,
					'sanitize_callback' => 'absint',
				),
				'refresh' => array(
					'required'          => false,
					'type'              => 'boolean',
					'default'           => false,
				),
			),
		) );
	}

	/**
	 * Check if user has permission to access media endpoints.
	 *
	 * @since    1.0.0
	 * @return   bool    True if user can edit posts.
	 */
	public function check_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'edit_posts' );
	}

	/**
	 * Get images missing alt text with pagination and caching.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   array                          Paginated results with missing alt images.
	 */
	public function get_missing_alt_images( $request ) {
		$page = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$refresh = $request->get_param( 'refresh' );

		$page = max( 1, $page );
		$per_page = min( max( 1, $per_page ), 100 );

		$cache_key = $this->cache_key . '_' . $page . '_' . $per_page;
		
		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
			),
		);

		$query = new WP_Query( $args );
		$results = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();
				$metadata = wp_get_attachment_metadata( $id );
				$attached_file = get_attached_file( $id );
				
				$results[] = array(
					'id'          => $id,
					'title'       => get_the_title(),
					'url'         => wp_get_attachment_url( $id ),
					'filename'    => $attached_file ? basename( $attached_file ) : '',
					'uploaded_at' => get_the_date( 'c' ),
					'resolution'  => isset( $metadata['width'], $metadata['height'] ) 
						? $metadata['width'] . 'x' . $metadata['height'] 
						: 'unknown',
				);
			}
			wp_reset_postdata();
		}

		$response = array(
			'results'     => $results,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);

		set_transient( $cache_key, $response, $this->cache_expiration );

		return $response;
	}

	/**
	 * Invalidate the missing alt images cache.
	 *
	 * Called when attachments are added, edited, or deleted.
	 *
	 * @since    1.0.0
	 * @param    int    $attachment_id    The attachment ID.
	 * @return   void
	 */
	public function invalidate_missing_alt_cache( $attachment_id = null ) {
		global $wpdb;

		$like_pattern = $wpdb->esc_like( '_transient_' . $this->cache_key ) . '%';
		$transients = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like_pattern
			)
		);

		foreach ( $transients as $transient ) {
			$key = str_replace( '_transient_', '', $transient );
			delete_transient( $key );
		}
	}
}
