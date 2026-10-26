<?php
/**
 * Queue ownership and resume fixtures, invoked by regression-tests.php.
 *
 * @package YoOhw_Media_Optimizer
 */

return static function( $optimizer, $invoke, $assert ) {
	$missing = new stdClass();
	$original_queue = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $missing );
	$original_lock = get_option( YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION, $missing );
	$original_options = get_option( YoOhw_Media_Optimizer::OPTION_KEY );
	$uploads = wp_get_upload_dir();
	$fixture = trailingslashit( $uploads['basedir'] ) . 'yoohw-mo-queue-' . wp_generate_password( 8, false, false );
	$lock_path = trailingslashit( realpath( $uploads['basedir'] ) ) . '.yoohw-media-optimizer.lock';
	$attachments = array();
	$children = array();
	$token = '';
	$query_ids = array( 0 );
	$query_filter = static function( $query ) use ( &$query_ids ) {
		if ( 'attachment' === $query->get( 'post_type' ) ) {
			$query->set( 'post__in', $query_ids );
		}
	};
	$spawn = static function( $mode, $id = 'child', $extra = '' ) use ( &$children, $fixture, $assert ) {
		$pipes = array();
		$barrier = $fixture . '/' . $id;
		$process = proc_open( array( PHP_BINARY, '-d', 'error_reporting=22527', __DIR__ . '/queue-process-probe.php', ABSPATH, $mode, $barrier, $extra ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$assert( is_resource( $process ), 'Could not start the WordPress queue probe.' );
		fclose( $pipes[0] );
		$child = array( $process, $pipes, $barrier );
		$children[] = $child;
		return $child;
	};
	$finish = static function( $child, $killed = false ) use ( $assert ) {
		$deadline = microtime( true ) + 15;
		while ( proc_get_status( $child[0] )['running'] && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		if ( proc_get_status( $child[0] )['running'] ) {
			proc_terminate( $child[0], 9 );
		}
		$output = stream_get_contents( $child[1][1] );
		$error = stream_get_contents( $child[1][2] );
		fclose( $child[1][1] );
		fclose( $child[1][2] );
		proc_close( $child[0] );
		if ( $killed ) {
			return array();
		}
		$data = json_decode( $output, true );
		$assert( is_array( $data ), 'WordPress queue probe failed: ' . $error . $output );
		return $data;
	};
	$ready = static function( $child ) use ( $assert ) {
		$deadline = microtime( true ) + 10;
		while ( ! file_exists( $child[2] ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		$assert( file_exists( $child[2] ), 'Queue child did not reach its ownership barrier.' );
	};
	$read_queue = static function() {
		wp_cache_delete( YoOhw_Media_Optimizer::QUEUE_OPTION, 'options' );
		return get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
	};
	try {
		wp_mkdir_p( $fixture );
		delete_option( YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION );
		delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		add_action( 'pre_get_posts', $query_filter );
		$options = $original_options;
		$options['optimize_originals'] = 0;
		$options['generate_webp_sidecars'] = 0;
		$options['generate_avif_sidecars'] = 0;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options, false );

		// A live native owner excludes another process even after TTL and DB reconnect.
		foreach ( array( 'hold', 'reconnect' ) as $mode ) {
			$child = $spawn( $mode, $mode );
			$ready( $child );
			$contender = $finish( $spawn( 'acquire', $mode . '-contender' ) );
			$assert( ! $contender['acquired'], 'A live owner was displaced after TTL or DB reconnect.' );
			$before = $read_queue();
			$busy_build = $optimizer->build_queue();
			$assert( ! empty( $busy_build['locked'] ) && $before === $read_queue(), 'Building a queue bypassed an active worker lock.' );
			$busy_optimize = $optimizer->optimize_attachment( 2147483000 );
			$busy_restore = $optimizer->restore_attachment( 2147483000 );
			$busy_cleanup = $invoke( 'cleanup_sidecars', array( false ) );
			$assert( ! empty( $busy_optimize['locked'] ) && ! empty( $busy_restore['locked'] ) && ! empty( $busy_cleanup['locked'] ), 'A direct media mutation bypassed the global owner.' );
			file_put_contents( $child[2] . '.continue', 'release' );
			$released = $finish( $child );
			$assert( ! empty( $released['released'] ), 'The native owner could not release after reconnect.' );
		}

		$token = $invoke( 'acquire_queue_lock' );
		$assert( is_string( $token ), 'The token test could not acquire ownership.' );
		$assert( false === $invoke( 'refresh_queue_lock', array( 'wrong-token' ) ) && false === $invoke( 'release_queue_lock', array( 'wrong-token' ) ), 'A wrong token refreshed or released ownership.' );
		$assert( ! $finish( $spawn( 'acquire', 'wrong-token-contender' ) )['acquired'], 'Wrong-token release dropped the native lock.' );
		$assert( true === $invoke( 'refresh_queue_lock', array( $token ) ), 'Correct-token refresh failed.' );
		$invoke( 'release_queue_lock', array( $token ) );
		$token = '';
		$assert( file_exists( $lock_path ), 'Release removed the persistent lockfile.' );

		// A fresh legacy owner is respected until its existing compatibility timeout.
		$legacy_lock = array( 'token' => 'legacy-owner', 'acquired_at' => time() );
		update_option( YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION, $legacy_lock, false );
		$assert( is_wp_error( $invoke( 'acquire_queue_lock' ) ) && $legacy_lock === get_option( YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION ), 'A fresh legacy owner was displaced.' );
		$legacy_lock['acquired_at'] -= 3600;
		update_option( YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION, $legacy_lock, false );
		$token = $invoke( 'acquire_queue_lock' );
		$assert( is_string( $token ), 'An expired legacy owner prevented native adoption.' );
		$invoke( 'release_queue_lock', array( $token ) );
		$token = '';

		// Nonregular paths, symlink aliases and replaced inode fail closed.
		rename( $lock_path, $fixture . '/saved-lock' );
		mkdir( $lock_path );
		$assert( is_wp_error( $invoke( 'acquire_queue_lock' ) ), 'A directory was accepted as the native lockfile.' );
		rmdir( $lock_path );
		symlink( $fixture . '/saved-lock', $lock_path );
		$assert( is_wp_error( $invoke( 'acquire_queue_lock' ) ), 'A symlink was accepted as the native lockfile.' );
		unlink( $lock_path );
		rename( $fixture . '/saved-lock', $lock_path );
		$token = $invoke( 'acquire_queue_lock' );
		rename( $lock_path, $fixture . '/replaced-lock' );
		file_put_contents( $lock_path, '' );
		$assert( false === $invoke( 'refresh_queue_lock', array( $token ) ), 'An owner continued after its lock inode was replaced.' );
		$invoke( 'release_queue_lock', array( $token ) );
		$token = '';
		unlink( $lock_path );
		rename( $fixture . '/replaced-lock', $lock_path );
		delete_option( YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION );

		$token = $invoke( 'acquire_queue_lock' );
		wp_mkdir_p( $fixture . '/alternate-uploads' );
		$change_root = static function( $value ) use ( $fixture ) { $value['basedir'] = $fixture . '/alternate-uploads'; return $value; };
		add_filter( 'upload_dir', $change_root );
		try {
			$assert( false === $invoke( 'refresh_queue_lock', array( $token ) ), 'An owner continued after the canonical uploads root changed.' );
		} finally {
			remove_filter( 'upload_dir', $change_root );
		}
		$invoke( 'release_queue_lock', array( $token ) );
		$token = '';
		rmdir( $fixture . '/alternate-uploads' );

		// Empty queues retain an identity and do not rebuild on refresh/resume.
		$empty = $optimizer->run_browser_batch( '', true, false, 1 );
		$assert( ! empty( $empty['runId'] ) && 0 === $empty['found'] && ! $empty['hasMore'], 'An empty browser run lost its identity or completion state.' );
		$empty_id = $empty['runId'];
		$empty_resume = $optimizer->run_browser_batch( $empty_id, false, false, 1 );
		$assert( $empty_id === $empty_resume['runId'] && 0 === $empty_resume['processedTotal'], 'Resuming an empty run rebuilt it.' );

		// Real attachments exercise immutable snapshots and absolute response totals.
		for ( $index = 0; $index < 3; ++$index ) {
			$path = $fixture . '/snapshot-' . $index . '.png';
			$image = imagecreatetruecolor( 32, 32 );
			imagepng( $image, $path, 0 );
			imagedestroy( $image );
			$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Queue regression ' . $index, 'post_status' => 'inherit' ), $path );
			$attachments[] = $id;
			update_attached_file( $id, $path );
			wp_update_attachment_metadata( $id, array( 'width' => 32, 'height' => 32, 'file' => _wp_relative_upload_path( $path ), 'sizes' => array() ) );
		}
		$query_ids = array_slice( $attachments, 0, 2 );
		$stale_empty_start = $optimizer->run_browser_batch( '', true, false, 1 );
		$assert( ! empty( $stale_empty_start['error'] ) && $empty_id === $read_queue()['run_id'], 'A stale tab without a run ID replaced a completed run.' );
		$first = $optimizer->run_browser_batch( $empty_id, true, false, 1 );
		$run_id = $first['runId'];
		$snapshot = $read_queue();
		$assert( 2 === $first['found'] && 1 === $first['processedTotal'] && $first['hasMore'], 'Browser start did not persist one bounded snapshot batch.' );
		$query_ids = $attachments;
		$rebuilt = $optimizer->build_queue();
		$assert( $snapshot['items'] === $read_queue()['items'] && $snapshot['run_id'] === $read_queue()['run_id'], 'A second tab rebuilt an active snapshot.' );
		$remaining = array_values( array_filter( array_keys( $snapshot['items'] ), static function( $id ) use ( $snapshot ) { return 'pending' === $snapshot['items'][ $id ]['status']; } ) );
		wp_delete_attachment( $remaining[0], true );
		$second = $optimizer->run_browser_batch( $run_id, false, false, 1 );
		$completed = $read_queue();
		$assert( 2 === $second['found'] && 2 === $second['processedTotal'] && ! $second['hasMore'] && 'skipped' === $completed['items'][ $remaining[0] ]['status'], 'A deleted snapshot ID shifted progress or was not terminal skipped.' );
		$assert( ! isset( $completed['items'][ $attachments[2] ] ), 'A later upload entered an existing snapshot.' );
		$lost_response = $optimizer->run_browser_batch( $run_id, false, false, 1 );
		$assert( $second['processedTotal'] === $lost_response['processedTotal'] && 0 === $lost_response['processed'] && $completed === $read_queue(), 'Lost-response retry replayed terminal work or double-counted totals.' );
		$new = $optimizer->run_browser_batch( $run_id, true, true, 1 );
		$stale = $optimizer->run_browser_batch( $run_id, false, false, 1 );
		$assert( ! empty( $stale['error'] ) && $new['runId'] === $read_queue()['run_id'], 'A stale tab processed the replacement run.' );
		$conflict = $optimizer->process_queue_batch( 1, false );
		$assert( ! empty( $conflict['error'] ), 'A conflicting explicit force policy was accepted.' );
		$inherited = $optimizer->process_queue_batch( 10 );
		$assert( empty( $inherited['error'] ) && true === $read_queue()['force'], 'An omitted CLI force did not inherit the job policy.' );

		// Legacy terminal states survive adoption; historical totals are not fabricated.
		$legacy_id = 2147483000;
		$legacy = array( 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00', 'items' => array( $legacy_id => array( 'id' => $legacy_id, 'status' => 'done', 'message' => 'legacy', 'updated_at' => '2020-01-01 00:00:00' ), $legacy_id + 1 => array( 'id' => $legacy_id + 1, 'status' => 'pending', 'updated_at' => current_time( 'mysql' ) ) ) );
		update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $legacy, false );
		$adopted = $optimizer->process_queue_batch( 1, true );
		$adopted_queue = $read_queue();
		$assert( ! empty( $adopted_queue['run_id'] ) && true === $adopted_queue['force'] && 'done' === $adopted_queue['items'][ $legacy_id ]['status'] && 'legacy' === $adopted_queue['items'][ $legacy_id ]['message'], 'Legacy adoption discarded terminal progress or force binding.' );
		$file_totals = $adopted_queue['totals'];
		unset( $file_totals['processed'] );
		$assert( 0 === array_sum( $file_totals ), 'Legacy adoption invented file operation totals.' );

		// A cached option from another request cannot hide authoritative queue changes.
		get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$fresh_id = wp_generate_uuid4();
		$finish( $spawn( 'replace-queue', 'cache', $fresh_id ) );
		$fresh_status = $optimizer->queue_status();
		$assert( $fresh_id === $fresh_status['run_id'], 'Queue status trusted a stale per-process options cache.' );

		// Refused writes cannot report progress or perform pending media work.
		$pending = $legacy;
		$pending['items'] = array( $legacy_id => array( 'id' => $legacy_id, 'status' => 'pending', 'updated_at' => current_time( 'mysql' ) ) );
		update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $pending, false );
		$refuse = static function( $value, $old ) { return $old; };
		add_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::QUEUE_OPTION, $refuse, 10, 2 );
		try {
			$failed_save = $optimizer->process_queue_batch( 1, false );
		} finally {
			remove_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::QUEUE_OPTION, $refuse, 10 );
		}
		$assert( ! empty( $failed_save['error'] ) && 0 === $failed_save['processed'] && $pending === $read_queue(), 'A failed queue write reported progress or changed persisted state.' );

		$pending['run_id'] = wp_generate_uuid4();
		$pending['force'] = false;
		$pending['totals'] = array( 'processed' => 0 );
		update_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $pending, false );
		$refuse_terminal = static function( $value, $old ) use ( $legacy_id ) {
			return 'skipped' === ( $value['items'][ $legacy_id ]['status'] ?? '' ) ? $old : $value;
		};
		add_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::QUEUE_OPTION, $refuse_terminal, 10, 2 );
		try {
			$failed_terminal = $optimizer->process_queue_batch( 1 );
		} finally {
			remove_filter( 'pre_update_option_' . YoOhw_Media_Optimizer::QUEUE_OPTION, $refuse_terminal, 10 );
		}
		$after_terminal_failure = $read_queue();
		$assert( ! empty( $failed_terminal['error'] ) && 0 === $failed_terminal['processed'] && 'running' === $after_terminal_failure['items'][ $legacy_id ]['status'] && 0 === $after_terminal_failure['totals']['processed'], 'A failed terminal save published false progress.' );
		$terminal_retry = $optimizer->process_queue_batch( 1 );
		$assert( 1 === $terminal_retry['processed'] && 1 === $read_queue()['totals']['processed'], 'A refused terminal save could not resume without double counting.' );

		// A killed owner leaves a recent running item; reclaim must not replay force.
		$query_ids = array( $attachments[0] );
		delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$options['optimize_originals'] = 1;
		$options['backup_originals'] = 1;
		$options['compression_mode'] = 'balanced';
		$options['metadata_policy'] = 'remove';
		$options['use_external_binaries'] = 0;
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options, false );
		$optimizer->optimize_attachment( $attachments[0] );
		$optimizer->build_queue();
		$interrupted = $spawn( 'interrupt', 'interrupted' );
		$ready( $interrupted );
		$running = $read_queue();
		$assert( 'running' === $running['items'][ $attachments[0] ]['status'], 'The interruption barrier did not persist running before media work.' );
		proc_terminate( $interrupted[0], 9 );
		$finish( $interrupted, true );
		$resumed = $optimizer->process_queue_batch( 1 );
		$tracking = get_post_meta( $attachments[0], YoOhw_Media_Optimizer::META_KEY, true );
		$assert( empty( $resumed['locked'] ) && empty( $resumed['error'] ) && 1 === $resumed['processed'] && 'done' === $read_queue()['items'][ $attachments[0] ]['status'], 'A dead owner recent running item could not be reclaimed.' );
		$assert( 'existing' === $tracking['files'][0]['original_status'], 'Reclaim replayed the interrupted force policy.' );
	} finally {
		remove_action( 'pre_get_posts', $query_filter );
		foreach ( $children as $child ) {
			if ( is_resource( $child[0] ) ) {
				proc_terminate( $child[0], 9 );
				foreach ( array( 1, 2 ) as $index ) {
					if ( is_resource( $child[1][ $index ] ) ) { fclose( $child[1][ $index ] ); }
				}
				proc_close( $child[0] );
			}
		}
		if ( $token ) { $invoke( 'release_queue_lock', array( $token ) ); }
		$invoke( 'close_mutation_handle' );
		foreach ( array( 'saved-lock', 'replaced-lock' ) as $saved ) {
			if ( is_file( $fixture . '/' . $saved ) ) {
				if ( is_link( $lock_path ) || is_file( $lock_path ) ) { unlink( $lock_path ); } elseif ( is_dir( $lock_path ) ) { rmdir( $lock_path ); }
				rename( $fixture . '/' . $saved, $lock_path );
			}
		}
		if ( is_dir( $fixture . '/alternate-uploads' ) ) { rmdir( $fixture . '/alternate-uploads' ); }
		foreach ( $attachments as $id ) {
			$tracking = get_post_meta( $id, YoOhw_Media_Optimizer::META_KEY, true );
			foreach ( (array) ( $tracking['files'] ?? array() ) as $file ) {
				$source = ! empty( $file['source'] ) ? $invoke( 'upload_relative_to_path', array( $file['source'] ) ) : '';
				$backup = $source && ! empty( $file['backup_path'] ) ? $invoke( 'backup_reference_to_path', array( $file['backup_path'], $source ) ) : '';
				if ( $backup && is_file( $backup ) ) { wp_delete_file( $backup ); }
			}
			wp_delete_attachment( $id, true );
		}
		foreach ( glob( $fixture . '/*' ) ?: array() as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) { unlink( $path ); }
		}
		if ( is_dir( $fixture ) ) { rmdir( $fixture ); }
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $original_options, false );
		foreach ( array( YoOhw_Media_Optimizer::QUEUE_OPTION => $original_queue, YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION => $original_lock ) as $name => $value ) {
			if ( $value === $missing ) { delete_option( $name ); } else { update_option( $name, $value, false ); }
		}
	}
};
