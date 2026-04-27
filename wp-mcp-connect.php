<?php
/**
 * Plugin Name:       WP MCP Connect Lite
 * Plugin URI:        https://ftw.digital
 * Description:       Headless REST API plugin for MCP clients — SEO management, content editing, redirections, and more. Lite version (no admin dashboard).
 * Version:           1.0.4
 * Author:            ftw.digital
 * Author URI:        https://ftw.digital
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wp-mcp-connect
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Requires PHP:      7.4
 *
 * @package    WP_MCP_Connect
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'WP_MCP_CONNECT_VERSION', '1.0.4' );
define( 'WP_MCP_CONNECT_MIN_WP_VERSION', '5.0' );
define( 'WP_MCP_CONNECT_MIN_PHP_VERSION', '7.4' );
define( 'WP_MCP_CONNECT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_MCP_CONNECT_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_MCP_CONNECT_BASENAME', plugin_basename( __FILE__ ) );
define( 'WP_MCP_CONNECT_UPDATE_URL', 'https://raw.githubusercontent.com/dawsman/wp-mcp-connector-lite/main/update-info.json' );

/**
 * Check PHP version compatibility.
 *
 * @since    1.0.0
 * @return   bool    True if compatible, false otherwise.
 */
function wp_mcp_connect_check_php_version() {
	return version_compare( PHP_VERSION, WP_MCP_CONNECT_MIN_PHP_VERSION, '>=' );
}

/**
 * Check WordPress version compatibility.
 *
 * @since    1.0.0
 * @return   bool    True if compatible, false otherwise.
 */
function wp_mcp_connect_check_wp_version() {
	return version_compare( get_bloginfo( 'version' ), WP_MCP_CONNECT_MIN_WP_VERSION, '>=' );
}

/**
 * Display admin notice for version incompatibility.
 *
 * @since    1.0.0
 * @return   void
 */
function wp_mcp_connect_version_notice() {
	$message = '';
	
	if ( ! wp_mcp_connect_check_php_version() ) {
		$message = sprintf(
			/* translators: 1: Required PHP version, 2: Current PHP version */
			__( 'WP MCP Connect requires PHP %1$s or higher. You are running PHP %2$s.', 'wp-mcp-connect' ),
			WP_MCP_CONNECT_MIN_PHP_VERSION,
			PHP_VERSION
		);
	} elseif ( ! wp_mcp_connect_check_wp_version() ) {
		$message = sprintf(
			/* translators: 1: Required WordPress version, 2: Current WordPress version */
			__( 'WP MCP Connect requires WordPress %1$s or higher. You are running WordPress %2$s.', 'wp-mcp-connect' ),
			WP_MCP_CONNECT_MIN_WP_VERSION,
			get_bloginfo( 'version' )
		);
	}
	
	if ( ! empty( $message ) ) {
		echo '<div class="error"><p>' . esc_html( $message ) . '</p></div>';
	}
}

if ( ! wp_mcp_connect_check_php_version() || ! wp_mcp_connect_check_wp_version() ) {
	add_action( 'admin_notices', 'wp_mcp_connect_version_notice' );
	return;
}

/**
 * Load plugin text domain for translations.
 *
 * @since    1.0.0
 * @return   void
 */
function wp_mcp_connect_load_textdomain() {
	load_plugin_textdomain(
		'wp-mcp-connect',
		false,
		dirname( WP_MCP_CONNECT_BASENAME ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'wp_mcp_connect_load_textdomain' );

/**
 * The code that runs during plugin activation.
 *
 * @since    1.0.0
 * @return   void
 */
function activate_wp_mcp_connect() {
	require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-activator.php';
	WP_MCP_Connect_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 *
 * @since    1.0.0
 * @return   void
 */
function deactivate_wp_mcp_connect() {
	require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-deactivator.php';
	WP_MCP_Connect_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_wp_mcp_connect' );
register_deactivation_hook( __FILE__, 'deactivate_wp_mcp_connect' );

require WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect.php';

/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 * @return   void
 */
function run_wp_mcp_connect() {
	$plugin = new WP_MCP_Connect();
	$plugin->run();
}
run_wp_mcp_connect();

/**
 * Initialize the self-hosted updater (admin only).
 *
 * @since 1.1.0
 */
if ( is_admin() ) {
	require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-updater.php';
	new WP_MCP_Connect_Updater(
		WP_MCP_CONNECT_BASENAME,
		WP_MCP_CONNECT_VERSION,
		'wp-mcp-connect',
		WP_MCP_CONNECT_UPDATE_URL
	);
}
