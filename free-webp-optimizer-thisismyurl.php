<?php
/**
 * Plugin Name: Free WebP Optimizer by thisismyurl
 * Description: Non-destructive WebP conversion with backups, undo, and live savings reports.
 * Version: 1.251224
 * Author: thisismyurl
 * Author URI: https://thisismyurl.com/
 * License: GPLv2 or later
 * Text Domain: free-webp-optimizer-thisismyurl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SECTION 1: ASSETS & DATA FETCHING
 */
function fwo_enqueue_admin_assets( $hook ) {
	if ( 'tools_page_webp-optimizer' !== $hook ) {
		return;
	}

	wp_enqueue_style( 'fwo-admin-styles', plugin_dir_url( __FILE__ ) . 'free-webp-optimizer-thisismyurl.css', array(), '1.251224' );
	wp_enqueue_script( 'fwo-admin-js', plugin_dir_url( __FILE__ ) . 'free-webp-optimizer-thisismyurl.js', array( 'jquery' ), '1.251224', true );

	// Fetch pending images using WP_Query (No direct SQL)
	$pending_query = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'post_mime_type' => array( 'image/jpeg', 'image/png' ),
		'no_found_rows'  => true,
	) );

	wp_localize_script( 'fwo-admin-js', 'fwoVars', array(
		'ajaxurl'    => admin_url( 'admin-ajax.php' ),
		'nonce'      => wp_create_nonce( 'fwo_webp_nonce' ),
		'pendingIds' => array_map( 'intval', $pending_query->posts ),
		'msgError'   => esc_html__( 'Failed to restore image #', 'free-webp-optimizer-thisismyurl' ),
		'msgConfirm' => esc_html__( 'Restore original files? This cannot be undone.', 'free-webp-optimizer-thisismyurl' ),
	) );
}
add_action( 'admin_enqueue_scripts', 'fwo_enqueue_admin_assets' );

/**
 * SECTION 2: CONVERSION ENGINE (WP_FILESYSTEM API)
 */
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

	if ( ! $full_path || ! $wp_fs->exists( $full_path ) ) {
		return false;
	}

	$info = getimagesize( $full_path );
	if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png' ) ) ) {
		return false;
	}

	$original_size = filesize( $full_path );
	$new_path      = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $full_path );

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
	}

	if ( ! isset( $image ) || ! $image ) {
		return false;
	}

	imagewebp( $image, $new_path, $quality );
	imagedestroy( $image );

	$new_size      = filesize( $new_path );
	$relative_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
	$upload_dir    = wp_upload_dir();
	$backup_dir    = $upload_dir['basedir'] . '/webp-backups/' . dirname( $relative_path );

	if ( ! $wp_fs->is_dir( $backup_dir ) ) {
		$wp_fs->mkdir( $backup_dir, FS_CHMOD_DIR );
	}

	$backup_path = $backup_dir . '/' . basename( $full_path );

	if ( $wp_fs->move( $full_path, $backup_path, true ) ) {
		update_post_meta( $attachment_id, '_webp_original_path', $backup_path );
		update_post_meta( $attachment_id, '_webp_savings', ( $original_size - $new_size ) );
		return true;
	}

	return false;
}

function fwo_restore_single_image( $id ) {
	$wp_fs        = fwo_init_filesystem();
	$backup_path  = get_post_meta( $id, '_webp_original_path', true );
	$current_webp = get_attached_file( $id );

	if ( $backup_path && $wp_fs->exists( $backup_path ) ) {
		if ( $wp_fs->exists( $current_webp ) ) {
			$wp_fs->delete( $current_webp );
		}
		
		$original_ext  = strtolower( strrchr( $backup_path, '.' ) );
		$restored_path = preg_replace( '/\.webp$/i', $original_ext, $current_webp );
		$backup_dir    = dirname( $backup_path );

		if ( $wp_fs->move( $backup_path, $restored_path ) ) {
			$dir_content = $wp_fs->dirlist( $backup_dir );
			if ( empty( $dir_content ) ) {
				$wp_fs->rmdir( $backup_dir );
			}

			$rel     = get_post_meta( $id, '_wp_attached_file', true );
			update_post_meta( $id, '_wp_attached_file', preg_replace( '/\.webp$/i', $original_ext, $rel ) );
			
			wp_update_post( array(
				'ID'             => (int) $id,
				'post_mime_type' => ( '.png' === $original_ext ? 'image/png' : 'image/jpeg' ),
			) );
			
			delete_post_meta( $id, '_webp_original_path' );
			delete_post_meta( $id, '_webp_savings' );
			return true;
		}
	}
	return false;
}

/**
 * SECTION 3: ADMIN UI
 */
add_action( 'admin_menu', function() {
	add_management_page( esc_html__( 'WebP Optimizer', 'free-webp-optimizer-thisismyurl' ), esc_html__( 'WebP Optimizer', 'free-webp-optimizer-thisismyurl' ), 'manage_options', 'webp-optimizer', 'fwo_render_admin_page' );
});

function fwo_render_admin_page() {
	// Fetch optimized images via WP_Query
	$optimized_query = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 20,
		'post_mime_type' => 'image/webp',
		'meta_key'       => '_webp_original_path',
		'no_found_rows'  => true,
	) );
	$optimized = $optimized_query->posts;

	// Calculate total saved using Metadata API (No Direct SQL)
	$savings_query = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'meta_key'       => '_webp_savings',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	$total_saved = 0;
	if ( ! empty( $savings_query->posts ) ) {
		foreach ( $savings_query->posts as $sid ) {
			$total_saved += (int) get_post_meta( $sid, '_webp_savings', true );
		}
	}

	$pending_count = count( array_map( 'intval', (new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'post_mime_type' => array( 'image/jpeg', 'image/png' ),
		'no_found_rows'  => true,
	) ))->posts ) );

	?>
	<div class="wrap webp-optimizer-wrap">
		<h1><?php esc_html_e( 'Free WebP Optimizer', 'free-webp-optimizer-thisismyurl' ); ?> <small><?php esc_html_e( 'by thisismyurl', 'free-webp-optimizer-thisismyurl' ); ?></small></h1>

		<div class="welcome-panel">
			<div class="welcome-panel-content">
				<h2><?php esc_html_e( 'Total Server Space Saved:', 'free-webp-optimizer-thisismyurl' ); ?> <span class="savings-total"><?php echo esc_html( size_format( $total_saved ) ); ?></span></h2>
				<br>
				<button id="btn-start" class="button button-primary button-large" <?php disabled( $pending_count, 0 ); ?>>
					<?php 
					/* translators: %d: The number of images pending optimization */
					printf( esc_html__( 'Optimize %d Existing Images', 'free-webp-optimizer-thisismyurl' ), (int) $pending_count ); 
					?>
				</button>
			</div>
		</div>

		<div class="report-table-wrap">
			<h2><?php esc_html_e( 'Recent Optimizations', 'free-webp-optimizer-thisismyurl' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Preview', 'free-webp-optimizer-thisismyurl' ); ?></th>
						<th><?php esc_html_e( 'ID', 'free-webp-optimizer-thisismyurl' ); ?></th>
						<th><?php esc_html_e( 'Savings', 'free-webp-optimizer-thisismyurl' ); ?></th>
						<th><?php esc_html_e( 'Action', 'free-webp-optimizer-thisismyurl' ); ?></th>
					</tr>
				</thead>
				<tbody id="report-log">
					<?php if ( $optimized ) : foreach ( $optimized as $post_obj ) : 
						$img_id = (int) $post_obj->ID;
						$thumb  = wp_get_attachment_image( $img_id, array( 50, 50 ) ); 
					?>
						<tr>
							<td><?php echo $thumb ? wp_kses_post( $thumb ) : esc_html__( 'No Preview', 'free-webp-optimizer-thisismyurl' ); ?></td>
							<td>#<?php echo esc_html( $img_id ); ?></td>
							<td><?php echo esc_html( size_format( (int) get_post_meta( $img_id, '_webp_savings', true ) ) ); ?></td>
							<td><button class="restore-btn button button-small" data-id="<?php echo esc_attr( $img_id ); ?>"><?php esc_html_e( 'Restore', 'free-webp-optimizer-thisismyurl' ); ?></button></td>
						</tr>
					<?php endforeach; else : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No images optimized yet.', 'free-webp-optimizer-thisismyurl' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div class="danger-zone" style="margin-top:50px; border:1px solid #d63638; padding:20px; background:#fff;">
			<h3 style="color:#d63638;"><?php esc_html_e( 'Danger Zone', 'free-webp-optimizer-thisismyurl' ); ?></h3>
			<div id="fwo-progress-container" style="display:none; margin-bottom:15px; background:#f0f0f1; border:1px solid #c3c4c7; height:25px; position:relative;">
				<div id="fwo-progress-bar" style="background:#d63638; height:100%; width:0%;"></div>
				<div id="fwo-progress-text" style="position:absolute; width:100%; text-align:center; top:0; line-height:25px; font-weight:bold; color:#000; mix-blend-mode:difference;">0%</div>
			</div>
			<div id="fwo-status-message" style="margin-bottom:10px; font-style:italic;"></div>
			<button id="btn-rollback-all" class="button button-link-delete"><?php esc_html_e( 'Restore All & Prepare for Uninstall', 'free-webp-optimizer-thisismyurl' ); ?></button>
		</div>
	</div>
	<?php
}

/**
 * SECTION 4: AJAX ENDPOINTS
 */
add_action( 'wp_ajax_webp_bulk_optimize', function() {
	check_ajax_referer( 'fwo_webp_nonce' );
	$id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
	if ( fwo_convert_to_webp( $id ) ) {
		$rel = get_post_meta( $id, '_wp_attached_file', true );
		update_post_meta( $id, '_wp_attached_file', preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $rel ) );
		wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/webp' ) );
		wp_send_json_success();
	}
	wp_send_json_error();
});

add_action( 'wp_ajax_webp_restore', function() {
	check_ajax_referer( 'fwo_webp_nonce' );
	$id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
	if ( fwo_restore_single_image( $id ) ) {
		wp_send_json_success();
	}
	wp_send_json_error();
});