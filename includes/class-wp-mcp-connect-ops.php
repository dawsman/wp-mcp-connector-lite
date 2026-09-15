<?php
defined( 'ABSPATH' ) || exit;

/**
 * Operations log and rollback support.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Ops {

	/**
	 * Table name suffix.
	 *
	 * @since 1.0.0
	 */
	const TABLE_NAME = 'cwp_ops_log';

	/**
	 * Retention: keep at most this many operations...
	 */
	const MAX_ROWS = 200;

	/**
	 * ...and none older than this many days. Rollback is only offered for
	 * rows that survive both limits.
	 */
	const MAX_AGE_DAYS = 90;

	/**
	 * Daily prune cron hook.
	 */
	const PRUNE_HOOK = 'cwp_ops_prune_event';

	/**
	 * Create the ops log table.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			op_type varchar(50) NOT NULL,
			payload longtext NULL,
			previous_state longtext NULL,
			user_id bigint(20) unsigned NULL,
			status varchar(20) NOT NULL DEFAULT 'completed',
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY idx_type (op_type),
			KEY idx_created (created_at)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create table if missing.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_create_table() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			self::create_table();
		}
	}

	/**
	 * Log an operation.
	 *
	 * @since 1.0.0
	 * @param string $op_type Operation type.
	 * @param mixed  $payload Operation payload.
	 * @param mixed  $previous_state Previous state.
	 * @param string $status Status.
	 * @return int Inserted ID.
	 */
	public static function log_operation( $op_type, $payload, $previous_state, $status = 'completed' ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->insert(
			$table,
			array(
				'op_type'        => sanitize_text_field( $op_type ),
				'payload'        => wp_json_encode( $payload ),
				'previous_state' => wp_json_encode( $previous_state ),
				'user_id'        => get_current_user_id(),
				'status'         => sanitize_text_field( $status ),
				'created_at'     => current_time( 'mysql', 1 ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Prune the ops log to MAX_ROWS newest rows and MAX_AGE_DAYS.
	 *
	 * Each row can hold a multi-megabyte previous_state snapshot (a full
	 * redirect table per import), so without this the table grows without
	 * bound.
	 *
	 * @since 1.0.5
	 * @return int Rows deleted.
	 */
	public static function prune_old_ops() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE_NAME;
		$deleted = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$deleted += (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $table WHERE created_at < %s",
			gmdate( 'Y-m-d H:i:s', time() - ( self::MAX_AGE_DAYS * DAY_IN_SECONDS ) )
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$cutoff_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET %d",
			self::MAX_ROWS
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $cutoff_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id <= %d", (int) $cutoff_id ) );
		}

		return $deleted;
	}

	/**
	 * Schedule the daily prune if not already scheduled.
	 *
	 * @since 1.0.5
	 * @return void
	 */
	public static function maybe_schedule_prune() {
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + 900, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @since 1.0.5
	 * @return void
	 */
	public function handle_prune_cron() {
		self::prune_old_ops();
	}

	/**
	 * Register routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/ops', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_ops' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'page'     => array( 'type' => 'integer', 'default' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
				'op_type'  => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( 'mcp/v1', '/ops/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_op' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/ops/rollback', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rollback_op' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'id' => array( 'required' => true, 'type' => 'integer' ),
			),
		) );
	}

	/**
	 * Permission check.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function check_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
	}

	/**
	 * List ops.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function list_ops( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$page = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$op_type = sanitize_text_field( (string) $request->get_param( 'op_type' ) );
		$offset = ( $page - 1 ) * $per_page;

		$where = 'WHERE 1=1';
		$params = array();
		if ( ! empty( $op_type ) ) {
			$where .= ' AND op_type = %s';
			$params[] = $op_type;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, op_type, status, user_id, created_at FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $params ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} {$where}",
					$params
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is controlled.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		}

		return array(
			'ops'         => $rows,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Get op details.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function get_op( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$id = (int) $request->get_param( 'id' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Operation not found.', 'wp-mcp-connect' ), array( 'status' => 404 ) );
		}

		$row['payload'] = json_decode( $row['payload'], true );
		$row['previous_state'] = json_decode( $row['previous_state'], true );

		return $row;
	}

	/**
	 * Roll back an operation.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function rollback_op( $request ) {
		$id = (int) $request->get_param( 'id' );
		$op = $this->get_op( $request );
		if ( is_wp_error( $op ) ) {
			return $op;
		}

		if ( empty( $op['op_type'] ) ) {
			return new WP_Error( 'invalid_op', __( 'Invalid operation data.', 'wp-mcp-connect' ), array( 'status' => 400 ) );
		}

		$result = false;
		switch ( $op['op_type'] ) {
			case 'seo_bulk':
				$result = $this->rollback_seo_bulk( $op['previous_state'] );
				break;
			case 'redirects_import':
				$result = $this->rollback_redirects_import( $op['previous_state'] );
				break;
			case 'custom_css':
				$result = $this->rollback_custom_css( $op['previous_state'] );
				break;
		}

		if ( ! $result ) {
			return new WP_Error( 'rollback_failed', __( 'Rollback failed or not supported.', 'wp-mcp-connect' ), array( 'status' => 400 ) );
		}

		return array(
			'success' => true,
			'op_id'   => $id,
		);
	}

	private function rollback_seo_bulk( $previous_state ) {
		if ( empty( $previous_state ) || ! is_array( $previous_state ) ) {
			return false;
		}

		foreach ( $previous_state as $item ) {
			$post_id = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
			if ( ! $post_id ) {
				continue;
			}
			if ( array_key_exists( 'seo_title', $item ) ) {
				update_post_meta( $post_id, '_cwp_seo_title', $item['seo_title'] );
			}
			if ( array_key_exists( 'seo_description', $item ) ) {
				update_post_meta( $post_id, '_cwp_seo_description', $item['seo_description'] );
			}
			if ( array_key_exists( 'og_title', $item ) ) {
				update_post_meta( $post_id, '_cwp_og_title', $item['og_title'] );
			}
			if ( array_key_exists( 'og_description', $item ) ) {
				update_post_meta( $post_id, '_cwp_og_description', $item['og_description'] );
			}
			if ( array_key_exists( 'og_image_id', $item ) ) {
				update_post_meta( $post_id, '_cwp_og_image_id', $item['og_image_id'] );
			}
			if ( array_key_exists( 'schema_json', $item ) ) {
				update_post_meta( $post_id, '_cwp_schema_json', $item['schema_json'] );
			}
		}
		return true;
	}

	private function rollback_redirects_import( $previous_state ) {
		if ( ! is_array( $previous_state ) ) {
			return false;
		}

		// Delete all redirects.
		$query = new WP_Query( array(
			'post_type'      => 'cwp_redirect',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		foreach ( $query->posts as $redirect_id ) {
			wp_delete_post( $redirect_id, true );
		}

		// Restore from previous state — re-apply the internal-URL check in
		// case the snapshot was taken when the check wasn't yet enforced.
		foreach ( $previous_state as $redirect ) {
			if ( empty( $redirect['from_url'] ) || empty( $redirect['to_url'] ) ) {
				continue;
			}
			if ( class_exists( 'WP_MCP_Connect_Redirects' ) &&
				! WP_MCP_Connect_Redirects::is_internal_url( $redirect['to_url'] ) ) {
				continue;
			}
			$post_id = wp_insert_post( array(
				'post_type'   => 'cwp_redirect',
				'post_status' => 'publish',
				'post_title'  => sprintf( 'Redirect: %s', $redirect['from_url'] ),
			) );
			if ( is_wp_error( $post_id ) ) {
				continue;
			}
			update_post_meta( $post_id, '_cwp_from_url', $redirect['from_url'] );
			update_post_meta( $post_id, '_cwp_to_url', $redirect['to_url'] );
			update_post_meta( $post_id, '_cwp_status_code', (int) $redirect['status_code'] );
			update_post_meta( $post_id, '_cwp_enabled', isset( $redirect['enabled'] ) ? (int) $redirect['enabled'] : 1 );
		}

		// Rebuild the serving cache now rather than waiting for the debounced
		// cron event: a rollback to an empty snapshot inserts nothing, so no
		// save_post hook would ever fire and the old rules would stay live.
		do_action( 'cwp_rebuild_redirect_cache' );

		return true;
	}

	private function rollback_custom_css( $previous_state ) {
		if ( ! is_array( $previous_state ) || ! array_key_exists( 'css', $previous_state ) ) {
			return false;
		}
		$result = wp_update_custom_css_post( $previous_state['css'] );
		return ! is_wp_error( $result );
	}
}
