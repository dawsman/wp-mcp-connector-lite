<?php
defined( 'ABSPATH' ) || exit;

/**
 * User and comment management for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Users {

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
		register_rest_route( 'mcp/v1', '/comments/pending', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_pending_comments' ),
			'permission_callback' => array( $this, 'check_moderate_permission' ),
			'args'                => array(
				'page' => array(
					'type'    => 'integer',
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
					'maximum' => 100,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/comments/moderate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'moderate_comment' ),
			'permission_callback' => array( $this, 'check_moderate_permission' ),
			'args'                => array(
				'comment_id' => array(
					'required' => true,
					'type'     => 'integer',
				),
				'action' => array(
					'required' => true,
					'type'     => 'string',
					'enum'     => array( 'approve', 'spam', 'trash', 'unapprove' ),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/comments/bulk-moderate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_moderate_comments' ),
			'permission_callback' => array( $this, 'check_moderate_permission' ),
			'args'                => array(
				'comment_ids' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
				),
				'action' => array(
					'required' => true,
					'type'     => 'string',
					'enum'     => array( 'approve', 'spam', 'trash', 'unapprove' ),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/users/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_author_stats' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'limit' => array(
					'type'    => 'integer',
					'default' => 10,
					'maximum' => 50,
				),
			),
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
	 * Check if user has moderation permission.
	 *
	 * @since    1.0.0
	 * @return   bool
	 */
	public function check_moderate_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'moderate_comments' );
	}

	/**
	 * Get pending comments.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_pending_comments( $request ) {
		$page = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
		$offset = ( $page - 1 ) * $per_page;

		$args = array(
			'status' => 'hold',
			'number' => $per_page,
			'offset' => $offset,
			'orderby' => 'comment_date_gmt',
			'order'  => 'DESC',
		);

		$comments = get_comments( $args );
		$total = wp_count_comments()->moderated;

		$results = array();

		foreach ( $comments as $comment ) {
			$post = get_post( $comment->comment_post_ID );

			$results[] = array(
				'id'           => (int) $comment->comment_ID,
				'author'       => $comment->comment_author,
				'author_email' => $comment->comment_author_email,
				'author_url'   => $comment->comment_author_url,
				'content'      => wp_trim_words( $comment->comment_content, 30 ),
				'date'         => $comment->comment_date,
				'post_id'      => (int) $comment->comment_post_ID,
				'post_title'   => $post ? $post->post_title : '',
				'post_url'     => get_permalink( $comment->comment_post_ID ),
			);
		}

		return array(
			'comments'    => $results,
			'total'       => (int) $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		);
	}

	/**
	 * Moderate a single comment.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error
	 */
	public function moderate_comment( $request ) {
		$comment_id = $request->get_param( 'comment_id' );
		$action = $request->get_param( 'action' );

		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error(
				'comment_not_found',
				__( 'Comment not found.', 'wp-mcp-connect' ),
				array( 'status' => 404 )
			);
		}

		$result = $this->apply_moderation_action( $comment_id, $action );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			'comment_id' => $comment_id,
			'action'     => $action,
			'new_status' => $result,
		);
	}

	/**
	 * Bulk moderate comments.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function bulk_moderate_comments( $request ) {
		$comment_ids = $request->get_param( 'comment_ids' );
		$action = $request->get_param( 'action' );

		if ( count( $comment_ids ) > 50 ) {
			return new WP_Error(
				'too_many_comments',
				__( 'Maximum 50 comments per request.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $comment_ids as $comment_id ) {
			$comment_id = absint( $comment_id );
			$comment = get_comment( $comment_id );

			if ( ! $comment ) {
				$results['failed'][] = array(
					'id'     => $comment_id,
					'reason' => 'Comment not found',
				);
				continue;
			}

			$result = $this->apply_moderation_action( $comment_id, $action );

			if ( is_wp_error( $result ) ) {
				$results['failed'][] = array(
					'id'     => $comment_id,
					'reason' => $result->get_error_message(),
				);
			} else {
				$results['success'][] = array(
					'id'         => $comment_id,
					'new_status' => $result,
				);
			}
		}

		return array(
			'action'    => $action,
			'processed' => count( $results['success'] ),
			'failed'    => count( $results['failed'] ),
			'results'   => $results,
		);
	}

	/**
	 * Apply moderation action to comment.
	 *
	 * @since    1.0.0
	 * @param    int       $comment_id    Comment ID.
	 * @param    string    $action        Action to apply.
	 * @return   string|WP_Error          New status or error.
	 */
	private function apply_moderation_action( $comment_id, $action ) {
		switch ( $action ) {
			case 'approve':
				$result = wp_set_comment_status( $comment_id, 'approve' );
				$new_status = 'approved';
				break;

			case 'unapprove':
				$result = wp_set_comment_status( $comment_id, 'hold' );
				$new_status = 'pending';
				break;

			case 'spam':
				$result = wp_spam_comment( $comment_id );
				$new_status = 'spam';
				break;

			case 'trash':
				$result = wp_trash_comment( $comment_id );
				$new_status = 'trash';
				break;

			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid moderation action.', 'wp-mcp-connect' ),
					array( 'status' => 400 )
				);
		}

		if ( ! $result ) {
			return new WP_Error(
				'moderation_failed',
				__( 'Failed to moderate comment.', 'wp-mcp-connect' ),
				array( 'status' => 500 )
			);
		}

		return $new_status;
	}

	/**
	 * Get author statistics.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_author_stats( $request ) {
		$limit = min( 50, max( 1, $request->get_param( 'limit' ) ) );

		global $wpdb;

		$authors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					u.ID,
					u.display_name,
					COUNT(p.ID) as post_count,
					MAX(p.post_date) as last_post_date
				FROM {$wpdb->users} u
				LEFT JOIN {$wpdb->posts} p ON u.ID = p.post_author AND p.post_status = 'publish' AND p.post_type = 'post'
				GROUP BY u.ID
				ORDER BY post_count DESC
				LIMIT %d",
				$limit
			)
		);

		$results = array();

		foreach ( $authors as $author ) {
			$results[] = array(
				'id'             => (int) $author->ID,
				'display_name'   => $author->display_name,
				'post_count'     => (int) $author->post_count,
				'last_post_date' => $author->last_post_date,
				'edit_url'       => get_edit_user_link( $author->ID ),
			);
		}

		return array(
			'authors' => $results,
			'total'   => count( $results ),
		);
	}
}
