<?php
/**
 * Read-only staging preparation report; does not run media operations.
 *
 * Run this checkout's script through WP-CLI against the selected staging site.
 * WordPress/plugin bootstrap may have its own side effects; disable WP-Cron.
 *
 * @package YoOhw_Media_Optimizer
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run this file through WP-CLI.\n" );
}

if ( ! class_exists( 'YoOhw_Media_Optimizer' ) ) {
	WP_CLI::error( 'Media Optimizer is not loaded on this site.' );
}

$optimizer = YoOhw_Media_Optimizer::instance();
$candidate = dirname( __DIR__ );
$loaded = dirname( dirname( ( new ReflectionClass( $optimizer ) )->getFileName() ) );
$files = array_merge(
	array( $candidate . '/yoohw-media-optimizer.php', $candidate . '/uninstall.php', $candidate . '/readme.txt' ),
	glob( $candidate . '/includes/*.php' ),
	glob( $candidate . '/assets/*.js' ),
	glob( $candidate . '/assets/*.css' )
);
$identity = array();
$matches = true;
foreach ( $files as $file ) {
	$relative = substr( $file, strlen( $candidate ) + 1 );
	$expected = hash_file( 'sha256', $file );
	$actual = is_file( $loaded . '/' . $relative ) ? hash_file( 'sha256', $loaded . '/' . $relative ) : null;
	$identity[ $relative ] = array( 'candidate_sha256' => $expected, 'loaded_sha256' => $actual, 'matches' => $expected === $actual );
	$matches = $matches && $expected === $actual;
}

$options = $optimizer->get_options();
$selected = array_intersect_key( $options, array_flip( array(
	'auto_optimize_uploads', 'generate_webp_sidecars', 'generate_avif_sidecars',
	'optimize_originals', 'backup_originals', 'use_external_binaries', 'quality',
	'webp_quality', 'avif_quality', 'webp_png_mode', 'avif_alpha_mode',
	'metadata_policy', 'skip_larger_files', 'delivery_mode',
) ) );
$imagick = class_exists( 'Imagick' );
$delegates = $imagick ? implode( ' ', Imagick::getConfigureOptions( 'DELEGATES' ) ) : '';
$editors = array();
foreach ( array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ) as $mime ) {
	$editors[ $mime ] = wp_image_editor_supports( array( 'mime_type' => $mime ) );
}
$binaries = array();
foreach ( $optimizer->external_binary_report() as $binary ) {
	// Discovery is not proof that a binary executes or supports the required flags.
	$binaries[ $binary['name'] ] = (bool) $binary['available'];
}
$wp_imagick = class_exists( 'WP_Image_Editor_Imagick' ) && WP_Image_Editor_Imagick::test();
$notes = array();
if ( ! $matches ) {
	$notes[] = 'Loaded plugin files differ from this checkout. Do not attribute staging results to the candidate yet.';
}
if ( ! $imagick || ! preg_match( '/\blcms(?:2)?\b/i', $delegates ) ) {
	$notes[] = 'Independent Imagick color-managed assertions require LCMS; unavailable branches must be reported NOT RUN.';
}
if ( $imagick && ! $wp_imagick ) {
	$notes[] = 'Imagick is installed but its WordPress editor test fails. GD format support does not establish profile-aware editor support.';
}
if ( empty( $binaries['cwebp'] ) || empty( $binaries['avifenc'] ) ) {
	$notes[] = 'PNG native profiles require enabled working cwebp/avifenc; discovery alone does not validate them.';
}
if ( ! $editors['image/webp'] || ! $editors['image/avif'] ) {
	$notes[] = 'Some WordPress editor formats are unavailable; inspect actual selected editors and native paths separately.';
}

WP_CLI::line( wp_json_encode( array(
	'report_kind' => 'preparation_only',
	'wordpress' => get_bloginfo( 'version' ),
	'php' => PHP_VERSION,
	'sapi' => PHP_SAPI,
	'php_ini' => php_ini_loaded_file(),
	'cli_memory_limit' => ini_get( 'memory_limit' ),
	'plugin_version' => defined( 'YOOHW_MEDIA_OPTIMIZER_VERSION' ) ? YOOHW_MEDIA_OPTIMIZER_VERSION : null,
	'candidate_runtime_files_match' => $matches,
	'identity' => $identity,
	'options' => $selected,
	'gd' => extension_loaded( 'gd' ),
	'exif' => function_exists( 'exif_read_data' ),
	'proc_open' => function_exists( 'proc_open' ),
	'imagick_extension_version' => $imagick ? phpversion( 'imagick' ) : null,
	'wordpress_imagick_usable' => $wp_imagick,
	'imagick_version' => $imagick ? Imagick::getVersion()['versionString'] : null,
	'imagick_lcms' => (bool) preg_match( '/\blcms(?:2)?\b/i', $delegates ),
	'imagick_formats' => $imagick ? array_values( array_intersect( array( 'JPEG', 'PNG', 'WEBP', 'AVIF' ), Imagick::queryFormats() ) ) : array(),
	'wordpress_editor_formats' => $editors,
	'enabled_binary_discovery' => $binaries,
	'notes' => $notes,
	'acceptance' => 'NOT RUN — preflight only; no encoding, attachment mutation, option update, cleanup or restore is requested by this script.',
	'web_runtime' => 'NOT RUN — PHP-FPM/browser may differ from this CLI process.',
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
