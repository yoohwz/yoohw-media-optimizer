<?php
/**
 * Delivery URL and diagnostic regressions using temporary upload fixtures.
 *
 * @package YoOhw_Media_Optimizer
 */

return static function( $optimizer, $invoke, $assert ) {
	$uploads = wp_get_upload_dir();
	$relative = 'yoohw-mo-delivery-' . wp_generate_password( 8, false, false ) . '.png';
	$path = trailingslashit( $uploads['basedir'] ) . $relative;
	$url = trailingslashit( $uploads['baseurl'] ) . $relative;
	$intercept = static function() {
		return array( 'headers' => array( 'content-type' => 'text/html' ), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	};
	try {
		file_put_contents( $path, 'source fixture' );
		file_put_contents( $path . '.webp', 'sidecar fixture' );
		touch( $path . '.webp', time() + 2 );
		$foreign = 'https://outside.invalid' . wp_parse_url( $url, PHP_URL_PATH );
		$assert( '' === $invoke( 'format_sidecar_url', array( $foreign, 'webp' ) ), 'An external URL sharing an upload path selected a local sidecar.' );
		add_filter( 'pre_http_request', $intercept );
		$report = $invoke( 'remote_head_report', array( $url . '.webp', array(), array( 'webp' ) ) );
		$assert( empty( $report['ok'] ), 'HTTP 200 with HTML was reported as successful image delivery.' );
	} finally {
		remove_filter( 'pre_http_request', $intercept );
		wp_delete_file( $path . '.webp' );
		wp_delete_file( $path );
	}
};
