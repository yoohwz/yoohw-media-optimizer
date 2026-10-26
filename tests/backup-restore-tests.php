<?php
/**
 * Backup and restore filesystem fixtures, invoked by regression-tests.php.
 *
 * @package YoOhw_Media_Optimizer
 */

return static function( $optimizer, $invoke, $assert, $test_dir, $runtime_options ) {
	$fixture = dirname( untrailingslashit( ABSPATH ) ) . '/yoohw-mo-backup-test-' . wp_generate_password( 8, false, false );
	$private_root = $fixture . '/private';
	$root_filter = static function() use ( &$private_root ) {
		return $private_root;
	};
	$remove_tree = static function( $path ) use ( &$remove_tree ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
		} elseif ( is_dir( $path ) ) {
			foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $child ) {
				$remove_tree( $child->getPathname() );
			}
			rmdir( $path );
		}
	};
	$source = $test_dir . '/backup-restore.png';
	$link = $test_dir . '/backup-link';
	$attachment_id = 0;
	$original_options = get_option( YoOhw_Media_Optimizer::OPTION_KEY, array() );
	$block_delete = null;
	$upload_alias = null;
	$legacy = '';
	add_filter( 'yoohw_media_optimizer_backup_dir', $root_filter );
	try {
		wp_mkdir_p( $fixture . '/outside' );
		$image = imagecreatetruecolor( 64, 64 );
		imagepng( $image, $source, 0 );
		imagedestroy( $image );
		copy( $source, $fixture . '/outside/source.png' );
		$assert( symlink( $fixture . '/outside', $link ), 'Could not create the backup containment fixture.' );
		$assert( '' === $invoke( 'backup_path_for', array( $link . '/source.png' ) ), 'Backup source symlink escape was accepted.' );
		$unsafe_options = array_merge( $runtime_options, array( 'optimize_originals' => 1, 'generate_webp_sidecars' => 1 ) );
		$unsafe_result = $invoke( 'optimize_file', array( array( 'path' => $link . '/source.png' ), false, $unsafe_options ) );
		$assert( 'failed' === $unsafe_result['original_status'] && 'failed' === $unsafe_result['status'] && ! file_exists( $fixture . '/outside/source.png.webp' ), 'An unsafe original source continued into sidecar generation.' );
		$assert( '' === $invoke( 'backup_reference_to_path', array( $invoke( 'relative_upload_path', array( $source ) ) ) ), 'An arbitrary original upload was accepted as a backup reference.' );
		$assert( '' === $invoke( 'backup_path_for', array( $test_dir . '/x/../backup-restore.png' ) ), 'Dot-segment traversal was accepted.' );
		$assert( '' === $invoke( 'backup_path_for', array( $link . '/missing.png' ) ), 'Missing target below a symlink was accepted.' );
		unlink( $link );
		$assert( symlink( $fixture . '/absent', $link ), 'Could not create a dangling-link fixture.' );
		$assert( '' === $invoke( 'backup_path_for', array( $link . '/source.png' ) ), 'A dangling directory link was accepted.' );
		unlink( $link );
		$normal_root = $private_root;
		$private_root = $fixture . '/root-alias';
		$assert( symlink( ABSPATH, $private_root ), 'Could not create the private-root alias fixture.' );
		$assert( '' === $invoke( 'backup_base_dir' ), 'A private root alias into the document root was accepted.' );
		unlink( $private_root );
		$private_root = dirname( untrailingslashit( ABSPATH ) );
		$assert( '' === $invoke( 'backup_base_dir' ), 'A private root covering the document root was accepted.' );
		$private_root = $normal_root;
		wp_mkdir_p( $private_root );
		$assert( symlink( $fixture . '/outside', $private_root . '/site-' . get_current_blog_id() ), 'Could not create the site alias fixture.' );
		$assert( '' === $invoke( 'backup_base_dir' ), 'A site backup directory alias was accepted.' );
		unlink( $private_root . '/site-' . get_current_blog_id() );
		$overlap_uploads = $private_root . '/site-' . get_current_blog_id();
		wp_mkdir_p( $overlap_uploads );
		$overlap_file = $overlap_uploads . '/overlap.png';
		copy( $source, $overlap_file );
		$overlap_hash = hash_file( 'sha256', $overlap_file );
		$upload_alias = static function( $values ) use ( $overlap_uploads ) {
			$values['basedir'] = $overlap_uploads;
			return $values;
		};
		add_filter( 'upload_dir', $upload_alias );
		$assert( '' === $invoke( 'backup_base_dir' ) && is_wp_error( $invoke( 'backup_file', array( $overlap_file ) ) ), 'External uploads were accepted as their own private backup namespace.' );
		$overlap_result = $invoke( 'optimize_original_file', array( $overlap_file, $runtime_options, true ) );
		$assert( 'failed' === $overlap_result['status'], 'Original optimization bypassed overlapping backup storage.' );
		$assert( $overlap_hash === hash_file( 'sha256', $overlap_file ), 'Rejecting overlapping storage changed the original.' );
		remove_filter( 'upload_dir', $upload_alias );
		$alias_uploads = $fixture . '/overlap-upload-alias';
		$assert( symlink( $overlap_uploads, $alias_uploads ), 'Could not create an overlapping uploads alias.' );
		$upload_alias = static function( $values ) use ( $alias_uploads ) {
			$values['basedir'] = $alias_uploads;
			return $values;
		};
		add_filter( 'upload_dir', $upload_alias );
		$assert( '' === $invoke( 'backup_base_dir' ) && is_wp_error( $invoke( 'backup_file', array( $alias_uploads . '/overlap.png' ) ) ), 'An uploads alias bypassed the backup namespace overlap guard.' );
		remove_filter( 'upload_dir', $upload_alias );
		$upload_alias = null;
		$aliased_private_root = $fixture . '/private-root-alias';
		$assert( symlink( $private_root, $aliased_private_root ), 'Could not create an overlapping backup-root alias.' );
		$private_root = $aliased_private_root;
		$upload_alias = static function( $values ) use ( $overlap_uploads ) {
			$values['basedir'] = $overlap_uploads;
			return $values;
		};
		add_filter( 'upload_dir', $upload_alias );
		$assert( '' === $invoke( 'backup_base_dir' ), 'A private-root alias bypassed uploads overlap checks.' );
		remove_filter( 'upload_dir', $upload_alias );
		$upload_alias = null;
		$private_root = $normal_root;
		$original_blog_id = $GLOBALS['blog_id'];
		$first_site = $invoke( 'backup_base_dir' );
		try {
			$GLOBALS['blog_id'] = $original_blog_id + 1;
			$second_site = $invoke( 'backup_base_dir' );
		} finally {
			$GLOBALS['blog_id'] = $original_blog_id;
		}
		$assert( $first_site !== $second_site, 'Different blog IDs shared a backup namespace.' );

		// The configured uploads root itself may be an alias; descendants may not.
		$uploads = wp_get_upload_dir();
		$alias = $fixture . '/uploads-root';
		$assert( symlink( $uploads['basedir'], $alias ), 'Could not create an uploads-root alias.' );
		$upload_alias = static function( $values ) use ( $alias ) {
			$values['basedir'] = $alias;
			return $values;
		};
		add_filter( 'upload_dir', $upload_alias );
		$alias_source = $alias . '/' . wp_basename( $test_dir ) . '/backup-restore.png';
		$alias_backup = $invoke( 'backup_file', array( $alias_source ) );
		$assert( ! is_wp_error( $alias_backup ), 'A legitimate uploads-root alias could not be backed up.' );
		remove_filter( 'upload_dir', $upload_alias );
		$upload_alias = null;
		$backup = $invoke( 'backup_path_for', array( $source ) );
		$original_hash = hash_file( 'sha256', $source );
		$assert( $original_hash === hash_file( 'sha256', $backup ), 'The verified backup differs from the source.' );
		file_put_contents( $source, file_get_contents( $fixture . '/outside/source.png' ) . 'changed' );
		$again = $invoke( 'backup_file', array( $source ) );
		$assert( ! is_wp_error( $again ) && ! $again['created'] && $original_hash === hash_file( 'sha256', $backup ), 'An existing backup was overwritten.' );

		// Lookups are read-only; migration commits verified private data before deleting legacy.
		$legacy = $invoke( 'legacy_backup_path_for', array( $source ) );
		wp_mkdir_p( dirname( $legacy ) );
		copy( $backup, $legacy );
		unlink( $backup );
		$legacy_reference = YoOhw_Media_Optimizer::BACKUP_DIR . '/' . $invoke( 'relative_upload_path', array( $source ) );
		$resolved = $invoke( 'backup_reference_to_path', array( $legacy_reference, $source ) );
		$assert( $legacy === $resolved && file_exists( $legacy ) && ! file_exists( $backup ), 'Reading a legacy reference mutated its storage.' );
		$migrated = $invoke( 'backup_file', array( $source ) );
		$assert( ! is_wp_error( $migrated ) && ! file_exists( $legacy ) && $original_hash === hash_file( 'sha256', $backup ), 'Verified legacy migration failed.' );
		file_put_contents( $legacy, file_get_contents( $backup ) . 'different' );
		$conflict = $invoke( 'backup_file', array( $source ) );
		$assert( is_wp_error( $conflict ) && file_exists( $legacy ) && $original_hash === hash_file( 'sha256', $backup ), 'Conflicting migration discarded a recovery source.' );
		unlink( $legacy );

		$options = array_merge( $runtime_options, array( 'optimize_originals' => 0, 'generate_webp_sidecars' => 0, 'generate_avif_sidecars' => 0 ) );
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options );
		$attachment_id = wp_insert_attachment( array( 'post_title' => 'Backup restore regression', 'post_mime_type' => 'image/png' ), $source );
		$relative = $invoke( 'relative_upload_path', array( $source ) );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', array( 'file' => $relative, 'width' => 64, 'height' => 64, 'sizes' => array() ) );
		$reference = $invoke( 'backup_reference_for', array( $backup ) );
		$tracked_file = array( 'source' => $relative, 'backup_path' => $reference, 'original_status' => 'optimized', 'status' => 'existing', 'avif_status' => 'existing', 'saved_bytes' => 7, 'webp_size' => 7, 'sidecar_size' => 7, 'avif_size' => 7 );
		$assert( symlink( $fixture . '/outside', $link ), 'Could not create the restore target escape fixture.' );
		$outside_hash = hash_file( 'sha256', $fixture . '/outside/source.png' );
		$unsafe_source = $link . '/source.png';
		$unsafe_relative = $invoke( 'relative_upload_path', array( $unsafe_source ) );
		update_attached_file( $attachment_id, $unsafe_source );
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, array( 'files' => array( array_merge( $tracked_file, array( 'source' => $unsafe_relative, 'backup_path' => 'private:' . $unsafe_relative ) ) ) ) );
		$escaped = $optimizer->restore_attachment( $attachment_id );
		$assert( 0 === $escaped['restored'] && 1 === $escaped['failed'] && $outside_hash === hash_file( 'sha256', $fixture . '/outside/source.png' ), 'An unsafe restore target changed an outside file or escaped failure accounting.' );
		update_attached_file( $attachment_id, $source );
		unlink( $link );
		foreach ( array( $relative, 'private:other.png', 'private:../outside.png' ) as $bad_reference ) {
			$invalid = $tracked_file;
			$invalid['backup_path'] = $bad_reference;
			update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, array( 'files' => array( $invalid ) ) );
			$before = hash_file( 'sha256', $source );
			$failed = $optimizer->restore_attachment( $attachment_id );
			$assert( 0 === $failed['restored'] && 1 === $failed['failed'] && $before === hash_file( 'sha256', $source ), 'An invalid tracked backup was ignored or replaced by discovered data.' );
		}
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, array( 'files' => array( $tracked_file ) ) );
		chmod( $source, 0640 );
		$restored = $optimizer->restore_attachment( $attachment_id );
		clearstatcache( true, $source );
		$assert( 1 === $restored['restored'] && 0 === $restored['failed'] && $original_hash === hash_file( 'sha256', $source ), 'Safe restore did not recover the original snapshot.' );
		$assert( 0640 === ( fileperms( $source ) & 0777 ), 'Restore changed original permission bits.' );
		chmod( $source, 0000 );
		$zero_mode = $optimizer->restore_attachment( $attachment_id );
		clearstatcache( true, $source );
		$zero_bits = fileperms( $source ) & 0777;
		chmod( $source, 0640 );
		$assert( 0 === $zero_mode['restored'] && 1 === $zero_mode['failed'] && 0 === $zero_bits && $original_hash === hash_file( 'sha256', $source ), 'An inaccessible original was replaced or had permissions broadened.' );
		unlink( $source );
		$missing = $optimizer->restore_attachment( $attachment_id );
		$assert( 1 === $missing['restored'] && $original_hash === hash_file( 'sha256', $source ), 'A missing original could not be restored.' );
		$nested_source = $test_dir . '/missing/parents/nested.png';
		$nested_backup = $invoke( 'backup_path_for', array( $nested_source ) );
		wp_mkdir_p( dirname( $nested_backup ) );
		copy( $backup, $nested_backup );
		$nested_relative = $invoke( 'relative_upload_path', array( $nested_source ) );
		update_attached_file( $attachment_id, $nested_source );
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, array( 'files' => array( array_merge( $tracked_file, array( 'source' => $nested_relative, 'backup_path' => $invoke( 'backup_reference_for', array( $nested_backup ) ) ) ) ) ) );
		$nested = $optimizer->restore_attachment( $attachment_id );
		$assert( 1 === $nested['restored'] && $original_hash === hash_file( 'sha256', $nested_source ), 'A missing safe restore parent could not be recreated.' );
		update_attached_file( $attachment_id, $source );

		// One bad source must not prevent a safe source from restoring, or become restored itself.
		$bad_source = $test_dir . '/failed-restore.png';
		copy( $source, $bad_source );
		$bad_relative = $invoke( 'relative_upload_path', array( $bad_source ) );
		$bad_file = array_merge( $tracked_file, array( 'source' => $bad_relative, 'backup_path' => 'private:not-this-source.png' ) );
		file_put_contents( $source . '.webp', 'sidecar' );
		file_put_contents( $bad_source . '.webp', 'sidecar' );
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, array( 'files' => array( $tracked_file, $bad_file ) ) );
		$mixed = $optimizer->restore_attachment( $attachment_id );
		$tracking = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( 1 === $mixed['restored'] && 1 === $mixed['failed'], 'Partial restore counters were not per-file.' );
		$assert( 'restored' === $tracking['files'][0]['original_status'] && 'optimized' === $tracking['files'][1]['original_status'], 'Partial restore marked a failed file restored.' );
		$assert( ! file_exists( $source . '.webp' ) && file_exists( $bad_source . '.webp' ), 'Partial restore removed a failed source sidecar.' );
		unlink( $bad_source . '.webp' );
		unlink( $bad_source );

		file_put_contents( $source . '.webp', 'retained-sidecar' );
		$block_delete = static function( $path ) use ( $source ) {
			return $path === $source . '.webp' ? '' : $path;
		};
		add_filter( 'wp_delete_file', $block_delete );
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, array( 'files' => array( $tracked_file ) ) );
		$partial = $optimizer->restore_attachment( $attachment_id );
		$tracking = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( 1 === $partial['restored'] && 1 === $partial['failed'], 'Failed sidecar cleanup was reported as complete success.' );
		$assert( 'restored' === $tracking['files'][0]['original_status'] && 'failed' === $tracking['files'][0]['status'] && strlen( 'retained-sidecar' ) === $tracking['files'][0]['webp_size'], 'Remaining sidecar tracking did not reflect disk.' );
		remove_filter( 'wp_delete_file', $block_delete );
		$block_delete = null;

		chmod( $backup, 0000 );
		$unreadable = $optimizer->restore_attachment( $attachment_id );
		chmod( $backup, 0600 );
		$assert( 0 === $unreadable['restored'] && 1 === $unreadable['failed'] && $original_hash === hash_file( 'sha256', $source ), 'An unreadable backup overwrote the source.' );
		unlink( $backup );
		file_put_contents( $backup, '' );
		$empty = $optimizer->restore_attachment( $attachment_id );
		$assert( 0 === $empty['restored'] && 1 === $empty['failed'] && $original_hash === hash_file( 'sha256', $source ), 'An empty backup overwrote the source.' );
		$assert( is_wp_error( $invoke( 'backup_file', array( $source ) ) ) && 0 === filesize( $backup ), 'Invalid existing backup was silently overwritten.' );

		// Child processes exercise the real publishers with a controlled filesystem-copy boundary.
		$io = $fixture . '/io';
		wp_mkdir_p( $io . '/public/uploads' );
		file_put_contents( $io . '/public/uploads/source.png', str_repeat( 'replacement', 200 ) );
		file_put_contents( $io . '/public/uploads/current.png', str_repeat( 'current', 200 ) );
		$current_hash = hash_file( 'sha256', $io . '/public/uploads/current.png' );
		$replacement_hash = hash_file( 'sha256', $io . '/public/uploads/source.png' );
		$spawn = static function( $mode, $id = 'one' ) use ( $io, $assert ) {
			$pipes = array();
			$process = proc_open( array( PHP_BINARY, __DIR__ . '/backup-io-probe.php', $io, $mode, $id ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
			$assert( is_resource( $process ), 'Could not start the filesystem fault probe.' );
			fclose( $pipes[0] );
			return array( $process, $pipes );
		};
		$finish = static function( $child ) use ( $assert ) {
			$deadline = microtime( true ) + 15;
			while ( proc_get_status( $child[0] )['running'] && microtime( true ) < $deadline ) { usleep( 10000 ); }
			if ( proc_get_status( $child[0] )['running'] ) { proc_terminate( $child[0], 9 ); }
			$output = stream_get_contents( $child[1][1] );
			$error = stream_get_contents( $child[1][2] );
			fclose( $child[1][1] );
			fclose( $child[1][2] );
			proc_close( $child[0] );
			$data = json_decode( $output, true );
			$assert( is_array( $data ), 'Filesystem probe failed: ' . $error . $output );
			return $data;
		};
		foreach ( array( 'partial', 'corrupt', 'chmod-fail', 'rename-fail' ) as $mode ) {
			$data = $finish( $spawn( $mode ) );
			$assert( ! $data['success'] && $current_hash === hash_file( 'sha256', $io . '/public/uploads/current.png' ), 'A failed copy/verification/publish changed the current original.' );
			if ( 'rename-fail' !== $mode ) {
				$assert( array() === glob( $io . '/public/uploads/.yoohw-mo-stage-*' ), 'A failed copy/verification left writable temporary data.' );
			}
			foreach ( glob( $io . '/public/uploads/.yoohw-mo-stage-*' ) as $stage ) { unlink( $stage ); }
		}
		$io_legacy = $io . '/public/uploads/' . YoOhw_Media_Optimizer::BACKUP_DIR . '/source.png';
		wp_mkdir_p( dirname( $io_legacy ) );
		file_put_contents( $io_legacy, 'legacy-recoverable-original' );
		$legacy_hash = hash_file( 'sha256', $io_legacy );
		foreach ( array( 'legacy-partial', 'legacy-corrupt' ) as $mode ) {
			$data = $finish( $spawn( $mode ) );
			$assert( ! $data['success'] && $legacy_hash === hash_file( 'sha256', $io_legacy ) && ! file_exists( $io . '/private/site-1/source.png' ), 'Failed migration discarded or published unverified recovery data.' );
		}
		unlink( $io_legacy );
		$data = $finish( $spawn( 'link-fail' ) );
		$assert( ! $data['success'] && 'yoohw_mo_backup_publish_failed' === $data['error'] && ! file_exists( $io . '/private/site-1/source.png' ) && $replacement_hash === hash_file( 'sha256', $io . '/public/uploads/source.png' ), 'Unavailable exclusive publication did not fail closed.' );
		foreach ( glob( $io . '/private/site-1/.yoohw-mo-stage-*' ) as $stage ) { unlink( $stage ); }
		wp_mkdir_p( $io . '/private/site-1' );
		$first = $spawn( 'race', 'first' );
		$second = $spawn( 'race', 'second' );
		$deadline = microtime( true ) + 10;
		while ( ( ! file_exists( $io . '/ready-first' ) || ! file_exists( $io . '/ready-second' ) ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
		$both_ready = file_exists( $io . '/ready-first' ) && file_exists( $io . '/ready-second' );
		file_put_contents( $io . '/publish', 'go' );
		$one = $finish( $first );
		$two = $finish( $second );
		$assert( $both_ready && 1 === (int) $one['created'] + (int) $two['created'], 'Exclusive backup publication did not have exactly one winner.' );
		$assert( $one['success'] && $two['success'], 'Competing backup publishers did not retain a valid snapshot: ' . json_encode( array( $one, $two ) ) );
		$race_backup = $io . '/private/site-1/source.png';
		$assert( $replacement_hash === hash_file( 'sha256', $race_backup ), 'Competing publishers corrupted their backup.' );
		$assert( array() === glob( dirname( $race_backup ) . '/.yoohw-mo-stage-*' ), 'Competing publishers left temporary snapshots.' );

		$interrupted = $spawn( 'interrupt', 'interrupted' );
		$deadline = microtime( true ) + 5;
		while ( ! file_exists( $io . '/ready-interrupted' ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
		$ready = file_exists( $io . '/ready-interrupted' );
		proc_terminate( $interrupted[0], 9 );
		fclose( $interrupted[1][1] );
		fclose( $interrupted[1][2] );
		proc_close( $interrupted[0] );
		$assert( $ready && $current_hash === hash_file( 'sha256', $io . '/public/uploads/current.png' ) && $replacement_hash === hash_file( 'sha256', $race_backup ), 'Interruption before publish changed the original or backup.' );
	} finally {
		if ( $block_delete ) {
			remove_filter( 'wp_delete_file', $block_delete );
		}
		if ( $upload_alias ) {
			remove_filter( 'upload_dir', $upload_alias );
		}
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		remove_filter( 'yoohw_media_optimizer_backup_dir', $root_filter );
		foreach ( array( $link, $source, $source . '.webp', $source . '.avif', $test_dir . '/missing', $test_dir . '/failed-restore.png', $test_dir . '/failed-restore.png.webp', $legacy ) as $created ) {
			if ( is_link( $created ) || file_exists( $created ) ) {
				$remove_tree( $created );
			}
		}
		$remove_tree( $fixture );
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $original_options );
	}
};
