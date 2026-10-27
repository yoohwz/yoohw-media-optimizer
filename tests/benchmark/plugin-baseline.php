<?php
/**
 * Offline sidecar-save probe against a disposable/local WordPress installation.
 * Never calls attachment optimization, updates options, or writes into uploads.
 *
 * @package YoOhw_Media_Optimizer
 */

if ( 'cli' !== PHP_SAPI || $argc < 4 ) {
	fwrite( STDERR, "Usage: php plugin-baseline.php /path/to/wordpress manifest.json /new/output-directory [--engine=native|imagick|gd] [--webp-quality=1..100] [--avif-quality=1..100] [--png-webp-mode=lossy|lossless] [--avif-alpha-mode=default|lossless]\n" );
	exit( 1 );
}

/** Hash the complete runtime PHP tree, including lazily loaded adapters. */
function yoohw_benchmark_runtime_hashes( $directory ) {
	$hashes = array();
	$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		$relative = substr( $file->getPathname(), strlen( $directory ) + 1 );
		if ( 0 === strpos( $relative, 'tests/' ) || 0 === strpos( $relative, '.git/' ) ) { continue; }
		if ( 'php' !== $file->getExtension() ) { continue; }
		if ( $file->isLink() || ! $file->isFile() ) { throw new RuntimeException( 'Runtime PHP must be a regular file: ' . $relative ); }
		$hashes[ $relative ] = hash_file( 'sha256', $file->getPathname() );
	}
	ksort( $hashes );
	return $hashes;
}

try {
	$started = microtime( true );
	$engine  = 'native';
	$options = array( 'use_external_binaries' => 1, 'quality' => 82, 'webp_quality' => 82, 'avif_quality' => 82, 'webp_png_mode' => 'lossy', 'avif_alpha_mode' => 'default' );
	$seen    = array();
	foreach ( array_slice( $argv, 4 ) as $argument ) {
		if ( ! preg_match( '/\A--(engine|webp-quality|avif-quality|png-webp-mode|avif-alpha-mode)=([^=]+)\z/', $argument, $match ) || isset( $seen[ $match[1] ] ) ) {
			throw new RuntimeException( 'Unknown, duplicate, or malformed option: ' . $argument );
		}
		list( , $key, $value ) = $match;
		$seen[ $key ] = true;
		if ( in_array( $key, array( 'webp-quality', 'avif-quality' ), true ) ) {
			if ( ! preg_match( '/\A(?:[1-9][0-9]?|100)\z/', $value ) ) { throw new RuntimeException( 'Quality must be an integer from 1 to 100.' ); }
			$options[ str_replace( '-', '_', $key ) ] = (int) $value;
		} elseif ( 'engine' === $key ) {
			if ( ! in_array( $value, array( 'native', 'imagick', 'gd' ), true ) ) { throw new RuntimeException( 'Unknown engine preference.' ); }
			$engine = $value;
		} else {
			$allowed = 'png-webp-mode' === $key ? array( 'lossy', 'lossless' ) : array( 'default', 'lossless' );
			if ( ! in_array( $value, $allowed, true ) ) { throw new RuntimeException( 'Unknown PNG mode.' ); }
			$options[ 'png-webp-mode' === $key ? 'webp_png_mode' : 'avif_alpha_mode' ] = $value;
		}
	}
	if ( 'native' !== $engine ) {
		if ( 'lossless' === $options['webp_png_mode'] || 'lossless' === $options['avif_alpha_mode'] ) { throw new RuntimeException( 'Advanced PNG modes require --engine=native.' ); }
		$options['use_external_binaries'] = 0;
	}
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
	$manifest_hash = hash_file( 'sha256', $manifest );
	if ( ! is_array( $data ) || empty( $data['images'] ) || ! is_array( $data['images'] ) ) {
		throw new RuntimeException( 'Expected a manifest with images.' );
	}
	$inputs = array();
	foreach ( $data['images'] as $entry ) {
		$id     = isset( $entry['id'] ) && is_string( $entry['id'] ) ? $entry['id'] : '';
		$source = isset( $entry['path'] ) && is_string( $entry['path'] ) ? realpath( $root . '/' . $entry['path'] ) : false;
		if ( ! preg_match( '/\A[a-zA-Z0-9_-]+\z/', $id ) || isset( $inputs[ $id ] ) || ! $source || ! is_file( $source ) || 0 !== strpos( $source, $root . DIRECTORY_SEPARATOR ) || empty( $entry['sha256'] ) || ! is_string( $entry['sha256'] ) || ! hash_equals( $entry['sha256'], hash_file( 'sha256', $source ) ) ) {
			throw new RuntimeException( 'Invalid, duplicate, escaped, or changed manifest input: ' . $id );
		}
		$inputs[ $id ] = array( $entry, $source );
	}
	define( 'DISABLE_WP_CRON', true );
	require $wp_root . '/wp-load.php';
	if ( ! class_exists( 'YoOhw_Media_Optimizer' ) ) {
		throw new RuntimeException( 'Activate the plugin in the local/disposable installation.' );
	}
	$reflection     = new ReflectionClass( 'YoOhw_Media_Optimizer' );
	$expected_root  = dirname( __DIR__, 2 );
	$loaded_root    = dirname( $reflection->getFileName(), 2 );
	$runtime_hashes = yoohw_benchmark_runtime_hashes( $expected_root );
	if ( yoohw_benchmark_runtime_hashes( $loaded_root ) !== $runtime_hashes ) {
		throw new RuntimeException( 'Loaded plugin differs from the benchmark checkout.' );
	}
	// Request-local options only. No update_option() or attachment mutation.
	add_filter( 'pre_option_' . YoOhw_Media_Optimizer::OPTION_KEY, static function() use ( $options ) {
		return $options;
	} );
	// This is an editor preference, not a forced engine: plain WebP can use direct GD.
	if ( 'native' !== $engine ) {
		add_filter( 'wp_image_editors', static function() use ( $engine ) {
			return array( 'imagick' === $engine ? 'WP_Image_Editor_Imagick' : 'WP_Image_Editor_GD' );
		}, PHP_INT_MAX );
	}
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
			$actual_options = $optimizer->get_options();
			$saved = $method->invoke( $optimizer, $source, $destination, $actual_options[ $codec . '_quality' ] );
			$row   = array( 'id' => $id, 'codec' => $codec, 'source_sha256' => $entry['sha256'], 'seconds' => microtime( true ) - $start, 'engine_request' => $engine, 'options' => $actual_options, 'quality' => $actual_options[ $codec . '_quality' ], 'engine' => null );
			if ( is_wp_error( $saved ) ) {
				$row['error'] = $saved->get_error_code() . ': ' . $saved->get_error_message();
			} else {
				if ( ! isset( $saved['path'] ) || $saved['path'] !== $destination || ! is_file( $destination ) || is_link( $destination ) ) { throw new RuntimeException( 'Unexpected plugin output: ' . $id ); }
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
	foreach ( $inputs as $id => $input ) {
		if ( ! hash_equals( $input[0]['sha256'], hash_file( 'sha256', $input[1] ) ) ) { throw new RuntimeException( 'Source changed: ' . $id ); }
	}
	if ( ! hash_equals( $manifest_hash, hash_file( 'sha256', $manifest ) ) ) { throw new RuntimeException( 'Manifest changed during probe.' ); }
	if ( yoohw_benchmark_runtime_hashes( $loaded_root ) !== $runtime_hashes ) { throw new RuntimeException( 'Runtime PHP changed during probe.' ); }
	$loaded_hashes = array();
	foreach ( get_included_files() as $loaded ) {
		if ( 0 === strpos( $loaded, $loaded_root . DIRECTORY_SEPARATOR ) || 0 === strpos( $loaded, $wp_root . '/wp-includes/' ) || $loaded === $wp_root . '/wp-load.php' ) { $loaded_hashes[ $loaded ] = hash_file( 'sha256', $loaded ); }
	}
	ksort( $loaded_hashes );
	$result = array(
		'php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'],
		'imagick' => extension_loaded( 'imagick' ) ? array( 'extension' => phpversion( 'imagick' ), 'library' => Imagick::getVersion(), 'formats' => Imagick::queryFormats() ) : null,
		'gd' => function_exists( 'gd_info' ) ? gd_info() : null,
		'engine_request' => $engine, 'engine_request_policy' => 'native retains production routing; imagick/gd disable external binaries and prefer that WordPress editor; direct GD WebP remains possible. Actual engines are recorded per result.',
		'options' => $optimizer->get_options(), 'seconds' => microtime( true ) - $started,
		'manifest_sha256' => $manifest_hash, 'plugin_sha256' => $runtime_hashes['includes/class-yoohw-media-optimizer.php'],
		'runtime_php_sha256' => $runtime_hashes, 'loaded_file_sha256' => $loaded_hashes,
		'source_hashes_unchanged' => true, 'images' => $rows,
	);
	$json = json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	if ( strlen( $json ) !== file_put_contents( $output . '/plugin-baseline.json', $json, LOCK_EX ) ) { throw new RuntimeException( 'Could not write probe results.' ); }
	fwrite( STDOUT, 'Recorded ' . count( $rows ) . " real plugin sidecar-save results.\n" );
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 1 );
}
