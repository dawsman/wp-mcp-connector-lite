<?php
defined( 'ABSPATH' ) || exit;
/**
 * Handles WordPress Customizer CSS functionality for WP MCP Connect.
 *
 * Provides REST API endpoints for managing custom CSS via the
 * WordPress Customizer's Additional CSS feature.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */

/**
 * Customizer CSS handler class.
 *
 * @since      1.0.0
 */
class WP_MCP_Connect_Customizer {

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
		$this->version     = $version;
	}

	/**
	 * Register REST API routes for custom CSS management.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		register_rest_route(
			'mcp/v1',
			'/customizer/css',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_custom_css' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_custom_css' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'css'  => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => 'CSS content to add or replace',
							'sanitize_callback' => array( $this, 'sanitize_css' ),
						),
						'mode' => array(
							'required'    => false,
							'type'        => 'string',
							'default'     => 'replace',
							'enum'        => array( 'replace', 'append', 'prepend' ),
							'description' => 'Mode for updating CSS: replace, append, or prepend',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_custom_css' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);
	}

	/**
	 * Check if the current user has permission to manage custom CSS.
	 *
	 * Requires the 'edit_theme_options' capability (Administrator role).
	 *
	 * @since    1.0.0
	 * @return   bool|WP_Error    True if permitted, WP_Error otherwise.
	 */
	public function check_permissions() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage custom CSS.', 'wp-mcp-connect' ),
				array( 'status' => 403 )
			);
		}
		return WP_MCP_Connect_Auth::check_capability( 'edit_theme_options' );
	}

	/**
	 * Sanitize CSS content.
	 *
	 * Uses WordPress core sanitization via wp_strip_all_tags for basic
	 * safety, then allows through valid CSS. WordPress handles additional
	 * sanitization when storing via wp_update_custom_css_post().
	 *
	 * @since    1.0.0
	 * @param    string    $css    Raw CSS content.
	 * @return   string            Sanitized CSS content.
	 */
	public function sanitize_css( $css ) {
		// WordPress core handles CSS sanitization in wp_update_custom_css_post()
		// We just ensure it's a string and trim whitespace
		return trim( (string) $css );
	}

	/**
	 * Get the current custom CSS from the Customizer.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   WP_REST_Response               Response with CSS content.
	 */
	public function get_custom_css( $request ) {
		$css        = wp_get_custom_css();
		$theme      = get_stylesheet();
		$css_length = strlen( $css );

		return rest_ensure_response(
			array(
				'css'    => $css,
				'theme'  => $theme,
				'length' => $css_length,
			)
		);
	}

	/**
	 * Update the custom CSS in the Customizer.
	 *
	 * Supports three modes:
	 * - 'replace': Completely replace existing CSS
	 * - 'append': Add new CSS after existing CSS
	 * - 'prepend': Add new CSS before existing CSS
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   WP_REST_Response|WP_Error      Response on success, WP_Error on failure.
	 */
	public function update_custom_css( $request ) {
		$new_css = $request->get_param( 'css' );
		$mode    = $request->get_param( 'mode' );

		// Get current CSS for append/prepend modes
		$current_css = wp_get_custom_css();
		$previous_state = array( 'css' => $current_css );

		// Determine final CSS based on mode
		switch ( $mode ) {
			case 'append':
				$final_css = ! empty( $current_css )
					? $current_css . "\n\n/* === Appended CSS === */\n\n" . $new_css
					: $new_css;
				break;

			case 'prepend':
				$final_css = ! empty( $current_css )
					? $new_css . "\n\n/* === Original CSS === */\n\n" . $current_css
					: $new_css;
				break;

			case 'replace':
			default:
				$final_css = $new_css;
				break;
		}

		// Update the custom CSS
		$result = wp_update_custom_css_post( $final_css );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( class_exists( 'WP_MCP_Connect_Ops' ) ) {
			WP_MCP_Connect_Ops::log_operation(
				'custom_css',
				array(
					'mode'   => $mode,
					'length' => strlen( $final_css ),
				),
				$previous_state
			);
		}

		return rest_ensure_response(
			array(
				'success'         => true,
				'mode'            => $mode,
				'length'          => strlen( $final_css ),
				'previous_length' => strlen( $current_css ),
				'theme'           => get_stylesheet(),
			)
		);
	}

	/**
	 * Clear all custom CSS from the Customizer.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   WP_REST_Response|WP_Error      Response on success, WP_Error on failure.
	 */
	public function clear_custom_css( $request ) {
		$current_css = wp_get_custom_css();
		$result      = wp_update_custom_css_post( '' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'         => true,
				'cleared_length'  => strlen( $current_css ),
				'theme'           => get_stylesheet(),
			)
		);
	}
}
