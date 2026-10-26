<?php
/**
 * Real WordPress image-quality probe; only generated test media is mutated.
 *
 * @package YoOhw_Media_Optimizer
 */

if ( 'cli' !== PHP_SAPI || ! isset( $argv[2] ) ) {
	exit( "Run through regression-tests.php.\n" );
}
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
$mode = $argv[2];
$required = '1' === getenv( 'YOOHW_MO_REQUIRE_NATIVE_QUALITY' );
$optimizer = YoOhw_Media_Optimizer::instance();
$reflection = new ReflectionClass( $optimizer );
$invoke = static function( $name, $arguments = array() ) use ( $optimizer, $reflection ) {
	$method = $reflection->getMethod( $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	return $method->invokeArgs( $optimizer, $arguments );
};
$count = 0;
$failure = false;
$assert = static function( $condition, $message ) use ( &$count ) {
	++$count;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$original_options = get_option( YoOhw_Media_Optimizer::OPTION_KEY, array() );
$original_path = getenv( 'PATH' );
$dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'yoohw-mo-quality-' . wp_generate_password( 8, false, false );
$ids = array();
$backups = array();
$filters = array();
$remove = static function( $path ) use ( &$remove ) {
	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );
	} elseif ( is_dir( $path ) ) {
		foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $child ) {
			$remove( $child->getPathname() );
		}
		rmdir( $path );
	}
};
$filter = static function( $hook, $callback ) use ( &$filters ) {
	add_filter( $hook, $callback, 999, 3 );
	$filters[] = array( $hook, $callback );
};
$options = array_merge( $original_options, array( 'optimize_originals' => 0, 'backup_originals' => 1, 'compression_mode' => 'balanced', 'metadata_policy' => 'remove', 'max_width' => 0, 'max_height' => 0, 'generate_webp_sidecars' => 1, 'generate_avif_sidecars' => 0, 'use_external_binaries' => 0, 'quality' => 82 ) );
$run = static function( $id, $changes = array(), $force = true ) use ( $optimizer, &$options ) {
	$current = array_merge( $options, $changes );
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $current );
	return $optimizer->optimize_attachment( $id, $force );
};
$attach = static function( $name, $bytes, $mime ) use ( $dir, &$ids, &$backups, $invoke, $assert ) {
	$path = $dir . '/' . $name;
	file_put_contents( $path, $bytes );
	$id = wp_insert_attachment( array( 'post_title' => 'Image quality fixture', 'post_mime_type' => $mime ), $path );
	$assert( $id > 0, 'Could not create quality attachment.' );
	$ids[] = $id;
	$backups[] = $invoke( 'backup_path_for', array( $path ) );
	return array( $id, $path );
};
$decode = static function( $path ) use ( $assert ) {
	$image = @imagecreatefromstring( file_get_contents( $path ) );
	if ( false === $image && class_exists( 'Imagick' ) && '.avif' === substr( $path, -5 ) ) {
		$native = new Imagick( $path );
		$native->setImageFormat( 'png' );
		$image = imagecreatefromstring( $native->getImageBlob() );
		$native->clear();
	}
	$assert( false !== $image, 'Native decoder rejected output: ' . basename( $path ) . ' bytes=' . filesize( $path ) );
	return $image;
};
$rgb = static function( $image, $x, $y ) {
	return imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );
};
$colors = array( array( 230, 25, 35 ), array( 20, 205, 50 ), array( 25, 45, 225 ), array( 230, 210, 20 ) );
$quadrants = array( 1 => array( 0, 1, 2, 3 ), 2 => array( 1, 0, 3, 2 ), 3 => array( 3, 2, 1, 0 ), 4 => array( 2, 3, 0, 1 ), 5 => array( 0, 2, 1, 3 ), 6 => array( 2, 0, 3, 1 ), 7 => array( 3, 1, 2, 0 ), 8 => array( 1, 3, 0, 2 ) );
$check_display = static function( $path, $orientation, $width, $height ) use ( $assert, $decode, $rgb, $colors, $quadrants ) {
	$image = $decode( $path );
	$assert( $width === imagesx( $image ) && $height === imagesy( $image ), 'Wrong displayed dimensions: ' . basename( $path ) . ' expected ' . $width . 'x' . $height . ', got ' . imagesx( $image ) . 'x' . imagesy( $image ) );
	foreach ( array( array( 0.25, 0.25 ), array( 0.75, 0.25 ), array( 0.25, 0.75 ), array( 0.75, 0.75 ) ) as $index => $point ) {
		$actual = $rgb( $image, (int) ( $width * $point[0] ), (int) ( $height * $point[1] ) );
		foreach ( array( 'red', 'green', 'blue' ) as $channel => $key ) {
			$assert( abs( $actual[ $key ] - $colors[ $quadrants[ $orientation ][ $index ] ][ $channel ] ) <= 25, 'Wrong displayed quadrant/color: ' . basename( $path ) . ' orientation ' . $orientation );
		}
	}
	imagedestroy( $image );
	if ( class_exists( 'Imagick' ) ) {
		$metadata = new Imagick( $path );
		$assert( $metadata->getImageOrientation() <= 1, 'Normalized output metadata would rotate the raster again.' );
		$metadata->clear();
	}
	if ( function_exists( 'exif_read_data' ) && preg_match( '/\.jpe?g$/i', $path ) ) {
		$exif = @exif_read_data( $path );
		$assert( empty( $exif['Orientation'] ) || 1 === (int) $exif['Orientation'], 'Normalized raster retained a non-neutral orientation (double rotation).' );
	}
};
$icc = file_get_contents( __DIR__ . '/fixtures/srgb.icc' );
$jpeg = static function( $orientation ) use ( $colors, $icc ) {
	$image = imagecreatetruecolor( 96, 64 );
	foreach ( $colors as $index => $color ) {
		$x = ( $index % 2 ) * 48;
		$y = (int) ( $index / 2 ) * 32;
		imagefilledrectangle( $image, $x, $y, $x + 47, $y + 31, imagecolorallocate( $image, $color[0], $color[1], $color[2] ) );
	}
	ob_start();
	imagejpeg( $image, null, 98 );
	$body = ob_get_clean();
	imagedestroy( $image );
	$exif = "Exif\0\0II\x2a\0" . pack( 'Vv', 8, 1 ) . pack( 'vvVv', 0x0112, 3, 1, $orientation ) . "\0\0" . pack( 'V', 0 );
	$profile = "ICC_PROFILE\0\x01\x01" . $icc;
	$padding = str_repeat( 'Generated image quality metadata. ', 1000 );
	return substr( $body, 0, 2 ) . "\xff\xe1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . "\xff\xe2" . pack( 'n', strlen( $profile ) + 2 ) . $profile . "\xff\xef" . pack( 'n', strlen( $padding ) + 2 ) . $padding . substr( $body, 2 );
};
$chunk = static function( $name, $bytes ) {
	return pack( 'N', strlen( $bytes ) ) . $name . $bytes . pack( 'N', crc32( $name . $bytes ) );
};
$png = static function( $palette ) use ( $chunk ) {
	$image = $palette ? imagecreate( 96, 64 ) : imagecreatetruecolor( 96, 64 );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	foreach ( array( array( 230, 25, 35, 0 ), array( 20, 205, 50, 0 ), array( 25, 45, 225, 127 ), array( 230, 210, 20, $palette ? 0 : 64 ) ) as $index => $color ) {
		$c = imagecolorallocatealpha( $image, $color[0], $color[1], $color[2], $color[3] );
		imagefilledrectangle( $image, ( $index % 2 ) * 48, (int) ( $index / 2 ) * 32, ( $index % 2 ) * 48 + 47, (int) ( $index / 2 ) * 32 + 31, $c );
	}
	ob_start();
	imagepng( $image, null, 0 );
	$body = ob_get_clean();
	imagedestroy( $image );
	return substr( $body, 0, 33 ) . $chunk( 'tEXt', 'Fixture' . "\0" . str_repeat( 'Generated metadata. ', 1000 ) ) . substr( $body, 33 );
};
$check_alpha = static function( $source, $output, $exact ) use ( $assert, $decode, $rgb ) {
	$a = $decode( $source );
	$b = $decode( $output );
	$assert( imagesx( $a ) === imagesx( $b ) && imagesy( $a ) === imagesy( $b ), 'PNG/sidecar dimensions changed.' );
	// Lossless originals compare every meaningful pixel; lossy sidecars sample flat regions.
	$step = $exact ? 1 : 16;
	for ( $y = $exact ? 0 : 8; $y < imagesy( $a ); $y += $step ) {
		for ( $x = $exact ? 0 : 8; $x < imagesx( $a ); $x += $step ) {
			$left = $rgb( $a, $x, $y );
			$right = $rgb( $b, $x, $y );
			$assert( abs( $left['alpha'] - $right['alpha'] ) <= ( $exact ? 0 : 2 ), 'Alpha was lost or changed.' );
			foreach ( array( 0, 255 ) as $background ) {
				foreach ( array( 'red', 'green', 'blue' ) as $channel ) {
					$av = $left[ $channel ] * ( 1 - $left['alpha'] / 127 ) + $background * $left['alpha'] / 127;
					$bv = $right[ $channel ] * ( 1 - $right['alpha'] / 127 ) + $background * $right['alpha'] / 127;
					$assert( abs( $av - $bv ) <= ( $exact ? 0 : 25 ), 'Meaningful RGBA/composite changed.' );
				}
			}
		}
	}
	imagedestroy( $a );
	imagedestroy( $b );
};

try {
	wp_mkdir_p( $dir );
	$assert( 588 === strlen( $icc ) && 'acsp' === substr( $icc, 36, 4 ) && unpack( 'N', substr( $icc, 0, 4 ) )[1] === strlen( $icc ), 'Invalid generated ICC fixture.' );
	if ( 'imagick' === $mode && ! class_exists( 'Imagick' ) ) {
		$assert( ! $required, 'Required Imagick extension unavailable.' );
		echo "Image quality imagick: NOT RUN — Imagick unavailable.\n";
		return;
	}
	$editor_name = 'imagick' === $mode ? 'WP_Image_Editor_Imagick' : 'WP_Image_Editor_GD';
	$filter( 'wp_image_editors', static function() use ( $editor_name ) { return array( $editor_name ); } );
	if ( 'native' !== $mode && 'fault' !== $mode ) {
		// No optional tools may accidentally satisfy the explicit editor evidence.
		putenv( 'PATH=' . $dir );
	}
	if ( 'gd' === $mode || 'imagick' === $mode ) {
		$assert( function_exists( 'exif_read_data' ), 'Orientation checks require EXIF reader.' );
		foreach ( 'gd' === $mode ? range( 1, 8 ) : array( 6, 2 ) as $orientation ) {
			list( $id, $path ) = $attach( 'orientation-' . $orientation . '.jpg', $jpeg( $orientation ), 'image/jpeg' );
			$exif = exif_read_data( $path );
			$assert( $orientation === (int) $exif['Orientation'], 'Fixture EXIF orientation invalid.' );
			$hash = hash_file( 'sha256', $path );
			$result = $run( $id );
			$expected = 1 === $orientation ? 'gd' : $editor_name;
			$assert( 'created' === $result['files'][0]['status'] && $expected === $result['files'][0]['webp_engine'], 'Wrong WebP engine/status for orientation ' . $orientation . ': ' . wp_json_encode( $result ) );
			$check_display( $path . '.webp', $orientation, $orientation >= 5 ? 64 : 96, $orientation >= 5 ? 96 : 64 );
			$assert( $hash === hash_file( 'sha256', $path ), 'Sidecar generation mutated original JPEG/ICC.' );
			if ( 6 === $orientation ) {
				foreach ( array( false, true ) as $force ) {
					delete_post_meta( $id, YoOhw_Media_Optimizer::META_KEY );
					$skip = $run( $id, array( 'optimize_originals' => 1, 'compression_mode' => 'lossless', 'use_external_binaries' => 1, 'generate_webp_sidecars' => 0 ), $force );
					$assert( 'no_change' === $skip['files'][0]['original_status'] && '' !== $skip['files'][0]['original_message'], 'Oriented lossless metadata removal was not explained/skipped.' );
					$assert( $hash === hash_file( 'sha256', $path ) && ! file_exists( $invoke( 'backup_path_for', array( $path ) ) ), 'Oriented lossless force stripped EXIF/ICC or created a backup.' );
				}
			}
			if ( 'imagick' === $mode ) {
				$profile_image = new Imagick( $path );
				$assert( $icc === $profile_image->getImageProfile( 'icc' ), 'Decoder did not recognize embedded valid ICC bytes.' );
				$profile_image->clear();
				if ( Imagick::queryFormats( 'AVIF' ) ) {
					$avif = $run( $id, array( 'generate_avif_sidecars' => 1 ) );
					$assert( $editor_name === $avif['files'][0]['avif_engine'], 'Imagick oriented AVIF fell back to another engine.' );
					$check_display( $path . '.avif', $orientation, $orientation >= 5 ? 64 : 96, $orientation >= 5 ? 96 : 64 );
				} else {
					$assert( ! $required, 'Required Imagick AVIF support unavailable.' );
					echo "Imagick AVIF: NOT RUN — format unavailable.\n";
				}
			}
		}
		foreach ( array( array( 48, 0, 48, 72 ), array( 0, 48, 32, 48 ), array( 40, 40, 27, 40 ), array( 200, 200, 64, 96 ) ) as $case ) {
			list( $id, $path ) = $attach( 'resize-' . $case[0] . '-' . $case[1] . '.jpg', $jpeg( 6 ), 'image/jpeg' );
			$hash = hash_file( 'sha256', $path );
			$result = $run( $id, array( 'optimize_originals' => 1, 'max_width' => $case[0], 'max_height' => $case[1], 'generate_webp_sidecars' => 0 ) );
			$assert( 'optimized' === $result['files'][0]['original_status'] && $editor_name === $result['files'][0]['engine'], 'Resize did not use selected original editor.' );
			$check_display( $path, 6, $case[2], $case[3] );
			$backup = $invoke( 'backup_path_for', array( $path ) );
			$assert( $hash === hash_file( 'sha256', $backup ), 'Resize backup lost original EXIF/ICC bytes.' );
			$restore = $optimizer->restore_attachment( $id );
			$assert( 1 === $restore['restored'] && 0 === $restore['failed'] && $hash === hash_file( 'sha256', $path ), 'Restore did not recover exact original/profile bytes.' );
		}
		foreach ( array( true, false ) as $palette ) {
			$bytes = $png( $palette );
			list( $id, $path ) = $attach( $palette ? 'palette.png' : 'rgba.png', $bytes, 'image/png' );
			$assert( false !== strpos( $bytes, $palette ? 'tRNS' : 'IHDR' ), 'Palette transparency fixture is invalid.' );
			$copy = $path . '.oracle';
			file_put_contents( $copy, $bytes );
			$result = $run( $id, array( 'optimize_originals' => 1, 'compression_mode' => 'lossless' ) );
			$assert( 'optimized' === $result['files'][0]['original_status'] && $editor_name === $result['files'][0]['engine'], 'PNG original did not use selected lossless editor.' );
			$check_alpha( $copy, $path, true );

			$restore = $optimizer->restore_attachment( $id );
			$assert( 1 === $restore['restored'] && hash( 'sha256', $bytes ) === hash_file( 'sha256', $path ), 'PNG restore changed source bytes.' );
			$sidecar = $run( $id );
			$assert( 'created' === $sidecar['files'][0]['status'] && 'gd' === $sidecar['files'][0]['webp_engine'], 'Neutral PNG WebP engine was not direct GD.' );
			$check_alpha( $copy, $path . '.webp', false );
		}
		// Known non-neutral orientation must fail if WordPress refuses normalization.
		list( $id, $path ) = $attach( 'rotation-refused.jpg', $jpeg( 6 ), 'image/jpeg' );
		file_put_contents( $path . '.webp', 'old sidecar' );
		$filter( 'wp_image_maybe_exif_rotate', static function() { return false; } );
		$result = $run( $id );
		$assert( 'failed' === $result['files'][0]['status'] && 'old sidecar' === file_get_contents( $path . '.webp' ), 'Refused normalization published incorrectly oriented output.' );
	} elseif ( 'no-exif' === $mode ) {
		$assert( ! function_exists( 'exif_read_data' ), 'Disabled-EXIF child still has EXIF reader.' );
		list( $id, $path ) = $attach( 'unknown-orientation.jpg', $jpeg( 6 ), 'image/jpeg' );
		$hash = hash_file( 'sha256', $path );
		file_put_contents( $path . '.webp', 'old sidecar' );
		$result = $run( $id, array( 'optimize_originals' => 1 ) );
		$assert( 'failed' === $result['files'][0]['original_status'] && 'failed' === $result['files'][0]['status'], 'Unknown JPEG orientation did not fail safely.' );
		$assert( $hash === hash_file( 'sha256', $path ) && 'old sidecar' === file_get_contents( $path . '.webp' ), 'Unknown orientation changed source/old sidecar.' );
		$assert( ! file_exists( $invoke( 'backup_path_for', array( $path ) ) ), 'Unknown orientation created a backup before safe decoding.' );
		list( $id, $path ) = $attach( 'exif-independent.png', $png( false ), 'image/png' );
		$result = $run( $id );
		$assert( 'created' === $result['files'][0]['status'], 'Disabled EXIF unnecessarily disabled PNG.' );
	} elseif ( 'native' === $mode ) {
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, array_merge( $options, array( 'use_external_binaries' => 1 ) ) );
		$tools = array();
		foreach ( array( 'cwebp', 'avifenc', 'jpegtran' ) as $tool ) {
			$tools[ $tool ] = $invoke( 'external_binary_path', array( $tool ) );
			$assert( ! $required || $tools[ $tool ], 'Required real encoder unavailable: ' . $tool );
		}
		foreach ( array( 'cwebp' => 'webp', 'avifenc' => 'avif' ) as $engine => $format ) {
			if ( ! $tools[ $engine ] ) {
				echo $engine . ": NOT RUN — binary unavailable.\n";
				continue;
			}
			foreach ( array( 'jpeg', 'palette', 'rgba' ) as $kind ) {
				list( $id, $path ) = $attach( 'native-' . $engine . '-' . $kind . ( 'jpeg' === $kind ? '.jpg' : '.png' ), 'jpeg' === $kind ? $jpeg( 1 ) : $png( 'palette' === $kind ), 'jpeg' === $kind ? 'image/jpeg' : 'image/png' );
				$hash = hash_file( 'sha256', $path );
				$result = $run( $id, array( 'use_external_binaries' => 1, 'generate_webp_sidecars' => 'webp' === $format ? 1 : 0, 'generate_avif_sidecars' => 'avif' === $format ? 1 : 0 ) );
				$assert( $engine === $result['files'][0][ $format . '_engine' ], 'Real encoder was masked by fallback: ' . $engine . wp_json_encode( $result ) );
				$assert( 'image/' . $format === getimagesize( $path . '.' . $format )['mime'], 'Wrong native output MIME.' );
				if ( 'jpeg' === $kind ) {
					$check_display( $path . '.' . $format, 1, 96, 64 );
				} else {
					$check_alpha( $path, $path . '.' . $format, false );
				}
				$assert( $hash === hash_file( 'sha256', $path ), 'Real sidecar encoder mutated source.' );
			}
			echo $engine . ": PASS — selected real encoder, decode/MIME/color/alpha/source identity.\n";
		}
		// Optional encoders enabled must still defer non-neutral EXIF to normalization.
		$native_editor = class_exists( 'Imagick' ) ? 'WP_Image_Editor_Imagick' : 'WP_Image_Editor_GD';
		$filter( 'wp_image_editors', static function() use ( $native_editor ) { return array( $native_editor ); } );
		foreach ( array( 6, 2 ) as $orientation ) {
			list( $id, $path ) = $attach( 'native-oriented-' . $orientation . '.jpg', $jpeg( $orientation ), 'image/jpeg' );
			$hash = hash_file( 'sha256', $path );
			$avif_capable = class_exists( 'Imagick' ) && Imagick::queryFormats( 'AVIF' );
			$result = $run( $id, array( 'use_external_binaries' => 1, 'generate_avif_sidecars' => $avif_capable ? 1 : 0 ) );
			$assert( $native_editor === $result['files'][0]['webp_engine'], 'Oriented JPEG used an external encoder without normalization.' );
			$check_display( $path . '.webp', $orientation, $orientation >= 5 ? 64 : 96, $orientation >= 5 ? 96 : 64 );
			if ( $avif_capable ) {
				$assert( $native_editor === $result['files'][0]['avif_engine'], 'Oriented AVIF did not use capable normalizing editor.' );
				$check_display( $path . '.avif', $orientation, $orientation >= 5 ? 64 : 96, $orientation >= 5 ? 96 : 64 );
			} else {
				$assert( ! $required, 'Required oriented native AVIF editor unavailable.' );
				echo "Oriented AVIF: NOT RUN — capable editor unavailable.\n";
			}
			$assert( $hash === hash_file( 'sha256', $path ), 'Native oriented sidecars changed source.' );
		}
		if ( $tools['jpegtran'] ) {
			// Isolate jpegtran from other optional original encoders, preserving its real binary.
			foreach ( array( 'jpegoptim', 'cjpeg', 'djpeg' ) as $blocked ) {
				file_put_contents( $dir . '/' . $blocked, "#!/bin/sh\nexit 1\n" );
				chmod( $dir . '/' . $blocked, 0700 );
			}
			symlink( $tools['jpegtran'], $dir . '/jpegtran' );
			putenv( 'PATH=' . $dir );
			list( $id, $path ) = $attach( 'native-lossless.jpg', $jpeg( 1 ), 'image/jpeg' );
			$before = $decode( $path );
			$hash = hash_file( 'sha256', $path );
			$result = $run( $id, array( 'use_external_binaries' => 1, 'optimize_originals' => 1, 'compression_mode' => 'lossless', 'generate_webp_sidecars' => 0 ) );
			$assert( 'jpegtran' === $result['files'][0]['engine'] && 'optimized' === $result['files'][0]['original_status'], 'Real jpegtran success was not selected.' );
			$after = $decode( $path );
			for ( $y = 0; $y < 64; ++$y ) {
				for ( $x = 0; $x < 96; ++$x ) {
					$assert( imagecolorat( $before, $x, $y ) === imagecolorat( $after, $x, $y ), 'Real jpegtran changed raw RGB.' );
				}
			}
			imagedestroy( $before );
			imagedestroy( $after );
			$check_display( $path, 1, 96, 64 );
			$assert( $hash === hash_file( 'sha256', $invoke( 'backup_path_for', array( $path ) ) ), 'Real jpegtran backup lost source/profile.' );
			echo "jpegtran: PASS — real selected engine, exact RGB and backup.\n";
		} else {
			echo "jpegtran: NOT RUN — binary unavailable.\n";
		}
	} elseif ( 'fault' === $mode ) {
		putenv( 'PATH=' . $dir );
		$valid = $png( false );
		$bad = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', 96, 64, 8, 6, 0, 0, 0 ) ) . $chunk( 'IDAT', 'not a zlib stream' ) . $chunk( 'IEND', '' );
		foreach ( array( $bad, 'not an image' ) as $index => $bytes ) {
			list( $id, $path ) = $attach( 'corrupt-' . $index . '.png', $valid, 'image/png' );
			$backup = $invoke( 'backup_file', array( $path ) );
			$assert( ! is_wp_error( $backup ), 'Could not establish recovery backup.' );
			$backup_hash = hash_file( 'sha256', $backup['path'] );
			file_put_contents( $path, $bytes );
			file_put_contents( $path . '.webp', 'old webp' );
			file_put_contents( $path . '.avif', 'old avif' );
			$assert( false === @imagecreatefrompng( $path ), 'Corrupt fixture was accepted by GD decoder.' );
			if ( class_exists( 'Imagick' ) ) {
				$rejected = false;
				try {
					$invalid = new Imagick( $path );
					$invalid->getImageBlob();
					$invalid->clear();
				} catch ( ImagickException $expected ) {
					$rejected = true;
				}
				$assert( $rejected, 'Corrupt fixture was accepted by Imagick decoder.' );
			}
			if ( 0 === $index ) {
				$assert( 96 === getimagesize( $path )[0], 'Corrupt-body fixture lacks readable dimensions.' );
			}
			$result = $run( $id, array( 'optimize_originals' => 1, 'generate_avif_sidecars' => 1 ) );
			$file = $result['files'][0];
			$assert( 'failed' === $file['original_status'] && 'failed' === $file['status'] && in_array( $file['avif_status'], array( 'failed', 'unsupported' ), true ), 'Decoder failure reported successful output.' );
			$assert( 1 === $result['summary']['original_failed'] && 1 === $result['summary']['failed'] && 0 === $result['summary']['original_optimized'] && 0 === $result['summary']['created'] && 0 === $result['summary']['avif_created'], 'Decoder failure counters are untruthful.' );
			$assert( $bytes === file_get_contents( $path ) && $backup_hash === hash_file( 'sha256', $backup['path'] ) && 'old webp' === file_get_contents( $path . '.webp' ) && 'old avif' === file_get_contents( $path . '.avif' ), 'Decoder failure changed source/recovery/old sidecars.' );
		}
		// A real process writes partial output and fails; fallback must clean only its stage.
		file_put_contents( $dir . '/cwebp', "#!/bin/sh\nfor argument in \"\$@\"; do destination=\$argument; done\nprintf partial > \"\$destination\"\nprintf called > \"\${0%/*}/called\"\nexit 1\n" );
		chmod( $dir . '/cwebp', 0700 );
		list( $id, $path ) = $attach( 'partial.png', $valid, 'image/png' );
		$result = $run( $id, array( 'use_external_binaries' => 1 ) );
		$assert( is_file( $dir . '/called' ) && 'gd' === $result['files'][0]['webp_engine'], 'Partial encoder probe did not run and fall back to GD.' );
		$assert( array() === glob( $dir . '/.*.yoohw-mo-*' ), 'Encoder error left unpublished partial output.' );
		$check_alpha( $path, $path . '.webp', false );
		$stage = $dir . '/owned-partial.webp';
		$failed = $invoke( 'save_webp_file_with_cwebp', array( $path, $stage, 90 ) );
		$assert( is_wp_error( $failed ) && ! file_exists( $stage ), 'Failed cwebp retained its caller-owned partial stage.' );
		// Editor save errors may name arbitrary data: delete only our supplied stage.
		if ( ! class_exists( 'WP_Image_Editor_GD' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
		}
		class YoOhw_MO_Quality_Failing_Editor extends WP_Image_Editor_GD {
			public static $sentinel;
			public function save( $destfilename = null, $mime_type = null ) {
				file_put_contents( $destfilename, 'partial editor output' );
				return new WP_Error( 'quality_partial_save', 'Expected editor partial save failure.', array( 'path' => self::$sentinel ) );
			}
		}
		$sentinel = $dir . '/unowned-sentinel';
		file_put_contents( $sentinel, 'retain me' );
		YoOhw_MO_Quality_Failing_Editor::$sentinel = $sentinel;
		$filter( 'wp_image_editors', static function() { return array( 'YoOhw_MO_Quality_Failing_Editor' ); } );
		list( $id, $path ) = $attach( 'editor-partial.jpg', $jpeg( 6 ), 'image/jpeg' );
		$hash = hash_file( 'sha256', $path );
		file_put_contents( $path . '.webp', 'old webp' );
		$result = $run( $id, array( 'optimize_originals' => 1 ) );
		$assert( 'failed' === $result['files'][0]['original_status'] && 'failed' === $result['files'][0]['status'], 'Editor partial save did not report failures.' );
		$assert( $hash === hash_file( 'sha256', $path ) && 'old webp' === file_get_contents( $path . '.webp' ) && 'retain me' === file_get_contents( $sentinel ), 'Editor error changed source/old sidecar/unowned error path.' );
		$assert( array() === glob( $dir . '/.*.yoohw-mo-*' ), 'Editor save error retained caller-owned partial stage.' );
	}
	echo 'Image quality ' . $mode . ': PASS — ' . $count . " assertions.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Image quality ' . $mode . ': FAIL — ' . $error->getMessage() . "\n" );
	$failure = true;
} finally {
	foreach ( $filters as $registered ) {
		remove_filter( $registered[0], $registered[1], 999 );
	}
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $original_options );
	putenv( false === $original_path ? 'PATH' : 'PATH=' . $original_path );
	foreach ( $ids as $id ) {
		wp_delete_attachment( $id, true );
	}
	foreach ( $backups as $backup ) {
		if ( $backup && is_file( $backup ) ) {
			unlink( $backup );
		}
	}
	$remove( $dir );
}

if ( $failure ) {
	exit( 1 );
}
