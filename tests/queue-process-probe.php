<?php
/**
 * Real WordPress child process for queue ownership and interruption tests.
 * No fault-injection hooks are installed in production code.
 *
 * @package YoOhw_Media_Optimizer
 */

if ( 'cli' !== PHP_SAPI || ! isset( $argv[3] ) ) {
	exit( "Run this fixture through the regression entry point.\n" );
}

require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
$mode = $argv[2];
$barrier = $argv[3];
$optimizer = YoOhw_Media_Optimizer::instance();
$reflection = new ReflectionClass( $optimizer );
$invoke = static function( $name, $arguments = array() ) use ( $optimizer, $reflection ) {
	$method = $reflection->getMethod( $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	return $method->invokeArgs( $optimizer, $arguments );
};
$wait = static function() use ( $barrier ) {
	$deadline = microtime( true ) + 20;
	while ( ! file_exists( $barrier . '.continue' ) && microtime( true ) < $deadline ) {
		usleep( 10000 );
	}
};

if ( 'hold' === $mode || 'reconnect' === $mode ) {
	$token = $invoke( 'acquire_queue_lock' );
	if ( is_wp_error( $token ) ) {
		echo wp_json_encode( array( 'error' => $token->get_error_code() ) );
		exit;
	}
	$value = get_option( YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION );
	$value['acquired_at'] = time() - 3600;
	update_option( YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION, $value, false );
	if ( 'reconnect' === $mode ) {
		global $wpdb;
		$wpdb->close();
		$wpdb->db_connect();
	}
	file_put_contents( $barrier, wp_json_encode( array( 'token' => $token ) ) );
	$wait();
	echo wp_json_encode( array( 'released' => $invoke( 'release_queue_lock', array( $token ) ) ) );
} elseif ( 'interrupt' === $mode ) {
	$pause = static function( $option, $old, $value ) use ( $barrier, $wait ) {
		if ( YoOhw_Media_Optimizer::QUEUE_OPTION !== $option ) {
			return;
		}
		foreach ( $value['items'] as $item ) {
			if ( 'running' === $item['status'] ) {
				file_put_contents( $barrier, 'running' );
				$wait();
				return;
			}
		}
	};
	add_action( 'updated_option', $pause, 10, 3 );
	echo wp_json_encode( $optimizer->process_queue_batch( 1, true ) );
} elseif ( 'replace-queue' === $mode ) {
	$queue = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
	$queue['run_id'] = $argv[4];
	update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $queue, false );
	echo wp_json_encode( array( 'run_id' => $queue['run_id'] ) );
} else {
	$token = $invoke( 'acquire_queue_lock' );
	$result = array( 'acquired' => is_string( $token ), 'error' => is_wp_error( $token ) ? $token->get_error_code() : '' );
	if ( is_string( $token ) ) {
		$invoke( 'release_queue_lock', array( $token ) );
	}
	echo wp_json_encode( $result );
}
