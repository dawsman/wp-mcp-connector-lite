<?php
defined( 'ABSPATH' ) || exit;

/**
 * Analytics integration for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Analytics {

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
		register_rest_route( 'mcp/v1', '/analytics/popular-posts', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_popular_posts' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'post_type' => array(
					'type'    => 'string',
					'default' => 'post',
				),
				'period' => array(
					'type'    => 'string',
					'default' => '30',
					'enum'    => array( '7', '30', '90', 'all' ),
				),
				'limit' => array(
					'type'    => 'integer',
					'default' => 10,
					'maximum' => 50,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/analytics/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_analytics_status' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
	}

	/**
	 * Check if user has permission.
	 *
	 * @since    1.0.0
	 * @return   bool
	 */
	public function check_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'edit_posts' );
	}

	/**
	 * Get popular posts.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_popular_posts( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$period = $request->get_param( 'period' );
		$limit = min( 50, max( 1, $request->get_param( 'limit' ) ) );

		$source = $this->detect_analytics_source();

		if ( 'jetpack' === $source ) {
			return $this->get_jetpack_popular_posts( $post_type, $period, $limit );
		}

		return $this->get_fallback_popular_posts( $post_type, $limit );
	}

	/**
	 * Detect which analytics plugin is available.
	 *
	 * @since    1.0.0
	 * @return   string|null    Analytics source or null.
	 */
	private function detect_analytics_source() {
		if ( class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) ) {
			if ( \Jetpack::is_module_active( 'stats' ) ) {
				return 'jetpack';
			}
		}

		if ( function_exists( 'monsterinsights_get_v4_id' ) && monsterinsights_get_v4_id() ) {
			return 'monsterinsights';
		}

		return null;
	}

	/**
	 * Get popular posts from Jetpack Stats.
	 *
	 * @since    1.0.0
	 * @param    string    $post_type    Post type.
	 * @param    string    $period       Time period.
	 * @param    int       $limit        Number of posts.
	 * @return   array
	 */
	private function get_jetpack_popular_posts( $post_type, $period, $limit ) {
		if ( ! function_exists( 'stats_get_csv' ) ) {
			return $this->get_fallback_popular_posts( $post_type, $limit );
		}

		$days = 'all' === $period ? -1 : (int) $period;

		$stats = stats_get_csv( 'postviews', array(
			'days'    => $days,
			'limit'   => $limit * 2,
			'summarize' => 1,
		) );

		if ( empty( $stats ) ) {
			return $this->get_fallback_popular_posts( $post_type, $limit );
		}

		$posts = array();

		foreach ( $stats as $stat ) {
			if ( empty( $stat['post_id'] ) ) {
				continue;
			}

			$post = get_post( $stat['post_id'] );

			if ( ! $post || $post->post_type !== $post_type || 'publish' !== $post->post_status ) {
				continue;
			}

			$posts[] = array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title,
				'permalink' => get_permalink( $post->ID ),
				'views'     => (int) $stat['views'],
				'date'      => $post->post_date,
			);

			if ( count( $posts ) >= $limit ) {
				break;
			}
		}

		return array(
			'source' => 'jetpack',
			'period' => $period,
			'posts'  => $posts,
		);
	}

	/**
	 * Get popular posts based on comment count.
	 *
	 * @since    1.0.0
	 * @param    string    $post_type    Post type.
	 * @param    int       $limit        Number of posts.
	 * @return   array
	 */
	private function get_fallback_popular_posts( $post_type, $limit ) {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'comment_count',
			'order'          => 'DESC',
		);

		$query = new WP_Query( $args );
		$posts = array();

		while ( $query->have_posts() ) {
			$query->the_post();

			$posts[] = array(
				'id'            => get_the_ID(),
				'title'         => get_the_title(),
				'permalink'     => get_permalink(),
				'comment_count' => (int) get_comments_number(),
				'date'          => get_the_date( 'Y-m-d H:i:s' ),
			);
		}

		wp_reset_postdata();

		return array(
			'source'  => 'comment_count',
			'message' => 'No analytics plugin detected. Ranking by comment count.',
			'posts'   => $posts,
		);
	}

	/**
	 * Get analytics status.
	 *
	 * @since    1.0.0
	 * @return   array
	 */
	public function get_analytics_status() {
		$source = $this->detect_analytics_source();

		$plugins = array(
			'jetpack' => array(
				'installed' => class_exists( 'Jetpack' ),
				'active'    => class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) && \Jetpack::is_module_active( 'stats' ),
			),
			'monsterinsights' => array(
				'installed' => function_exists( 'monsterinsights' ),
				'active'    => function_exists( 'monsterinsights_get_v4_id' ) && monsterinsights_get_v4_id(),
			),
		);

		return array(
			'source'           => $source,
			'fallback'         => null === $source ? 'comment_count' : null,
			'detected_plugins' => $plugins,
		);
	}
}
