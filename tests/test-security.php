<?php
/**
 * Security tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_Security_Test extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	protected static $admin_id;
	protected static $subscriber_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Test that unauthenticated users cannot access protected endpoints.
	 */
	public function test_unauthenticated_access_blocked() {
		wp_set_current_user( 0 );

		// WP_REST_Server::dispatch() validates required args *before* it calls
		// the permission callback, so any endpoint with a required parameter
		// must be given one here — otherwise the 400 for the missing param
		// masks the authentication check we are actually asserting on.
		$protected_endpoints = array(
			array( 'GET', '/mcp/v1/seo/audit', array() ),
			array( 'POST', '/mcp/v1/content/create', array( 'title' => 'Unauthenticated Post' ) ),
			array( 'GET', '/mcp/v1/settings', array() ),
			array( 'POST', '/mcp/v1/settings', array() ),
			array( 'GET', '/mcp/v1/health-score/summary', array() ),
		);

		foreach ( $protected_endpoints as $endpoint ) {
			list( $method, $route, $params ) = $endpoint;

			$request = new WP_REST_Request( $method, $route );
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}

			$response = $this->server->dispatch( $request );
			$this->assertContains(
				$response->get_status(),
				array( 401, 403 ),
				"Endpoint {$method} {$route} should require authentication"
			);
		}
	}

	/**
	 * Test that external redirect URLs are rejected.
	 */
	public function test_external_redirect_rejected() {
		wp_set_current_user( self::$admin_id );

		// Give admin the custom capability.
		$admin_role = get_role( 'administrator' );
		$admin_role->add_cap( 'manage_cwp_redirects' );

		// Register the redirect CPT so wp_insert_post works.
		if ( ! post_type_exists( 'cwp_redirect' ) ) {
			register_post_type( 'cwp_redirect', array( 'public' => false ) );
		}

		$redirect_id = $this->factory()->post->create( array(
			'post_type'   => 'cwp_redirect',
			'post_status' => 'publish',
		) );

		update_post_meta( $redirect_id, '_cwp_from_url', '/old-page' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/redirects/' . $redirect_id );
		$request->set_param( 'to_url', 'https://evil.com/phishing' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status(), 'External redirect URL should be rejected' );
	}

	/**
	 * Test that is_internal_url handles malformed URLs safely.
	 */
	public function test_malformed_url_not_treated_as_internal() {
		// Malformed or off-site URLs must never be treated as internal.
		$this->assertFalse( WP_MCP_Connect_Redirects::is_internal_url( '://malformed' ) );
		$this->assertFalse( WP_MCP_Connect_Redirects::is_internal_url( 'https://evil.com' ) );
		$this->assertFalse( WP_MCP_Connect_Redirects::is_internal_url( 'https:evil.com' ) );
		$this->assertFalse( WP_MCP_Connect_Redirects::is_internal_url( '//evil.com/path' ) );
		$this->assertFalse( WP_MCP_Connect_Redirects::is_internal_url( '/\\evil.com' ) );
		$this->assertFalse( WP_MCP_Connect_Redirects::is_internal_url( "/path\nhttps://evil.com" ) );
		$this->assertFalse( WP_MCP_Connect_Redirects::is_internal_url( 'javascript:alert(1)' ) );
		$this->assertFalse( WP_MCP_Connect_Redirects::is_internal_url( '' ) );

		// Internal URLs should pass.
		$this->assertTrue( WP_MCP_Connect_Redirects::is_internal_url( '/some-page' ) );
		$this->assertTrue( WP_MCP_Connect_Redirects::is_internal_url( '/another/path' ) );
		$this->assertTrue( WP_MCP_Connect_Redirects::is_internal_url( home_url( '/on-site' ) ) );
	}

	/**
	 * Test that SEO meta fields are sanitized.
	 */
	public function test_seo_meta_sanitized() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp/v1/content/create' );
		$request->set_param( 'title', 'Test Post' );
		$request->set_param( 'seo_title', '<script>alert("xss")</script>Safe Title' );
		$request->set_param( 'seo_description', '<img onerror=alert(1)>Description' );
		$response = $this->server->dispatch( $request );

		if ( 200 === $response->get_status() || 201 === $response->get_status() ) {
			$data      = $response->get_data();
			$seo_title = get_post_meta( $data['id'], '_cwp_seo_title', true );
			$seo_desc  = get_post_meta( $data['id'], '_cwp_seo_description', true );

			$this->assertStringNotContainsString( '<script>', $seo_title );
			$this->assertStringNotContainsString( 'onerror', $seo_desc );
		}
	}

	/**
	 * Test that content creation requires proper capability.
	 */
	public function test_content_creation_requires_capability() {
		wp_set_current_user( self::$subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mcp/v1/content/create' );
		$request->set_param( 'title', 'Test Post' );
		$response = $this->server->dispatch( $request );

		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'Subscriber should not be able to create content'
		);
	}

	/**
	 * Test that settings endpoint requires administrator role.
	 */
	public function test_settings_requires_admin() {
		wp_set_current_user( self::$subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/mcp/v1/settings' );
		$response = $this->server->dispatch( $request );

		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'Subscriber should not be able to access settings'
		);
	}

	/**
	 * Test that admin can access settings.
	 */
	public function test_admin_can_access_settings() {
		wp_set_current_user( self::$admin_id );

		$request  = new WP_REST_Request( 'GET', '/mcp/v1/settings' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}
}
