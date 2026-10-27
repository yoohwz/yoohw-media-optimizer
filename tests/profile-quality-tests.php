<?php
/**
 * Codec profiles use fresh children to isolate optional-tool caches/options.
 *
 * @package YoOhw_Media_Optimizer
 */
return static function( $optimizer, $invoke, $assert, $test_dir ) {
	$assert( function_exists( 'proc_open' ), 'Codec profile probes require proc_open.' );
	foreach ( array( 'settings', 'gd', 'editor', 'native', 'native-fault', 'native-missing' ) as $mode ) {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/codec-profile-probe.php', ABSPATH, $mode ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$assert( is_resource( $process ), 'Could not start codec profile child: ' . $mode );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		$exit = proc_close( $process );
		$assert( 0 === $exit, 'Codec profile ' . $mode . ' failed: ' . $output . $error );
		$assert( false !== strpos( $output, 'Codec profile ' . $mode . ':' ), 'Missing codec profile result: ' . $mode );
		WP_CLI::log( trim( $output ) );
	}
};
