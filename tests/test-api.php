<?php
/**
 * API endpoint tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_API_Test extends WP_UnitTestCase {

    protected static $admin_id;
    protected static $editor_id;
    protected static $subscriber_id;
    protected $server;

    public static function wpSetUpBeforeClass( $factory ) {
        self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
        self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
        self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
    }

    public function set_up() {
        parent::set_up();

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down() {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_system_endpoint_registered() {
        $routes = $this->server->get_routes();
        $this->assertArrayHasKey( '/mcp/v1/system', $routes );
    }

    public function test_search_endpoint_registered() {
        $routes = $this->server->get_routes();
        $this->assertArrayHasKey( '/mcp/v1/search', $routes );
    }

    public function test_system_endpoint_requires_admin() {
        wp_set_current_user( self::$editor_id );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/system' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 403, $response->get_status() );
    }

    public function test_system_endpoint_returns_data_for_admin() {
        wp_set_current_user( self::$admin_id );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/system' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'site_name', $data );
        $this->assertArrayHasKey( 'site_url', $data );
        $this->assertArrayHasKey( 'wp_version', $data );
        $this->assertArrayHasKey( 'timezone', $data );
        $this->assertArrayHasKey( 'active_theme', $data );
        $this->assertArrayHasKey( 'active_plugin_count', $data );
        $this->assertArrayHasKey( 'seo_plugin', $data );
        $this->assertArrayHasKey( 'plugin_version', $data );

        // Sensitive fields are withheld unless ?details=true is requested.
        $this->assertArrayNotHasKey( 'php_version', $data );
        $this->assertArrayNotHasKey( 'plugins', $data );
    }

    public function test_system_endpoint_details_includes_sensitive_fields() {
        wp_set_current_user( self::$admin_id );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/system' );
        $request->set_param( 'details', 'true' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'php_version', $data );
        $this->assertArrayHasKey( 'plugins', $data );
        $this->assertEquals( phpversion(), $data['php_version'] );
    }

    public function test_search_endpoint_requires_editor() {
        wp_set_current_user( self::$subscriber_id );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/search' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 403, $response->get_status() );
    }

    public function test_search_endpoint_works_for_editor() {
        wp_set_current_user( self::$editor_id );

        $this->factory->post->create( array( 'post_title' => 'Test Search Post', 'post_status' => 'publish' ) );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/search' );
        $request->set_param( 'term', 'Test Search' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'results', $data );
        $this->assertArrayHasKey( 'total', $data );
        $this->assertArrayHasKey( 'total_pages', $data );
        $this->assertArrayHasKey( 'page', $data );
        $this->assertArrayHasKey( 'per_page', $data );
    }

    public function test_search_pagination_limits() {
        wp_set_current_user( self::$editor_id );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/search' );
        $request->set_param( 'per_page', 500 );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertLessThanOrEqual( 100, $data['per_page'] );
    }

    public function test_search_invalid_post_type() {
        wp_set_current_user( self::$editor_id );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/search' );
        $request->set_param( 'post_type', 'nonexistent_type' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 400, $response->get_status() );
    }

    public function test_search_by_post_type() {
        wp_set_current_user( self::$editor_id );

        $this->factory->post->create( array( 'post_title' => 'A Post', 'post_status' => 'publish', 'post_type' => 'post' ) );
        $this->factory->post->create( array( 'post_title' => 'A Page', 'post_status' => 'publish', 'post_type' => 'page' ) );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/search' );
        $request->set_param( 'post_type', 'page' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();

        foreach ( $data['results'] as $result ) {
            $this->assertEquals( 'page', $result['type'] );
        }
    }

    public function test_rate_limiting() {
        wp_set_current_user( self::$admin_id );
        delete_transient( 'cwp_rate_limit_user_' . self::$admin_id );

        for ( $i = 0; $i < 60; $i++ ) {
            $request = new WP_REST_Request( 'GET', '/mcp/v1/system' );
            $response = $this->server->dispatch( $request );
            $this->assertEquals( 200, $response->get_status() );
        }

        $request = new WP_REST_Request( 'GET', '/mcp/v1/system' );
        $response = $this->server->dispatch( $request );
        $this->assertEquals( 429, $response->get_status() );
    }
}
