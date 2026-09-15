<?php
/**
 * PHPUnit bootstrap file for WP MCP Connect.
 *
 * Loads the plugin as a must-use plugin inside the WordPress test suite and
 * then runs the activation routine, so tests see the same database tables and
 * custom roles/capabilities a real install gets.
 *
 * Requires WP_TESTS_DIR to point at a checkout of the WordPress test library
 * (see bin/test-local.sh).
 *
 * @package WP_MCP_Connect
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL;
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load the plugin under test.
 *
 * @return void
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-mcp-connect.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Run the plugin activator once, before any test case executes.
 *
 * Activation creates the custom tables (404 log, ops, topology, audit log) and
 * grants the administrator role the `manage_cwp_redirects` capability the
 * redirect CPT is registered against. Without it, every REST call against
 * /wp/v2/redirects returns 403 even for an administrator.
 *
 * This runs outside the per-test database transaction, so the schema and role
 * changes persist for the whole run.
 *
 * @return void
 */
function _cwp_activate_plugin_for_tests() {
	require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-activator.php';
	WP_MCP_Connect_Activator::activate();

	// Roles are cached on WP_Roles at load; re-read so users created by test
	// factories pick up the capability granted above.
	wp_roles()->for_site();
}
tests_add_filter( 'init', '_cwp_activate_plugin_for_tests', 0 );

require "{$_tests_dir}/includes/bootstrap.php";
