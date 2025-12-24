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
 * SECTION 1: CORE ENGINE (CONVERSION & RESTORATION)
 */
function fwo_get_media_lists() {
	$query = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	) );

	$pending = array();
	$media   = array();

	if ( $query->posts ) {
		foreach ( $query->posts as $post ) {
			$file         = get_attached_file( $post->ID );
			$mime         = get_post_mime_type( $post->ID );
			$is_webp      = ( 'image/webp' === $mime || strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'webp' );
			$is_optimizable = in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp' ) );
			$orig_path    = get_post_meta( $post->ID, '_webp_original_path', true );
			$exists       = file_exists( $file );

			// Mark existing WebP files as externally managed
			if ( $is_webp && ! $orig_path ) {
				update_post_meta( $post->ID, '_webp_original_path', 'external' );
				$orig_path = 'external';
			}

			// If file missing, move to second list with special status
			if ( ! $exists ) {
				$post->fwo_status = 'missing';
				$media[] = $post;
				continue;
			}

			if ( $orig_path || $is_webp || ! $is_optimizable ) {
				$media[] = $post;
			} else {
				$pending[] = $post;
			}
		}
	}
	return array( 'pending' => $pending, 'media' => $media );
}

function fwo_init_filesystem() {
	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}
	return $wp_filesystem;
}

function fwo_convert_to_webp( $attachment_id, $quality = 80 ) {
	$wp_fs     = fwo_init_filesystem();
	$full_path = get_attached_file( $attachment_id );

	if ( ! $full_path || ! $wp_fs->exists( $full_path ) ) return new WP_Error('missing','File missing.');
	$info = getimagesize( $full_path );
	if ( ! $info ) return new WP_Error('info','Invalid image.');

	$original_size = filesize( $full_path );
	$new_path      = preg_replace( '/\.(jpg|jpeg|png|gif|bmp)$/i', '.webp', $full_path );

	if ( 'image/jpeg' === $info['mime'] ) $image = imagecreatefromjpeg( $full_path );
	elseif ( 'image/png' === $info['mime'] ) {
		$image = imagecreatefrompng( $full_path );
		if ( $image ) { imagepalettetotruecolor($image); imagealphablending($image, true); imagesavealpha($image, true); }
	} elseif ( 'image/gif' === $info['mime'] ) $image = imagecreatefromgif( $full_path );
	elseif ( 'image/bmp' === $info['mime'] ) $image = imagecreatefrombmp( $full_path );

	if ( ! isset( $image ) || ! $image ) return new WP_Error('gd','GD failed.');
	imagewebp( $image, $new_path, $quality );
	imagedestroy( $image );

	$upload_dir  = wp_upload_dir();
	$backup_base = $upload_dir['basedir'] . '/webp-backups';
	$rel_path    = get_post_meta( $attachment_id, '_wp_attached_file', true );
	$backup_dir  = $backup_base . '/' . dirname( $rel_path );

	// Reliable recursive backup creation
	if ( ! wp_mkdir_p( $backup_dir ) ) return new WP_Error('dir','Backup folder failed.');

	$backup_path = $backup_dir . '/' . basename( $full_path );

	if ( $wp_fs->move( $full_path, $backup_path, true ) ) {
		update_post_meta( $attachment_id, '_webp_original_path', $backup_path );
		update_post_meta( $attachment_id, '_webp_savings', ( $original_size - filesize( $new_path ) ) );
		return true;
	}
	return new WP_Error('move','Archive failed.');
}

function fwo_restore_image( $attachment_id ) {
	$wp_fs = fwo_init_filesystem();
	$backup_path = get_post_meta( $attachment_id, '_webp_original_path', true );

	if ( ! $backup_path || 'external' === $backup_path || ! $wp_fs->exists( $backup_path ) ) return false;

	$current_webp_path = get_attached_file( $attachment_id );
	$original_ext = pathinfo( $backup_path, PATHINFO_EXTENSION );
	$restored_path = preg_replace( '/\.webp$/i', '.' . $original_ext, $current_webp_path );

	if ( $wp_fs->move( $backup_path, $restored_path, true ) ) {
		if ( $wp_fs->exists( $current_webp_path ) ) $wp_fs->delete( $current_webp_path );
		
		$rel_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
		update_post_meta( $attachment_id, '_wp_attached_file', preg_replace( '/\.webp$/i', '.' . $original_ext, $rel_path ) );
		
		$mimes = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp' );
		$mime = isset( $mimes[ strtolower( $original_ext ) ] ) ? $mimes[ strtolower( $original_ext ) ] : 'image/jpeg';
		
		wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => $mime ) );
		delete_post_meta( $attachment_id, '_webp_original_path' );
		delete_post_meta( $attachment_id, '_webp_savings' );
		return true;
	}
	return false;
}

/**
 * SECTION 2: ADMIN UI (Media Menu)
 */
add_action( 'admin_menu', function() {
	add_media_page( 'WebP Optimizer', 'WebP Optimizer', 'manage_options', 'webp-optimizer', 'fwo_render_admin_page' );
});

function fwo_render_admin_page() {
	$lists = fwo_get_media_lists();
	$pending_ids = array_map( function($p) { return $p->ID; }, $lists['pending'] );
	$restorable_ids = array();
	foreach($lists['media'] as $m) {
		$orig = get_post_meta($m->ID, '_webp_original_path', true);
		if($orig && $orig !== 'external') $restorable_ids[] = $m->ID;
	}
	?>
	<style>
		.webp-optimizer-wrap .welcome-panel { padding: 30px; background: #fff; border: 1px solid #c3c4c7; margin-top: 20px; }
		.fwo-progress-container { display: none; margin-top: 20px; background: #f0f0f1; border: 1px solid #c3c4c7; height: 30px; position: relative; border-radius: 4px; overflow: hidden; }
		.fwo-progress-bar { background: #2271b1; height: 100%; width: 0%; transition: width 0.1s linear; }
		.fwo-progress-text { position: absolute; width: 100%; text-align: center; top: 0; line-height: 30px; font-weight: bold; color: #fff; mix-blend-mode: difference; }
		.fwo-status-text { margin-top: 10px; font-weight: bold; display: none; }
		.report-table-wrap { margin-top: 30px; }
		.restore-all-wrap { margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #c3c4c7; }
	</style>

	<div class="wrap webp-optimizer-wrap">
		<h1>Free WebP Optimizer <small>by thisismyurl</small></h1>

		<div class="welcome-panel">
			<div class="welcome-panel-content">
				<h2>Optimization Dashboard</h2>
				<div class="fwo-controls" style="display: flex; gap: 10px;">
					<button id="btn-start" class="button button-primary button-large" <?php disabled( count($pending_ids), 0 ); ?>>Optimize All <?php echo count($pending_ids); ?> Images</button>
					<button id="btn-cancel" class="button button-secondary button-large" style="display:none; color: #d63638;">Cancel Batch</button>
				</div>
				<div id="fwo-progress-container" class="fwo-progress-container">
					<div id="fwo-progress-bar" class="fwo-progress-bar"></div>
					<div id="fwo-progress-text" class="fwo-progress-text">0%</div>
				</div>
				<div id="fwo-status-text" class="fwo-status-text">Processing image <span id="fwo-current-idx">0</span> of <?php echo count($pending_ids); ?>...</div>
			</div>
		</div>

		<div class="report-table-wrap">
			<h2>Pending Optimizations (Count: <span id="p-cnt"><?php echo count($pending_ids); ?></span>)</h2>
			<table class="widefat striped" id="fwo-pending-table">
				<thead><tr><th>Preview</th><th>ID</th><th>File Name</th></tr></thead>
				<tbody>
					<?php if ( $lists['pending'] ) : foreach ( $lists['pending'] as $post ) : ?>
						<tr id="fwo-row-<?php echo esc_attr( $post->ID ); ?>">
							<td><?php echo wp_get_attachment_image( $post->ID, array( 50, 50 ) ); ?></td>
							<td>#<?php echo esc_html( $post->ID ); ?></td>
							<td><?php echo esc_html( basename( get_attached_file( $post->ID ) ) ); ?></td>
						</tr>
					<?php endforeach; else : ?>
						<tr class="no-images"><td colspan="3">All images optimized!</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div class="report-table-wrap">
			<h2>Media Files (Count: <span id="m-cnt"><?php echo count($lists['media']); ?></span>)</h2>
			<table class="widefat striped" id="fwo-media-table">
				<thead><tr><th>Preview</th><th>ID</th><th>File Name</th><th>Action</th></tr></thead>
				<tbody>
					<?php if ( $lists['media'] ) : foreach ( $lists['media'] as $post ) : 
						$orig = get_post_meta( $post->ID, '_webp_original_path', true );
						$mime = get_post_mime_type( $post->ID );
						$is_image = (strpos($mime, 'image/') === 0);
						$is_webp = ($mime === 'image/webp');
						$status = isset($post->fwo_status) ? $post->fwo_status : '';
					?>
						<tr id="fwo-media-row-<?php echo esc_attr( $post->ID ); ?>">
							<td><?php echo wp_get_attachment_image( $post->ID, array( 50, 50 ) ); ?></td>
							<td>#<?php echo esc_html( $post->ID ); ?></td>
							<td><?php echo esc_html( basename( get_attached_file( $post->ID ) ) ); ?></td>
							<td>
								<?php if ( $status === 'missing' ) : ?>
									<span class="description" style="color:#d63638;">No file to optimize</span>
								<?php elseif ( $orig && 'external' !== $orig ) : ?>
									<button class="restore-btn button button-small" data-id="<?php echo esc_attr( $post->ID ); ?>">Restore Original</button>
								<?php elseif ( $is_image && !$is_webp ) : ?>
								<?php else : ?>
									<span class="description"><?php echo $is_webp ? 'Optimized' : 'Managed'; ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; else : ?>
						<tr class="no-images"><td colspan="4">No media files found.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( !empty($restorable_ids) ) : ?>
				<div class="restore-all-wrap">
					<button id="btn-restore-all" class="button button-secondary" data-ids="<?php echo esc_attr(json_encode($restorable_ids)); ?>">Restore All Optimized Images</button>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<script type="text/javascript">
	jQuery(document).ready(function($) {
		const pendingIds = <?php echo json_encode( $pending_ids ); ?>;
		const nonce = '<?php echo wp_create_nonce( "fwo_webp_nonce" ); ?>';
		let completed = 0;
		let isCancelled = false;

		// One-click restoration
		$(document).on('click', '.restore-btn', function(e) {
			const $btn = $(this);
			$btn.attr('disabled', true).text('Working...');
			$.post(ajaxurl, { action: 'fwo_restore_single', attachment_id: $btn.data('id'), _ajax_nonce: nonce })
			.done(() => location.reload());
		});

		// Restore All Logic
		$('#btn-restore-all').click(function() {
			const ids = $(this).data('ids');
			if(!confirm('Restore all ' + ids.length + ' images to originals?')) return;
			$(this).attr('disabled', true).text('Restoring All...');
			
			const restoreBatch = () => {
				if(!ids.length) return location.reload();
				$.post(ajaxurl, { action: 'fwo_restore_single', attachment_id: ids.shift(), _ajax_nonce: nonce }).always(restoreBatch);
			};
			restoreBatch();
		});

		$('#btn-start').click(function(e) {
			e.preventDefault();
			if (!pendingIds.length) return;
			const $btn = $(this);
			const total = pendingIds.length;
			$btn.attr('disabled', true).text('Processing...');
			$('#btn-cancel').show();
			$('#fwo-progress-container, #fwo-status-text').fadeIn();

			function processNext() {
				if (isCancelled || !pendingIds.length) {
					$btn.text(isCancelled ? 'Resume' : 'Complete');
					return;
				}
				const id = pendingIds.shift();
				const row = $('#fwo-row-' + id);
				$.post(ajaxurl, { action: 'webp_bulk_optimize', attachment_id: id, _ajax_nonce: nonce })
				.done(function(res) {
					if (res.success) {
						completed++;
						const percent = Math.round((completed / total) * 100);
						$('#fwo-progress-bar').css('width', percent + '%');
						$('#fwo-progress-text').text(percent + '%');
						$('#fwo-current-idx').text(completed);
						
						row.fadeOut(300, function() {
							$(this).remove();
							const newRow = `<tr id="fwo-media-row-${id}"><td>${res.data.thumb}</td><td>#${id}</td><td>${res.data.filename}</td><td><button class="restore-btn button button-small" data-id="${id}">Restore Original</button></td></tr>`;
							$('#fwo-media-table tbody .no-images').remove();
							$('#fwo-media-table tbody').prepend($(newRow).hide().fadeIn(400));
							
							// Dynamic Count Updates
							$('#p-cnt').text(parseInt($('#p-cnt').text()) - 1);
							$('#m-cnt').text(parseInt($('#m-cnt').text()) + 1);
						});
					}
					processNext();
				});
			}
			processNext();
		});

		$('#btn-cancel').click(function() { isCancelled = true; $(this).attr('disabled', true).text('Stopping...'); });
	});
	</script>
	<?php
}

/**
 * SECTION 3: AJAX ENDPOINTS
 */
add_action( 'wp_ajax_webp_bulk_optimize', function() {
	check_ajax_referer( 'fwo_webp_nonce' );
	$id = (int) $_POST['attachment_id'];
	if ( fwo_convert_to_webp( $id ) === true ) {
		update_post_meta( $id, '_wp_attached_file', preg_replace( '/\.(jpg|jpeg|png|gif|bmp)$/i', '.webp', get_post_meta( $id, '_wp_attached_file', true ) ) );
		wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/webp' ) );
		wp_send_json_success( array( 'filename' => basename( get_attached_file( $id ) ), 'thumb' => wp_get_attachment_image( $id, array( 50, 50 ) ) ) );
	}
	wp_send_json_error();
});

add_action( 'wp_ajax_fwo_restore_single', function() {
	check_ajax_referer( 'fwo_webp_nonce' );
	if ( fwo_restore_image( (int)$_POST['attachment_id'] ) ) wp_send_json_success();
	wp_send_json_error();
});

require_once plugin_dir_path( __FILE__ ) . 'updater.php';