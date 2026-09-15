<?php
defined( 'ABSPATH' ) || exit;

class WP_MCP_Connect_Admin_Lite {

	public function register_admin_menu() {
		add_menu_page(
			__( 'MCP Connect Lite', 'wp-mcp-connect' ),
			__( 'MCP Connect Lite', 'wp-mcp-connect' ),
			'manage_options',
			'wp-mcp-connect',
			array( $this, 'render_page' ),
			'dashicons-rest-api',
			80
		);
	}

	public function render_page() {
		$last_access = get_option( 'cwp_api_last_access', array() );
		$connected   = ! empty( $last_access['timestamp'] );
		$recent      = $connected && ( time() - $last_access['timestamp'] ) < 86400;

		// Read-only pagination on an admin screen already gated by
		// manage_options; there is no state change to protect with a nonce and
		// the value is cast to a positive integer before use.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 20;
		$ops = $this->get_ops_log( $per_page, ( $page - 1 ) * $per_page );
		$total = $this->get_ops_count();
		$total_pages = (int) ceil( $total / $per_page );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MCP Connect Lite', 'wp-mcp-connect' ); ?></h1>

			<div class="cwp-status-card" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $recent ? '#00a32a' : ( $connected ? '#dba617' : '#d63638' ); ?>;padding:16px 20px;margin:20px 0;max-width:700px;">
				<h2 style="margin:0 0 8px;font-size:15px;">
					<?php if ( $recent ) : ?>
						&#9679; <?php esc_html_e( 'Connected', 'wp-mcp-connect' ); ?>
					<?php elseif ( $connected ) : ?>
						&#9679; <?php esc_html_e( 'Idle', 'wp-mcp-connect' ); ?>
					<?php else : ?>
						&#9679; <?php esc_html_e( 'Not connected', 'wp-mcp-connect' ); ?>
					<?php endif; ?>
				</h2>
				<?php if ( $connected ) : ?>
					<table class="form-table" style="margin:0;">
						<tr><th style="padding:4px 10px 4px 0;width:120px;"><?php esc_html_e( 'Last seen', 'wp-mcp-connect' ); ?></th>
							<td style="padding:4px 0;"><?php echo esc_html( $this->time_ago( $last_access['timestamp'] ) ); ?> <span class="description">(<?php echo esc_html( wp_date( 'M j, Y g:i a', $last_access['timestamp'] ) ); ?>)</span></td></tr>
						<?php if ( ! empty( $last_access['user_login'] ) ) : ?>
						<tr><th style="padding:4px 10px 4px 0;"><?php esc_html_e( 'User', 'wp-mcp-connect' ); ?></th>
							<td style="padding:4px 0;"><?php echo esc_html( $last_access['user_login'] ); ?></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $last_access['ip'] ) ) : ?>
						<tr><th style="padding:4px 10px 4px 0;"><?php esc_html_e( 'IP address', 'wp-mcp-connect' ); ?></th>
							<td style="padding:4px 0;"><code><?php echo esc_html( $last_access['ip'] ); ?></code></td></tr>
						<?php endif; ?>
					</table>
				<?php else : ?>
					<p class="description" style="margin:0;"><?php esc_html_e( 'No MCP client has connected yet. Configure your MCP server with this site\'s URL and an application password.', 'wp-mcp-connect' ); ?></p>
				<?php endif; ?>
			</div>

			<h2><?php esc_html_e( 'Change Log', 'wp-mcp-connect' ); ?>
				<?php if ( $total > 0 ) : ?>
					<span class="description" style="font-size:13px;font-weight:normal;"> &mdash; <?php echo esc_html( number_format_i18n( $total ) ); ?> <?php esc_html_e( 'operations', 'wp-mcp-connect' ); ?></span>
				<?php endif; ?>
			</h2>

			<?php if ( empty( $ops ) ) : ?>
				<p class="description"><?php esc_html_e( 'No operations recorded yet. Changes made via MCP will appear here.', 'wp-mcp-connect' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th style="width:150px;"><?php esc_html_e( 'Date', 'wp-mcp-connect' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Operation', 'wp-mcp-connect' ); ?></th>
							<th><?php esc_html_e( 'Details', 'wp-mcp-connect' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Status', 'wp-mcp-connect' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ops as $op ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'M j, g:i a', strtotime( $op->created_at ) ) ); ?></td>
								<td><code><?php echo esc_html( $op->op_type ); ?></code></td>
								<td><?php echo esc_html( $this->summarize_payload( $op->op_type, $op->payload ) ); ?></td>
								<td>
									<?php if ( 'rolled_back' === $op->status ) : ?>
										<span style="color:#d63638;">&#8634; <?php echo esc_html( $op->status ); ?></span>
									<?php else : ?>
										<?php echo esc_html( $op->status ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom" style="max-width:900px;">
						<div class="tablenav-pages">
							<?php
							// paginate_links() returns markup, so escape it as post
							// content rather than plain text.
							echo wp_kses_post( paginate_links( array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $page,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							) ) );
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_ops_log( $limit, $offset ) {
		global $wpdb;
		$table = $wpdb->prefix . 'cwp_ops_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + a literal; other interpolations are generated %s/%d placeholder lists or literal SQL. All caller input is bound via prepare().
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT op_type, payload, status, created_at FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$limit,
			$offset
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function get_ops_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'cwp_ops_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + hardcoded constant, existence already checked above.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	private function time_ago( $timestamp ) {
		$diff = time() - $timestamp;
		if ( $diff < 60 ) {
			return __( 'just now', 'wp-mcp-connect' );
		}
		if ( $diff < 3600 ) {
			$mins = (int) floor( $diff / 60 );
			return sprintf( _n( '%d minute ago', '%d minutes ago', $mins, 'wp-mcp-connect' ), $mins );
		}
		if ( $diff < 86400 ) {
			$hours = (int) floor( $diff / 3600 );
			return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'wp-mcp-connect' ), $hours );
		}
		$days = (int) floor( $diff / 86400 );
		return sprintf( _n( '%d day ago', '%d days ago', $days, 'wp-mcp-connect' ), $days );
	}

	private function summarize_payload( $op_type, $payload_json ) {
		if ( empty( $payload_json ) ) {
			return '';
		}

		$data = json_decode( $payload_json, true );
		if ( ! is_array( $data ) ) {
			return '';
		}

		$title = $data['title'] ?? $data['post_title'] ?? $data['name'] ?? '';
		$id    = $data['id'] ?? $data['post_id'] ?? '';

		$parts = array();
		if ( $id ) {
			$parts[] = '#' . $id;
		}
		if ( $title ) {
			$parts[] = '"' . mb_strimwidth( $title, 0, 60, '...' ) . '"';
		}

		if ( empty( $parts ) ) {
			$keys = array_keys( $data );
			$keys = array_filter( $keys, function ( $k ) {
				return ! in_array( $k, array( 'previous_state', 'nonce' ), true );
			} );
			return implode( ', ', array_slice( $keys, 0, 4 ) );
		}

		return implode( ' ', $parts );
	}
}
