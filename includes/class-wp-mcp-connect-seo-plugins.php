<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles detection and integration with third-party SEO plugins.
 *
 * Supports Rank Math, Yoast SEO, and All in One SEO with automatic
 * detection and meta field mapping.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_SEO_Plugins {

	/**
	 * Supported SEO plugins with their meta key mappings.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array
	 */
	private static $plugins = array(
		'rank_math' => array(
			'file'      => 'seo-by-rank-math/rank-math.php',
			'name'      => 'Rank Math',
			'meta_keys' => array(
				'seo_title'       => 'rank_math_title',
				'seo_description' => 'rank_math_description',
				'og_title'        => 'rank_math_facebook_title',
				'og_description'  => 'rank_math_facebook_description',
				'og_image_id'     => 'rank_math_facebook_image_id',
				'schema_json'          => 'rank_math_schema',
				'focus_keyword'        => 'rank_math_focus_keyword',
				'cornerstone_content'  => 'rank_math_pillar_content',
			),
		),
		'yoast' => array(
			'file'      => 'wordpress-seo/wp-seo.php',
			'name'      => 'Yoast SEO',
			'meta_keys' => array(
				'seo_title'       => '_yoast_wpseo_title',
				'seo_description' => '_yoast_wpseo_metadesc',
				'og_title'        => '_yoast_wpseo_opengraph-title',
				'og_description'  => '_yoast_wpseo_opengraph-description',
				'og_image_id'     => '_yoast_wpseo_opengraph-image-id',
				// Yoast's schema page-type meta holds a schema *type string* ('WebPage',
				// 'AboutPage'), not a JSON-LD document. Writing a JSON blob there corrupts the
				// post's Yoast page-type setting, so JSON-LD lives in our own meta key instead.
				'schema_json'          => '_cwp_schema_json',
				'focus_keyword'        => '_yoast_wpseo_focuskw',
				'cornerstone_content'  => '_yoast_wpseo_is_cornerstone',
			),
		),
		'aioseo' => array(
			'file'      => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
			'name'      => 'All in One SEO',
			/*
			 * AIOSEO 4.x dropped its legacy 2.x/3.x post meta keys and moved per-post SEO into its
			 * own {$wpdb->prefix}aioseo_posts table. Only schema_json still lives in post meta,
			 * in our own key. Every other field is dispatched to get_aioseo_value() /
			 * set_aioseo_value(), which read and write the table via AIOSEO's Post model.
			 * The empty strings keep get_meta_key() honest: there is no post meta key.
			 */
			'meta_keys' => array(
				'seo_title'            => '',
				'seo_description'      => '',
				'og_title'             => '',
				'og_description'       => '',
				'og_image_id'          => '',
				'schema_json'          => '_cwp_schema_json',
				'focus_keyword'        => '',
				'cornerstone_content'  => '',
			),
			'columns'   => array(
				'seo_title'            => 'title',
				'seo_description'      => 'description',
				'og_title'             => 'og_title',
				'og_description'       => 'og_description',
				'og_image_id'          => 'og_image_custom_url',
				'focus_keyword'        => 'keyphrases',
				'cornerstone_content'  => 'pillar_content',
			),
		),
		'cwp' => array(
			'file'      => null,
			'name'      => 'WP MCP Connect (Built-in)',
			'meta_keys' => array(
				'seo_title'       => '_cwp_seo_title',
				'seo_description' => '_cwp_seo_description',
				'og_title'        => '_cwp_og_title',
				'og_description'  => '_cwp_og_description',
				'og_image_id'     => '_cwp_og_image_id',
				'schema_json'          => '_cwp_schema_json',
				'focus_keyword'        => '_cwp_focus_keyword',
				'cornerstone_content'  => '_cwp_cornerstone_content',
			),
		),
	);

	/**
	 * Cached active plugin detection result.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array|null
	 */
	private static $active_plugin = null;

	/**
	 * Detect which SEO plugin is active.
	 *
	 * Returns the first detected plugin from the priority list:
	 * Rank Math > Yoast > AIOSEO > cwp (fallback)
	 *
	 * @since    1.0.0
	 * @return   array    Plugin data with slug, name, file, and meta_keys.
	 */
	public static function detect_active_plugin() {
		if ( self::$active_plugin !== null ) {
			return self::$active_plugin;
		}

		// Check if function exists (it won't on frontend early)
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check plugins in priority order
		foreach ( self::$plugins as $slug => $plugin ) {
			if ( $plugin['file'] === null ) {
				continue; // Skip cwp fallback for now
			}

			if ( is_plugin_active( $plugin['file'] ) ) {
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin['file'] );
				self::$active_plugin = array(
					'slug'      => $slug,
					'name'      => $plugin['name'],
					'version'   => isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '',
					'file'      => $plugin['file'],
					'meta_keys' => $plugin['meta_keys'],
				);
				return self::$active_plugin;
			}
		}

		// Fallback to built-in cwp fields
		self::$active_plugin = array(
			'slug'      => 'cwp',
			'name'      => self::$plugins['cwp']['name'],
			'version'   => '',
			'file'      => null,
			'meta_keys' => self::$plugins['cwp']['meta_keys'],
		);

		return self::$active_plugin;
	}

	/**
	 * Get the meta key for a specific SEO field based on active plugin.
	 *
	 * @since    1.0.0
	 * @param    string    $field    The SEO field name (seo_title, seo_description, etc.)
	 * @return   string              The meta key to use.
	 */
	public static function get_meta_key( $field ) {
		$plugin = self::detect_active_plugin();

		if ( isset( $plugin['meta_keys'][ $field ] ) ) {
			return $plugin['meta_keys'][ $field ];
		}

		// Fallback to cwp key
		return self::$plugins['cwp']['meta_keys'][ $field ] ?? '_cwp_' . $field;
	}

	/**
	 * Get an SEO value from the active plugin's storage.
	 *
	 * schema_json always reads from our own _cwp_schema_json meta key first, whichever
	 * SEO plugin is active, and only falls back to a native lookup when that is empty.
	 * AIOSEO fields (other than schema_json) live in the aioseo_posts table, not post meta.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @return   mixed                 The value, or null when the active plugin has no home for the field.
	 */
	public static function get_seo_value( $post_id, $field ) {
		$plugin = self::detect_active_plugin();

		if ( 'schema_json' === $field ) {
			return self::get_schema_json_value( $post_id, $plugin );
		}

		if ( 'aioseo' === $plugin['slug'] ) {
			return self::get_aioseo_value( $post_id, $field );
		}

		$meta_key = self::get_meta_key( $field );
		if ( '' === $meta_key ) {
			// The active plugin has no storage for this field.
			return null;
		}

		return get_post_meta( $post_id, $meta_key, true );
	}

	/**
	 * Read the JSON-LD schema for a post.
	 *
	 * Canonical storage is _cwp_schema_json for every SEO plugin, because none of the
	 * third-party plugins expose a field that holds a raw JSON-LD document:
	 *  - Yoast's schema page-type meta holds a schema *type string*, not a document.
	 *  - AIOSEO's `schema` column holds AIOSEO's own graph *options* structure.
	 * Only Rank Math has a genuine (if proprietary) schema store, so it alone gets a
	 * read-only fallback so schema authored inside Rank Math stays visible.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int       $post_id    The post ID.
	 * @param    array     $plugin     The active plugin data.
	 * @return   string                The schema as a JSON string, or '' when there is none.
	 */
	private static function get_schema_json_value( $post_id, $plugin ) {
		$value = get_post_meta( $post_id, '_cwp_schema_json', true );

		if ( is_array( $value ) && ! empty( $value ) ) {
			return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return $value;
		}

		// Read-only fallback: schema authored natively in Rank Math.
		if ( 'rank_math' === $plugin['slug'] ) {
			$native = get_post_meta( $post_id, 'rank_math_schema', true );

			if ( is_array( $native ) && ! empty( $native ) ) {
				$unwrapped = self::unwrap_schema_from_rank_math( $native );
				return wp_json_encode( $unwrapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}

			if ( is_string( $native ) && '' !== trim( $native ) ) {
				return $native;
			}
		}

		return '';
	}

	/**
	 * Get the resolved/rendered SEO value, including defaults from templates.
	 *
	 * When SEO plugins use templates like %title% %sep% %sitename%, the post
	 * meta may be empty but there's still SEO content being generated.
	 * This method returns the actual rendered value that appears on the frontend.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name (seo_title, seo_description).
	 * @return   array                 Array with 'value', 'is_custom', and 'template' keys.
	 */
	public static function get_resolved_seo_value( $post_id, $field ) {
		$plugin = self::detect_active_plugin();
		$custom_value = self::get_seo_value( $post_id, $field );

		$result = array(
			'value'     => $custom_value,
			'is_custom' => ! empty( $custom_value ),
			'template'  => '',
		);

		// If there's a custom value, return it
		if ( ! empty( $custom_value ) ) {
			return $result;
		}

		// Try to get the resolved default value based on active plugin
		if ( 'rank_math' === $plugin['slug'] ) {
			$result = self::get_rank_math_resolved_value( $post_id, $field, $result );
		} elseif ( 'yoast' === $plugin['slug'] ) {
			$result = self::get_yoast_resolved_value( $post_id, $field, $result );
		} elseif ( 'aioseo' === $plugin['slug'] ) {
			$result = self::get_aioseo_resolved_value( $post_id, $field, $result );
		}

		return $result;
	}

	/**
	 * Get resolved SEO value from Rank Math.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_rank_math_resolved_value( $post_id, $field, $result ) {
		if ( ! class_exists( 'RankMath\Helper' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		$post_type = $post->post_type;

		if ( 'seo_title' === $field ) {
			// Get the title template from Rank Math settings
			$template = \RankMath\Helper::get_settings( "titles.pt_{$post_type}_title" );
			if ( empty( $template ) ) {
				$template = '%title% %sep% %sitename%';
			}
			$result['template'] = $template;
			$result['value'] = \RankMath\Helper::replace_vars( $template, $post );
		} elseif ( 'seo_description' === $field ) {
			// Get the description template from Rank Math settings
			$template = \RankMath\Helper::get_settings( "titles.pt_{$post_type}_description" );
			if ( empty( $template ) ) {
				$template = '%excerpt%';
			}
			$result['template'] = $template;
			$result['value'] = \RankMath\Helper::replace_vars( $template, $post );
		}

		return $result;
	}

	/**
	 * Get resolved SEO value from Yoast.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_yoast_resolved_value( $post_id, $field, $result ) {
		if ( ! class_exists( 'WPSEO_Options' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		$post_type = $post->post_type;

		if ( 'seo_title' === $field ) {
			$template = \WPSEO_Options::get( "title-{$post_type}" );
			if ( ! empty( $template ) ) {
				$result['template'] = $template;
				if ( class_exists( 'WPSEO_Replace_Vars' ) ) {
					$replace_vars = new \WPSEO_Replace_Vars();
					$result['value'] = $replace_vars->replace( $template, $post );
				}
			}
		} elseif ( 'seo_description' === $field ) {
			$template = \WPSEO_Options::get( "metadesc-{$post_type}" );
			if ( ! empty( $template ) ) {
				$result['template'] = $template;
				if ( class_exists( 'WPSEO_Replace_Vars' ) ) {
					$replace_vars = new \WPSEO_Replace_Vars();
					$result['value'] = $replace_vars->replace( $template, $post );
				}
			}
		}

		return $result;
	}

	/**
	 * Get resolved SEO value from All in One SEO.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_aioseo_resolved_value( $post_id, $field, $result ) {
		if ( ! function_exists( 'aioseo' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		// AIOSEO uses its own helper functions
		if ( 'seo_title' === $field ) {
			$title = aioseo()->meta->title->getPostTitle( $post );
			if ( ! empty( $title ) ) {
				$result['value'] = $title;
				$result['template'] = '(AIOSEO default)';
			}
		} elseif ( 'seo_description' === $field ) {
			$desc = aioseo()->meta->description->getPostDescription( $post );
			if ( ! empty( $desc ) ) {
				$result['value'] = $desc;
				$result['template'] = '(AIOSEO default)';
			}
		}

		return $result;
	}

	/**
	 * Get the resolved schema value, including defaults from plugin settings.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @return   array                 Array with 'value', 'is_custom', 'schema_type', and 'template' keys.
	 */
	public static function get_resolved_schema_value( $post_id ) {
		$plugin = self::detect_active_plugin();
		$custom_value = self::get_seo_value( $post_id, 'schema_json' );

		$result = array(
			'value'       => $custom_value,
			'is_custom'   => ! empty( $custom_value ),
			'schema_type' => '',
			'template'    => '',
		);

		// If there's a custom value, try to extract the schema type.
		if ( ! empty( $custom_value ) ) {
			$decoded = json_decode( $custom_value, true );
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['@type'] ) ) {
					// Single schema object - @type may be string or array.
					$type = $decoded['@type'];
					$result['schema_type'] = is_array( $type ) ? implode( ', ', $type ) : $type;
				} elseif ( isset( $decoded[0] ) ) {
					// Array of schema objects - collect all types.
					$types = array();
					foreach ( $decoded as $schema_obj ) {
						if ( isset( $schema_obj['@type'] ) ) {
							$t = $schema_obj['@type'];
							$types[] = is_array( $t ) ? implode( ', ', $t ) : $t;
						}
					}
					$result['schema_type'] = implode( '; ', $types );
				}
			}
			return $result;
		}

		// Get default schema based on active plugin
		if ( 'rank_math' === $plugin['slug'] ) {
			$result = self::get_rank_math_default_schema( $post_id, $result );
		} elseif ( 'yoast' === $plugin['slug'] ) {
			$result = self::get_yoast_default_schema( $post_id, $result );
		} elseif ( 'aioseo' === $plugin['slug'] ) {
			$result = self::get_aioseo_default_schema( $post_id, $result );
		}

		return $result;
	}

	/**
	 * Get Rank Math default schema for a post.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_rank_math_default_schema( $post_id, $result ) {
		if ( ! class_exists( 'RankMath\Helper' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		$post_type = $post->post_type;

		// Get default schema type from Rank Math settings
		$schema_type = \RankMath\Helper::get_settings( "titles.pt_{$post_type}_default_rich_snippet" );
		if ( empty( $schema_type ) || 'off' === $schema_type ) {
			$schema_type = 'post' === $post_type ? 'Article' : 'WebPage';
		}

		$result['schema_type'] = ucfirst( $schema_type );
		$result['template'] = "(Rank Math default: {$result['schema_type']})";

		return $result;
	}

	/**
	 * Get Yoast default schema for a post.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_yoast_default_schema( $post_id, $result ) {
		if ( ! class_exists( 'WPSEO_Options' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		$post_type = $post->post_type;

		// Yoast uses schema-page-type and schema-article-type settings
		$page_type = \WPSEO_Options::get( "schema-page-type-{$post_type}" );
		$article_type = \WPSEO_Options::get( "schema-article-type-{$post_type}" );

		if ( ! empty( $article_type ) && 'None' !== $article_type ) {
			$result['schema_type'] = $article_type;
		} elseif ( ! empty( $page_type ) ) {
			$result['schema_type'] = $page_type;
		} else {
			$result['schema_type'] = 'post' === $post_type ? 'Article' : 'WebPage';
		}

		$result['template'] = "(Yoast default: {$result['schema_type']})";

		return $result;
	}

	/**
	 * Get AIOSEO default schema for a post.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_aioseo_default_schema( $post_id, $result ) {
		if ( ! function_exists( 'aioseo' ) ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		// AIOSEO has default schema types
		$result['schema_type'] = 'post' === $post->post_type ? 'Article' : 'WebPage';
		$result['template'] = "(AIOSEO default: {$result['schema_type']})";

		return $result;
	}

	/**
	 * Get resolved SEO values for a taxonomy term.
	 *
	 * @since    1.0.0
	 * @param    int       $term_id    The term ID.
	 * @param    string    $taxonomy   The taxonomy name.
	 * @param    string    $field      The SEO field name (seo_title, seo_description).
	 * @return   array                 Array with 'value', 'is_custom', and 'template' keys.
	 */
	public static function get_resolved_term_seo_value( $term_id, $taxonomy, $field ) {
		$plugin = self::detect_active_plugin();

		$result = array(
			'value'     => '',
			'is_custom' => false,
			'template'  => '',
		);

		// Get custom term meta based on plugin
		if ( 'rank_math' === $plugin['slug'] ) {
			$result = self::get_rank_math_term_seo( $term_id, $taxonomy, $field, $result );
		} elseif ( 'yoast' === $plugin['slug'] ) {
			$result = self::get_yoast_term_seo( $term_id, $taxonomy, $field, $result );
		} elseif ( 'aioseo' === $plugin['slug'] ) {
			$result = self::get_aioseo_term_seo( $term_id, $taxonomy, $field, $result );
		}

		return $result;
	}

	/**
	 * Get Rank Math SEO values for a taxonomy term.
	 *
	 * @since    1.0.0
	 * @param    int       $term_id    The term ID.
	 * @param    string    $taxonomy   The taxonomy name.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_rank_math_term_seo( $term_id, $taxonomy, $field, $result ) {
		if ( ! class_exists( 'RankMath\Helper' ) ) {
			return $result;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return $result;
		}

		// Check for custom term meta first
		if ( 'seo_title' === $field ) {
			$custom = get_term_meta( $term_id, 'rank_math_title', true );
			if ( ! empty( $custom ) ) {
				$result['value'] = $custom;
				$result['is_custom'] = true;
				return $result;
			}

			// Get template from settings
			$template = \RankMath\Helper::get_settings( "titles.tax_{$taxonomy}_title" );
			if ( empty( $template ) ) {
				$template = '%term% %sep% %sitename%';
			}
			$result['template'] = $template;
			$result['value'] = \RankMath\Helper::replace_vars( $template, $term );
		} elseif ( 'seo_description' === $field ) {
			$custom = get_term_meta( $term_id, 'rank_math_description', true );
			if ( ! empty( $custom ) ) {
				$result['value'] = $custom;
				$result['is_custom'] = true;
				return $result;
			}

			// Get template from settings
			$template = \RankMath\Helper::get_settings( "titles.tax_{$taxonomy}_description" );
			if ( empty( $template ) ) {
				$template = '%term_description%';
			}
			$result['template'] = $template;
			$result['value'] = \RankMath\Helper::replace_vars( $template, $term );
		}

		return $result;
	}

	/**
	 * Get Yoast SEO values for a taxonomy term.
	 *
	 * @since    1.0.0
	 * @param    int       $term_id    The term ID.
	 * @param    string    $taxonomy   The taxonomy name.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_yoast_term_seo( $term_id, $taxonomy, $field, $result ) {
		if ( ! class_exists( 'WPSEO_Options' ) || ! class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
			return $result;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return $result;
		}

		if ( 'seo_title' === $field ) {
			$custom = \WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy, 'title' );
			if ( ! empty( $custom ) ) {
				$result['value'] = $custom;
				$result['is_custom'] = true;
				return $result;
			}

			$template = \WPSEO_Options::get( "title-tax-{$taxonomy}" );
			if ( ! empty( $template ) && class_exists( 'WPSEO_Replace_Vars' ) ) {
				$result['template'] = $template;
				$replace_vars = new \WPSEO_Replace_Vars();
				$result['value'] = $replace_vars->replace( $template, $term );
			}
		} elseif ( 'seo_description' === $field ) {
			$custom = \WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy, 'desc' );
			if ( ! empty( $custom ) ) {
				$result['value'] = $custom;
				$result['is_custom'] = true;
				return $result;
			}

			$template = \WPSEO_Options::get( "metadesc-tax-{$taxonomy}" );
			if ( ! empty( $template ) && class_exists( 'WPSEO_Replace_Vars' ) ) {
				$result['template'] = $template;
				$replace_vars = new \WPSEO_Replace_Vars();
				$result['value'] = $replace_vars->replace( $template, $term );
			}
		}

		return $result;
	}

	/**
	 * Get AIOSEO SEO values for a taxonomy term.
	 *
	 * @since    1.0.0
	 * @param    int       $term_id    The term ID.
	 * @param    string    $taxonomy   The taxonomy name.
	 * @param    string    $field      The SEO field name.
	 * @param    array     $result     The result array to populate.
	 * @return   array                 Updated result array.
	 */
	private static function get_aioseo_term_seo( $term_id, $taxonomy, $field, $result ) {
		if ( ! function_exists( 'aioseo' ) ) {
			return $result;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return $result;
		}

		if ( 'seo_title' === $field ) {
			$title = aioseo()->meta->title->getTermTitle( $term );
			if ( ! empty( $title ) ) {
				$result['value'] = $title;
				$result['template'] = '(AIOSEO default)';
			}
		} elseif ( 'seo_description' === $field ) {
			$desc = aioseo()->meta->description->getTermDescription( $term );
			if ( ! empty( $desc ) ) {
				$result['value'] = $desc;
				$result['template'] = '(AIOSEO default)';
			}
		}

		return $result;
	}

	/**
	 * Set an SEO value on the active plugin's storage.
	 *
	 * schema_json always writes to our own _cwp_schema_json meta key, whichever SEO plugin
	 * is active (see get_schema_json_value() for why). AIOSEO fields go to the aioseo_posts
	 * table. Clearing a field is idempotent: passing an empty value always reports success,
	 * even when there was nothing stored to remove.
	 *
	 * @since    1.0.0
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @param    mixed     $value      The value to set. An empty value clears the field.
	 * @return   bool|WP_Error         True on success, false on failure, WP_Error when the
	 *                                 active plugin has no storage for the field.
	 */
	public static function set_seo_value( $post_id, $field, $value ) {
		$plugin    = self::detect_active_plugin();
		$is_clear  = self::is_empty_seo_value( $value );

		// Schema is stored in our own meta key for every plugin. None of the third-party
		// plugins expose a field that holds a raw JSON-LD document, and writing into the
		// ones that look like they might (Yoast's page-type string, Rank Math's internal
		// wrapper array) corrupts their own settings.
		if ( 'schema_json' === $field ) {
			if ( $is_clear ) {
				delete_post_meta( $post_id, '_cwp_schema_json' );
				return true;
			}

			return (bool) update_post_meta( $post_id, '_cwp_schema_json', $value );
		}

		if ( 'aioseo' === $plugin['slug'] ) {
			return self::set_aioseo_value( $post_id, $field, $value, $is_clear );
		}

		$meta_key = self::get_meta_key( $field );
		if ( '' === $meta_key ) {
			return self::unsupported_field_error( $plugin['name'], $field );
		}

		if ( $is_clear ) {
			delete_post_meta( $post_id, $meta_key );
			return true;
		}

		// Rank Math handling - use direct update_post_meta for all fields.
		// RankMath's Helper::update_post_meta() has proven unreliable across versions.
		// Direct meta updates work consistently for all RankMath fields.
		if ( 'rank_math' === $plugin['slug'] ) {
			$result = update_post_meta( $post_id, $meta_key, $value );
			self::debug_log_seo_save( $post_id, $field, $meta_key, $value, 'direct_meta' );
			return (bool) $result;
		}

		// Use Yoast's WPSEO_Meta class for proper handling and validation
		if ( 'yoast' === $plugin['slug'] && class_exists( 'WPSEO_Meta' ) ) {
			// WPSEO_Meta::set_value() expects the key without the '_yoast_wpseo_' prefix
			$yoast_key = str_replace( '_yoast_wpseo_', '', $meta_key );
			return (bool) WPSEO_Meta::set_value( $yoast_key, $value, $post_id );
		}

		return (bool) update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Whether a value passed to set_seo_value() means "clear this field".
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    mixed    $value    The incoming value.
	 * @return   bool               True when the field should be cleared.
	 */
	private static function is_empty_seo_value( $value ) {
		if ( is_array( $value ) ) {
			return empty( $value );
		}

		return ( null === $value || '' === $value || 0 === $value || '0' === $value || false === $value );
	}

	/**
	 * Build the WP_Error returned when a plugin has no storage for a field.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    string    $plugin_name    The active plugin's display name.
	 * @param    string    $field          The SEO field name.
	 * @return   WP_Error
	 */
	private static function unsupported_field_error( $plugin_name, $field ) {
		return new WP_Error(
			'unsupported_field',
			sprintf(
				/* translators: 1: SEO plugin name, 2: SEO field name. */
				__( '%1$s does not support %2$s', 'wp-mcp-connect' ),
				$plugin_name,
				$field
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Log SEO save operations for debugging when WP_DEBUG is enabled.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @param    string    $meta_key   The actual meta key used.
	 * @param    mixed     $value      The value that was saved.
	 * @param    string    $method     The save method used ('rank_math_helper' or 'direct_meta').
	 * @return   void
	 */
	private static function debug_log_seo_save( $post_id, $field, $meta_key, $value, $method ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Verify the save by reading back the value.
		$saved = get_post_meta( $post_id, $meta_key, true );

		// Compare saved value - handle arrays and strings.
		$save_ok = false;
		if ( is_array( $value ) && is_array( $saved ) ) {
			$save_ok = ( $saved === $value );
		} elseif ( is_string( $value ) && is_string( $saved ) ) {
			$save_ok = ( $saved === $value );
		} elseif ( ! empty( $saved ) ) {
			// At least something was saved.
			$save_ok = true;
		}

		$status = $save_ok ? 'OK' : 'FAILED';
		$value_preview = is_array( $value ) ? 'array(' . count( $value ) . ')' : substr( (string) $value, 0, 50 );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[WP MCP Connect] SEO save: post=%d field=%s key=%s method=%s value="%s" status=%s',
				$post_id,
				$field,
				$meta_key,
				$method,
				$value_preview,
				$status
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------------------
	 * All in One SEO 4.x / 5.x storage.
	 *
	 * Verified against the plugin source on the WordPress.org SVN trunk (version 5.0.1.1,
	 * fetched 2026-09-15 from https://plugins.svn.wordpress.org/all-in-one-seo-pack/trunk/):
	 *
	 *   app/Common/Models/Post.php
	 *     - namespace AIOSEO\Plugin\Common\Models; class Post extends Model
	 *     - protected $table = 'aioseo_posts';
	 *     - public static function getPost( $postId ) : Post - always returns a model;
	 *       $post->exists() is false when no row has been written for the post yet.
	 *     - public static function savePost( $postId, array $data ) - patch semantics:
	 *       sanitizeAndSetDefaults() only assigns keys present in $data, so a partial save
	 *       leaves every other column untouched. Returns a string on DB error, null on
	 *       success, false when $data is empty.
	 *     - $jsonFields includes keyphrases, schema, additional_keywords.
	 *     - $booleanFields includes pillar_content.
	 *     - getSanitizeFieldMap() maps the input keys used below to columns:
	 *         title => title (text), description => description (text),
	 *         pillar_content => pillar_content (bool), og_title => og_title (text),
	 *         og_description => og_description (text),
	 *         og_image_type => og_image_type (text, default 'default'),
	 *         og_image_custom_url => og_image_custom_url (url).
	 *     - keyphrases and focus_keyword are handled inline in sanitizeAndSetDefaults().
	 *       AIOSEO 5.0.0.1 added a dedicated focus_keyword varchar(255) column alongside
	 *       the legacy keyphrases longtext JSON and keeps the two in sync
	 *       (getKeywordColumnsWithLegacyFallback()), so we write both keys and read the
	 *       column first with a fallback to the JSON.
	 *     - keyphrases JSON shape, from getKeyphrasesDefaults():
	 *         { "focus": { "keyphrase": "", "score": 0, "analysis": {...} }, "additional": [] }
	 *
	 *   app/Common/Social/Image.php - og_image_type === 'custom_image' is the value that
	 *   makes AIOSEO read og_image_custom_url when building the og:image tag.
	 *
	 *   app/Common/Utils/Database.php - 'aioseo_posts' is a registered custom table, created
	 *   through dbDelta by Updates::addInitialCustomTablesForV4().
	 *
	 * Note: AIOSEO's own `schema` column stores AIOSEO's structured graph *options*, not a raw
	 * JSON-LD document, so schema_json is deliberately not mapped onto it. See
	 * get_schema_json_value().
	 *
	 * Note: post_id is not a unique key on aioseo_posts (AIOSEO ships its own
	 * Updates::removeDuplicateRecords() to clean duplicates up), so the direct-SQL fallback
	 * does a SELECT-then-INSERT/UPDATE rather than INSERT ... ON DUPLICATE KEY UPDATE.
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * The fully-qualified class name of AIOSEO's Post model.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	private const AIOSEO_POST_MODEL = 'AIOSEO\\Plugin\\Common\\Models\\Post';

	/**
	 * Get the prefixed aioseo_posts table name.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   string    The table name.
	 */
	private static function get_aioseo_table() {
		global $wpdb;

		return $wpdb->prefix . 'aioseo_posts';
	}

	/**
	 * Whether the aioseo_posts table exists. Cached for the request.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   bool    True when the table exists.
	 */
	private static function aioseo_table_exists() {
		static $exists = null;

		if ( null !== $exists ) {
			return $exists;
		}

		global $wpdb;
		$table = self::get_aioseo_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		$exists = ( $found === $table );

		return $exists;
	}

	/**
	 * Get the AIOSEO data source for a post: the Post model when AIOSEO is loaded,
	 * otherwise the raw table row.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int    $post_id    The post ID.
	 * @return   object|null        The Post model or a stdClass row, null when there is no data.
	 */
	private static function get_aioseo_source( $post_id ) {
		if ( function_exists( 'aioseo' ) && class_exists( self::AIOSEO_POST_MODEL ) ) {
			$model = call_user_func( array( self::AIOSEO_POST_MODEL, 'getPost' ), $post_id );

			// getPost() always returns a model; exists() is false when no row has been
			// written yet, and also during the early bootstrap where AIOSEO's DB layer is
			// not ready. Fall through to the raw row in both cases.
			if ( is_object( $model ) && ( ! method_exists( $model, 'exists' ) || $model->exists() ) ) {
				return $model;
			}
		}

		return self::get_aioseo_row( $post_id );
	}

	/**
	 * Read the raw aioseo_posts row for a post.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int    $post_id    The post ID.
	 * @return   object|null        The row, or null when there is none.
	 */
	private static function get_aioseo_row( $post_id ) {
		if ( ! self::aioseo_table_exists() ) {
			return null;
		}

		global $wpdb;
		$table = self::get_aioseo_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE post_id = %d LIMIT 1", absint( $post_id ) ) );

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Get an SEO value from AIOSEO's aioseo_posts table.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int       $post_id    The post ID.
	 * @param    string    $field      The SEO field name.
	 * @return   mixed                 The value, '' when unset, null when unsupported.
	 */
	private static function get_aioseo_value( $post_id, $field ) {
		$columns = self::$plugins['aioseo']['columns'];

		if ( ! isset( $columns[ $field ] ) ) {
			return null;
		}

		$source = self::get_aioseo_source( $post_id );
		if ( null === $source ) {
			return '';
		}

		if ( 'og_image_id' === $field ) {
			$url = isset( $source->og_image_custom_url ) ? $source->og_image_custom_url : '';

			return self::aioseo_og_image_id_from_url( $url );
		}

		if ( 'focus_keyword' === $field ) {
			// AIOSEO >= 5.0.0.1 keeps a dedicated column; older rows only have the JSON.
			if ( ! empty( $source->focus_keyword ) && is_string( $source->focus_keyword ) ) {
				return $source->focus_keyword;
			}

			return self::decode_aioseo_focus_keyphrase( isset( $source->keyphrases ) ? $source->keyphrases : null );
		}

		if ( 'cornerstone_content' === $field ) {
			return empty( $source->pillar_content ) ? '' : '1';
		}

		$column = $columns[ $field ];

		if ( ! isset( $source->$column ) || null === $source->$column ) {
			return '';
		}

		return $source->$column;
	}

	/**
	 * Set an SEO value on AIOSEO's aioseo_posts table.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int       $post_id     The post ID.
	 * @param    string    $field       The SEO field name.
	 * @param    mixed     $value       The value to set.
	 * @param    bool      $is_clear    Whether the value means "clear this field".
	 * @return   bool|WP_Error          True on success, WP_Error on failure or unsupported field.
	 */
	private static function set_aioseo_value( $post_id, $field, $value, $is_clear ) {
		$columns     = self::$plugins['aioseo']['columns'];
		$plugin_name = self::$plugins['aioseo']['name'];

		if ( ! isset( $columns[ $field ] ) ) {
			return self::unsupported_field_error( $plugin_name, $field );
		}

		$data = array();

		switch ( $field ) {
			case 'og_image_id':
				$url = $is_clear ? '' : self::aioseo_og_image_url_from_id( $value );

				if ( ! $is_clear && '' === $url ) {
					return new WP_Error(
						'invalid_attachment',
						sprintf(
							/* translators: %s: attachment ID. */
							__( 'Attachment %s has no URL, so it cannot be used as the Open Graph image.', 'wp-mcp-connect' ),
							(string) $value
						),
						array( 'status' => 400 )
					);
				}

				$data['og_image_custom_url'] = $url;
				$data['og_image_type']       = '' === $url ? 'default' : 'custom_image';
				break;

			case 'focus_keyword':
				$keyword  = $is_clear ? '' : (string) $value;
				$source   = self::get_aioseo_source( $post_id );
				$existing = ( $source && isset( $source->keyphrases ) ) ? $source->keyphrases : null;

				// Write both representations: the legacy JSON (all versions) and the
				// dedicated column (5.0.0.1+, ignored by older versions).
				$data['keyphrases']    = self::encode_aioseo_keyphrases( $existing, $keyword );
				$data['focus_keyword'] = $keyword;
				break;

			case 'cornerstone_content':
				$data['pillar_content'] = ! $is_clear;
				break;

			default:
				$data[ $columns[ $field ] ] = $is_clear ? '' : $value;
				break;
		}

		if ( function_exists( 'aioseo' ) && class_exists( self::AIOSEO_POST_MODEL ) && method_exists( self::AIOSEO_POST_MODEL, 'savePost' ) ) {
			$result = call_user_func( array( self::AIOSEO_POST_MODEL, 'savePost' ), $post_id, $data );

			// savePost() returns the DB error message on failure and null on success.
			if ( is_string( $result ) && '' !== $result ) {
				return new WP_Error( 'aioseo_save_failed', $result, array( 'status' => 500 ) );
			}

			return true;
		}

		return self::set_aioseo_value_direct( $post_id, $data );
	}

	/**
	 * Write AIOSEO column data straight to aioseo_posts when the Post model is unavailable.
	 *
	 * Every key set_aioseo_value() builds is both a valid savePost() input key and the
	 * matching column name (title, description, og_title, og_description, og_image_type,
	 * og_image_custom_url, keyphrases, focus_keyword, pillar_content), so the same array
	 * serves both paths. set_aioseo_robots_noindex() is the one caller that has to
	 * translate, because the robots input keys are shortened ('noindex' -> robots_noindex).
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int      $post_id    The post ID.
	 * @param    array    $data       Column => value pairs.
	 * @return   bool|WP_Error        True on success, WP_Error when the table is missing.
	 */
	private static function set_aioseo_value_direct( $post_id, $data ) {
		if ( ! self::aioseo_table_exists() ) {
			return new WP_Error(
				'aioseo_table_missing',
				__( 'The All in One SEO posts table is not available.', 'wp-mcp-connect' ),
				array( 'status' => 500 )
			);
		}

		global $wpdb;
		$table   = self::get_aioseo_table();
		$post_id = absint( $post_id );

		// keyphrases is a JSON column; the model would encode it for us.
		if ( isset( $data['keyphrases'] ) && ! is_string( $data['keyphrases'] ) ) {
			$data['keyphrases'] = wp_json_encode( $data['keyphrases'] );
		}

		if ( isset( $data['pillar_content'] ) ) {
			$data['pillar_content'] = $data['pillar_content'] ? 1 : 0;
		}

		$now      = gmdate( 'Y-m-d H:i:s' );
		$existing = self::get_aioseo_row( $post_id );

		if ( $existing && isset( $existing->id ) ) {
			$data['updated'] = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update( $table, $data, array( 'post_id' => $post_id ) );

			return false !== $updated;
		}

		$data['post_id'] = $post_id;
		$data['created'] = $now;
		$data['updated'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $table, $data );

		return false !== $inserted;
	}

	/**
	 * Set AIOSEO's robots noindex flag on the aioseo_posts table.
	 *
	 * AIOSEO 4.x keeps robots settings in the same table as the rest of the post's SEO.
	 * Setting robots_default to false is what tells AIOSEO to honour the explicit flag
	 * rather than fall back to the site-wide default for the post type.
	 *
	 * @since    1.0.0
	 * @param    int     $post_id    The post ID.
	 * @param    bool    $noindex    Whether the post should be noindexed.
	 * @return   bool|WP_Error       True on success, WP_Error on failure.
	 */
	public static function set_aioseo_robots_noindex( $post_id, $noindex ) {
		$data = array(
			'default' => false,
			'noindex' => (bool) $noindex,
		);

		if ( function_exists( 'aioseo' ) && class_exists( self::AIOSEO_POST_MODEL ) && method_exists( self::AIOSEO_POST_MODEL, 'savePost' ) ) {
			$result = call_user_func( array( self::AIOSEO_POST_MODEL, 'savePost' ), $post_id, $data );

			if ( is_string( $result ) && '' !== $result ) {
				return new WP_Error( 'aioseo_save_failed', $result, array( 'status' => 500 ) );
			}

			return true;
		}

		// Direct SQL uses the column names, not the model's input keys.
		return self::set_aioseo_value_direct(
			$post_id,
			array(
				'robots_default' => 0,
				'robots_noindex' => $noindex ? 1 : 0,
			)
		);
	}

	/**
	 * Decode AIOSEO's keyphrases column into an array.
	 *
	 * Accepts the JSON string stored in the column, or the decoded object/array the
	 * Post model exposes.
	 *
	 * @since    1.0.0
	 * @param    mixed    $keyphrases    The raw keyphrases value.
	 * @return   array                   The decoded structure, or an empty array.
	 */
	public static function decode_aioseo_keyphrases( $keyphrases ) {
		if ( empty( $keyphrases ) ) {
			return array();
		}

		if ( is_string( $keyphrases ) ) {
			$decoded = json_decode( $keyphrases, true );

			return is_array( $decoded ) ? $decoded : array();
		}

		if ( is_array( $keyphrases ) || is_object( $keyphrases ) ) {
			$decoded = json_decode( (string) wp_json_encode( $keyphrases ), true );

			return is_array( $decoded ) ? $decoded : array();
		}

		return array();
	}

	/**
	 * Extract the focus keyphrase from AIOSEO's keyphrases structure.
	 *
	 * @since    1.0.0
	 * @param    mixed    $keyphrases    The raw keyphrases value.
	 * @return   string                  The focus keyphrase, or '' when there is none.
	 */
	public static function decode_aioseo_focus_keyphrase( $keyphrases ) {
		$data = self::decode_aioseo_keyphrases( $keyphrases );

		if ( isset( $data['focus']['keyphrase'] ) && is_scalar( $data['focus']['keyphrase'] ) ) {
			return (string) $data['focus']['keyphrase'];
		}

		return '';
	}

	/**
	 * Set the focus keyphrase on AIOSEO's keyphrases structure, preserving everything else.
	 *
	 * Additional keyphrases and per-keyphrase analysis on the existing structure are kept
	 * intact; only focus.keyphrase is replaced.
	 *
	 * @since    1.0.0
	 * @param    mixed     $keyphrases       The existing keyphrases value (string, array, object or null).
	 * @param    string    $focus_keyword    The focus keyphrase to set.
	 * @return   array                       The updated keyphrases structure.
	 */
	public static function encode_aioseo_keyphrases( $keyphrases, $focus_keyword ) {
		$data = self::decode_aioseo_keyphrases( $keyphrases );

		if ( ! isset( $data['focus'] ) || ! is_array( $data['focus'] ) ) {
			$data['focus'] = array();
		}

		$data['focus']['keyphrase'] = (string) $focus_keyword;

		if ( ! isset( $data['focus']['score'] ) ) {
			$data['focus']['score'] = 0;
		}

		if ( ! isset( $data['additional'] ) || ! is_array( $data['additional'] ) ) {
			$data['additional'] = array();
		}

		return $data;
	}

	/**
	 * Convert an attachment ID to the URL AIOSEO stores in og_image_custom_url.
	 *
	 * @since    1.0.0
	 * @param    mixed     $attachment_id    The attachment ID.
	 * @return   string                      The attachment URL, or '' when there is none.
	 */
	public static function aioseo_og_image_url_from_id( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return '';
		}

		$url = wp_get_attachment_url( $attachment_id );

		return $url ? $url : '';
	}

	/**
	 * Convert AIOSEO's og_image_custom_url back to an attachment ID.
	 *
	 * @since    1.0.0
	 * @param    mixed     $url    The stored image URL.
	 * @return   int|string        The attachment ID, or '' when the URL is empty or unknown.
	 */
	public static function aioseo_og_image_id_from_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return '';
		}

		$attachment_id = attachment_url_to_postid( $url );

		return $attachment_id ? (int) $attachment_id : '';
	}

	/**
	 * Get all supported SEO field names.
	 *
	 * @since    1.0.0
	 * @return   array    Array of field names.
	 */
	public static function get_field_names() {
		return array(
			'seo_title',
			'seo_description',
			'og_title',
			'og_description',
			'og_image_id',
			'schema_json',
			'focus_keyword',
			'cornerstone_content',
		);
	}

	/**
	 * Get info about the active SEO plugin for system info endpoint.
	 *
	 * @since    1.0.0
	 * @return   array    Plugin info with slug, name, and version.
	 */
	public static function get_plugin_info() {
		$plugin = self::detect_active_plugin();

		return array(
			'slug'    => $plugin['slug'],
			'name'    => $plugin['name'],
			'version' => $plugin['version'],
		);
	}

	/**
	 * Wrap standard JSON-LD schema into Rank Math's proprietary format.
	 *
	 * Rank Math expects schema stored as:
	 *   array( 'SchemaType-cwp1' => array( '@type' => '...', ..., 'metadata' => array(...) ) )
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    array     $schema     Decoded JSON-LD schema (single object, array of objects, or @graph container).
	 * @param    int       $post_id    The post ID.
	 * @param    string    $mode       'replace' to overwrite all schemas, 'merge' to preserve non-cwp schemas.
	 * @return   array                 Schema in Rank Math wrapper format.
	 */
	private static function wrap_schema_for_rank_math( $schema, $post_id, $mode = 'replace' ) {
		// Normalize input to a flat array of schema objects.
		$schemas = array();
		if ( isset( $schema['@graph'] ) && is_array( $schema['@graph'] ) ) {
			$schemas = $schema['@graph'];
		} elseif ( isset( $schema['@type'] ) ) {
			// Single schema object.
			$schemas = array( $schema );
		} elseif ( is_array( $schema ) && ! empty( $schema ) ) {
			// Check if it's a sequential array of schema objects.
			if ( isset( $schema[0] ) ) {
				$schemas = $schema;
			} else {
				// Associative array without @type - treat as single schema.
				$schemas = array( $schema );
			}
		}

		// Start with existing non-cwp schemas when merging.
		$wrapped = array();
		if ( 'merge' === $mode ) {
			$existing = get_post_meta( $post_id, 'rank_math_schema', true );
			if ( is_array( $existing ) ) {
				foreach ( $existing as $key => $entry ) {
					if ( ! self::is_cwp_schema_key( $key ) ) {
						$wrapped[ $key ] = $entry;
					}
				}
			}
		}

		// Wrap each schema object.
		$is_first = empty( $wrapped );
		foreach ( $schemas as $index => $single ) {
			$type = 'Thing';
			if ( isset( $single['@type'] ) ) {
				$type = is_array( $single['@type'] ) ? $single['@type'][0] : $single['@type'];
			}

			$key = $type . '-cwp' . ( $index + 1 );

			$single['metadata'] = array(
				'title'     => $type,
				'type'      => 'custom',
				'shortcode' => 'rank_math_schema',
				'isPrimary' => $is_first,
			);

			$wrapped[ $key ] = $single;
			$is_first = false;
		}

		return $wrapped;
	}

	/**
	 * Unwrap Rank Math's proprietary schema format into clean JSON-LD.
	 *
	 * Detects whether the stored value is in Rank Math wrapper format
	 * (string keys with metadata sub-arrays) and strips the wrapper,
	 * returning standard JSON-LD. Passes through already-clean data unchanged.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    array     $rank_math_schema    The raw rank_math_schema meta value.
	 * @return   array                          Clean JSON-LD schema (single object or array of objects).
	 */
	private static function unwrap_schema_from_rank_math( $rank_math_schema ) {
		if ( ! is_array( $rank_math_schema ) || empty( $rank_math_schema ) ) {
			return $rank_math_schema;
		}

		// Detect Rank Math wrapper format: string keys and at least one entry has 'metadata'.
		$is_wrapped = false;
		foreach ( $rank_math_schema as $key => $entry ) {
			if ( is_string( $key ) && is_array( $entry ) && isset( $entry['metadata'] ) ) {
				$is_wrapped = true;
				break;
			}
		}

		if ( ! $is_wrapped ) {
			return $rank_math_schema;
		}

		// Strip wrapper: remove metadata from each entry.
		$schemas = array();
		foreach ( $rank_math_schema as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			unset( $entry['metadata'] );
			$schemas[] = $entry;
		}

		if ( count( $schemas ) === 1 ) {
			return $schemas[0];
		}

		return $schemas;
	}

	/**
	 * Check if a Rank Math schema key was created by this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    string    $key    The schema array key.
	 * @return   bool              True if the key matches the cwp pattern.
	 */
	private static function is_cwp_schema_key( $key ) {
		return (bool) preg_match( '/-cwp\d+$/', $key );
	}

	/**
	 * Repair posts with corrupted RankMath schema.
	 *
	 * Deletes the rank_math_schema meta to restore RankMath UI functionality.
	 * When schema is written in incompatible formats, it can break RankMath's
	 * JavaScript in the post editor, causing the metabox to fail.
	 *
	 * @since    1.0.0
	 * @param    int|null    $post_id    Optional post ID to repair. If null, repairs all affected posts.
	 * @return   int|bool                Number of affected rows when repairing all, or true/false for single post.
	 */
	public static function repair_rank_math_schema( $post_id = null ) {
		global $wpdb;

		if ( $post_id ) {
			// Repair single post - delete the rank_math_schema meta.
			$post_id = absint( $post_id );
			return delete_post_meta( $post_id, 'rank_math_schema' );
		}

		// Repair all posts with cwp-formatted schema.
		// The cwp suffix identifies schemas written by this plugin.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				'rank_math_schema',
				'%-cwp%'
			)
		);

		// Clear object cache for affected posts.
		wp_cache_flush();

		return $affected;
	}

	/**
	 * Get list of posts with potentially corrupted RankMath schema.
	 *
	 * @since    1.0.0
	 * @return   array    Array of post IDs with cwp-formatted schema.
	 */
	public static function get_posts_with_corrupted_schema() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				'rank_math_schema',
				'%-cwp%'
			)
		);

		return array_map( 'absint', $post_ids );
	}

	/**
	 * Clear the cached plugin detection (useful for testing).
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function clear_cache() {
		self::$active_plugin = null;
	}
}
