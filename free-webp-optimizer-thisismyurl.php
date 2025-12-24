<?php
/**
 * Plugin Name: Free WebP Optimizer by thisismyurl
 * Description: Non-destructive WebP conversion with backups, live categorization, and one-click restoration.
 * Version: 1.251224
 * Author: thisismyurl
 * Author URI: https://thisismyurl.com/
 * License: GPLv2 or later
 * Text Domain: free-webp-optimizer-thisismyurl
 * GitHub Plugin URI: https://github.com/thisismyurl/free-webp-optimizer-thisismyurl
 * Primary Branch: main
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Free_WebP_Optimizer
 *
 * Handles the core logic for image conversion and restoration.
 */
class Free_WebP_Optimizer {

	/**
	 * Initialize the plugin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'wp_ajax_fwo_bulk_optimize', array( __CLASS__, 'ajax_bulk_optimize' ) );
		add_action( 'wp_ajax_fwo_restore_single', array( __CLASS__, 'ajax_restore_single' ) );
	}

	/**
	 * Register the Media submenu page.
	 */
	public static function add_admin_menu() {
		add_media_page(
			__( 'WebP Optimizer', 'free-webp-optimizer-thisismyurl' ),
			__( 'WebP Optimizer', 'free-webp-optimizer-thisismyurl' ),
			'manage_options',
			'webp-optimizer',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Core Engine: Initialize WordPress Filesystem.
	 *
	 * @return WP_Filesystem_Base
	 */
	private static function init_fs() {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	/**
	 * Get lists of pending and managed media items.
	 *
	 * @return array
	 */
	public static function get_media_lists() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp' ),
			)
		);

		$pending = array();
		$media   = array();

		if ( $query->posts ) {
			foreach ( $query->posts as $post ) {
				$file      = get_attached_file( $post->ID );
				$mime      = get_post_mime_type( $post->ID );
				$orig_path = get_post_meta( $post->ID, '_webp_original_path', true );
				$is_webp   = ( 'image/webp' === $mime );

				// Handle externally uploaded WebPs.
				if ( $is_webp && ! $orig_path ) {
					update_post_meta( $post->ID, '_webp_original_path', 'external' );
					$orig_path = 'external';
				}

				if ( ! file_exists( $file ) ) {
					$post->fwo_status = 'missing';
					$media[]          = $post;
					continue;
				}

				if ( $orig_path || $is_webp ) {
					$media[] = $post;
				} else {
					$pending[] = $post;
				}
			}
		}
		return array(
			'pending' => $pending,
			'media'   => $media,
		);
	}

	/**
	 * Convert an image to WebP and back up the original.
	 *
	 * @param int $id Attachment ID.
	 * @param int $quality Compression quality.
	 * @return bool|WP_Error
	 */
	public static function convert_to_webp( $id, $quality = 80 ) {
		$fs        = self::init_fs();
		$full_path = get_attached_file( $id );

		if ( ! $full_path || ! $fs->exists( $full_path ) ) {
			return new WP_Error( 'missing', __( 'File does not exist.', 'free-webp-optimizer-thisismyurl' ) );
		}

		$info = getimagesize( $full_path );
		if ( ! $info ) {
			return new WP_Error( 'info', __( 'Invalid image data.', 'free-webp-optimizer-thisismyurl' ) );
		}

		$original_size = filesize( $full_path );
		$new_path      = preg_replace( '/\.(jpg|jpeg|png|gif|bmp)$/i', '.webp', $full_path );

		// Create image resource based on MIME.
		switch ( $info['mime'] ) {
			case 'image/jpeg':
				$image = imagecreatefromjpeg( $full_path );
				break;
			case 'image/png':
				$image = imagecreatefrompng( $full_path );
				if ( $image ) {
					imagepalettetotruecolor( $image );
					imagealphablending( $image, true );
					imagesavealpha( $image, true );
				}
				break;
			case 'image/gif':
				$image = imagecreatefromgif( $full_path );
				break;
			case 'image/bmp':
				$image = imagecreatefrombmp( $full_path );
				break;
			default:
				return new WP_Error( 'mime', __( 'Unsupported format.', 'free-webp-optimizer-thisismyurl' ) );
		}

		if ( ! $image ) {
			return new WP_Error( 'gd', __( 'GD Library failed to process image.', 'free-webp-optimizer-thisismyurl' ) );
		}

		imagewebp( $image, $new_path, $quality );
		imagedestroy( $image );

		// Move original to backup folder.
		$upload_dir  = wp_upload_dir();
		$rel_path    = get_post_meta( $id, '_wp_attached_file', true );
		$backup_dir  = $upload_dir['basedir'] . '/webp-backups/' . dirname( $rel_path );

		if ( wp_mkdir_p( $backup_dir ) ) {
			$backup_path = $backup_dir . '/' . basename( $full_path );
			if ( $fs->move( $full_path, $backup_path, true ) ) {
				update_post_meta( $id, '_webp_original_path', $backup_path );
				update_post_meta( $id, '_webp_savings', ( $original_size - filesize( $new_path ) ) );
				
				// Update WP Attachment Data.
				$new_rel_path = preg_replace( '/\.(jpg|jpeg|png|gif|bmp)$/i', '.webp', $rel_path );
				update_post_meta( $id, '_wp_attached_file', $new_rel_path );
				wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/webp' ) );
				
				return true;
			}
		}

		return new WP_Error( 'move', __( 'Failed to archive original file.', 'free-webp-optimizer-thisismyurl' ) );
	}

	/**
	 * Restore original image from backup.
	 */
	public static function restore_image( $id ) {
		$fs          = self::init_fs();
		$backup_path = get_post_meta( $id, '_webp_original_path', true );

		if ( ! $backup_path || 'external' === $backup_path || ! $fs->exists( $backup_path ) ) {
			return false;
		}

		$current_webp = get_attached_file( $id );
		$extension    = pathinfo( $backup_path, PATHINFO_EXTENSION );
		$restored_path = preg_replace( '/\.webp$/i', '.' . $extension, $current_webp );

		if ( $fs->move( $backup_path, $restored_path, true ) ) {
			if ( $fs->exists( $current_webp ) ) {
				$fs->delete( $current_webp );
			}

			$rel_path = get_post_meta( $id, '_wp_attached_file', true );
			$new_rel  = preg_replace( '/\.webp$/i', '.' . $extension, $rel_path );
			
			update_post_meta( $id, '_wp_attached_file', $new_rel );
			
			$mimes = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp' );
			$mime  = isset( $mimes[ strtolower( $extension ) ] ) ? $mimes[ strtolower( $extension ) ] : 'image/jpeg';

			wp_update_post( array( 'ID' => $id, 'post_mime_type' => $mime ) );
			delete_post_meta( $id, '_webp_original_path' );
			delete_post_meta( $id, '_webp_savings' );
			return true;
		}
		return false;
	}

	/**
	 * AJAX Handler: Bulk Optimization.
	 */
	public static function ajax_bulk_optimize() {
		check_ajax_referer( 'fwo_webp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$id     = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$result = self::convert_to_webp( $id );

		if ( true === $result ) {
			wp_send_json_success( array(
				'filename' => basename( get_attached_file( $id ) ),
				'thumb'    => wp_get_attachment_image( $id, array( 50, 50 ) ),
			) );
		}

		wp_send_json_error( is_wp_error( $result ) ? $result->get_error_message() : 'Unknown error' );
	}

	/**
	 * AJAX Handler: Single Restoration.
	 */
	public static function ajax_restore_single() {
		check_ajax_referer( 'fwo_webp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( self::restore_image( $id ) ) {
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	/**
	 * Render the Admin Dashboard.
	 */
	public static function render_admin_page() {
		$lists       = self::get_media_lists();
		$pending_ids = array_map( function( $p ) { return $p->ID; }, $lists['pending'] );
		$restorable  = array();

		foreach ( $lists['media'] as $m ) {
			$orig = get_post_meta( $m->ID, '_webp_original_path', true );
			if ( $orig && 'external' !== $orig ) {
				$restorable[] = $m->ID;
			}
		}
		?>
		<div class="wrap webp-optimizer-wrap">
			<h1><?php esc_html_e( 'Free WebP Optimizer', 'free-webp-optimizer-thisismyurl' ); ?></h1>

			<div class="welcome-panel" style="background-color:#f0f0f1;">
				<div class="welcome-panel-content">
					<h2><?php esc_html_e( 'Optimization Dashboard', 'free-webp-optimizer-thisismyurl' ); ?></h2>
					<div class="fwo-controls" style="display: flex; gap: 10px;">
						<button id="btn-start" class="button button-primary button-large" <?php disabled( empty( $pending_ids ) ); ?>>
							<?php printf( esc_html__( 'Optimize All %d Images', 'free-webp-optimizer-thisismyurl' ), count( $pending_ids ) ); ?>
						</button>
						<button id="btn-cancel" class="button button-secondary button-large" style="display:none; color: #d63638;">
							<?php esc_html_e( 'Cancel Batch', 'free-webp-optimizer-thisismyurl' ); ?>
						</button>
					</div>
					
					<div id="fwo-progress-container" class="fwo-progress-container" style="display:none; margin-top:20px; background:#f0f0f1; height:30px; position:relative; border-radius:4px; overflow:hidden; border:1px solid #c3c4c7;">
						<div id="fwo-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.2s;"></div>
						<div id="fwo-progress-text" style="position:absolute; width:100%; text-align:center; top:0; line-height:30px; font-weight:bold; color:#fff; mix-blend-mode:difference;">0%</div>
					</div>
				</div>
			</div>

			<div class="report-table-wrap" style="margin-top:30px;">
				<h2><?php esc_html_e( 'Pending Optimizations', 'free-webp-optimizer-thisismyurl' ); ?> (<span id="p-cnt"><?php echo count( $pending_ids ); ?></span>)</h2>
				<table class="widefat striped" id="fwo-pending-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Preview', 'free-webp-optimizer-thisismyurl' ); ?></th>
							<th><?php esc_html_e( 'ID', 'free-webp-optimizer-thisismyurl' ); ?></th>
							<th><?php esc_html_e( 'File Name', 'free-webp-optimizer-thisismyurl' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( $lists['pending'] ) : foreach ( $lists['pending'] as $post ) : ?>
							<tr id="fwo-row-<?php echo esc_attr( $post->ID ); ?>">
								<td><?php echo wp_get_attachment_image( $post->ID, array( 50, 50 ) ); ?></td>
								<td>#<?php echo esc_html( $post->ID ); ?></td>
								<td><?php echo esc_html( basename( get_attached_file( $post->ID ) ) ); ?></td>
							</tr>
						<?php endforeach; else : ?>
							<tr class="no-images"><td colspan="3"><?php esc_html_e( 'All images optimized!', 'free-webp-optimizer-thisismyurl' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="report-table-wrap" style="margin-top:30px;">
				<h2><?php esc_html_e( 'Managed Media', 'free-webp-optimizer-thisismyurl' ); ?> (<span id="m-cnt"><?php echo count( $lists['media'] ); ?></span>)</h2>
				<table class="widefat striped" id="fwo-media-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Preview', 'free-webp-optimizer-thisismyurl' ); ?></th>
							<th><?php esc_html_e( 'ID', 'free-webp-optimizer-thisismyurl' ); ?></th>
							<th><?php esc_html_e( 'File Name', 'free-webp-optimizer-thisismyurl' ); ?></th>
							<th><?php esc_html_e( 'Action', 'free-webp-optimizer-thisismyurl' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $lists['media'] as $post ) : 
							$orig = get_post_meta( $post->ID, '_webp_original_path', true );
							$status = isset( $post->fwo_status ) ? $post->fwo_status : '';
						?>
							<tr id="fwo-media-row-<?php echo esc_attr( $post->ID ); ?>">
								<td><?php echo wp_get_attachment_image( $post->ID, array( 50, 50 ) ); ?></td>
								<td>#<?php echo esc_html( $post->ID ); ?></td>
								<td><?php echo esc_html( basename( get_attached_file( $post->ID ) ) ); ?></td>
								<td>
									<?php if ( 'missing' === $status ) : ?>
										<span style="color:#d63638;"><?php esc_html_e( 'File Missing', 'free-webp-optimizer-thisismyurl' ); ?></span>
									<?php elseif ( $orig && 'external' !== $orig ) : ?>
										<button class="restore-btn button button-small" data-id="<?php echo esc_attr( $post->ID ); ?>">
											<?php esc_html_e( 'Restore Original', 'free-webp-optimizer-thisismyurl' ); ?>
										</button>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'Optimized', 'free-webp-optimizer-thisismyurl' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( ! empty( $restorable ) ) : ?>
					<div class="restore-all-wrap" style="margin-top:20px; padding:15px; background:#fff; border:1px solid #c3c4c7;">
						<button id="btn-restore-all" class="button button-secondary" data-ids="<?php echo esc_attr( wp_json_encode( $restorable ) ); ?>">
							<?php esc_html_e( 'Restore All Optimized Images', 'free-webp-optimizer-thisismyurl' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			const pendingIds = <?php echo wp_json_encode( $pending_ids ); ?>;
			const nonce = '<?php echo esc_js( wp_create_nonce( "fwo_webp_nonce" ) ); ?>';
			let completed = 0;
			let isCancelled = false;

			$(document).on('click', '.restore-btn', function() {
				const $btn = $(this);
				$btn.prop('disabled', true).text('...');
				$.post(ajaxurl, { action: 'fwo_restore_single', attachment_id: $btn.data('id'), nonce: nonce })
					.done(() => location.reload());
			});

			$('#btn-restore-all').click(function() {
				const ids = $(this).data('ids');
				if(!confirm('<?php esc_js( esc_html_e( "Restore all images?", "free-webp-optimizer-thisismyurl" ) ); ?>')) return;
				$(this).prop('disabled', true).text('Restoring...');
				
				const processRestore = () => {
					if(!ids.length) return location.reload();
					$.post(ajaxurl, { action: 'fwo_restore_single', attachment_id: ids.shift(), nonce: nonce }).always(processRestore);
				};
				processRestore();
			});

			$('#btn-start').click(function() {
				const $btn = $(this);
				const total = pendingIds.length;
				$btn.prop('disabled', true).text('Processing...');
				$('#btn-cancel').show();
				$('#fwo-progress-container').fadeIn();

				const processNext = () => {
					if (isCancelled || !pendingIds.length) return;
					const id = pendingIds.shift();
					$.post(ajaxurl, { action: 'fwo_bulk_optimize', attachment_id: id, nonce: nonce })
						.done(function(res) {
							if (res.success) {
								completed++;
								const pct = Math.round((completed / total) * 100);
								$('#fwo-progress-bar').css('width', pct + '%');
								$('#fwo-progress-text').text(pct + '%');
								$('#fwo-row-' + id).remove();
								$('#p-cnt').text(total - completed);
							}
							processNext();
						});
				};
				processNext();
			});

			$('#btn-cancel').click(() => { isCancelled = true; location.reload(); });
		});
		</script>
		<?php
	}
}

// Start the engine.
Free_WebP_Optimizer::init();

// Include the updater if it exists.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'updater.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'updater.php';
}