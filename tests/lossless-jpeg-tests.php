<?php
/**
 * Lossless JPEG regression fixtures, invoked by regression-tests.php.
 *
 * @package YoOhw_Media_Optimizer
 */

return static function( $optimizer, $invoke, $assert, $test_dir, $runtime_options ) {
	$original_options = get_option( YoOhw_Media_Optimizer::OPTION_KEY, array() );
	$path = $test_dir . '/lossless.jpg';
	$backup = $invoke( 'backup_path_for', array( $path ) );
	$attachment_id = 0;
	$binary_dir = $test_dir . '/lossless-tools';
	$original_path = getenv( 'PATH' );
	$original_real_tool = getenv( 'YOOHW_MO_TEST_REAL_JPEGTRAN' );
	$gd_editors = static function() {
		return array( 'WP_Image_Editor_GD' );
	};
	$pixel_hash = static function( $file ) {
		$image = imagecreatefromjpeg( $file );
		$hash = hash_init( 'sha256' );
		for ( $y = 0; $y < imagesy( $image ); ++$y ) {
			for ( $x = 0; $x < imagesx( $image ); ++$x ) {
				hash_update( $hash, pack( 'N', imagecolorat( $image, $x, $y ) ) );
			}
		}
		imagedestroy( $image );
		return hash_final( $hash );
	};

	add_filter( 'wp_image_editors', $gd_editors, 20 );
	try {
		$image = imagecreatetruecolor( 96, 64 );
		for ( $y = 0; $y < 64; ++$y ) {
			for ( $x = 0; $x < 96; ++$x ) {
				$color = imagecolorallocate( $image, ( $x * 17 + $y * 3 ) % 256, ( $y * 23 + $x ) % 256, ( $x * 7 + $y * 13 ) % 256 );
				imagesetpixel( $image, $x, $y, $color );
			}
		}
		ob_start();
		imagejpeg( $image, null, 82 );
		$jpeg = ob_get_clean();
		imagedestroy( $image );
		// Valid EXIF orientation plus a large APP segment makes GD re-encoding smaller, but lossy.
		$exif = "Exif\0\0II\x2a\0" . pack( 'Vv', 8, 1 ) . pack( 'vvVv', 0x0112, 3, 1, 6 ) . "\0\0" . pack( 'V', 0 );
		$app = str_repeat( 'Lossless metadata fixture. ', 1500 );
		$bytes = substr( $jpeg, 0, 2 ) . "\xff\xe1" . pack( 'n', strlen( $exif ) + 2 ) . $exif
			. "\xff\xef" . pack( 'n', strlen( $app ) + 2 ) . $app . substr( $jpeg, 2 );
		file_put_contents( $path, $bytes );
		if ( function_exists( 'exif_read_data' ) ) {
			$exif_data = exif_read_data( $path );
			$assert( 6 === (int) ( $exif_data['Orientation'] ?? 0 ), 'The lossless fixture does not contain valid EXIF orientation.' );
		}
		$source_hash = hash_file( 'sha256', $path );
		$source_pixels = $pixel_hash( $path );
		$attachment_id = wp_insert_attachment( array( 'post_title' => 'Lossless JPEG regression', 'post_mime_type' => 'image/jpeg' ), $path );
		$assert( $attachment_id > 0, 'Could not create the lossless JPEG attachment.' );
		$options = array_merge( $runtime_options, array( 'optimize_originals' => 1, 'compression_mode' => 'lossless', 'generate_webp_sidecars' => 0, 'generate_avif_sidecars' => 0, 'use_external_binaries' => 0, 'max_width' => 0, 'max_height' => 0 ) );

		foreach ( array( 'remove', 'preserve_all' ) as $policy ) {
			foreach ( array( false, true ) as $force ) {
				file_put_contents( $path, $bytes );
				clearstatcache( true, $path );
				delete_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY );
				$options['metadata_policy'] = $policy;
				update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options );
				$result = $optimizer->optimize_attachment( $attachment_id, $force );
				$assert( $source_pixels === $pixel_hash( $path ), 'Lossless JPEG fallback changed decoded pixels without resizing.' );
				$assert( $source_hash === hash_file( 'sha256', $path ), 'Lossless JPEG fallback changed source bytes/EXIF without a lossless encoder.' );
				$assert( 'no_change' === $result['files'][0]['original_status'], 'Lossless JPEG without an encoder was not skipped.' );
				$assert( ! file_exists( $backup ), 'A skipped fresh JPEG created a backup.' );
				$assert( 0 === $result['summary']['original_optimized'] && 1 === $result['summary']['original_skipped'], 'A skipped JPEG reported original optimization.' );
				$assert( 0 === $optimizer->attachment_savings( $attachment_id )['original_saved_bytes'], 'A skipped fresh JPEG reported new savings.' );
				if ( 'remove' === $policy || $force ) {
					$assert( '' !== $result['files'][0]['original_message'], 'Skipped lossless JPEG did not explain why it was left unchanged.' );
				}
			}
		}
		$options['metadata_policy'] = 'remove';
		$options['generate_webp_sidecars'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options );
		delete_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY );
		$sidecar = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'no_change' === $sidecar['files'][0]['original_status'] && 'created' === $sidecar['files'][0]['status'], 'Skipping a lossless JPEG prevented independent WebP generation.' );
		$assert( $source_hash === hash_file( 'sha256', $path ), 'Independent sidecar generation changed the skipped JPEG.' );
		$options['generate_webp_sidecars'] = 0;

		// Encoder-success cases must be neutral: stripping Orientation=6 changes display.
		$exif = "Exif\0\0II\x2a\0" . pack( 'Vv', 8, 1 ) . pack( 'vvVv', 0x0112, 3, 1, 1 ) . "\0\0" . pack( 'V', 0 );
		$bytes = substr( $jpeg, 0, 2 ) . "\xff\xe1" . pack( 'n', strlen( $exif ) + 2 ) . $exif
			. "\xff\xef" . pack( 'n', strlen( $app ) + 2 ) . $app . substr( $jpeg, 2 );
		file_put_contents( $path, $bytes );
		clearstatcache( true, $path );
		$source_hash = hash_file( 'sha256', $path );
		$source_pixels = $pixel_hash( $path );
		delete_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY );

		// Controlled encoders exercise success, no gain, and partial-output failure without dependencies.
		wp_mkdir_p( $binary_dir );
		file_put_contents( $binary_dir . '/smaller.jpg', $jpeg );
		file_put_contents( $binary_dir . '/jpegoptim', <<<'SH'
#!/bin/sh
directory=${0%/*}
IFS= read -r mode < "$directory/mode"
printf 'jpegoptim\n' >> "$directory/calls"
for argument in "$@"; do destination=$argument; done
if [ "$mode" = fail ]; then printf partial > "$destination"; exit 1; fi
if [ "$mode" = no_gain ]; then exit 0; fi
exit 1
SH
		);
		file_put_contents( $binary_dir . '/jpegtran', <<<'SH'
#!/bin/sh
directory=${0%/*}
IFS= read -r mode < "$directory/mode"
printf 'jpegtran\n' >> "$directory/calls"
destination=
previous=
for argument in "$@"; do
    if [ "$previous" = -outfile ]; then destination=$argument; fi
    previous=$argument
    source=$argument
done
case "$mode" in
    fail) printf partial > "$destination"; exit 1 ;;
    no_gain) /bin/cp "$source" "$destination" ;;
    success) /bin/cp "$directory/smaller.jpg" "$destination" ;;
    real) exec "$YOOHW_MO_TEST_REAL_JPEGTRAN" "$@" ;;
    *) exit 1 ;;
esac
SH
		);
		chmod( $binary_dir . '/jpegoptim', 0700 );
		chmod( $binary_dir . '/jpegtran', 0700 );
		putenv( 'PATH=' . $binary_dir . PATH_SEPARATOR . (string) $original_path );
		$options['metadata_policy'] = 'remove';
		$options['use_external_binaries'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options );
		foreach ( array( 'fail', 'no_gain' ) as $mode ) {
			file_put_contents( $binary_dir . '/mode', $mode . "\n" );
			delete_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY );
			$result = $optimizer->optimize_attachment( $attachment_id, true );
			$assert( 'no_change' === $result['files'][0]['original_status'], 'Unsuccessful lossless encoder did not skip JPEG fallback.' );
			$assert( $source_hash === hash_file( 'sha256', $path ) && $source_pixels === $pixel_hash( $path ), 'Unsuccessful lossless encoder changed the source.' );
			$assert( ! file_exists( $backup ), 'Unsuccessful lossless encoder created a backup.' );
			$assert( array() === glob( $test_dir . '/.lossless.jpg.yoohw-mo-bin-*' ), 'Unsuccessful lossless encoder left temporary output.' );
		}
		$calls = file_get_contents( $binary_dir . '/calls' );
		$assert( 2 === substr_count( $calls, 'jpegoptim' ) && 2 === substr_count( $calls, 'jpegtran' ), 'The failure/no-gain encoders did not actually execute.' );

		file_put_contents( $binary_dir . '/mode', "success\n" );
		$repeat = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'existing' === $repeat['files'][0]['original_status'], 'The no-change fingerprint stopped protecting repeated processing.' );
		$assert( $calls === file_get_contents( $binary_dir . '/calls' ), 'Repeated processing retried an encoder without force or changed options.' );
		$success = $optimizer->optimize_attachment( $attachment_id, true );
		$assert( 'optimized' === $success['files'][0]['original_status'] && 'jpegtran' === $success['files'][0]['engine'], 'A smaller lossless encoder output was blocked.' );
		$assert( $source_pixels === $pixel_hash( $path ), 'The successful fixture changed decoded JPEG pixels.' );
		$assert( $source_hash === hash_file( 'sha256', $backup ), 'Lossless replacement did not preserve the original backup.' );

		$real_jpegtran = '';
		foreach ( array( '/opt/homebrew/bin/jpegtran', '/usr/local/bin/jpegtran', '/usr/bin/jpegtran' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				$real_jpegtran = $candidate;
				break;
			}
		}
		if ( $real_jpegtran ) {
			putenv( 'YOOHW_MO_TEST_REAL_JPEGTRAN=' . $real_jpegtran );
			file_put_contents( $binary_dir . '/mode', "real\n" );
			file_put_contents( $path, $bytes );
			clearstatcache( true, $path );
			$real = $optimizer->optimize_attachment( $attachment_id, true );
			$assert( 'optimized' === $real['files'][0]['original_status'] && 'jpegtran' === $real['files'][0]['engine'], 'Real jpegtran did not optimize the metadata-heavy JPEG.' );
			$assert( $source_pixels === $pixel_hash( $path ), 'Real jpegtran changed decoded JPEG pixels.' );
			$assert( $source_hash === hash_file( 'sha256', $backup ), 'Real jpegtran overwrote the recoverable backup.' );
			WP_CLI::log( 'Lossless JPEG real jpegtran checks: PASS.' );
		} else {
			WP_CLI::log( 'Lossless JPEG real jpegtran checks: NOT RUN — binary unavailable; controlled encoders ran.' );
		}

		// Resizing remains allowed, with the untouched original retained before replacement.
		file_put_contents( $path, $bytes );
		clearstatcache( true, $path );
		$options['use_external_binaries'] = 0;
		$options['max_width'] = 48;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options );
		$resized = $optimizer->optimize_attachment( $attachment_id, true );
		$dimensions = $invoke( 'image_dimensions', array( $path ) );
		$assert( 'optimized' === $resized['files'][0]['original_status'] && $dimensions['width'] <= 48 && $dimensions['height'] <= 32, 'The permitted lossless-mode resize no longer works.' );
		$assert( $source_hash === hash_file( 'sha256', $backup ), 'Resizing overwrote the recoverable original backup.' );
		$resized_hash = hash_file( 'sha256', $path );
		$options['max_width'] = 0;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options );
		$existing_backup = $optimizer->optimize_attachment( $attachment_id, true );
		$assert( 'no_change' === $existing_backup['files'][0]['original_status'] && $resized_hash === hash_file( 'sha256', $path ), 'Forced no-resize lossless processing re-encoded an already optimized JPEG.' );
		$assert( 1 === $existing_backup['summary']['backed_up'] && filesize( $backup ) - filesize( $path ) === $optimizer->attachment_savings( $attachment_id )['original_saved_bytes'], 'Skipped processing lost historical backup/savings evidence.' );
		$assert( $source_hash === hash_file( 'sha256', $backup ), 'Skipped processing modified the existing backup.' );
	} finally {
		putenv( false === $original_path ? 'PATH' : 'PATH=' . $original_path );
		putenv( false === $original_real_tool ? 'YOOHW_MO_TEST_REAL_JPEGTRAN' : 'YOOHW_MO_TEST_REAL_JPEGTRAN=' . $original_real_tool );
		remove_filter( 'wp_image_editors', $gd_editors, 20 );
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		foreach ( array_merge( array( $path, $backup, $path . '.webp', $path . '.avif' ), glob( $test_dir . '/.lossless.jpg.yoohw-mo-*' ) ) as $created ) {
			if ( $created && file_exists( $created ) ) {
				wp_delete_file( $created );
			}
		}
		foreach ( array( 'jpegoptim', 'jpegtran', 'mode', 'calls', 'smaller.jpg' ) as $name ) {
			if ( file_exists( $binary_dir . '/' . $name ) ) {
				wp_delete_file( $binary_dir . '/' . $name );
			}
		}
		if ( is_dir( $binary_dir ) ) {
			@rmdir( $binary_dir );
		}
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $original_options );
	}
};
