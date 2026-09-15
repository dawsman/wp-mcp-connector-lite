<?php
defined( 'ABSPATH' ) || exit;
/**
 * Content creation handler for WP MCP Connect.
 *
 * @package    WP_MCP_Connect
 * @since      1.0.0
 */

/**
 * Handles content creation for WP MCP Connect.
 *
 * @since      1.0.0
 * @package    WP_MCP_Connect
 */
class WP_MCP_Connect_Content {

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
	 * @param    string                $plugin_name    The name of the plugin.
	 * @param    string                $version        The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register REST API routes.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function register_routes() {
		register_rest_route(
			'mcp/v1',
			'/content/create',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_content' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'title'             => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'content'           => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
					'post_type'         => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'post',
						'sanitize_callback' => 'sanitize_key',
					),
					'categories'        => array(
						'required' => false,
						'type'     => 'array',
						'default'  => array(),
						'items'    => array( 'type' => 'integer' ),
					),
					'tags'              => array(
						'required' => false,
						'type'     => 'array',
						'default'  => array(),
						'items'    => array( 'type' => 'integer' ),
					),
					'featured_image_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'seo_title'         => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'seo_description'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'excerpt'           => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			'mcp/v1',
			'/content/upload-image',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_image' ),
				'permission_callback' => array( $this, 'check_upload_permission' ),
				'args'                => array(
					'url'         => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'base64'      => array(
						'required' => false,
						'type'     => 'string',
					),
					'filename'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_file_name',
					),
					'alt_text'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'title'       => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			'mcp/v1',
			'/content/delete',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_content' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'    => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'force' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);
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
	 * Check if user has permission to upload files.
	 *
	 * @since    1.0.0
	 * @return   bool    True if user can upload files.
	 */
	public function check_upload_permission() {
		return WP_MCP_Connect_Auth::check_capability( 'upload_files' );
	}

	/**
	 * Create a new post/page with metadata.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request $request    The REST request object.
	 * @return   array|WP_Error                 Created post data or error.
	 */
	public function create_content( $request ) {
		$title             = $request->get_param( 'title' );
		$content           = $request->get_param( 'content' );
		$post_type         = $request->get_param( 'post_type' );
		$categories        = $request->get_param( 'categories' );
		$tags              = $request->get_param( 'tags' );
		$featured_image_id = $request->get_param( 'featured_image_id' );
		$seo_title         = $request->get_param( 'seo_title' );
		$seo_description   = $request->get_param( 'seo_description' );
		$excerpt           = $request->get_param( 'excerpt' );

		$public_types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! in_array( $post_type, $public_types, true ) ) {
			return new WP_Error(
				'invalid_post_type',
				__( 'Invalid or non-public post type.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		$post_type_obj = get_post_type_object( $post_type );
		$create_cap = $post_type_obj && isset( $post_type_obj->cap )
			? ( $post_type_obj->cap->create_posts ?? $post_type_obj->cap->edit_posts )
			: '';
		if ( empty( $create_cap ) || ! current_user_can( $create_cap ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to create this type of content.', 'wp-mcp-connect' ),
				array( 'status' => 403 )
			);
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => wp_kses_post( $content ),
			'post_status'  => 'draft',
			'post_type'    => $post_type,
		);

		if ( ! empty( $excerpt ) ) {
			$post_data['post_excerpt'] = $excerpt;
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! empty( $categories ) && 'post' === $post_type ) {
			$category_ids = array_map( 'absint', $categories );
			wp_set_post_categories( $post_id, $category_ids );
		}

		if ( ! empty( $tags ) && 'post' === $post_type ) {
			$tag_ids = array_map( 'absint', $tags );
			wp_set_post_tags( $post_id, $tag_ids );
		}

		if ( ! empty( $featured_image_id ) ) {
			set_post_thumbnail( $post_id, $featured_image_id );
		}

		if ( ! empty( $seo_title ) ) {
			update_post_meta( $post_id, '_cwp_seo_title', $seo_title );
		}

		if ( ! empty( $seo_description ) ) {
			update_post_meta( $post_id, '_cwp_seo_description', $seo_description );
		}

		$post = get_post( $post_id );

		return array(
			'id'          => $post_id,
			'title'       => $post->post_title,
			'status'      => $post->post_status,
			'post_type'   => $post->post_type,
			'url'         => get_permalink( $post_id ),
			'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
			'preview_url' => get_preview_post_link( $post_id ),
		);
	}

	/**
	 * Delete a post or page.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request $request    The REST request object.
	 * @return   array|WP_Error                 Deletion result or error.
	 */
	public function delete_content( $request ) {
		$id    = $request->get_param( 'id' );
		$force = $request->get_param( 'force' );

		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'Post not found.', 'wp-mcp-connect' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to delete this content.', 'wp-mcp-connect' ),
				array( 'status' => 403 )
			);
		}

		$post_type       = $post->post_type;
		$title           = $post->post_title;
		$previous_status = $post->post_status;

		$result = wp_delete_post( $id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete content.', 'wp-mcp-connect' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'   => true,
				'deleted'   => true,
				'id'        => $id,
				'post_type' => $post_type,
				'previous'  => array(
					'id'     => $id,
					'title'  => $title,
					'status' => $previous_status,
				),
			)
		);
	}

	/**
	 * Upload an image from URL or base64.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Request $request    The REST request object.
	 * @return   array|WP_Error                 Uploaded image data or error.
	 */
	public function upload_image( $request ) {
		$url         = $request->get_param( 'url' );
		$base64      = $request->get_param( 'base64' );
		$filename    = $request->get_param( 'filename' );
		$alt_text    = $request->get_param( 'alt_text' );
		$title       = $request->get_param( 'title' );
		$description = $request->get_param( 'description' );

		if ( empty( $url ) && empty( $base64 ) ) {
			return new WP_Error(
				'missing_image_data',
				__( 'Either url or base64 parameter is required.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( ! empty( $url ) ) {
			$attachment_id = $this->upload_from_url( $url, $filename );
		} else {
			$attachment_id = $this->upload_from_base64( $base64, $filename );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( ! empty( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		if ( ! empty( $title ) || ! empty( $description ) ) {
			$update_data = array( 'ID' => $attachment_id );
			if ( ! empty( $title ) ) {
				$update_data['post_title'] = $title;
			}
			if ( ! empty( $description ) ) {
				$update_data['post_content'] = $description;
			}
			wp_update_post( $update_data );
		}

		$attachment = get_post( $attachment_id );
		$metadata   = wp_get_attachment_metadata( $attachment_id );

		return array(
			'id'        => $attachment_id,
			'url'       => wp_get_attachment_url( $attachment_id ),
			'title'     => $attachment->post_title,
			'alt_text'  => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'filename'  => basename( get_attached_file( $attachment_id ) ),
			'mime_type' => $attachment->post_mime_type,
			'width'     => isset( $metadata['width'] ) ? $metadata['width'] : null,
			'height'    => isset( $metadata['height'] ) ? $metadata['height'] : null,
			'sizes'     => isset( $metadata['sizes'] ) ? array_keys( $metadata['sizes'] ) : array(),
		);
	}

	/**
	 * Validate URL is safe to download (prevents SSRF attacks).
	 *
	 * @since    1.0.0
	 * @param    string $url    The URL to validate.
	 * @return   bool|WP_Error     True if safe, WP_Error if unsafe.
	 */
	private function validate_url_for_download( $url ) {
		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Invalid URL format.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		// Only allow HTTPS for security.
		if ( 'https' !== strtolower( $parsed['scheme'] ) ) {
			return new WP_Error(
				'insecure_url',
				__( 'Only HTTPS URLs are allowed for security.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		$host = strtolower( $parsed['host'] );

		// Block localhost and loopback addresses.
		$blocked_hosts = array( 'localhost', '127.0.0.1', '0.0.0.0', '::1' );
		if ( in_array( $host, $blocked_hosts, true ) ) {
			return new WP_Error(
				'blocked_host',
				__( 'Downloads from localhost are not allowed.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		// Block private/internal IP ranges.
		$ip = gethostbyname( $host );
		if ( $ip !== $host ) {
			// Block private IPv4 ranges (RFC 1918).
			if ( preg_match( '/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|127\.|0\.|169\.254\.)/', $ip ) ) {
				return new WP_Error(
					'private_ip',
					__( 'Downloads from private/internal IP addresses are not allowed.', 'wp-mcp-connect' ),
					array( 'status' => 400 )
				);
			}
		}

		// Block cloud metadata endpoints.
		$blocked_hosts_patterns = array(
			'metadata.google.internal',
			'169.254.169.254',
			'metadata.azure.com',
			'169.254.170.2',
		);
		foreach ( $blocked_hosts_patterns as $pattern ) {
			if ( false !== strpos( $host, $pattern ) || $ip === $pattern ) {
				return new WP_Error(
					'blocked_metadata',
					__( 'Downloads from cloud metadata services are not allowed.', 'wp-mcp-connect' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Upload image from URL.
	 *
	 * @since    1.0.0
	 * @param    string $url       The image URL.
	 * @param    string $filename  Optional filename override.
	 * @return   int|WP_Error         Attachment ID or error.
	 */
	private function upload_from_url( $url, $filename = null ) {
		// Validate URL to prevent SSRF attacks.
		$validation = $this->validate_url_for_download( $url );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$tmp_file = download_url( $url, 30 );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		if ( empty( $filename ) ) {
			$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
		}

		if ( empty( $filename ) ) {
			$filename = 'uploaded-image-' . time() . '.jpg';
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp_file );
			return $attachment_id;
		}

		return $attachment_id;
	}

	/**
	 * Allowed image MIME types for upload.
	 *
	 * @since    1.0.0
	 * @var      array
	 */
	private $allowed_image_types = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	/**
	 * Detect actual MIME type from file content using magic bytes.
	 *
	 * @since    1.0.0
	 * @param    string $content    The file content.
	 * @return   string|false          MIME type or false if unknown.
	 */
	private function detect_image_mime_from_content( $content ) {
		$magic_bytes = array(
			'image/jpeg' => array( "\xFF\xD8\xFF" ),
			'image/png'  => array( "\x89PNG\r\n\x1A\n" ),
			'image/gif'  => array( 'GIF87a', 'GIF89a' ),
			'image/webp' => array( 'RIFF' ),
		);

		foreach ( $magic_bytes as $mime => $signatures ) {
			foreach ( $signatures as $signature ) {
				if ( substr( $content, 0, strlen( $signature ) ) === $signature ) {
					// Additional check for WebP (must have WEBP after RIFF header).
					if ( 'image/webp' === $mime && false === strpos( substr( $content, 0, 12 ), 'WEBP' ) ) {
						continue;
					}
					return $mime;
				}
			}
		}

		return false;
	}

	/**
	 * Upload image from base64 data.
	 *
	 * @since    1.0.0
	 * @param    string $base64    The base64 encoded image data.
	 * @param    string $filename  Optional filename.
	 * @return   int|WP_Error         Attachment ID or error.
	 */
	private function upload_from_base64( $base64, $filename = null ) {
		// Strip data URI prefix if present.
		$base64 = preg_replace( '/^data:image\/\w+;base64,/', '', $base64 );

		$decoded = base64_decode( $base64, true );

		if ( false === $decoded ) {
			return new WP_Error(
				'invalid_base64',
				__( 'Invalid base64 data.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $decoded ) > 10 * 1024 * 1024 ) {
			return new WP_Error(
				'file_too_large',
				__( 'Image file is too large. Maximum 10MB.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		// Detect actual MIME type from file content (not from client-provided data).
		$detected_mime = $this->detect_image_mime_from_content( $decoded );
		if ( false === $detected_mime ) {
			return new WP_Error(
				'invalid_image_type',
				__( 'File is not a valid image. Only JPEG, PNG, GIF, and WebP are allowed.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		// Get the correct extension for the detected MIME type.
		$extension = array_search( $detected_mime, $this->allowed_image_types, true );
		if ( false === $extension ) {
			return new WP_Error(
				'disallowed_image_type',
				__( 'This image type is not allowed.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		// Generate safe filename with correct extension.
		if ( empty( $filename ) ) {
			$filename = 'uploaded-image-' . time() . '.' . $extension;
		} else {
			// Force the correct extension based on actual content.
			$pathinfo = pathinfo( $filename );
			$filename = sanitize_file_name( $pathinfo['filename'] ) . '.' . $extension;
		}

		$upload_dir = wp_upload_dir();

		$unique_filename = wp_unique_filename( $upload_dir['path'], $filename );
		$file_path       = $upload_dir['path'] . '/' . $unique_filename;
		// Stage the write to a temporary sibling. This way a valid-looking
		// image never exists at the final public path unless both the
		// magic-byte check and wp_check_filetype_and_ext agree on the type.
		$tmp_path        = $file_path . '.tmp';

		$result = file_put_contents( $tmp_path, $decoded );

		if ( false === $result ) {
			return new WP_Error(
				'upload_failed',
				__( 'Failed to save uploaded file.', 'wp-mcp-connect' ),
				array( 'status' => 500 )
			);
		}

		// Verify the staged file using WordPress functions before promoting it
		// to the final path.
		$file_type = wp_check_filetype_and_ext( $tmp_path, $unique_filename );
		if ( empty( $file_type['type'] ) || ! in_array( $file_type['type'], $this->allowed_image_types, true ) ) {
			wp_delete_file( $tmp_path );
			return new WP_Error(
				'file_type_mismatch',
				__( 'File validation failed. The file may not be a valid image.', 'wp-mcp-connect' ),
				array( 'status' => 400 )
			);
		}

		if ( ! @rename( $tmp_path, $file_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $tmp_path );
			return new WP_Error(
				'upload_failed',
				__( 'Failed to promote uploaded file.', 'wp-mcp-connect' ),
				array( 'status' => 500 )
			);
		}

		$attachment_data = array(
			'post_mime_type' => $detected_mime,
			'post_title'     => sanitize_file_name( pathinfo( $unique_filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment_data, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return $attachment_id;
		}

		$attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		return $attachment_id;
	}
}
