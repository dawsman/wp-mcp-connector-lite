<?php
defined( 'ABSPATH' ) || exit;
/**
 * The core plugin class file for WP MCP Connect.
 *
 * @package    WP_MCP_Connect
 * @since      1.0.0
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, database migrations,
 * and public-facing site hooks.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect {

	/**
	 * The loader that's responsible for maintaining and registering all hooks.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Loader    $loader    Maintains and registers all hooks.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * SEO handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_SEO    $seo    SEO functionality handler.
	 */
	protected $seo;

	/**
	 * Redirects handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Redirects    $redirects    Redirects functionality handler.
	 */
	protected $redirects;

	/**
	 * API handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_API    $api    API functionality handler.
	 */
	protected $api;

	/**
	 * Media handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Media    $media    Media functionality handler.
	 */
	protected $media;

	/**
	 * Redirects IO handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Redirects_IO    $redirects_io    Redirects import/export handler.
	 */
	protected $redirects_io;

	/**
	 * Content audit handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Content_Audit    $content_audit    Content audit handler.
	 */
	protected $content_audit;

	/**
	 * SEO bulk operations handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_SEO_Bulk    $seo_bulk    SEO bulk operations handler.
	 */
	protected $seo_bulk;

	/**
	 * Content creation handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Content    $content    Content creation handler.
	 */
	protected $content;

	/**
	 * Settings handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Settings    $settings    Plugin settings handler.
	 */
	protected $settings;

	/**
	 * Extended media handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Media_Extended    $media_extended    Extended media functionality.
	 */
	protected $media_extended;

	/**
	 * Links handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Links    $links    Content relationship handler.
	 */
	protected $links;

	/**
	 * Users handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Users    $users    User and comment handler.
	 */
	protected $users;

	/**
	 * Analytics handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Analytics    $analytics    Analytics integration handler.
	 */
	protected $analytics;

	/**
	 * Menus handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Menus    $menus    Menu management handler.
	 */
	protected $menus;

	/**
	 * Customizer handler instance.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Customizer    $customizer    Customizer CSS handler.
	 */
	protected $customizer;

	/**
	 * Task queue handler.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Tasks    $tasks    Task queue handler.
	 */
	protected $tasks;

	/**
	 * 404 log handler.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_404_Log    $log_404    404 logger.
	 */
	protected $log_404;

	/**
	 * Ops log handler.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Ops    $ops    Ops logger.
	 */
	protected $ops;

	/**
	 * Reports handler.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Reports    $reports    Reports handler.
	 */
	protected $reports;

	/**
	 * Topology handler.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_MCP_Connect_Topology    $topology    Site topology handler.
	 */
	protected $topology;

	/**
	 * Audit log handler.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      WP_MCP_Connect_Audit_Log    $audit_log    Security audit log handler.
	 */
	private $audit_log;

	/**
	 * Webhooks handler.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      WP_MCP_Connect_Webhooks    $webhooks    Webhook event handler.
	 */
	private $webhooks;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->plugin_name = 'wp-mcp-connect';
		$this->version     = WP_MCP_CONNECT_VERSION;

		$this->load_dependencies();
		$this->instantiate_components();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_api_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   void
	 */
	private function load_dependencies() {
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-loader.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-crypto.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-auth.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-meta-suggest.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-seo-plugins.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-seo.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-seo-bulk.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-redirects.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-redirects-io.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-api.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-media.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-content-audit.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-content.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-settings.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-media-extended.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-links.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-users.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-analytics.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-menus.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-customizer.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-tasks.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-404-log.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-ops.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-reports.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-health-score.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-topology.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-link-suggest.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-audit-log.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-clusters.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-webhooks.php';
		require_once WP_MCP_CONNECT_PATH . 'includes/class-wp-mcp-connect-admin-lite.php';
	}

	/**
	 * Instantiate all component classes once.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   void
	 */
	private function instantiate_components() {
		$this->loader         = new WP_MCP_Connect_Loader();
		$this->seo            = new WP_MCP_Connect_SEO( $this->get_plugin_name(), $this->get_version() );
		$this->seo_bulk       = new WP_MCP_Connect_SEO_Bulk( $this->get_plugin_name(), $this->get_version() );
		$this->redirects      = new WP_MCP_Connect_Redirects( $this->get_plugin_name(), $this->get_version() );
		$this->redirects_io   = new WP_MCP_Connect_Redirects_IO( $this->get_plugin_name(), $this->get_version() );
		$this->api            = new WP_MCP_Connect_API( $this->get_plugin_name(), $this->get_version() );
		$this->media          = new WP_MCP_Connect_Media( $this->get_plugin_name(), $this->get_version() );
		$this->content_audit  = new WP_MCP_Connect_Content_Audit( $this->get_plugin_name(), $this->get_version() );
		$this->content        = new WP_MCP_Connect_Content( $this->get_plugin_name(), $this->get_version() );
		$this->settings       = new WP_MCP_Connect_Settings( $this->get_plugin_name(), $this->get_version() );
		$this->media_extended = new WP_MCP_Connect_Media_Extended( $this->get_plugin_name(), $this->get_version() );
		$this->links          = new WP_MCP_Connect_Links( $this->get_plugin_name(), $this->get_version() );
		$this->users          = new WP_MCP_Connect_Users( $this->get_plugin_name(), $this->get_version() );
		$this->analytics      = new WP_MCP_Connect_Analytics( $this->get_plugin_name(), $this->get_version() );
		$this->menus          = new WP_MCP_Connect_Menus( $this->get_plugin_name(), $this->get_version() );
		$this->customizer     = new WP_MCP_Connect_Customizer( $this->get_plugin_name(), $this->get_version() );
		$this->tasks          = new WP_MCP_Connect_Tasks( $this->get_plugin_name(), $this->get_version() );
		$this->log_404        = new WP_MCP_Connect_404_Log( $this->get_plugin_name(), $this->get_version() );
		$this->ops            = new WP_MCP_Connect_Ops();
		$this->reports        = new WP_MCP_Connect_Reports();
		$this->topology       = new WP_MCP_Connect_Topology( $this->get_plugin_name(), $this->get_version() );
		$this->audit_log      = new WP_MCP_Connect_Audit_Log( $this->get_plugin_name(), $this->get_version() );
		$this->webhooks       = new WP_MCP_Connect_Webhooks( $this->get_plugin_name(), $this->get_version() );
	}

	/**
	 * Register all admin-related hooks.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   void
	 */
	private function define_admin_hooks() {
		$installed = get_option( 'cwp_plugin_version', '0' );
		if ( version_compare( $installed, WP_MCP_CONNECT_VERSION, '<' ) ) {
			$this->log_404->maybe_create_table();
			$this->ops->maybe_create_table();
			$this->topology->maybe_create_table();
			$this->audit_log->maybe_create_table();
			update_option( 'cwp_plugin_version', WP_MCP_CONNECT_VERSION );
		}

		$admin_lite = new WP_MCP_Connect_Admin_Lite();
		$this->loader->add_action( 'admin_menu',                    $admin_lite, 'register_admin_menu' );
	}

	/**
	 * Register all public-facing hooks.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   void
	 */
	private function define_public_hooks() {
		$this->loader->add_action( 'wp_head', $this->seo, 'output_meta_tags' );
		$this->loader->add_filter( 'pre_get_document_title', $this->seo, 'filter_document_title', 99 );

		$this->loader->add_action( 'init', $this->redirects, 'register_redirect_cpt' );
		$this->loader->add_action( 'template_redirect', $this->redirects, 'perform_redirect', 99 );
		$this->loader->add_action( 'save_post_cwp_redirect', $this->redirects, 'schedule_cache_rebuild' );
		$this->loader->add_action( 'before_delete_post', $this->redirects, 'maybe_schedule_cache_rebuild' );
		$this->loader->add_action( 'trashed_post', $this->redirects, 'maybe_schedule_cache_rebuild' );
		$this->loader->add_action( 'untrashed_post', $this->redirects, 'maybe_schedule_cache_rebuild' );
		$this->loader->add_action( 'save_post_cwp_redirect', $this->audit_log, 'log_redirect_change' );
		$this->loader->add_action( 'cwp_rebuild_redirect_cache', $this->redirects, 'do_cache_rebuild' );
		$this->loader->add_filter( 'cron_schedules', $this->reports, 'register_schedules' );
		$this->loader->add_action( 'save_post', $this->topology, 'on_post_save' );
		$this->loader->add_action( 'cwp_topology_rebuild_batch', $this->topology, 'rebuild_all', 10, 2 );
	}

	/**
	 * Register all REST API hooks.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   void
	 */
	private function define_api_hooks() {
		$this->loader->add_action( 'rest_api_init', $this->seo, 'register_api_fields' );
		$this->loader->add_action( 'rest_api_init', $this->seo_bulk, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->redirects, 'register_api_fields' );
		$this->loader->add_action( 'rest_api_init', $this->redirects_io, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->api, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->media, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->content_audit, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->content, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->settings, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->media_extended, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->links, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->users, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->analytics, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->menus, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->customizer, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->tasks, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->log_404, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->ops, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->reports, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->topology, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->audit_log, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this->webhooks, 'register_routes' );
		$this->loader->add_filter( 'rest_request_after_callbacks', $this->api, 'track_api_access', 10, 3 );

		$this->loader->add_action( 'add_attachment', $this->media, 'invalidate_missing_alt_cache' );
		$this->loader->add_action( 'edit_attachment', $this->media, 'invalidate_missing_alt_cache' );
		$this->loader->add_action( 'delete_attachment', $this->media, 'invalidate_missing_alt_cache' );
		$this->loader->add_action( 'add_attachment', $this->media_extended, 'index_attachment' );
		$this->loader->add_action( 'edit_attachment', $this->media_extended, 'index_attachment' );
		$this->loader->add_filter( 'wp_update_attachment_metadata', $this->media_extended, 'index_attachment_metadata', 10, 2 );
		$this->loader->add_action( 'init', $this->media_extended, 'maybe_schedule_index' );
		$this->loader->add_action( 'cwp_media_index_event', $this->media_extended, 'handle_index_cron' );
		$this->loader->add_action( 'template_redirect', $this->log_404, 'maybe_log_404' );
		$this->loader->add_action( 'init', $this->tasks, 'register_cpt' );
		$this->loader->add_action( 'cwp_task_refresh_event', $this->tasks, 'handle_refresh_cron' );
		$this->loader->add_action( 'cwp_weekly_report_event', $this->reports, 'handle_weekly_cron' );
		$this->loader->add_action( 'cwp_404_cleanup_event', $this->log_404, 'handle_cleanup_cron' );
		$this->loader->add_action( 'init', $this->ops, 'maybe_schedule_prune' );
		$this->loader->add_action( 'cwp_ops_prune_event', $this->ops, 'handle_prune_cron' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin.
	 *
	 * @since    1.0.0
	 * @return   string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The version number of the plugin.
	 *
	 * @since    1.0.0
	 * @return   string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * The reference to the class that orchestrates the hooks.
	 *
	 * @since    1.0.0
	 * @return   WP_MCP_Connect_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}
}
