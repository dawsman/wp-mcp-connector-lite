<?php
defined( 'ABSPATH' ) || exit;

/**
 * Content Health Score utility class.
 *
 * Calculates a composite 0-100 health score for posts based on
 * SEO completeness, content freshness, and internal linking.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Health_Score {

	/**
	 * SEO field weights for completeness scoring.
	 *
	 * @var array<string, int>
	 */
	private static $seo_field_weights = array(
		'seo_title'       => 20,
		'seo_description' => 25,
		'og_title'        => 10,
		'og_description'  => 10,
		'schema_json'     => 15,
		'focus_keyword'   => 20,
	);

	/**
	 * Component weights for composite score.
	 *
	 * @var array<string, float>
	 */
	private static $component_weights = array(
		'seo'       => 0.4286,
		'freshness' => 0.2857,
		'linking'   => 0.2857,
	);

	/**
	 * Calculate the composite health score for a post.
	 *
	 * @since    1.0.0
	 * @param    int    $post_id    The post ID.
	 * @return   array{score: int, breakdown: array, status: string}|WP_Error
	 */
	public static function calculate( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', __( 'Post not found.', 'wp-mcp-connect' ), array( 'status' => 404 ) );
		}

		$seo_score       = self::calculate_seo_completeness( $post_id );
		$freshness_score = self::calculate_freshness( $post );
		$linking_score   = self::calculate_linking( $post_id );

		$composite = (int) round(
			$seo_score       * self::$component_weights['seo']
			+ $freshness_score * self::$component_weights['freshness']
			+ $linking_score   * self::$component_weights['linking']
		);

		$composite = max( 0, min( 100, $composite ) );

		$breakdown = array(
			'seo'       => $seo_score,
			'freshness' => $freshness_score,
			'linking'   => $linking_score,
		);

		$status = self::score_to_status( $composite );

		// Cache results as post meta.
		update_post_meta( $post_id, '_cwp_health_score', $composite );
		update_post_meta( $post_id, '_cwp_health_breakdown', wp_json_encode( $breakdown ) );
		update_post_meta( $post_id, '_cwp_health_updated', current_time( 'mysql', true ) );

		return array(
			'post_id'   => $post_id,
			'score'     => $composite,
			'breakdown' => $breakdown,
			'status'    => $status,
			'updated'   => current_time( 'mysql', true ),
		);
	}

	/**
	 * Get cached score or recalculate if missing.
	 *
	 * @since    1.0.0
	 * @param    int    $post_id    The post ID.
	 * @return   array{score: int, breakdown: array, status: string}|WP_Error
	 */
	public static function get_score( $post_id ) {
		$cached_score = get_post_meta( $post_id, '_cwp_health_score', true );

		if ( '' === $cached_score ) {
			return self::calculate( $post_id );
		}

		$breakdown_json = get_post_meta( $post_id, '_cwp_health_breakdown', true );
		$updated        = get_post_meta( $post_id, '_cwp_health_updated', true );

		$breakdown = array();
		if ( $breakdown_json ) {
			$decoded = json_decode( $breakdown_json, true );
			if ( is_array( $decoded ) ) {
				$breakdown = $decoded;
			}
		}

		return array(
			'post_id'   => (int) $post_id,
			'score'     => (int) $cached_score,
			'breakdown' => $breakdown,
			'status'    => self::score_to_status( (int) $cached_score ),
			'updated'   => $updated ?: null,
		);
	}

	/**
	 * Calculate health scores for multiple posts.
	 *
	 * @since    1.0.0
	 * @param    int[]    $post_ids    Array of post IDs.
	 * @return   array    Array of score results keyed by post ID.
	 */
	public static function calculate_bulk( $post_ids ) {
		$results = array();
		foreach ( $post_ids as $post_id ) {
			$result = self::calculate( (int) $post_id );
			if ( ! is_wp_error( $result ) ) {
				$results[] = $result;
			}
		}
		return $results;
	}

	/**
	 * Convert a numeric score to a status label.
	 *
	 * @since    1.0.0
	 * @param    int    $score    The health score (0-100).
	 * @return   string           Status label.
	 */
	public static function score_to_status( $score ) {
		if ( $score >= 80 ) {
			return 'healthy';
		}
		if ( $score >= 60 ) {
			return 'good';
		}
		if ( $score >= 40 ) {
			return 'needs_attention';
		}
		if ( $score >= 20 ) {
			return 'declining';
		}
		return 'critical';
	}

	/**
	 * Calculate SEO completeness score (0-100).
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int    $post_id    The post ID.
	 * @return   int                SEO completeness score.
	 */
	private static function calculate_seo_completeness( $post_id ) {
		$filled_weight = 0;

		foreach ( self::$seo_field_weights as $field => $weight ) {
			$value = '';

			if ( class_exists( 'WP_MCP_Connect_SEO_Plugins' ) ) {
				$value = WP_MCP_Connect_SEO_Plugins::get_seo_value( $post_id, $field );
			}

			if ( empty( $value ) ) {
				$value = get_post_meta( $post_id, '_cwp_' . $field, true );
			}

			if ( ! empty( $value ) ) {
				$filled_weight += $weight;
			}
		}

		return $filled_weight;
	}

	/**
	 * Calculate content freshness score (0-100).
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    WP_Post    $post    The post object.
	 * @return   int                 Freshness score.
	 */
	private static function calculate_freshness( $post ) {
		$modified = strtotime( $post->post_modified_gmt );
		if ( ! $modified ) {
			return 20;
		}

		$days_ago = (int) floor( ( time() - $modified ) / DAY_IN_SECONDS );

		if ( $days_ago <= 30 ) {
			return 100;
		}
		if ( $days_ago <= 90 ) {
			return 80;
		}
		if ( $days_ago <= 180 ) {
			return 60;
		}
		if ( $days_ago <= 365 ) {
			return 40;
		}
		return 20;
	}

	/**
	 * Calculate internal linking score (0-100).
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int    $post_id    The post ID.
	 * @return   int                Linking score.
	 */
	private static function calculate_linking( $post_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'cwp_internal_links';

		// Check if the table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( ! $table_exists ) {
			return 50; // Neutral if table doesn't exist.
		}

		$post_url = get_permalink( $post_id );

		// Count inbound links (other pages linking to this post).
		$inlinks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE target_url = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_url
			)
		);

		// Count outbound links from this post.
		$outlinks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id
			)
		);

		// Inlinks scoring (max 50).
		if ( $inlinks >= 4 ) {
			$inlink_score = 50;
		} elseif ( $inlinks >= 2 ) {
			$inlink_score = 35;
		} elseif ( 1 === $inlinks ) {
			$inlink_score = 20;
		} else {
			$inlink_score = 0;
		}

		// Outlinks scoring (max 50).
		if ( $outlinks >= 3 ) {
			$outlink_score = 50;
		} elseif ( $outlinks >= 1 ) {
			$outlink_score = 25;
		} else {
			$outlink_score = 0;
		}

		return $inlink_score + $outlink_score;
	}
}
