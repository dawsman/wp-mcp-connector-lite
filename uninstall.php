<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'cwp_redirect_rules' );

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cwp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cwp_' ) . '%'
	)
);

$redirect_posts = get_posts( array(
	'post_type'      => 'cwp_redirect',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );

foreach ( $redirect_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}

// Removes every plugin meta key, including the media index keys
// _cwp_file_hash and _cwp_file_size.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_cwp_' ) . '%'
	)
);

// Remove task posts.
$task_posts = get_posts( array(
	'post_type'      => 'cwp_task',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );

foreach ( $task_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}

// Drop custom tables.
$cwp_tables = array(
	'cwp_404_log',
	'cwp_ops_log',
	'cwp_api_log',
	'cwp_audit_log',
	'cwp_topology',
	// Legacy cleanup: GSC tables from pre-Lite installs.
	'cwp_gsc_data',
	'cwp_gsc_queries',
	'cwp_gsc_sync_log',
	'cwp_gsc_data_snapshots',
	'cwp_gsc_query_snapshots',
	'cwp_gsc_ctr_curve',
);
foreach ( $cwp_tables as $cwp_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$cwp_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Remove remaining plugin options — especially anything holding secrets.
$cwp_options = array(
	// Legacy cleanup: Google Search Console OAuth + sync state from pre-Lite installs.
	'cwp_gsc_access_token',
	'cwp_gsc_refresh_token',
	'cwp_gsc_token_expiry',
	'cwp_gsc_site_url',
	'cwp_gsc_sync_enabled',
	'cwp_gsc_sync_frequency',
	'cwp_gsc_data_retention_days',
	'cwp_gsc_last_sync',
	// Webhooks (secret is HMAC key material).
	'cwp_webhook_secret',
	'cwp_webhooks',
	// Logging, versioning, general settings.
	'cwp_enable_logging',
	'cwp_plugin_version',
	'cwp_audit_log_db_version',
	'cwp_rate_limit',
	'cwp_rate_limit_window',
	'cwp_ip_whitelist',
	'cwp_ip_blacklist',
	'cwp_trusted_proxies',
	'cwp_reports_recipients',
	'cwp_automation_rules',
	'cwp_api_last_access',
);
foreach ( $cwp_options as $cwp_option ) {
	delete_option( $cwp_option );
}

// Remove custom capability granted to administrator role during activation.
$cwp_admin_role = get_role( 'administrator' );
if ( $cwp_admin_role instanceof WP_Role ) {
	$cwp_admin_role->remove_cap( 'manage_cwp_redirects' );
}

// Unschedule all plugin cron events.
$cwp_cron_hooks = array(
	'cwp_task_refresh_event',
	'cwp_weekly_report_event',
	'cwp_404_cleanup_event',
	'cwp_ops_prune_event',
	'cwp_media_index_event',
	// Legacy cleanup: GSC cron hooks from pre-Lite installs.
	'cwp_gsc_scheduled_sync',
	'cwp_gsc_manual_sync',
	'cwp_evaluate_rules',
	'cwp_refresh_tasks',
);
foreach ( $cwp_cron_hooks as $cwp_cron_hook ) {
	$cwp_ts = wp_next_scheduled( $cwp_cron_hook );
	if ( $cwp_ts ) {
		wp_unschedule_event( $cwp_ts, $cwp_cron_hook );
	}
	wp_clear_scheduled_hook( $cwp_cron_hook );
}
