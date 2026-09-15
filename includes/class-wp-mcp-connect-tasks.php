<?php
defined( 'ABSPATH' ) || exit;

/**
 * Task queue for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Tasks {

	/**
	 * Task CPT slug.
	 *
	 * @since 1.0.0
	 */
	const POST_TYPE = 'cwp_task';

	/**
	 * The plugin name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $plugin_name;

	/**
	 * The plugin version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $version;

	/**
	 * Initialize the class.
	 *
	 * @since 1.0.0
	 * @param string                      $plugin_name Plugin name.
	 * @param string                      $version     Plugin version.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register the task CPT.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_cpt() {
		$labels = array(
			'name'          => _x( 'Tasks', 'Post Type General Name', 'wp-mcp-connect' ),
			'singular_name' => _x( 'Task', 'Post Type Singular Name', 'wp-mcp-connect' ),
		);

		$args = array(
			'label'        => __( 'Task', 'wp-mcp-connect' ),
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => false,
			'show_in_rest' => false,
			'supports'     => array( 'title' ),
			'rewrite'      => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register REST routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/tasks', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_tasks' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'status'   => array( 'type' => 'string' ),
					'type'     => array( 'type' => 'string' ),
					'priority' => array( 'type' => 'string' ),
					'assignee' => array( 'type' => 'string' ),
					'source'   => array( 'type' => 'string' ),
					'search'   => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_task' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'title'    => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string' ),
					'type'     => array( 'type' => 'string' ),
					'priority' => array( 'type' => 'string' ),
					'assignee' => array( 'type' => 'string' ),
					'post_id'  => array( 'type' => 'integer' ),
					'url'      => array( 'type' => 'string' ),
					'source'   => array( 'type' => 'string' ),
					'metadata' => array( 'type' => 'object' ),
				),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_task' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'id'       => array( 'required' => true, 'type' => 'integer' ),
					'status'   => array( 'type' => 'string' ),
					'priority' => array( 'type' => 'string' ),
					'assignee' => array( 'type' => 'string' ),
					'metadata' => array( 'type' => 'object' ),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/tasks/bulk', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_update_tasks' ),
			'permission_callback' => array( $this, 'check_write_permission' ),
			'args'                => array(
				'ids'    => array( 'required' => true, 'type' => 'array' ),
				'action' => array( 'required' => true, 'type' => 'string' ),
				'status' => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( 'mcp/v1', '/tasks/export', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'export_tasks' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/tasks/refresh', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'refresh_tasks' ),
			'permission_callback' => array( $this, 'check_write_permission' ),
			'args'                => array(
				'mode' => array( 'type' => 'string', 'default' => 'merge' ),
			),
		) );
	}

	/**
	 * Check read permissions (list/export).
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function check_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'edit_posts' );
	}

	/**
	 * Check write permissions (create/update/bulk/refresh).
	 *
	 * Writes include bulk-delete and cron-refresh triggers, which affect
	 * tasks created by any user and should be restricted to site admins.
	 *
	 * @since 1.0.1
	 * @return bool
	 */
	public function check_write_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
	}

	/**
	 * Maximum length (bytes) of the JSON-encoded task `metadata` blob.
	 */
	const MAX_METADATA_BYTES = 10000;

	/**
	 * List tasks.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function list_tasks( $request ) {
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );

		$meta_query = array();
		$filters = array(
			'status'   => 'status',
			'type'     => 'type',
			'priority' => 'priority',
			'assignee' => 'assignee',
			'source'   => 'source',
		);

		foreach ( $filters as $param => $meta_key ) {
			$value = $request->get_param( $param );
			if ( ! empty( $value ) ) {
				$meta_query[] = array(
					'key'   => '_cwp_task_' . $meta_key,
					'value' => sanitize_text_field( (string) $value ),
				);
			}
		}

		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => $search,
		);

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $args );
		$tasks = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$tasks[] = $this->format_task( get_the_ID() );
			}
			wp_reset_postdata();
		}

		return array(
			'tasks'       => $tasks,
			'total'       => (int) $query->found_posts,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Create a task.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function create_task( $request ) {
		$type = sanitize_text_field( (string) $request->get_param( 'type' ) );
		$post_id = (int) $request->get_param( 'post_id' );
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		$source = sanitize_text_field( (string) $request->get_param( 'source' ) );

		$fingerprint = $this->build_fingerprint( $type, $post_id, $url, $source );
		$existing_id = $this->find_task_by_fingerprint( $fingerprint );

		if ( $existing_id ) {
			return array(
				'success' => true,
				'task'    => $this->format_task( $existing_id ),
				'message' => __( 'Task already exists.', 'wp-mcp-connect' ),
			);
		}

		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( empty( $title ) ) {
			$title = $this->build_task_title( $type, $post_id, $url );
		}

		$post_id_inserted = wp_insert_post( array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		), true );

		if ( is_wp_error( $post_id_inserted ) ) {
			return $post_id_inserted;
		}

		$this->update_task_meta_from_request( $post_id_inserted, $request );
		update_post_meta( $post_id_inserted, '_cwp_task_fingerprint', $fingerprint );

		if ( ! get_post_meta( $post_id_inserted, '_cwp_task_status', true ) ) {
			update_post_meta( $post_id_inserted, '_cwp_task_status', 'open' );
		}
		if ( ! get_post_meta( $post_id_inserted, '_cwp_task_priority', true ) ) {
			update_post_meta( $post_id_inserted, '_cwp_task_priority', 'medium' );
		}

		return array(
			'success' => true,
			'task'    => $this->format_task( $post_id_inserted ),
		);
	}

	/**
	 * Update a task.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function update_task( $request ) {
		$id = (int) $request->get_param( 'id' );
		$task = get_post( $id );

		if ( ! $task || self::POST_TYPE !== $task->post_type ) {
			return new WP_Error( 'task_not_found', __( 'Task not found.', 'wp-mcp-connect' ), array( 'status' => 404 ) );
		}

		$this->update_task_meta_from_request( $id, $request );

		return array(
			'success' => true,
			'task'    => $this->format_task( $id ),
		);
	}

	/**
	 * Bulk update tasks.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function bulk_update_tasks( $request ) {
		$ids = $request->get_param( 'ids' );
		$action = sanitize_text_field( (string) $request->get_param( 'action' ) );
		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return new WP_Error( 'invalid_ids', __( 'No task IDs provided.', 'wp-mcp-connect' ), array( 'status' => 400 ) );
		}

		$updated = 0;
		foreach ( $ids as $id ) {
			$task_id = (int) $id;
			$task = get_post( $task_id );
			if ( ! $task || self::POST_TYPE !== $task->post_type ) {
				continue;
			}

			if ( 'resolve' === $action ) {
				update_post_meta( $task_id, '_cwp_task_status', $status ? $status : 'resolved' );
				$updated++;
			}
		}

		return array(
			'success' => true,
			'updated' => $updated,
		);
	}

	/**
	 * Export tasks as CSV.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function export_tasks() {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		$query = new WP_Query( $args );
		$rows = array();
		$rows[] = array( 'id', 'title', 'status', 'type', 'priority', 'assignee', 'post_id', 'url', 'source', 'created_at', 'updated_at' );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$task = $this->format_task( get_the_ID() );
				$rows[] = array(
					$task['id'],
					$task['title'],
					$task['status'],
					$task['type'],
					$task['priority'],
					$task['assignee'],
					$task['post_id'],
					$task['url'],
					$task['source'],
					$task['created_at'],
					$task['updated_at'],
				);
			}
			wp_reset_postdata();
		}

		$csv = $this->array_to_csv( $rows );

		return array(
			'filename' => 'tasks-export.csv',
			'csv'      => $csv,
			'total'    => count( $rows ) - 1,
		);
	}

	/**
	 * Refresh tasks from audits.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function refresh_tasks( $request ) {
		$mode = sanitize_text_field( (string) $request->get_param( 'mode' ) );
		$created = 0;

		if ( 'replace' === $mode ) {
			$this->delete_all_tasks();
		}

		$created += $this->create_tasks_from_seo_audit();
		$created += $this->create_tasks_from_missing_alt();
		$created += $this->create_tasks_from_broken_links();
		$created += $this->create_tasks_from_broken_images();
		$created += $this->create_tasks_from_orphaned_content();

		return array(
			'success' => true,
			'created' => $created,
		);
	}

	/**
	 * Schedule daily refresh if enabled.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_schedule_refresh() {
		$enabled = (bool) get_option( 'cwp_task_refresh_enabled', false );
		$frequency = get_option( 'cwp_task_refresh_frequency', 'daily' );

		$hook = 'cwp_task_refresh_event';
		$next = wp_next_scheduled( $hook );

		if ( ! $enabled ) {
			if ( $next ) {
				wp_unschedule_event( $next, $hook );
			}
			return;
		}

		$schedule = in_array( $frequency, array( 'daily', 'twicedaily', 'hourly' ), true ) ? $frequency : 'daily';

		if ( $next ) {
			wp_unschedule_event( $next, $hook );
		}

		wp_schedule_event( time() + 300, $schedule, $hook );
	}

	/**
	 * Cron callback for refresh.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_refresh_cron() {
		$request = new WP_REST_Request();
		$request->set_param( 'mode', 'merge' );
		$this->refresh_tasks( $request );
	}

	/**
	 * Helpers.
	 */
	private function update_task_meta_from_request( $task_id, $request ) {
		$status = $request->get_param( 'status' );
		$type = $request->get_param( 'type' );
		$priority = $request->get_param( 'priority' );
		$assignee = $request->get_param( 'assignee' );
		$post_id = $request->get_param( 'post_id' );
		$url = $request->get_param( 'url' );
		$source = $request->get_param( 'source' );
		$metadata = $request->get_param( 'metadata' );

		if ( null !== $status ) {
			update_post_meta( $task_id, '_cwp_task_status', sanitize_text_field( (string) $status ) );
		}
		if ( null !== $type ) {
			update_post_meta( $task_id, '_cwp_task_type', sanitize_text_field( (string) $type ) );
		}
		if ( null !== $priority ) {
			update_post_meta( $task_id, '_cwp_task_priority', sanitize_text_field( (string) $priority ) );
		}
		if ( null !== $assignee ) {
			update_post_meta( $task_id, '_cwp_task_assignee', sanitize_text_field( (string) $assignee ) );
		}
		if ( null !== $post_id ) {
			update_post_meta( $task_id, '_cwp_task_post_id', (int) $post_id );
		}
		if ( null !== $url ) {
			update_post_meta( $task_id, '_cwp_task_url', esc_url_raw( (string) $url ) );
		}
		if ( null !== $source ) {
			update_post_meta( $task_id, '_cwp_task_source', sanitize_text_field( (string) $source ) );
		}
		if ( null !== $metadata ) {
			$encoded = wp_json_encode( $metadata );
			if ( is_string( $encoded ) && strlen( $encoded ) <= self::MAX_METADATA_BYTES ) {
				update_post_meta( $task_id, '_cwp_task_metadata', $encoded );
			}
			// If the blob is oversized, silently skip the metadata write rather
			// than error — callers continue to get their task with core fields
			// set, but the oversized payload is dropped to protect postmeta.
		}
	}

	private function format_task( $task_id ) {
		$metadata_raw = get_post_meta( $task_id, '_cwp_task_metadata', true );
		$metadata = array();
		if ( ! empty( $metadata_raw ) ) {
			$decoded = json_decode( $metadata_raw, true );
			if ( is_array( $decoded ) ) {
				$metadata = $decoded;
			}
		}

		$post = get_post( $task_id );
		return array(
			'id'         => $task_id,
			'title'      => $post ? $post->post_title : '',
			'status'     => get_post_meta( $task_id, '_cwp_task_status', true ) ?: 'open',
			'type'       => get_post_meta( $task_id, '_cwp_task_type', true ) ?: '',
			'priority'   => get_post_meta( $task_id, '_cwp_task_priority', true ) ?: 'medium',
			'assignee'   => get_post_meta( $task_id, '_cwp_task_assignee', true ) ?: '',
			'post_id'    => (int) get_post_meta( $task_id, '_cwp_task_post_id', true ),
			'url'        => get_post_meta( $task_id, '_cwp_task_url', true ) ?: '',
			'source'     => get_post_meta( $task_id, '_cwp_task_source', true ) ?: '',
			'metadata'   => $metadata,
			'created_at' => $post ? $post->post_date_gmt : '',
			'updated_at' => $post ? $post->post_modified_gmt : '',
		);
	}

	private function build_task_title( $type, $post_id, $url ) {
		if ( $post_id ) {
			$title = get_the_title( $post_id );
			if ( $title ) {
				return sprintf( '%s: %s', ucfirst( str_replace( '_', ' ', $type ) ), $title );
			}
		}
		if ( $url ) {
			return sprintf( '%s: %s', ucfirst( str_replace( '_', ' ', $type ) ), $url );
		}
		return ucfirst( str_replace( '_', ' ', $type ) );
	}

	private function build_fingerprint( $type, $post_id, $url, $source ) {
		return md5( strtolower( trim( $type . '|' . $post_id . '|' . $url . '|' . $source ) ) );
	}

	private function find_task_by_fingerprint( $fingerprint ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_key'       => '_cwp_task_fingerprint',
			'meta_value'     => $fingerprint,
		);
		$query = new WP_Query( $args );
		if ( $query->have_posts() ) {
			return $query->posts[0]->ID;
		}
		return 0;
	}

	private function delete_all_tasks() {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		$query = new WP_Query( $args );
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $task_id ) {
				wp_delete_post( $task_id, true );
			}
		}
	}

	private function array_to_csv( $rows ) {
		$fh = fopen( 'php://temp', 'w' );
		foreach ( $rows as $row ) {
			fputcsv( $fh, $row );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	private function create_tasks_from_seo_audit() {
		if ( ! class_exists( 'WP_MCP_Connect_SEO_Bulk' ) ) {
			return 0;
		}
		$seo_bulk = new WP_MCP_Connect_SEO_Bulk( $this->plugin_name, $this->version );
		$request = new WP_REST_Request();
		$request->set_param( 'post_type', 'any' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 100 );

		$result = $seo_bulk->audit_seo( $request );
		if ( ! is_array( $result ) || empty( $result['results'] ) ) {
			return 0;
		}

		$created = 0;
		foreach ( $result['results'] as $row ) {
			if ( empty( $row['missing_fields'] ) ) {
				continue;
			}
			$type = 'seo_missing';
			$metadata = array(
				'missing_fields' => $row['missing_fields'],
			);

			$created += $this->create_task_from_data(
				$type,
				(int) $row['post_id'],
				(string) $row['post_url'],
				'seo_audit',
				$metadata
			);
		}
		return $created;
	}

	private function create_tasks_from_missing_alt() {
		if ( ! class_exists( 'WP_MCP_Connect_Media' ) ) {
			return 0;
		}
		$media = new WP_MCP_Connect_Media( $this->plugin_name, $this->version );
		$request = new WP_REST_Request();
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 100 );
		$response = $media->get_missing_alt_images( $request );
		if ( ! is_array( $response ) || empty( $response['results'] ) ) {
			return 0;
		}

		$created = 0;
		foreach ( $response['results'] as $image ) {
			$created += $this->create_task_from_data(
				'alt_missing',
				0,
				(string) $image['url'],
				'media_audit',
				array(
					'media_id' => (int) $image['id'],
					'filename' => $image['filename'],
				)
			);
		}
		return $created;
	}

	private function create_tasks_from_broken_links() {
		if ( ! class_exists( 'WP_MCP_Connect_Links' ) ) {
			return 0;
		}
		$links = new WP_MCP_Connect_Links( $this->plugin_name, $this->version );
		$request = new WP_REST_Request();
		$request->set_param( 'post_type', 'any' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 50 );
		$response = $links->get_broken_links( $request );
		if ( ! is_array( $response ) || empty( $response['posts'] ) ) {
			return 0;
		}

		$created = 0;
		foreach ( $response['posts'] as $post ) {
			foreach ( $post['broken_links'] as $broken ) {
				$created += $this->create_task_from_data(
					'broken_link',
					(int) $post['post_id'],
					(string) $broken['url'],
					'links_audit',
					array(
						'reason' => $broken['reason'],
					)
				);
			}
		}
		return $created;
	}

	private function create_tasks_from_broken_images() {
		if ( ! class_exists( 'WP_MCP_Connect_Content_Audit' ) ) {
			return 0;
		}
		$audit = new WP_MCP_Connect_Content_Audit( $this->plugin_name, $this->version );
		$request = new WP_REST_Request();
		$request->set_param( 'post_type', 'any' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 50 );
		$response = $audit->get_broken_images( $request );
		if ( ! is_array( $response ) || empty( $response['results'] ) ) {
			return 0;
		}

		$created = 0;
		foreach ( $response['results'] as $post ) {
			foreach ( $post['broken_images'] as $broken ) {
				$created += $this->create_task_from_data(
					'broken_image',
					(int) $post['post_id'],
					isset( $broken['url'] ) ? (string) $broken['url'] : '',
					'content_audit',
					$broken
				);
			}
		}
		return $created;
	}

	private function create_tasks_from_orphaned_content() {
		if ( ! class_exists( 'WP_MCP_Connect_Links' ) ) {
			return 0;
		}
		$links = new WP_MCP_Connect_Links( $this->plugin_name, $this->version );
		$request = new WP_REST_Request();
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 50 );
		$response = $links->get_orphaned_content( $request );
		if ( ! is_array( $response ) || empty( $response['posts'] ) ) {
			return 0;
		}

		$created = 0;
		foreach ( $response['posts'] as $post ) {
			$created += $this->create_task_from_data(
				'orphaned_content',
				(int) $post['post_id'],
				(string) $post['post_url'],
				'content_audit',
				array()
			);
		}
		return $created;
	}

	private function create_task_from_data( $type, $post_id, $url, $source, $metadata ) {
		$fingerprint = $this->build_fingerprint( $type, $post_id, $url, $source );
		$existing_id = $this->find_task_by_fingerprint( $fingerprint );
		if ( $existing_id ) {
			return 0;
		}

		$title = $this->build_task_title( $type, $post_id, $url );
		$post_id_inserted = wp_insert_post( array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		), true );

		if ( is_wp_error( $post_id_inserted ) ) {
			return 0;
		}

		update_post_meta( $post_id_inserted, '_cwp_task_status', 'open' );
		update_post_meta( $post_id_inserted, '_cwp_task_type', sanitize_text_field( $type ) );
		update_post_meta( $post_id_inserted, '_cwp_task_priority', 'medium' );
		update_post_meta( $post_id_inserted, '_cwp_task_assignee', '' );
		update_post_meta( $post_id_inserted, '_cwp_task_post_id', (int) $post_id );
		update_post_meta( $post_id_inserted, '_cwp_task_url', esc_url_raw( $url ) );
		update_post_meta( $post_id_inserted, '_cwp_task_source', sanitize_text_field( $source ) );
		// Cron callers can reach this path with attacker-influenced values
		// originating from post content (image srcs, anchor hrefs, etc.).
		// Sanitise recursively before encoding so rendered metadata in any
		// admin view can't carry script content.
		$encoded_meta = wp_json_encode( self::sanitize_metadata( $metadata ) );
		if ( is_string( $encoded_meta ) && strlen( $encoded_meta ) <= self::MAX_METADATA_BYTES ) {
			update_post_meta( $post_id_inserted, '_cwp_task_metadata', $encoded_meta );
		}
		update_post_meta( $post_id_inserted, '_cwp_task_fingerprint', $fingerprint );

		return 1;
	}

	/**
	 * Recursively sanitise a metadata value tree for safe storage + rendering.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function sanitize_metadata( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ is_string( $k ) ? sanitize_key( $k ) : $k ] = self::sanitize_metadata( $v );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		return (string) $value;
	}
}
