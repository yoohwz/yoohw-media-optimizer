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
$jpeg_attachment_id = 0;
$jpeg_backup = '';
$gd_editors = static function() {
	return array( 'WP_Image_Editor_GD' );
};

$invoke = static function( $method, array $arguments = array() ) use ( $optimizer, $reflection ) {
	$ref = $reflection->getMethod( $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$ref->setAccessible( true );
	}

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

	$delivery_checks = require __DIR__ . '/delivery-tests.php';
	$delivery_checks( $optimizer, $invoke, $assert );

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
		$color = imagecolorallocate( $image, 120, 30, 180 );
		imagefilledrectangle( $image, 32, 32, 223, 223, $color );
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
		$assert( 0 === $sidecar_only['summary']['original_skipped'] && 0 === $sidecar_only['summary']['original_failed'], 'Sidecar-only processing reported original operations.' );
		$assert( ! isset( $sidecar_only['metadata'] ), 'Historical original success triggered attachment metadata refresh.' );
		$assert( 'disabled' !== $tracking['files'][0]['status'], 'Sidecar-only processing did not update its sidecar outcome.' );
		$assert( $optimized_hash === hash_file( 'sha256', $lifecycle_source ), 'Sidecar-only processing changed the original file.' );
		$assert( $original_savings['original_saved_bytes'] === $optimizer->attachment_savings( $attachment_id )['original_saved_bytes'], 'Sidecar-only processing lost original savings.' );

		$lifecycle_options['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$resumed = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'existing' === $resumed['files'][0]['original_status'], 'Re-enabling originals did not skip unchanged output.' );
		$assert( $optimized_hash === hash_file( 'sha256', $lifecycle_source ), 'Re-enabling originals recompressed unchanged output.' );
		$assert( $original_hash === hash_file( 'sha256', $attachment_backup ), 'Re-enabling originals replaced the recoverable backup.' );

		// Changing settings while disabled must not make old output match new settings.
		$lifecycle_options['optimize_originals'] = 0;
		$lifecycle_options['max_width'] = 128;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$optimizer->optimize_attachment( $attachment_id );
		$tracking = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( $original_tracking['files'][0]['original_options_fingerprint'] === $tracking['files'][0]['original_options_fingerprint'], 'Disabled processing relabeled the original options fingerprint.' );
		$lifecycle_options['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$resized = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'optimized' === $resized['files'][0]['original_status'], 'Re-enabled originals ignored changed resize settings.' );
		$assert( 128 === $invoke( 'image_dimensions', array( $lifecycle_source ) )['width'], 'Changed resize settings were not applied.' );

		// Replace the source with another valid PNG while retaining its size and mtime.
		$tracking = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$old_size = filesize( $lifecycle_source );
		$old_mtime = filemtime( $lifecycle_source );
		$image = imagecreatetruecolor( 128, 128 );
		ob_start();
		imagepng( $image, null, 9 );
		$replacement = ob_get_clean();
		imagedestroy( $image );
		$assert( strlen( $replacement ) <= $old_size, 'The replacement fixture cannot retain the previous size.' );
		file_put_contents( $lifecycle_source, str_pad( $replacement, $old_size, "\0" ) );
		touch( $lifecycle_source, $old_mtime );
		clearstatcache( true, $lifecycle_source );
		$assert( $old_size === filesize( $lifecycle_source ) && $old_mtime === filemtime( $lifecycle_source ), 'The replacement fixture changed size or mtime.' );
		$lifecycle_options['optimize_originals'] = 0;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$optimizer->optimize_attachment( $attachment_id );
		$changed_tracking = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( $tracking['files'][0]['optimized_hash'] === $changed_tracking['files'][0]['optimized_hash'], 'Disabled processing fabricated an identity for changed source content.' );
		$assert( 0 === $optimizer->attachment_savings( $attachment_id )['original_saved_bytes'], 'Changed source retained stale original savings.' );
		$assert( $old_size === $optimizer->attachment_savings( $attachment_id )['current_source_bytes'], 'Changed source size was not reported.' );
		$lifecycle_options['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$changed = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'existing' !== $changed['files'][0]['original_status'] && 0 === $changed['summary']['original_failed'], 'Changed source was skipped or failed.' );
		$lifecycle_options['jpeg_quality'] = 90;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$forced = $optimizer->optimize_attachment( $attachment_id, true );
		$assert( 'existing' !== $forced['files'][0]['original_status'] && 0 === $forced['summary']['original_failed'], 'Explicit force no longer processes the original.' );
		$assert( $original_hash === hash_file( 'sha256', $attachment_backup ), 'Changed settings/source or force replaced the original backup.' );

		// Legacy evidence must use the old original options, never the sidecar run's options.
		$legacy = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		unset( $legacy['files'][0]['original_input_hash'], $legacy['files'][0]['optimized_hash'], $legacy['files'][0]['original_options_fingerprint'] );
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, $legacy );
		$lifecycle_options['optimize_originals'] = 0;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$optimizer->optimize_attachment( $attachment_id );
		$lifecycle_options['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$legacy_unchanged = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'existing' === $legacy_unchanged['files'][0]['original_status'], 'Verified legacy tracking did not skip unchanged output after a sidecar pass.' );
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, $legacy );
		$lifecycle_options['optimize_originals'] = 0;
		$lifecycle_options['max_width'] = 64;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$optimizer->optimize_attachment( $attachment_id );
		$legacy_preserved = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( hash_file( 'sha256', $lifecycle_source ) === $legacy_preserved['files'][0]['optimized_hash'], 'Verified legacy output identity was not preserved.' );
		$legacy_options = array_merge( $legacy['options'], array( 'jpeg_quality' => $lifecycle_options['jpeg_quality'] ) );
		$assert( $invoke( 'original_options_fingerprint', array( $legacy_options ) ) === $legacy_preserved['files'][0]['original_options_fingerprint'], 'Legacy identity used unexecuted sidecar settings.' );
		$assert( ! isset( $legacy_preserved['files'][0]['original_input_hash'] ), 'Legacy preservation invented an original input hash.' );
		$lifecycle_options['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$legacy_changed = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'optimized' === $legacy_changed['files'][0]['original_status'], 'Legacy tracking masked changed settings.' );

		// A new size has no original history, even when another source is tracked.
		$new_source = $test_dir . '/tracking-new.png';
		copy( $lifecycle_source, $new_source );
		$created_paths = array_merge( $created_paths, array( $new_source, $new_source . '.webp', $new_source . '.avif' ) );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata['sizes']['new'] = array( 'file' => wp_basename( $new_source ), 'width' => 64, 'height' => 64 );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
		$lifecycle_options['optimize_originals'] = 0;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$optimizer->optimize_attachment( $attachment_id );
		$with_new = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( 2 === count( $with_new['files'] ), 'The new image size was not processed.' );
		$assert( '' === $with_new['files'][1]['backup_path'] && ! isset( $with_new['files'][1]['optimized_hash'] ), 'A new source borrowed original history.' );
		$assert( 1 === $optimizer->attachment_status( $attachment_id )['backed_up'], 'The original backup disappeared or leaked into the new source.' );
		unset( $metadata['sizes']['new'] );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );

		// Missing sources retain recovery identity, but cannot report stale success or savings.
		$before_missing = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$source_bytes = file_get_contents( $lifecycle_source );
		unlink( $lifecycle_source );
		$missing = $optimizer->optimize_attachment( $attachment_id );
		$missing_tracking = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( 'failed' === $missing['files'][0]['status'], 'Missing source was not reported as failed.' );
		$assert( $before_missing['files'][0]['backup_path'] === $missing_tracking['files'][0]['backup_path'] && $before_missing['files'][0]['optimized_hash'] === $missing_tracking['files'][0]['optimized_hash'], 'Missing source lost recovery identity.' );
		$assert( 0 === $optimizer->attachment_savings( $attachment_id )['original_saved_bytes'] && 0 === $optimizer->attachment_status( $attachment_id )['original_optimized'], 'Missing source reported stale original savings or success.' );
		file_put_contents( $lifecycle_source, $source_bytes );

		$restored = $optimizer->restore_attachment( $attachment_id );
		$assert( 1 === $restored['restored'] && 0 === $restored['failed'], 'The retained backup could not restore the source.' );
		$optimizer->optimize_attachment( $attachment_id );
		$restored_tracking = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( 'restored' === $restored_tracking['files'][0]['original_status'], 'Sidecar processing lost the restored state.' );
		$assert( $original_hash === hash_file( 'sha256', $lifecycle_source ) && $original_hash === hash_file( 'sha256', $attachment_backup ), 'Sidecar processing changed the restored source or backup.' );

		// A sidecar failure must not erase the independently successful original history.
		$lifecycle_options['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$optimizer->optimize_attachment( $attachment_id );
		$before_failure = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$lifecycle_options['optimize_originals'] = 0;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$unavailable_editors = static function() {
			return array();
		};
		add_filter( 'wp_image_editors', $unavailable_editors, 20 );
		try {
			$sidecar_failure = $optimizer->optimize_attachment( $attachment_id, true );
		} finally {
			remove_filter( 'wp_image_editors', $unavailable_editors, 20 );
		}
		$after_failure = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( 'failed' === $sidecar_failure['files'][0]['status'], 'The unavailable-editor fixture did not fail sidecar generation.' );
		$assert( $before_failure['files'][0]['original_status'] === $after_failure['files'][0]['original_status'] && $before_failure['files'][0]['optimized_hash'] === $after_failure['files'][0]['optimized_hash'], 'Sidecar failure erased original history.' );

		// Failed originals remain failed history; a sidecar pass cannot turn them into success.
		$failed_original = $after_failure;
		$failed_original['files'][0]['original_status'] = 'failed';
		$failed_original['files'][0]['original_message'] = 'Regression original failure';
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, $failed_original );
		$optimizer->optimize_attachment( $attachment_id );
		$failed_preserved = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( 'failed' === $failed_preserved['files'][0]['original_status'] && 'Regression original failure' === $failed_preserved['files'][0]['original_message'], 'Sidecar processing replaced failed original history with success.' );

		// Incomplete modern identity cannot fall back to legacy matching.
		$partial_identity = $before_failure;
		unset( $partial_identity['files'][0]['optimized_hash'] );
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, $partial_identity );
		$lifecycle_options['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$partial_result = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'existing' !== $partial_result['files'][0]['original_status'], 'Incomplete modern identity incorrectly used legacy matching.' );
		$input_only = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		unset( $input_only['files'][0]['optimized_hash'], $input_only['files'][0]['original_options_fingerprint'] );
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, $input_only );
		$input_only_result = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'existing' !== $input_only_result['files'][0]['original_status'], 'Input-only modern identity incorrectly used legacy matching.' );

		// Legacy custom quality is unverifiable; disabled processing must not invent identity.
		$custom_legacy = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$custom_legacy['options']['compression_mode'] = 'custom';
		unset( $custom_legacy['files'][0]['original_input_hash'], $custom_legacy['files'][0]['optimized_hash'], $custom_legacy['files'][0]['original_options_fingerprint'] );
		update_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, $custom_legacy );
		$lifecycle_options['optimize_originals'] = 0;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$optimizer->optimize_attachment( $attachment_id );
		$custom_preserved = get_post_meta( $attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( ! isset( $custom_preserved['files'][0]['optimized_hash'] ) && ! isset( $custom_preserved['files'][0]['original_options_fingerprint'] ), 'Unverifiable legacy custom tracking acquired a fabricated identity.' );
		$assert( $custom_legacy['files'][0]['backup_path'] === $custom_preserved['files'][0]['backup_path'], 'Unverifiable legacy custom tracking lost its backup reference.' );
		$lifecycle_options['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $lifecycle_options );
		$custom_result = $optimizer->optimize_attachment( $attachment_id );
		$assert( 'existing' !== $custom_result['files'][0]['original_status'], 'Unverifiable legacy custom tracking used new sidecar options to skip processing.' );

		// Non-custom JPEG modes must preserve legacy matching with a non-default stored quality.
		$jpeg_source = $test_dir . '/tracking.jpg';
		$image = imagecreatetruecolor( 256, 256 );
		for ( $row = 0; $row < 256; ++$row ) {
			$color = imagecolorallocate( $image, $row, (int) ( $row / 2 ), 255 - $row );
			imageline( $image, 0, $row, 255, $row, $color );
		}
		imagejpeg( $image, $jpeg_source, 100 );
		imagedestroy( $image );
		$created_paths = array_merge( $created_paths, array( $jpeg_source, $jpeg_source . '.webp', $jpeg_source . '.avif' ) );
		$jpeg_backup = $invoke( 'backup_path_for', array( $jpeg_source ) );
		$jpeg_attachment_id = wp_insert_attachment( array( 'post_title' => 'Legacy JPEG tracking regression', 'post_mime_type' => 'image/jpeg' ), $jpeg_source );
		$assert( $jpeg_attachment_id > 0, 'Could not create the legacy JPEG attachment.' );
		$jpeg_options = array_merge( $lifecycle_options, array( 'optimize_originals' => 1, 'generate_webp_sidecars' => 0, 'max_width' => 0, 'jpeg_quality' => 90 ) );
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $jpeg_options );
		$jpeg_original_hash = hash_file( 'sha256', $jpeg_source );
		$jpeg_first = $optimizer->optimize_attachment( $jpeg_attachment_id );
		$assert( 'optimized' === $jpeg_first['files'][0]['original_status'], 'The JPEG fixture was not optimized by GD.' );
		$jpeg_optimized_hash = hash_file( 'sha256', $jpeg_source );
		$jpeg_legacy = get_post_meta( $jpeg_attachment_id, YoOhw_Media_Optimizer::META_KEY, true );
		unset( $jpeg_legacy['files'][0]['original_input_hash'], $jpeg_legacy['files'][0]['optimized_hash'], $jpeg_legacy['files'][0]['original_options_fingerprint'] );
		update_post_meta( $jpeg_attachment_id, YoOhw_Media_Optimizer::META_KEY, $jpeg_legacy );
		$jpeg_options['optimize_originals'] = 0;
		$jpeg_options['generate_webp_sidecars'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $jpeg_options );
		$optimizer->optimize_attachment( $jpeg_attachment_id );
		$jpeg_options['optimize_originals'] = 1;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $jpeg_options );
		$jpeg_resumed = $optimizer->optimize_attachment( $jpeg_attachment_id );
		$assert( 'existing' === $jpeg_resumed['files'][0]['original_status'], 'Legacy JPEG with non-default quality was reprocessed after a sidecar pass.' );
		$assert( $jpeg_optimized_hash === hash_file( 'sha256', $jpeg_source ), 'The legacy JPEG was recompressed.' );
		$assert( $jpeg_original_hash === hash_file( 'sha256', $jpeg_backup ), 'The legacy JPEG backup was replaced.' );

		$backup_restore_checks = require __DIR__ . '/backup-restore-tests.php';
		$backup_restore_checks( $optimizer, $invoke, $assert, $test_dir, $runtime_options );

		$lossless_jpeg_checks = require __DIR__ . '/lossless-jpeg-tests.php';
		$lossless_jpeg_checks( $optimizer, $invoke, $assert, $test_dir, $runtime_options );

		$queue_checks = require __DIR__ . '/queue-tests.php';
		$queue_checks( $optimizer, $invoke, $assert );

		$image_quality_checks = require __DIR__ . '/image-quality-tests.php';
		$image_quality_checks( $optimizer, $invoke, $assert, $test_dir );
		$color_profile_checks = require __DIR__ . '/color-profile-tests.php';
		$color_profile_checks( $optimizer, $invoke, $assert, $test_dir );
		$profile_quality_checks = require __DIR__ . '/profile-quality-tests.php';
		$profile_quality_checks( $optimizer, $invoke, $assert, $test_dir );
		$thumbnail_manager_checks = require __DIR__ . '/thumbnail-manager-tests.php';
		$thumbnail_manager_checks( $optimizer, $invoke, $assert );
		$prune_cleanup_checks = require __DIR__ . '/prune-cleanup-tests.php';
		$prune_cleanup_checks( $optimizer, $invoke, $assert );
	}

} catch ( Throwable $error ) {
	$failure = $error->getMessage();
} finally {
	remove_filter( 'wp_image_editors', $gd_editors );
	foreach ( array( $attachment_id, $jpeg_attachment_id ) as $test_attachment_id ) {
		if ( $test_attachment_id ) {
			wp_delete_attachment( $test_attachment_id, true );
		}
	}
	foreach ( array( $attachment_backup, $jpeg_backup ) as $test_backup ) {
		if ( $test_backup && file_exists( $test_backup ) ) {
			wp_delete_file( $test_backup );
		}
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
