<?php
defined( 'ABSPATH' ) || exit;

/**
 * 404 logging and redirect helpers.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_404_Log {

	/**
	 * Table name suffix.
	 *
	 * @since 1.0.0
	 */
	const TABLE_NAME = 'cwp_404_log';

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
	 * @param string $plugin_name Plugin name.
	 * @param string $version     Plugin version.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Create the 404 log table.
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
			url varchar(2048) NOT NULL,
			url_hash char(32) NOT NULL,
			referrer text NULL,
			hits int(10) unsigned NOT NULL DEFAULT 1,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			user_agent_hash char(32) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_url_hash (url_hash),
			KEY idx_status (status),
			KEY idx_last_seen (last_seen)
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
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $exists ) {
			self::create_table();
		}
	}

	/**
	 * Schedule daily cleanup.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_schedule_cleanup() {
		$hook = 'cwp_404_cleanup_event';
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + 600, 'daily', $hook );
		}
	}

	/**
	 * Cron callback to cleanup logs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_cleanup_cron() {
		self::cleanup_logs();
	}

	/**
	 * Register REST routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/404', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_entries' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'status'   => array( 'type' => 'string', 'default' => 'open' ),
					'search'   => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 25, 'maximum' => 100 ),
				),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_entry' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'     => array( 'type' => 'integer' ),
					'status' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/404/redirect', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_redirect_from_404' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'id'          => array( 'required' => true, 'type' => 'integer' ),
				'to_url'      => array( 'required' => true, 'type' => 'string' ),
				'status_code' => array( 'type' => 'integer', 'default' => 301 ),
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
	 * Log 404s on frontend.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_log_404() {
		if ( is_admin() || ! is_404() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( empty( $request_uri ) ) {
			return;
		}

		$url = home_url( $request_uri );
		$url_hash = md5( strtolower( $url ) );
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$user_agent_hash = $user_agent ? md5( $user_agent ) : null;

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, hits FROM {$table} WHERE url_hash = %s", $url_hash )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$now = current_time( 'mysql', 1 );

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'hits'      => (int) $existing->hits + 1,
					'last_seen' => $now,
					'referrer'  => $referrer,
					'status'    => 'open',
				),
				array( 'id' => (int) $existing->id ),
				array( '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'url'             => $url,
					'url_hash'        => $url_hash,
					'referrer'        => $referrer,
					'hits'            => 1,
					'first_seen'      => $now,
					'last_seen'       => $now,
					'status'          => 'open',
					'user_agent_hash' => $user_agent_hash,
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Cleanup old 404 logs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function cleanup_logs() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		// Remove older than 90 days.
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", $cutoff ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Cap table size to 5000 rows.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count > 5000 ) {
			$to_delete = $count - 5000;
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} ORDER BY last_seen ASC LIMIT %d",
					$to_delete
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * List 404 entries.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function list_entries( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset = ( $page - 1 ) * $per_page;

		$where = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $status ) && 'all' !== $status ) {
			$where .= ' AND status = %s';
			$params[] = $status;
		}

		if ( ! empty( $search ) ) {
			$where .= ' AND url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql = "SELECT * FROM {$table} {$where} ORDER BY last_seen DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		$count_params = array_slice( $params, 0, count( $params ) - 2 );
		if ( ! empty( $count_params ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $count_params ) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is controlled.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		}

		return array(
			'entries'     => $rows,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Update 404 entry status.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function update_entry( $request ) {
		$id = (int) $request->get_param( 'id' );
		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );

		if ( empty( $id ) || empty( $status ) ) {
			return new WP_Error( 'invalid_request', __( 'Invalid 404 update request.', 'wp-mcp-connect' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$updated = $wpdb->update(
			$table,
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return array(
			'success' => (bool) $updated,
			'id'      => $id,
			'status'  => $status,
		);
	}

	/**
	 * Create redirect from 404 entry.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function create_redirect_from_404( $request ) {
		$id = (int) $request->get_param( 'id' );
		$to_url = esc_url_raw( (string) $request->get_param( 'to_url' ) );
		$status_code = (int) $request->get_param( 'status_code' );

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $entry ) {
			return new WP_Error( 'not_found', __( '404 entry not found.', 'wp-mcp-connect' ), array( 'status' => 404 ) );
		}

		$from_url = wp_parse_url( $entry['url'], PHP_URL_PATH );
		if ( empty( $from_url ) ) {
			$from_url = '/';
		}

		$post_id = wp_insert_post( array(
			'post_type'   => 'cwp_redirect',
			'post_status' => 'publish',
			'post_title'  => sprintf( 'Redirect: %s', $from_url ),
		), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_cwp_from_url', $from_url );
		update_post_meta( $post_id, '_cwp_to_url', $to_url );
		update_post_meta( $post_id, '_cwp_status_code', in_array( $status_code, array( 301, 302, 307, 308 ), true ) ? $status_code : 301 );
		update_post_meta( $post_id, '_cwp_enabled', 1 );

		$wpdb->update( $table, array( 'status' => 'resolved' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		return array(
			'success' => true,
			'redirect_id' => $post_id,
		);
	}
}
