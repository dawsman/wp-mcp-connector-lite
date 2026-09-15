<?php
defined( 'ABSPATH' ) || exit;

/**
 * Extended media functionality for WP MCP Connect.
 *
 * Image duplicate/size reporting is served from an index held in post meta
 * ( _cwp_file_hash and _cwp_file_size ) rather than by scanning the uploads
 * directory on every request. The index is written when an attachment is
 * added or changed, and back-filled for existing libraries by an hourly
 * WP-Cron batch.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Media_Extended {

	/**
	 * Meta key holding the md5 hash of the original file.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const META_HASH = '_cwp_file_hash';

	/**
	 * Meta key holding the size of the original file in bytes.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const META_SIZE = '_cwp_file_size';

	/**
	 * Cron hook that back-fills the index.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const CRON_HOOK = 'cwp_media_index_event';

	/**
	 * Number of attachments indexed per back-fill batch.
	 *
	 * @since    1.0.0
	 * @var      int
	 */
	const BATCH_SIZE = 200;

	/**
	 * Files larger than this are sized but not hashed ( 50 MB ).
	 *
	 * Hashing a very large file is slow and duplicate originals of that size
	 * are vanishingly rare, so the hash is stored as an empty string. The
	 * empty value keeps the attachment out of the back-fill queue without
	 * ever matching another file in the duplicate grouping, which excludes
	 * empty hashes explicitly.
	 *
	 * @since    1.0.0
	 * @var      int
	 */
	const MAX_HASH_BYTES = 52428800;

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
		register_rest_route( 'mcp/v1', '/media/oversized', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_oversized_images' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'threshold' => array(
					'type'              => 'integer',
					'default'           => 2097152,
					'sanitize_callback' => 'absint',
				),
				'page' => array(
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
		) );

		register_rest_route( 'mcp/v1', '/media/duplicates', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_duplicate_images' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'page' => array(
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
		) );

		register_rest_route( 'mcp/v1', '/media/index-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_index_status' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/media/index-run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'run_index_batch_request' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'mcp/v1', '/media/bulk-alt-update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_update_alt_text' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'updates' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => 'integer' ),
							'alt_text' => array( 'type' => 'string' ),
						),
					),
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
		return WP_MCP_Connect_Auth::check_capability( 'upload_files' );
	}

	/**
	 * Check if user may run maintenance operations.
	 *
	 * @since    1.0.0
	 * @return   bool|WP_Error
	 */
	public function check_admin_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'manage_options' );
	}

	/**
	 * Schedule the index back-fill cron event when it is not already queued.
	 *
	 * Called from the activator and again on every `init` so an install whose
	 * schedule was lost ( cron table wiped, plugin copied between sites ) heals
	 * itself without a reactivation.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function maybe_schedule_index() {
		$hook = self::CRON_HOOK;
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + 300, 'hourly', $hook );
		}
	}

	/**
	 * Cron callback: index one batch of un-indexed images.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function handle_index_cron() {
		$this->run_index_batch();
	}

	/**
	 * Record the hash and size of an attachment's original file.
	 *
	 * Non-image attachments are ignored. When the file is missing both meta
	 * values are removed so stale index entries never surface in a report.
	 *
	 * @since    1.0.0
	 * @param    int    $attachment_id    The attachment post ID.
	 * @return   void
	 */
	public function index_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return;
		}

		$mime = get_post_mime_type( $attachment_id );

		if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
			return;
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
			delete_post_meta( $attachment_id, self::META_HASH );
			delete_post_meta( $attachment_id, self::META_SIZE );
			return;
		}

		$size = filesize( $file );

		if ( false === $size ) {
			delete_post_meta( $attachment_id, self::META_HASH );
			delete_post_meta( $attachment_id, self::META_SIZE );
			return;
		}

		update_post_meta( $attachment_id, self::META_SIZE, (int) $size );

		if ( $size > self::MAX_HASH_BYTES ) {
			update_post_meta( $attachment_id, self::META_HASH, '' );
			return;
		}

		$hash = md5_file( $file );
		update_post_meta( $attachment_id, self::META_HASH, false === $hash ? '' : $hash );
	}

	/**
	 * Index an attachment once its generated metadata is saved.
	 *
	 * Hooked to the `wp_update_attachment_metadata` filter, which is the last
	 * step of the upload pipeline and therefore the point at which the original
	 * file is final. WordPress's big-image handling rewrites the original after
	 * `add_attachment` fires, so hashing on that hook alone can hash a file that
	 * is about to be replaced.
	 *
	 * @since    1.0.0
	 * @param    array    $data             Attachment metadata.
	 * @param    int      $attachment_id    The attachment post ID.
	 * @return   array                      The unmodified metadata.
	 */
	public function index_attachment_metadata( $data, $attachment_id ) {
		$this->index_attachment( $attachment_id );

		return $data;
	}

	/**
	 * Index up to BATCH_SIZE image attachments that have no hash recorded.
	 *
	 * @since    1.0.0
	 * @return   int    Number of attachments processed.
	 */
	public function run_index_batch() {
		$query = new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => self::BATCH_SIZE,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'meta_query'             => array(
				array(
					'key'     => self::META_HASH,
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		$ids = $query->posts;

		if ( empty( $ids ) ) {
			return 0;
		}

		foreach ( $ids as $attachment_id ) {
			$this->index_attachment( $attachment_id );

			// A missing or unreadable file leaves no hash behind, which would
			// put the attachment back at the front of the next batch forever.
			// Record an empty hash so the queue drains; the add/edit hooks
			// overwrite it with a real hash if the file ever reappears.
			if ( ! metadata_exists( 'post', $attachment_id, self::META_HASH ) ) {
				update_post_meta( $attachment_id, self::META_HASH, '' );
			}
		}

		return count( $ids );
	}

	/**
	 * REST callback: run one back-fill batch immediately.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function run_index_batch_request( $request ) {
		$processed = $this->run_index_batch();

		$status = $this->get_index_stats();
		$status['processed'] = $processed;
		$status['batch_size'] = self::BATCH_SIZE;

		return $status;
	}

	/**
	 * REST callback: report index progress.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_index_status( $request ) {
		$status = $this->get_index_stats();

		$next_run = wp_next_scheduled( self::CRON_HOOK );

		$status['batch_size'] = self::BATCH_SIZE;
		$status['cron_scheduled'] = (bool) $next_run;
		$status['next_run'] = $next_run ? (int) $next_run : null;
		$status['next_run_gmt'] = $next_run ? gmdate( 'Y-m-d H:i:s', $next_run ) : null;

		return $status;
	}

	/**
	 * Count indexed versus total image attachments.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   array
	 */
	private function get_index_stats() {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts} p
				WHERE p.post_type = %s
				AND p.post_mime_type LIKE %s",
				'attachment',
				'image/%'
			)
		);

		$indexed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_mime_type LIKE %s",
				self::META_HASH,
				'attachment',
				'image/%'
			)
		);

		$unindexed = max( 0, $total - $indexed );

		return array(
			'total_images'   => $total,
			'indexed'        => $indexed,
			'unindexed'      => $unindexed,
			'index_complete' => ( 0 === $unindexed ),
		);
	}

	/**
	 * Get images larger than threshold.
	 *
	 * Served from the `_cwp_file_size` index; results are only complete once
	 * the back-fill has finished, which the response reports.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_oversized_images( $request ) {
		global $wpdb;

		$threshold = absint( $request->get_param( 'threshold' ) );
		$page = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$offset = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->postmeta} sm
				INNER JOIN {$wpdb->posts} p ON p.ID = sm.post_id
				WHERE sm.meta_key = %s
				AND CAST( sm.meta_value AS UNSIGNED ) >= %d",
				self::META_SIZE,
				$threshold
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, sm.meta_value AS file_size, fm.meta_value AS file_path
				FROM {$wpdb->postmeta} sm
				INNER JOIN {$wpdb->posts} p ON p.ID = sm.post_id
				LEFT JOIN {$wpdb->postmeta} fm ON fm.post_id = p.ID AND fm.meta_key = %s
				WHERE sm.meta_key = %s
				AND CAST( sm.meta_value AS UNSIGNED ) >= %d
				ORDER BY CAST( sm.meta_value AS UNSIGNED ) DESC, p.ID ASC
				LIMIT %d OFFSET %d",
				'_wp_attached_file',
				self::META_SIZE,
				$threshold,
				$per_page,
				$offset
			)
		);

		$images = array();

		foreach ( $rows as $row ) {
			$images[] = array(
				'id'        => (int) $row->ID,
				'title'     => $row->post_title,
				'file_path' => null === $row->file_path ? '' : $row->file_path,
				'file_size' => (int) $row->file_size,
				'url'       => wp_get_attachment_url( $row->ID ),
			);
		}

		$stats = $this->get_index_stats();

		return array(
			'images'         => $images,
			'total'          => $total,
			'page'           => $page,
			'per_page'       => $per_page,
			'total_pages'    => (int) ceil( $total / $per_page ),
			'threshold'      => $threshold,
			'index_complete' => $stats['index_complete'],
			'unindexed'      => $stats['unindexed'],
		);
	}

	/**
	 * Find duplicate images based on file hash.
	 *
	 * Grouping happens in SQL against the `_cwp_file_hash` index. Empty hashes
	 * ( files too large to hash, or missing ) are excluded so they never form a
	 * bogus group.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array
	 */
	public function get_duplicate_images( $request ) {
		global $wpdb;

		$page = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$offset = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT pm.meta_value
					FROM {$wpdb->postmeta} pm
					WHERE pm.meta_key = %s
					AND pm.meta_value != ''
					GROUP BY pm.meta_value
					HAVING COUNT(*) > 1
				) AS groups_count",
				self::META_HASH
			)
		);

		$groups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS hash, COUNT(*) AS c
				FROM {$wpdb->postmeta} pm
				WHERE pm.meta_key = %s
				AND pm.meta_value != ''
				GROUP BY pm.meta_value
				HAVING c > 1
				ORDER BY c DESC, pm.meta_value ASC
				LIMIT %d OFFSET %d",
				self::META_HASH,
				$per_page,
				$offset
			)
		);

		$duplicates = array();

		if ( ! empty( $groups ) ) {
			$hashes = wp_list_pluck( $groups, 'hash' );
			$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );

			$args = array_merge(
				array( '_wp_attached_file', self::META_HASH ),
				$hashes
			);

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_value AS hash, p.ID, p.post_title, fm.meta_value AS file_path
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					LEFT JOIN {$wpdb->postmeta} fm ON fm.post_id = p.ID AND fm.meta_key = %s
					WHERE pm.meta_key = %s
					AND pm.meta_value IN ( {$placeholders} )
					ORDER BY p.ID ASC",
					$args
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$by_hash = array();

			foreach ( $rows as $row ) {
				if ( ! isset( $by_hash[ $row->hash ] ) ) {
					$by_hash[ $row->hash ] = array();
				}

				$by_hash[ $row->hash ][] = array(
					'id'        => (int) $row->ID,
					'title'     => $row->post_title,
					'file_path' => null === $row->file_path ? '' : $row->file_path,
					'url'       => wp_get_attachment_url( $row->ID ),
				);
			}

			foreach ( $groups as $group ) {
				$images = isset( $by_hash[ $group->hash ] ) ? $by_hash[ $group->hash ] : array();

				$duplicates[] = array(
					'hash'   => $group->hash,
					'count'  => count( $images ),
					'images' => $images,
				);
			}
		}

		$stats = $this->get_index_stats();

		return array(
			'duplicate_groups' => $duplicates,
			'total'            => $total,
			'page'             => $page,
			'per_page'         => $per_page,
			'total_pages'      => (int) ceil( $total / $per_page ),
			'index_complete'   => $stats['index_complete'],
			'unindexed'        => $stats['unindexed'],
		);
	}

	/**
	 * Bulk update alt text for multiple images.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request    $request    The request object.
	 * @return   array|WP_Error
	 */
	public function bulk_update_alt_text( $request ) {
		$updates = $request->get_param( 'updates' );

		if ( count( $updates ) > 50 ) {
			return new WP_Error(
				'too_many_updates',
				__( 'Maximum 50 updates per request.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $updates as $update ) {
			if ( empty( $update['id'] ) ) {
				$results['failed'][] = array(
					'id'     => null,
					'reason' => 'Missing ID',
				);
				continue;
			}

			$id = absint( $update['id'] );
			$attachment = get_post( $id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				$results['failed'][] = array(
					'id'     => $id,
					'reason' => 'Attachment not found',
				);
				continue;
			}

			if ( ! current_user_can( 'edit_post', $id ) ) {
				$results['failed'][] = array(
					'id'     => $id,
					'reason' => 'Permission denied',
				);
				continue;
			}

			$alt_text = isset( $update['alt_text'] ) ? sanitize_text_field( $update['alt_text'] ) : '';
			update_post_meta( $id, '_wp_attachment_image_alt', $alt_text );

			$results['success'][] = array(
				'id'       => $id,
				'alt_text' => $alt_text,
			);
		}

		delete_transient( 'cwp_media_missing_alt_results' );
		delete_transient( 'cwp_media_missing_alt_total' );

		return array(
			'updated' => count( $results['success'] ),
			'failed'  => count( $results['failed'] ),
			'results' => $results,
		);
	}
}
