<?php
/**
 * Image quality regressions through the public attachment lifecycle.
 *
 * @package YoOhw_Media_Optimizer
 */

return static function( $optimizer, $invoke, $assert, $test_dir ) {
	$original_options = get_option( YoOhw_Media_Optimizer::OPTION_KEY );
	$path = $test_dir . '/quality-orientation.jpg';
	$attachment = 0;
	$gd = static function() { return array( 'WP_Image_Editor_GD' ); };
	try {
		$image = imagecreatetruecolor( 96, 64 );
		foreach ( array( array( 0, 0, 47, 31, 240, 20, 20 ), array( 48, 0, 95, 31, 20, 240, 20 ), array( 0, 32, 47, 63, 20, 20, 240 ), array( 48, 32, 95, 63, 240, 240, 20 ) ) as $region ) {
			imagefilledrectangle( $image, $region[0], $region[1], $region[2], $region[3], imagecolorallocate( $image, $region[4], $region[5], $region[6] ) );
		}
		ob_start(); imagejpeg( $image, null, 95 ); $jpeg = ob_get_clean(); imagedestroy( $image );
		$exif = "Exif\0\0II\x2a\0" . pack( 'Vv', 8, 1 ) . pack( 'vvVv', 0x0112, 3, 1, 6 ) . "\0\0" . pack( 'V', 0 );
		file_put_contents( $path, substr( $jpeg, 0, 2 ) . "\xff\xe1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $jpeg, 2 ) );
		$hash = hash_file( 'sha256', $path );
		$options = array_merge( $original_options, array( 'auto_optimize_uploads' => 0, 'optimize_originals' => 0, 'generate_webp_sidecars' => 1, 'generate_avif_sidecars' => 0, 'use_external_binaries' => 0, 'skip_larger_files' => 0 ) );
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options );
		add_filter( 'wp_image_editors', $gd, 100 );
		$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Image orientation quality fixture' ), $path );
		$result = $optimizer->optimize_attachment( $attachment );
		$size = getimagesize( $path . '.webp' );
		$assert( 64 === $size[0] && 96 === $size[1], 'EXIF Orientation=6 WebP does not preserve displayed dimensions (expected 64x96).' );
		$assert( $hash === hash_file( 'sha256', $path ), 'Sidecar quality check changed its original source.' );
	} finally {
		remove_filter( 'wp_image_editors', $gd, 100 );
		if ( $attachment ) { wp_delete_attachment( $attachment, true ); }
		foreach ( array( $path . '.webp', $path ) as $file ) { if ( file_exists( $file ) ) { wp_delete_file( $file ); } }
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $original_options );
	}
};
