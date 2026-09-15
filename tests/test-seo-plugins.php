<?php
/**
 * Tests for the third-party SEO plugin compatibility layer.
 *
 * Covers the pure helpers used by the AIOSEO 4.x/5.x storage path: the keyphrases
 * JSON encode/decode round trip and the Open Graph image ID <-> URL conversion.
 *
 * @package WP_MCP_Connect
 */

class WP_MCP_Connect_SEO_Plugins_Test extends WP_UnitTestCase {

    /**
     * A realistic keyphrases payload, shaped as AIOSEO's getKeyphrasesDefaults() writes it.
     *
     * @return array
     */
    private function sample_keyphrases() {
        return array(
            'focus'      => array(
                'keyphrase' => 'chartered accountant',
                'score'     => 72,
                'analysis'  => array(
                    'keyphraseInTitle' => array(
                        'score'    => 9,
                        'maxScore' => 9,
                        'error'    => 0,
                    ),
                ),
            ),
            'additional' => array(
                array(
                    'keyphrase' => 'small business accountant',
                    'score'     => 40,
                ),
            ),
        );
    }

    /* ------------------------------------------------------------------ */
    /* decode_aioseo_keyphrases                                            */
    /* ------------------------------------------------------------------ */

    public function test_decode_keyphrases_from_json_string() {
        $json    = wp_json_encode( $this->sample_keyphrases() );
        $decoded = WP_MCP_Connect_SEO_Plugins::decode_aioseo_keyphrases( $json );

        $this->assertIsArray( $decoded );
        $this->assertEquals( 'chartered accountant', $decoded['focus']['keyphrase'] );
        $this->assertCount( 1, $decoded['additional'] );
    }

    public function test_decode_keyphrases_from_object() {
        $object  = json_decode( wp_json_encode( $this->sample_keyphrases() ) );
        $decoded = WP_MCP_Connect_SEO_Plugins::decode_aioseo_keyphrases( $object );

        $this->assertIsArray( $decoded );
        $this->assertEquals( 'chartered accountant', $decoded['focus']['keyphrase'] );
    }

    public function test_decode_keyphrases_handles_empty_and_invalid_input() {
        $this->assertSame( array(), WP_MCP_Connect_SEO_Plugins::decode_aioseo_keyphrases( null ) );
        $this->assertSame( array(), WP_MCP_Connect_SEO_Plugins::decode_aioseo_keyphrases( '' ) );
        $this->assertSame( array(), WP_MCP_Connect_SEO_Plugins::decode_aioseo_keyphrases( 'not json' ) );
        $this->assertSame( array(), WP_MCP_Connect_SEO_Plugins::decode_aioseo_keyphrases( '"a string"' ) );
    }

    /* ------------------------------------------------------------------ */
    /* decode_aioseo_focus_keyphrase                                       */
    /* ------------------------------------------------------------------ */

    public function test_decode_focus_keyphrase() {
        $json = wp_json_encode( $this->sample_keyphrases() );

        $this->assertEquals(
            'chartered accountant',
            WP_MCP_Connect_SEO_Plugins::decode_aioseo_focus_keyphrase( $json )
        );
    }

    public function test_decode_focus_keyphrase_returns_empty_string_when_absent() {
        $this->assertSame( '', WP_MCP_Connect_SEO_Plugins::decode_aioseo_focus_keyphrase( null ) );
        $this->assertSame( '', WP_MCP_Connect_SEO_Plugins::decode_aioseo_focus_keyphrase( '{"additional":[]}' ) );
        $this->assertSame( '', WP_MCP_Connect_SEO_Plugins::decode_aioseo_focus_keyphrase( '{"focus":{}}' ) );
    }

    /* ------------------------------------------------------------------ */
    /* encode_aioseo_keyphrases                                            */
    /* ------------------------------------------------------------------ */

    public function test_encode_keyphrases_preserves_additional_keyphrases() {
        $existing = wp_json_encode( $this->sample_keyphrases() );

        $encoded = WP_MCP_Connect_SEO_Plugins::encode_aioseo_keyphrases( $existing, 'tax return help' );

        $this->assertEquals( 'tax return help', $encoded['focus']['keyphrase'] );
        $this->assertCount( 1, $encoded['additional'] );
        $this->assertEquals( 'small business accountant', $encoded['additional'][0]['keyphrase'] );
    }

    public function test_encode_keyphrases_preserves_focus_analysis() {
        $existing = wp_json_encode( $this->sample_keyphrases() );

        $encoded = WP_MCP_Connect_SEO_Plugins::encode_aioseo_keyphrases( $existing, 'tax return help' );

        $this->assertArrayHasKey( 'analysis', $encoded['focus'] );
        $this->assertEquals( 9, $encoded['focus']['analysis']['keyphraseInTitle']['maxScore'] );
        $this->assertEquals( 72, $encoded['focus']['score'] );
    }

    public function test_encode_keyphrases_builds_defaults_from_nothing() {
        $encoded = WP_MCP_Connect_SEO_Plugins::encode_aioseo_keyphrases( null, 'bookkeeping' );

        $this->assertEquals( 'bookkeeping', $encoded['focus']['keyphrase'] );
        $this->assertSame( 0, $encoded['focus']['score'] );
        $this->assertSame( array(), $encoded['additional'] );
    }

    public function test_encode_keyphrases_clears_focus_keyphrase() {
        $existing = wp_json_encode( $this->sample_keyphrases() );

        $encoded = WP_MCP_Connect_SEO_Plugins::encode_aioseo_keyphrases( $existing, '' );

        $this->assertSame( '', $encoded['focus']['keyphrase'] );
        $this->assertCount( 1, $encoded['additional'] );
    }

    public function test_encode_keyphrases_round_trips_through_json() {
        $encoded = WP_MCP_Connect_SEO_Plugins::encode_aioseo_keyphrases(
            wp_json_encode( $this->sample_keyphrases() ),
            'payroll services'
        );

        $round_tripped = WP_MCP_Connect_SEO_Plugins::decode_aioseo_focus_keyphrase( wp_json_encode( $encoded ) );

        $this->assertEquals( 'payroll services', $round_tripped );
    }

    public function test_encode_keyphrases_repairs_a_malformed_focus_node() {
        $encoded = WP_MCP_Connect_SEO_Plugins::encode_aioseo_keyphrases(
            '{"focus":"not an object","additional":"also wrong"}',
            'vat registration'
        );

        $this->assertEquals( 'vat registration', $encoded['focus']['keyphrase'] );
        $this->assertSame( array(), $encoded['additional'] );
    }

    /* ------------------------------------------------------------------ */
    /* Open Graph image ID <-> URL                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Create an attachment whose file actually exists, so attachment_url_to_postid() resolves it.
     *
     * @return int
     */
    private function create_attachment() {
        return $this->factory->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );
    }

    public function test_og_image_url_from_id() {
        $attachment_id = $this->create_attachment();

        $url = WP_MCP_Connect_SEO_Plugins::aioseo_og_image_url_from_id( $attachment_id );

        $this->assertNotEmpty( $url );
        $this->assertEquals( wp_get_attachment_url( $attachment_id ), $url );
    }

    public function test_og_image_url_from_id_returns_empty_string_for_no_id() {
        $this->assertSame( '', WP_MCP_Connect_SEO_Plugins::aioseo_og_image_url_from_id( 0 ) );
        $this->assertSame( '', WP_MCP_Connect_SEO_Plugins::aioseo_og_image_url_from_id( '' ) );
        $this->assertSame( '', WP_MCP_Connect_SEO_Plugins::aioseo_og_image_url_from_id( null ) );
    }

    public function test_og_image_id_from_url() {
        $attachment_id = $this->create_attachment();
        $url           = wp_get_attachment_url( $attachment_id );

        $this->assertSame(
            (int) $attachment_id,
            WP_MCP_Connect_SEO_Plugins::aioseo_og_image_id_from_url( $url )
        );
    }

    public function test_og_image_id_from_url_returns_empty_string_when_unresolvable() {
        $this->assertSame( '', WP_MCP_Connect_SEO_Plugins::aioseo_og_image_id_from_url( '' ) );
        $this->assertSame( '', WP_MCP_Connect_SEO_Plugins::aioseo_og_image_id_from_url( null ) );
        $this->assertSame( '', WP_MCP_Connect_SEO_Plugins::aioseo_og_image_id_from_url( 123 ) );
        $this->assertSame(
            '',
            WP_MCP_Connect_SEO_Plugins::aioseo_og_image_id_from_url( 'https://example.com/not-in-this-library.jpg' )
        );
    }

    public function test_og_image_id_url_round_trip() {
        $attachment_id = $this->create_attachment();

        $url      = WP_MCP_Connect_SEO_Plugins::aioseo_og_image_url_from_id( $attachment_id );
        $resolved = WP_MCP_Connect_SEO_Plugins::aioseo_og_image_id_from_url( $url );

        $this->assertSame( (int) $attachment_id, $resolved );
    }

    /* ------------------------------------------------------------------ */
    /* Field mapping honesty                                               */
    /* ------------------------------------------------------------------ */

    public function test_schema_json_maps_to_cwp_meta_key_when_no_plugin_is_active() {
        WP_MCP_Connect_SEO_Plugins::clear_cache();

        $this->assertEquals( '_cwp_schema_json', WP_MCP_Connect_SEO_Plugins::get_meta_key( 'schema_json' ) );
    }

    public function test_schema_json_reads_and_writes_the_cwp_meta_key() {
        WP_MCP_Connect_SEO_Plugins::clear_cache();

        $post_id = $this->factory->post->create();
        $schema  = '{"@context":"https://schema.org","@type":"Article","headline":"Hello"}';

        $this->assertTrue( WP_MCP_Connect_SEO_Plugins::set_seo_value( $post_id, 'schema_json', $schema ) );
        $this->assertEquals( $schema, get_post_meta( $post_id, '_cwp_schema_json', true ) );
        $this->assertEquals( $schema, WP_MCP_Connect_SEO_Plugins::get_seo_value( $post_id, 'schema_json' ) );
    }

    public function test_clearing_schema_json_is_idempotent() {
        WP_MCP_Connect_SEO_Plugins::clear_cache();

        $post_id = $this->factory->post->create();

        // Nothing stored yet - clearing still reports success rather than a failed write.
        $this->assertTrue( WP_MCP_Connect_SEO_Plugins::set_seo_value( $post_id, 'schema_json', '' ) );

        WP_MCP_Connect_SEO_Plugins::set_seo_value( $post_id, 'schema_json', '{"@type":"Article"}' );

        $this->assertTrue( WP_MCP_Connect_SEO_Plugins::set_seo_value( $post_id, 'schema_json', '' ) );
        $this->assertSame( '', get_post_meta( $post_id, '_cwp_schema_json', true ) );
    }
}
