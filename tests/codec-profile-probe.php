<?php
/**
 * Sidecar profile regression probe. Mutates only generated disposable fixtures.
 *
 * @package YoOhw_Media_Optimizer
 */
if ( 'cli' !== PHP_SAPI || ! isset( $argv[2] ) ) { exit( "Run through regression-tests.php.\n" ); }
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
$count = 0; $failed = false;
$assert = static function( $condition, $message ) use ( &$count ) { ++$count; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$original_options = get_option( YoOhw_Media_Optimizer::OPTION_KEY, array() );
$original_path = getenv( 'PATH' );
$dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'yoohw-mo-codec-' . wp_generate_password( 8, false, false );
$ids = $filters = $backups = array();
$options = array_merge( $original_options, array( 'auto_optimize_uploads' => 0, 'optimize_originals' => 0, 'backup_originals' => 1, 'quality' => 82, 'webp_quality' => 71, 'avif_quality' => 33, 'webp_png_mode' => 'lossy', 'avif_alpha_mode' => 'default', 'generate_webp_sidecars' => 1, 'generate_avif_sidecars' => 0, 'use_external_binaries' => 0, 'skip_larger_files' => 0, 'max_width' => 0, 'max_height' => 0, 'metadata_policy' => 'remove' ) );
$filter = static function( $hook, $callback ) use ( &$filters ) { add_filter( $hook, $callback, 999, 3 ); $filters[] = array( $hook, $callback ); };
$remove = static function( $path ) use ( &$remove ) {
	if ( is_file( $path ) || is_link( $path ) ) { unlink( $path ); }
	elseif ( is_dir( $path ) ) { foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $child ) { $remove( $child->getPathname() ); } rmdir( $path ); }
};
$run = static function( $id, $changes = array(), $force = true ) use ( $optimizer, $options ) {
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, array_merge( $options, $changes ) );
	return $optimizer->optimize_attachment( $id, $force );
};
$attach = static function( $name, $bytes ) use ( $dir, &$ids, $assert ) {
	$path = $dir . '/' . $name; file_put_contents( $path, $bytes );
	$id = wp_insert_attachment( array( 'post_title' => 'Codec profile fixture', 'post_mime_type' => '.png' === substr( $name, -4 ) ? 'image/png' : 'image/jpeg' ), $path );
	$assert( $id > 0, 'Cannot create codec fixture attachment.' ); $ids[] = $id;
	return array( $id, $path );
};
$chunk = static function( $type, $data ) { return pack( 'N', strlen( $data ) ) . $type . $data . hash( 'crc32b', $type . $data, true ); };
// Direct 8-bit RGBA PNG covers all 256 alpha values, including odd values GD cannot represent.
$png = static function( $depth = 8, $profile = '', $padding = true ) use ( $chunk ) {
	$raw = '';
	for ( $y = 0; $y < 16; ++$y ) {
		$raw .= "\0";
		for ( $x = 0; $x < 16; ++$x ) {
			$alpha = $y * 16 + $x;
			$rgb = $profile ? array( array( 230, 25, 35 ), array( 20, 205, 50 ), array( 25, 45, 225 ), array( 230, 210, 20 ) )[ ( $x >= 8 ? 1 : 0 ) + ( $y >= 8 ? 2 : 0 ) ] : array( 37 + ( $x % 3 ) * 61, 29 + ( $y % 3 ) * 73, 211 - ( $x % 4 ) * 41 );
			foreach ( array_merge( $rgb, array( $alpha ) ) as $value ) { $raw .= 16 === $depth ? pack( 'n', $value * 257 ) : chr( $value ); }
		}
	}
	return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', 16, 16, $depth, 6, 0, 0, 0 ) ) . ( $profile ? $chunk( 'iCCP', "Fixture\0\0" . gzcompress( $profile ) ) : '' ) . ( $padding ? $chunk( 'tEXt', "private\0" . str_repeat( 'GPS fixture payload ', 1000 ) ) : '' ) . $chunk( 'IDAT', gzcompress( $raw ) ) . $chunk( 'IEND', '' );
};
$jpeg = static function( $orientation = 1 ) {
	$image = imagecreatetruecolor( 48, 32 );
	for ( $y = 0; $y < 32; ++$y ) { for ( $x = 0; $x < 48; ++$x ) { imagesetpixel( $image, $x, $y, imagecolorallocate( $image, ( $x * 5 ) % 256, ( $y * 7 ) % 256, ( $x * 3 + $y * 2 ) % 256 ) ); } }
	ob_start(); imagejpeg( $image, null, 98 ); $bytes = ob_get_clean(); imagedestroy( $image );
	$exif = "Exif\0\0II\x2a\0" . pack( 'Vv', 8, 1 ) . pack( 'vvVv', 0x0112, 3, 1, $orientation ) . "\0\0" . pack( 'V', 0 );
	return substr( $bytes, 0, 2 ) . "\xff\xe1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $bytes, 2 );
};
$clean_stages = static function() use ( $assert, $dir ) { $assert( array() === glob( $dir . '/*.tmp-*' ) && array() === glob( $dir . '/.*.yoohw-mo-*' ), 'Codec failure leaked a caller-owned stage.' ); };
$failure_case = static function( $name, $bytes, $changes ) use ( $attach, $run, $assert, $clean_stages, $invoke, &$backups ) {
	list( $id, $path ) = $attach( $name, $bytes );
	$backup = $invoke( 'backup_file', array( $path ) );
	$assert( ! is_wp_error( $backup ), 'Cannot seed profile recovery data.' ); $backups[] = $backup['path']; $backup_hash = hash_file( 'sha256', $backup['path'] );
	file_put_contents( $path . '.webp', 'old webp' ); file_put_contents( $path . '.avif', 'old avif' );
	$result = $run( $id, $changes ); $file = $result['files'][0];
	$assert( 'failed' === $file['status'] && in_array( $file['avif_status'], array( 'failed', 'unsupported' ), true ), 'Advanced unavailable/failing profile silently fell back: ' . wp_json_encode( $result ) );
	$assert( 0 === $result['summary']['created'] && 0 === $result['summary']['updated'] && 0 === $result['summary']['avif_created'] && 0 === $result['summary']['avif_updated'], 'Failed profiles reported sidecar success.' );
	$assert( $bytes === file_get_contents( $path ) && 'old webp' === file_get_contents( $path . '.webp' ) && 'old avif' === file_get_contents( $path . '.avif' ), 'Failed profile changed source or historical sidecars.' );
	$assert( $backup_hash === hash_file( 'sha256', $backup['path'] ), 'Profile failure changed recovery bytes.' );
	$clean_stages(); return array( $id, $path );
};
// Decode libavif PNG output directly: GD exposes only 7 alpha bits.
$rgba8 = static function( $path ) use ( $assert ) {
	$bytes = file_get_contents( $path ); $position = 8; $compressed = ''; $header = null;
	while ( $position + 12 <= strlen( $bytes ) ) {
		$length = unpack( 'N', substr( $bytes, $position, 4 ) )[1]; $type = substr( $bytes, $position + 4, 4 ); $data = substr( $bytes, $position + 8, $length ); $position += 12 + $length;
		if ( 'IHDR' === $type ) { $header = unpack( 'Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data ); }
		if ( 'IDAT' === $type ) { $compressed .= $data; }
		if ( 'IEND' === $type ) { break; }
	}
	$assert( $header && 8 === $header['depth'] && 6 === $header['color'] && 0 === $header['interlace'], 'Exact alpha oracle requires noninterlaced RGBA8 PNG.' );
	$raw = gzuncompress( $compressed ); $stride = $header['width'] * 4; $previous = array_fill( 0, $stride, 0 ); $pixels = array(); $at = 0;
	for ( $y = 0; $y < $header['height']; ++$y ) {
		$kind = ord( $raw[ $at++ ] ); $row = array();
		for ( $i = 0; $i < $stride; ++$i ) {
			$a = $i >= 4 ? $row[ $i - 4 ] : 0; $b = $previous[$i]; $c = $i >= 4 ? $previous[$i - 4] : 0;
			$p = $a + $b - $c; $pa = abs( $p - $a ); $pb = abs( $p - $b ); $pc = abs( $p - $c );
			$predict = array( 0, $a, $b, (int) floor( ( $a + $b ) / 2 ), $pa <= $pb && $pa <= $pc ? $a : ( $pb <= $pc ? $b : $c ) );
			$assert( $kind <= 4, 'Invalid PNG predictor.' ); $row[$i] = ( ord( $raw[ $at++ ] ) + $predict[$kind] ) & 255;
		}
		$pixels = array_merge( $pixels, array_chunk( $row, 4 ) ); $previous = $row;
	}
	return array( $header['width'], $header['height'], $pixels );
};
$process = static function( $command ) use ( $assert ) {
	$child = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$assert( is_resource( $child ), 'Cannot launch native decoder.' ); fclose( $pipes[0] ); $output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
	$assert( 0 === proc_close( $child ), 'Native decoder failed: ' . $output );
};
$find = static function( $tool ) use ( $original_path ) {
	foreach ( array_unique( array_merge( explode( PATH_SEPARATOR, (string) $original_path ), array( '/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/opt/local/bin' ) ) ) as $directory ) {
		$path = rtrim( $directory, '/' ) . '/' . $tool; if ( is_file( $path ) && is_executable( $path ) ) { return $path; }
	}
	return '';
};
try {
	wp_mkdir_p( $dir );
	$filter( 'wp_image_editors', static function() { return array( 'WP_Image_Editor_GD' ); } );
	if ( 'settings' === $mode ) {
		foreach ( array( array(), array( 'quality' => 59 ), array( 'quality' => 59, 'webp_quality' => 24 ), array( 'quality' => 59, 'avif_quality' => 96 ) ) as $stored ) {
			update_option( YoOhw_Media_Optimizer::OPTION_KEY, $stored ); $actual = $optimizer->get_options(); $legacy = $stored['quality'] ?? 82;
			$assert( ( $stored['webp_quality'] ?? $legacy ) === $actual['webp_quality'] && ( $stored['avif_quality'] ?? $legacy ) === $actual['avif_quality'], 'Each absent codec quality must inherit legacy quality independently.' );
			$assert( 'lossy' === $actual['webp_png_mode'] && 'default' === $actual['avif_alpha_mode'], 'Profile defaults changed existing behavior.' );
		}
		foreach ( array( 0 => 1, 101 => 100, 1 => 1, 100 => 100 ) as $input => $expected ) {
			update_option( YoOhw_Media_Optimizer::OPTION_KEY, array( 'webp_quality' => $input, 'avif_quality' => $input, 'webp_png_mode' => 'invalid', 'avif_alpha_mode' => 'invalid' ) ); $actual = $optimizer->get_options();
			$assert( $expected === $actual['webp_quality'] && $expected === $actual['avif_quality'], 'Codec quality out of range.' );
			$assert( 'lossy' === $actual['webp_png_mode'] && 'default' === $actual['avif_alpha_mode'], 'Unknown profile did not normalize safely.' );
		}
		$a = $invoke( 'original_options_fingerprint', array( $options ) );
		$b = $invoke( 'original_options_fingerprint', array( array_merge( $options, array( 'webp_quality' => 12, 'avif_quality' => 98, 'webp_png_mode' => 'lossless', 'avif_alpha_mode' => 'lossless' ) ) ) );
		$assert( $a === $b, 'Sidecar profiles invalidated original fingerprints.' );
	} elseif ( 'gd' === $mode ) {
		list( $id, $path ) = $attach( 'gd.png', $png() ); $result = $run( $id );
		$assert( 'created' === $result['files'][0]['status'] && 'gd' === $result['files'][0]['webp_engine'], 'GD codec quality route unavailable.' );
		$image = imagecreatefrompng( $path ); imagepalettetotruecolor( $image ); imagealphablending( $image, true ); imagesavealpha( $image, true );
		imagewebp( $image, $dir . '/gd-expected.webp', 71 ); imagedestroy( $image );
		$assert( hash_file( 'sha256', $path . '.webp' ) === hash_file( 'sha256', $dir . '/gd-expected.webp' ), 'GD used legacy/AVIF quality instead of WebP quality.' );
	} elseif ( 'editor' === $mode ) {
		if ( ! class_exists( 'WP_Image_Editor' ) ) { require_once ABSPATH . WPINC . '/class-wp-image-editor.php'; }
		if ( ! class_exists( 'WP_Image_Editor_GD' ) ) { require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php'; }
		class YoOhw_MO_Codec_Quality_Editor extends WP_Image_Editor_GD {
			public static $qualities = array();
			public function set_quality( $quality = null, $dims = array() ) { self::$qualities[] = $quality; return parent::set_quality( $quality ); }
		}
		$filter( 'wp_image_editors', static function() { return array( 'YoOhw_MO_Codec_Quality_Editor' ); } );
		foreach ( array( 'webp' => 71, 'avif' => 33 ) as $format => $quality ) {
			if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/' . $format ) ) ) { echo $format . " profile editor: NOT RUN — WordPress/GD codec unavailable.\n"; continue; }
			list( $id, $path ) = $attach( 'editor-' . $format . '.jpg', $jpeg( 6 ) ); YoOhw_MO_Codec_Quality_Editor::$qualities = array();
			$result = $run( $id, array( 'generate_webp_sidecars' => 'webp' === $format ? 1 : 0, 'generate_avif_sidecars' => 'avif' === $format ? 1 : 0 ) );
			$status = 'webp' === $format ? 'status' : 'avif_status'; $assert( 'created' === $result['files'][0][$status], 'Oriented editor profile failed: ' . wp_json_encode( $result ) );
			$assert( in_array( $quality, YoOhw_MO_Codec_Quality_Editor::$qualities, true ) && ! in_array( 'webp' === $format ? 33 : 71, YoOhw_MO_Codec_Quality_Editor::$qualities, true ), 'WordPress editor received wrong codec quality.' );
		}
	} elseif ( 'editor-quality' === $mode ) {
		require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
		// Independent real-save oracle: apply quality after WordPress has resolved MIME.
		class YoOhw_MO_Quality_Oracle_GD extends WP_Image_Editor_GD {
			public $requested_quality;
			protected function get_output_format( $filename = null, $mime_type = null ) {
				$output = parent::get_output_format( $filename, $mime_type );
				$this->set_quality( $this->requested_quality );
				return $output;
			}
		}
		// Existing site callbacks must survive every save and cannot override explicit codec quality.
		$filter( 'wp_editor_set_quality', static function() { return 64; } );
		$quality_hooks = $GLOBALS['wp_filter']['wp_editor_set_quality']->callbacks;
		$check_hooks = static function() use ( $assert, $quality_hooks ) {
			$assert( $quality_hooks === $GLOBALS['wp_filter']['wp_editor_set_quality']->callbacks, 'Save leaked or removed a quality callback.' );
			$assert( 64 === apply_filters( 'wp_editor_set_quality', 11, 'image/jpeg' ), 'Save changed an unrelated MIME/site quality filter.' );
		};
		$engines = array( 'gd' );
		if ( class_exists( 'Imagick' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
			require_once dirname( __DIR__ ) . '/includes/class-yoohw-media-optimizer-imagick.php';
			class YoOhw_MO_Quality_Oracle_Imagick extends YoOhw_Media_Optimizer_Imagick {
				public $requested_quality;
				public function quality_state() {
					return array( 'image' => $this->image->getImageCompressionQuality(), 'wand' => $this->image->getCompressionQuality(), 'format' => $this->image->getImageFormat(), 'lossless' => $this->image->getOption( 'webp:lossless' ), 'near_lossless' => $this->image->getOption( 'webp:near-lossless' ) );
				}
				protected function get_output_format( $filename = null, $mime_type = null ) {
					$output = parent::get_output_format( $filename, $mime_type );
					$this->set_quality( $this->requested_quality );
					$this->image->setCompressionQuality( $this->requested_quality );
					return $output;
				}
			}
			$engines[] = 'imagick';
		} else {
			$assert( ! $required, 'Required real Imagick quality editor is unavailable.' );
			echo "Imagick editor quality: NOT RUN — extension unavailable.\n";
		}
		$engine = 'gd';
		$filter( 'wp_image_editors', static function() use ( &$engine ) { return array( 'imagick' === $engine ? 'WP_Image_Editor_Imagick' : 'WP_Image_Editor_GD' ); } );
		$icc = file_get_contents( __DIR__ . '/fixtures/display_p3.icc' );
		foreach ( $engines as $engine ) {
			foreach ( array( 'webp', 'avif' ) as $format ) {
				$mime = 'image/' . $format;
				if ( ! wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) {
					$assert( ! $required || 'imagick' !== $engine, 'Required Imagick quality codec unavailable: ' . $format );
					echo $engine . '/' . $format . " quality: NOT RUN — WordPress editor codec unavailable.\n"; continue;
				}
				$cases = 'imagick' === $engine ? array( 'rotate' => 6, 'reflect' => 2, 'neutral' => 1, 'png' => 1 ) : array( 'rotate' => 6 );
				foreach ( $cases as $case => $orientation ) {
					$bytes = 'png' === $case ? $png( 8, $icc ) : $jpeg( $orientation );
					if ( 'imagick' === $engine && 'png' !== $case ) {
						$body = "ICC_PROFILE\0\x01\x01" . $icc;
						$bytes = substr( $bytes, 0, 2 ) . "\xff\xe2" . pack( 'n', strlen( $body ) + 2 ) . $body . substr( $bytes, 2 );
					}
					list( $id, $path ) = $attach( $engine . '-' . $format . '-' . $case . ( 'png' === $case ? '.png' : '.jpg' ), $bytes );
					$hashes = $diagnostics = array();
					foreach ( array( 23, 89 ) as $quality ) {
						$changes = array( 'generate_webp_sidecars' => 'webp' === $format ? 1 : 0, 'generate_avif_sidecars' => 'avif' === $format ? 1 : 0, 'webp_quality' => 'webp' === $format ? $quality : 47, 'avif_quality' => 'avif' === $format ? $quality : 47 );
						$result = $run( $id, $changes ); $check_hooks(); $file = $result['files'][0];
						$status = 'webp' === $format ? 'status' : 'avif_status'; $engine_key = 'webp' === $format ? 'webp_engine' : 'avif_engine';
						$assert( in_array( $file[$status], array( 'created', 'updated' ), true ), 'Real editor quality save failed: ' . wp_json_encode( $result ) );
						$assert( ( 'imagick' === $engine ? 'YoOhw_Media_Optimizer_Imagick' : 'WP_Image_Editor_GD' ) === $file[$engine_key], 'Quality test silently selected another encoder.' );
						$class = 'imagick' === $engine ? 'YoOhw_MO_Quality_Oracle_Imagick' : 'YoOhw_MO_Quality_Oracle_GD';
						$expected = new $class( $path ); $expected->requested_quality = $quality;
						$assert( ! is_wp_error( $expected->load() ), 'Cannot load real quality oracle.' );
						if ( $orientation > 1 ) { $assert( true === $expected->maybe_exif_rotate(), 'Quality oracle cannot normalize orientation.' ); }
						if ( 'imagick' === $engine ) { $assert( true === $expected->prepare_color( $icc, true ), 'Quality oracle cannot prepare ICC/privacy.' ); }
						$oracle = $dir . '/expected-' . $engine . '-' . $format . '-' . $case . '-' . $quality . '.' . $format;
						$assert( ! is_wp_error( $expected->save( $oracle, $mime ) ), 'Real quality oracle cannot save.' );
						$hashes[] = hash_file( 'sha256', $path . '.' . $format );
						$diagnostics[] = array( 'requested' => $quality, 'editor_quality' => $expected->get_quality(), 'state' => method_exists( $expected, 'quality_state' ) ? $expected->quality_state() : array(), 'chunk' => substr( file_get_contents( $path . '.' . $format ), 12, 4 ), 'bytes' => filesize( $path . '.' . $format ), 'hash' => end( $hashes ) );
						$assert( end( $hashes ) === hash_file( 'sha256', $oracle ), 'Encoded quality differs from post-MIME oracle: ' . $engine . '/' . $format . '/' . $case . '/' . $quality );
						$size = getimagesize( $path . '.' . $format );
						$assert( $mime === $size['mime'] && ( 6 === $orientation ? array( 32, 48 ) : ( 'png' === $case ? array( 16, 16 ) : array( 48, 32 ) ) ) === array( $size[0], $size[1] ), 'Quality save changed MIME/display dimensions.' );
						$assert( hash( 'sha256', $bytes ) === hash_file( 'sha256', $path ), 'Quality save changed source bytes.' );
						if ( 'imagick' === $engine ) {
							$image = new Imagick( $path . '.' . $format ); $profiles = $image->getImageProfiles( '*', true );
							$assert( $icc === ( $profiles['icc'] ?? '' ) && empty( $profiles['exif'] ) && empty( $profiles['xmp'] ), 'Quality save lost ICC or retained privacy metadata.' ); $image->clear();
						}
						// Changed settings alone must keep a fresh sidecar; explicit force above applies quality.
						touch( $path . '.' . $format, time() + 5 );
						$fresh = $run( $id, array_merge( $changes, array( 'webp_quality' => 51, 'avif_quality' => 52 ) ), false );
						$assert( 'existing' === $fresh['files'][0][$status] && end( $hashes ) === hash_file( 'sha256', $path . '.' . $format ), 'Quality change replayed a fresh sidecar.' );
					}
					$assert( $hashes[0] !== $hashes[1], 'Different qualities produced identical real editor output: ' . $engine . '/' . $format . '/' . $case . ' ' . wp_json_encode( array( 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'imagick' => phpversion( 'imagick' ), 'library' => class_exists( 'Imagick' ) ? Imagick::getVersion() : array(), 'encodes' => $diagnostics ) ) );
				}
			}
		}
		// Exercise the scope boundary even when no image is written or save throws.
		class YoOhw_MO_Quality_Failure_Editor {
			public $failure;
			public $seen = array();
			public $setter_calls = 0;
			public function set_quality( $quality = null ) {
				++$this->setter_calls;
				return 'setter' === $this->failure ? new WP_Error( 'quality_fixture', 'Setter refused quality.' ) : true;
			}
			public function save( $path, $mime ) {
				$this->seen = array( apply_filters( 'wp_editor_set_quality', 11, $mime ), apply_filters( 'wp_editor_set_quality', 11, 'image/jpeg' ) );
				if ( 'throw' === $this->failure ) { throw new RuntimeException( 'Quality save fixture.' ); }
				return 'save' === $this->failure ? new WP_Error( 'quality_fixture', 'Save refused quality.' ) : array( 'path' => $path );
			}
		}
		list( $id, $path ) = $attach( 'quality-scope.jpg', $jpeg() );
		foreach ( array( 'success', 'setter', 'save', 'throw', 'original' ) as $failure ) {
			$editor = new YoOhw_MO_Quality_Failure_Editor(); $editor->failure = $failure;
			try {
				$args = array( $editor, $path, $dir . '/scope.webp', 'image/webp', $options );
				if ( 'original' !== $failure ) { $args[] = 71; }
				$result = $invoke( 'save_color_editor', $args );
				$assert( 'throw' !== $failure, 'Save exception was swallowed.' );
				$assert( in_array( $failure, array( 'setter', 'save' ), true ) === is_wp_error( $result ), 'Quality/save error was not propagated.' );
			} catch ( RuntimeException $error ) {
				$assert( 'throw' === $failure && 'Quality save fixture.' === $error->getMessage(), 'Unexpected scope exception: ' . $error->getMessage() );
			}
			$check_hooks();
			$assert( 'setter' === $failure ? array() === $editor->seen : array( 'original' === $failure ? 64 : 71, 64 ) === $editor->seen, 'Quality callback escaped target MIME or setter failure still saved.' );
			$assert( ( 'original' === $failure ? 0 : 1 ) === $editor->setter_calls, 'Optional sidecar quality changed original/editor setter behavior.' );
		}
	} elseif ( 'native-missing' === $mode ) {
		$changes = array( 'generate_avif_sidecars' => 1, 'webp_png_mode' => 'lossless', 'avif_alpha_mode' => 'lossless', 'use_external_binaries' => 0 );
		list( $id, $path ) = $failure_case( 'disabled-native.png', $png(), $changes );
		// New modes alone do not rewrite fresh historical sidecars.
		touch( $path . '.webp', time() + 10 ); touch( $path . '.avif', time() + 10 );
		$result = $run( $id, $changes, false );
		$assert( 'existing' === $result['files'][0]['status'] && 'existing' === $result['files'][0]['avif_status'] && 'old webp' === file_get_contents( $path . '.webp' ) && 'old avif' === file_get_contents( $path . '.avif' ), 'Fresh sidecars regenerated after profile change.' );
		$run( $id, $changes, true ); $assert( 'old webp' === file_get_contents( $path . '.webp' ) && 'old avif' === file_get_contents( $path . '.avif' ), 'Force bypassed unavailable-profile safety.' );
		list( $jpeg_id, $jpeg_path ) = $attach( 'jpeg-unaffected.jpg', $jpeg() );
		$result = $run( $jpeg_id, $changes ); $assert( 'created' === $result['files'][0]['status'] && 'gd' === $result['files'][0]['webp_engine'], 'PNG advanced profile disabled normal JPEG fallback.' );
	} else {
		// First lookup occurs only after PATH wrappers are installed, isolating the static cache.
		$tools = array( 'cwebp' => $find( 'cwebp' ), 'avifenc' => $find( 'avifenc' ), 'avifdec' => $find( 'avifdec' ) );
		foreach ( array( 'cwebp', 'avifenc' ) as $tool ) {
			if ( 'native-fault' !== $mode && ! $tools[$tool] ) { $assert( ! $required, 'Required real profile encoder unavailable: ' . $tool ); echo $tool . " profile: NOT RUN — binary unavailable.\n"; continue; }
			$wrapper = "<?php\n" . '$args = array_slice($argv, 1); file_put_contents(substr(__FILE__, 0, -4) . ".args", json_encode($args) . "\\n", FILE_APPEND);' . "\n";
			if ( 'native-fault' === $mode ) {
				$wrapper .= '$out = end($args); if (false !== ($i = array_search("-o", $args, true))) { $out = $args[$i + 1]; } file_put_contents($out, "partial output"); exit(1);';
			} else {
				$wrapper .= '$p = proc_open(array_merge(array(' . var_export( $tools[$tool], true ) . '), $args), array(0 => array("file", "/dev/null", "r"), 1 => array("file", "/dev/null", "w"), 2 => array("file", "/dev/null", "w")), $pipes); $code = is_resource($p) ? proc_close($p) : 1; if (!$code && is_file(__DIR__ . "/inflate")) { $out = end($args); if (false !== ($i = array_search("-o", $args, true))) { $out = $args[$i + 1]; } $body = file_get_contents($out); $padding = str_repeat("x", 50000); if ("cwebp" === basename(__FILE__, ".php")) { $body .= "JUNK" . pack("V", strlen($padding)) . $padding; $body = substr($body, 0, 4) . pack("V", strlen($body) - 8) . substr($body, 8); } else { $body .= pack("N", strlen($padding) + 8) . "free" . $padding; } file_put_contents($out, $body); } exit($code);';
			}
			file_put_contents( $dir . '/' . $tool . '.php', $wrapper );
			file_put_contents( $dir . '/' . $tool, "#!/bin/sh\nexec " . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $dir . '/' . $tool . '.php' ) . ' "$@"' . "\n" );
			chmod( $dir . '/' . $tool, 0700 );
		}
		putenv( 'PATH=' . $dir );
		$advanced = array( 'generate_avif_sidecars' => 1, 'webp_png_mode' => 'lossless', 'avif_alpha_mode' => 'lossless', 'use_external_binaries' => 1 );
		if ( 'native-fault' === $mode ) {
			$failure_case( 'failing-native.png', $png(), $advanced );
			$assert( is_file( $dir . '/cwebp.args' ) && is_file( $dir . '/avifenc.args' ), 'Fault probe did not invoke both native encoders.' );
		} else {
			foreach ( array( 'cwebp' => 'webp', 'avifenc' => 'avif' ) as $tool => $format ) {
				if ( ! $tools[$tool] ) { continue; }
				$changes = array_merge( $advanced, array( 'generate_webp_sidecars' => 'webp' === $format ? 1 : 0, 'generate_avif_sidecars' => 'avif' === $format ? 1 : 0 ) );
				$status = 'webp' === $format ? 'status' : 'avif_status'; $engine = $format . '_engine';
				list( $id, $path ) = $attach( 'real-' . $format . '.png', $png() ); $source = file_get_contents( $path );
				$result = $run( $id, $changes ); $file = $result['files'][0];
				$assert( 'created' === $file[$status] && $tool === $file[$engine], 'Advanced real encoder failed/fell back: ' . wp_json_encode( $result ) );
				$assert( $source === file_get_contents( $path ), 'Advanced profile changed source bytes.' );
				$args = json_decode( trim( file_get_contents( $dir . '/' . $tool . '.args' ) ), true );
				if ( 'webp' === $format ) {
					$assert( in_array( '-lossless', $args, true ) && '75' === $args[array_search( '-q', $args, true ) + 1] && '4' === $args[array_search( '-m', $args, true ) + 1], 'Lossless WebP effort flags differ from bounded profile.' );
				} else {
					$assert( '33' === $args[array_search( '-q', $args, true ) + 1] && '0' === $args[array_search( '--minalpha', $args, true ) + 1] && '0' === $args[array_search( '--maxalpha', $args, true ) + 1] && '1' === $args[array_search( '--jobs', $args, true ) + 1], 'AVIF color quality/alpha profile flags differ.' );
				}
				// Every visible RGB+alpha sample is exact for lossless WebP; AVIF checks all 256 alpha values.
				list( $width, $height, $expected ) = $rgba8( $path );
				if ( 'avif' === $format && $tools['avifdec'] ) {
					$decoded = $dir . '/decoded.png'; $process( array( $tools['avifdec'], '--depth', '8', $path . '.avif', $decoded ) ); list( $w, $h, $actual ) = $rgba8( $decoded );
				} elseif ( class_exists( 'Imagick' ) && Imagick::queryFormats( strtoupper( $format ) ) ) {
					$image = new Imagick( $path . '.' . $format ); $w = $image->getImageWidth(); $h = $image->getImageHeight(); $actual = array_chunk( $image->exportImagePixels( 0, 0, $w, $h, 'RGBA', Imagick::PIXEL_CHAR ), 4 ); $image->clear();
				} else {
					$assert( ! $required, 'Required exact 8-bit profile decoder unavailable: ' . $format ); echo $format . " exact pixels: NOT RUN — no 8-bit native decoder.\n"; $actual = null;
				}
				if ( null !== $actual ) {
					$assert( $width === $w && $height === $h && count( $expected ) === count( $actual ), 'Advanced profile decoded dimensions changed.' );
					foreach ( $expected as $index => $pixel ) {
						$assert( $pixel[3] === $actual[$index][3], 'Advanced profile changed exact 8-bit alpha at pixel ' . $index );
						if ( 'webp' === $format && $pixel[3] ) { $assert( array_slice( $pixel, 0, 3 ) === array_slice( $actual[$index], 0, 3 ), 'Lossless WebP changed visible RGB at pixel ' . $index ); }
					}
				}
				$assert( false === strpos( file_get_contents( $path . '.' . $format ), 'GPS fixture payload' ), 'Advanced native profile retained private metadata.' );
				// Changing codec settings preserves freshness; force and stale sources apply the selected profile.
				$old = hash_file( 'sha256', $path . '.' . $format ); touch( $path . '.' . $format, time() + 5 );
				$result = $run( $id, array_merge( $changes, array( 'webp_quality' => 19, 'avif_quality' => 21 ) ), false );
				$assert( 'existing' === $result['files'][0][$status] && $old === hash_file( 'sha256', $path . '.' . $format ), 'Profile change rewrote fresh output.' );
				$result = $run( $id, $changes, true ); $assert( 'updated' === $result['files'][0][$status], 'Force did not regenerate advanced profile.' );
				touch( $path . '.' . $format, time() - 10 ); touch( $path, time() ); clearstatcache();
				$result = $run( $id, $changes, false ); $assert( 'updated' === $result['files'][0][$status], 'Stale advanced sidecar was not regenerated.' );
				// Quality routing remains codec-specific in ordinary native mode and JPEG ignores PNG-only flags.
				list( $jpeg_id, $jpeg_path ) = $attach( 'native-' . $format . '.jpg', $jpeg() ); $result = $run( $jpeg_id, $changes );
				$assert( 'created' === $result['files'][0][$status] && $tool === $result['files'][0][$engine], 'PNG profile changed JPEG engine behavior.' );
				$lines = file( $dir . '/' . $tool . '.args', FILE_IGNORE_NEW_LINES ); $args = json_decode( end( $lines ), true );
				$assert( ( 'webp' === $format ? '71' : '33' ) === $args[array_search( '-q', $args, true ) + 1] && ! in_array( '-lossless', $args, true ) && ! in_array( '--minalpha', $args, true ) && ! in_array( '--maxalpha', $args, true ), 'JPEG received PNG-only mode or wrong codec quality.' );
				// Valid oversized candidates must not replace an existing published sidecar.
				list( $large_id, $large_path ) = $attach( 'larger-' . $format . '.png', $png() );
				file_put_contents( $large_path . '.' . $format, 'old sidecar' ); file_put_contents( $dir . '/inflate', '1' );
				try { $large = $run( $large_id, array_merge( $changes, array( 'skip_larger_files' => 1 ) ) ); }
				finally { unlink( $dir . '/inflate' ); }
				$assert( 'skipped_larger' === $large['files'][0][$status] && 'old sidecar' === file_get_contents( $large_path . '.' . $format ) && $png() === file_get_contents( $large_path ), 'Larger profile candidate replaced source/historical sidecar: ' . wp_json_encode( $large ) );
				$clean_stages();
				// Tagged P3 must keep the same raw samples and exact source ICC, plus privacy.
				if ( class_exists( 'Imagick' ) && Imagick::queryFormats( strtoupper( $format ) ) ) {
					$profile = file_get_contents( __DIR__ . '/fixtures/display_p3.icc' );
					list( $tagged_id, $tagged_path ) = $attach( 'p3-' . $format . '.png', $png( 8, $profile ) ); $tagged_source = file_get_contents( $tagged_path );
					$tagged = $run( $tagged_id, $changes );
					$assert( 'created' === $tagged['files'][0][$status] && $tool === $tagged['files'][0][$engine], 'P3 advanced profile did not use real native encoder: ' . wp_json_encode( $tagged ) );
					$image = new Imagick( $tagged_path . '.' . $format ); $profiles = $image->getImageProfiles( '*', true );
					$assert( $profile === ( $profiles['icc'] ?? ( $profiles['icm'] ?? '' ) ) && ! array_intersect( array_keys( $profiles ), array( 'exif', 'xmp', 'iptc' ) ), 'Advanced P3 output lost ICC or retained private metadata.' );
					$raw = array_chunk( $image->exportImagePixels( 0, 0, 16, 16, 'RGBA', Imagick::PIXEL_CHAR ), 4 ); list( $w, $h, $expected_p3 ) = $rgba8( $tagged_path );
					foreach ( array( 68, 76, 196, 204 ) as $index ) { foreach ( array( 0, 1, 2 ) as $channel ) { $assert( abs( $raw[$index][$channel] - $expected_p3[$index][$channel] ) <= ( 'webp' === $format ? 0 : 18 ), 'P3 advanced raster was converted or relabelled.' ); } }
					$delegates = implode( ' ', Imagick::getConfigureOptions( 'DELEGATES' ) );
					if ( preg_match( '/\blcms(?:2)?\b/i', $delegates ) ) {
						$image->setImageRenderingIntent( Imagick::RENDERINGINTENT_RELATIVE ); $image->profileImage( 'icc', file_get_contents( __DIR__ . '/fixtures/srgb.icc' ) );
						$managed = array( array( 251, 0, 7 ), array( 0, 209, 0 ), array( 18, 46, 234 ), array( 234, 209, 0 ) );
						foreach ( array( array( 4, 4 ), array( 12, 4 ), array( 4, 12 ), array( 12, 12 ) ) as $i => $point ) { $pixel = $image->getImagePixelColor( $point[0], $point[1] )->getColor(); foreach ( array( 'r', 'g', 'b' ) as $c => $key ) { $assert( abs( $pixel[$key] - $managed[$i][$c] ) <= ( 'webp' === $format ? 3 : 21 ), 'Advanced P3 managed patch differs from independent LittleCMS oracle.' ); } }
					} else { $assert( ! $required, 'Required P3 LCMS verifier unavailable.' ); echo $format . " P3 CMS: NOT RUN — Imagick lacks LCMS.\n"; }
					$image->clear(); $assert( $tagged_source === file_get_contents( $tagged_path ), 'Advanced P3 changed source bytes.' );
				} else { $assert( ! $required, 'Required P3 verifier unavailable.' ); echo $format . " P3: NOT RUN — Imagick codec unavailable.\n"; }
				echo $format . " profile: PASS — real native encoder, exact decoded alpha, source/privacy/freshness/quality routing.\n";
			}
			$failure_case( 'sixteen-bit.png', $png( 16 ), $advanced );
		}
	}
	$clean_stages(); echo 'Codec profile ' . $mode . ': PASS — ' . $count . " assertions.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Codec profile ' . $mode . ': FAIL — ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n" ); $failed = true;
} finally {
	foreach ( $filters as $registered ) { remove_filter( $registered[0], $registered[1], 999 ); }
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $original_options );
	putenv( false === $original_path ? 'PATH' : 'PATH=' . $original_path );
	foreach ( $ids as $id ) { wp_delete_attachment( $id, true ); }
	foreach ( $backups as $backup ) { if ( is_file( $backup ) ) { unlink( $backup ); } }
	$remove( $dir );
}
if ( $failed ) { exit( 1 ); }
