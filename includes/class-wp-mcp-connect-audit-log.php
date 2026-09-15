<?php
defined( 'ABSPATH' ) || exit;

/**
 * Security audit log for WP MCP Connect.
 *
 * Logs security-sensitive mutations: settings changes and redirect CRUD.
 * Each entry captures timestamp, user_id, action_type, details,
 * and IP address.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Audit_Log {

	/**
	 * The plugin name.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string
	 */
	private $plugin_name;

	/**
	 * The plugin version.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string
	 */
	private $version;

	/**
	 * Initialize the class.
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
	 * Create the audit log database table.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function create_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'cwp_audit_log';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			user_login VARCHAR(60) DEFAULT '',
			action_type VARCHAR(50) NOT NULL,
			action_detail TEXT,
			ip_address VARCHAR(45) DEFAULT '',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_action_type (action_type),
			KEY idx_user_id (user_id),
			KEY idx_created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get the full table name.
	 *
	 * @since    1.0.0
	 * @return   string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'cwp_audit_log';
	}

	/**
	 * Log a security-relevant action.
	 *
	 * @since    1.0.0
	 * @param    string    $action_type    Category of action (settings_changed, redirect_created, etc.)
	 * @param    string    $detail         Human-readable description of what changed.
	 * @return   void
	 */
	public static function log( $action_type, $detail = '' ) {
		global $wpdb;
		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}

		$user = wp_get_current_user();
		$ip = '';
		if ( class_exists( 'WP_MCP_Connect_Auth' ) ) {
			$ip = WP_MCP_Connect_Auth::get_client_ip();
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$wpdb->insert(
			$table,
			array(
				'user_id'       => $user->ID ?? 0,
				'user_login'    => $user->user_login ?? '',
				'action_type'   => sanitize_key( $action_type ),
				'action_detail' => sanitize_textarea_field( $detail ),
				'ip_address'    => $ip,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get audit log entries.
	 *
	 * @since    1.0.0
	 * @param    array    $args    Query arguments (page, per_page, type).
	 * @return   array             Paginated results with entries, total, page, per_page, total_pages.
	 */
	public static function get_entries( $args = array() ) {
		global $wpdb;
		$table = self::table_name();

		$defaults = array(
			'page'     => 1,
			'per_page' => 50,
			'type'     => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$where = '1=1';
		$params = array();

		if ( ! empty( $args['type'] ) ) {
			$where .= ' AND action_type = %s';
			$params[] = $args['type'];
		}

		$offset = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
		$limit = min( 100, max( 1, (int) $args['per_page'] ) );

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			...$params
		), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$count_params = array_slice( $params, 0, -2 );
		if ( ! empty( $count_params ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE {$where}",
				...$count_params
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return array(
			'entries'     => $results,
			'total'       => $total,
			'page'        => (int) $args['page'],
			'per_page'    => $limit,
			'total_pages' => (int) ceil( $total / $limit ),
		);
	}

	/**
	 * Create table if not already at current schema version.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function maybe_create_table() {
		$installed = get_option( 'cwp_audit_log_db_version', '0' );
		if ( version_compare( $installed, '1.0', '<' ) ) {
			self::create_table();
			update_option( 'cwp_audit_log_db_version', '1.0' );
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/audit-log', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_get_entries' ),
			'permission_callback' => function() {
				return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
			},
			'args'                => array(
				'page'     => array( 'type' => 'integer', 'default' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 50 ),
				'type'     => array( 'type' => 'string', 'default' => '' ),
			),
		) );
	}

	/**
	 * REST callback to get audit log entries.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   WP_REST_Response
	 */
	public function rest_get_entries( $request ) {
		$entries = self::get_entries( array(
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
			'type'     => $request->get_param( 'type' ),
		) );
		return rest_ensure_response( $entries );
	}

	/**
	 * Log a redirect change from save_post_cwp_redirect hook.
	 *
	 * @since    1.0.0
	 * @param    int    $post_id    The redirect post ID.
	 * @return   void
	 */
	public function log_redirect_change( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		$from = get_post_meta( $post_id, '_cwp_from_url', true );
		self::log( 'redirect_changed', "Redirect #{$post_id} updated (from: {$from})" );
	}
}
