<?php
/**
 * Offline sidecar-save probe against a disposable/local WordPress installation.
 * Never calls attachment optimization, updates options, or writes into uploads.
 *
 * @package YoOhw_Media_Optimizer
 */

if ( 'cli' !== PHP_SAPI || 4 !== $argc ) {
	fwrite( STDERR, "Usage: php plugin-baseline.php /path/to/wordpress manifest.json /new/output-directory\n" );
	exit( 1 );
}

try {
	$wp_root  = realpath( $argv[1] );
	$manifest = realpath( $argv[2] );
	$parent   = realpath( dirname( $argv[3] ) );
	$name     = basename( $argv[3] );
	if ( ! $wp_root || ! is_file( $wp_root . '/wp-load.php' ) || ! $manifest || ! $parent || in_array( $name, array( '.', '..', '' ), true ) ) {
		throw new RuntimeException( 'Invalid WordPress, manifest, or output path.' );
	}
	$output = $parent . DIRECTORY_SEPARATOR . $name;
	$root   = dirname( $manifest );
	foreach ( array( $root, $wp_root ) as $protected ) {
		if ( $output === $protected || 0 === strpos( $output, $protected . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'Output must be outside the corpus and WordPress trees.' );
		}
	}
	if ( file_exists( $output ) || is_link( $output ) ) {
		throw new RuntimeException( 'Output must not already exist.' );
	}
	$data = json_decode( file_get_contents( $manifest ), true );
	if ( ! is_array( $data ) || empty( $data['images'] ) ) {
		throw new RuntimeException( 'Expected a manifest with images.' );
	}
	$inputs = array();
	foreach ( $data['images'] as $entry ) {
		$id     = isset( $entry['id'] ) ? $entry['id'] : '';
		$source = isset( $entry['path'] ) ? realpath( $root . '/' . $entry['path'] ) : false;
		if ( ! preg_match( '/\A[a-zA-Z0-9_-]+\z/', $id ) || isset( $inputs[ $id ] ) || ! $source || ! is_file( $source ) || 0 !== strpos( $source, $root . DIRECTORY_SEPARATOR ) || empty( $entry['sha256'] ) || ! hash_equals( $entry['sha256'], hash_file( 'sha256', $source ) ) ) {
			throw new RuntimeException( 'Invalid, duplicate, escaped, or changed manifest input: ' . $id );
		}
		$inputs[ $id ] = array( $entry, $source );
	}
	define( 'DISABLE_WP_CRON', true );
	require $wp_root . '/wp-load.php';
	if ( ! class_exists( 'YoOhw_Media_Optimizer' ) ) {
		throw new RuntimeException( 'Activate the plugin in the local/disposable installation.' );
	}
	$reflection = new ReflectionClass( 'YoOhw_Media_Optimizer' );
	$expected   = dirname( __DIR__, 2 ) . '/includes/class-yoohw-media-optimizer.php';
	if ( hash_file( 'sha256', $reflection->getFileName() ) !== hash_file( 'sha256', $expected ) ) {
		throw new RuntimeException( 'Loaded plugin differs from the benchmark checkout.' );
	}
	// Request-local options only. No update_option() or attachment mutation.
	add_filter( 'pre_option_' . YoOhw_Media_Optimizer::OPTION_KEY, static function() {
		return array( 'use_external_binaries' => 1, 'quality' => 82 );
	} );
	if ( ! mkdir( $output, 0700 ) ) {
		throw new RuntimeException( 'Could not exclusively create output.' );
	}
	$optimizer = YoOhw_Media_Optimizer::instance();
	$rows      = array();
	foreach ( $inputs as $id => $input ) {
		list( $entry, $source ) = $input;
		foreach ( array( 'webp', 'avif' ) as $codec ) {
			$destination = $output . '/' . $id . '.' . $codec;
			$method      = $reflection->getMethod( 'save_' . $codec . '_file' );
			$method->setAccessible( true );
			$start = microtime( true );
			$saved = $method->invoke( $optimizer, $source, $destination, 82 );
			$row   = array( 'id' => $id, 'codec' => $codec, 'seconds' => microtime( true ) - $start );
			if ( is_wp_error( $saved ) ) {
				$row['error'] = $saved->get_error_code() . ': ' . $saved->get_error_message();
			} else {
				$row['engine'] = isset( $saved['engine'] ) ? $saved['engine'] : 'unknown';
				$row['path']   = basename( $saved['path'] );
				$row['bytes']  = filesize( $saved['path'] );
				$row['sha256'] = hash_file( 'sha256', $saved['path'] );
			}
			$rows[] = $row;
			if ( ! hash_equals( $entry['sha256'], hash_file( 'sha256', $source ) ) ) {
				throw new RuntimeException( 'Source changed: ' . $id );
			}
		}
	}
	$result = array( 'php' => PHP_VERSION, 'plugin_sha256' => hash_file( 'sha256', $expected ), 'source_hashes_unchanged' => true, 'images' => $rows );
	file_put_contents( $output . '/plugin-baseline.json', json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	fwrite( STDOUT, 'Recorded ' . count( $rows ) . " real plugin sidecar-save results.\n" );
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 1 );
}
