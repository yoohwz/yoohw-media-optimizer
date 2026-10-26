<?php
/**
 * Color-profile checks run in fresh PHP processes (binary lookup is cached).
 *
 * @package YoOhw_Media_Optimizer
 */

return static function( $optimizer, $invoke, $assert, $test_dir ) {
	$assert( function_exists( 'proc_open' ), 'Color-profile child processes require proc_open.' );
	foreach ( array( 'gd', 'imagick', 'native', 'fault', 'tool-flags' ) as $mode ) {
		$command = array( PHP_BINARY );
		$command = array_merge( $command, array( __DIR__ . '/color-profile-probe.php', ABSPATH, $mode ) );
		$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$assert( is_resource( $process ), 'Could not start color-profile child: ' . $mode );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );
		$assert( 0 === $exit, 'Color-profile ' . $mode . ' failed: ' . $output . $error );
		$assert( false !== strpos( $output, 'Color profile ' . $mode . ':' ), 'Missing quality result: ' . $mode . $output . $error );
		WP_CLI::log( trim( $output ) );
	}
};
