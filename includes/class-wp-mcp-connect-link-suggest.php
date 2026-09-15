<?php
defined( 'ABSPATH' ) || exit;

class WP_MCP_Connect_Link_Suggest {

	/**
	 * Find internal linking opportunities for a post.
	 *
	 * @param int $post_id Source post to find link opportunities in.
	 * @param int $limit Max suggestions.
	 * @return array Suggestions.
	 */
	public static function suggest( $post_id, $limit = 10 ) {
		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return array();
		}

		$content_text = wp_strip_all_tags( $post->post_content );
		$content_lower = strtolower( $content_text );
		$site_url = home_url();

		// Get existing internal link targets to avoid suggesting duplicates
		global $wpdb;
		$links_table = $wpdb->prefix . 'cwp_internal_links';
		$existing_targets = array();
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$links_table}'" ) === $links_table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing_targets = $wpdb->get_col( $wpdb->prepare(
				"SELECT target_post_id FROM {$links_table} WHERE source_post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id
			) );
		}
		$existing_set = array_flip( array_map( 'intval', $existing_targets ) );

		// Get candidate target posts (published, public types, not self)
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$candidates = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_type FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND ID != %d
			 ORDER BY post_date DESC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			...array_merge( array_values( $post_types ), array( $post_id ) )
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$suggestions = array();

		foreach ( $candidates as $candidate ) {
			if ( isset( $existing_set[ $candidate->ID ] ) ) {
				continue; // Already linked
			}

			$match_phrases = array();
			$best_score = 0;

			// Check title match
			$title_lower = strtolower( $candidate->post_title );
			if ( strlen( $title_lower ) >= 3 && false !== strpos( $content_lower, $title_lower ) ) {
				$match_phrases[] = $candidate->post_title;
				$best_score = max( $best_score, 80 );
			}

			// Check focus keyword match
			$focus = get_post_meta( $candidate->ID, '_cwp_focus_keyword', true );
			if ( ! $focus && class_exists( 'WP_MCP_Connect_SEO_Plugins' ) ) {
				$focus = WP_MCP_Connect_SEO_Plugins::get_seo_value( $candidate->ID, 'focus_keyword' );
			}
			if ( $focus && strlen( $focus ) >= 3 ) {
				$focus_lower = strtolower( $focus );
				if ( false !== strpos( $content_lower, $focus_lower ) ) {
					$match_phrases[] = $focus;
					$best_score = max( $best_score, 90 );
				}
			}

			if ( empty( $match_phrases ) || $best_score === 0 ) {
				continue;
			}

			// Find the best anchor text (longest match phrase found in content)
			usort( $match_phrases, function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			} );
			$anchor = $match_phrases[0];

			// Find position in original content for context
			$pos = stripos( $content_text, $anchor );
			$context = '';
			if ( false !== $pos ) {
				$start = max( 0, $pos - 40 );
				$end = min( strlen( $content_text ), $pos + strlen( $anchor ) + 40 );
				$context = '...' . substr( $content_text, $start, $end - $start ) . '...';
			}

			// Boost score if target has low inlinks (needs links more)
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$links_table}'" ) === $links_table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$inlinks = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$links_table} WHERE target_post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$candidate->ID
				) );
				if ( $inlinks === 0 ) {
					$best_score += 10; // Orphan boost
				}
			}

			$suggestions[] = array(
				'target_post_id'  => (int) $candidate->ID,
				'target_title'    => $candidate->post_title,
				'target_type'     => $candidate->post_type,
				'target_url'      => get_permalink( $candidate->ID ),
				'anchor_text'     => $anchor,
				'context'         => $context,
				'relevance_score' => min( 100, $best_score ),
				'match_type'      => count( $match_phrases ) > 1 ? 'multiple' : 'single',
			);
		}

		// Sort by relevance score desc
		usort( $suggestions, function ( $a, $b ) {
			return $b['relevance_score'] - $a['relevance_score'];
		} );

		return array_slice( $suggestions, 0, $limit );
	}

	/**
	 * Insert a link into post content.
	 *
	 * @param int    $post_id     Source post.
	 * @param string $anchor_text Text to wrap in link.
	 * @param string $target_url  URL to link to.
	 * @return bool|WP_Error True on success.
	 */
	public static function insert_link( $post_id, $anchor_text, $target_url ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$content = $post->post_content;
		$anchor_escaped = preg_quote( $anchor_text, '/' );

		// Check the phrase exists and isn't already linked
		if ( ! preg_match( '/(?<!["\'>])(' . $anchor_escaped . ')(?![^<]*<\/a>)/i', $content ) ) {
			return new WP_Error( 'anchor_not_found', 'Anchor text not found in content or already linked.' );
		}

		// Replace first occurrence only (not inside existing links)
		$replacement = '<a href="' . esc_url( $target_url ) . '">' . '$1' . '</a>';
		$new_content = preg_replace(
			'/(?<!["\'>])(' . $anchor_escaped . ')(?![^<]*<\/a>)/i',
			$replacement,
			$content,
			1
		);

		if ( $new_content === $content ) {
			return new WP_Error( 'no_change', 'Could not insert link.' );
		}

		$result = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
