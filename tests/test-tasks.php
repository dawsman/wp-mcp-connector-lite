<?php
/**
 * Tasks endpoint tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_Tasks_Test extends WP_UnitTestCase {

    protected static $admin_id;
    protected static $editor_id;
    protected $server;

    public static function wpSetUpBeforeClass( $factory ) {
        self::$admin_id  = $factory->user->create( array( 'role' => 'administrator' ) );
        self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
    }

    public function set_up() {
        parent::set_up();

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        do_action( 'init' );
    }

    public function tear_down() {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_tasks_endpoint_registered() {
        $routes = $this->server->get_routes();
        $this->assertArrayHasKey( '/mcp/v1/tasks', $routes );
        $this->assertArrayHasKey( '/mcp/v1/tasks/refresh', $routes );
    }

    public function test_create_and_list_task() {
        wp_set_current_user( self::$admin_id );

        $request = new WP_REST_Request( 'POST', '/mcp/v1/tasks' );
        $request->set_param( 'type', 'seo_missing' );
        $request->set_param( 'title', 'Test Task' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $list_request = new WP_REST_Request( 'GET', '/mcp/v1/tasks' );
        $list_response = $this->server->dispatch( $list_request );

        $this->assertEquals( 200, $list_response->get_status() );
        $data = $list_response->get_data();
        $this->assertArrayHasKey( 'tasks', $data );
    }

    /**
     * Task writes affect tasks owned by every user, so since 1.0.1 they are
     * restricted to manage_options. Reads stay open to editors.
     */
    public function test_editor_cannot_create_task() {
        wp_set_current_user( self::$editor_id );

        $request = new WP_REST_Request( 'POST', '/mcp/v1/tasks' );
        $request->set_param( 'type', 'seo_missing' );
        $request->set_param( 'title', 'Editor Task' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 403, $response->get_status() );
    }

    public function test_editor_can_list_tasks() {
        wp_set_current_user( self::$editor_id );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/tasks' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );
        $this->assertArrayHasKey( 'tasks', $response->get_data() );
    }

    public function test_editor_cannot_refresh_tasks() {
        wp_set_current_user( self::$editor_id );

        $request = new WP_REST_Request( 'POST', '/mcp/v1/tasks/refresh' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 403, $response->get_status() );
    }
}
