<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin deactivation.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Deactivator {

	/**
	 * Plugin deactivation handler.
	 *
	 * Flushes rewrite rules to remove CPT rewrites.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function deactivate() {
		delete_transient( 'cwp_flush_rewrite_rules' );
		$tasks = wp_next_scheduled( 'cwp_task_refresh_event' );
		if ( $tasks ) {
			wp_unschedule_event( $tasks, 'cwp_task_refresh_event' );
		}

		$reports = wp_next_scheduled( 'cwp_weekly_report_event' );
		if ( $reports ) {
			wp_unschedule_event( $reports, 'cwp_weekly_report_event' );
		}
		$cleanup = wp_next_scheduled( 'cwp_404_cleanup_event' );
		if ( $cleanup ) {
			wp_unschedule_event( $cleanup, 'cwp_404_cleanup_event' );
		}

		$media_index = wp_next_scheduled( 'cwp_media_index_event' );
		if ( $media_index ) {
			wp_unschedule_event( $media_index, 'cwp_media_index_event' );
		}

		$ops_prune = wp_next_scheduled( 'cwp_ops_prune_event' );
		if ( $ops_prune ) {
			wp_unschedule_event( $ops_prune, 'cwp_ops_prune_event' );
		}
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->remove_cap( 'manage_cwp_redirects' );
		}

		delete_transient( 'wp_mcp_connect_updater_data' );
		flush_rewrite_rules();
	}
}
