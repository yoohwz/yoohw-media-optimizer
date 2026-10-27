<?php
/**
 * Scoped handoff fixtures. Real both-plugin scenarios run in the companion TM smoke.
 *
 * @package YoOhw_Media_Optimizer
 */

// Keep fake counterparts out of a site where the real plugin is active.
if ( function_exists( 'yotm_optimizer_handoff' ) || function_exists( 'yotm_optimizer_active_job' ) ) {
	return static function() { WP_CLI::log( 'NOT RUN: synthetic Thumbnail Manager fixtures require the real counterpart to be inactive.' ); };
}

if ( ! function_exists( 'yotm_optimizer_handoff' ) ) {
function yotm_optimizer_handoff( $token, $page = 1, $attachment_id = 0 ) {
	$fixture = $GLOBALS['yoohw_mo_tm_fixture'] ?? array();
	if ( empty( $fixture['source'] ) || $token !== $fixture['source']['job_token'] || ! empty( $fixture['unavailable'] ) ) {
		return new WP_Error( 'fixture_unavailable', 'Saved Thumbnail Manager result is unavailable or expired.' );
	}
	$source = $fixture['source'];
	$source['page'] = (int) $page;
	$source['items'] = $fixture['pages'][ $page ] ?? array();
	if ( $attachment_id ) {
		$source['items'] = array();
		foreach ( $fixture['pages'] as $items ) {
			foreach ( $items as $item ) {
				if ( $attachment_id === $item['attachment_id'] ) { $source['items'][] = $item; }
			}
		}
	}
	return $source;
}
}

if ( ! function_exists( 'yotm_optimizer_active_job' ) ) {
function yotm_optimizer_active_job() {
	return ! empty( $GLOBALS['yoohw_mo_tm_fixture']['busy'] ) ? new WP_Error( 'fixture_busy', 'Thumbnail Manager is active.' ) : false;
}
}

if ( ! function_exists( 'yotm_optimizer_mutation_active' ) ) {
	function yotm_optimizer_mutation_active() {
		return ! empty( $GLOBALS['yoohw_mo_tm_fixture']['mutation'] );
	}
}

return static function( $optimizer, $invoke, $assert ) {
	$queue = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION, false );
	$options = get_option( YoOhw_Media_Optimizer::OPTION_KEY );
	$user = get_current_user_id();
	$uploads = wp_get_upload_dir();
	$dir = trailingslashit( $uploads['basedir'] ) . 'yoohw-mo-handoff-' . wp_generate_password( 8, false, false );
	$ids = array();
	$paths = array();
	$users = array();
	$token = '';
	try {
		foreach ( array( 'owner', 'other' ) as $name ) {
			$id = wp_insert_user( array( 'user_login' => 'mo-handoff-' . $name . '-' . wp_generate_password( 8, false, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
			$assert( ! is_wp_error( $id ), 'Could not create a handoff fixture user.' );
			$users[] = $id;
		}
		wp_set_current_user( $users[0] );
		wp_mkdir_p( $dir );
		$image = imagecreatetruecolor( 64, 64 );
		for ( $y = 0; $y < 64; ++$y ) {
			for ( $x = 0; $x < 64; ++$x ) { imagesetpixel( $image, $x, $y, imagecolorallocate( $image, $x * 3, $y * 3, 100 ) ); }
		}
		foreach ( array( 'full.png', 'selected.png', 'untouched.png' ) as $name ) {
			imagepng( $image, $dir . '/' . $name );
			$paths[] = $dir . '/' . $name;
		}
		imagedestroy( $image );
		$full = $dir . '/full.png';
		$selected = $dir . '/selected.png';
		$untouched = $dir . '/untouched.png';
		$relative = wp_basename( $dir ) . '/';
		$id = wp_insert_attachment( array( 'post_title' => 'Exact handoff', 'post_mime_type' => 'image/png' ), $full );
		$ids[] = $id;
		$metadata = array( 'file' => $relative . 'full.png', 'width' => 64, 'height' => 64, 'sizes' => array( 'selected' => array( 'file' => 'selected.png', 'width' => 64, 'height' => 64, 'mime-type' => 'image/png' ), 'untouched' => array( 'file' => 'untouched.png', 'width' => 64, 'height' => 64, 'mime-type' => 'image/png' ) ) );
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		$empty_id = wp_insert_attachment( array( 'post_title' => 'No journal outputs', 'post_mime_type' => 'image/png' ) );
		$ids[] = $empty_id;
		$settings = $options;
		$settings['optimize_originals'] = 1;
		$settings['auto_optimize_uploads'] = 1;
		$settings['generate_webp_sidecars'] = 1;
		$settings['generate_avif_sidecars'] = 0;
		$settings['skip_larger_files'] = 0;
		$settings['use_external_binaries'] = 0;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $settings );
		$tracked = array( 'options' => array( 'optimize_originals' => 1, 'compression_mode' => 'balanced', 'jpeg_quality' => 61, 'metadata_policy' => 'preserve_all', 'max_width' => 0, 'max_height' => 0, 'use_external_binaries' => 0 ), 'files' => array( array( 'source' => $relative . 'full.png', 'backup_path' => 'private:original-reference', 'original_status' => 'optimized', 'original_options_fingerprint' => 'keep-me', 'optimized_hash' => hash_file( 'sha256', $full ) ), array( 'source' => $relative . 'untouched.png', 'backup_path' => 'private:untouched-reference', 'original_status' => 'optimized', 'original_options_fingerprint' => 'keep-this-too' ), array( 'source' => $relative . 'selected.png', 'backup_path' => 'private:selected-reference', 'original_status' => 'optimized', 'optimized_size' => filesize( $selected ), 'original_size' => filesize( $selected ) + 123, 'saved_bytes' => 123, 'source_mtime' => filemtime( $selected ) ) ) );
		update_post_meta( $id, YoOhw_Media_Optimizer::META_KEY, $tracked );
		$files = array( array( 'size' => 'selected', 'path' => $relative . 'selected.png', 'sha256' => hash_file( 'sha256', $selected ) ) );
		$GLOBALS['yoohw_mo_tm_fixture'] = array( 'source' => array( 'schema' => 1, 'job_token' => 'fixture-saved-result', 'source_url' => admin_url( 'tools.php?page=thumbnail-manager&yotm_result_token=fixture-saved-result' ), 'scope_label' => 'Selected generated files', 'total' => 2, 'pages' => 2 ), 'pages' => array( 1 => array( array( 'attachment_id' => $id, 'title' => 'Exact handoff', 'files' => $files ) ), 2 => array( array( 'attachment_id' => $empty_id, 'title' => 'Empty journal', 'files' => array() ) ) ) );
		$before = array_map( static function( $path ) { return hash_file( 'sha256', $path ); }, $paths );
		delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$invoke( 'thumbnail_manager_source', array( 'fixture-saved-result' ) );
		$assert( false === get_option( YoOhw_Media_Optimizer::QUEUE_OPTION, false ), 'Read-only preview created a queue.' );
		$GLOBALS['yoohw_mo_tm_fixture']['mutation'] = true;
		$assert( $metadata === $optimizer->optimize_generated_metadata( $metadata, $id ), 'TM mutation metadata was changed by automatic optimization.' );
		$assert( ! file_exists( $selected . '.webp' ) && $tracked === get_post_meta( $id, YoOhw_Media_Optimizer::META_KEY, true ), 'Automatic optimization encoded or tracked inside TM scope.' );
		$GLOBALS['yoohw_mo_tm_fixture']['mutation'] = false;
		$GLOBALS['yoohw_mo_tm_fixture']['busy'] = true;
		$assert( ! empty( $optimizer->optimize_attachment( $id )['locked'] ), 'MO bypassed an active TM job.' );
		$token = $optimizer->begin_thumbnail_manager_mutation();
		$assert( is_string( $token ) && true === $optimizer->checkpoint_thumbnail_manager_mutation( $token ), 'TM could not own the shared mutex.' );
		$assert( is_wp_error( $optimizer->checkpoint_thumbnail_manager_mutation( 'wrong' ) ) && is_wp_error( $optimizer->end_thumbnail_manager_mutation( 'wrong' ) ), 'A wrong external token bypassed ownership.' );
		$assert( ! empty( $optimizer->optimize_attachment( $id )['locked'] ), 'A reentrant mutation inherited external ownership.' );
		$assert( true === $optimizer->end_thumbnail_manager_mutation( $token ), 'External ownership did not release.' );
		$token = '';
		$GLOBALS['yoohw_mo_tm_fixture']['busy'] = false;
		file_put_contents( $selected . '.webp', 'old-sidecar' );
		touch( $selected . '.webp', time() + 10 );
		$paths[] = $selected . '.webp';
		$run = $optimizer->run_thumbnail_manager_batch( 'fixture-saved-result', '', true, 1 );
		$assert( empty( $run['error'] ) && $run['hasMore'] && 1 === $run['processedTotal'], 'A pending source import page was treated as complete.' );
		$assert( 'old-sidecar' !== file_get_contents( $selected . '.webp' ), 'Same-path/same-mtime evidence did not rebuild the selected sidecar.' );
		$assert( ! file_exists( $full . '.webp' ) && ! file_exists( $untouched . '.webp' ), 'Handoff expanded to full or unrelated files.' );
		$after_tracking = get_post_meta( $id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( $tracked['options'] === $after_tracking['options'] && $tracked['files'][0] === $after_tracking['files'][0] && $tracked['files'][1] === $after_tracking['files'][1], 'Handoff lost untouched legacy provenance, backup or fingerprint history.' );
		$assert( 'private:selected-reference' === $after_tracking['files'][2]['backup_path'] && 'optimized' === $after_tracking['files'][2]['original_status'] && 123 === $after_tracking['files'][2]['saved_bytes'] && ! empty( $after_tracking['files'][2]['original_options_fingerprint'] ) && hash_file( 'sha256', $selected ) === $after_tracking['files'][2]['optimized_hash'], 'Selected legacy original history was not preserved/upgraded against its prior option provenance.' );
		$assert( $metadata === get_post_meta( $id, '_wp_attachment_metadata', true ), 'Sidecar handoff wrote attachment metadata.' );
		$assert( $before === array_map( static function( $path ) { return hash_file( 'sha256', $path ); }, array_slice( $paths, 0, 3 ) ), 'Handoff changed a source despite forced original disablement.' );
		$assert( ! empty( $optimizer->build_queue()['error'] ), 'Library queue building replaced an unfinished handoff.' );
		wp_set_current_user( $users[1] );
		$assert( ! empty( $optimizer->process_queue_batch( 1 )['error'] ), 'Another user resumed a handoff.' );
		wp_set_current_user( $users[0] );
		$done = $optimizer->run_thumbnail_manager_batch( 'fixture-saved-result', $run['runId'], false, 1 );
		$assert( empty( $done['error'] ) && ! $done['hasMore'] && 2 === $done['processedTotal'] && 1 === $done['skippedItems'], 'Empty exact evidence did not become an explicit skipped item.' );
		$assert( ! empty( $optimizer->run_browser_batch( $run['runId'], true, false, 1 )['error'] ), 'A completed scoped run became a library run.' );
		$repeat = $optimizer->run_thumbnail_manager_batch( 'fixture-saved-result', $run['runId'], true, 1 );
		$assert( empty( $repeat['error'] ) && 0 === $repeat['batchProcessed'] && $run['runId'] === $repeat['runId'], 'A completed handoff restarted implicitly.' );
		$saved_queue = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$invalid = $saved_queue;
		unset( $invalid['kind'] );
		update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $invalid, false );
		$assert( ! empty( $optimizer->process_queue_batch( 1 )['error'] ), 'Missing queue kind with handoff provenance fell back to library mode.' );
		$invalid['kind'] = 'unknown';
		update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $invalid, false );
		$assert( ! empty( $optimizer->process_queue_batch( 1 )['error'] ), 'Unknown queue kind fell back to library mode.' );
		$saved_queue['items'][ $id ]['status'] = 'running';
		$saved_queue['totals'] = $invoke( 'empty_queue_totals' );
		update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $saved_queue, false );
		file_put_contents( $selected . '.webp', 'retry-old-sidecar' );
		touch( $selected . '.webp', time() + 10 );
		$retried = $optimizer->process_queue_batch( 1 );
		$assert( empty( $retried['error'] ) && 'retry-old-sidecar' !== file_get_contents( $selected . '.webp' ), 'A reclaimed running handoff trusted an unrelated fresh sidecar.' );
		$saved_queue['items'][ $id ]['status'] = 'pending';
		update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $saved_queue, false );
		$sidecar_hash = hash_file( 'sha256', $selected . '.webp' );
		$GLOBALS['yoohw_mo_tm_fixture']['pages'][1][0]['files'][0]['sha256'] = str_repeat( '0', 64 );
		$changed = $optimizer->process_queue_batch( 1 );
		$assert( empty( $changed['error'] ) && $changed['batchFailed'] > 0 && $sidecar_hash === hash_file( 'sha256', $selected . '.webp' ), 'Changed evidence encoded a different source.' );
		$failed = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$assert( 'failed' === $failed['items'][ $id ]['status'] && ! empty( $failed['items'][ $id ]['message'] ), 'Changed source failure lost its per-item reason.' );
		foreach ( array( 'full', 'traversal', 'symlink', 'companion' ) as $case ) {
			$candidate = $files;
			$current_metadata = $metadata;
			if ( 'full' === $case ) { $candidate[0]['size'] = 'full'; $candidate[0]['path'] = $relative . 'full.png'; }
			if ( 'traversal' === $case ) { $candidate[0]['path'] = $relative . '../selected.png'; }
			if ( 'companion' === $case ) { $current_metadata['source_image'] = 'selected.png'; }
			if ( 'symlink' === $case ) {
				rename( $selected, $selected . '.real' );
				symlink( $selected . '.real', $selected );
			}
			update_post_meta( $id, '_wp_attachment_metadata', $current_metadata );
			$rejected = false;
			try { $invoke( 'thumbnail_manager_files', array( $id, $candidate ) ); } catch ( RuntimeException $error ) { $rejected = true; }
			$assert( $rejected, 'Unsafe exact generated-file evidence was accepted: ' . $case );
			if ( 'symlink' === $case ) { unlink( $selected ); rename( $selected . '.real', $selected ); }
		}
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		$GLOBALS['yoohw_mo_tm_fixture']['unavailable'] = true;
		$assert( ! empty( $optimizer->process_queue_batch( 1 )['error'] ), 'Expired/unavailable TM evidence resumed a scoped queue.' );
		$GLOBALS['yoohw_mo_tm_fixture']['unavailable'] = false;
		$GLOBALS['yoohw_mo_tm_fixture']['pages'] = array( 1 => array(), 2 => array( array( 'attachment_id' => $empty_id, 'title' => 'Empty file evidence', 'files' => array() ) ) );
		$GLOBALS['yoohw_mo_tm_fixture']['source']['total'] = 1;
		delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$first_empty = $optimizer->run_thumbnail_manager_batch( 'fixture-saved-result', '', true, 1 );
		$assert( empty( $first_empty['error'] ) && $first_empty['hasMore'] && 0 === $first_empty['batchProcessed'], 'An unfinished source import with zero imported items reported completion.' );
		$prior_import = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$refuse_page = static function( $value, $old ) {
			return isset( $value['handoff']['next_page'] ) && 3 === $value['handoff']['next_page'] ? $old : $value;
		};
		add_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::QUEUE_OPTION, $refuse_page, 10, 2 );
		$refused = $optimizer->process_queue_batch( 1 );
		remove_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::QUEUE_OPTION, $refuse_page );
		$assert( ! empty( $refused['error'] ) && $prior_import === get_option( YoOhw_Media_Optimizer::QUEUE_OPTION ), 'A failed page append advanced its persisted import cursor.' );
		$imported = $optimizer->process_queue_batch( 1 );
		$assert( empty( $imported['error'] ) && ! $imported['hasMore'] && 1 === $imported['processedTotal'], 'An import retry duplicated or lost its scoped source item.' );
		$wrong_site = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		++$wrong_site['handoff']['site'];
		update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $wrong_site, false );
		$assert( ! empty( $optimizer->process_queue_batch( 1 )['error'] ), 'A handoff resumed under another site identity.' );
	} finally {
		if ( $token ) { $optimizer->end_thumbnail_manager_mutation( $token ); }
		$GLOBALS['yoohw_mo_tm_fixture'] = array();
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options );
		foreach ( $ids as $id ) { wp_delete_attachment( $id, true ); }
		foreach ( $paths as $path ) { if ( file_exists( $path ) || is_link( $path ) ) { unlink( $path ); } }
		if ( file_exists( $dir . '/selected.png.real' ) ) { unlink( $dir . '/selected.png.real' ); }
		@rmdir( $dir );
		if ( false === $queue ) { delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION ); } else { update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $queue, false ); }
		wp_set_current_user( $user );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $users as $id ) { wp_delete_user( $id ); }
	}
};
