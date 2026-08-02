<?php
/**
 * Plugin Name: YoOhw Media Optimizer
 * Description: Optimizes WordPress media with modern sidecars, optional backed-up original compression, resize controls, and restore tools.
 * Version: 1.0.1
 * Author: YoOhw Studio
 * Text Domain: yoohw-media-optimizer
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package YoOhw_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YOOHW_MEDIA_OPTIMIZER_VERSION', '1.0.1' );
define( 'YOOHW_MEDIA_OPTIMIZER_FILE', __FILE__ );
define( 'YOOHW_MEDIA_OPTIMIZER_PATH', plugin_dir_path( __FILE__ ) );
define( 'YOOHW_MEDIA_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

require_once YOOHW_MEDIA_OPTIMIZER_PATH . 'includes/class-yoohw-media-optimizer.php';

register_activation_hook( __FILE__, array( 'YoOhw_Media_Optimizer', 'activate' ) );

add_action(
	'plugins_loaded',
	static function() {
		YoOhw_Media_Optimizer::instance();
	}
);
