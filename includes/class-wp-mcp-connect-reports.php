<?php
defined( 'ABSPATH' ) || exit;

/**
 * Weekly report generator.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Reports {

	/**
	 * Register routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/reports/weekly', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'send_weekly_report' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'send' => array( 'type' => 'boolean', 'default' => true ),
			),
		) );
	}

	/**
	 * Add weekly cron schedule.
	 *
	 * @since 1.0.0
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function register_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'wp-mcp-connect' ),
			);
		}
		return $schedules;
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
	 * Schedule weekly report.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_schedule_report() {
		$enabled = (bool) get_option( 'cwp_reports_enabled', false );
		$frequency = get_option( 'cwp_reports_frequency', 'weekly' );

		$hook = 'cwp_weekly_report_event';
		$next = wp_next_scheduled( $hook );

		if ( ! $enabled || 'weekly' !== $frequency ) {
			if ( $next ) {
				wp_unschedule_event( $next, $hook );
			}
			return;
		}

		if ( $next ) {
			wp_unschedule_event( $next, $hook );
		}

		$timestamp = self::next_monday_9am();
		wp_schedule_event( $timestamp, 'weekly', $hook );
	}

	/**
	 * Cron callback.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_weekly_cron() {
		$request = new WP_REST_Request();
		$request->set_param( 'send', true );
		$this->send_weekly_report( $request );
	}

	/**
	 * Generate and send the weekly report.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function send_weekly_report( $request ) {
		$send = (bool) $request->get_param( 'send' );
		$summary = $this->build_summary();
		$body = $this->format_report_body( $summary );

		// Split stored recipients on comma, validate each, drop anything that
		// isn't a well-formed email. This also strips any CR/LF that might have
		// reached the option via a path that didn't sanitise, preventing
		// mail-header injection in the To: header.
		$stored     = (string) get_option( 'cwp_reports_recipients', '' );
		$candidates = array_filter( array_map( 'trim', explode( ',', $stored ) ) );
		$valid      = array();
		foreach ( $candidates as $candidate ) {
			$clean = sanitize_email( $candidate );
			if ( '' !== $clean && is_email( $clean ) ) {
				$valid[] = $clean;
			}
		}
		$recipients = ! empty( $valid ) ? implode( ',', $valid ) : get_option( 'admin_email' );

		$sent = false;
		if ( $send ) {
			$subject = sprintf( __( '[%s] Weekly SEO Report', 'wp-mcp-connect' ), get_bloginfo( 'name' ) );
			$sent = wp_mail( $recipients, $subject, $body );
		}

		return array(
			'success'    => $send ? (bool) $sent : true,
			'sent'       => (bool) $sent,
			'recipients' => $recipients,
			'summary'    => $summary,
		);
	}

	/**
	 * Build report summary.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function build_summary() {
		$summary = array(
			'missing_seo'       => 0,
			'missing_alt'       => 0,
			'broken_links'      => 0,
			'broken_images'     => 0,
			'orphaned_content'  => 0,
		);

		// SEO audit summary.
		if ( class_exists( 'WP_MCP_Connect_SEO_Bulk' ) ) {
			$seo_bulk = new WP_MCP_Connect_SEO_Bulk( 'wp-mcp-connect', WP_MCP_CONNECT_VERSION );
			$request = new WP_REST_Request();
			$request->set_param( 'post_type', 'any' );
			$request->set_param( 'page', 1 );
			$request->set_param( 'per_page', 100 );
			$response = $seo_bulk->audit_seo( $request );
			if ( is_array( $response ) && ! empty( $response['summary'] ) ) {
				$summary['missing_seo'] = (int) $response['summary']['missing_title'] + (int) $response['summary']['missing_description'];
			}
		}

		// Missing alt count.
		if ( class_exists( 'WP_MCP_Connect_Media' ) ) {
			$media = new WP_MCP_Connect_Media( 'wp-mcp-connect', WP_MCP_CONNECT_VERSION );
			$request = new WP_REST_Request();
			$request->set_param( 'page', 1 );
			$request->set_param( 'per_page', 1 );
			$response = $media->get_missing_alt_images( $request );
			if ( is_array( $response ) && isset( $response['total'] ) ) {
				$summary['missing_alt'] = (int) $response['total'];
			}
		}

		// Broken links count.
		if ( class_exists( 'WP_MCP_Connect_Links' ) ) {
			$links = new WP_MCP_Connect_Links( 'wp-mcp-connect', WP_MCP_CONNECT_VERSION );
			$request = new WP_REST_Request();
			$request->set_param( 'post_type', 'any' );
			$request->set_param( 'page', 1 );
			$request->set_param( 'per_page', 1 );
			$response = $links->get_broken_links( $request );
			if ( is_array( $response ) && isset( $response['total'] ) ) {
				$summary['broken_links'] = (int) $response['total'];
			}
		}

		// Broken images count.
		if ( class_exists( 'WP_MCP_Connect_Content_Audit' ) ) {
			$audit = new WP_MCP_Connect_Content_Audit( 'wp-mcp-connect', WP_MCP_CONNECT_VERSION );
			$request = new WP_REST_Request();
			$request->set_param( 'post_type', 'any' );
			$request->set_param( 'page', 1 );
			$request->set_param( 'per_page', 1 );
			$response = $audit->get_broken_images( $request );
			if ( is_array( $response ) && isset( $response['posts_with_issues'] ) ) {
				$summary['broken_images'] = (int) $response['posts_with_issues'];
			}
		}

		// Orphaned content count.
		if ( class_exists( 'WP_MCP_Connect_Links' ) ) {
			$links = new WP_MCP_Connect_Links( 'wp-mcp-connect', WP_MCP_CONNECT_VERSION );
			$request = new WP_REST_Request();
			$request->set_param( 'post_type', 'post' );
			$request->set_param( 'page', 1 );
			$request->set_param( 'per_page', 1 );
			$response = $links->get_orphaned_content( $request );
			if ( is_array( $response ) && isset( $response['total'] ) ) {
				$summary['orphaned_content'] = (int) $response['total'];
			}
		}

		return $summary;
	}

	/**
	 * Format report body.
	 *
	 * @since 1.0.0
	 * @param array $summary Summary.
	 * @return string
	 */
	private function format_report_body( $summary ) {
		$lines = array();
		$lines[] = sprintf( 'Weekly SEO Report for %s', get_bloginfo( 'name' ) );
		$lines[] = '';
		$lines[] = 'Summary:';
		$lines[] = sprintf( 'Missing SEO fields: %d', (int) $summary['missing_seo'] );
		$lines[] = sprintf( 'Images missing alt text: %d', (int) $summary['missing_alt'] );
		$lines[] = sprintf( 'Broken internal links: %d', (int) $summary['broken_links'] );
		$lines[] = sprintf( 'Posts with broken images: %d', (int) $summary['broken_images'] );
		$lines[] = sprintf( 'Orphaned content: %d', (int) $summary['orphaned_content'] );
		$lines[] = '';

		$lines[] = 'Generated by WP MCP Connect.';

		return implode( "\n", $lines );
	}

	private static function next_monday_9am() {
		$now = current_time( 'timestamp' );
		$timestamp = strtotime( 'next monday 9am', $now );
		if ( false === $timestamp ) {
			$timestamp = $now + WEEK_IN_SECONDS;
		}
		return $timestamp;
	}
}
