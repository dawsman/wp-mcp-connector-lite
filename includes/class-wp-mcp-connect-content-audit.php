<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles content audit functionality for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Content_Audit {

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
	 * Cache key prefix for broken images.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $cache_key    The transient cache key prefix.
	 */
	private $cache_key = 'cwp_broken_images';

	/**
	 * Cache expiration in seconds (10 minutes).
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      int    $cache_expiration    Cache expiration time.
	 */
	private $cache_expiration = 600;

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
		register_rest_route( 'mcp/v1', '/content/broken-images', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_broken_images' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'post_type' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => 'any',
					'sanitize_callback' => 'sanitize_key',
				),
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
	 * Check if user has permission to access audit endpoints.
	 *
	 * @since    1.0.0
	 * @return   bool    True if user can edit posts.
	 */
	public function check_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'edit_posts' );
	}

	/**
	 * Get posts with broken/missing images.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   array                          Paginated results.
	 */
	public function get_broken_images( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$page = max( 1, $request->get_param( 'page' ) );
		$per_page = min( max( 1, $request->get_param( 'per_page' ) ), 100 );
		$refresh = $request->get_param( 'refresh' );

		$cache_key = $this->cache_key . '_' . md5( $post_type . '_' . $page . '_' . $per_page );

		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$args = array(
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => 'publish',
		);

		if ( 'any' === $post_type ) {
			$args['post_type'] = get_post_types( array( 'public' => true ), 'names' );
		} else {
			$public_types = get_post_types( array( 'public' => true ), 'names' );
			if ( in_array( $post_type, $public_types, true ) ) {
				$args['post_type'] = $post_type;
			} else {
				$args['post_type'] = get_post_types( array( 'public' => true ), 'names' );
			}
		}

		$query = new WP_Query( $args );
		$results = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
				$content = get_the_content();

				$broken_images = $this->find_broken_images_in_content( $content, $post_id );

				if ( ! empty( $broken_images ) ) {
					$results[] = array(
						'post_id'       => $post_id,
						'post_title'    => get_the_title(),
						'post_type'     => get_post_type(),
						'post_url'      => get_permalink(),
						'edit_url'      => get_edit_post_link( $post_id, 'raw' ),
						'broken_images' => $broken_images,
					);
				}
			}
			wp_reset_postdata();
		}

		$response = array(
			'results'     => $results,
			'total_posts_scanned' => $query->found_posts,
			'posts_with_issues'   => count( $results ),
			'page'        => $page,
			'per_page'    => $per_page,
		);

		set_transient( $cache_key, $response, $this->cache_expiration );

		return $response;
	}

	/**
	 * Find broken images in post content.
	 *
	 * @since    1.0.0
	 * @param    string    $content    The post content.
	 * @param    int       $post_id    The post ID.
	 * @return   array                 Array of broken image info.
	 */
	private function find_broken_images_in_content( $content, $post_id ) {
		$broken = array();

		preg_match_all( '/<img[^>]+>/i', $content, $img_matches );

		if ( empty( $img_matches[0] ) ) {
			return $broken;
		}

		foreach ( $img_matches[0] as $img_tag ) {
			preg_match( '/src=["\']([^"\']+)["\']/i', $img_tag, $src_match );
			if ( empty( $src_match[1] ) ) {
				continue;
			}

			$src = $src_match[1];
			$issue = $this->check_image_url( $src );

			if ( $issue ) {
				$broken[] = array(
					'url'    => $src,
					'issue'  => $issue,
				);
			}
		}

		preg_match_all( '/wp-image-(\d+)/i', $content, $id_matches );

		if ( ! empty( $id_matches[1] ) ) {
			foreach ( $id_matches[1] as $attachment_id ) {
				$attachment_id = intval( $attachment_id );
				if ( $attachment_id > 0 && ! wp_attachment_is_image( $attachment_id ) ) {
					$broken[] = array(
						'attachment_id' => $attachment_id,
						'issue'         => 'deleted_attachment',
					);
				}
			}
		}

		$featured_image_id = get_post_thumbnail_id( $post_id );
		if ( $featured_image_id && ! wp_attachment_is_image( $featured_image_id ) ) {
			$broken[] = array(
				'attachment_id' => $featured_image_id,
				'issue'         => 'deleted_featured_image',
			);
		}

		return $broken;
	}

	/**
	 * Check if an image URL is valid.
	 *
	 * @since    1.0.0
	 * @param    string    $url    The image URL.
	 * @return   string|null       Issue description or null if OK.
	 */
	private function check_image_url( $url ) {
		if ( empty( $url ) ) {
			return 'empty_url';
		}

		$site_url = get_site_url();
		$is_local = strpos( $url, $site_url ) === 0 ||
		            strpos( $url, '/' ) === 0 ||
		            strpos( $url, 'wp-content' ) !== false;

		if ( $is_local ) {
			if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
				$url = $site_url . $url;
			}

			$upload_dir = wp_upload_dir();
			$upload_base = $upload_dir['basedir'];
			$upload_url = $upload_dir['baseurl'];

			if ( strpos( $url, $upload_url ) === 0 ) {
				$file_path = str_replace( $upload_url, $upload_base, $url );

				if ( ! file_exists( $file_path ) ) {
					return 'file_not_found';
				}
			}
		}

		return null;
	}

	/**
	 * Invalidate the broken images cache.
	 *
	 * @since    1.0.0
	 * @param    int    $post_id    The post ID.
	 * @return   void
	 */
	public function invalidate_cache( $post_id = null ) {
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
