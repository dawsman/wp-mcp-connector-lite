<?php
defined( 'ABSPATH' ) || exit;

/**
 * Menu management for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Menus {

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
	 * Register REST API routes.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		register_rest_route( 'mcp/v1', '/menus', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_menus' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_menu' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'args'                => array(
					'name' => array(
						'required' => true,
						'type'     => 'string',
					),
					'location' => array(
						'type' => 'string',
					),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/menus/locations', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'assign_menu_location' ),
			'permission_callback' => array( $this, 'check_edit_permission' ),
			'args'                => array(
				'menu_id' => array(
					'required' => true,
					'type'     => 'integer',
				),
				'location' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/menus/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_menu' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'id' => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/menus/(?P<id>\d+)/items', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_menu_items' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_menu_item' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'args'                => array(
					'title' => array(
						'required' => true,
						'type'     => 'string',
					),
					'url' => array(
						'type' => 'string',
					),
					'object_type' => array(
						'type' => 'string',
						'enum' => array( 'custom', 'post', 'page', 'category', 'tag' ),
					),
					'object_id' => array(
						'type' => 'integer',
					),
					'parent' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'position' => array(
						'type' => 'integer',
					),
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/menus/(?P<menu_id>\d+)/items/(?P<item_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_menu_item' ),
			'permission_callback' => array( $this, 'check_edit_permission' ),
			'args'                => array(
				'menu_id' => array(
					'type'     => 'integer',
					'required' => true,
				),
				'item_id' => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		) );
	}

	/**
	 * Check if user has permission.
	 *
	 * @since    1.0.0
	 * @return   bool
	 */
	public function check_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'edit_posts' );
	}

	/**
	 * Check if user has edit permission.
	 *
	 * @since    1.0.0
	 * @return   bool
	 */
	public function check_edit_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'edit_theme_options' );
	}

	/**
	 * List all registered menus.
	 *
	 * @since    1.0.0
	 * @return   array
	 */
	public function list_menus() {
		$menus = wp_get_nav_menus();
		$locations = get_nav_menu_locations();
		$registered_locations = get_registered_nav_menus();

		$results = array();

		foreach ( $menus as $menu ) {
			$location = array_search( $menu->term_id, $locations, true );

			$results[] = array(
				'id'           => $menu->term_id,
				'name'         => $menu->name,
				'slug'         => $menu->slug,
				'count'        => $menu->count,
				'location'     => $location ? $location : null,
				'location_name' => $location && isset( $registered_locations[ $location ] ) ? $registered_locations[ $location ] : null,
			);
		}

		return array(
			'menus'     => $results,
			'locations' => $registered_locations,
		);
	}

	/**
	 * Get a single menu.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error
	 */
	public function get_menu( $request ) {
		$menu_id = $request->get_param( 'id' );
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new WP_Error(
				'menu_not_found',
				__( 'Menu not found.', 'wp-mcp-connect' ),
				array( 'status' => 404 )
			);
		}

		$locations = get_nav_menu_locations();
		$location = array_search( $menu->term_id, $locations, true );

		return array(
			'id'       => $menu->term_id,
			'name'     => $menu->name,
			'slug'     => $menu->slug,
			'count'    => $menu->count,
			'location' => $location ? $location : null,
		);
	}

	/**
	 * Get menu items.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error
	 */
	public function get_menu_items( $request ) {
		$menu_id = $request->get_param( 'id' );
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new WP_Error(
				'menu_not_found',
				__( 'Menu not found.', 'wp-mcp-connect' ),
				array( 'status' => 404 )
			);
		}

		$items = wp_get_nav_menu_items( $menu_id );

		if ( ! $items ) {
			$items = array();
		}

		$results = array();

		foreach ( $items as $item ) {
			$results[] = array(
				'id'          => $item->ID,
				'title'       => $item->title,
				'url'         => $item->url,
				'object_type' => $item->type,
				'object_id'   => (int) $item->object_id,
				'parent'      => (int) $item->menu_item_parent,
				'position'    => (int) $item->menu_order,
				'target'      => $item->target,
				'classes'     => $item->classes,
			);
		}

		return array(
			'menu_id' => $menu_id,
			'items'   => $results,
		);
	}

	/**
	 * Add a menu item.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error
	 */
	public function add_menu_item( $request ) {
		$menu_id = $request->get_param( 'id' );
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new WP_Error(
				'menu_not_found',
				__( 'Menu not found.', 'wp-mcp-connect' ),
				array( 'status' => 404 )
			);
		}

		$title = sanitize_text_field( $request->get_param( 'title' ) );
		$url = $request->get_param( 'url' );
		$object_type = $request->get_param( 'object_type' ) ?: 'custom';
		$object_id = $request->get_param( 'object_id' );
		$parent = $request->get_param( 'parent' ) ?: 0;
		$position = $request->get_param( 'position' );

		$item_data = array(
			'menu-item-title'     => $title,
			'menu-item-parent-id' => $parent,
			'menu-item-status'    => 'publish',
		);

		if ( $position ) {
			$item_data['menu-item-position'] = $position;
		}

		switch ( $object_type ) {
			case 'post':
				if ( ! $object_id ) {
					return new WP_Error( 'missing_object_id', 'object_id required for posts', array( 'status' => 400 ) );
				}
				$post = get_post( $object_id );
				if ( ! $post ) {
					return new WP_Error( 'post_not_found', 'Post not found', array( 'status' => 404 ) );
				}
				$item_data['menu-item-type'] = 'post_type';
				$item_data['menu-item-object'] = $post->post_type;
				$item_data['menu-item-object-id'] = $object_id;
				break;

			case 'page':
				if ( ! $object_id ) {
					return new WP_Error( 'missing_object_id', 'object_id required for pages', array( 'status' => 400 ) );
				}
				$item_data['menu-item-type'] = 'post_type';
				$item_data['menu-item-object'] = 'page';
				$item_data['menu-item-object-id'] = $object_id;
				break;

			case 'category':
				if ( ! $object_id ) {
					return new WP_Error( 'missing_object_id', 'object_id required for categories', array( 'status' => 400 ) );
				}
				$item_data['menu-item-type'] = 'taxonomy';
				$item_data['menu-item-object'] = 'category';
				$item_data['menu-item-object-id'] = $object_id;
				break;

			case 'tag':
				if ( ! $object_id ) {
					return new WP_Error( 'missing_object_id', 'object_id required for tags', array( 'status' => 400 ) );
				}
				$item_data['menu-item-type'] = 'taxonomy';
				$item_data['menu-item-object'] = 'post_tag';
				$item_data['menu-item-object-id'] = $object_id;
				break;

			case 'custom':
			default:
				if ( empty( $url ) ) {
					return new WP_Error( 'missing_url', 'URL required for custom links', array( 'status' => 400 ) );
				}
				$item_data['menu-item-type'] = 'custom';
				$item_data['menu-item-url'] = esc_url_raw( $url );
				break;
		}

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $item_data );

		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		return array(
			'success' => true,
			'item_id' => $item_id,
			'menu_id' => $menu_id,
		);
	}

	/**
	 * Delete a menu item.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error
	 */
	public function delete_menu_item( $request ) {
		$menu_id = $request->get_param( 'menu_id' );
		$item_id = $request->get_param( 'item_id' );

		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new WP_Error(
				'menu_not_found',
				__( 'Menu not found.', 'wp-mcp-connect' ),
				array( 'status' => 404 )
			);
		}

		$item = get_post( $item_id );

		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return new WP_Error(
				'item_not_found',
				__( 'Menu item not found.', 'wp-mcp-connect' ),
				array( 'status' => 404 )
			);
		}

		$result = wp_delete_post( $item_id, true );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete menu item.', 'wp-mcp-connect' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'deleted' => $item_id,
			'menu_id' => $menu_id,
		);
	}

	/**
	 * Create a new menu.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error
	 */
	public function create_menu( $request ) {
		$name = sanitize_text_field( $request->get_param( 'name' ) );
		$location = $request->get_param( 'location' );

		// Check if menu already exists
		$existing = wp_get_nav_menu_object( $name );
		if ( $existing ) {
			return new WP_Error(
				'menu_exists',
				__( 'A menu with this name already exists.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		// Create the menu
		$menu_id = wp_create_nav_menu( $name );

		if ( is_wp_error( $menu_id ) ) {
			return $menu_id;
		}

		// Assign to location if specified
		if ( $location ) {
			$registered_locations = get_registered_nav_menus();
			if ( ! isset( $registered_locations[ $location ] ) ) {
				return new WP_Error(
					'invalid_location',
					__( 'Invalid menu location.', 'wp-mcp-connect' ),
					array( 'status' => 400 )
				);
			}

			$locations = get_nav_menu_locations();
			$locations[ $location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		return array(
			'success'  => true,
			'menu_id'  => $menu_id,
			'name'     => $name,
			'location' => $location,
		);
	}

	/**
	 * Assign a menu to a theme location.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error
	 */
	public function assign_menu_location( $request ) {
		$menu_id = $request->get_param( 'menu_id' );
		$location = sanitize_text_field( $request->get_param( 'location' ) );

		// Verify menu exists
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return new WP_Error(
				'menu_not_found',
				__( 'Menu not found.', 'wp-mcp-connect' ),
				array( 'status' => 404 )
			);
		}

		// Verify location is registered
		$registered_locations = get_registered_nav_menus();
		if ( ! isset( $registered_locations[ $location ] ) ) {
			return new WP_Error(
				'invalid_location',
				__( 'Invalid menu location.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		// Assign the menu to the location
		$locations = get_nav_menu_locations();
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		return array(
			'success'       => true,
			'menu_id'       => $menu_id,
			'menu_name'     => $menu->name,
			'location'      => $location,
			'location_name' => $registered_locations[ $location ],
		);
	}
}
