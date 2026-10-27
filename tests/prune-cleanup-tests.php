<?php
/**
 * Synthetic Prune evidence tests; the counterpart smoke tests real TM authority.
 *
 * @package YoOhw_Media_Optimizer
 */

if ( function_exists( 'yotm_optimizer_prune_handoff' ) || function_exists( 'yotm_optimizer_with_cleanup_guard' ) ) {
	return static function() { WP_CLI::log( 'NOT RUN: synthetic Prune cleanup fixtures require the real counterpart to be inactive.' ); };
}

if ( ! function_exists( 'yotm_optimizer_prune_handoff' ) ) {
function yotm_optimizer_prune_handoff( $token, $page = 1, $key = '' ) {
	$fixture = $GLOBALS['yoohw_mo_prune_fixture'] ?? array();
	if ( ! empty( $fixture['unavailable'] ) || $token !== ( $fixture['source']['job_token'] ?? '' ) ) { return new WP_Error( 'unavailable', 'Prune evidence is unavailable or expired.' ); }
	$source = $fixture['source'];
	$source['page'] = $page;
	if ( $key ) { $source['items'] = array_values( array_filter( $source['items'], static function( $item ) use ( $key ) { return $item['key'] === $key; } ) ); }
	return $source;
}

}

if ( ! function_exists( 'yotm_optimizer_with_cleanup_guard' ) ) {
function yotm_optimizer_with_cleanup_guard( $paths, $callback ) {
	if ( ! empty( $GLOBALS['yoohw_mo_prune_fixture']['guard_error'] ) ) { return new WP_Error( 'guard', 'Cleanup guard unavailable.' ); }
	return $callback( static function() { return empty( $GLOBALS['yoohw_mo_prune_fixture']['lost_guard'] ) ? true : new WP_Error( 'lost', 'Cleanup guard ownership was lost.' ); } );
}

}

if ( ! function_exists( 'yotm_media_reference_path_owners' ) ) {
	function yotm_media_reference_path_owners( $path ) {
		if ( ! empty( $GLOBALS['yoohw_mo_prune_fixture']['dirty'] ) ) { return new WP_Error( 'dirty', 'Reference safety is unavailable.' ); }
		return $GLOBALS['yoohw_mo_prune_fixture']['references'][ $path ] ?? array( 'protected' => array(), 'generated' => array() );
	}
}

return static function( $optimizer, $invoke, $assert ) {
	$options = get_option( YoOhw_Media_Optimizer::OPTION_KEY );
	$cleanup = get_option( YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION, false );
	$revision = get_option( YoOhw_Media_Optimizer::SOURCE_REVISION_OPTION, false );
	$user = get_current_user_id();
	$uploads = wp_get_upload_dir();
	$relative = 'mo-prune-' . wp_generate_password( 8, false, false );
	$dir = trailingslashit( $uploads['basedir'] ) . $relative;
	$id = 0;
	$users = array();
	$paths = array();
	$fault = null;
	try {
		delete_option( YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION );
		foreach ( array( 'owner', 'other' ) as $name ) {
			$created = wp_insert_user( array( 'user_login' => 'mo-prune-' . $name . '-' . wp_generate_password( 8, false, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
			$assert( ! is_wp_error( $created ), 'Could not create cleanup fixture owner.' );
			$users[] = $created;
		}
		wp_set_current_user( $users[0] );
		wp_mkdir_p( $dir );
		$image = imagecreatetruecolor( 64, 64 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 50, 140, 220 ) );
		imagepng( $image, $dir . '/full.png' );
		imagedestroy( $image );
		$paths[] = $dir . '/full.png';
		$id = wp_insert_attachment( array( 'post_title' => 'Prune cleanup fixture', 'post_mime_type' => 'image/png' ), $dir . '/full.png' );
		$metadata = array( 'file' => $relative . '/full.png', 'width' => 64, 'height' => 64, 'sizes' => array() );
		$items = array();
		for ( $n = 1; $n <= 12; ++$n ) {
			$name = 'size-' . $n . '.png';
			copy( $dir . '/full.png', $dir . '/' . $name );
			$paths[] = $dir . '/' . $name;
			$paths[] = $dir . '/' . $name . '.webp';
			$metadata['sizes'][ 'size_' . $n ] = array( 'file' => $name, 'width' => 64, 'height' => 64, 'mime-type' => 'image/png' );
			$items[] = array( 'key' => 'deleted-' . $n, 'attachment_id' => (int) $id, 'path' => $relative . '/' . $name, 'sha256' => hash_file( 'sha256', $dir . '/' . $name ) );
		}
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		$settings = $options;
		$settings['optimize_originals'] = 0;
		$settings['generate_webp_sidecars'] = 1;
		$settings['generate_avif_sidecars'] = 0;
		$settings['skip_larger_files'] = 0;
		$settings['use_external_binaries'] = 0;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $settings );
		$before_revision = $optimizer->get_source_revision( $id );
		$generated = $optimizer->optimize_attachment( $id, true );
		$assert( empty( $generated['error'] ), 'Could not generate proven cleanup fixtures.' );
		$assert( $before_revision === $optimizer->get_source_revision( $id ), 'Sidecar-only processing changed the source revision.' );
		$tracking = get_post_meta( $id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( count( $tracking['files'] ) === 13, 'Cleanup fixture tracking is incomplete.' );
		foreach ( $tracking['files'] as $row ) { $assert( ! empty( $row['sidecar_ownership']['webp'] ), 'Actual WebP creation did not record ownership.' ); }
		// A fresh discovered file must never gain historical ownership.
		$legacy = $tracking;
		foreach ( $legacy['files'] as &$row ) { if ( $row['source'] === $items[11]['path'] ) { unset( $row['sidecar_ownership'] ); } }
		unset( $row );
		update_post_meta( $id, YoOhw_Media_Optimizer::META_KEY, $legacy );
		$optimizer->optimize_attachment( $id, false );
		$tracking = get_post_meta( $id, YoOhw_Media_Optimizer::META_KEY, true );
		foreach ( $tracking['files'] as $row ) { if ( $row['source'] === $items[11]['path'] ) { $assert( empty( $row['sidecar_ownership'] ), 'Legacy sidecar was granted ownership by discovery.' ); } }
		foreach ( $items as $item ) { unlink( $uploads['basedir'] . '/' . $item['path'] ); }
		$GLOBALS['yoohw_mo_prune_fixture'] = array( 'source' => array( 'schema' => 1, 'job_token' => 'fixture-completed-prune', 'source_url' => admin_url( 'tools.php?page=thumbnail-manager' ), 'scope_label' => 'Deleted thumbnail fixtures', 'page' => 1, 'pages' => 1, 'total' => count( $items ), 'items' => $items ) );
		$run = $optimizer->prepare_prune_cleanup( 'fixture-completed-prune' );
		$assert( empty( $run['error'] ) && count( $run['items'] ) === 11 && count( $run['preserved'] ) === 13, 'Preview must select only exact owned WebP sidecars.' );
		$absent_rows = array_filter( $run['preserved'], static function( $row ) { return ( $row['status'] ?? '' ) === 'skipped' && $row['message'] === 'No sidecar exists; no cleanup is needed.'; } );
		$legacy_rows = array_filter( $run['preserved'], static function( $row ) use ( $items ) { return $row['path'] === $items[11]['path'] . '.webp' && ( $row['status'] ?? '' ) === 'preserved'; } );
		$assert( count( $absent_rows ) === 12 && count( $legacy_rows ) === 1, 'Absent AVIF files must be skipped; an existing unowned legacy sidecar must remain preserved.' );

		$assert( ! empty( $optimizer->prepare_prune_cleanup( 'fixture-completed-prune' )['error'] ), 'An unfinished preview was replaced.' );
		$assert( ! empty( $optimizer->process_prune_cleanup( $run['id'], $run['hash'] )['error'] ), 'An unapproved preview deleted files.' );
		$assert( ! empty( $optimizer->approve_prune_cleanup( $run['id'], str_repeat( '0', 64 ) )['error'] ), 'A tampered approval hash was accepted.' );
		wp_set_current_user( $users[1] );
		$assert( ! empty( $optimizer->approve_prune_cleanup( $run['id'], $run['hash'] )['error'] ) && ! empty( $optimizer->discard_prune_cleanup( $run['id'] )['error'] ), 'Another administrator adopted/discarded cleanup ownership.' );
		wp_set_current_user( $users[0] );
		wp_set_current_user( 0 );
		$assert( ! empty( $optimizer->approve_prune_cleanup( $run['id'], $run['hash'] )['error'] ), 'An unauthenticated caller approved cleanup.' );
		wp_set_current_user( $users[0] );
		$expired = $run;
		$expired['created'] = time() - 901;
		$expired['expires'] = $expired['created'] + 900;
		$expired['hash'] = $invoke( 'cleanup_manifest_hash', array( $expired ) );
		update_option( YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION, $expired, false );
		$assert( ! empty( $optimizer->approve_prune_cleanup( $expired['id'], $expired['hash'] )['error'] ), 'Expired cleanup approval was accepted.' );
		update_option( YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION, $run, false );
		$wrong_site = $run;
		++$wrong_site['site'];
		$wrong_site['hash'] = $invoke( 'cleanup_manifest_hash', array( $wrong_site ) );
		update_option( YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION, $wrong_site, false );
		$assert( ! empty( $optimizer->approve_prune_cleanup( $wrong_site['id'], $wrong_site['hash'] )['error'] ), 'Cross-site cleanup approval was accepted.' );
		update_option( YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION, $run, false );
		$approved = $optimizer->approve_prune_cleanup( $run['id'], $run['hash'] );
		$assert( ! empty( $approved['approved'] ), 'Exact cleanup approval failed.' );
		file_put_contents( $dir . '/size-7.png.webp', 'changed sidecar bytes' );
		copy( $dir . '/full.png', $dir . '/size-8.png' );
		$GLOBALS['yoohw_mo_prune_fixture']['references'][ $dir . '/size-9.png.webp' ] = array( 'protected' => array(), 'generated' => array( 99 ) );
		copy( $dir . '/size-10.png.webp', $dir . '/replacement.webp' );
		rename( $dir . '/replacement.webp', $dir . '/size-10.png.webp' );
		unlink( $dir . '/size-11.png.webp' );
		symlink( $dir . '/full.png.webp', $dir . '/size-11.png.webp' );
		$first = $optimizer->process_prune_cleanup( $run['id'], $run['hash'] );
		$assert( empty( $first['error'] ) && $first['cursor'] === 8, 'Cleanup request exceeded/failed its bounded batch.' );
		$second = $optimizer->process_prune_cleanup( $run['id'], $run['hash'] );
		$assert( empty( $second['error'] ) && $second['cursor'] === 11, 'Explicit cleanup continuation failed.' );
		$statuses = array_count_values( array_column( $second['outcomes'], 'status' ) );
		$assert( ( $statuses['deleted'] ?? 0 ) === 6 && ( $statuses['preserved'] ?? 0 ) === 5, 'Changed/source-restored/referenced/replaced/symlink files were not preserved.' );
		$assert( is_file( $dir . '/full.png' ) && is_file( $dir . '/full.png.webp' ) && is_file( $dir . '/size-12.png.webp' ), 'Cleanup removed original or unowned media.' );
		$assert( $optimizer->process_prune_cleanup( $run['id'], $run['hash'] )['cursor'] === 11, 'A completed cleanup restarted.' );
		$assert( ! empty( $optimizer->discard_prune_cleanup( $run['id'] )['discarded'] ), 'Could not discard completed cleanup results.' );

		// A failed result write after unlink must retain the frozen identity on replay.
		copy( $dir . '/full.png', $dir . '/size-1.png' );
		$optimizer->optimize_attachment( $id, true );
		unlink( $dir . '/size-1.png' );
		$GLOBALS['yoohw_mo_prune_fixture']['source']['items'] = array( $items[0] );
		$GLOBALS['yoohw_mo_prune_fixture']['source']['total'] = 1;
		$run = $optimizer->prepare_prune_cleanup( 'fixture-completed-prune' );
		$assert( count( $run['items'] ) === 1, 'Replay fixture has no ownership proof.' );
		$optimizer->approve_prune_cleanup( $run['id'], $run['hash'] );
		$sidecar_bytes = file_get_contents( $dir . '/size-1.png.webp' );
		$fault = static function( $value, $old ) { return is_array( $value ) && ( $value['cursor'] ?? 0 ) === 1 ? $old : $value; };
		add_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION, $fault, 10, 2 );
		$failed = $optimizer->process_prune_cleanup( $run['id'], $run['hash'] );
		remove_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION, $fault, 10 );
		$fault = null;
		$assert( ! empty( $failed['error'] ) && ! file_exists( $dir . '/size-1.png.webp' ), 'Post-unlink persistence fault was not reported.' );
		file_put_contents( $dir . '/replacement.webp', $sidecar_bytes );
		rename( $dir . '/replacement.webp', $dir . '/size-1.png.webp' );
		$retry = $optimizer->process_prune_cleanup( $run['id'], $run['hash'] );
		$assert( $retry['outcomes'][0]['status'] === 'preserved' && file_exists( $dir . '/size-1.png.webp' ), 'Replay removed a replacement after a failed result write.' );
		$optimizer->discard_prune_cleanup( $run['id'] );

		// Duplicate raw metadata and unavailable reference safety cannot grant authority.
		$duplicate = add_post_meta( $id, YoOhw_Media_Optimizer::META_KEY, $tracking );
		$run = $optimizer->prepare_prune_cleanup( 'fixture-completed-prune' );
		$assert( count( $run['items'] ) === 0, 'Duplicate ownership postmeta granted destructive authority.' );
		delete_metadata_by_mid( 'post', $duplicate );
		$optimizer->discard_prune_cleanup( $run['id'] );
		$GLOBALS['yoohw_mo_prune_fixture']['dirty'] = true;
		$run = $optimizer->prepare_prune_cleanup( 'fixture-completed-prune' );
		$assert( count( $run['items'] ) === 0, 'Dirty reference evidence granted destructive authority.' );
		$GLOBALS['yoohw_mo_prune_fixture']['unavailable'] = true;
		$assert( ! empty( $optimizer->discard_prune_cleanup( $run['id'] )['discarded'] ), 'Unavailable source evidence blocked owner discard.' );
		$assert( ! empty( $optimizer->prepare_prune_cleanup( 'fixture-completed-prune' )['error'] ), 'Unavailable Prune evidence was accepted.' );
		$assert( ! empty( $optimizer->discard_prune_cleanup( $run['id'] )['error'] ), 'A stale discarded run was accepted.' );
		$before = $optimizer->get_source_revision( $id );
		$restore = $optimizer->restore_attachment( $id );
		$after = $optimizer->get_source_revision( $id );
		$assert( empty( $restore['error'] ) && $after['token'] !== $before['token'] && false === $after['changing'], 'A no-change restore attempt did not rotate/finish the conservative revision.' );
		$full_hash = hash_file( 'sha256', $dir . '/full.png' );
		$settings['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $settings );
		$marker_fault = static function( $value, $old ) { return $old; };
		add_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::SOURCE_REVISION_OPTION, $marker_fault, 10, 2 );
		$refused = $optimizer->optimize_attachment( $id, true );
		remove_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::SOURCE_REVISION_OPTION, $marker_fault, 10 );
		$assert( ! empty( $refused['error'] ) && hash_file( 'sha256', $dir . '/full.png' ) === $full_hash, 'A failed pre-marker allowed original source publication.' );
	} finally {
		if ( $fault ) { remove_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION, $fault, 10 ); }
		unset( $GLOBALS['yoohw_mo_prune_fixture'] );
		if ( $id ) { wp_delete_attachment( $id, true ); }
		foreach ( array_merge( $paths, array( $dir . '/full.png.webp', $dir . '/replacement.webp' ) ) as $path ) { if ( file_exists( $path ) || is_link( $path ) ) { unlink( $path ); } }
		@rmdir( $dir );
		foreach ( $users as $created ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $created ); }
		wp_set_current_user( $user );
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options );
		if ( false === $cleanup ) { delete_option( YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION ); } else { update_option( YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION, $cleanup, false ); }
		if ( false === $revision ) { delete_option( YoOhw_Media_Optimizer::SOURCE_REVISION_OPTION ); } else { update_option( YoOhw_Media_Optimizer::SOURCE_REVISION_OPTION, $revision, false ); }
	}
	WP_CLI::log( 'Prune cleanup synthetic ownership, bounded deletion and replay checks passed.' );
};
