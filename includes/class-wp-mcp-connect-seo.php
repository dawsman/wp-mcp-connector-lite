<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles SEO functionality for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_SEO {

	/**
	 * The plugin name.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The plugin version.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string    $plugin_name    The name of the plugin.
	 * @param    string    $version        The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Get supported post types for SEO fields.
	 *
	 * Returns all public post types that are visible in REST API.
	 * Can be filtered via 'cwp_seo_post_types' hook.
	 *
	 * @since    1.0.0
	 * @return   array    Array of post type names.
	 */
	public function get_supported_post_types() {
		$post_types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'names'
		);

		$post_types = array_values( $post_types );

		return apply_filters( 'cwp_seo_post_types', $post_types );
	}

	/**
	 * Register REST API fields for SEO meta.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_api_fields() {
		$types = $this->get_supported_post_types();

		foreach ( $types as $type ) {
			register_rest_field( $type, 'cwp_seo_title', array(
				'get_callback'    => array( $this, 'get_meta_callback' ),
				'update_callback' => array( $this, 'update_meta_callback' ),
				'schema'          => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) ),
			) );

			register_rest_field( $type, 'cwp_seo_description', array(
				'get_callback'    => array( $this, 'get_meta_callback' ),
				'update_callback' => array( $this, 'update_meta_callback' ),
				'schema'          => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) ),
			) );

			register_rest_field( $type, 'cwp_og_title', array(
				'get_callback'    => array( $this, 'get_meta_callback' ),
				'update_callback' => array( $this, 'update_meta_callback' ),
				'schema'          => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) ),
			) );

			register_rest_field( $type, 'cwp_og_description', array(
				'get_callback'    => array( $this, 'get_meta_callback' ),
				'update_callback' => array( $this, 'update_meta_callback' ),
				'schema'          => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) ),
			) );

			register_rest_field( $type, 'cwp_og_image_id', array(
				'get_callback'    => array( $this, 'get_meta_callback' ),
				'update_callback' => array( $this, 'update_integer_callback' ),
				'schema'          => array( 'type' => 'integer', 'context' => array( 'view', 'edit' ) ),
			) );

			register_rest_field( $type, 'cwp_schema_json', array(
				'get_callback'    => array( $this, 'get_meta_callback' ),
				'update_callback' => array( $this, 'update_schema_callback' ),
				'schema'          => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) ),
			) );

			register_rest_field( $type, 'cwp_focus_keyword', array(
				'get_callback'    => array( $this, 'get_meta_callback' ),
				'update_callback' => array( $this, 'update_meta_callback' ),
				'schema'          => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) ),
			) );

			register_rest_field( $type, 'cwp_cornerstone_content', array(
				'get_callback'    => array( $this, 'get_boolean_callback' ),
				'update_callback' => array( $this, 'update_boolean_callback' ),
				'schema'          => array( 'type' => 'boolean', 'context' => array( 'view', 'edit' ) ),
			) );
		}
	}

	/**
	 * Convert REST field name to SEO field name.
	 *
	 * Strips the 'cwp_' prefix from field names.
	 *
	 * @since    1.0.0
	 * @param    string    $field_name    The REST field name (e.g., 'cwp_seo_title').
	 * @return   string                   The SEO field name (e.g., 'seo_title').
	 */
	private function get_seo_field_name( $field_name ) {
		return preg_replace( '/^cwp_/', '', $field_name );
	}

	/**
	 * Get meta field callback for REST API.
	 *
	 * Reads from the active SEO plugin's meta fields.
	 *
	 * @since    1.0.0
	 * @param    array     $object       The post object array.
	 * @param    string    $field_name   The field name.
	 * @param    object    $request      The REST request object.
	 * @return   mixed                   The meta value.
	 */
	public function get_meta_callback( $object, $field_name, $request ) {
		$seo_field = $this->get_seo_field_name( $field_name );
		return WP_MCP_Connect_SEO_Plugins::get_seo_value( $object['id'], $seo_field );
	}

	/**
	 * Update text meta field callback for REST API.
	 *
	 * Writes to the active SEO plugin's meta fields.
	 *
	 * @since    1.0.0
	 * @param    mixed     $value        The value to save.
	 * @param    object    $object       The post object.
	 * @param    string    $field_name   The field name.
	 * @return   bool|WP_Error           True on success, false on failure, WP_Error when the
	 *                                   active SEO plugin has no storage for the field.
	 */
	public function update_meta_callback( $value, $object, $field_name ) {
		$seo_field = $this->get_seo_field_name( $field_name );
		return WP_MCP_Connect_SEO_Plugins::set_seo_value( $object->ID, $seo_field, sanitize_text_field( $value ) );
	}

	/**
	 * Update integer meta field callback for REST API.
	 *
	 * Writes to the active SEO plugin's meta fields.
	 *
	 * @since    1.0.0
	 * @param    mixed     $value        The value to save.
	 * @param    object    $object       The post object.
	 * @param    string    $field_name   The field name.
	 * @return   bool|WP_Error           True on success, false on failure, WP_Error when the
	 *                                   active SEO plugin has no storage for the field.
	 */
	public function update_integer_callback( $value, $object, $field_name ) {
		$seo_field = $this->get_seo_field_name( $field_name );
		return WP_MCP_Connect_SEO_Plugins::set_seo_value( $object->ID, $seo_field, absint( $value ) );
	}

	/**
	 * Get boolean meta field callback for REST API.
	 *
	 * Reads from the active SEO plugin's meta fields and converts to boolean.
	 *
	 * @since    1.0.0
	 * @param    array     $object       The post object array.
	 * @param    string    $field_name   The field name.
	 * @param    object    $request      The REST request object.
	 * @return   bool                    The boolean value.
	 */
	public function get_boolean_callback( $object, $field_name, $request ) {
		$seo_field = $this->get_seo_field_name( $field_name );
		$value = WP_MCP_Connect_SEO_Plugins::get_seo_value( $object['id'], $seo_field );
		return (bool) $value;
	}

	/**
	 * Update boolean meta field callback for REST API.
	 *
	 * Writes to the active SEO plugin's meta fields.
	 *
	 * @since    1.0.0
	 * @param    mixed     $value        The value to save.
	 * @param    object    $object       The post object.
	 * @param    string    $field_name   The field name.
	 * @return   bool|WP_Error           True on success, false on failure, WP_Error when the
	 *                                   active SEO plugin has no storage for the field.
	 */
	public function update_boolean_callback( $value, $object, $field_name ) {
		$seo_field = $this->get_seo_field_name( $field_name );
		return WP_MCP_Connect_SEO_Plugins::set_seo_value( $object->ID, $seo_field, $value ? '1' : '' );
	}

	/**
	 * Update schema JSON field callback for REST API.
	 * Normalizes JSON by decode/encode cycle to strip potentially dangerous content.
	 *
	 * Writes to the active SEO plugin's meta fields.
	 *
	 * @since    1.0.0
	 * @param    mixed     $value        The JSON value to save.
	 * @param    object    $object       The post object.
	 * @param    string    $field_name   The field name.
	 * @return   bool|WP_Error           True on success, WP_Error on invalid JSON or when the
	 *                                   active SEO plugin has no storage for the field.
	 */
	public function update_schema_callback( $value, $object, $field_name ) {
		$seo_field = $this->get_seo_field_name( $field_name );

		if ( empty( $value ) ) {
			return WP_MCP_Connect_SEO_Plugins::set_seo_value( $object->ID, $seo_field, '' );
		}

		$decoded = json_decode( $value, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'invalid_json',
				__( 'Invalid JSON provided for schema', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		$normalized = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $normalized ) {
			return new WP_Error(
				'json_encode_failed',
				__( 'Failed to normalize JSON schema', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		return WP_MCP_Connect_SEO_Plugins::set_seo_value( $object->ID, $seo_field, $normalized );
	}

	/**
	 * Output meta tags in wp_head.
	 *
	 * Title, description and Open Graph tags are only emitted when no third-party SEO
	 * plugin is active, so they never duplicate Rank Math / Yoast / AIOSEO output. The
	 * JSON-LD block is always emitted, because _cwp_schema_json is where this plugin
	 * stores schema for every SEO plugin (none of them hold a raw JSON-LD document) and
	 * nothing else would print it.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function output_meta_tags() {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$seo_plugin = WP_MCP_Connect_SEO_Plugins::get_plugin_info();

		if ( 'cwp' === $seo_plugin['slug'] ) {
			$this->output_basic_meta_tags( $post );
		}

		$this->output_schema_json( $post );
	}

	/**
	 * Output the description and Open Graph tags from the built-in cwp fields.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    WP_Post    $post    The current post.
	 * @return   void
	 */
	private function output_basic_meta_tags( $post ) {
		$desc = get_post_meta( $post->ID, '_cwp_seo_description', true );
		if ( ! empty( $desc ) ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}

		$og_title = get_post_meta( $post->ID, '_cwp_og_title', true );
		if ( empty( $og_title ) ) {
			$og_title = get_post_meta( $post->ID, '_cwp_seo_title', true );
		}
		if ( empty( $og_title ) ) {
			$og_title = get_the_title( $post->ID );
		}

		$og_desc = get_post_meta( $post->ID, '_cwp_og_description', true );
		if ( empty( $og_desc ) ) {
			$og_desc = $desc;
		}

		$og_image_id = get_post_meta( $post->ID, '_cwp_og_image_id', true );
		$og_image_url = '';
		if ( ! empty( $og_image_id ) ) {
			$img = wp_get_attachment_image_src( absint( $og_image_id ), 'large' );
			if ( $img ) {
				$og_image_url = $img[0];
			}
		} elseif ( has_post_thumbnail( $post->ID ) ) {
			$og_image_url = get_the_post_thumbnail_url( $post->ID, 'large' );
		}

		echo '<meta property="og:type" content="article" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '" />' . "\n";
		if ( ! empty( $og_desc ) ) {
			echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '" />' . "\n";
		}
		echo '<meta property="og:url" content="' . esc_url( get_permalink( $post->ID ) ) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";
		if ( ! empty( $og_image_url ) ) {
			echo '<meta property="og:image" content="' . esc_url( $og_image_url ) . '" />' . "\n";
		}
	}

	/**
	 * Output the JSON-LD block stored in _cwp_schema_json.
	 *
	 * Runs whichever SEO plugin is active - see output_meta_tags().
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    WP_Post    $post    The current post.
	 * @return   void
	 */
	private function output_schema_json( $post ) {
		$schema_json = get_post_meta( $post->ID, '_cwp_schema_json', true );

		if ( empty( $schema_json ) ) {
			return;
		}

		if ( is_array( $schema_json ) ) {
			$decoded = $schema_json;
		} else {
			$decoded = json_decode( $schema_json );
		}

		if ( ! $decoded ) {
			return;
		}

		echo '<script type="application/ld+json">' . "\n";
		echo wp_json_encode( $decoded, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES ) . "\n";
		echo '</script>' . "\n";
	}

	/**
	 * Filter the document title.
	 *
	 * Only filters if using built-in cwp fields (no third-party SEO plugin).
	 * This prevents conflicts when Rank Math, Yoast, or AIOSEO is active.
	 *
	 * @since    1.0.0
	 * @param    string    $title    The current document title.
	 * @return   string              The filtered document title.
	 */
	public function filter_document_title( $title ) {
		// Only filter title if no third-party SEO plugin is active
		$seo_plugin = WP_MCP_Connect_SEO_Plugins::get_plugin_info();
		if ( $seo_plugin['slug'] !== 'cwp' ) {
			return $title;
		}

		if ( ! is_singular() ) {
			return $title;
		}

		global $post;
		if ( ! $post instanceof WP_Post ) {
			return $title;
		}

		$custom_title = get_post_meta( $post->ID, '_cwp_seo_title', true );

		if ( ! empty( $custom_title ) ) {
			return $custom_title;
		}

		return $title;
	}
}
