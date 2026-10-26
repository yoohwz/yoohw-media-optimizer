<?php
/**
 * Backup and restore filesystem fixtures, invoked by regression-tests.php.
 *
 * @package YoOhw_Media_Optimizer
 */

return static function( $optimizer, $invoke, $assert, $test_dir, $runtime_options ) {
	$fixture = dirname( untrailingslashit( ABSPATH ) ) . '/yoohw-mo-backup-test-' . wp_generate_password( 8, false, false );
	$private_root = $fixture . '/private';
	$root_filter = static function() use ( &$private_root ) {
		return $private_root;
	};
	$remove_tree = static function( $path ) use ( &$remove_tree ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
		} elseif ( is_dir( $path ) ) {
			foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $child ) {
				$remove_tree( $child->getPathname() );
			}
			rmdir( $path );
		}
	};
	$source = $test_dir . '/backup-restore.png';
	$link = $test_dir . '/backup-link';
	add_filter( 'yoohw_media_optimizer_backup_dir', $root_filter );
	try {
		wp_mkdir_p( $fixture . '/outside' );
		$image = imagecreatetruecolor( 64, 64 );
		imagepng( $image, $source, 0 );
		imagedestroy( $image );
		copy( $source, $fixture . '/outside/source.png' );
		$assert( symlink( $fixture . '/outside', $link ), 'Could not create the backup containment fixture.' );
		$assert( '' === $invoke( 'backup_path_for', array( $link . '/source.png' ) ), 'Backup source symlink escape was accepted.' );
		$assert( '' === $invoke( 'backup_reference_to_path', array( $invoke( 'relative_upload_path', array( $source ) ) ) ), 'An arbitrary original upload was accepted as a backup reference.' );
	} finally {
		remove_filter( 'yoohw_media_optimizer_backup_dir', $root_filter );
		foreach ( array( $link, $source ) as $created ) {
			if ( is_link( $created ) || file_exists( $created ) ) {
				$remove_tree( $created );
			}
		}
		$remove_tree( $fixture );
	}
};
