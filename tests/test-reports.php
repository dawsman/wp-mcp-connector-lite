<?php
/**
 * Reports tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_Reports_Test extends WP_UnitTestCase {

	protected static $admin_id;
	protected static $subscriber_id;
	protected $server;
	protected $reports;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function set_up() {
		parent::set_up();

		$this->reports = new WP_MCP_Connect_Reports();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	// ========================================================================
	// Route registration + permissions
	// ========================================================================

	public function test_weekly_report_route_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp/v1/reports/weekly', $routes );
	}

	public function test_subscriber_cannot_send_report() {
		wp_set_current_user( self::$subscriber_id );
		$request  = new WP_REST_Request( 'POST', '/mcp/v1/reports/weekly' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_admin_can_send_report() {
		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'POST', '/mcp/v1/reports/weekly' );
		$request->set_param( 'send', false );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
	}

	// ========================================================================
	// send_weekly_report
	// ========================================================================

	public function test_send_weekly_report_with_send_false() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp/v1/reports/weekly' );
		$request->set_param( 'send', false );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertFalse( $data['sent'] );
		$this->assertArrayHasKey( 'summary', $data );
	}

	public function test_send_weekly_report_with_send_true() {
		wp_set_current_user( self::$admin_id );

		// Reset the mailer to capture sent emails.
		reset_phpmailer_instance();

		$request = new WP_REST_Request( 'POST', '/mcp/v1/reports/weekly' );
		$request->set_param( 'send', true );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'sent', $data );
		$this->assertArrayHasKey( 'recipients', $data );
	}

	public function test_report_uses_admin_email_as_fallback() {
		wp_set_current_user( self::$admin_id );

		delete_option( 'cwp_reports_recipients' );

		$request = new WP_REST_Request( 'POST', '/mcp/v1/reports/weekly' );
		$request->set_param( 'send', false );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( get_option( 'admin_email' ), $data['recipients'] );
	}

	// ========================================================================
	// Summary structure
	// ========================================================================

	public function test_summary_has_expected_keys() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp/v1/reports/weekly' );
		$request->set_param( 'send', false );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$summary = $data['summary'];
		$this->assertArrayHasKey( 'missing_seo', $summary );
		$this->assertArrayHasKey( 'missing_alt', $summary );
		$this->assertArrayHasKey( 'broken_links', $summary );
		$this->assertArrayHasKey( 'broken_images', $summary );
		$this->assertArrayHasKey( 'orphaned_content', $summary );
	}

	// ========================================================================
	// Schedule registration
	// ========================================================================

	public function test_register_schedules_adds_weekly() {
		$schedules = WP_MCP_Connect_Reports::register_schedules( array() );
		$this->assertArrayHasKey( 'weekly', $schedules );
		$this->assertEquals( WEEK_IN_SECONDS, $schedules['weekly']['interval'] );
	}

	public function test_register_schedules_preserves_existing_weekly() {
		$existing = array(
			'weekly' => array(
				'interval' => 12345,
				'display'  => 'Custom Weekly',
			),
		);
		$schedules = WP_MCP_Connect_Reports::register_schedules( $existing );
		$this->assertEquals( 12345, $schedules['weekly']['interval'] );
	}

	// ========================================================================
	// next_monday_9am
	// ========================================================================

	public function test_maybe_schedule_report_creates_event_when_enabled() {
		update_option( 'cwp_reports_enabled', true );
		update_option( 'cwp_reports_frequency', 'weekly' );

		WP_MCP_Connect_Reports::maybe_schedule_report();

		$next = wp_next_scheduled( 'cwp_weekly_report_event' );
		$this->assertNotFalse( $next );
		$this->assertGreaterThan( time(), $next );

		// Cleanup.
		wp_unschedule_event( $next, 'cwp_weekly_report_event' );
		delete_option( 'cwp_reports_enabled' );
		delete_option( 'cwp_reports_frequency' );
	}
}
