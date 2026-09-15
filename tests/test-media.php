<?php
/**
 * Media functionality tests for WP MCP Connect.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_Media_Test extends WP_UnitTestCase {

    protected static $editor_id;
    protected static $subscriber_id;
    protected $server;

    public static function wpSetUpBeforeClass( $factory ) {
        self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
        self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
    }

    public function set_up() {
        parent::set_up();

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( self::$editor_id );
    }

    public function tear_down() {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    protected function create_test_attachment( $with_alt = false ) {
        $attachment_id = $this->factory->attachment->create( array(
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'Test Image',
            'post_status'    => 'inherit',
        ) );

        if ( $with_alt ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Test alt text' );
        }

        return $attachment_id;
    }

    public function test_missing_alt_endpoint_registered() {
        $routes = $this->server->get_routes();
        $this->assertArrayHasKey( '/mcp/v1/media/missing-alt', $routes );
    }

    public function test_missing_alt_requires_editor() {
        wp_set_current_user( self::$subscriber_id );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 403, $response->get_status() );
    }

    public function test_missing_alt_returns_images_without_alt() {
        $without_alt = $this->create_test_attachment( false );
        $with_alt = $this->create_test_attachment( true );

        delete_transient( 'cwp_missing_alt_images_1_20' );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'results', $data );
        $this->assertArrayHasKey( 'total', $data );
        $this->assertArrayHasKey( 'total_pages', $data );

        $ids = array_column( $data['results'], 'id' );
        $this->assertContains( $without_alt, $ids );
        $this->assertNotContains( $with_alt, $ids );
    }

    public function test_missing_alt_pagination() {
        for ( $i = 0; $i < 5; $i++ ) {
            $this->create_test_attachment( false );
        }

        $media = new WP_MCP_Connect_Media( 'wp-mcp-connect', '1.0.0' );
        $media->invalidate_missing_alt_cache();

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $request->set_param( 'per_page', 2 );
        $request->set_param( 'page', 1 );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $this->assertLessThanOrEqual( 2, count( $data['results'] ) );
        $this->assertEquals( 1, $data['page'] );
        $this->assertEquals( 2, $data['per_page'] );
    }

    public function test_per_page_max_limit() {
        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $request->set_param( 'per_page', 500 );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $this->assertLessThanOrEqual( 100, $data['per_page'] );
    }

    public function test_per_page_min_limit() {
        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $request->set_param( 'per_page', 0 );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $this->assertGreaterThanOrEqual( 1, $data['per_page'] );
    }

    public function test_results_include_required_fields() {
        $attachment_id = $this->create_test_attachment( false );

        $media = new WP_MCP_Connect_Media( 'wp-mcp-connect', '1.0.0' );
        $media->invalidate_missing_alt_cache();

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();

        if ( count( $data['results'] ) > 0 ) {
            $result = $data['results'][0];
            $this->assertArrayHasKey( 'id', $result );
            $this->assertArrayHasKey( 'title', $result );
            $this->assertArrayHasKey( 'url', $result );
            $this->assertArrayHasKey( 'filename', $result );
            $this->assertArrayHasKey( 'uploaded_at', $result );
            $this->assertArrayHasKey( 'resolution', $result );
        }
    }

    public function test_refresh_parameter_bypasses_cache() {
        $this->create_test_attachment( false );

        $cache_key = 'cwp_missing_alt_images_1_20';
        set_transient( $cache_key, array( 'results' => array(), 'total' => 0 ), 300 );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $request->set_param( 'refresh', false );
        $response = $this->server->dispatch( $request );
        $cached_data = $response->get_data();

        $this->assertEquals( 0, $cached_data['total'] );

        $request_refresh = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $request_refresh->set_param( 'refresh', true );
        $response_refresh = $this->server->dispatch( $request_refresh );
        $refreshed_data = $response_refresh->get_data();

        $this->assertGreaterThan( 0, $refreshed_data['total'] );
    }

    public function test_cache_invalidation() {
        $attachment_id = $this->create_test_attachment( false );

        $cache_key = 'cwp_missing_alt_images_1_20';
        set_transient( $cache_key, array( 'results' => array( array( 'id' => 999 ) ), 'total' => 1 ), 300 );

        $media = new WP_MCP_Connect_Media( 'wp-mcp-connect', '1.0.0' );
        $media->invalidate_missing_alt_cache( $attachment_id );

        $cached = get_transient( $cache_key );
        $this->assertFalse( $cached );
    }

    public function test_empty_alt_treated_as_missing() {
        $attachment_id = $this->factory->attachment->create( array(
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'Empty Alt Image',
            'post_status'    => 'inherit',
        ) );
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', '' );

        $media = new WP_MCP_Connect_Media( 'wp-mcp-connect', '1.0.0' );
        $media->invalidate_missing_alt_cache();

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $ids = array_column( $data['results'], 'id' );
        $this->assertContains( $attachment_id, $ids );
    }

    protected function create_uploaded_attachment( $title = 'Uploaded Image' ) {
        $attachment_id = $this->factory->attachment->create_upload_object(
            dirname( __FILE__ ) . '/data/test-image.jpg'
        );

        wp_update_post( array(
            'ID'         => $attachment_id,
            'post_title' => $title,
        ) );

        return $attachment_id;
    }

    public function test_oversized_endpoint_registered() {
        $routes = $this->server->get_routes();
        $this->assertArrayHasKey( '/mcp/v1/media/oversized', $routes );
    }

    public function test_index_status_endpoint_registered() {
        $routes = $this->server->get_routes();
        $this->assertArrayHasKey( '/mcp/v1/media/index-status', $routes );
        $this->assertArrayHasKey( '/mcp/v1/media/index-run', $routes );
    }

    public function test_index_attachment_records_hash_and_size() {
        $attachment_id = $this->create_uploaded_attachment();
        $file = get_attached_file( $attachment_id );

        $this->assertNotEmpty( $file );
        $this->assertFileExists( $file );

        $media_extended = new WP_MCP_Connect_Media_Extended( 'wp-mcp-connect', '1.0.0' );
        $media_extended->index_attachment( $attachment_id );

        $hash = get_post_meta( $attachment_id, '_cwp_file_hash', true );
        $size = get_post_meta( $attachment_id, '_cwp_file_size', true );

        $this->assertEquals( md5_file( $file ), $hash );
        $this->assertEquals( filesize( $file ), (int) $size );
    }

    public function test_index_attachment_skips_non_images() {
        $pdf_attachment = $this->factory->attachment->create( array(
            'post_mime_type' => 'application/pdf',
            'post_title'     => 'PDF File',
            'post_status'    => 'inherit',
        ) );

        $media_extended = new WP_MCP_Connect_Media_Extended( 'wp-mcp-connect', '1.0.0' );
        $media_extended->index_attachment( $pdf_attachment );

        $this->assertFalse( metadata_exists( 'post', $pdf_attachment, '_cwp_file_hash' ) );
        $this->assertFalse( metadata_exists( 'post', $pdf_attachment, '_cwp_file_size' ) );
    }

    public function test_index_attachment_removes_meta_when_file_missing() {
        $attachment_id = $this->create_uploaded_attachment();
        $file = get_attached_file( $attachment_id );

        $media_extended = new WP_MCP_Connect_Media_Extended( 'wp-mcp-connect', '1.0.0' );
        $media_extended->index_attachment( $attachment_id );
        $this->assertNotEmpty( get_post_meta( $attachment_id, '_cwp_file_hash', true ) );

        unlink( $file );
        $media_extended->index_attachment( $attachment_id );

        $this->assertFalse( metadata_exists( 'post', $attachment_id, '_cwp_file_hash' ) );
        $this->assertFalse( metadata_exists( 'post', $attachment_id, '_cwp_file_size' ) );
    }

    public function test_duplicates_groups_identical_files() {
        $first = $this->create_uploaded_attachment( 'Duplicate One' );
        $second = $this->create_uploaded_attachment( 'Duplicate Two' );

        $media_extended = new WP_MCP_Connect_Media_Extended( 'wp-mcp-connect', '1.0.0' );
        $media_extended->index_attachment( $first );
        $media_extended->index_attachment( $second );

        $this->assertEquals(
            get_post_meta( $first, '_cwp_file_hash', true ),
            get_post_meta( $second, '_cwp_file_hash', true )
        );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/duplicates' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'duplicate_groups', $data );
        $this->assertArrayHasKey( 'index_complete', $data );
        $this->assertArrayHasKey( 'unindexed', $data );
        $this->assertEquals( 1, $data['total'] );
        $this->assertCount( 1, $data['duplicate_groups'] );

        $group = $data['duplicate_groups'][0];
        $this->assertEquals( 2, $group['count'] );

        $ids = array_column( $group['images'], 'id' );
        $this->assertContains( $first, $ids );
        $this->assertContains( $second, $ids );
    }

    public function test_duplicates_ignores_unique_files() {
        $attachment_id = $this->create_uploaded_attachment( 'Unique Image' );

        $media_extended = new WP_MCP_Connect_Media_Extended( 'wp-mcp-connect', '1.0.0' );
        $media_extended->index_attachment( $attachment_id );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/duplicates' );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $this->assertEquals( 0, $data['total'] );
        $this->assertSame( array(), $data['duplicate_groups'] );
    }

    public function test_oversized_uses_size_index() {
        $attachment_id = $this->create_uploaded_attachment( 'Sized Image' );

        $media_extended = new WP_MCP_Connect_Media_Extended( 'wp-mcp-connect', '1.0.0' );
        $media_extended->index_attachment( $attachment_id );

        $size = (int) get_post_meta( $attachment_id, '_cwp_file_size', true );
        $this->assertGreaterThan( 0, $size );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/oversized' );
        $request->set_param( 'threshold', $size );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $ids = array_column( $data['images'], 'id' );
        $this->assertContains( $attachment_id, $ids );
        $this->assertGreaterThanOrEqual( 1, $data['total'] );

        $request_above = new WP_REST_Request( 'GET', '/mcp/v1/media/oversized' );
        $request_above->set_param( 'threshold', $size + 1 );
        $response_above = $this->server->dispatch( $request_above );

        $ids_above = array_column( $response_above->get_data()['images'], 'id' );
        $this->assertNotContains( $attachment_id, $ids_above );
    }

    public function test_index_status_reports_progress() {
        $attachment_id = $this->create_uploaded_attachment( 'Status Image' );
        delete_post_meta( $attachment_id, '_cwp_file_hash' );

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/index-status' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'total_images', $data );
        $this->assertArrayHasKey( 'indexed', $data );
        $this->assertArrayHasKey( 'unindexed', $data );
        $this->assertArrayHasKey( 'index_complete', $data );
        $this->assertGreaterThanOrEqual( 1, $data['unindexed'] );
        $this->assertFalse( $data['index_complete'] );
    }

    public function test_index_run_requires_manage_options() {
        wp_set_current_user( self::$editor_id );

        $request = new WP_REST_Request( 'POST', '/mcp/v1/media/index-run' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 403, $response->get_status() );
    }

    public function test_index_batch_backfills_existing_attachments() {
        $attachment_id = $this->create_uploaded_attachment( 'Backfill Image' );
        delete_post_meta( $attachment_id, '_cwp_file_hash' );
        delete_post_meta( $attachment_id, '_cwp_file_size' );

        $media_extended = new WP_MCP_Connect_Media_Extended( 'wp-mcp-connect', '1.0.0' );
        $processed = $media_extended->run_index_batch();

        $this->assertGreaterThanOrEqual( 1, $processed );
        $this->assertNotEmpty( get_post_meta( $attachment_id, '_cwp_file_hash', true ) );
        $this->assertGreaterThan( 0, (int) get_post_meta( $attachment_id, '_cwp_file_size', true ) );
    }

    public function test_only_images_returned() {
        $pdf_attachment = $this->factory->attachment->create( array(
            'post_mime_type' => 'application/pdf',
            'post_title'     => 'PDF File',
            'post_status'    => 'inherit',
        ) );

        $image_attachment = $this->create_test_attachment( false );

        $media = new WP_MCP_Connect_Media( 'wp-mcp-connect', '1.0.0' );
        $media->invalidate_missing_alt_cache();

        $request = new WP_REST_Request( 'GET', '/mcp/v1/media/missing-alt' );
        $response = $this->server->dispatch( $request );

        $data = $response->get_data();
        $ids = array_column( $data['results'], 'id' );

        $this->assertNotContains( $pdf_attachment, $ids );
        $this->assertContains( $image_attachment, $ids );
    }
}
