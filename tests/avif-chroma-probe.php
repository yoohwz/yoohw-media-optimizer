<?php
/** Imagick chroma checks reuse the isolated codec-profile fixture lifecycle.
 * @package YoOhw_Media_Optimizer
 */
if ( ! isset( $mode, $assert, $invoke ) || 'cli' !== PHP_SAPI ) { exit; }
if ( 0 === strpos( $mode, 'avif-chroma-post-' ) ) {
	$case = substr( $mode, strlen( 'avif-chroma-post-' ) );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$assert( ! empty( $admins ), 'Settings probe needs the disposable site administrator.' );
	wp_set_current_user( 'capability' === $case ? 0 : $admins[0] );
	$stored = array_merge( $options, array( 'avif_imagick_chroma' => 'enable' === $case ? 'default' : '444' ) );
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $stored );
	$_POST = array( '_wpnonce' => 'nonce' === $case ? 'invalid' : wp_create_nonce( 'yoohw_mo_save_settings' ) );
	if ( 'missing' !== $case ) { $_POST['avif_imagick_chroma'] = 'invalid' === $case ? array( '444' ) : ( 'enable' === $case ? '444' : 'default' ); }
	$_REQUEST = $_POST;
	// Invoke the real guards and handler; intercept termination only inside this child.
	$filter( 'wp_die_handler', static function() { return static function() { throw new RuntimeException( 'fixture wp_die' ); }; } );
	$filter( 'wp_redirect', static function() { throw new RuntimeException( 'fixture redirect' ); } );
	ob_start();
	try { $optimizer->handle_save_settings(); $assert( false, 'Settings handler did not terminate.' ); }
	catch ( RuntimeException $error ) {
		$denied = in_array( $case, array( 'nonce', 'capability' ), true );
		$assert( ( $denied ? 'fixture wp_die' : 'fixture redirect' ) === $error->getMessage(), 'Unexpected settings termination: ' . $error->getMessage() );
	} finally { ob_end_clean(); }
	$actual = get_option( YoOhw_Media_Optimizer::OPTION_KEY );
	if ( $denied ) { $assert( $stored === $actual, 'Rejected settings request changed options.' ); }
	else { $assert( ( in_array( $case, array( 'missing', 'enable' ), true ) ? '444' : 'default' ) === $actual['avif_imagick_chroma'], 'Settings POST did not normalize/preserve chroma.' ); }
	return;
}
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
require_once dirname( $reflection->getFileName() ) . '/class-yoohw-media-optimizer-imagick.php';
if ( 'avif-chroma-fault' === $mode ) {
	class YoOhw_MO_Chroma_Wand {
		public $failure;
		public $calls = array();
		public function setOption( $key, $value ) {
			$this->calls[] = array( $key, $value );
			if ( 'exception' === $this->failure ) { throw new Exception( 'Chroma fixture exception.' ); }
			if ( 'error' === $this->failure ) { throw new Error( 'Chroma fixture error.' ); }
			return 'false' !== $this->failure;
		}
	}
	class YoOhw_MO_Chroma_Fault_Editor extends YoOhw_Media_Optimizer_Imagick {
		public static $failure = 'false';
		public static $writes = 0;
		public static $wand;
		public static function test( $args = array() ) { return true; }
		public static function supports_mime_type( $mime ) { return true; }
		public function load() { $this->image = self::$wand = new YoOhw_MO_Chroma_Wand(); $this->image->failure = self::$failure; return true; }
		public function prepare_color( $icc, $private ) { return true; }
		public function set_quality( $quality = null, $dims = array() ) { return true; }
		public function save( $path = null, $mime = null ) {
			++self::$writes; file_put_contents( $path, 'partial stage' );
			return new WP_Error( 'chroma_partial', 'Chroma partial save.', array( 'path' => $path ) );
		}
	}
	$filter( 'wp_image_editors', static function() { return array( 'YoOhw_MO_Chroma_Fault_Editor' ); } );
	foreach ( array( 'false', 'exception', 'error', 'partial' ) as $fault ) {
		YoOhw_MO_Chroma_Fault_Editor::$failure = $fault; YoOhw_MO_Chroma_Fault_Editor::$writes = 0;
		if ( 'partial' !== $fault ) {
			$editor = new YoOhw_MO_Chroma_Fault_Editor( '' ); $editor->load();
			$result = $editor->request_avif444();
			$assert( is_wp_error( $result ) && 'yoohw_mo_avif_chroma' === $result->get_error_code(), 'Actual chroma setter must reject wand false/Throwable.' );
			$assert( array( array( 'heic:chroma', '444' ) ) === YoOhw_MO_Chroma_Fault_Editor::$wand->calls, 'Setter requested another preference.' );
		}
		$failure_case( 'chroma-' . $fault . '.png', $png(), array( 'generate_webp_sidecars' => 0, 'generate_avif_sidecars' => 1, 'avif_imagick_chroma' => '444' ) );
		$assert( ( 'partial' === $fault ? 1 : 0 ) === YoOhw_MO_Chroma_Fault_Editor::$writes, 'Failed chroma setter still wrote a candidate.' );
	}
	// Unrelated MIME, default, original and independent custom editors receive no request.
	list( $id, $path ) = $attach( 'chroma-scope.png', $png() );
	foreach ( array( array( 'image/webp', 33, '444' ), array( 'image/png', null, '444' ), array( 'image/avif', 33, 'default' ) ) as $scope ) {
		$editor = new YoOhw_MO_Chroma_Fault_Editor( '' ); $editor->load();
		$invoke( 'save_color_editor', array( $editor, $path, $dir . '/scope.tmp', $scope[0], array_merge( $options, array( 'avif_imagick_chroma' => $scope[2] ) ), $scope[1] ) );
		$assert( array() === YoOhw_MO_Chroma_Fault_Editor::$wand->calls, 'Chroma request escaped the selected AVIF sidecar scope.' );
	}
	class YoOhw_MO_Chroma_Custom_Editor {
		public function request_avif444() { throw new RuntimeException( 'Independent editor received chroma request.' ); }
		public function set_quality( $quality ) { return true; }
		public function save( $path, $mime ) { return new WP_Error( 'custom_fixture', 'Expected custom save.' ); }
	}
	$invoke( 'save_color_editor', array( new YoOhw_MO_Chroma_Custom_Editor(), $path, $dir . '/custom.tmp', 'image/avif', array_merge( $options, array( 'avif_imagick_chroma' => '444' ) ), 33 ) );
	return;
}
// Install before rendering diagnostics can populate the process-local native cache.
file_put_contents( $dir . '/avifenc.php', '<?php $args = array_slice($argv, 1); file_put_contents(__DIR__ . "/avifenc.args", json_encode($args)); $i = array_search("-o", $args, true); if (false !== $i) { file_put_contents($args[$i + 1], "partial native stage"); } exit(1);' );
file_put_contents( $dir . '/avifenc', "#!/bin/sh\nexec " . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $dir . '/avifenc.php' ) . ' "$@"' . "\n" ); chmod( $dir . '/avifenc', 0700 ); putenv( 'PATH=' . $dir );
foreach ( array( null, 'default', '444', 'invalid', 444, array( '444' ), true, new stdClass() ) as $input ) {
	$stored = null === $input ? array() : array( 'avif_imagick_chroma' => $input );
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $stored );
	$assert( ( '444' === $input ? '444' : 'default' ) === $optimizer->get_options()['avif_imagick_chroma'], 'Stored chroma did not normalize strictly.' );
}
$settings = array_merge( $options, array( 'avif_imagick_chroma' => '444' ) );
ob_start(); $invoke( 'render_settings_form', array( $settings ) ); $html = ob_get_clean();
$assert( preg_match( '/<select name="avif_imagick_chroma">.*?<option value="444"\s+selected=/s', $html ), 'Rendered settings do not select the current chroma value.' );
$assert( $invoke( 'original_options_fingerprint', array( $options ) ) === $invoke( 'original_options_fingerprint', array( $settings ) ), 'Chroma changed original optimization fingerprints.' );
$filter( 'wp_image_editors', static function() { return array( 'WP_Image_Editor_Imagick' ); } );
if ( ! class_exists( 'Imagick' ) || ! Imagick::queryFormats( 'AVIF' ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
	$assert( ! $required, 'Required real Imagick AVIF chroma encoder unavailable.' );
	echo "Imagick AVIF chroma: NOT RUN — extension/codec/WordPress AVIF editor unavailable (including WordPress 6.4).\n"; return;
}
// Read bounded BMFF properties, not an option echo: color av1C must declare no subsampling.
$chroma = static function( $path ) use ( $assert ) {
	$bytes = file_get_contents( $path ); $properties = array();
	$walk = static function( $start, $end, $depth = 0 ) use ( &$walk, &$properties, $bytes, $assert ) {
		$assert( $depth < 8, 'AVIF property nesting exceeds fixture bounds.' );
		for ( $at = $start; $at + 8 <= $end; $at += $size ) {
			$size = unpack( 'N', substr( $bytes, $at, 4 ) )[1]; $type = substr( $bytes, $at + 4, 4 );
			$assert( $size >= 8 && $at + $size <= $end, 'Invalid bounded AVIF property box.' );
			if ( in_array( $type, array( 'meta', 'iprp', 'ipco' ), true ) ) { $walk( $at + ( 'meta' === $type ? 12 : 8 ), $at + $size, $depth + 1 ); }
			if ( 'av1C' === $type ) { $assert( $size >= 12 && 0x81 === ord( $bytes[$at + 8] ), 'Invalid AV1 configuration record.' ); $flags = ord( $bytes[$at + 10] ); if ( ! ( $flags & 0x10 ) ) { $properties[] = $flags & 0x0c; } }
		}
	};
	$walk( 0, strlen( $bytes ) ); $assert( ! empty( $properties ), 'AVIF lacks a color AV1 configuration record.' );
	return $properties;
};
class YoOhw_MO_Chroma_Oracle extends YoOhw_Media_Optimizer_Imagick {
	public $requested_quality;
	protected function get_output_format( $filename = null, $mime = null ) {
		$result = parent::get_output_format( $filename, $mime ); $this->set_quality( $this->requested_quality ); $this->image->setCompressionQuality( $this->requested_quality ); return $result;
	}
}
$icc = file_get_contents( __DIR__ . '/fixtures/display_p3.icc' );
foreach ( array( 'untagged' => 1, 'p3' => 1, 'rotate' => 6, 'reflect' => 2 ) as $case => $orientation ) {
	$tagged = 'untagged' !== $case; $is_png = in_array( $case, array( 'untagged', 'p3' ), true );
	$bytes = $is_png ? $png( 8, $tagged ? $icc : '' ) : $jpeg( $orientation );
	if ( ! $is_png ) { $body = "ICC_PROFILE\0\x01\x01" . $icc; $bytes = substr( $bytes, 0, 2 ) . "\xff\xe2" . pack( 'n', strlen( $body ) + 2 ) . $body . substr( $bytes, 2 ); }
	list( $id, $path ) = $attach( 'chroma-' . $case . ( $is_png ? '.png' : '.jpg' ), $bytes ); $hashes = array();
	foreach ( array( 'default', '444' ) as $preference ) {
		foreach ( array( 23, 89 ) as $quality ) {
			$changes = array( 'generate_webp_sidecars' => 0, 'generate_avif_sidecars' => 1, 'avif_quality' => $quality, 'avif_imagick_chroma' => $preference );
			$result = $run( $id, $changes ); $file = $result['files'][0];
			$assert( in_array( $file['avif_status'], array( 'created', 'updated' ), true ) && 'YoOhw_Media_Optimizer_Imagick' === $file['avif_engine'], 'Chroma selected another encoder or failed: ' . wp_json_encode( $result ) );
			$oracle = new YoOhw_MO_Chroma_Oracle( $path ); $oracle->requested_quality = $quality;
			$assert( ! is_wp_error( $oracle->load() ), 'Cannot load chroma byte oracle.' );
			if ( $orientation > 1 ) { $assert( true === $oracle->maybe_exif_rotate(), 'Cannot normalize chroma oracle orientation.' ); }
			$assert( true === $oracle->prepare_color( $tagged ? $icc : '', true ), 'Cannot prepare chroma oracle color/privacy.' );
			if ( '444' === $preference ) { $assert( true === $oracle->request_avif444(), 'Real Imagick refused chroma preference.' ); }
			$expected = $dir . '/expected-' . $case . '-' . $preference . '-' . $quality . '.avif';
			$assert( ! is_wp_error( $oracle->save( $expected, 'image/avif' ) ), 'Cannot save chroma oracle.' );
			$hash = hash_file( 'sha256', $path . '.avif' ); $hashes[$preference][] = $hash;
			$assert( $hash === hash_file( 'sha256', $expected ), 'Chroma output differs from real post-MIME quality oracle/default parity.' );
			if ( '444' === $preference ) { $assert( array( 0 ) === array_values( array_unique( $chroma( $path . '.avif' ) ) ), 'Actual AVIF color bitstream is not YUV444.' ); }
			$image = new Imagick( $path . '.avif' ); $profiles = $image->getImageProfiles( '*', true );
			$assert( ( 6 === $orientation ? array( 32, 48 ) : ( $is_png ? array( 16, 16 ) : array( 48, 32 ) ) ) === array( $image->getImageWidth(), $image->getImageHeight() ), 'Chroma changed displayed dimensions.' );
			$assert( ( $tagged ? $icc : '' ) === ( $profiles['icc'] ?? ( $profiles['icm'] ?? '' ) ) && ! array_intersect( array_keys( $profiles ), array( 'exif', 'xmp', 'iptc' ) ), 'Chroma changed ICC/privacy.' );
			if ( 'p3' === $case ) {
				$patches = array( array( 230, 25, 35 ), array( 20, 205, 50 ), array( 25, 45, 225 ), array( 230, 210, 20 ) );
				foreach ( array( array( 4, 4 ), array( 12, 4 ), array( 4, 12 ), array( 12, 12 ) ) as $i => $point ) { $pixel = $image->getImagePixelColor( $point[0], $point[1] )->getColor(); foreach ( array( 'r', 'g', 'b' ) as $c => $key ) { $assert( abs( $pixel[$key] - $patches[$i][$c] ) <= 18, 'Chroma converted/relabelled P3 raw samples.' ); } }
			}
			$image->clear();
			$assert( $bytes === file_get_contents( $path ), 'Chroma changed source bytes.' );
			touch( $path . '.avif', time() + 5 );
			$fresh = $run( $id, array_merge( $changes, array( 'avif_imagick_chroma' => '444' === $preference ? 'default' : '444' ) ), false );
			$assert( 'existing' === $fresh['files'][0]['avif_status'] && $hash === hash_file( 'sha256', $path . '.avif' ), 'Changed chroma rewrote a fresh sidecar.' );
		}
		$assert( $hashes[$preference][0] !== $hashes[$preference][1], 'Chroma request lost quality23/89 behavior.' );
	}
}
echo "Imagick AVIF chroma: PASS — real YUV444 bitstream, default byte parity, quality/orientation/ICC/privacy/freshness.\n";
// Actual core/thumbnail save retains its own wand settings even with the preference enabled.
update_option( YoOhw_Media_Optimizer::OPTION_KEY, $settings );
$core = wp_get_image_editor( $dir . '/chroma-untagged.png' );
$assert( ! is_wp_error( $core ) && in_array( get_class( $core ), array( 'WP_Image_Editor_Imagick', 'YoOhw_Media_Optimizer_Thumbnail_Imagick' ), true ), 'Cannot select the standard thumbnail editor.' );
$property = new ReflectionProperty( 'WP_Image_Editor_Imagick', 'image' ); if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
$wand = $property->getValue( $core ); $before = $wand->getOption( 'heic:chroma' );
$assert( ! is_wp_error( $core->save( $dir . '/core.avif', 'image/avif' ) ) && $before === $wand->getOption( 'heic:chroma' ), 'Sidecar chroma changed the core thumbnail wand.' );
class YoOhw_MO_Chroma_Larger_Editor extends YoOhw_Media_Optimizer_Imagick {
	public function save( $path = null, $mime = null ) {
		$result = parent::save( $path, $mime );
		if ( ! is_wp_error( $result ) ) { $padding = str_repeat( 'x', 50000 ); file_put_contents( $path, pack( 'N', strlen( $padding ) + 8 ) . 'free' . $padding, FILE_APPEND ); }
		return $result;
	}
}
$filter( 'wp_image_editors', static function() { return array( 'YoOhw_MO_Chroma_Larger_Editor' ); } );
list( $id, $path ) = $attach( 'chroma-larger.png', $png() );
$backup = $invoke( 'backup_file', array( $path ) ); $assert( ! is_wp_error( $backup ), 'Cannot seed larger candidate recovery data.' ); $backups[] = $backup['path']; $backup_hash = hash_file( 'sha256', $backup['path'] );
file_put_contents( $path . '.avif', 'old avif' );
$result = $run( $id, array( 'generate_webp_sidecars' => 0, 'generate_avif_sidecars' => 1, 'avif_imagick_chroma' => '444', 'skip_larger_files' => 1 ) );
$assert( 'skipped_larger' === $result['files'][0]['avif_status'] && 1 === $result['summary']['avif_skipped_larger'] && 0 === $result['summary']['avif_updated'] && 0 === $result['summary']['avif_created'], 'Larger444 candidate counters/status are wrong.' );
$assert( $png() === file_get_contents( $path ) && 'old avif' === file_get_contents( $path . '.avif' ) && $backup_hash === hash_file( 'sha256', $backup['path'] ), 'Larger444 candidate changed source/recovery/old sidecar.' );
$clean_stages();
// First native lookup is isolated to this child: ordinary failure may fall back,
// whereas the exact-alpha profile retains its native-only failure contract.
$filter( 'wp_image_editors', static function() { return array( 'WP_Image_Editor_Imagick' ); } );
list( $id, $path ) = $attach( 'chroma-native-fallback.png', $png() );
$result = $run( $id, array( 'generate_webp_sidecars' => 0, 'generate_avif_sidecars' => 1, 'avif_imagick_chroma' => '444', 'use_external_binaries' => 1 ) );
$assert( is_file( $dir . '/avifenc.args' ) && 'created' === $result['files'][0]['avif_status'] && 'YoOhw_Media_Optimizer_Imagick' === $result['files'][0]['avif_engine'], 'Ordinary native failure did not fall back to the selected Imagick adapter: ' . wp_json_encode( $result ) . ' native args=' . (int) is_file( $dir . '/avifenc.args' ) );
$assert( array( 0 ) === array_values( array_unique( $chroma( $path . '.avif' ) ) ) && $png() === file_get_contents( $path ), 'Native failure fallback lost444/source bytes.' );
$failure_case( 'chroma-native-alpha.png', $png(), array( 'generate_webp_sidecars' => 0, 'generate_avif_sidecars' => 1, 'avif_imagick_chroma' => '444', 'avif_alpha_mode' => 'lossless', 'use_external_binaries' => 1 ) );
