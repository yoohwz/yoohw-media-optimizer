<?php
/**
 * Isolated filesystem fault probe. No WordPress database or production hooks.
 * Invoked in child PHP processes by backup-restore-tests.php.
 *
 * @package YoOhw_Media_Optimizer
 */

if ( 'cli' !== PHP_SAPI || ! isset( $argv[2] ) ) {
	exit( "Run this fixture through the regression entry point.\n" );
}

$probe_root = $argv[1];
$probe_mode = $argv[2];
$probe_id = $argv[3] ?? 'one';
define( 'ABSPATH', $probe_root . '/public/' );

function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function untrailingslashit( $path ) { return rtrim( $path, '/\\' ); }
function wp_basename( $path ) { return basename( $path ); }
function wp_get_upload_dir() { return array( 'basedir' => ABSPATH . 'uploads' ); }
function get_current_blog_id() { return 1; }
function apply_filters( $name, $value ) { global $probe_root; return 'yoohw_media_optimizer_backup_dir' === $name ? $probe_root . '/private' : $value; }
function wp_mkdir_p( $path ) { return is_dir( $path ) || @mkdir( $path, 0755, true ); }
function wp_delete_file( $path ) { if ( file_exists( $path ) ) { @unlink( $path ); } }
function __( $text, $domain = '' ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error { public $code; public function __construct( $code, $message ) { $this->code = $code; } }
class WP_Filesystem_Base {}
class WP_Filesystem_Direct extends WP_Filesystem_Base {
	public function __construct( $args ) {}
	public function chmod( $path, $mode ) {
		global $probe_mode;
		return 'chmod-fail' !== $probe_mode && chmod( $path, $mode );
	}
	public function copy( $source, $destination, $overwrite = false ) {
		global $probe_mode, $probe_root, $probe_id;
		$fault = str_replace( 'legacy-', '', $probe_mode );
		if ( 'partial' === $fault ) {
			file_put_contents( $destination, 'partial' );
			return false;
		}
		if ( 'corrupt' === $fault ) {
			file_put_contents( $destination, str_repeat( 'x', filesize( $source ) ) );
			return true;
		}
		if ( 'interrupt' === $probe_mode ) {
			file_put_contents( $destination, 'partial' );
			file_put_contents( $probe_root . '/ready-' . $probe_id, 'ready' );
			sleep( 30 );
			return false;
		}
		$copied = copy( $source, $destination );
		if ( 'race' === $probe_mode ) {
			file_put_contents( $probe_root . '/ready-' . $probe_id, 'ready' );
			$deadline = microtime( true ) + 10;
			while ( ! file_exists( $probe_root . '/publish' ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
		}
		if ( in_array( $probe_mode, array( 'rename-fail', 'link-fail' ), true ) ) {
			chmod( dirname( $destination ), 0500 );
		}
		return $copied;
	}
}

require dirname( __DIR__ ) . '/includes/class-yoohw-media-optimizer.php';
$reflection = new ReflectionClass( 'YoOhw_Media_Optimizer' );
$optimizer = $reflection->newInstanceWithoutConstructor();
$source = ABSPATH . 'uploads/source.png';
$destination = ABSPATH . 'uploads/current.png';
$backup_operation = in_array( $probe_mode, array( 'race', 'link-fail', 'legacy-partial', 'legacy-corrupt' ), true );
$method = $reflection->getMethod( $backup_operation ? 'backup_file' : 'replace_original_file' );
if ( PHP_VERSION_ID < 80100 ) {
	$method->setAccessible( true );
}
$result = $backup_operation ? $method->invoke( $optimizer, $source ) : $method->invoke( $optimizer, $source, $destination );
if ( 'rename-fail' === $probe_mode ) {
	chmod( dirname( $destination ), 0755 );
}
if ( 'link-fail' === $probe_mode ) {
	chmod( $probe_root . '/private/site-1', 0755 );
}
echo json_encode( array( 'success' => is_array( $result ) || true === $result, 'created' => is_array( $result ) && $result['created'], 'error' => is_wp_error( $result ) ? $result->code : '' ) );
