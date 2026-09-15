<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin activation.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Activator {

	/**
	 * Plugin activation handler.
	 *
	 * Sets a transient flag to flush rewrite rules after CPT registration
	 * and creates the logging database table.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function activate() {
		set_transient( 'cwp_flush_rewrite_rules', true, 60 );

		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-404-log.php';
		WP_MCP_Connect_404_Log::create_table();
		WP_MCP_Connect_404_Log::maybe_schedule_cleanup();

		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-ops.php';
		WP_MCP_Connect_Ops::create_table();
		WP_MCP_Connect_Ops::maybe_schedule_prune();

		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-reports.php';
		WP_MCP_Connect_Reports::maybe_schedule_report();

		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-tasks.php';
		WP_MCP_Connect_Tasks::maybe_schedule_refresh();

		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-media-extended.php';
		WP_MCP_Connect_Media_Extended::maybe_schedule_index();

		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-topology.php';
		WP_MCP_Connect_Topology::create_table();

		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-audit-log.php';
		WP_MCP_Connect_Audit_Log::create_table();

		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( 'manage_cwp_redirects' );
		}

		update_option( 'cwp_plugin_version', WP_MCP_CONNECT_VERSION );
	}
}
