<?php
/**
 * Uninstall handler for YoOhw Media Optimizer.
 *
 * @package YoOhw_Media_Optimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove plugin options and attachment tracking metadata for the current site.
 *
 * Generated sidecars and backups are intentionally left in place because a
 * site may be serving them through server/CDN rewrite rules or need restores.
 *
 * @return void
 */
function yoohw_media_optimizer_uninstall_site() {
	delete_option( 'yoohw_media_optimizer_options' );
	delete_option( 'yoohw_media_optimizer_queue' );
	delete_option( 'yoohw_media_optimizer_queue_lock' );
	delete_transient( 'yoohw_media_optimizer_savings' );
	delete_metadata( 'post', 0, '_yoohw_media_optimizer', '', true );
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$yoohw_media_optimizer_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $yoohw_media_optimizer_site_ids as $yoohw_media_optimizer_site_id ) {
		switch_to_blog( (int) $yoohw_media_optimizer_site_id );
		yoohw_media_optimizer_uninstall_site();
		restore_current_blog();
	}

	delete_site_option( 'yoohw_media_optimizer_options' );
	delete_site_option( 'yoohw_media_optimizer_queue' );
} else {
	yoohw_media_optimizer_uninstall_site();
}
