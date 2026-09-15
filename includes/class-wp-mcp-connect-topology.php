<?php
defined( 'ABSPATH' ) || exit;

/**
 * Site topology — internal link graph.
 *
 * Maintains an adjacency table of internal links extracted from post content
 * and exposes REST endpoints for graph data, stats, and audit.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Topology {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Static cache for url_to_postid lookups.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var array
	 */
	private static $url_cache = array();

	/**
	 * DB version key for schema migrations.
	 *
	 * @since 1.0.0
	 */
	const DB_VERSION_OPTION = 'cwp_topology_db_version';

	/**
	 * Current schema version.
	 *
	 * @since 1.0.0
	 */
	const DB_VERSION = '1.0';

	/**
	 * Initialize the class.
	 *
	 * @since 1.0.0
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Get the fully qualified table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'cwp_internal_links';
	}

	/**
	 * Create the internal links table via dbDelta.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;
		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id bigint(20) unsigned NOT NULL,
			target_post_id bigint(20) unsigned NOT NULL,
			anchor_text varchar(500) DEFAULT '',
			context varchar(100) DEFAULT 'content',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_source (source_post_id),
			KEY idx_target (target_post_id),
			UNIQUE KEY idx_source_target_anchor (source_post_id, target_post_id, anchor_text(191))
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create table if the schema version has changed.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_create_table() {
		$installed = get_option( self::DB_VERSION_OPTION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_table();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	// =========================================================================
	// Link Parser
	// =========================================================================

	/**
	 * Parse internal links from a post's content.
	 *
	 * @since 1.0.0
	 * @param int $post_id Post ID to parse.
	 * @return array Array of [ 'target_post_id' => int, 'anchor_text' => string, 'url' => string ].
	 */
	public function parse_links( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$content = $post->post_content;
		if ( empty( $content ) ) {
			return array();
		}

		$links    = array();
		$site_url = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $matches as $match ) {
			$href        = trim( $match[1] );
			$anchor_text = wp_strip_all_tags( $match[2] );

			// Skip non-link hrefs.
			if ( empty( $href ) || '#' === $href[0] || 0 === strpos( $href, 'javascript:' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
				continue;
			}

			// Determine if internal.
			$is_internal = false;
			if ( '/' === $href[0] && ( strlen( $href ) < 2 || '/' !== $href[1] ) ) {
				$is_internal = true;
			} else {
				$parsed_host = wp_parse_url( $href, PHP_URL_HOST );
				if ( $parsed_host && strtolower( $parsed_host ) === strtolower( $site_url ) ) {
					$is_internal = true;
				}
			}

			if ( ! $is_internal ) {
				continue;
			}

			// Resolve target post ID (with static cache).
			if ( isset( self::$url_cache[ $href ] ) ) {
				$target_id = self::$url_cache[ $href ];
			} else {
				$target_id = url_to_postid( $href );
				if ( ! $target_id ) {
					$target_id = url_to_postid( rtrim( $href, '/' ) );
				}
				if ( ! $target_id ) {
					$target_id = url_to_postid( trailingslashit( $href ) );
				}
				self::$url_cache[ $href ] = $target_id;
			}

			if ( ! $target_id || (int) $target_id === (int) $post_id ) {
				continue;
			}

			$links[] = array(
				'target_post_id' => (int) $target_id,
				'anchor_text'    => mb_substr( $anchor_text, 0, 500 ),
				'url'            => $href,
			);
		}

		return $links;
	}

	/**
	 * Delete existing links for a post and insert freshly parsed ones.
	 *
	 * @since 1.0.0
	 * @param int $post_id Post ID to rebuild.
	 * @return void
	 */
	public function rebuild_post_links( $post_id ) {
		global $wpdb;
		$table = self::table_name();

		$wpdb->delete( $table, array( 'source_post_id' => (int) $post_id ), array( '%d' ) );

		$links = $this->parse_links( $post_id );
		foreach ( $links as $link ) {
			$wpdb->replace(
				$table,
				array(
					'source_post_id' => (int) $post_id,
					'target_post_id' => (int) $link['target_post_id'],
					'anchor_text'    => $link['anchor_text'],
					'context'        => 'content',
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Batched full rebuild of all internal links.
	 *
	 * @since 1.0.0
	 * @param int $offset     Offset to start from.
	 * @param int $batch_size Number of posts per batch.
	 * @return void
	 */
	public function rebuild_all( $offset = 0, $batch_size = 50 ) {
		global $wpdb;

		$public_types = get_post_types( array( 'public' => true ) );
		if ( empty( $public_types ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $public_types ), '%s' ) );
		$query_args   = array_values( $public_types );
		$query_args[] = (int) $batch_size;
		$query_args[] = (int) $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders) ORDER BY ID ASC LIMIT %d OFFSET %d",
				...$query_args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( empty( $posts ) ) {
			update_option( 'cwp_topology_last_rebuild', current_time( 'mysql' ) );
			delete_transient( 'cwp_topology_rebuilding' );
			return;
		}

		foreach ( $posts as $pid ) {
			$this->rebuild_post_links( (int) $pid );
		}

		set_transient( 'cwp_topology_rebuilding', true, 300 );
		wp_schedule_single_event( time() + 1, 'cwp_topology_rebuild_batch', array( $offset + $batch_size, $batch_size ) );
	}

	/**
	 * Incrementally update links when a post is saved.
	 *
	 * @since 1.0.0
	 * @param int $post_id Post ID being saved.
	 * @return void
	 */
	public function on_post_save( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$this->rebuild_post_links( $post_id );
	}

	// =========================================================================
	// Query Methods
	// =========================================================================

	/**
	 * Get full graph data: nodes, edges, and stats.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_graph_data() {
		global $wpdb;
		$table = self::table_name();

		// Get all edges.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$edges = $wpdb->get_results( "SELECT source_post_id AS source, target_post_id AS target, anchor_text FROM $table", ARRAY_A );

		// Collect all post IDs that appear in edges.
		$post_ids = array();
		foreach ( $edges as $edge ) {
			$post_ids[ (int) $edge['source'] ] = true;
			$post_ids[ (int) $edge['target'] ] = true;
		}

		if ( empty( $post_ids ) ) {
			return array(
				'nodes' => array(),
				'edges' => $edges,
				'stats' => $this->get_stats(),
			);
		}

		$ids          = array_keys( $post_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// Count outlinks per source.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$outlinks = $wpdb->get_results( $wpdb->prepare( "SELECT source_post_id AS pid, COUNT(*) AS cnt FROM $table GROUP BY source_post_id" ), ARRAY_A );
		$out_map  = array();
		foreach ( $outlinks as $row ) {
			$out_map[ (int) $row['pid'] ] = (int) $row['cnt'];
		}

		// Count inlinks per target.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inlinks = $wpdb->get_results( $wpdb->prepare( "SELECT target_post_id AS pid, COUNT(*) AS cnt FROM $table GROUP BY target_post_id" ), ARRAY_A );
		$in_map  = array();
		foreach ( $inlinks as $row ) {
			$in_map[ (int) $row['pid'] ] = (int) $row['cnt'];
		}

		// Build nodes.
		$nodes = array();
		foreach ( $ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$nodes[] = array(
				'id'          => (int) $pid,
				'title'       => get_the_title( $pid ),
				'type'        => $post->post_type,
				'url'         => get_permalink( $pid ),
				'inlinks'     => $in_map[ $pid ] ?? 0,
				'outlinks'    => $out_map[ $pid ] ?? 0,
			);
		}

		return array(
			'nodes' => $nodes,
			'edges' => $edges,
			'stats' => $this->get_stats(),
		);
	}

	/**
	 * Get inlinks and outlinks for a specific post.
	 *
	 * @since 1.0.0
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_post_links( $post_id ) {
		global $wpdb;
		$table = self::table_name();

		// Outlinks.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$outlinks_raw = $wpdb->get_results(
			$wpdb->prepare( "SELECT target_post_id, anchor_text FROM $table WHERE source_post_id = %d", $post_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$outlinks = array();
		foreach ( $outlinks_raw as $row ) {
			$outlinks[] = array(
				'post_id'     => (int) $row['target_post_id'],
				'title'       => get_the_title( (int) $row['target_post_id'] ),
				'url'         => get_permalink( (int) $row['target_post_id'] ),
				'anchor_text' => $row['anchor_text'],
			);
		}

		// Inlinks.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$inlinks_raw = $wpdb->get_results(
			$wpdb->prepare( "SELECT source_post_id, anchor_text FROM $table WHERE target_post_id = %d", $post_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inlinks = array();
		foreach ( $inlinks_raw as $row ) {
			$inlinks[] = array(
				'post_id'     => (int) $row['source_post_id'],
				'title'       => get_the_title( (int) $row['source_post_id'] ),
				'url'         => get_permalink( (int) $row['source_post_id'] ),
				'anchor_text' => $row['anchor_text'],
			);
		}

		return array(
			'post_id'  => (int) $post_id,
			'title'    => get_the_title( $post_id ),
			'url'      => get_permalink( $post_id ),
			'outlinks' => $outlinks,
			'inlinks'  => $inlinks,
		);
	}

	/**
	 * Get topology statistics.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

		// Total unique nodes (union of sources and targets).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_nodes = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT pid) FROM (SELECT source_post_id AS pid FROM $table UNION SELECT target_post_id AS pid FROM $table) AS all_nodes" );

		// Orphan pages: published posts with zero inlinks.
		$public_types     = get_post_types( array( 'public' => true ) );
		$type_placeholder = implode( ', ', array_fill( 0, count( $public_types ), '%s' ) );
		$type_values      = array_values( $public_types );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$orphan_pages = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type IN ($type_placeholder) AND p.ID NOT IN (SELECT DISTINCT target_post_id FROM $table)",
				...$type_values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// Dead ends: published posts with zero outlinks.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$dead_ends = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type IN ($type_placeholder) AND p.ID NOT IN (SELECT DISTINCT source_post_id FROM $table)",
				...$type_values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array(
			'total_links'  => $total_links,
			'total_nodes'  => $total_nodes,
			'orphan_pages' => $orphan_pages,
			'dead_ends'    => $dead_ends,
			'last_rebuild' => get_option( 'cwp_topology_last_rebuild', null ),
			'is_building'  => (bool) get_transient( 'cwp_topology_rebuilding' ),
		);
	}

	/**
	 * Get audit data — posts with link counts, filterable.
	 *
	 * @since 1.0.0
	 * @param string $filter   Filter: 'all', 'orphans', 'dead_ends'.
	 * @param int    $page     Page number.
	 * @param int    $per_page Per page count.
	 * @return array
	 */
	public function get_audit( $filter = 'all', $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table = self::table_name();

		$public_types     = get_post_types( array( 'public' => true ) );
		$type_placeholder = implode( ', ', array_fill( 0, count( $public_types ), '%s' ) );
		$type_values      = array_values( $public_types );

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where_extra = '';
		if ( 'orphans' === $filter ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where_extra = " AND p.ID NOT IN (SELECT DISTINCT target_post_id FROM $table)";
		} elseif ( 'dead_ends' === $filter ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where_extra = " AND p.ID NOT IN (SELECT DISTINCT source_post_id FROM $table)";
		}

		$query_args   = $type_values;
		$query_args[] = (int) $per_page;
		$query_args[] = (int) $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type IN ($type_placeholder) $where_extra ORDER BY p.ID DESC LIMIT %d OFFSET %d",
				...$query_args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// Count total for pagination.
		$count_args = $type_values;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type IN ($type_placeholder) $where_extra",
				...$count_args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// Collect link counts for this page of posts in two grouped queries
		// instead of two COUNT(*) queries per row.
		$in_counts  = array();
		$out_counts = array();
		$page_ids   = array_map( 'intval', wp_list_pluck( $posts, 'ID' ) );
		if ( ! empty( $page_ids ) ) {
			$id_placeholder = implode( ', ', array_fill( 0, count( $page_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
			$in_rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT target_post_id AS pid, COUNT(*) AS c FROM $table WHERE target_post_id IN ($id_placeholder) GROUP BY target_post_id", ...$page_ids ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
			$out_rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT source_post_id AS pid, COUNT(*) AS c FROM $table WHERE source_post_id IN ($id_placeholder) GROUP BY source_post_id", ...$page_ids ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			foreach ( (array) $in_rows as $r ) {
				$in_counts[ (int) $r['pid'] ] = (int) $r['c'];
			}
			foreach ( (array) $out_rows as $r ) {
				$out_counts[ (int) $r['pid'] ] = (int) $r['c'];
			}
		}

		$results = array();
		foreach ( $posts as $row ) {
			$pid = (int) $row['ID'];

			$results[] = array(
				'id'       => $pid,
				'title'    => $row['post_title'],
				'type'     => $row['post_type'],
				'url'      => get_permalink( $pid ),
				'inlinks'  => isset( $in_counts[ $pid ] ) ? $in_counts[ $pid ] : 0,
				'outlinks' => isset( $out_counts[ $pid ] ) ? $out_counts[ $pid ] : 0,
			);
		}

		return array(
			'posts'    => $results,
			'total'    => $total,
			'page'     => (int) $page,
			'per_page' => (int) $per_page,
		);
	}

	// =========================================================================
	// Crawl Depth Analysis
	// =========================================================================

	/**
	 * Compute crawl depth for all pages via BFS from homepage.
	 *
	 * @since 1.0.0
	 * @return array ['distribution' => [...], 'deep_pages' => [...], 'unreachable' => [...]]
	 */
	public function analyze_crawl_depth() {
		global $wpdb;
		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array( 'error' => 'Link graph not built yet. Run a topology rebuild first.' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$edge_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $edge_count > 50000 ) {
			return array( 'error' => 'Link graph too large for in-memory analysis (' . number_format( $edge_count ) . ' edges). Consider analyzing subsets via the audit endpoint.' );
		}

		// Build adjacency list from DB
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$edges = $wpdb->get_results(
			"SELECT source_post_id, target_post_id FROM {$table}",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$adjacency = array();
		foreach ( $edges as $edge ) {
			$src = (int) $edge['source_post_id'];
			$tgt = (int) $edge['target_post_id'];
			if ( ! isset( $adjacency[ $src ] ) ) {
				$adjacency[ $src ] = array();
			}
			$adjacency[ $src ][] = $tgt;
		}

		// Find homepage post ID
		$front_page_id = (int) get_option( 'page_on_front', 0 );
		if ( ! $front_page_id ) {
			// If using "Your latest posts" as homepage, use the blog page or find by URL
			$front_page_id = (int) get_option( 'page_for_posts', 0 );
		}

		// BFS
		$depths = array();
		$queue  = array();

		if ( $front_page_id ) {
			$depths[ $front_page_id ] = 0;
			$queue[]                  = $front_page_id;
		}

		// Also seed with any post that has no inbound links but is in the graph
		// (effectively a root node)

		$head = 0;
		while ( $head < count( $queue ) ) {
			$current       = $queue[ $head++ ];
			$current_depth = $depths[ $current ];

			if ( isset( $adjacency[ $current ] ) ) {
				foreach ( $adjacency[ $current ] as $neighbor ) {
					if ( ! isset( $depths[ $neighbor ] ) ) {
						$depths[ $neighbor ] = $current_depth + 1;
						$queue[]             = $neighbor;
					}
				}
			}
		}

		// Get all published posts to find unreachable ones
		$post_types   = get_post_types( array( 'public' => true ), 'names' );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$all_posts = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
			...array_values( $post_types )
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// Build distribution
		$distribution = array();
		$deep_pages   = array();

		foreach ( $all_posts as $pid ) {
			$pid   = (int) $pid;
			$depth = isset( $depths[ $pid ] ) ? $depths[ $pid ] : null;

			if ( null === $depth ) {
				continue; // unreachable — handled below
			}

			$bucket = $depth <= 3 ? (string) $depth : '4+';
			if ( ! isset( $distribution[ $bucket ] ) ) {
				$distribution[ $bucket ] = 0;
			}
			$distribution[ $bucket ]++;

			if ( $depth >= 4 ) {
				$post         = get_post( $pid );
				$deep_pages[] = array(
					'post_id'   => $pid,
					'title'     => $post ? $post->post_title : "Post #{$pid}",
					'type'      => $post ? $post->post_type : 'unknown',
					'depth'     => $depth,
					'edit_url'  => get_edit_post_link( $pid, 'raw' ),
					'permalink' => get_permalink( $pid ),
				);
			}
		}

		// Unreachable pages (not reachable from homepage via BFS)
		$unreachable = array();
		foreach ( $all_posts as $pid ) {
			$pid = (int) $pid;
			if ( ! isset( $depths[ $pid ] ) ) {
				$post          = get_post( $pid );
				$unreachable[] = array(
					'post_id'   => $pid,
					'title'     => $post ? $post->post_title : "Post #{$pid}",
					'type'      => $post ? $post->post_type : 'unknown',
					'edit_url'  => get_edit_post_link( $pid, 'raw' ),
					'permalink' => get_permalink( $pid ),
				);
			}
		}

		// Sort deep pages by depth desc, then by title
		usort( $deep_pages, function ( $a, $b ) {
			if ( $a['depth'] !== $b['depth'] ) {
				return $b['depth'] - $a['depth'];
			}
			return strcmp( $a['title'], $b['title'] );
		} );

		// Sort distribution keys
		ksort( $distribution );

		return array(
			'homepage_id'       => $front_page_id,
			'distribution'      => $distribution,
			'deep_pages'        => array_slice( $deep_pages, 0, 50 ),
			'unreachable'       => array_slice( $unreachable, 0, 50 ),
			'total_pages'       => count( $all_posts ),
			'reachable'         => count( $depths ),
			'unreachable_count' => count( $all_posts ) - count( $depths ),
		);
	}

	// =========================================================================
	// Link Equity (Simplified PageRank)
	// =========================================================================

	/**
	 * Calculate internal authority scores (simplified PageRank).
	 *
	 * @since 1.0.0
	 * @param int   $iterations Number of iterations (default 20).
	 * @param float $damping    Damping factor (default 0.85).
	 * @return array Sorted list of pages with authority scores.
	 */
	public function calculate_link_equity( $iterations = 20, $damping = 0.85 ) {
		global $wpdb;
		$table = self::table_name();

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array( 'error' => 'Link graph not built yet.' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		$edge_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $edge_count > 50000 ) {
			return array( 'error' => 'Link graph too large for in-memory analysis (' . number_format( $edge_count ) . ' edges). Consider analyzing subsets via the audit endpoint.' );
		}

		// Build adjacency.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$edges    = $wpdb->get_results( "SELECT source_post_id, target_post_id FROM {$table}", ARRAY_A );
		$outlinks = array(); // source -> [targets]
		$inlinks  = array(); // target -> [sources]
		$all_ids  = array();

		foreach ( $edges as $e ) {
			$src = (int) $e['source_post_id'];
			$tgt = (int) $e['target_post_id'];
			$outlinks[ $src ][] = $tgt;
			$inlinks[ $tgt ][]  = $src;
			$all_ids[ $src ]    = true;
			$all_ids[ $tgt ]    = true;
		}

		$n = count( $all_ids );
		if ( 0 === $n ) {
			return array( 'pages' => array(), 'total_pages' => 0 );
		}

		// Initialize scores.
		$scores = array();
		foreach ( $all_ids as $id => $_ ) {
			$scores[ $id ] = 1.0 / $n;
		}

		// Iterate.
		for ( $i = 0; $i < $iterations; $i++ ) {
			$new_scores = array();
			foreach ( $all_ids as $id => $_ ) {
				$sum = 0;
				if ( isset( $inlinks[ $id ] ) ) {
					foreach ( $inlinks[ $id ] as $src ) {
						$out_count = isset( $outlinks[ $src ] ) ? count( $outlinks[ $src ] ) : 1;
						$sum      += $scores[ $src ] / $out_count;
					}
				}
				$new_scores[ $id ] = ( 1 - $damping ) / $n + $damping * $sum;
			}
			$scores = $new_scores;
		}

		// Normalize to 0-100.
		$max     = max( $scores ) ?: 1;
		$results = array();
		foreach ( $scores as $id => $score ) {
			$normalized = round( ( $score / $max ) * 100, 1 );
			$post       = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$results[] = array(
				'post_id'   => $id,
				'title'     => $post->post_title,
				'type'      => $post->post_type,
				'authority' => $normalized,
				'inlinks'   => isset( $inlinks[ $id ] ) ? count( $inlinks[ $id ] ) : 0,
				'outlinks'  => isset( $outlinks[ $id ] ) ? count( $outlinks[ $id ] ) : 0,
				'edit_url'  => get_edit_post_link( $id, 'raw' ),
			);
		}

		usort( $results, function ( $a, $b ) {
			return $b['authority'] <=> $a['authority'];
		} );

		return array(
			'pages'       => array_slice( $results, 0, 100 ),
			'total_pages' => count( $results ),
		);
	}

	// =========================================================================
	// REST Endpoints
	// =========================================================================

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'mcp/v1',
			'/topology/graph',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_graph' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'mcp/v1',
			'/topology/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'mcp/v1',
			'/topology/post/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_post_links' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'mcp/v1',
			'/topology/rebuild',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_trigger_rebuild' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'mcp/v1',
			'/topology/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_audit' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'filter'   => array(
						'type'    => 'string',
						'default' => 'all',
						'enum'    => array( 'all', 'orphans', 'dead_ends' ),
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'mcp/v1',
			'/topology/crawl-depth',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_crawl_depth' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'mcp/v1',
			'/topology/link-equity',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_link_equity' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission check — require manage_options.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function check_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
	}

	/**
	 * REST: get graph data.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public function rest_get_graph() {
		return rest_ensure_response( $this->get_graph_data() );
	}

	/**
	 * REST: get stats.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public function rest_get_stats() {
		return rest_ensure_response( $this->get_stats() );
	}

	/**
	 * REST: get post links.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function rest_get_post_links( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_REST_Response( array( 'error' => 'Post not found' ), 404 );
		}

		return rest_ensure_response( $this->get_post_links( $post_id ) );
	}

	/**
	 * REST: trigger a full rebuild.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public function rest_trigger_rebuild() {
		if ( get_transient( 'cwp_topology_rebuilding' ) ) {
			return rest_ensure_response( array(
				'status'  => 'already_running',
				'message' => 'A rebuild is already in progress.',
			) );
		}

		set_transient( 'cwp_topology_rebuilding', true, 600 );
		wp_schedule_single_event( time(), 'cwp_topology_rebuild_batch', array( 0, 50 ) );

		return rest_ensure_response( array(
			'status'  => 'started',
			'message' => 'Link graph rebuild started. This runs in the background.',
		) );
	}

	/**
	 * REST: get audit data.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function rest_get_audit( $request ) {
		$filter   = $request->get_param( 'filter' ) ?: 'all';
		$page     = (int) ( $request->get_param( 'page' ) ?: 1 );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?: 20 );

		$allowed_filters = array( 'all', 'orphans', 'dead_ends' );
		if ( ! in_array( $filter, $allowed_filters, true ) ) {
			$filter = 'all';
		}

		return rest_ensure_response( $this->get_audit( $filter, $page, $per_page ) );
	}

	/**
	 * REST: get crawl depth analysis.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public function rest_get_crawl_depth() {
		return rest_ensure_response( $this->analyze_crawl_depth() );
	}

	/**
	 * REST: get link equity scores.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public function rest_get_link_equity() {
		return rest_ensure_response( $this->calculate_link_equity() );
	}
}
