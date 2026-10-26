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
	} finally {
		remove_filter( 'wp_image_editors', $gd_editors, 20 );
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		foreach ( array( $path, $backup, $path . '.webp', $path . '.avif' ) as $created ) {
			if ( $created && file_exists( $created ) ) {
				wp_delete_file( $created );
			}
		}
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $original_options );
	}
};
