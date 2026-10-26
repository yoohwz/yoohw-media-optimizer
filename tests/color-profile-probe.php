<?php
/**
 * ICC preservation checks in a fresh process; only generated media is mutated.
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
	if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
	return $method->invokeArgs( $optimizer, $arguments );
};
$count = 0;
$failed = false;
$assert = static function( $condition, $message ) use ( &$count ) {
	++$count;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$original_options = get_option( YoOhw_Media_Optimizer::OPTION_KEY, array() );
$original_path = getenv( 'PATH' );
$dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'yoohw-mo-color-' . wp_generate_password( 8, false, false );
$ids = $backups = $filters = array();
$filter = static function( $hook, $callback ) use ( &$filters ) {
	add_filter( $hook, $callback, 999, 3 );
	$filters[] = array( $hook, $callback );
};
$remove = static function( $path ) use ( &$remove ) {
	if ( is_file( $path ) || is_link( $path ) ) { unlink( $path ); }
	elseif ( is_dir( $path ) ) {
		foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $child ) { $remove( $child->getPathname() ); }
		rmdir( $path );
	}
};
$options = array_merge( $original_options, array( 'auto_optimize_uploads' => 0, 'optimize_originals' => 0, 'backup_originals' => 1, 'compression_mode' => 'balanced', 'metadata_policy' => 'remove', 'max_width' => 0, 'max_height' => 0, 'generate_webp_sidecars' => 1, 'generate_avif_sidecars' => 0, 'use_external_binaries' => 0, 'jpeg_quality' => 82 ) );
$run = static function( $id, $changes = array(), $force = true ) use ( $optimizer, $options ) {
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, array_merge( $options, $changes ) );
	return $optimizer->optimize_attachment( $id, $force );
};
$attach = static function( $name, $bytes ) use ( $dir, &$ids, &$backups, $invoke, $assert ) {
	$path = $dir . '/' . $name;
	file_put_contents( $path, $bytes );
	$id = wp_insert_attachment( array( 'post_title' => 'ICC fixture', 'post_mime_type' => '.png' === substr( $name, -4 ) ? 'image/png' : 'image/jpeg' ), $path );
	$assert( $id > 0, 'Could not create ICC attachment.' );
	$ids[] = $id;
	$backups[] = $invoke( 'backup_path_for', array( $path ) );
	return array( $id, $path );
};
$profiles = array();
foreach ( array( 'srgb', 'display_p3', 'adobe_rgb_1998' ) as $name ) { $profiles[ $name ] = file_get_contents( __DIR__ . '/fixtures/' . $name . '.icc' ); }
$colors = array( array( 230, 25, 35 ), array( 20, 205, 50 ), array( 25, 45, 225 ), array( 230, 210, 20 ) );
// Independent LittleCMS 2.19/Pillow oracle, relative colorimetric, committed profiles.
$managed = array( 'srgb' => $colors, 'display_p3' => array( array( 251, 0, 7 ), array( 0, 209, 0 ), array( 18, 46, 234 ), array( 234, 209, 0 ) ), 'adobe_rgb_1998' => array( array( 255, 18, 30 ), array( 0, 206, 8 ), array( 0, 41, 230 ), array( 238, 211, 0 ) ) );
$segment = static function( $marker, $body ) { return "\xff" . chr( $marker ) . pack( 'n', strlen( $body ) + 2 ) . $body; };
$chunk = static function( $name, $body ) { return pack( 'N', strlen( $body ) ) . $name . $body . pack( 'N', crc32( $name . $body ) ); };
$fixture = static function( $kind, $profile, $orientation = 1 ) use ( $colors, $segment, $chunk ) {
	$palette = 'palette' === $kind;
	$image = $palette ? imagecreate( 96, 64 ) : imagecreatetruecolor( 96, 64 );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	foreach ( $colors as $index => $color ) {
		$alpha = 'jpeg' === $kind ? 0 : ( 2 === $index ? 127 : ( 3 === $index && ! $palette ? 64 : 0 ) );
		$c = imagecolorallocatealpha( $image, $color[0], $color[1], $color[2], $alpha );
		imagefilledrectangle( $image, ( $index % 2 ) * 48, (int) ( $index / 2 ) * 32, ( $index % 2 ) * 48 + 47, (int) ( $index / 2 ) * 32 + 31, $c );
	}
	ob_start();
	if ( 'jpeg' === $kind ) { imagejpeg( $image, null, 100 ); } else { imagepng( $image, null, 0 ); }
	$body = ob_get_clean();
	imagedestroy( $image );
	if ( 'jpeg' === $kind ) {
		$exif = "Exif\0\0II\x2a\0" . pack( 'Vv', 8, 1 ) . pack( 'vvVv', 0x0112, 3, 1, $orientation ) . "\0\0" . pack( 'V', 0 );
		$icc = $profile ? $segment( 0xe2, "ICC_PROFILE\0\x01\x01" . $profile ) : '';
		return substr( $body, 0, 2 ) . $segment( 0xe1, $exif ) . $icc . $segment( 0xe1, "http://ns.adobe.com/xap/1.0/\0<private>GPS latitude</private>" ) . $segment( 0xef, str_repeat( 'private fixture metadata ', 900 ) ) . substr( $body, 2 );
	}
	return substr( $body, 0, 33 ) . ( $profile ? $chunk( 'iCCP', "Fixture\0\0" . gzcompress( $profile ) ) : '' ) . $chunk( 'tEXt', "private\0" . str_repeat( 'GPS fixture metadata ', 900 ) ) . substr( $body, 33 );
};
$decode = static function( $path ) use ( $assert ) {
	$image = @imagecreatefromstring( file_get_contents( $path ) );
	if ( ! $image && class_exists( 'Imagick' ) ) {
		$native = new Imagick( $path );
		$native->setImageFormat( 'png' );
		$image = imagecreatefromstring( $native->getImageBlob() );
		$native->clear();
	}
	$assert( false !== $image, 'Cannot decode color output ' . basename( $path ) );
	return $image;
};
$check = static function( $path, $name, $orientation = 1, $width = 96, $height = 64, $alpha = false, $tolerance = 18 ) use ( $assert, $decode, $profiles, $colors, $managed ) {
	$native = new Imagick( $path );
	$assert( $profiles[ $name ] === $native->getImageProfile( 'icc' ), 'Output ICC differs from source: ' . basename( $path ) );
	$assert( $native->getImageOrientation() <= 1, 'Output ICC raster still has non-neutral EXIF.' );
	$image = $decode( $path );
	$assert( $width === imagesx( $image ) && $height === imagesy( $image ), 'Wrong color output dimensions.' );
	$order = 6 === $orientation ? array( 2, 0, 3, 1 ) : ( 2 === $orientation ? array( 1, 0, 3, 2 ) : array( 0, 1, 2, 3 ) );
	$native->setImageRenderingIntent( Imagick::RENDERINGINTENT_RELATIVE );
	$native->profileImage( 'icc', $profiles['srgb'] );
	foreach ( array( array( .25, .25 ), array( .75, .25 ), array( .25, .75 ), array( .75, .75 ) ) as $i => $point ) {
		$x = (int) ( $width * $point[0] ); $y = (int) ( $height * $point[1] ); $index = $order[ $i ];
		$raw = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );
		$expected_alpha = $alpha ? ( 2 === $index ? 127 : ( 3 === $index && 'rgba' === $alpha ? 64 : 0 ) ) : 0;
		$assert( abs( $raw['alpha'] - $expected_alpha ) <= 2, 'ICC output changed alpha.' );
		if ( 127 === $expected_alpha ) { continue; }
		$cms = $native->getImagePixelColor( $x, $y )->getColor();
		foreach ( array( 'red' => 'r', 'green' => 'g', 'blue' => 'b' ) as $channel => $short ) {
			$offset = array_search( $channel, array( 'red', 'green', 'blue' ), true );
			$assert( abs( $raw[ $channel ] - $colors[ $index ][ $offset ] ) <= $tolerance, 'ICC pixels converted/relabelled: ' . basename( $path ) );
			$assert( abs( $cms[ $short ] - $managed[ $name ][ $index ][ $offset ] ) <= $tolerance + 3, 'Color-managed patch differs from independent LittleCMS oracle.' );
		}
	}
	imagedestroy( $image );
	$native->clear();
};
$privacy = static function( $path ) use ( $assert ) {
	$image = new Imagick( $path );
	$names = array_map( 'strtolower', $image->getImageProfiles( '*', false ) );
	$assert( ! array_intersect( array( 'exif', 'xmp', 'iptc' ), $names ), 'Remove policy retained private metadata profiles.' );
	$assert( false === strpos( file_get_contents( $path ), 'GPS fixture metadata' ) && false === strpos( file_get_contents( $path ), 'GPS latitude' ), 'Remove policy retained private metadata payload.' );
	$image->clear();
};
$safe_failure = static function( $name, $bytes ) use ( $assert, $attach, $invoke, $run ) {
	list( $id, $path ) = $attach( $name, $bytes );
	$backup = $invoke( 'backup_file', array( $path ) );
	$assert( ! is_wp_error( $backup ), 'Could not seed recovery data.' );
	$backup_hash = hash_file( 'sha256', $backup['path'] );
	file_put_contents( $path . '.webp', 'old webp' ); file_put_contents( $path . '.avif', 'old avif' );
	$result = $run( $id, array( 'optimize_originals' => 1, 'generate_avif_sidecars' => 1 ) );
	$file = $result['files'][0];
	$assert( 'failed' === $file['original_status'] && 'failed' === $file['status'] && in_array( $file['avif_status'], array( 'failed', 'unsupported' ), true ), 'Unsafe ICC reported success: ' . $name . wp_json_encode( $result ) );
	$assert( 0 === $result['summary']['original_optimized'] && 0 === $result['summary']['created'] && 0 === $result['summary']['avif_created'] && 1 === $result['summary']['original_failed'] && 1 === $result['summary']['failed'], 'ICC failure counters untruthful.' );
	$assert( $bytes === file_get_contents( $path ) && $backup_hash === hash_file( 'sha256', $backup['path'] ) && 'old webp' === file_get_contents( $path . '.webp' ) && 'old avif' === file_get_contents( $path . '.avif' ), 'ICC rejection changed source/backup/old sidecars.' );
};

try {
	wp_mkdir_p( $dir );
	$editor = 'gd' === $mode || 'tool-flags' === $mode ? 'WP_Image_Editor_GD' : 'WP_Image_Editor_Imagick';
	$filter( 'wp_image_editors', static function() use ( $editor ) { return array( $editor ); } );
	if ( 'native' !== $mode ) { putenv( 'PATH=' . $dir ); }
	if ( in_array( $mode, array( 'imagick', 'native' ), true ) && ! class_exists( 'Imagick' ) ) {
		$assert( ! $required, 'Required ICC Imagick verifier unavailable.' );
		echo 'Color profile ' . $mode . ": NOT RUN — Imagick unavailable.\n";
		return;
	}
	if ( 'gd' === $mode ) {
		foreach ( $profiles as $profile ) {
			foreach ( array( 'jpeg', 'palette', 'rgba' ) as $kind ) { $safe_failure( 'gd-' . $count . ( 'jpeg' === $kind ? '.jpg' : '.png' ), $fixture( $kind, $profile ) ); }
		}
		$legacy_options = array( 'compression_mode' => 'balanced', 'jpeg_quality' => 82, 'metadata_policy' => 'remove', 'max_width' => 0, 'max_height' => 0, 'use_external_binaries' => 0, 'optimizer_version' => YOOHW_MEDIA_OPTIMIZER_VERSION );
		$legacy_fingerprint = hash( 'sha256', wp_json_encode( $legacy_options ) );
		$assert( $legacy_fingerprint === $invoke( 'original_options_fingerprint', array( $options ) ), 'ICC policy changed existing original option fingerprints.' );
		list( $legacy_id, $legacy_path ) = $attach( 'matching-old-original.jpg', $fixture( 'jpeg', $profiles['display_p3'] ) );
		$legacy_hash = hash_file( 'sha256', $legacy_path );
		$legacy = $invoke( 'optimize_original_file', array( $legacy_path, $options, false, array( 'original_status' => 'optimized', 'original_options_fingerprint' => $legacy_fingerprint, 'optimized_hash' => $legacy_hash, 'engine' => 'gd' ) ) );
		$assert( 'existing' === $legacy['status'] && $legacy_hash === hash_file( 'sha256', $legacy_path ), 'Matching old original was reencoded/rejected by ICC migration.' );
		// Fresh existing sidecars are not migrated implicitly by the new ICC contract.
		list( $id, $path ) = $attach( 'fresh-old.jpg', $fixture( 'jpeg', $profiles['display_p3'] ) );
		file_put_contents( $path . '.webp', 'legacy untagged webp' ); touch( $path . '.webp', time() + 5 );
		$result = $run( $id, array(), false );
		$assert( 'existing' === $result['files'][0]['status'] && 'legacy untagged webp' === file_get_contents( $path . '.webp' ), 'Fresh legacy sidecar automatically regenerated.' );
	} elseif ( 'imagick' === $mode ) {
		foreach ( $profiles as $name => $profile ) {
			foreach ( array( 'jpeg', 'palette', 'rgba' ) as $kind ) {
				list( $id, $path ) = $attach( $name . '-' . $kind . ( 'jpeg' === $kind ? '.jpg' : '.png' ), $fixture( $kind, $profile ) );
				$hash = hash_file( 'sha256', $path );
				$result = $run( $id );
				$assert( 'created' === $result['files'][0]['status'] && false !== strpos( $result['files'][0]['webp_engine'], 'Imagick' ), 'Tagged WebP did not use capable Imagick: ' . wp_json_encode( $result ) );
				$check( $path . '.webp', $name, 1, 96, 64, 'jpeg' === $kind ? false : $kind ); $privacy( $path . '.webp' );
				$assert( $hash === hash_file( 'sha256', $path ), 'ICC sidecar mutated source.' );
				$result = $run( $id, array( 'optimize_originals' => 1, 'generate_webp_sidecars' => 0, 'compression_mode' => 'jpeg' === $kind ? 'balanced' : 'lossless' ) );
				$assert( 'optimized' === $result['files'][0]['original_status'], 'Tagged original not optimized: ' . wp_json_encode( $result ) );
				$check( $path, $name, 1, 96, 64, 'jpeg' === $kind ? false : $kind, 'jpeg' === $kind ? 18 : 0 ); $privacy( $path );
				$backup = $invoke( 'backup_path_for', array( $path ) );
				$assert( $hash === hash_file( 'sha256', $backup ), 'ICC backup lost source bytes.' );
				$optimized_hash = hash_file( 'sha256', $path );
				$repeat = $run( $id, array( 'optimize_originals' => 1, 'generate_webp_sidecars' => 0, 'compression_mode' => 'jpeg' === $kind ? 'balanced' : 'lossless' ), false );
				$assert( 'existing' === $repeat['files'][0]['original_status'] && 0 === $repeat['summary']['original_optimized'] && $optimized_hash === hash_file( 'sha256', $path ) && $hash === hash_file( 'sha256', $backup ), 'ICC preservation replayed lossy original or replaced backup.' );
				$restore = $optimizer->restore_attachment( $id );
				$assert( 1 === $restore['restored'] && $hash === hash_file( 'sha256', $path ), 'ICC restore not byte exact.' );
			}
		}
		foreach ( array( 6, 2 ) as $orientation ) {
			list( $id, $path ) = $attach( 'p3-orientation-' . $orientation . '.jpg', $fixture( 'jpeg', $profiles['display_p3'], $orientation ) );
			$hash = hash_file( 'sha256', $path ); $rotated = 6 === $orientation;
			$result = $run( $id ); $assert( 'created' === $result['files'][0]['status'], 'Tagged oriented sidecar failed.' );
			$check( $path . '.webp', 'display_p3', $orientation, $rotated ? 64 : 96, $rotated ? 96 : 64 );
			$result = $run( $id, array( 'optimize_originals' => 1, 'generate_webp_sidecars' => 0, 'max_width' => 48 ) );
			$assert( 'optimized' === $result['files'][0]['original_status'], 'Tagged oriented resize failed.' );
			$check( $path, 'display_p3', $orientation, 48, $rotated ? 72 : 32 ); $privacy( $path );
			$assert( $hash === hash_file( 'sha256', $invoke( 'backup_path_for', array( $path ) ) ), 'Oriented ICC resize backup changed.' );
			$restore = $optimizer->restore_attachment( $id ); $assert( 1 === $restore['restored'] && $hash === hash_file( 'sha256', $path ), 'Oriented ICC restore changed.' );
		}

		list( $id, $path ) = $attach( 'p3-preserve-all.jpg', $fixture( 'jpeg', $profiles['display_p3'] ) );
		$result = $run( $id, array( 'metadata_policy' => 'preserve_all', 'optimize_originals' => 1, 'generate_webp_sidecars' => 0 ) );
		$assert( 'optimized' === $result['files'][0]['original_status'], 'Preserve-all ICC original failed.' );
		$check( $path, 'display_p3' );
		if ( Imagick::queryFormats( 'AVIF' ) && wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			list( $id, $path ) = $attach( 'p3-editor-avif.jpg', $fixture( 'jpeg', $profiles['display_p3'], 6 ) );
			$result = $run( $id, array( 'generate_webp_sidecars' => 0, 'generate_avif_sidecars' => 1 ) );
			$assert( 'created' === $result['files'][0]['avif_status'] && false !== strpos( $result['files'][0]['avif_engine'], 'Imagick' ), 'Tagged AVIF editor failed or fell back: ' . wp_json_encode( $result ) );
			$check( $path . '.avif', 'display_p3', 6, 64, 96 ); $privacy( $path . '.avif' );
		} else {
			$assert( ! $required, 'Required tagged AVIF WordPress editor capability unavailable.' );
			echo "Tagged editor AVIF: NOT RUN — WordPress/Imagick AVIF capability unavailable.\n";
		}
	} elseif ( 'native' === $mode ) {
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, array_merge( $options, array( 'use_external_binaries' => 1 ) ) );
		foreach ( array( 'cwebp' => 'webp', 'avifenc' => 'avif', 'jpegtran' => 'jpg' ) as $engine => $format ) {
			$binary = $invoke( 'external_binary_path', array( $engine ) );
			$assert( ! $required || $binary, 'Required ICC native engine unavailable: ' . $engine );
			if ( ! $binary ) { echo $engine . " ICC: NOT RUN — binary unavailable.\n"; continue; }
			foreach ( $profiles as $name => $profile ) {
				foreach ( 'jpegtran' === $engine ? array( 'jpeg' ) : array( 'jpeg', 'palette', 'rgba' ) as $kind ) {
					list( $id, $path ) = $attach( $engine . '-' . $name . '-' . $kind . ( 'jpeg' === $kind ? '.jpg' : '.png' ), $fixture( $kind, $profile ) );
					$hash = hash_file( 'sha256', $path );
					$output = $path . '.native.' . $format;
					$method = 'jpegtran' === $engine ? 'optimize_jpeg_with_jpegtran' : ( 'cwebp' === $engine ? 'save_webp_file_with_cwebp' : 'save_avif_file_with_avifenc' );
					$result = $invoke( $method, array( $path, $output, 'jpegtran' === $engine ? array( 'metadata_policy' => 'remove' ) : 90 ) );
					$assert( ! is_wp_error( $result ) && $engine === $result['engine'], 'ICC real native engine failed/fell back: ' . $engine . ( is_wp_error( $result ) ? $result->get_error_message() : wp_json_encode( $result ) ) );
					$check( $output, $name, 1, 96, 64, 'jpeg' === $kind ? false : $kind ); $privacy( $output );
					$assert( $hash === hash_file( 'sha256', $path ), 'Native ICC mutated source.' );
					if ( 'jpegtran' === $engine ) {
						$a = $decode( $path ); $b = $decode( $output ); $equal = true;
						for ( $y = 0; $y < 64; ++$y ) { for ( $x = 0; $x < 96; ++$x ) { if ( imagecolorat( $a, $x, $y ) !== imagecolorat( $b, $x, $y ) ) { $equal = false; break 2; } } }
						$assert( $equal, 'ICC jpegtran changed raw lossless pixels.' ); imagedestroy( $a ); imagedestroy( $b );
					}
				}
			}
			echo $engine . " ICC: PASS — real engine, exact ICC, raw and independently managed patches/privacy/alpha.\n";
		}
	} elseif ( 'fault' === $mode ) {
		$valid = $fixture( 'jpeg', '' );
		$icc_segment = $segment( 0xe2, "ICC_PROFILE\0\x01\x01" . $profiles['display_p3'] );
		$bad_profiles = array( 'invalid' => 'invalid profile', 'incompatible-cmyk' => substr_replace( $profiles['display_p3'], 'CMYK', 16, 4 ) );
		foreach ( $bad_profiles as $name => $profile ) { $safe_failure( $name . '.jpg', $fixture( 'jpeg', $profile ) ); }
		foreach ( array( 'duplicate' => $icc_segment . $icc_segment, 'missing-part' => $segment( 0xe2, "ICC_PROFILE\0\x01\x02" . $profiles['display_p3'] ), 'bad-sequence' => $segment( 0xe2, "ICC_PROFILE\0\x00\x01" . $profiles['display_p3'] ) ) as $name => $extra ) {
			$safe_failure( $name . '.jpg', substr( $valid, 0, 2 ) . $extra . substr( $valid, 2 ) );
		}
		$png = $fixture( 'rgba', '' );
		foreach ( array( 'corrupt-iccp' => $chunk( 'iCCP', "Fixture\0\0not zlib" ), 'oversized-iccp' => $chunk( 'iCCP', "Fixture\0\0" . gzcompress( str_repeat( 'x', 1048577 ) ) ), 'gamma-no-icc' => $chunk( 'gAMA', pack( 'N', 45455 ) ) ) as $name => $extra ) {
			$safe_failure( $name . '.png', substr( $png, 0, 33 ) . $extra . substr( $png, 33 ) );
		}
		if ( class_exists( 'Imagick' ) ) {
			$cmyk = new Imagick(); $cmyk->newImage( 96, 64, 'red', 'jpeg' ); $cmyk->transformImageColorspace( Imagick::COLORSPACE_CMYK );
			$safe_failure( 'untagged-cmyk.jpg', $cmyk->getImageBlob() ); $cmyk->clear();
			require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
			class YoOhw_MO_Color_Stripping_Editor extends WP_Image_Editor_Imagick {
				public function save( $destination = null, $mime_type = null ) {
					$result = parent::save( $destination, $mime_type );
					if ( ! is_wp_error( $result ) ) { $image = new Imagick( $result['path'] ); $image->stripImage(); $image->writeImage( $result['path'] ); $image->clear(); }
					return $result;
				}
			}
			$filter( 'wp_image_editors', static function() { return array( 'YoOhw_MO_Color_Stripping_Editor' ); } );
			$safe_failure( 'stripped-output.jpg', $fixture( 'jpeg', $profiles['display_p3'], 6 ) );
		} else { echo "ICC stripped-editor/CMYK: NOT RUN — Imagick unavailable.\n"; }
		$assert( array() === glob( $dir . '/.*.yoohw-mo-*' ) && array() === glob( $dir . '/*.tmp-*' ), 'ICC rejection leaked owned temporary output.' );
	} elseif ( 'tool-flags' === $mode ) {
		// Controlled executables inspect argument policy, not real encoder fidelity.
		foreach ( array( 'jpegoptim', 'jpegtran', 'djpeg', 'cjpeg', 'pngquant', 'oxipng', 'optipng' ) as $tool ) {
			file_put_contents( $dir . '/' . $tool, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$0.args\"\nexit 1\n" ); chmod( $dir . '/' . $tool, 0700 );
		}
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, array_merge( $options, array( 'use_external_binaries' => 1 ) ) );
		list( $id, $jpeg ) = $attach( 'flags.jpg', $fixture( 'jpeg', $profiles['display_p3'] ) );
		list( $id, $png ) = $attach( 'flags.png', $fixture( 'rgba', $profiles['display_p3'] ) );
		$invoke( 'optimize_jpeg_with_cjpeg', array( $jpeg, $dir . '/cjpeg-out.jpg', $options ) );
		$assert( ! file_exists( $dir . '/djpeg.args' ) && ! file_exists( $dir . '/cjpeg.args' ), 'Tagged JPEG reached unsafe djpeg/cjpeg conversion.' );
		$invoke( 'optimize_png_with_external_binary', array( $png, $dir . '/png-out.png', $options ) );
		$assert( ! file_exists( $dir . '/pngquant.args' ), 'Tagged PNG reached CMS-converting pngquant.' );
		$assert( is_file( $dir . '/oxipng.args' ) && false === strpos( file_get_contents( $dir . '/oxipng.args' ), "all\n" ), 'oxipng strips ICC with all metadata.' );
		$assert( is_file( $dir . '/optipng.args' ) && false === strpos( file_get_contents( $dir . '/optipng.args' ), "all\n" ), 'optipng policy removes ICC with all metadata.' );
		$invoke( 'optimize_jpeg_with_jpegoptim', array( $jpeg, $dir . '/jpegoptim-out.jpg', $options ) );
		$args = file_get_contents( $dir . '/jpegoptim.args' );
		$assert( false !== strpos( $args, '--keep-icc' ) && ( false === strpos( $args, '--strip-all' ) || strpos( $args, '--strip-all' ) < strpos( $args, '--keep-icc' ) ), 'jpegoptim metadata policy strips ICC.' );
	}
	echo 'Color profile ' . $mode . ': PASS — ' . $count . " assertions.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Color profile ' . $mode . ': FAIL — ' . $error->getMessage() . "\n" ); $failed = true;
} finally {
	foreach ( $filters as $registered ) { remove_filter( $registered[0], $registered[1], 999 ); }
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $original_options );
	putenv( false === $original_path ? 'PATH' : 'PATH=' . $original_path );
	foreach ( $ids as $id ) { wp_delete_attachment( $id, true ); }
	foreach ( $backups as $backup ) { if ( $backup && is_file( $backup ) ) { unlink( $backup ); } }
	$remove( $dir );
}
if ( $failed ) { exit( 1 ); }
