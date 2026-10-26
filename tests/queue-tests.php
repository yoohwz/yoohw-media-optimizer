<?php
/**
 * Queue ownership and resume fixtures, invoked by regression-tests.php.
 *
 * @package YoOhw_Media_Optimizer
 */

return static function( $optimizer, $invoke, $assert ) {
	$missing = new stdClass();
	$original_queue = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $missing );
	$token = '';
	try {
		delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$built = $optimizer->build_queue();
		$before = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$token = $invoke( 'acquire_queue_lock' );
		$assert( is_string( $token ), 'The queue test could not acquire ownership.' );
		$rebuilt = $optimizer->build_queue();
		$after = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$assert( ! empty( $rebuilt['locked'] ) && $before === $after, 'Building a queue bypassed an active worker lock.' );
	} finally {
		if ( $token ) {
			$invoke( 'release_queue_lock', array( $token ) );
		}
		if ( $original_queue === $missing ) {
			delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		} else {
			update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $original_queue, false );
		}
	}
};
