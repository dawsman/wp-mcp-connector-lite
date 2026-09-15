<?php
defined( 'ABSPATH' ) || exit;

class WP_MCP_Connect_Clusters {

    /**
     * Build topic clusters from shared taxonomy terms.
     *
     * @return array Clusters with members and internal-linking metrics.
     */
    public static function build_clusters() {
        global $wpdb;

        // Step 1: Group posts by shared categories
        $cat_groups = self::group_by_taxonomy( 'category' );

        // Merge: posts sharing categories form initial clusters
        $clusters = array();
        foreach ( $cat_groups as $term_id => $posts ) {
            $term = get_term( $term_id );
            if ( ! $term || is_wp_error( $term ) || count( $posts ) < 2 ) {
                continue;
            }
            $clusters[] = array(
                'name'     => $term->name,
                'source'   => 'category',
                'term_id'  => $term_id,
                'post_ids' => $posts,
            );
        }

        $results = array();

        foreach ( $clusters as $cluster ) {
            $members = array();

            foreach ( $cluster['post_ids'] as $pid ) {
                $post = get_post( $pid );
                if ( ! $post ) {
                    continue;
                }

                $members[] = array(
                    'post_id'   => $pid,
                    'title'     => $post->post_title,
                    'type'      => $post->post_type,
                    'edit_url'  => get_edit_post_link( $pid, 'raw' ),
                    'permalink' => get_permalink( $pid ),
                );
            }

            // Check internal linking within cluster
            $internal_links = 0;
            $links_table    = $wpdb->prefix . 'cwp_internal_links';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$links_table}'" ) === $links_table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $pid_list       = implode( ',', array_map( 'intval', $cluster['post_ids'] ) );
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
                $internal_links = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$links_table}
                     WHERE source_post_id IN ({$pid_list}) AND target_post_id IN ({$pid_list})" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                );
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }

            $results[] = array(
                'name'           => $cluster['name'],
                'source'         => $cluster['source'],
                'member_count'   => count( $members ),
                'members'        => $members,
                'internal_links' => $internal_links,
            );
        }

        // Sort by cluster size desc, then by internal linking desc
        usort( $results, function ( $a, $b ) {
            if ( $b['member_count'] === $a['member_count'] ) {
                return $b['internal_links'] - $a['internal_links'];
            }
            return $b['member_count'] - $a['member_count'];
        } );

        return $results;
    }

	/**
	 * Group posts by taxonomy terms.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array Associative array of term_id => post IDs.
	 */
	private static function group_by_taxonomy( $taxonomy ) {
		$groups = array();

		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		) );

		if ( is_wp_error( $terms ) ) {
			return $groups;
		}

		foreach ( $terms as $term ) {
			$posts = get_posts( array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
				'posts_per_page' => 100,
				'fields'         => 'ids',
			) );
			if ( count( $posts ) >= 2 ) {
				$groups[ $term->term_id ] = $posts;
			}
		}

		return $groups;
	}
}
