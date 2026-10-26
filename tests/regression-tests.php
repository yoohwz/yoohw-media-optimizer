<?php
/**
 * Destructive-path regression checks using temporary media only.
 *
 * Run with:
 * wp eval-file wp-content/plugins/yoohw-media-optimizer/tests/regression-tests.php
 *
 * @package YoOhw_Media_Optimizer
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run this file through WP-CLI.\n" );
}

$optimizer = YoOhw_Media_Optimizer::instance();
$reflection = new ReflectionClass( $optimizer );
$original_options = get_option( YoOhw_Media_Optimizer::OPTION_KEY, array() );
$uploads = wp_get_upload_dir();
$test_dir = trailingslashit( $uploads['basedir'] ) . 'yoohw-mo-regression-' . wp_generate_password( 8, false, false );
$test_url = trailingslashit( $uploads['baseurl'] ) . wp_basename( $test_dir );
$created_paths = array();
$private_backup = '';
$assertions = 0;
$failure = '';
$queue_lock = '';
$attachment_id = 0;
$attachment_backup = '';
$gd_editors = static function() {
	return array( 'WP_Image_Editor_GD' );
};

$invoke = static function( $method, array $arguments = array() ) use ( $optimizer, $reflection ) {
	$ref = $reflection->getMethod( $method );
	$ref->setAccessible( true );

	return $ref->invokeArgs( $optimizer, $arguments );
};

$assert = static function( $condition, $message ) use ( &$assertions ) {
	++$assertions;

	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

try {
	wp_mkdir_p( $test_dir );

	$options = wp_parse_args(
		array(
			'generate_webp_sidecars' => 1,
			'generate_avif_sidecars' => 1,
			'delivery_mode'          => YoOhw_Media_Optimizer::DELIVERY_HTML,
			'optimize_originals'     => 0,
		),
		$original_options
	);
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options );

	$png = $test_dir . '/picture.png';
	file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl8sAAAAASUVORK5CYII=', true ) );
	file_put_contents( $png . '.webp', 'webp-test' );
	file_put_contents( $png . '.avif', 'avif-test' );
	touch( $png . '.webp', time() + 2 );
	touch( $png . '.avif', time() + 2 );
	$created_paths = array_merge( $created_paths, array( $png, $png . '.webp', $png . '.avif' ) );

	$image_url = $test_url . '/picture.png';
	$picture = $invoke( 'build_picture_html', array( '<img src="' . esc_url( $image_url ) . '" alt="Test">' ) );
	$assert( false !== strpos( $picture, '<picture data-yoohw-mo-picture="1">' ), 'Picture wrapper was not generated.' );
	$assert( false !== strpos( $picture, 'type="image/avif"' ), 'AVIF source is missing.' );
	$assert( false !== strpos( $picture, 'type="image/webp"' ), 'WebP source is missing.' );
	$assert( false !== strpos( $picture, 'src="' . esc_url( $image_url ) . '"' ), 'Original image fallback is missing.' );

	$unsafe = dirname( $uploads['basedir'] ) . '/outside.webp';
	file_put_contents( $unsafe, 'outside-test' );
	$created_paths[] = $unsafe;
	$assert( true === $invoke( 'is_safe_sidecar_path', array( $png . '.webp' ) ), 'An upload sidecar was rejected as unsafe.' );
	$assert( false === $invoke( 'is_safe_sidecar_path', array( $unsafe ) ), 'A sidecar outside uploads was accepted.' );

	$acquired_lock = $invoke( 'acquire_queue_lock' );
	$assert( is_string( $acquired_lock ) && '' !== $acquired_lock, 'The first queue worker could not acquire its lock.' );
	$queue_lock = $acquired_lock;
	$assert( is_wp_error( $invoke( 'acquire_queue_lock' ) ), 'A second queue worker acquired the same lock.' );
	$invoke( 'release_queue_lock', array( $queue_lock ) );
	$queue_lock = '';

	if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' ) ) {
		$source = $test_dir . '/original.png';
		$image = imagecreatetruecolor( 256, 256 );
		$color = imagecolorallocate( $image, 120, 30, 180 );
		imagefilledrectangle( $image, 0, 0, 255, 255, $color );
		imagepng( $image, $source, 0 );
		imagedestroy( $image );
		$created_paths[] = $source;

		$runtime_options = wp_parse_args(
			array(
				'backup_originals'      => 1,
				'use_external_binaries' => 0,
				'compression_mode'       => 'balanced',
				'metadata_policy'        => 'remove',
				'jpeg_quality'           => 82,
				'max_width'              => 0,
				'max_height'             => 0,
			),
			$options
		);
		$first = $invoke( 'optimize_original_file', array( $source, $runtime_options, false, array() ) );
		$previous = array(
			'original_status'              => $first['status'],
			'original_options_fingerprint' => $first['options_fingerprint'],
			'optimized_hash'               => $first['optimized_hash'],
			'engine'                       => $first['engine'],
		);
		$second = $invoke( 'optimize_original_file', array( $source, $runtime_options, false, $previous ) );
		$assert( 'existing' === $second['status'], 'A second identical original optimization was not skipped.' );

		$private_backup = $invoke( 'backup_path_for', array( $source ) );
		$assert( 0 !== strpos( wp_normalize_path( $private_backup ), wp_normalize_path( trailingslashit( ABSPATH ) ) ), 'Private backup is still inside the WordPress document root.' );

		// Exercise persisted tracking across real attachment operations, not just helper calls.
		add_filter( 'wp_image_editors', $gd_editors );
		$lifecycle_source = $test_dir . '/tracking.png';
		$image = imagecreatetruecolor( 256, 256 );
		imagepng( $image, $lifecycle_source, 0 );
		imagedestroy( $image );
		$created_paths = array_merge( $created_paths, array( $lifecycle_source, $lifecycle_source . '.webp', $lifecycle_source . '.avif' ) );
		$attachment_backup = $invoke( 'backup_path_for', array( $lifecycle_source ) );
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Optimizer tracking regression',
				'post_mime_type' => 'image/png',
			),
			$lifecycle_source
		);
		$assert( $attachment_id > 0, 'Could not create the tracking test attachment.' );
		update_post_meta(
			$attachment_id,
			'_wp_attachment_metadata',
			array(
				'file'   => $invoke( 'relative_upload_path', array( $lifecycle_source ) ),
				'width'  => 256,
				'height' => 256,
				'sizes'  => array(),
			)
		);
		$lifecycle_options = array_merge(
			$runtime_options,
			array(
				'optimize_originals'     => 1,
				'generate_webp_sidecars' => 0,
				'generate_avif_sidecars' => 0,
			)
		);
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$original_hash = hash_file( 'sha256', $lifecycle_source );
		$optimized = $optimizer->optimize_attachment( $attachment_id );
		$assert( 1 === $optimized['summary']['original_optimized'], 'The tracking fixture was not optimized by GD.' );
		$optimized_hash = hash_file( 'sha256', $lifecycle_source );
		$assert( $original_hash === hash_file( 'sha256', $attachment_backup ), 'The tracking fixture backup does not contain the original.' );
		$original_tracking = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$original_savings = $optimizer->attachment_savings( $attachment_id );

		$lifecycle_options['optimize_originals'] = 0;
		$lifecycle_options['generate_webp_sidecars'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$sidecar_only = $optimizer->optimize_attachment( $attachment_id );
		$tracking = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		foreach ( array( 'original_status', 'original_input_hash', 'optimized_hash', 'original_options_fingerprint', 'backup_path', 'original_size', 'saved_bytes', 'saved_percent', 'engine' ) as $key ) {
			$assert( $original_tracking['files'][0][ $key ] === ( $tracking['files'][0][ $key ] ?? null ), 'Sidecar-only processing lost original tracking: ' . $key );
		}
		$assert( 0 === $sidecar_only['summary']['original_optimized'], 'Sidecar-only processing reported a new original optimization.' );
		$assert( $optimized_hash === hash_file( 'sha256', $lifecycle_source ), 'Sidecar-only processing changed the original file.' );
		$assert( $original_savings['original_saved_bytes'] === $optimizer->attachment_savings( $attachment_id )['original_saved_bytes'], 'Sidecar-only processing lost original savings.' );

		$lifecycle_options['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$resumed = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'existing' === $resumed['files'][0]['original_status'], 'Re-enabling originals did not skip unchanged output.' );
		$assert( $optimized_hash === hash_file( 'sha256', $lifecycle_source ), 'Re-enabling originals recompressed unchanged output.' );
		$assert( $original_hash === hash_file( 'sha256', $attachment_backup ), 'Re-enabling originals replaced the recoverable backup.' );
	}

} catch ( Throwable $error ) {
	$failure = $error->getMessage();
} finally {
	remove_filter( 'wp_image_editors', $gd_editors );
	if ( $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}
	if ( $attachment_backup && file_exists( $attachment_backup ) ) {
		wp_delete_file( $attachment_backup );
	}
	if ( $queue_lock ) {
		$invoke( 'release_queue_lock', array( $queue_lock ) );
	}

	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $original_options );
	delete_transient( YoOhw_Media_Optimizer::SAVINGS_TRANSIENT );

	foreach ( array_unique( array_filter( $created_paths ) ) as $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	if ( $private_backup && file_exists( $private_backup ) ) {
		wp_delete_file( $private_backup );
	}

	if ( is_dir( $test_dir ) ) {
		@rmdir( $test_dir );
	}
}

if ( $failure ) {
	WP_CLI::error( $failure );
}

WP_CLI::success( sprintf( 'YoOhw regression checks passed: %d assertion(s).', $assertions ) );
