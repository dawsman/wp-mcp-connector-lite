<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles bulk SEO operations for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_SEO_Bulk {

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
	 * Maximum items per bulk update request.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      int    $max_batch_size    Maximum batch size.
	 */
	private $max_batch_size = 50;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string                      $plugin_name    The name of the plugin.
	 * @param    string                      $version        The version of this plugin.
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
		register_rest_route( 'mcp/v1', '/seo/audit', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'audit_seo' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'post_type' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => 'any',
					'sanitize_callback' => 'sanitize_key',
				),
				'missing_fields' => array(
					'required'          => false,
					'type'              => 'array',
					'default'           => array(),
					'items'             => array( 'type' => 'string' ),
				),
				'page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 20,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/seo/bulk-update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_update_seo' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'updates' => array(
					'required'    => true,
					'type'        => 'array',
					'description' => 'Array of update objects with post_id and SEO fields',
				),
			),
		) );

		register_rest_route( 'mcp/v1', '/seo/noindex', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'set_noindex' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'post_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => 'Post ID to set noindex on',
				),
				'noindex' => array(
					'required'          => false,
					'type'              => 'boolean',
					'default'           => true,
					'description'       => 'Set to true for noindex, false for index (default: true)',
				),
			),
		) );
	}

	/**
	 * Check if user has permission.
	 *
	 * @since    1.0.0
	 * @return   bool    True if user can edit posts.
	 */
	public function check_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'edit_posts' );
	}

	/**
	 * Audit SEO metadata across posts.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   array                          Audit results.
	 */
	public function audit_seo( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$missing_fields = $request->get_param( 'missing_fields' );
		$page = max( 1, $request->get_param( 'page' ) );
		$per_page = min( max( 1, $request->get_param( 'per_page' ) ), 100 );

		$args = array(
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => 'publish',
		);

		if ( 'any' === $post_type ) {
			$args['post_type'] = get_post_types( array( 'public' => true ), 'names' );
		} else {
			$public_types = get_post_types( array( 'public' => true ), 'names' );
			if ( in_array( $post_type, $public_types, true ) ) {
				$args['post_type'] = $post_type;
			} else {
				$args['post_type'] = get_post_types( array( 'public' => true ), 'names' );
			}
		}

		$query = new WP_Query( $args );
		$results = array();
		$totals = array(
			'missing_title'         => 0,
			'missing_description'   => 0,
			'missing_og_title'      => 0,
			'missing_og_desc'       => 0,
			'missing_schema'        => 0,
			'missing_focus_keyword' => 0,
		);

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				// Get resolved SEO values (includes defaults from templates)
				$resolved_title = WP_MCP_Connect_SEO_Plugins::get_resolved_seo_value( $post_id, 'seo_title' );
				$resolved_desc = WP_MCP_Connect_SEO_Plugins::get_resolved_seo_value( $post_id, 'seo_description' );
				$resolved_schema = WP_MCP_Connect_SEO_Plugins::get_resolved_schema_value( $post_id );

				$seo_data = array(
					'post_id'              => $post_id,
					'post_title'           => get_the_title(),
					'post_type'            => get_post_type(),
					'post_url'             => get_permalink(),
					'edit_url'             => get_edit_post_link( $post_id, 'raw' ),
					'seo_title'            => $resolved_title['value'],
					'seo_title_is_custom'  => $resolved_title['is_custom'],
					'seo_title_template'   => $resolved_title['template'],
					'seo_description'           => $resolved_desc['value'],
					'seo_description_is_custom' => $resolved_desc['is_custom'],
					'seo_description_template'  => $resolved_desc['template'],
					'og_title'        => WP_MCP_Connect_SEO_Plugins::get_seo_value( $post_id, 'og_title' ),
					'og_description'  => WP_MCP_Connect_SEO_Plugins::get_seo_value( $post_id, 'og_description' ),
					'og_image_id'     => WP_MCP_Connect_SEO_Plugins::get_seo_value( $post_id, 'og_image_id' ),
					'schema_json'            => $resolved_schema['value'],
					'schema_is_custom'       => $resolved_schema['is_custom'],
					'schema_type'            => $resolved_schema['schema_type'],
					'schema_template'        => $resolved_schema['template'],
					'focus_keyword'          => WP_MCP_Connect_SEO_Plugins::get_seo_value( $post_id, 'focus_keyword' ),
					'cornerstone_content'    => (bool) WP_MCP_Connect_SEO_Plugins::get_seo_value( $post_id, 'cornerstone_content' ),
				);

				$missing = array();
				// Count as missing only if no custom value set (even if default exists)
				if ( ! $resolved_title['is_custom'] ) {
					$missing[] = 'seo_title';
					$totals['missing_title']++;
				}
				if ( ! $resolved_desc['is_custom'] ) {
					$missing[] = 'seo_description';
					$totals['missing_description']++;
				}
				if ( empty( $seo_data['og_title'] ) ) {
					$missing[] = 'og_title';
					$totals['missing_og_title']++;
				}
				if ( empty( $seo_data['og_description'] ) ) {
					$missing[] = 'og_description';
					$totals['missing_og_desc']++;
				}
				if ( ! $resolved_schema['is_custom'] ) {
					$missing[] = 'schema_json';
					$totals['missing_schema']++;
				}
				if ( empty( $seo_data['focus_keyword'] ) ) {
					$missing[] = 'focus_keyword';
					$totals['missing_focus_keyword']++;
				}

				$seo_data['missing_fields'] = $missing;

				if ( ! empty( $missing_fields ) ) {
					$has_match = false;
					foreach ( $missing_fields as $field ) {
						if ( in_array( $field, $missing, true ) ) {
							$has_match = true;
							break;
						}
					}
					if ( ! $has_match ) {
						continue;
					}
				}

				$results[] = $seo_data;
			}
			wp_reset_postdata();
		}

		return array(
			'results'     => $results,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
			'summary'     => $totals,
		);
	}

	/**
	 * Bulk update SEO metadata.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   array|WP_Error                 Update results or error.
	 */
	public function bulk_update_seo( $request ) {
		$updates = $request->get_param( 'updates' );

		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return new WP_Error(
				'invalid_data',
				__( 'No updates provided.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $updates ) > $this->max_batch_size ) {
			return new WP_Error(
				'batch_too_large',
				sprintf( __( 'Maximum %d updates per request.', 'wp-mcp-connect' ), $this->max_batch_size ),
				array( 'status' => 400 )
			);
		}

		$results = array(
			'updated' => 0,
			'failed'  => 0,
			'errors'  => array(),
		);
		$previous_state = array();

		foreach ( $updates as $index => $update ) {
			if ( ! isset( $update['post_id'] ) || ! is_numeric( $update['post_id'] ) ) {
				$results['failed']++;
				$results['errors'][] = sprintf( __( 'Item %d: Missing or invalid post_id.', 'wp-mcp-connect' ), $index + 1 );
				continue;
			}

			$post_id = absint( $update['post_id'] );
			$post = get_post( $post_id );

			if ( ! $post ) {
				$results['failed']++;
				$results['errors'][] = sprintf( __( 'Item %d: Post %d not found.', 'wp-mcp-connect' ), $index + 1, $post_id );
				continue;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$results['failed']++;
				$results['errors'][] = sprintf( __( 'Item %d: No permission to edit post %d.', 'wp-mcp-connect' ), $index + 1, $post_id );
				continue;
			}

			$updated_fields = 0;
			$seo_field_names = WP_MCP_Connect_SEO_Plugins::get_field_names();
			$before = array( 'post_id' => $post_id );

			foreach ( $seo_field_names as $field_name ) {
				if ( ! isset( $update[ $field_name ] ) ) {
					continue;
				}

				$before[ $field_name ] = WP_MCP_Connect_SEO_Plugins::get_seo_value( $post_id, $field_name );
				$value = $update[ $field_name ];

				if ( 'og_image_id' === $field_name ) {
					$value = absint( $value );
				} elseif ( 'schema_json' === $field_name ) {
					if ( ! empty( $value ) ) {
						$decoded = json_decode( $value, true );
						if ( json_last_error() !== JSON_ERROR_NONE ) {
							$results['errors'][] = sprintf( __( 'Item %d: Invalid JSON for schema_json.', 'wp-mcp-connect' ), $index + 1 );
							continue;
						}
						$value = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					}
				} else {
					$value = sanitize_text_field( $value );
				}

				$saved = WP_MCP_Connect_SEO_Plugins::set_seo_value( $post_id, $field_name, $value );

				if ( is_wp_error( $saved ) ) {
					$results['errors'][] = sprintf(
						/* translators: 1: item number, 2: SEO field name, 3: error message. */
						__( 'Item %1$d: could not set %2$s - %3$s', 'wp-mcp-connect' ),
						$index + 1,
						$field_name,
						$saved->get_error_message()
					);
					continue;
				}

				if ( false === $saved ) {
					$results['errors'][] = sprintf(
						/* translators: 1: item number, 2: SEO field name. */
						__( 'Item %1$d: failed to save %2$s.', 'wp-mcp-connect' ),
						$index + 1,
						$field_name
					);
					continue;
				}

				$updated_fields++;
			}

			if ( $updated_fields > 0 ) {
				$results['updated']++;
				$previous_state[] = $before;
			}
		}

		if ( class_exists( 'WP_MCP_Connect_Ops' ) && ! empty( $previous_state ) ) {
			WP_MCP_Connect_Ops::log_operation( 'seo_bulk', array( 'count' => $results['updated'] ), $previous_state );
		}

		return $results;
	}

	/**
	 * Set noindex/index on a post.
	 *
	 * Works with Rank Math, Yoast, and built-in meta.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The REST request object.
	 * @return   array|WP_Error                 Result or error.
	 */
	public function set_noindex( $request ) {
		$post_id = $request->get_param( 'post_id' );
		$noindex = $request->get_param( 'noindex' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'Post not found.', 'wp-mcp-connect' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to edit this content.', 'wp-mcp-connect' ),
				array( 'status' => 403 )
			);
		}

		$plugin = WP_MCP_Connect_SEO_Plugins::detect_active_plugin();
		$result = false;

		if ( 'rank_math' === $plugin['slug'] ) {
			// Rank Math stores robots as an array.
			$robots = $noindex ? array( 'noindex' ) : array( 'index' );
			$result = update_post_meta( $post_id, 'rank_math_robots', $robots );
		} elseif ( 'yoast' === $plugin['slug'] ) {
			// Yoast uses 1 for noindex, 2 for index.
			$value = $noindex ? '1' : '2';
			$result = update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $value );
		} elseif ( 'aioseo' === $plugin['slug'] ) {
			// AIOSEO 4.x keeps robots settings in its own aioseo_posts table, not post meta.
			$result = WP_MCP_Connect_SEO_Plugins::set_aioseo_robots_noindex( $post_id, $noindex );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			// Fallback: use built-in meta.
			$result = update_post_meta( $post_id, '_cwp_noindex', $noindex ? '1' : '0' );
		}

		return array(
			'success'   => (bool) $result,
			'post_id'   => $post_id,
			'noindex'   => $noindex,
			'seo_plugin' => $plugin['name'],
		);
	}
}
