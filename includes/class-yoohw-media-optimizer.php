<?php
/**
 * Main plugin class.
 *
 * @package YoOhw_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-yoohw-media-optimizer-color.php';

/**
 * Generates modern sidecar files for WordPress media.
 */
class YoOhw_Media_Optimizer {
	const MENU_SLUG              = 'yoohw-media-optimizer';
	const CAPABILITY             = 'manage_options';
	const OPTION_KEY             = 'yoohw_media_optimizer_options';
	const QUEUE_OPTION           = 'yoohw_media_optimizer_queue';
	const QUEUE_LOCK_OPTION      = 'yoohw_media_optimizer_queue_lock';
	const SAVINGS_TRANSIENT      = 'yoohw_media_optimizer_savings';
	const META_KEY               = '_yoohw_media_optimizer';
	const DEFAULT_LIMIT          = 25;
	const UI_BATCH_LIMIT         = 8;
	const BACKUP_DIR             = 'yoohw-media-backups';
	const META_SCHEMA            = 3;
	const DELIVERY_GENERATE_ONLY = 'generate_only';
	const DELIVERY_HTML          = 'html';

	/**
	 * Singleton instance.
	 *
	 * @var YoOhw_Media_Optimizer|null
	 */
	private static $instance = null;

	/**
	 * Metadata hashes optimized during the current request.
	 *
	 * @var array
	 */
	private $optimized_metadata_hashes = array();

	/**
	 * Attachment metadata currently being rewritten internally.
	 *
	 * @var array
	 */
	private $metadata_refreshing = array();

	/**
	 * Per-request attachment savings cache.
	 *
	 * @var array
	 */
	private $attachment_savings_cache = array();

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private $admin_hook = '';

	/** Native mutation ownership; never inherited by a reentrant public call. */
	private $mutation_handle = null;
	private $mutation_token = '';
	private $mutation_root = '';
	private $mutation_path = '';

	/**
	 * Get singleton instance.
	 *
	 * @return YoOhw_Media_Optimizer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::default_options() );
		}
	}

	/**
	 * Default plugin options.
	 *
	 * @return array
	 */
	private static function default_options() {
		return array(
			'auto_optimize_uploads'  => 1,
			'generate_webp_sidecars' => 1,
			'generate_avif_sidecars' => 0,
			'optimize_originals'     => 0,
			'backup_originals'       => 1,
			'use_external_binaries'  => 1,
			'quality'                => 82,
			'jpeg_quality'           => 82,
			'webp_png_mode'          => 'lossy',
			'avif_alpha_mode'        => 'default',
			'avif_imagick_chroma'    => 'default',
			'compression_mode'       => 'balanced',
			'metadata_policy'        => 'remove',
			'max_width'              => 0,
			'max_height'             => 0,
			'skip_larger_files'      => 1,
			'delivery_mode'          => self::DELIVERY_GENERATE_ONLY,
		);
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'wp_image_editors', array( $this, 'thumbnail_image_editors' ), PHP_INT_MAX );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'optimize_generated_metadata' ), 20, 2 );
		add_filter( 'wp_update_attachment_metadata', array( $this, 'optimize_generated_metadata' ), 20, 2 );
		add_action( 'delete_attachment', array( $this, 'delete_attachment_sidecars' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_filter( 'admin_footer_text', array( $this, 'filter_admin_footer_text' ) );
		add_filter( 'update_footer', array( $this, 'filter_update_footer' ), 11 );
		add_action( 'admin_post_yoohw_mo_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_yoohw_mo_optimize_batch', array( $this, 'handle_optimize_batch' ) );
		add_action( 'admin_post_yoohw_mo_cleanup_sidecars', array( $this, 'handle_cleanup_sidecars' ) );
		add_action( 'admin_post_yoohw_mo_restore_attachment', array( $this, 'handle_restore_attachment' ) );
		add_action( 'admin_post_yoohw_mo_restore_backups', array( $this, 'handle_restore_backups' ) );
		add_action( 'admin_post_yoohw_mo_build_queue', array( $this, 'handle_build_queue' ) );
		add_action( 'admin_post_yoohw_mo_process_queue', array( $this, 'handle_process_queue' ) );
		add_action( 'wp_ajax_yoohw_mo_optimize_batch', array( $this, 'handle_ajax_optimize_batch' ) );
		add_action( 'wp_ajax_yoohw_mo_test_delivery', array( $this, 'handle_ajax_test_delivery' ) );

		add_filter( 'manage_upload_columns', array( $this, 'add_media_library_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_library_column' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'register_media_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_media_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'render_media_admin_notices' ) );

		add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image_html' ), 20, 5 );
		add_filter( 'the_content', array( $this, 'filter_content_images' ), 20 );

		$this->register_cli_command();
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function admin_menu() {
		$this->admin_hook = add_media_page(
			__( 'Media Optimizer', 'yoohw-media-optimizer' ),
			__( 'Media Optimizer', 'yoohw-media-optimizer' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function admin_assets( $hook ) {
		$load_plugin_page = $this->admin_hook === $hook;
		$load_media_list  = 'upload.php' === $hook;

		if ( ! $load_plugin_page && ! $load_media_list ) {
			return;
		}

		wp_enqueue_style(
			'yoohw-media-optimizer-admin',
			YOOHW_MEDIA_OPTIMIZER_URL . 'assets/admin.css',
			array(),
			YOOHW_MEDIA_OPTIMIZER_VERSION
		);

		if ( ! $load_plugin_page ) {
			return;
		}

		wp_enqueue_script(
			'yoohw-media-optimizer-admin',
			YOOHW_MEDIA_OPTIMIZER_URL . 'assets/admin.js',
			array(),
			YOOHW_MEDIA_OPTIMIZER_VERSION,
			true
		);

		wp_localize_script(
			'yoohw-media-optimizer-admin',
			'yoohwMediaOptimizer',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'yoohw_mo_ajax' ),
				'batchSize' => self::UI_BATCH_LIMIT,
				'queueProgress' => $this->browser_queue_progress(),
				'strings' => array(
					'resume'         => __( 'Resume optimizing', 'yoohw-media-optimizer' ),
					'starting'       => __( 'Preparing media optimization...', 'yoohw-media-optimizer' ),
					'running'        => __( 'Optimizing media...', 'yoohw-media-optimizer' ),
					'paused'         => __( 'Optimization paused.', 'yoohw-media-optimizer' ),
					'complete'       => __( 'Optimization complete.', 'yoohw-media-optimizer' ),
					'failed'         => __( 'Optimization stopped because a request failed.', 'yoohw-media-optimizer' ),
					'empty'          => __( 'No supported media found.', 'yoohw-media-optimizer' ),
					'start'          => __( 'Start optimizing', 'yoohw-media-optimizer' ),
					'runningButton'  => __( 'Optimizing...', 'yoohw-media-optimizer' ),
					'finishing'      => __( 'Finishing media optimization...', 'yoohw-media-optimizer' ),
					'currentBatch'   => __( 'Processing the next group of images...', 'yoohw-media-optimizer' ),
					'waiting'        => __( 'Waiting to start', 'yoohw-media-optimizer' ),
					'notAvailable'   => __( 'Not available yet', 'yoohw-media-optimizer' ),
					'testingDelivery' => __( 'Testing delivery...', 'yoohw-media-optimizer' ),
					'deliveryFailed'  => __( 'Delivery test failed.', 'yoohw-media-optimizer' ),
					'notTested'       => __( 'Not tested', 'yoohw-media-optimizer' ),
					'ok'              => __( 'OK', 'yoohw-media-optimizer' ),
					'unknownContentType' => __( 'unknown content type', 'yoohw-media-optimizer' ),
					'unavailable'     => __( 'Unavailable', 'yoohw-media-optimizer' ),
					'notDetected'     => __( 'not detected', 'yoohw-media-optimizer' ),
					'sampleLabel'     => __( 'Sample', 'yoohw-media-optimizer' ),
					'directAvifLabel' => __( 'Direct AVIF', 'yoohw-media-optimizer' ),
					'directWebpLabel' => __( 'Direct WebP', 'yoohw-media-optimizer' ),
					'rewriteAvifLabel' => __( 'Rewrite (AVIF preferred)', 'yoohw-media-optimizer' ),
					'rewriteWebpLabel' => __( 'Rewrite (WebP only)', 'yoohw-media-optimizer' ),
					'originalFallback' => __( 'Original fallback', 'yoohw-media-optimizer' ),
					'avifSourceLabel' => __( 'AVIF picture source', 'yoohw-media-optimizer' ),
					'webpSourceLabel' => __( 'WebP picture source', 'yoohw-media-optimizer' ),
					'htmlModeLabel'   => __( 'HTML delivery mode', 'yoohw-media-optimizer' ),
					'pictureAvailable' => __( 'Available as a browser-native picture source', 'yoohw-media-optimizer' ),
					'pictureUnavailable' => __( 'No fresh sidecar is available for this format', 'yoohw-media-optimizer' ),
					'enabled'         => __( 'Enabled', 'yoohw-media-optimizer' ),
					'generateOnly'    => __( 'Generate only', 'yoohw-media-optimizer' ),
					/* translators: 1: processed attachments, 2: total attachments. */
					'processedOf'     => __( 'Processed %1$s of %2$s attachments', 'yoohw-media-optimizer' ),
					/* translators: 1: attachments processed in this batch, 2: total processed attachments, 3: total attachments. */
					'processedBatch'  => __( 'Processed %1$s attachment(s). Total %2$s / %3$s.', 'yoohw-media-optimizer' ),
					'secondsShort'    => _x( 's', 'seconds abbreviation', 'yoohw-media-optimizer' ),
					'minutesShort'    => _x( 'm', 'minutes abbreviation', 'yoohw-media-optimizer' ),
					'perMinute'       => __( '/min', 'yoohw-media-optimizer' ),
				),
			)
		);
	}

	/**
	 * Replace the admin footer text on the plugin page.
	 *
	 * @param string $text Existing footer text.
	 * @return string
	 */
	public function filter_admin_footer_text( $text ) {
		if ( ! $this->is_optimizer_admin_screen() ) {
			return $text;
		}

		return sprintf(
			/* translators: %s: YoOhw Studio website link. */
			esc_html__( 'Thank you for using YoOhw Media Optimizer by %s.', 'yoohw-media-optimizer' ),
			'<a href="' . esc_url( 'https://yoohw.com' ) . '" target="_blank" rel="noopener noreferrer">YoOhw Studio</a>'
		);
	}

	/**
	 * Replace the right-side admin footer version on the plugin page.
	 *
	 * @param string $text Existing footer version text.
	 * @return string
	 */
	public function filter_update_footer( $text ) {
		if ( ! $this->is_optimizer_admin_screen() ) {
			return $text;
		}

		return sprintf(
			/* translators: %s: plugin version. */
			esc_html__( 'Version %s', 'yoohw-media-optimizer' ),
			esc_html( YOOHW_MEDIA_OPTIMIZER_VERSION )
		);
	}

	/**
	 * Whether the current admin screen is the Media Optimizer page.
	 *
	 * @return bool
	 */
	private function is_optimizer_admin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && ! empty( $this->admin_hook ) && $screen->id === $this->admin_hook;
	}

	/**
	 * Get sanitized options.
	 *
	 * @return array
	 */
	public function get_options() {
		$options = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::default_options() );

		$options['auto_optimize_uploads']  = empty( $options['auto_optimize_uploads'] ) ? 0 : 1;
		$options['generate_webp_sidecars'] = empty( $options['generate_webp_sidecars'] ) ? 0 : 1;
		$options['generate_avif_sidecars'] = empty( $options['generate_avif_sidecars'] ) ? 0 : 1;
		$options['optimize_originals']     = empty( $options['optimize_originals'] ) ? 0 : 1;
		$options['backup_originals']       = empty( $options['backup_originals'] ) ? 0 : 1;
		$options['use_external_binaries']  = empty( $options['use_external_binaries'] ) ? 0 : 1;
		$options['skip_larger_files']      = empty( $options['skip_larger_files'] ) ? 0 : 1;
		$options['quality']                = max( 1, min( 100, absint( $options['quality'] ) ) );
		// Keep absent codec qualities tied to the sanitized legacy value without a migration.
		$options['webp_quality']           = max( 1, min( 100, absint( $options['webp_quality'] ?? $options['quality'] ) ) );
		$options['avif_quality']           = max( 1, min( 100, absint( $options['avif_quality'] ?? $options['quality'] ) ) );
		$options['webp_png_mode']          = in_array( $options['webp_png_mode'], array( 'lossy', 'lossless' ), true ) ? $options['webp_png_mode'] : 'lossy';
		$options['avif_alpha_mode']        = in_array( $options['avif_alpha_mode'], array( 'default', 'lossless' ), true ) ? $options['avif_alpha_mode'] : 'default';
		$options['avif_imagick_chroma']    = in_array( $options['avif_imagick_chroma'], array( 'default', '444' ), true ) ? $options['avif_imagick_chroma'] : 'default';
		$options['jpeg_quality']           = max( 1, min( 100, absint( $options['jpeg_quality'] ) ) );
		$options['max_width']              = max( 0, absint( $options['max_width'] ) );
		$options['max_height']             = max( 0, absint( $options['max_height'] ) );
		$options['compression_mode']       = in_array( $options['compression_mode'], array( 'lossless', 'balanced', 'aggressive', 'custom' ), true ) ? $options['compression_mode'] : 'balanced';
		if ( in_array( $options['metadata_policy'], array( 'preserve_copyright', 'preserve_camera_date' ), true ) ) {
			$options['metadata_policy'] = 'preserve_all';
		}

		$options['metadata_policy']        = in_array( $options['metadata_policy'], array( 'remove', 'preserve_all' ), true ) ? $options['metadata_policy'] : 'remove';
		$options['delivery_mode']          = in_array( $options['delivery_mode'], array( self::DELIVERY_GENERATE_ONLY, self::DELIVERY_HTML ), true ) ? $options['delivery_mode'] : self::DELIVERY_GENERATE_ONLY;

		if ( empty( $options['generate_webp_sidecars'] ) && empty( $options['generate_avif_sidecars'] ) && self::DELIVERY_HTML === $options['delivery_mode'] ) {
			$options['delivery_mode'] = self::DELIVERY_GENERATE_ONLY;
		}

		return $options;
	}

	/**
	 * Wrap WordPress attachment image HTML in a browser-native picture fallback.
	 *
	 * @param string       $html Attachment image HTML.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size Requested image size.
	 * @param bool         $icon Whether the image is an icon.
	 * @param array        $attr Image attributes.
	 * @return string
	 */
	public function filter_attachment_image_html( $html, $attachment_id, $size, $icon, $attr ) {
		unset( $attachment_id, $size, $attr );

		if ( $icon || ! $this->should_deliver_modern_images() ) {
			return $html;
		}

		return $this->build_picture_html( $html );
	}

	/**
	 * Wrap classic content images in picture markup with an original fallback.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content_images( $content ) {
		if ( ! $content || ! $this->should_deliver_modern_images() || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $content;
		}

		$updated = preg_replace_callback(
			'/<picture\b[^>]*>.*?<\/picture>|<img\b[^>]*>/is',
			function( $matches ) {
				$html = $matches[0];

				if ( 0 === stripos( ltrim( $html ), '<picture' ) ) {
					return $html;
				}

				return $this->build_picture_html( $html );
			},
			$content
		);

		return is_string( $updated ) ? $updated : $content;
	}

	/**
	 * Build picture markup for one image tag when matching sidecars exist.
	 *
	 * @param string $image_html Image HTML.
	 * @return string
	 */
	private function build_picture_html( $image_html ) {
		if ( ! $image_html || ! class_exists( 'WP_HTML_Tag_Processor' ) || false !== stripos( $image_html, 'data-yoohw-mo-picture' ) ) {
			return $image_html;
		}

		$processor = new WP_HTML_Tag_Processor( $image_html );

		if ( ! $processor->next_tag( 'img' ) ) {
			return $image_html;
		}

		$src    = $processor->get_attribute( 'src' );
		$srcset = $processor->get_attribute( 'srcset' );
		$sizes  = $processor->get_attribute( 'sizes' );

		if ( ! is_string( $src ) || ! $this->upload_url_to_path( $src ) ) {
			return $image_html;
		}

		$sources = array();

		foreach ( $this->enabled_delivery_formats() as $format ) {
			$modern_srcset = is_string( $srcset ) && '' !== $srcset
				? $this->replace_srcset_with_format_urls( $srcset, $format )
				: $this->format_sidecar_url( $src, $format );

			if ( ! $modern_srcset ) {
				continue;
			}

			$source = '<source type="image/' . esc_attr( $format ) . '" srcset="' . esc_attr( $modern_srcset ) . '"';

			if ( is_string( $sizes ) && '' !== $sizes ) {
				$source .= ' sizes="' . esc_attr( $sizes ) . '"';
			}

			$sources[] = $source . '>';
		}

		if ( empty( $sources ) ) {
			return $image_html;
		}

		return '<picture data-yoohw-mo-picture="1">' . implode( '', $sources ) . $image_html . '</picture>';
	}

	/**
	 * Whether modern image delivery should run on the current request.
	 *
	 * @return bool
	 */
	private function should_deliver_modern_images() {
		$options = $this->get_options();

		if ( self::DELIVERY_HTML !== $options['delivery_mode'] ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		return ! empty( $this->enabled_delivery_formats() );
	}

	/**
	 * Get enabled modern formats in browser preference order.
	 *
	 * @return array
	 */
	private function enabled_delivery_formats() {
		$options = $this->get_options();
		$formats = array();

		if ( ! empty( $options['generate_avif_sidecars'] ) ) {
			$formats[] = 'avif';
		}

		if ( ! empty( $options['generate_webp_sidecars'] ) ) {
			$formats[] = 'webp';
		}

		return $formats;
	}

	/**
	 * Optimize newly generated attachment metadata.
	 *
	 * @param array $metadata Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function optimize_generated_metadata( $metadata, $attachment_id ) {
		$options = $this->get_options();
		$attachment_id = absint( $attachment_id );

		if ( isset( $this->metadata_refreshing[ $attachment_id ] ) || empty( $options['auto_optimize_uploads'] ) || ! is_array( $metadata ) ) {
			return $metadata;
		}

		$metadata_hash = $attachment_id . ':' . md5( wp_json_encode( $metadata ) );

		if ( isset( $this->optimized_metadata_hashes[ $metadata_hash ] ) ) {
			return $metadata;
		}

		$this->optimized_metadata_hashes[ $metadata_hash ] = true;

		$result = $this->optimize_attachment( (int) $attachment_id, false, $metadata );

		if ( ! empty( $result['metadata'] ) && is_array( $result['metadata'] ) ) {
			return $result['metadata'];
		}

		return $metadata;
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ) );
		}

		$options = $this->get_options();
		$summary = $this->scan_library( 2000, 0 );
		$savings = $this->savings_report();
		$queue   = $this->queue_status();
		$progress = $this->browser_queue_progress();
		$tab     = $this->current_admin_tab();
		?>
		<div class="wrap yoohw-mo-wrap">
			<div class="yoohw-mo-page-header">
				<div>
					<h1><?php esc_html_e( 'Media Optimizer', 'yoohw-media-optimizer' ); ?></h1>
					<p class="yoohw-mo-lead"><?php esc_html_e( 'Generate modern image sidecars and optionally optimize original JPEG/PNG files with backups, resize limits, metadata policy, and restore controls.', 'yoohw-media-optimizer' ); ?></p>
				</div>
				<div class="yoohw-mo-header-badges">
					<span class="yoohw-mo-badge <?php echo $this->webp_generation_supported() ? 'is-ok' : 'is-error'; ?>">
						<?php echo $this->webp_generation_supported() ? esc_html__( 'WebP ready', 'yoohw-media-optimizer' ) : esc_html__( 'WebP unavailable', 'yoohw-media-optimizer' ); ?>
					</span>
					<span class="yoohw-mo-badge <?php echo self::DELIVERY_HTML === $options['delivery_mode'] ? 'is-ok' : 'is-warning'; ?>">
						<?php echo self::DELIVERY_HTML === $options['delivery_mode'] ? esc_html__( 'HTML delivery', 'yoohw-media-optimizer' ) : esc_html__( 'Generate only', 'yoohw-media-optimizer' ); ?>
					</span>
				</div>
			</div>

			<?php $this->render_notices(); ?>
			<?php $this->render_page_tabs( $tab ); ?>

			<div class="yoohw-mo-grid yoohw-mo-tab-panel">
				<section class="yoohw-mo-card yoohw-mo-card-wide" <?php echo 'overview' === $tab ? '' : 'hidden'; ?>>
					<div class="yoohw-mo-card-head">
						<div>
							<p class="yoohw-mo-kicker"><?php esc_html_e( 'Media library', 'yoohw-media-optimizer' ); ?></p>
							<h2><?php esc_html_e( 'Optimization status', 'yoohw-media-optimizer' ); ?></h2>
						</div>
						<span class="yoohw-mo-badge <?php echo $this->webp_generation_supported() ? 'is-ok' : 'is-error'; ?>">
							<?php echo $this->webp_generation_supported() ? esc_html__( 'WebP supported', 'yoohw-media-optimizer' ) : esc_html__( 'WebP unavailable', 'yoohw-media-optimizer' ); ?>
						</span>
					</div>

					<?php $this->render_overview_dashboard( $summary, $savings ); ?>
					<?php if ( ! empty( $summary['truncated'] ) ) : ?>
						<p class="yoohw-mo-muted"><?php esc_html_e( 'The dashboard scan was capped for speed. Use WP-CLI for a full library report on very large sites.', 'yoohw-media-optimizer' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="yoohw-mo-card yoohw-mo-card-wide" <?php echo 'delivery' === $tab ? '' : 'hidden'; ?>>
					<div class="yoohw-mo-card-head">
						<div>
							<p class="yoohw-mo-kicker"><?php esc_html_e( 'Delivery assistant', 'yoohw-media-optimizer' ); ?></p>
							<h2><?php esc_html_e( 'Confirm modern image delivery on the frontend', 'yoohw-media-optimizer' ); ?></h2>
						</div>
						<span class="yoohw-mo-badge <?php echo self::DELIVERY_HTML === $options['delivery_mode'] ? 'is-ok' : 'is-warning'; ?>">
							<?php echo self::DELIVERY_HTML === $options['delivery_mode'] ? esc_html__( 'HTML delivery enabled', 'yoohw-media-optimizer' ) : esc_html__( 'Generate only', 'yoohw-media-optimizer' ); ?>
						</span>
					</div>
					<?php $this->render_delivery_assistant( $options, $summary ); ?>
				</section>

				<section class="yoohw-mo-card yoohw-mo-card-wide" <?php echo 'optimize' === $tab ? '' : 'hidden'; ?>>
					<h2><?php esc_html_e( 'Optimize media library', 'yoohw-media-optimizer' ); ?></h2>
					<p class="yoohw-mo-muted"><?php esc_html_e( 'Processes existing uploads with automatic batching, live progress, and resumable pause controls.', 'yoohw-media-optimizer' ); ?></p>
					<?php $this->render_batch_form( $progress ); ?>
				</section>

				<section class="yoohw-mo-card yoohw-mo-card-wide" <?php echo 'settings' === $tab ? '' : 'hidden'; ?>>
					<h2><?php esc_html_e( 'Settings', 'yoohw-media-optimizer' ); ?></h2>
					<?php $this->render_settings_form( $options ); ?>
				</section>

				<section class="yoohw-mo-card yoohw-mo-card-wide" <?php echo 'optimize' === $tab ? '' : 'hidden'; ?>>
					<h2><?php esc_html_e( 'Queue', 'yoohw-media-optimizer' ); ?></h2>
					<p class="yoohw-mo-muted"><?php esc_html_e( 'Build a resumable attachment queue for heavier original-file optimization or WP-CLI processing.', 'yoohw-media-optimizer' ); ?></p>
					<?php $this->render_queue_controls( $queue ); ?>
				</section>

				<section class="yoohw-mo-card yoohw-mo-card-wide" <?php echo 'maintenance' === $tab ? '' : 'hidden'; ?>>
					<h2><?php esc_html_e( 'Cleanup and restore', 'yoohw-media-optimizer' ); ?></h2>
					<p class="yoohw-mo-muted"><?php esc_html_e( 'Remove generated sidecars or restore original image files from optimizer backups.', 'yoohw-media-optimizer' ); ?></p>
					<?php $this->render_cleanup_form(); ?>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the active admin tab.
	 *
	 * @return string
	 */
	private function current_admin_tab() {
		$tab = sanitize_key( $this->get_query_arg( 'tab' ) );

		if ( ! in_array( $tab, array_keys( $this->admin_tabs() ), true ) ) {
			return 'overview';
		}

		return $tab;
	}

	/**
	 * Admin tabs.
	 *
	 * @return array
	 */
	private function admin_tabs() {
		return array(
			'overview'    => __( 'Overview', 'yoohw-media-optimizer' ),
			'optimize'    => __( 'Optimize', 'yoohw-media-optimizer' ),
			'delivery'    => __( 'Delivery', 'yoohw-media-optimizer' ),
			'settings'    => __( 'Settings', 'yoohw-media-optimizer' ),
			'maintenance' => __( 'Maintenance', 'yoohw-media-optimizer' ),
		);
	}

	/**
	 * Render WooCommerce-style admin tabs.
	 *
	 * @param string $active_tab Active tab key.
	 * @return void
	 */
	private function render_page_tabs( $active_tab ) {
		?>
		<nav class="nav-tab-wrapper woo-nav-tab-wrapper yoohw-mo-tabs" aria-label="<?php esc_attr_e( 'Media Optimizer sections', 'yoohw-media-optimizer' ); ?>">
			<?php foreach ( $this->admin_tabs() as $tab => $label ) : ?>
				<a class="nav-tab <?php echo $tab === $active_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $tab, $this->admin_page_url() ) ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		$options = $this->get_options();

		if ( ! empty( $options['generate_webp_sidecars'] ) && ! $this->webp_generation_supported() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No WebP engine is available. Enable GD/Imagick WebP support or install cwebp before generating WebP sidecars.', 'yoohw-media-optimizer' ) . '</p></div>';
		}

		$notice = sanitize_key( $this->get_query_arg( 'yoohw_mo_notice' ) );

		if ( ! $notice ) {
			return;
		}

		if ( 'settings_saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Media optimizer settings saved.', 'yoohw-media-optimizer' ) . '</p></div>';
			return;
		}

		if ( 'batch_done' === $notice ) {
			$processed = absint( $this->get_query_arg( 'processed' ) );
			$created   = absint( $this->get_query_arg( 'created' ) );
			$existing  = absint( $this->get_query_arg( 'existing' ) );
			$skipped   = absint( $this->get_query_arg( 'skipped' ) );
			$failed    = absint( $this->get_query_arg( 'failed' ) );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: processed attachments, 2: created files, 3: existing files, 4: skipped files, 5: failed files. */
						__( 'Batch complete. Attachments: %1$d. Created/updated modern files: %2$d. Existing: %3$d. Skipped larger: %4$d. Failed: %5$d.', 'yoohw-media-optimizer' ),
						$processed,
						$created,
						$existing,
						$skipped,
						$failed
					)
				)
			);
		}

		if ( 'cleanup_done' === $notice ) {
			$deleted = absint( $this->get_query_arg( 'deleted' ) );
			$failed  = absint( $this->get_query_arg( 'failed' ) );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: deleted files, 2: failed files. */
						__( 'Cleanup complete. Deleted generated sidecars: %1$d. Failed: %2$d.', 'yoohw-media-optimizer' ),
						$deleted,
						$failed
					)
				)
			);
		}

		if ( 'restore_done' === $notice ) {
			$restored = absint( $this->get_query_arg( 'restored' ) );
			$failed   = absint( $this->get_query_arg( 'failed' ) );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: restored files, 2: failed files. */
						__( 'Restore complete. Restored files: %1$d. Failed: %2$d.', 'yoohw-media-optimizer' ),
						$restored,
						$failed
					)
				)
			);
		}

		if ( 'queue_built' === $notice ) {
			$count = absint( $this->get_query_arg( 'queued' ) );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: queued attachments. */
						__( 'Optimization queue built with %d attachment(s).', 'yoohw-media-optimizer' ),
						$count
					)
				)
			);
		}

		if ( 'queue_done' === $notice ) {
			$processed = absint( $this->get_query_arg( 'processed' ) );
			$failed    = absint( $this->get_query_arg( 'failed' ) );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: processed attachments, 2: failed attachments. */
						__( 'Queue batch complete. Processed: %1$d. Failed attachments: %2$d.', 'yoohw-media-optimizer' ),
						$processed,
						$failed
					)
				)
			);
		}

		if ( 'queue_locked' === $notice ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Another optimization queue worker is already running.', 'yoohw-media-optimizer' ) . '</p></div>';
		}
	}

	/**
	 * Render notices on the Media Library screen for bulk actions.
	 *
	 * @return void
	 */
	public function render_media_admin_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'upload' !== $screen->base ) {
			return;
		}

		$notice = sanitize_key( $this->get_query_arg( 'yoohw_mo_notice' ) );

		if ( 'restore_done' !== $notice ) {
			return;
		}

		$restored = absint( $this->get_query_arg( 'restored' ) );
		$failed   = absint( $this->get_query_arg( 'failed' ) );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: restored files, 2: failed files. */
					__( 'YoOhw restore complete. Restored files: %1$d. Failed: %2$d.', 'yoohw-media-optimizer' ),
					$restored,
					$failed
				)
			)
		);
	}

	/**
	 * Add Media Library columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_media_library_columns( $columns ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return $columns;
		}

		$columns['yoohw_mo_savings'] = __( 'Savings', 'yoohw-media-optimizer' );

		return $columns;
	}

	/**
	 * Render Media Library column content.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Attachment ID.
	 * @return void
	 */
	public function render_media_library_column( $column, $post_id ) {
		if ( 'yoohw_mo_savings' !== $column || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! $this->is_supported_attachment( $post_id ) ) {
			echo '<span class="yoohw-mo-muted">-</span>';
			return;
		}

		$savings = $this->attachment_savings( $post_id );
		$saved   = absint( $savings['saved_bytes'] ?? 0 );
		$backups = absint( $savings['backups'] ?? 0 );

		echo '<div class="yoohw-mo-media-cell">';

		if ( $saved > 0 ) {
			printf(
				'<div class="yoohw-mo-media-savings-main"><strong>%1$s</strong><span>%2$s%%</span></div>',
				esc_html( size_format( $saved, 1 ) ),
				esc_html( number_format_i18n( (float) ( $savings['saved_percent'] ?? 0 ), 1 ) )
			);
		} else {
			echo '<div class="yoohw-mo-media-savings-main is-empty"><strong>' . esc_html__( 'No savings yet', 'yoohw-media-optimizer' ) . '</strong></div>';
		}

		echo '<div class="yoohw-mo-media-savings-actions">';

		if ( ! empty( $savings['has_failures'] ) ) {
			echo '<em class="yoohw-mo-mini-status is-error">' . esc_html__( 'Failed', 'yoohw-media-optimizer' ) . '</em>';
		} elseif ( $saved > 0 ) {
			echo '<em class="yoohw-mo-mini-status is-ok">' . esc_html__( 'Optimized', 'yoohw-media-optimizer' ) . '</em>';
		} else {
			echo '<em class="yoohw-mo-mini-status">' . esc_html__( 'Pending', 'yoohw-media-optimizer' ) . '</em>';
		}

		if ( $backups > 0 && current_user_can( self::CAPABILITY ) ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'        => 'yoohw_mo_restore_attachment',
						'attachment_id' => absint( $post_id ),
					),
					admin_url( 'admin-post.php' )
				),
				'yoohw_mo_restore_attachment_' . absint( $post_id )
			);

			echo '<a class="button button-small yoohw-mo-restore-button" href="' . esc_url( $url ) . '">' . esc_html__( 'Restore', 'yoohw-media-optimizer' ) . '</a>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Register Media Library bulk actions.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function register_media_bulk_actions( $actions ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return $actions;
		}

		$actions['yoohw_mo_restore'] = __( 'Restore YoOhw optimized originals', 'yoohw-media-optimizer' );

		return $actions;
	}

	/**
	 * Handle Media Library bulk actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction Action.
	 * @param array  $post_ids Attachment IDs.
	 * @return string
	 */
	public function handle_media_bulk_action( $redirect_to, $doaction, $post_ids ) {
		if ( 'yoohw_mo_restore' !== $doaction ) {
			return $redirect_to;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return $redirect_to;
		}

		$result = array(
			'restored' => 0,
			'failed'   => 0,
		);

		foreach ( (array) $post_ids as $post_id ) {
			$restored = $this->restore_attachment( absint( $post_id ) );
			$this->require_admin_mutation_result( $restored );

			$result['restored'] += absint( $restored['restored'] ?? 0 );
			$result['failed']   += absint( $restored['failed'] ?? 0 );
		}

		return add_query_arg(
			array(
				'yoohw_mo_notice' => 'restore_done',
				'restored'        => $result['restored'],
				'failed'          => $result['failed'],
			),
			$redirect_to
		);
	}

	/**
	 * Render the overview dashboard.
	 *
	 * @param array $summary Scan summary.
	 * @param array $savings Savings report.
	 * @return void
	 */
	private function render_overview_dashboard( $summary, $savings ) {
		$files              = absint( $summary['files'] ?? 0 );
		$webp_ready         = absint( $summary['optimized'] ?? 0 );
		$webp_coverage      = $files > 0 ? ( $webp_ready / $files ) * 100 : 0;
		$storage_impact     = (int) ( $savings['storage_impact_bytes'] ?? 0 );
		$storage_tone       = $storage_impact > 0 ? 'is-warning' : 'is-ok';
		if ( $storage_impact > 0 ) {
			$storage_note = __( 'Extra storage while sidecars/backups are kept', 'yoohw-media-optimizer' );
		} elseif ( $storage_impact < 0 ) {
			$storage_note = __( 'Net disk reduction after sidecars/backups', 'yoohw-media-optimizer' );
		} else {
			$storage_note = __( 'Storage is neutral after sidecars/backups', 'yoohw-media-optimizer' );
		}
		$bandwidth_basis    = absint( $savings['source_bytes'] ?? 0 ) + absint( $savings['original_saved_bytes'] ?? 0 );
		$transfer_max       = max(
			1,
			$bandwidth_basis,
			absint( $savings['source_bytes'] ?? 0 ),
			absint( $savings['delivery_bytes'] ?? 0 ),
			absint( $savings['webp_bytes'] ?? 0 ),
			absint( $savings['avif_bytes'] ?? 0 )
		);
		$storage_chart_max  = max(
			1,
			absint( $savings['storage_removed_bytes'] ?? 0 ),
			absint( $savings['modern_sidecar_bytes'] ?? 0 ),
			absint( $savings['backup_bytes'] ?? 0 ),
			abs( $storage_impact )
		);
		?>
		<div class="yoohw-mo-overview-summary">
			<div class="yoohw-mo-metric-panel is-bandwidth">
				<div class="yoohw-mo-metric-head">
					<span class="dashicons dashicons-performance" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Estimated bandwidth savings', 'yoohw-media-optimizer' ); ?></span>
				</div>
				<strong><?php echo esc_html( size_format( absint( $savings['saved_bytes'] ?? 0 ), 2 ) ); ?></strong>
				<em>
					<?php
					printf(
						/* translators: %s: reduction percentage. */
						esc_html__( '%s reduction when modern sidecars are served', 'yoohw-media-optimizer' ),
						esc_html( number_format_i18n( (float) ( $savings['saved_percent'] ?? 0 ), 1 ) . '%' )
					);
					?>
				</em>
			</div>

			<div class="yoohw-mo-metric-panel is-storage <?php echo esc_attr( $storage_tone ); ?>">
				<div class="yoohw-mo-metric-head">
					<span class="dashicons dashicons-database" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Storage impact', 'yoohw-media-optimizer' ); ?></span>
				</div>
				<strong><?php echo esc_html( $this->signed_size_format( $storage_impact ) ); ?></strong>
				<em><?php echo esc_html( $storage_note ); ?></em>
			</div>

			<div class="yoohw-mo-metric-panel is-coverage">
				<div class="yoohw-mo-metric-head">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<span><?php esc_html_e( 'WebP coverage', 'yoohw-media-optimizer' ); ?></span>
				</div>
				<div class="yoohw-mo-ring" style="<?php echo esc_attr( '--yoohw-mo-value: ' . min( 100, max( 0, $webp_coverage ) ) . '%;' ); ?>">
					<span><?php echo esc_html( number_format_i18n( $webp_coverage, 0 ) ); ?>%</span>
				</div>
				<em>
					<?php
					printf(
						/* translators: 1: optimized files, 2: total files. */
						esc_html__( '%1$s of %2$s files ready', 'yoohw-media-optimizer' ),
						esc_html( number_format_i18n( $webp_ready ) ),
						esc_html( number_format_i18n( $files ) )
					);
					?>
				</em>
			</div>
		</div>

		<div class="yoohw-mo-overview-charts">
			<div class="yoohw-mo-chart-card">
				<h3><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><?php esc_html_e( 'Transfer size', 'yoohw-media-optimizer' ); ?></h3>
				<?php
				$this->render_overview_chart_row( __( 'Original basis', 'yoohw-media-optimizer' ), $bandwidth_basis, $transfer_max, 'is-original' );
				$this->render_overview_chart_row( __( 'Current originals', 'yoohw-media-optimizer' ), absint( $savings['source_bytes'] ?? 0 ), $transfer_max, 'is-current' );
				$this->render_overview_chart_row( __( 'Modern delivered', 'yoohw-media-optimizer' ), absint( $savings['delivery_bytes'] ?? 0 ), $transfer_max, 'is-webp' );
				$this->render_overview_chart_row( __( 'WebP total', 'yoohw-media-optimizer' ), absint( $savings['webp_bytes'] ?? 0 ), $transfer_max, 'is-current' );
				$this->render_overview_chart_row( __( 'AVIF total', 'yoohw-media-optimizer' ), absint( $savings['avif_bytes'] ?? 0 ), $transfer_max, 'is-saved' );
				?>
			</div>

			<div class="yoohw-mo-chart-card">
				<h3><span class="dashicons dashicons-database" aria-hidden="true"></span><?php esc_html_e( 'Server storage', 'yoohw-media-optimizer' ); ?></h3>
				<?php
				$this->render_overview_chart_row( __( 'Disk saved from originals', 'yoohw-media-optimizer' ), absint( $savings['storage_removed_bytes'] ?? 0 ), $storage_chart_max, 'is-saved' );
				$this->render_overview_chart_row( __( 'Modern sidecars added', 'yoohw-media-optimizer' ), absint( $savings['modern_sidecar_bytes'] ?? 0 ), $storage_chart_max, 'is-added' );
				$this->render_overview_chart_row( __( 'Backups held', 'yoohw-media-optimizer' ), absint( $savings['backup_bytes'] ?? 0 ), $storage_chart_max, 'is-backup' );
				$this->render_overview_chart_row( __( 'Net storage impact', 'yoohw-media-optimizer' ), abs( $storage_impact ), $storage_chart_max, $storage_impact > 0 ? 'is-net-positive' : 'is-net-negative', $this->signed_size_format( $storage_impact ) );
				?>
			</div>
		</div>

		<?php $this->render_status_cards( $summary ); ?>
		<?php
	}

	/**
	 * Render one overview chart row.
	 *
	 * @param string      $label Row label.
	 * @param int         $value Byte value.
	 * @param int         $max Max byte value for scaling.
	 * @param string      $class Extra class.
	 * @param string|null $display_value Optional display value.
	 * @return void
	 */
	private function render_overview_chart_row( $label, $value, $max, $class = '', $display_value = null ) {
		$value = absint( $value );
		$max   = max( 1, absint( $max ) );
		$width = $value > 0 ? min( 100, max( 3, ( $value / $max ) * 100 ) ) : 0;

		if ( null === $display_value ) {
			$display_value = size_format( $value, 2 );
		}
		?>
		<div class="yoohw-mo-chart-row <?php echo esc_attr( $class ); ?>">
			<div class="yoohw-mo-chart-label">
				<span><?php echo esc_html( $label ); ?></span>
				<strong><?php echo esc_html( $display_value ); ?></strong>
			</div>
			<div class="yoohw-mo-chart-track" aria-hidden="true">
				<span style="<?php echo esc_attr( '--yoohw-mo-value: ' . $width . '%;' ); ?>"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Format a byte value with a plus/minus prefix.
	 *
	 * @param int $bytes Byte value.
	 * @return string
	 */
	private function signed_size_format( $bytes ) {
		$bytes = (int) $bytes;

		if ( 0 === $bytes ) {
			return size_format( 0, 2 );
		}

		return ( $bytes > 0 ? '+' : '-' ) . size_format( abs( $bytes ), 2 );
	}

	/**
	 * Render status cards.
	 *
	 * @param array $summary Scan summary.
	 * @return void
	 */
	private function render_status_cards( $summary ) {
		$cards = array(
			'attachments'        => array(
				'label' => __( 'Supported attachments', 'yoohw-media-optimizer' ),
				'icon'  => 'dashicons-format-gallery',
			),
			'files'              => array(
				'label' => __( 'Image files scanned', 'yoohw-media-optimizer' ),
				'icon'  => 'dashicons-images-alt2',
			),
			'optimized'          => array(
				'label' => __( 'WebP ready', 'yoohw-media-optimizer' ),
				'icon'  => 'dashicons-yes-alt',
				'tone'  => 'is-ok',
			),
			'missing'            => array(
				'label' => __( 'Missing WebP', 'yoohw-media-optimizer' ),
				'icon'  => 'dashicons-warning',
				'tone'  => 'is-warning',
			),
			'stale'              => array(
				'label' => __( 'Stale WebP', 'yoohw-media-optimizer' ),
				'icon'  => 'dashicons-update',
				'tone'  => 'is-warning',
			),
			'original_optimized' => array(
				'label' => __( 'Originals optimized', 'yoohw-media-optimizer' ),
				'icon'  => 'dashicons-admin-tools',
			),
			'backed_up'          => array(
				'label' => __( 'Backups tracked', 'yoohw-media-optimizer' ),
				'icon'  => 'dashicons-backup',
			),
			'failed'             => array(
				'label' => __( 'Tracked failures', 'yoohw-media-optimizer' ),
				'icon'  => 'dashicons-dismiss',
				'tone'  => 'is-error',
			),
		);
		?>
		<div class="yoohw-mo-status-grid">
			<?php foreach ( $cards as $key => $card ) : ?>
				<div class="yoohw-mo-status-card <?php echo esc_attr( $card['tone'] ?? '' ); ?>">
					<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>" aria-hidden="true"></span>
					<em><?php echo esc_html( $card['label'] ); ?></em>
					<strong><?php echo esc_html( number_format_i18n( absint( $summary[ $key ] ?? 0 ) ) ); ?></strong>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render delivery assistant.
	 *
	 * @param array $options Plugin options.
	 * @param array $summary Scan summary.
	 * @return void
	 */
	private function render_delivery_assistant( $options, $summary ) {
		$sample = $this->delivery_sample();
		$ready  = ! empty( $summary['optimized'] ) && empty( $summary['missing'] ) && empty( $summary['stale'] ) && empty( $summary['failed'] );
		?>
		<div class="yoohw-mo-delivery-grid">
			<div class="yoohw-mo-delivery-step">
				<span class="yoohw-mo-badge <?php echo $ready ? 'is-ok' : 'is-warning'; ?>"><?php echo $ready ? esc_html__( 'Ready', 'yoohw-media-optimizer' ) : esc_html__( 'Needs attention', 'yoohw-media-optimizer' ); ?></span>
				<h3><?php esc_html_e( '1. Generate', 'yoohw-media-optimizer' ); ?></h3>
				<p><?php esc_html_e( 'WebP and/or AVIF sidecars should exist for supported attachment files before delivery is enabled.', 'yoohw-media-optimizer' ); ?></p>
			</div>
			<div class="yoohw-mo-delivery-step">
				<span class="yoohw-mo-badge <?php echo self::DELIVERY_HTML === $options['delivery_mode'] ? 'is-ok' : 'is-warning'; ?>"><?php echo self::DELIVERY_HTML === $options['delivery_mode'] ? esc_html__( 'Enabled', 'yoohw-media-optimizer' ) : esc_html__( 'Manual', 'yoohw-media-optimizer' ); ?></span>
				<h3><?php esc_html_e( '2. Deliver', 'yoohw-media-optimizer' ); ?></h3>
				<p><?php esc_html_e( 'HTML delivery emits browser-native picture sources with the original JPEG/PNG kept as the fallback.', 'yoohw-media-optimizer' ); ?></p>
			</div>
			<div class="yoohw-mo-delivery-step">
				<span class="yoohw-mo-badge <?php echo $sample ? 'is-ok' : 'is-error'; ?>"><?php echo $sample ? esc_html__( 'Sample found', 'yoohw-media-optimizer' ) : esc_html__( 'No sample', 'yoohw-media-optimizer' ); ?></span>
				<h3><?php esc_html_e( '3. Test', 'yoohw-media-optimizer' ); ?></h3>
				<p><?php esc_html_e( 'Run a delivery test to check direct AVIF/WebP access and server rewrite behavior for one optimized image.', 'yoohw-media-optimizer' ); ?></p>
			</div>
		</div>

		<div class="yoohw-mo-delivery-test">
			<button type="button" class="button" data-yoohw-mo-test-delivery <?php disabled( ! $sample ); ?>><?php esc_html_e( 'Test delivery', 'yoohw-media-optimizer' ); ?></button>
			<div class="yoohw-mo-delivery-result" data-yoohw-mo-delivery-result>
				<?php if ( $sample ) : ?>
					<p><?php esc_html_e( 'Sample image:', 'yoohw-media-optimizer' ); ?> <code><?php echo esc_html( $sample['source'] ); ?></code></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Optimize at least one supported image before running a delivery test.', 'yoohw-media-optimizer' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<details class="yoohw-mo-delivery-rules">
			<summary><?php esc_html_e( 'Apache rewrite example for server/CDN delivery', 'yoohw-media-optimizer' ); ?></summary>
			<pre><code>&lt;IfModule mod_rewrite.c&gt;
RewriteEngine On
RewriteCond %{HTTP_ACCEPT} image/avif
RewriteCond %{REQUEST_FILENAME}.avif -f
RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.avif [T=image/avif,E=accept:1]

RewriteCond %{HTTP_ACCEPT} image/webp
RewriteCond %{REQUEST_FILENAME}.webp -f
RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [T=image/webp,E=accept:1]
&lt;/IfModule&gt;

&lt;IfModule mod_headers.c&gt;
Header append Vary Accept env=REDIRECT_accept
&lt;/IfModule&gt;</code></pre>
		</details>
		<?php
	}

	/**
	 * Render cleanup controls.
	 *
	 * @return void
	 */
	private function render_cleanup_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yoohw-mo-stacked-form">
			<input type="hidden" name="action" value="yoohw_mo_cleanup_sidecars">
			<?php wp_nonce_field( 'yoohw_mo_cleanup_sidecars' ); ?>

			<label class="yoohw-mo-check">
				<input type="checkbox" name="confirm_cleanup" value="1" required>
				<span><?php esc_html_e( 'I understand this deletes only generated sidecars, not original uploads or backups.', 'yoohw-media-optimizer' ); ?></span>
			</label>

			<label class="yoohw-mo-check">
				<input type="checkbox" name="delete_tracking" value="1">
				<span><?php esc_html_e( 'Also remove optimizer tracking metadata from attachments.', 'yoohw-media-optimizer' ); ?></span>
			</label>

			<button type="submit" class="button"><?php esc_html_e( 'Remove generated sidecars', 'yoohw-media-optimizer' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yoohw-mo-stacked-form">
			<input type="hidden" name="action" value="yoohw_mo_restore_backups">
			<?php wp_nonce_field( 'yoohw_mo_restore_backups' ); ?>

			<label class="yoohw-mo-check">
				<input type="checkbox" name="confirm_restore" value="1" required>
				<span><?php esc_html_e( 'I understand this restores backed up originals and removes generated sidecars for restored files.', 'yoohw-media-optimizer' ); ?></span>
			</label>

			<label class="yoohw-mo-field">
				<span><?php esc_html_e( 'Restore batch size', 'yoohw-media-optimizer' ); ?></span>
				<input type="number" name="limit" min="1" max="500" value="100">
				<em><?php esc_html_e( 'Use smaller batches on shared hosting.', 'yoohw-media-optimizer' ); ?></em>
			</label>

			<button type="submit" class="button"><?php esc_html_e( 'Restore backed up originals', 'yoohw-media-optimizer' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render persistent queue controls.
	 *
	 * @param array $queue Queue summary.
	 * @return void
	 */
	private function render_queue_controls( $queue ) {
		?>
		<div class="yoohw-mo-queue-summary">
			<span><?php esc_html_e( 'Pending', 'yoohw-media-optimizer' ); ?> <strong><?php echo esc_html( number_format_i18n( absint( $queue['pending'] ?? 0 ) ) ); ?></strong></span>
			<span><?php esc_html_e( 'Running', 'yoohw-media-optimizer' ); ?> <strong><?php echo esc_html( number_format_i18n( absint( $queue['running'] ?? 0 ) ) ); ?></strong></span>
			<span><?php esc_html_e( 'Done', 'yoohw-media-optimizer' ); ?> <strong><?php echo esc_html( number_format_i18n( absint( $queue['done'] ?? 0 ) ) ); ?></strong></span>
			<span><?php esc_html_e( 'Failed', 'yoohw-media-optimizer' ); ?> <strong><?php echo esc_html( number_format_i18n( absint( $queue['failed'] ?? 0 ) ) ); ?></strong></span>
		</div>

		<div class="yoohw-mo-queue-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yoohw-mo-inline-form">
				<input type="hidden" name="action" value="yoohw_mo_build_queue">
				<?php wp_nonce_field( 'yoohw_mo_build_queue' ); ?>
				<button type="submit" class="button">
					<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
					<?php esc_html_e( 'Build queue', 'yoohw-media-optimizer' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yoohw-mo-inline-form">
				<input type="hidden" name="action" value="yoohw_mo_process_queue">
				<input type="hidden" name="limit" value="<?php echo esc_attr( self::DEFAULT_LIMIT ); ?>">
				<?php wp_nonce_field( 'yoohw_mo_process_queue' ); ?>
				<button type="submit" class="button button-primary">
					<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
					<?php esc_html_e( 'Process queue batch', 'yoohw-media-optimizer' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render batch optimize form.
	 *
	 * @param array $progress Persisted queue progress.
	 * @return void
	 */
	private function render_batch_form( $progress ) {
		?>
		<form class="yoohw-mo-batch-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-yoohw-mo-batch-form>
			<input type="hidden" name="action" value="yoohw_mo_optimize_batch">
			<input type="hidden" name="limit" value="<?php echo esc_attr( self::UI_BATCH_LIMIT ); ?>">
			<input type="hidden" name="run_id" value="<?php echo esc_attr( $progress['runId'] ); ?>">
			<input type="hidden" name="start" value="<?php echo $progress['hasMore'] ? '0' : '1'; ?>">
			<?php wp_nonce_field( 'yoohw_mo_optimize_batch' ); ?>

			<div class="yoohw-mo-optimize-console">
				<div class="yoohw-mo-optimize-console-main">
					<span class="dashicons dashicons-images-alt2" aria-hidden="true"></span>
					<div>
						<h3><?php esc_html_e( 'Media library optimizer', 'yoohw-media-optimizer' ); ?></h3>
						<p><?php esc_html_e( 'Runs in small automatic batches and keeps the browser updated until the library is complete.', 'yoohw-media-optimizer' ); ?></p>
					</div>
				</div>

				<div class="yoohw-mo-batch-actions">
					<button type="submit" class="button button-primary button-hero" data-yoohw-mo-start <?php disabled( ! $this->can_run_any_optimization() ); ?>>
						<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
						<?php echo esc_html( $progress['hasMore'] ? __( 'Resume optimizing', 'yoohw-media-optimizer' ) : __( 'Start optimizing', 'yoohw-media-optimizer' ) ); ?>
					</button>
					<button type="button" class="button button-hero" data-yoohw-mo-stop hidden>
						<span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>
						<?php esc_html_e( 'Pause', 'yoohw-media-optimizer' ); ?>
					</button>
				</div>
			</div>

			<details class="yoohw-mo-advanced-options">
				<summary><?php esc_html_e( 'Advanced options', 'yoohw-media-optimizer' ); ?></summary>
				<label class="yoohw-mo-check">
					<input type="checkbox" name="force" value="1" <?php checked( ! empty( $progress['force'] ) ); ?> <?php disabled( ! empty( $progress['hasMore'] ) && ! empty( $progress['runId'] ) ); ?>>
					<span><?php esc_html_e( 'Force rebuild existing modern sidecars', 'yoohw-media-optimizer' ); ?></span>
				</label>
			</details>

			<div class="yoohw-mo-progress" data-yoohw-mo-progress <?php echo empty( $progress['runId'] ) ? 'hidden' : ''; ?> aria-live="polite">
				<div class="yoohw-mo-progress-head">
					<div class="yoohw-mo-progress-title">
						<span class="yoohw-mo-progress-pulse" aria-hidden="true"></span>
						<strong data-yoohw-mo-progress-title><?php esc_html_e( 'Ready to optimize', 'yoohw-media-optimizer' ); ?></strong>
					</div>
					<span data-yoohw-mo-progress-percent><?php echo esc_html( $progress['percent'] ); ?>%</span>
				</div>
				<div class="yoohw-mo-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $progress['percent'] ); ?>" data-yoohw-mo-progressbar>
					<span data-yoohw-mo-progress-fill style="width: <?php echo esc_attr( $progress['percent'] ); ?>%"></span>
				</div>
				<div class="yoohw-mo-progress-meta">
					<span data-yoohw-mo-current><?php esc_html_e( 'Waiting to start', 'yoohw-media-optimizer' ); ?></span>
					<span><?php esc_html_e( 'Elapsed', 'yoohw-media-optimizer' ); ?> <strong data-yoohw-mo-elapsed>0s</strong></span>
					<span><?php esc_html_e( 'ETA', 'yoohw-media-optimizer' ); ?> <strong data-yoohw-mo-eta>-</strong></span>
				</div>
				<div class="yoohw-mo-progress-stats">
					<span><?php esc_html_e( 'Processed', 'yoohw-media-optimizer' ); ?> <strong data-yoohw-mo-stat="processed"><?php echo esc_html( $progress['processedTotal'] ); ?></strong></span>
					<span><?php esc_html_e( 'Created/updated', 'yoohw-media-optimizer' ); ?> <strong data-yoohw-mo-stat="created"><?php echo esc_html( $progress['created'] ); ?></strong></span>
					<span><?php esc_html_e( 'Existing', 'yoohw-media-optimizer' ); ?> <strong data-yoohw-mo-stat="existing"><?php echo esc_html( $progress['existing'] ); ?></strong></span>
					<span><?php esc_html_e( 'Skipped larger', 'yoohw-media-optimizer' ); ?> <strong data-yoohw-mo-stat="skipped"><?php echo esc_html( $progress['skipped'] ); ?></strong></span>
					<span><?php esc_html_e( 'Originals optimized', 'yoohw-media-optimizer' ); ?> <strong data-yoohw-mo-stat="originalOptimized"><?php echo esc_html( $progress['originalOptimized'] ); ?></strong></span>
					<span><?php esc_html_e( 'Originals skipped', 'yoohw-media-optimizer' ); ?> <strong data-yoohw-mo-stat="originalSkipped"><?php echo esc_html( $progress['originalSkipped'] ); ?></strong></span>
					<span><?php esc_html_e( 'Failed', 'yoohw-media-optimizer' ); ?> <strong data-yoohw-mo-stat="failed"><?php echo esc_html( $progress['failed'] + $progress['originalFailed'] ); ?></strong></span>
					<span><?php esc_html_e( 'Rate', 'yoohw-media-optimizer' ); ?> <strong data-yoohw-mo-rate>-</strong></span>
				</div>
				<p class="yoohw-mo-progress-note" data-yoohw-mo-progress-note><?php echo esc_html( $progress['note'] ? $progress['note'] : __( 'The optimizer will continue automatically until all supported media is processed.', 'yoohw-media-optimizer' ) ); ?></p>
				<ul class="yoohw-mo-progress-log" data-yoohw-mo-progress-log></ul>
			</div>

			<noscript>
				<p class="yoohw-mo-muted"><?php esc_html_e( 'JavaScript is disabled, so this form will process one batch per submit.', 'yoohw-media-optimizer' ); ?></p>
			</noscript>
		</form>
		<?php
	}

	/**
	 * Render settings form.
	 *
	 * @param array $options Plugin options.
	 * @return void
	 */
	private function render_settings_form( $options ) {
		?>
		<form class="yoohw-mo-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="yoohw_mo_save_settings">
			<?php wp_nonce_field( 'yoohw_mo_save_settings' ); ?>

			<label class="yoohw-mo-check">
				<input type="checkbox" name="auto_optimize_uploads" value="1" <?php checked( ! empty( $options['auto_optimize_uploads'] ) ); ?>>
				<span><?php esc_html_e( 'Automatically optimize new uploads', 'yoohw-media-optimizer' ); ?></span>
			</label>

			<label class="yoohw-mo-check">
				<input type="checkbox" name="generate_webp_sidecars" value="1" <?php checked( ! empty( $options['generate_webp_sidecars'] ) ); ?>>
				<span><?php esc_html_e( 'Generate WebP sidecars', 'yoohw-media-optimizer' ); ?></span>
			</label>

			<label class="yoohw-mo-check">
				<input type="checkbox" name="generate_avif_sidecars" value="1" <?php checked( ! empty( $options['generate_avif_sidecars'] ) ); ?> <?php disabled( ! $this->avif_supported() ); ?>>
				<span><?php esc_html_e( 'Generate AVIF sidecars when supported', 'yoohw-media-optimizer' ); ?></span>
			</label>

			<label class="yoohw-mo-check">
				<input type="checkbox" name="skip_larger_files" value="1" <?php checked( ! empty( $options['skip_larger_files'] ) ); ?>>
				<span><?php esc_html_e( 'Skip generated modern files that are larger than the source', 'yoohw-media-optimizer' ); ?></span>
			</label>

			<fieldset class="yoohw-mo-field yoohw-mo-radio-field">
				<legend><?php esc_html_e( 'Optimization engines', 'yoohw-media-optimizer' ); ?></legend>
				<label>
					<input type="checkbox" name="use_external_binaries" value="1" <?php checked( ! empty( $options['use_external_binaries'] ) ); ?>>
					<span><?php esc_html_e( 'Use server binaries when available', 'yoohw-media-optimizer' ); ?></span>
					<em><?php esc_html_e( 'The plugin chooses the best available engine for JPEG, PNG, WebP, and AVIF, then falls back to GD/Imagick when needed.', 'yoohw-media-optimizer' ); ?></em>
				</label>
				<?php $this->render_external_binary_status(); ?>
			</fieldset>

			<fieldset class="yoohw-mo-field yoohw-mo-radio-field">
				<legend><?php esc_html_e( 'Original file optimization', 'yoohw-media-optimizer' ); ?></legend>
				<label>
					<input type="checkbox" name="optimize_originals" value="1" <?php checked( ! empty( $options['optimize_originals'] ) ); ?>>
					<span><?php esc_html_e( 'Optimize JPEG/PNG originals and generated sizes', 'yoohw-media-optimizer' ); ?></span>
					<em><?php esc_html_e( 'When enabled, the plugin can resize and recompress physical files after creating a backup.', 'yoohw-media-optimizer' ); ?></em>
				</label>
				<label>
					<input type="checkbox" name="backup_originals" value="1" <?php checked( ! empty( $options['backup_originals'] ) ); ?>>
					<span><?php esc_html_e( 'Require backups before changing original files', 'yoohw-media-optimizer' ); ?></span>
				<em><?php esc_html_e( 'Backups are stored outside the WordPress document root. Original-file optimization is blocked without them.', 'yoohw-media-optimizer' ); ?></em>
				</label>
			</fieldset>

			<div class="yoohw-mo-field-row">
				<label class="yoohw-mo-field">
					<span><?php esc_html_e( 'Max width', 'yoohw-media-optimizer' ); ?></span>
					<input type="number" name="max_width" min="0" max="10000" value="<?php echo esc_attr( absint( $options['max_width'] ) ); ?>">
					<em><?php esc_html_e( '0 means no width limit.', 'yoohw-media-optimizer' ); ?></em>
				</label>

				<label class="yoohw-mo-field">
					<span><?php esc_html_e( 'Max height', 'yoohw-media-optimizer' ); ?></span>
					<input type="number" name="max_height" min="0" max="10000" value="<?php echo esc_attr( absint( $options['max_height'] ) ); ?>">
					<em><?php esc_html_e( '0 means no height limit.', 'yoohw-media-optimizer' ); ?></em>
				</label>
			</div>

			<label class="yoohw-mo-field">
				<span><?php esc_html_e( 'Compression mode', 'yoohw-media-optimizer' ); ?></span>
				<select name="compression_mode">
					<option value="lossless" <?php selected( 'lossless', $options['compression_mode'] ); ?>><?php esc_html_e( 'Lossless / resize only', 'yoohw-media-optimizer' ); ?></option>
					<option value="balanced" <?php selected( 'balanced', $options['compression_mode'] ); ?>><?php esc_html_e( 'Balanced', 'yoohw-media-optimizer' ); ?></option>
					<option value="aggressive" <?php selected( 'aggressive', $options['compression_mode'] ); ?>><?php esc_html_e( 'Aggressive', 'yoohw-media-optimizer' ); ?></option>
					<option value="custom" <?php selected( 'custom', $options['compression_mode'] ); ?>><?php esc_html_e( 'Custom JPEG quality', 'yoohw-media-optimizer' ); ?></option>
				</select>
				<em><?php esc_html_e( 'Uses server binaries when available, then falls back to the active WordPress image editor. Without resizing, lossless JPEG optimization requires jpegoptim or jpegtran; otherwise the original is kept unchanged.', 'yoohw-media-optimizer' ); ?></em>
			</label>

			<label class="yoohw-mo-field">
				<span><?php esc_html_e( 'Custom JPEG quality', 'yoohw-media-optimizer' ); ?></span>
				<input type="number" name="jpeg_quality" min="1" max="100" value="<?php echo esc_attr( absint( $options['jpeg_quality'] ) ); ?>">
			</label>

			<label class="yoohw-mo-field">
				<span><?php esc_html_e( 'Metadata policy', 'yoohw-media-optimizer' ); ?></span>
			<select name="metadata_policy">
					<option value="remove" <?php selected( 'remove', $options['metadata_policy'] ); ?>><?php esc_html_e( 'Remove private metadata; retain color profiles', 'yoohw-media-optimizer' ); ?></option>
					<option value="preserve_all" <?php selected( 'preserve_all', $options['metadata_policy'] ); ?>><?php esc_html_e( 'Preserve all metadata when possible', 'yoohw-media-optimizer' ); ?></option>
				</select>
				<em><?php esc_html_e( 'Supported color profiles are retained. Files are left unchanged when their colors cannot be preserved safely. Backups keep the untouched original.', 'yoohw-media-optimizer' ); ?></em>
			</label>

			<fieldset class="yoohw-mo-field yoohw-mo-radio-field">
				<legend><?php esc_html_e( 'Delivery mode', 'yoohw-media-optimizer' ); ?></legend>
				<label>
					<input type="radio" name="delivery_mode" value="<?php echo esc_attr( self::DELIVERY_GENERATE_ONLY ); ?>" <?php checked( self::DELIVERY_GENERATE_ONLY, $options['delivery_mode'] ); ?>>
					<span><?php esc_html_e( 'Generate only', 'yoohw-media-optimizer' ); ?></span>
					<em><?php esc_html_e( 'Safest mode. The plugin creates modern sidecar files but does not change frontend image URLs.', 'yoohw-media-optimizer' ); ?></em>
				</label>
				<label>
					<input type="radio" name="delivery_mode" value="<?php echo esc_attr( self::DELIVERY_HTML ); ?>" <?php checked( self::DELIVERY_HTML, $options['delivery_mode'] ); ?>>
					<span><?php esc_html_e( 'Replace attachment image HTML', 'yoohw-media-optimizer' ); ?></span>
					<em><?php esc_html_e( 'Adds AVIF and WebP picture sources while keeping the original JPEG/PNG img URL as a browser-native fallback.', 'yoohw-media-optimizer' ); ?></em>
				</label>
			</fieldset>

			<div class="yoohw-mo-field-row">
				<label class="yoohw-mo-field">
					<span><?php esc_html_e( 'WebP quality', 'yoohw-media-optimizer' ); ?></span>
					<input type="number" name="webp_quality" min="1" max="100" value="<?php echo esc_attr( $options['webp_quality'] ); ?>">
				</label>
				<label class="yoohw-mo-field">
					<span><?php esc_html_e( 'AVIF color quality', 'yoohw-media-optimizer' ); ?></span>
					<input type="number" name="avif_quality" min="1" max="100" value="<?php echo esc_attr( $options['avif_quality'] ); ?>">
				</label>
			</div>
			<p class="description"><?php esc_html_e( 'WebP and AVIF quality scales are different: equal numbers do not mean equal visual quality. Each keeps your previous quality setting, or 82 by default. Existing fresh sidecars are kept; use force to regenerate them.', 'yoohw-media-optimizer' ); ?></p>

			<label class="yoohw-mo-field">
				<span><?php esc_html_e( 'PNG to WebP', 'yoohw-media-optimizer' ); ?></span>
				<select name="webp_png_mode">
					<option value="lossy" <?php selected( 'lossy', $options['webp_png_mode'] ); ?>><?php esc_html_e( 'Lossy (default)', 'yoohw-media-optimizer' ); ?></option>
					<option value="lossless" <?php selected( 'lossless', $options['webp_png_mode'] ); ?>><?php esc_html_e( 'Lossless pixels (requires cwebp)', 'yoohw-media-optimizer' ); ?></option>
				</select>
				<em><?php esc_html_e( 'Lossless PNG pixels use fixed cwebp effort settings and ignore WebP quality. RGB hidden by fully transparent pixels may change.', 'yoohw-media-optimizer' ); ?></em>
			</label>
			<label class="yoohw-mo-field">
				<span><?php esc_html_e( 'Imagick AVIF color detail', 'yoohw-media-optimizer' ); ?></span>
				<select name="avif_imagick_chroma">
					<option value="default" <?php selected( 'default', $options['avif_imagick_chroma'] ); ?>><?php esc_html_e( 'Encoder default', 'yoohw-media-optimizer' ); ?></option>
					<option value="444" <?php selected( '444', $options['avif_imagick_chroma'] ); ?>><?php esc_html_e( 'Request 4:4:4', 'yoohw-media-optimizer' ); ?></option>
				</select>
				<em><?php esc_html_e( 'Only affects AVIF sidecars encoded by Imagick; it does not switch engines or make alpha lossless. Writer support varies; larger files and more CPU are possible.', 'yoohw-media-optimizer' ); ?></em>
			</label>
			<label class="yoohw-mo-field">
				<span><?php esc_html_e( 'PNG to AVIF alpha', 'yoohw-media-optimizer' ); ?></span>
				<select name="avif_alpha_mode">
					<option value="default" <?php selected( 'default', $options['avif_alpha_mode'] ); ?>><?php esc_html_e( 'Encoder default', 'yoohw-media-optimizer' ); ?></option>
					<option value="lossless" <?php selected( 'lossless', $options['avif_alpha_mode'] ); ?>><?php esc_html_e( 'Lossless alpha (requires avifenc)', 'yoohw-media-optimizer' ); ?></option>
				</select>
				<em><?php esc_html_e( 'Lossless alpha retains transparency while color still uses AVIF quality. Advanced PNG modes support up to 8 bits per channel and fail if their native encoder cannot complete; JPEG behavior is unchanged.', 'yoohw-media-optimizer' ); ?></em>
			</label>

			<button type="submit" class="button"><?php esc_html_e( 'Save settings', 'yoohw-media-optimizer' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Save settings handler.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ) );
		}

		check_admin_referer( 'yoohw_mo_save_settings' );

		$current = $this->get_options();
		$avif_chroma = array_key_exists( 'avif_imagick_chroma', $_POST ) ? wp_unslash( $_POST['avif_imagick_chroma'] ) : $current['avif_imagick_chroma'];
		$quality = isset( $_POST['quality'] ) ? max( 1, min( 100, absint( wp_unslash( $_POST['quality'] ) ) ) ) : $current['quality'];
		$options = array(
			'auto_optimize_uploads'  => empty( $_POST['auto_optimize_uploads'] ) ? 0 : 1,
			'generate_webp_sidecars' => empty( $_POST['generate_webp_sidecars'] ) ? 0 : 1,
			'generate_avif_sidecars' => empty( $_POST['generate_avif_sidecars'] ) ? 0 : 1,
			'optimize_originals'     => empty( $_POST['optimize_originals'] ) ? 0 : 1,
			'backup_originals'       => empty( $_POST['backup_originals'] ) ? 0 : 1,
			'use_external_binaries'  => empty( $_POST['use_external_binaries'] ) ? 0 : 1,
			'skip_larger_files'      => empty( $_POST['skip_larger_files'] ) ? 0 : 1,
			'quality'                => $quality,
			'webp_quality'           => isset( $_POST['webp_quality'] ) ? max( 1, min( 100, absint( wp_unslash( $_POST['webp_quality'] ) ) ) ) : ( isset( $_POST['quality'] ) ? $quality : $current['webp_quality'] ),
			'avif_quality'           => isset( $_POST['avif_quality'] ) ? max( 1, min( 100, absint( wp_unslash( $_POST['avif_quality'] ) ) ) ) : ( isset( $_POST['quality'] ) ? $quality : $current['avif_quality'] ),
			'webp_png_mode'          => isset( $_POST['webp_png_mode'] ) ? sanitize_key( wp_unslash( $_POST['webp_png_mode'] ) ) : $current['webp_png_mode'],
			'avif_alpha_mode'        => isset( $_POST['avif_alpha_mode'] ) ? sanitize_key( wp_unslash( $_POST['avif_alpha_mode'] ) ) : $current['avif_alpha_mode'],
			'avif_imagick_chroma'    => in_array( $avif_chroma, array( 'default', '444' ), true ) ? $avif_chroma : 'default',
			'jpeg_quality'           => isset( $_POST['jpeg_quality'] ) ? max( 1, min( 100, absint( wp_unslash( $_POST['jpeg_quality'] ) ) ) ) : 82,
			'max_width'              => isset( $_POST['max_width'] ) ? max( 0, min( 10000, absint( wp_unslash( $_POST['max_width'] ) ) ) ) : 0,
			'max_height'             => isset( $_POST['max_height'] ) ? max( 0, min( 10000, absint( wp_unslash( $_POST['max_height'] ) ) ) ) : 0,
			'compression_mode'       => isset( $_POST['compression_mode'] ) ? sanitize_key( wp_unslash( $_POST['compression_mode'] ) ) : 'balanced',
			'metadata_policy'        => isset( $_POST['metadata_policy'] ) ? sanitize_key( wp_unslash( $_POST['metadata_policy'] ) ) : 'remove',
			'delivery_mode'          => isset( $_POST['delivery_mode'] ) ? sanitize_key( wp_unslash( $_POST['delivery_mode'] ) ) : self::DELIVERY_GENERATE_ONLY,
		);

		$options['webp_png_mode']   = in_array( $options['webp_png_mode'], array( 'lossy', 'lossless' ), true ) ? $options['webp_png_mode'] : 'lossy';
		$options['avif_alpha_mode'] = in_array( $options['avif_alpha_mode'], array( 'default', 'lossless' ), true ) ? $options['avif_alpha_mode'] : 'default';

		if ( ! in_array( $options['compression_mode'], array( 'lossless', 'balanced', 'aggressive', 'custom' ), true ) ) {
			$options['compression_mode'] = 'balanced';
		}

		if ( ! in_array( $options['metadata_policy'], array( 'remove', 'preserve_all' ), true ) ) {
			$options['metadata_policy'] = 'remove';
		}

		if ( ! in_array( $options['delivery_mode'], array( self::DELIVERY_GENERATE_ONLY, self::DELIVERY_HTML ), true ) ) {
			$options['delivery_mode'] = self::DELIVERY_GENERATE_ONLY;
		}

		if ( empty( $options['generate_webp_sidecars'] ) && empty( $options['generate_avif_sidecars'] ) && self::DELIVERY_HTML === $options['delivery_mode'] ) {
			$options['delivery_mode'] = self::DELIVERY_GENERATE_ONLY;
		}

		if ( ! $this->avif_supported() ) {
			$options['generate_avif_sidecars'] = 0;
		}

		if ( ! empty( $options['optimize_originals'] ) ) {
			$options['backup_originals'] = 1;
		}

		update_option( self::OPTION_KEY, $options );
		$this->invalidate_savings_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'              => 'settings',
					'yoohw_mo_notice' => 'settings_saved',
				),
				$this->admin_page_url()
			)
		);
		exit;
	}

	/**
	 * Batch optimize handler.
	 *
	 * @return void
	 */
	public function handle_optimize_batch() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ) );
		}
		check_admin_referer( 'yoohw_mo_optimize_batch' );
		$result = $this->browser_batch_request( wp_unslash( $_POST ) );
		if ( ! empty( $result['error'] ) ) {
			wp_die( esc_html( $result['message'] ), '', array( 'response' => absint( $result['httpStatus'] ) ) );
		}
		wp_safe_redirect( add_query_arg( array( 'yoohw_mo_notice' => 'batch_done', 'tab' => 'optimize', 'processed' => $result['processedTotal'], 'created' => $result['created'], 'existing' => $result['existing'], 'skipped' => $result['skipped'], 'failed' => $result['failed'], 'originalOptimized' => $result['originalOptimized'], 'originalSkipped' => $result['originalSkipped'], 'originalFailed' => $result['originalFailed'] ), $this->admin_page_url() ) );
		exit;
	}

	/** AJAX uses the same persisted lifecycle as a regular form submission. */
	public function handle_ajax_optimize_batch() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ) ), 403 );
		}
		check_ajax_referer( 'yoohw_mo_ajax', 'nonce' );
		$result = $this->browser_batch_request( wp_unslash( $_POST ) );
		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( $result, $result['httpStatus'] );
		}
		wp_send_json_success( $result );
	}

	private function require_admin_mutation_result( $result ) {
		if ( ! empty( $result['error'] ) ) {
			wp_die( esc_html( $result['message'] ), '', array( 'response' => absint( $result['httpStatus'] ?? 500 ) ) );
		}
	}

	private function browser_batch_request( $request ) {
		$run_id = isset( $request['run_id'] ) ? sanitize_text_field( $request['run_id'] ) : '';
		$start = ! empty( $request['start'] );
		$force = isset( $request['force'] ) ? (bool) $request['force'] : null;
		$limit = isset( $request['limit'] ) ? absint( $request['limit'] ) : self::UI_BATCH_LIMIT;
		return $this->run_browser_batch( $run_id, $start, $force, $limit );
	}

	/**
	 * Cleanup generated sidecars.
	 *
	 * @return void
	 */
	public function handle_cleanup_sidecars() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ) );
		}

		check_admin_referer( 'yoohw_mo_cleanup_sidecars' );

		$confirmed = ! empty( $_POST['confirm_cleanup'] );

		if ( ! $confirmed ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'yoohw_mo_notice' => 'cleanup_done',
						'tab'             => 'maintenance',
						'deleted'         => 0,
						'failed'          => 0,
					),
					$this->admin_page_url()
				)
			);
			exit;
		}

		$result = $this->cleanup_sidecars( ! empty( $_POST['delete_tracking'] ) );
		$this->require_admin_mutation_result( $result );

		wp_safe_redirect(
			add_query_arg(
				array(
					'yoohw_mo_notice' => 'cleanup_done',
					'tab'             => 'maintenance',
					'deleted'         => $result['deleted'],
					'failed'          => $result['failed'],
				),
				$this->admin_page_url()
			)
		);
		exit;
	}

	/**
	 * Restore one attachment from optimizer backups.
	 *
	 * @return void
	 */
	public function handle_restore_attachment() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ) );
		}

		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0;

		check_admin_referer( 'yoohw_mo_restore_attachment_' . $attachment_id );

		$result   = $attachment_id ? $this->restore_attachment( $attachment_id ) : array( 'restored' => 0, 'failed' => 1 );
		$this->require_admin_mutation_result( $result );
		$redirect = wp_get_referer() ? wp_get_referer() : $this->admin_page_url();

		wp_safe_redirect(
			add_query_arg(
				array(
					'yoohw_mo_notice' => 'restore_done',
					'restored'        => absint( $result['restored'] ?? 0 ),
					'failed'          => absint( $result['failed'] ?? 0 ),
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Restore backed up originals in batches.
	 *
	 * @return void
	 */
	public function handle_restore_backups() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ) );
		}

		check_admin_referer( 'yoohw_mo_restore_backups' );

		$result = array(
			'restored' => 0,
			'failed'   => 0,
		);

		if ( ! empty( $_POST['confirm_restore'] ) ) {
			$limit = isset( $_POST['limit'] ) ? max( 1, min( 500, absint( wp_unslash( $_POST['limit'] ) ) ) ) : 100;
			$query = $this->query_supported_attachment_ids( $limit, 0, false );

			foreach ( $query['ids'] as $attachment_id ) {
				$restored = $this->restore_attachment( $attachment_id );
				$this->require_admin_mutation_result( $restored );

				$result['restored'] += absint( $restored['restored'] ?? 0 );
				$result['failed']   += absint( $restored['failed'] ?? 0 );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'yoohw_mo_notice' => 'restore_done',
					'tab'             => 'maintenance',
					'restored'        => $result['restored'],
					'failed'          => $result['failed'],
				),
				$this->admin_page_url()
			)
		);
		exit;
	}

	/**
	 * Build the persistent optimization queue.
	 *
	 * @return void
	 */
	public function handle_build_queue() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ) );
		}

		check_admin_referer( 'yoohw_mo_build_queue' );

		$result = $this->build_queue();
		$this->require_admin_mutation_result( $result );

		wp_safe_redirect(
			add_query_arg(
				array(
					'yoohw_mo_notice' => 'queue_built',
					'tab'             => 'optimize',
					'queued'          => absint( $result['queued'] ?? 0 ),
				),
				$this->admin_page_url()
			)
		);
		exit;
	}

	/**
	 * Process one persistent queue batch.
	 *
	 * @return void
	 */
	public function handle_process_queue() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ) );
		}

		check_admin_referer( 'yoohw_mo_process_queue' );

		$limit  = isset( $_POST['limit'] ) ? max( 1, min( 200, absint( wp_unslash( $_POST['limit'] ) ) ) ) : self::DEFAULT_LIMIT;
		$result = $this->process_queue_batch( $limit, null );
		$this->require_admin_mutation_result( $result );
		$notice = ! empty( $result['locked'] ) ? 'queue_locked' : 'queue_done';

		wp_safe_redirect(
			add_query_arg(
				array(
					'yoohw_mo_notice' => $notice,
					'tab'             => 'optimize',
					'processed'       => absint( $result['processed'] ?? 0 ),
					'failed'          => absint( $result['batchFailed'] ?? 0 ),
				),
				$this->admin_page_url()
			)
		);
		exit;
	}

	/**
	 * AJAX delivery test handler.
	 *
	 * @return void
	 */
	public function handle_ajax_test_delivery() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ),
				),
				403
			);
		}

		check_ajax_referer( 'yoohw_mo_ajax', 'nonce' );

		$sample = $this->delivery_sample();

		if ( ! $sample ) {
			wp_send_json_error(
				array(
					'message' => __( 'No optimized image sample is available yet.', 'yoohw-media-optimizer' ),
				),
				404
			);
		}

		$options = $this->get_options();
		$direct_avif = $this->remote_head_report( $sample['avif_url'] ?? '', array(), array( 'avif' ) );
		$direct_webp = $this->remote_head_report( $sample['webp_url'] ?? '', array(), array( 'webp' ) );
		$rewrite_avif = $this->remote_head_report(
			$sample['source_url'],
			array(
				'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8',
			)
		);
		$rewrite_webp = $this->remote_head_report(
			$sample['source_url'],
			array(
				'Accept' => 'image/avif;q=0,image/webp,image/*,*/*;q=0.8',
			),
			array( 'webp' )
		);

		wp_send_json_success(
			array(
				'sample'          => $sample,
				'directAvif'      => $direct_avif,
				'directWebp'      => $direct_webp,
				'rewriteAvif'     => $rewrite_avif,
				'rewriteWebp'     => $rewrite_webp,
				'deliveryMode'    => $options['delivery_mode'],
				'htmlModeEnabled' => self::DELIVERY_HTML === $options['delivery_mode'],
			)
		);
	}

	/**
	 * Whether the current image editor supports WebP writes.
	 *
	 * @return bool
	 */
	public function webp_supported() {
		return wp_image_editor_supports(
			array(
				'mime_type' => 'image/webp',
			)
		);
	}

	/**
	 * Whether any supported engine can write WebP.
	 *
	 * @return bool
	 */
	public function webp_generation_supported() {
		return $this->webp_supported() || $this->external_binary_available( 'cwebp' );
	}

	/**
	 * Whether any supported engine can write AVIF.
	 *
	 * @return bool
	 */
	public function avif_supported() {
		return $this->avif_editor_supported() || $this->external_binary_available( 'avifenc' );
	}

	/**
	 * Whether the current image editor supports AVIF writes.
	 *
	 * @return bool
	 */
	private function avif_editor_supported() {
		return wp_image_editor_supports(
			array(
				'mime_type' => 'image/avif',
			)
		);
	}

	/**
	 * Get detected external binary status.
	 *
	 * @return array
	 */
	public function external_binary_report() {
		$report = array();

		foreach ( $this->external_binary_names() as $name ) {
			$path = $this->external_binary_path( $name );

			$report[] = array(
				'name'      => $name,
				'available' => ! empty( $path ),
				'path'      => $path,
			);
		}

		return $report;
	}

	/**
	 * Render external binary status rows.
	 *
	 * @return void
	 */
	private function render_external_binary_status() {
		$report = $this->external_binary_report();
		?>
		<div class="yoohw-mo-engine-list">
			<?php foreach ( $report as $binary ) : ?>
				<div class="yoohw-mo-engine-row">
					<strong><?php echo esc_html( $binary['name'] ); ?></strong>
					<span class="yoohw-mo-mini-status <?php echo ! empty( $binary['available'] ) ? 'is-ok' : ''; ?>"><?php echo ! empty( $binary['available'] ) ? esc_html__( 'Available', 'yoohw-media-optimizer' ) : esc_html__( 'Fallback', 'yoohw-media-optimizer' ); ?></span>
					<code><?php echo ! empty( $binary['path'] ) ? esc_html( $binary['path'] ) : esc_html__( 'Not detected', 'yoohw-media-optimizer' ); ?></code>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Whether one external binary is usable.
	 *
	 * @param string $binary Binary name.
	 * @return bool
	 */
	private function external_binary_available( $binary ) {
		return '' !== $this->external_binary_path( $binary );
	}

	/**
	 * Locate an allowed external binary.
	 *
	 * @param string $binary Binary name.
	 * @return string
	 */
	private function external_binary_path( $binary ) {
		static $cache = array();

		$options = $this->get_options();
		$binary  = sanitize_key( $binary );

		if ( empty( $options['use_external_binaries'] ) || ! in_array( $binary, $this->external_binary_names(), true ) ) {
			return '';
		}

		if ( isset( $cache[ $binary ] ) ) {
			return $cache[ $binary ];
		}

		$cache[ $binary ] = '';

		foreach ( $this->external_binary_search_paths() as $directory ) {
			$candidate = trailingslashit( $directory ) . $binary;

			if ( file_exists( $candidate ) && is_executable( $candidate ) && wp_basename( $candidate ) === $binary ) {
				$cache[ $binary ] = wp_normalize_path( $candidate );
				break;
			}
		}

		return $cache[ $binary ];
	}

	/**
	 * Allowed external binary names.
	 *
	 * @return array
	 */
	private function external_binary_names() {
		return array( 'jpegoptim', 'cjpeg', 'djpeg', 'jpegtran', 'pngquant', 'oxipng', 'optipng', 'cwebp', 'avifenc' );
	}

	/**
	 * Search paths for external binaries.
	 *
	 * @return array
	 */
	private function external_binary_search_paths() {
		$paths = array(
			'/usr/bin',
			'/usr/local/bin',
			'/opt/homebrew/bin',
			'/opt/local/bin',
		);

		$env_path = getenv( 'PATH' );

		if ( is_string( $env_path ) && '' !== $env_path ) {
			$paths = array_merge( explode( PATH_SEPARATOR, $env_path ), $paths );
		}

		$paths = array_filter(
			array_map(
				static function( $path ) {
					return wp_normalize_path( untrailingslashit( (string) $path ) );
				},
				$paths
			),
			static function( $path ) {
				return is_string( $path ) && '' !== $path && '/' === substr( $path, 0, 1 );
			}
		);

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Whether proc_open is available for external binaries.
	 *
	 * @return bool
	 */
	private function can_run_external_binaries() {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'proc_open', $disabled, true );
	}

	/**
	 * Run an external binary with a timeout.
	 *
	 * @param array $command Command array. First element must be an absolute binary path.
	 * @param int   $timeout Timeout in seconds.
	 * @return array|WP_Error
	 */
	private function run_external_binary( $command, $timeout = 30 ) {
		if ( ! $this->can_run_external_binaries() ) {
			return new WP_Error( 'yoohw_mo_proc_open_unavailable', __( 'proc_open is unavailable, so external binaries cannot run.', 'yoohw-media-optimizer' ) );
		}

		if ( empty( $command[0] ) || ! is_string( $command[0] ) || ! is_executable( $command[0] ) ) {
			return new WP_Error( 'yoohw_mo_binary_missing', __( 'External binary is missing or not executable.', 'yoohw-media-optimizer' ) );
		}

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$pipes       = array();
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Optional local encoder uses argv with shell bypass; WordPress has no process API.
		$process     = proc_open( array_values( $command ), $descriptors, $pipes, null, null, array( 'bypass_shell' => true ) );

		if ( ! is_resource( $process ) ) {
			return new WP_Error( 'yoohw_mo_binary_start_failed', __( 'Could not start external optimizer.', 'yoohw-media-optimizer' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe; WP_Filesystem cannot close these handles.
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout = '';
		$stderr = '';
		$start  = microtime( true );
		$exit_code = null;

		while ( true ) {
			$status = proc_get_status( $process );
			$stdout .= stream_get_contents( $pipes[1] );
			$stderr .= stream_get_contents( $pipes[2] );

			if ( empty( $status['running'] ) ) {
				$exit_code = isset( $status['exitcode'] ) ? (int) $status['exitcode'] : null;
				break;
			}

			if ( microtime( true ) - $start > $timeout ) {
				proc_terminate( $process );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe; WP_Filesystem cannot close these handles.
				fclose( $pipes[1] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe; WP_Filesystem cannot close these handles.
				fclose( $pipes[2] );
				proc_close( $process );

				return new WP_Error( 'yoohw_mo_binary_timeout', __( 'External optimizer timed out.', 'yoohw-media-optimizer' ) );
			}

			usleep( 100000 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe; WP_Filesystem cannot close these handles.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe; WP_Filesystem cannot close these handles.
		fclose( $pipes[2] );

		$close_code = proc_close( $process );

		if ( null === $exit_code || $exit_code < 0 ) {
			$exit_code = (int) $close_code;
		}

		if ( 0 !== $exit_code ) {
			return new WP_Error(
				'yoohw_mo_binary_failed',
				trim( $stderr ) ? trim( $stderr ) : __( 'External optimizer exited with an error.', 'yoohw-media-optimizer' ),
				array(
					'exit_code' => $exit_code,
					'stdout'    => $stdout,
					'stderr'    => $stderr,
				)
			);
		}

		return array(
			'exit_code' => $exit_code,
			'stdout'    => $stdout,
			'stderr'    => $stderr,
		);
	}

	/**
	 * Whether the current configuration can run at least one optimization pass.
	 *
	 * @return bool
	 */
	private function can_run_any_optimization() {
		$options = $this->get_options();

		if ( ! empty( $options['optimize_originals'] ) ) {
			return true;
		}

		if ( ! empty( $options['generate_webp_sidecars'] ) && $this->webp_generation_supported() ) {
			return true;
		}

		return ! empty( $options['generate_avif_sidecars'] ) && $this->avif_supported();
	}

	/**
	 * Query supported JPEG and PNG attachment IDs.
	 *
	 * @param int  $limit Number of IDs. Zero means all.
	 * @param int  $offset Offset.
	 * @param bool $with_found_rows Whether to calculate found rows.
	 * @return array
	 */
	public function query_supported_attachment_ids( $limit = 0, $offset = 0, $with_found_rows = false ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'fields'         => 'ids',
				'posts_per_page' => $limit > 0 ? absint( $limit ) : -1,
				'offset'         => absint( $offset ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => ! $with_found_rows,
			)
		);

		return array(
			'ids'   => array_map( 'absint', $query->posts ),
			'found' => $with_found_rows ? absint( $query->found_posts ) : count( $query->posts ),
		);
	}

	/**
	 * Scan media library optimization status.
	 *
	 * @param int $limit Max attachments to scan. Zero means all.
	 * @param int $offset Offset.
	 * @return array
	 */
	public function scan_library( $limit = 0, $offset = 0 ) {
		$query   = $this->query_supported_attachment_ids( $limit, $offset, true );
		$summary = array(
			'attachments'        => 0,
			'files'              => 0,
			'optimized'          => 0,
			'missing'            => 0,
			'stale'              => 0,
			'original_optimized' => 0,
			'backed_up'          => 0,
			'failed'             => 0,
			'truncated'          => $limit > 0 && $query['found'] > ( $offset + count( $query['ids'] ) ),
		);

		foreach ( $query['ids'] as $attachment_id ) {
			$status = $this->attachment_status( $attachment_id );

			++$summary['attachments'];
			$summary['files']              += $status['files'];
			$summary['optimized']          += $status['optimized'];
			$summary['missing']            += $status['missing'];
			$summary['stale']              += $status['stale'];
			$summary['original_optimized'] += $status['original_optimized'];
			$summary['backed_up']          += $status['backed_up'];
			$summary['failed']             += $status['failed'];
		}

		return $summary;
	}

	/**
	 * Build an aggregate savings report from current sidecar files.
	 *
	 * @return array
	 */
	private function savings_report() {
		$cached = get_transient( self::SAVINGS_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$limit  = 2000;
		$query  = $this->query_supported_attachment_ids( $limit, 0, true );
		$report = array(
			'optimized_files'      => 0,
			'source_bytes'         => 0,
			'webp_bytes'           => 0,
			'avif_bytes'           => 0,
			'delivery_bytes'       => 0,
			'modern_sidecar_bytes' => 0,
			'backup_bytes'         => 0,
			'saved_bytes'          => 0,
			'original_saved_bytes' => 0,
			'storage_added_bytes'  => 0,
			'storage_removed_bytes' => 0,
			'storage_impact_bytes' => 0,
			'saved_percent'        => 0,
			'larger_or_equal'      => 0,
			'latest_sidecar'       => 0,
			'truncated'            => $query['found'] > count( $query['ids'] ),
		);
		$sidecars_seen = array();
		$backups_seen  = array();

		foreach ( $query['ids'] as $attachment_id ) {
			$files = $this->collect_attachment_files( $attachment_id );

			foreach ( $files as $file ) {
				if ( empty( $file['path'] ) || ! file_exists( $file['path'] ) || ! $this->is_supported_source_path( $file['path'] ) ) {
					continue;
				}

				$source_size  = (int) filesize( $file['path'] );
				$webp_sidecar = $this->sidecar_path( $file['path'] );
				$avif_sidecar = $this->format_sidecar_path( $file['path'], 'avif' );
				$webp_size    = 0;
				$avif_size    = 0;

				if ( $this->fresh_sidecar_exists( $file['path'], $webp_sidecar ) ) {
					$webp_size = (int) filesize( $webp_sidecar );

					if ( $webp_size > 0 ) {
						$report['webp_bytes'] += $webp_size;

						$webp_key = wp_normalize_path( $webp_sidecar );

						if ( empty( $sidecars_seen[ $webp_key ] ) ) {
							$sidecars_seen[ $webp_key ] = true;
							$report['modern_sidecar_bytes'] += $webp_size;
						}

						$report['latest_sidecar'] = max( $report['latest_sidecar'], (int) filemtime( $webp_sidecar ) );
					}
				}

				if ( $this->fresh_sidecar_exists( $file['path'], $avif_sidecar ) ) {
					$avif_size = (int) filesize( $avif_sidecar );

					if ( $avif_size > 0 ) {
						$report['avif_bytes'] += $avif_size;

						$avif_key = wp_normalize_path( $avif_sidecar );

						if ( empty( $sidecars_seen[ $avif_key ] ) ) {
							$sidecars_seen[ $avif_key ] = true;
							$report['modern_sidecar_bytes'] += $avif_size;
						}

						$report['latest_sidecar'] = max( $report['latest_sidecar'], (int) filemtime( $avif_sidecar ) );
					}
				}

				$delivery_size = $avif_size > 0 ? $avif_size : $webp_size;

				if ( $source_size > 0 && $delivery_size > 0 ) {
					++$report['optimized_files'];
					$report['source_bytes']   += $source_size;
					$report['delivery_bytes'] += $delivery_size;

					if ( $delivery_size >= $source_size ) {
						++$report['larger_or_equal'];
					}
				}
			}

			$attachment_savings = $this->attachment_savings( $attachment_id );
			$report['original_saved_bytes'] += absint( $attachment_savings['original_saved_bytes'] ?? 0 );

			$tracked = get_post_meta( $attachment_id, self::META_KEY, true );

			if ( is_array( $tracked ) && ! empty( $tracked['files'] ) && is_array( $tracked['files'] ) ) {
				foreach ( $tracked['files'] as $tracked_file ) {
					if ( empty( $tracked_file['backup_path'] ) ) {
						continue;
					}

					$backup_path = $this->backup_reference_to_path( $tracked_file['backup_path'] );

					if ( ! $backup_path || ! file_exists( $backup_path ) ) {
						continue;
					}

					$backup_key = wp_normalize_path( $backup_path );

					if ( ! empty( $backups_seen[ $backup_key ] ) ) {
						continue;
					}

					$backups_seen[ $backup_key ] = true;
					$report['backup_bytes'] += (int) filesize( $backup_path );
				}
			}
		}

		$report['saved_bytes']   = max( 0, $report['source_bytes'] - $report['delivery_bytes'] ) + $report['original_saved_bytes'];
		$original_basis          = $report['source_bytes'] + $report['original_saved_bytes'];
		$report['saved_percent'] = $original_basis > 0 ? ( $report['saved_bytes'] / $original_basis ) * 100 : 0;
		$report['storage_added_bytes']   = $report['modern_sidecar_bytes'] + $report['backup_bytes'];
		$report['storage_removed_bytes'] = $report['original_saved_bytes'];
		$report['storage_impact_bytes']  = $report['storage_added_bytes'] - $report['storage_removed_bytes'];
		set_transient( self::SAVINGS_TRANSIENT, $report, 15 * MINUTE_IN_SECONDS );

		return $report;
	}

	/**
	 * Get optimization status for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public function attachment_status( $attachment_id ) {
		$files  = $this->collect_attachment_files( $attachment_id );
		$status = array(
			'files'              => 0,
			'optimized'          => 0,
			'missing'            => 0,
			'stale'              => 0,
			'original_optimized' => 0,
			'backed_up'          => 0,
			'failed'             => 0,
		);
		$tracked = get_post_meta( $attachment_id, self::META_KEY, true );

		if ( is_array( $tracked ) && ! empty( $tracked['files'] ) ) {
			foreach ( $tracked['files'] as $tracked_file ) {
				if ( ! empty( $tracked_file['status'] ) && 'failed' === $tracked_file['status'] ) {
					++$status['failed'];
				}

				if ( ! empty( $tracked_file['original_status'] ) && 'failed' === $tracked_file['original_status'] ) {
					++$status['failed'];
				}

				if ( ( ! empty( $tracked_file['original_status'] ) && 'optimized' === $tracked_file['original_status'] ) || absint( $tracked_file['saved_bytes'] ?? 0 ) > 0 ) {
					++$status['original_optimized'];
				}

				if ( ! empty( $tracked_file['backup_path'] ) ) {
					++$status['backed_up'];
				}
			}
		}

		foreach ( $files as $file ) {
			if ( empty( $file['path'] ) || ! file_exists( $file['path'] ) || ! $this->is_supported_source_path( $file['path'] ) ) {
				continue;
			}

			++$status['files'];

			$sidecar = $this->sidecar_path( $file['path'] );

			if ( ! file_exists( $sidecar ) ) {
				++$status['missing'];
				continue;
			}

			if ( filemtime( $sidecar ) < filemtime( $file['path'] ) ) {
				++$status['stale'];
				continue;
			}

			++$status['optimized'];
		}

		return $status;
	}

	/**
	 * Get per-attachment savings from optimizer tracking.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public function attachment_savings( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( isset( $this->attachment_savings_cache[ $attachment_id ] ) ) {
			return $this->attachment_savings_cache[ $attachment_id ];
		}

		$tracked = get_post_meta( $attachment_id, self::META_KEY, true );
		$result  = array(
			'files'                => 0,
			'original_saved_bytes' => 0,
			'webp_saved_bytes'     => 0,
			'saved_bytes'          => 0,
			'saved_percent'        => 0,
			'original_source_bytes' => 0,
			'current_source_bytes' => 0,
			'backups'              => 0,
			'has_failures'         => false,
		);

		if ( ! is_array( $tracked ) || empty( $tracked['files'] ) || ! is_array( $tracked['files'] ) ) {
			$this->attachment_savings_cache[ $attachment_id ] = $result;
			return $result;
		}

		foreach ( $tracked['files'] as $file ) {
			++$result['files'];

			$original_size = absint( $file['original_size'] ?? $file['source_size'] ?? 0 );
			$current_size  = absint( $file['optimized_size'] ?? $file['source_size'] ?? 0 );
			$webp_size     = absint( $file['webp_size'] ?? $file['sidecar_size'] ?? 0 );

			$result['original_source_bytes'] += $original_size;
			$result['current_source_bytes']  += $current_size;
			$result['original_saved_bytes']  += absint( $file['saved_bytes'] ?? 0 );

			if ( $current_size > 0 && $webp_size > 0 && $webp_size < $current_size ) {
				$result['webp_saved_bytes'] += $current_size - $webp_size;
			}

			if ( ! empty( $file['backup_path'] ) ) {
				++$result['backups'];
			}

			if ( ( ! empty( $file['status'] ) && 'failed' === $file['status'] ) || ( ! empty( $file['original_status'] ) && 'failed' === $file['original_status'] ) ) {
				$result['has_failures'] = true;
			}
		}

		$result['saved_bytes']   = $result['original_saved_bytes'] + $result['webp_saved_bytes'];
		$result['saved_percent'] = $result['original_source_bytes'] > 0 ? ( $result['saved_bytes'] / $result['original_source_bytes'] ) * 100 : 0;

		$this->attachment_savings_cache[ $attachment_id ] = $result;

		return $result;
	}

	/**
	 * Return a summarized persistent queue status.
	 *
	 * @return array
	 */
	/** Return persisted counts without rebuilding the attachment snapshot. */
	public function queue_status() {
		try {
			return $this->summarize_queue( $this->get_queue() );
		} catch ( RuntimeException $error ) {
			return array_merge( $this->summarize_queue( array( 'items' => array() ) ), $this->mutation_error( $error->getMessage() ) );
		}
	}

	private function summarize_queue( $queue ) {
		$status = array( 'total' => 0, 'pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'run_id' => $queue['run_id'] ?? '' );
		foreach ( $queue['items'] as $item ) {
			++$status['total'];
			$key = $item['status'] ?? 'pending';
			if ( isset( $status[ $key ] ) ) {
				++$status[ $key ];
			}
		}
		return $status;
	}

	/** Build only when the previous snapshot has no unfinished items. */
	public function build_queue() {
		return $this->with_mutation_lock( function () {
			$queue = $this->adopt_queue( $this->get_queue() );
			if ( ! $this->queue_has_more( $queue ) ) {
				$queue = $this->new_queue();
			}
			$this->save_queue( $queue );
			return array( 'queued' => count( $queue['items'] ), 'runId' => $queue['run_id'] );
		} );
	}

	private function new_queue( $force = null ) {
		$query = $this->query_supported_attachment_ids( 0, 0, false );
		$queue = array( 'run_id' => wp_generate_uuid4(), 'force' => $force, 'totals' => $this->empty_queue_totals(), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ), 'items' => array() );
		foreach ( $query['ids'] as $id ) {
			$queue['items'][ $id ] = array( 'id' => absint( $id ), 'status' => 'pending', 'message' => '', 'updated_at' => current_time( 'mysql' ) );
		}
		return $queue;
	}

	private function empty_queue_totals() {
		return array_fill_keys( array( 'processed', 'created', 'existing', 'skipped', 'failed', 'original_optimized', 'original_skipped', 'original_failed', 'backed_up' ), 0 );
	}

	private function adopt_queue( $queue ) {
		if ( empty( $queue['run_id'] ) ) {
			$queue['run_id'] = wp_generate_uuid4();
		}
		if ( ! array_key_exists( 'force', $queue ) ) {
			$queue['force'] = null;
		}
		$queue['legacy_totals_unknown'] = ! isset( $queue['totals'] ) || ! empty( $queue['legacy_totals_unknown'] );
		$queue['totals'] = array_merge( $this->empty_queue_totals(), is_array( $queue['totals'] ?? null ) ? $queue['totals'] : array() );
		return $queue;
	}

	private function queue_has_more( $queue ) {
		foreach ( $queue['items'] as $item ) {
			if ( in_array( $item['status'] ?? 'pending', array( 'pending', 'running' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Omitted force inherits the run's policy. */
	public function process_queue_batch( $limit = self::DEFAULT_LIMIT, $force = null ) {
		return $this->with_mutation_lock( function () use ( $limit, $force ) {
			$queue = $this->adopt_queue( $this->get_queue() );
			$this->bind_queue_force( $queue, $force );
			$this->save_queue( $queue );
			return $this->process_queue_owned( $queue, $limit );
		} );
	}

	/** Browser and no-JavaScript submissions share exactly the same run. */
	public function run_browser_batch( $run_id, $start, $force, $limit = self::UI_BATCH_LIMIT ) {
		return $this->with_mutation_lock( function () use ( $run_id, $start, $force, $limit ) {
			$queue = $this->get_queue();
			$existing_id = $queue['run_id'] ?? '';
			if ( $run_id && ( ! $existing_id || ! hash_equals( $existing_id, (string) $run_id ) ) ) {
				throw new RuntimeException( 'This optimization run has been replaced. Reload the page.', 409 );
			}
			if ( $existing_id && ! $run_id ) {
				throw new RuntimeException( 'An optimization run ID is required. Reload the page.', 409 );
			}
			if ( ! $start && ! $existing_id && empty( $queue['items'] ) ) {
				throw new RuntimeException( 'An optimization run ID is required to resume.', 409 );
			}
			if ( $start && ! $this->queue_has_more( $queue ) ) {
				$queue = $this->new_queue( (bool) $force );
			} else {
				$queue = $this->adopt_queue( $queue );
				$this->bind_queue_force( $queue, $force );
			}
			$this->save_queue( $queue );
			return $this->process_queue_owned( $queue, $limit );
		} );
	}

	private function bind_queue_force( &$queue, $force ) {
		if ( null === $queue['force'] ) {
			$queue['force'] = (bool) $force;
		} elseif ( null !== $force && (bool) $force !== (bool) $queue['force'] ) {
			throw new RuntimeException( 'The force policy is bound to this run and cannot be changed on resume.', 409 );
		}
	}

	private function process_queue_owned( $queue, $limit ) {
		$limit = max( 1, min( 200, absint( $limit ) ) );
		$started = microtime( true );
		$processed = 0;
		$done = 0;
		$failed = 0;
		foreach ( $queue['items'] as $id => $item ) {
			if ( $processed >= $limit || ( $processed && microtime( true ) - $started >= 20 ) ) {
				break;
			}
			$status = $item['status'] ?? 'pending';
			if ( ! in_array( $status, array( 'pending', 'running' ), true ) ) {
				continue;
			}
			$queue['items'][ $id ]['status'] = 'running';
			$queue['items'][ $id ]['updated_at'] = current_time( 'mysql' );
			$this->save_queue( $queue );
			if ( ! $this->is_supported_attachment( $id ) ) {
				$summary = array();
				$terminal = 'skipped';
			} else {
				// A previous native owner may have published files before dying. Never force its retry.
				$optimized = $this->optimize_attachment_owned( absint( $id ), 'running' === $status ? false : (bool) $queue['force'] );
				$summary = $optimized['summary'];
				$failures = absint( $summary['failed'] ?? 0 ) + absint( $summary['avif_failed'] ?? 0 ) + absint( $summary['original_failed'] ?? 0 );
				$terminal = $failures ? 'failed' : 'done';
			}
			++$processed;
			$done += 'done' === $terminal ? 1 : 0;
			$failed += 'failed' === $terminal ? 1 : 0;
			++$queue['totals']['processed'];
			foreach ( array( 'created' => array( 'created', 'updated', 'avif_created', 'avif_updated' ), 'existing' => array( 'existing', 'avif_existing' ), 'skipped' => array( 'skipped_larger', 'avif_skipped_larger' ), 'failed' => array( 'failed', 'avif_failed' ), 'original_optimized' => array( 'original_optimized' ), 'original_skipped' => array( 'original_skipped' ), 'original_failed' => array( 'original_failed' ), 'backed_up' => array( 'backed_up' ) ) as $key => $sources ) {
				foreach ( $sources as $source ) {
					$queue['totals'][ $key ] += absint( $summary[ $source ] ?? 0 );
				}
			}
			$queue['items'][ $id ]['status'] = $terminal;
			$queue['items'][ $id ]['message'] = 'failed' === $terminal ? 'File operations failed.' : '';
			$queue['items'][ $id ]['updated_at'] = current_time( 'mysql' );
			$queue['updated_at'] = current_time( 'mysql' );
			$this->save_queue( $queue );
		}
		return array_merge( $this->queue_progress( $queue ), array( 'processed' => $processed, 'batchProcessed' => $processed, 'done' => $done, 'batchFailed' => $failed, 'locked' => 0 ) );
	}

	private function queue_progress( $queue ) {
		$status = $this->summarize_queue( $queue );
		$total = $status['total'];
		$processed = $total - $status['pending'] - $status['running'];
		$totals = array_merge( $this->empty_queue_totals(), $queue['totals'] ?? array() );
		$result = array( 'runId' => $queue['run_id'] ?? '', 'force' => (bool) ( $queue['force'] ?? false ), 'found' => $total, 'processedTotal' => $processed, 'percent' => $total ? round( 100 * $processed / $total, 1 ) : 100, 'hasMore' => $this->queue_has_more( $queue ), 'legacyTotalsUnknown' => ! empty( $queue['legacy_totals_unknown'] ), 'skippedItems' => $status['skipped'], 'created' => $totals['created'], 'existing' => $totals['existing'], 'skipped' => $totals['skipped'], 'failed' => $totals['failed'], 'originalOptimized' => $totals['original_optimized'], 'originalSkipped' => $totals['original_skipped'], 'originalFailed' => $totals['original_failed'] );
		$result['note'] = ! empty( $queue['legacy_totals_unknown'] ) ? __( 'File-operation totals from before this queue was upgraded are unavailable.', 'yoohw-media-optimizer' ) : '';
		if ( $status['skipped'] ) {
			$result['note'] .= ' ' . sprintf( /* translators: %d: unavailable snapshot attachments. */ __( '%d snapshot attachment(s) no longer available were skipped.', 'yoohw-media-optimizer' ), $status['skipped'] );
		}
		return $result;
	}

	private function browser_queue_progress() {
		try {
			return $this->queue_progress( $this->get_queue() );
		} catch ( RuntimeException $error ) {
			return array_merge( $this->queue_progress( array( 'items' => array() ) ), $this->mutation_error( $error->getMessage() ) );
		}
	}

	/** A single descriptor is held through encoding, publication and persistence. */
	private function acquire_queue_lock() {
		if ( is_resource( $this->mutation_handle ) || DIRECTORY_SEPARATOR !== '/' || ! function_exists( 'flock' ) ) {
			return new WP_Error( 'yoohw_mo_queue_locked', 'Media mutation locking is busy or unavailable.', array( 'status' => is_resource( $this->mutation_handle ) ? 409 : 503 ) );
		}
		$uploads = wp_get_upload_dir();
		$root = realpath( $uploads['basedir'] );
		if ( ! $root || ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'yoohw_mo_queue_locked', 'The canonical uploads directory is unavailable.', array( 'status' => 503 ) );
		}
		$path = $root . '/.yoohw-media-optimizer.lock';
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( $stat && ( $stat['mode'] & 0170000 ) !== 0100000 ) {
			return new WP_Error( 'yoohw_mo_queue_locked', 'The media mutation lockfile is unsafe.', array( 'status' => 503 ) );
		}
		// Exclusive creation never replaces an inode. Read access lets CLI and web users share flock.
		// The e flag prevents encoder children inheriting the native descriptor.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no native descriptor for process-held flock or close-on-exec.
		$handle = $stat ? @fopen( $path, 'rbe' ) : @fopen( $path, 'x+be' );
		if ( ! $handle && ! $stat ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Open the existing native mutex descriptor after another process wins exclusive creation.
			$handle = @fopen( $path, 'rbe' );
		}
		if ( ! $handle || ! @flock( $handle, LOCK_EX | LOCK_NB, $would_block ) ) {
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native mutex descriptor; WP_Filesystem exposes no descriptor API.
				fclose( $handle );
			}
			return new WP_Error( 'yoohw_mo_queue_locked', 'Another media mutation worker is running, or locking is unavailable.', array( 'status' => ! empty( $would_block ) ? 409 : 503 ) );
		}
		$this->mutation_handle = $handle;
		$this->mutation_root = $root;
		$this->mutation_path = $path;
		$this->mutation_token = wp_generate_uuid4();
		try {
			$this->check_lockfile();
			$existing = $this->fresh_option( self::QUEUE_LOCK_OPTION, array() );
			if ( $existing && ( ! is_array( $existing ) || empty( $existing['native'] ) ) && ( ! is_array( $existing ) || time() - absint( $existing['acquired_at'] ?? time() ) <= 1800 ) ) {
				throw new RuntimeException( 'A legacy queue worker may still be running.', 409 );
			}
			$value = array( 'token' => $this->mutation_token, 'acquired_at' => time(), 'native' => 1 );
			$this->write_verified_option( self::QUEUE_LOCK_OPTION, $value );
			return $this->mutation_token;
		} catch ( RuntimeException $error ) {
			$this->close_mutation_handle();
			return new WP_Error( 'yoohw_mo_queue_locked', $error->getMessage(), array( 'status' => $error->getCode() ?: 500 ) );
		}
	}

	private function check_lockfile() {
		$uploads = wp_get_upload_dir();
		clearstatcache( true, $this->mutation_path );
		$path = @lstat( $this->mutation_path );
		$handle = is_resource( $this->mutation_handle ) ? fstat( $this->mutation_handle ) : false;
		if ( ! $path || ! $handle || ( $path['mode'] & 0170000 ) !== 0100000 || ( $handle['mode'] & 0170000 ) !== 0100000 || $path['dev'] !== $handle['dev'] || $path['ino'] !== $handle['ino'] || realpath( $uploads['basedir'] ) !== $this->mutation_root ) {
			throw new RuntimeException( 'The media mutation lockfile identity changed.', 503 );
		}
	}

	private function checkpoint_if_owned() {
		if ( $this->mutation_token ) {
			$this->mutation_checkpoint();
		}
	}

	private function mutation_checkpoint() {
		$this->check_lockfile();
		$existing = $this->fresh_option( self::QUEUE_LOCK_OPTION, array() );
		if ( ! is_array( $existing ) || empty( $this->mutation_token ) || ! hash_equals( $this->mutation_token, (string) ( $existing['token'] ?? '' ) ) ) {
			throw new RuntimeException( 'Media mutation ownership was lost.', 503 );
		}
	}

	private function release_queue_lock( $token ) {
		if ( ! $this->mutation_token || ! hash_equals( $this->mutation_token, (string) $token ) ) {
			return false;
		}
		$released = false;
		try {
			$this->mutation_checkpoint();
			delete_option( self::QUEUE_LOCK_OPTION );
			$released = ! $this->fresh_option( self::QUEUE_LOCK_OPTION, false );
		} catch ( RuntimeException $error ) {
			$released = false;
		} finally {
			$this->close_mutation_handle();
		}
		return $released;
	}

	private function close_mutation_handle() {
		if ( is_resource( $this->mutation_handle ) ) {
			flock( $this->mutation_handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the process-held descriptor; never unlink the shared mutex inode.
			fclose( $this->mutation_handle );
		}
		$this->mutation_handle = null;
		$this->mutation_token = '';
	}

	private function refresh_queue_lock( $token ) {
		if ( ! $this->mutation_token || ! hash_equals( $this->mutation_token, (string) $token ) ) {
			return false;
		}
		try {
			$this->mutation_checkpoint();
			$this->write_verified_option( self::QUEUE_LOCK_OPTION, array( 'token' => $token, 'acquired_at' => time(), 'native' => 1 ) );
			return true;
		} catch ( RuntimeException $error ) {
			return false;
		}
	}

	private function mutation_error( $message, $status = 500 ) {
		return array( 'httpStatus' => $status, 'locked' => 409 === $status ? 1 : 0, 'error' => 'yoohw_mo_mutation_stopped', 'message' => $message, 'processed' => 0, 'done' => 0, 'queued' => 0, 'restored' => 0, 'deleted' => 0, 'failed' => 1, 'summary' => array( 'failed' => 1 ) );
	}

	private function with_mutation_lock( $callback ) {
		$token = $this->acquire_queue_lock();
		if ( is_wp_error( $token ) ) {
			return $this->mutation_error( $token->get_error_message(), $token->get_error_data()['status'] ?? 500 );
		}
		try {
			$this->mutation_checkpoint();
			$result = $callback();
		} catch ( RuntimeException $error ) {
			$result = $this->mutation_error( $error->getMessage(), $error->getCode() ?: 500 );
		} finally {
			$released = $this->release_queue_lock( $token );
		}
		return $released || ! empty( $result['error'] ) ? $result : $this->mutation_error( 'Could not verify media mutation ownership at completion.', 503 );
	}

	/**
	 * Restore one attachment from tracked backups.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public function restore_attachment( $attachment_id ) {
		return $this->with_mutation_lock( function () use ( $attachment_id ) {
			return $this->restore_attachment_owned( $attachment_id );
		} );
	}

	private function restore_attachment_owned( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$candidates    = $this->restore_candidates( $attachment_id );
		$result        = array(
			'restored' => 0,
			'failed'   => 0,
		);
		$restored_sources = array();

		foreach ( $candidates as $candidate ) {
			$source = $candidate['source'];
			$backup = $candidate['backup'];

			if ( ! $source || ! $this->relative_upload_path_for_storage( $source ) || ! $this->is_supported_source_path( $source ) || ! $this->readable_snapshot( $backup ) ) {
				++$result['failed'];
				continue;
			}

			if ( $this->replace_original_file( $backup, $source ) ) {
				++$result['restored'];
				$restored_sources[ $source ] = true;
				if ( ! $this->delete_sidecars_for_path( $source ) ) {
					++$result['failed'];
				}
			} else {
				++$result['failed'];
			}
		}

		if ( $result['restored'] > 0 ) {
			$this->refresh_attachment_file_metadata( $attachment_id, null, true );

			$tracked = get_post_meta( $attachment_id, self::META_KEY, true );

			if ( is_array( $tracked ) ) {
				if ( ! empty( $tracked['files'] ) && is_array( $tracked['files'] ) ) {
					foreach ( $tracked['files'] as $index => $file ) {
						$source = ! empty( $file['source'] ) ? $this->upload_relative_to_path( $file['source'] ) : '';

						if ( ! $source || empty( $restored_sources[ $source ] ) ) {
							continue;
						}

						$current_size = file_exists( $source ) ? (int) filesize( $source ) : 0;

						foreach ( array( 'webp' => 'status', 'avif' => 'avif_status' ) as $format => $status_key ) {
							$sidecar = $this->format_sidecar_path( $source, $format );
							$remaining = file_exists( $sidecar ) || is_link( $sidecar );
							$size = $remaining && $this->is_safe_sidecar_path( $sidecar ) && is_file( $sidecar ) ? (int) filesize( $sidecar ) : 0;
							$tracked['files'][ $index ][ $status_key ] = $remaining ? 'failed' : 'restored';
							$tracked['files'][ $index ][ $format . '_size' ] = $size;
							if ( 'webp' === $format ) {
								$tracked['files'][ $index ]['sidecar_size'] = $size;
							}
						}
						$tracked['files'][ $index ]['original_status'] = 'restored';
						$tracked['files'][ $index ]['source_size']     = $current_size;
						$tracked['files'][ $index ]['optimized_size']  = $current_size;
						$tracked['files'][ $index ]['saved_bytes']     = 0;
						$tracked['files'][ $index ]['saved_percent']   = 0;
					}
				}

				$tracked['restored_at']     = current_time( 'mysql' );
				$tracked['restore_summary'] = $result;
				$this->checkpoint_if_owned();
				update_post_meta( $attachment_id, self::META_KEY, $tracked );
			}

			$this->invalidate_savings_cache( $attachment_id );
		}

		return $result;
	}

	/**
	 * Get persistent queue state.
	 *
	 * @return array
	 */
	private function get_queue() {
		$queue = $this->fresh_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || ! isset( $queue['items'] ) || ! is_array( $queue['items'] ) ) {
			if ( $queue ) {
				throw new RuntimeException( 'The persisted optimization queue is invalid.' );
			}
			$queue = array( 'created_at' => '', 'updated_at' => '', 'items' => array() );
		}
		return $queue;
	}

	private function save_queue( $queue ) {
		$this->mutation_checkpoint();
		$this->write_verified_option( self::QUEUE_OPTION, $queue );
	}

	/** Bypass persistent option caches for ownership and queue decisions. */
	private function fresh_option( $name, $default ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read authoritative media mutation state.' );
		}
		return $row ? maybe_unserialize( $row['option_value'] ) : $default;
	}

	private function write_verified_option( $name, $value ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		update_option( $name, $value, false );
		if ( $this->fresh_option( $name, null ) !== $value ) {
			throw new RuntimeException( 'Could not persist media mutation state.' );
		}
	}

	/**
	 * Invalidate aggregate and per-request savings caches after file changes.
	 *
	 * @param int $attachment_id Optional attachment ID.
	 * @return void
	 */
	private function invalidate_savings_cache( $attachment_id = 0 ) {
		delete_transient( self::SAVINGS_TRANSIENT );

		if ( $attachment_id ) {
			unset( $this->attachment_savings_cache[ absint( $attachment_id ) ] );
		} else {
			$this->attachment_savings_cache = array();
		}
	}

	/**
	 * Build restore candidates from tracked metadata and current attachment files.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function restore_candidates( $attachment_id ) {
		$tracked = get_post_meta( $attachment_id, self::META_KEY, true );
		$candidates = array();
		if ( is_array( $tracked ) && ! empty( $tracked['files'] ) && is_array( $tracked['files'] ) ) {
			foreach ( $tracked['files'] as $file ) {
				if ( empty( $file['source'] ) || empty( $file['backup_path'] ) ) {
					continue;
				}
				$source = $this->upload_relative_to_path( $file['source'] );
				$backup = $source ? $this->backup_reference_to_path( $file['backup_path'], $source ) : '';
				// Retain invalid tracked candidates as failures, rather than silently discovering another backup.
				$candidates[ wp_normalize_path( $file['source'] ) ] = array( 'source' => $source, 'backup' => $backup );
			}
		}
		foreach ( $this->collect_attachment_files( $attachment_id ) as $file ) {
			if ( empty( $file['path'] ) ) {
				continue;
			}
			$key = $this->relative_upload_path( $file['path'] );
			if ( isset( $candidates[ $key ] ) ) {
				continue;
			}
			if ( ! $this->relative_upload_path_for_storage( $file['path'] ) ) {
				$candidates[ $key ] = array( 'source' => $file['path'], 'backup' => '' );
				continue;
			}
			$backup = $this->backup_path_for( $file['path'] );
			if ( ! $backup || ! file_exists( $backup ) ) {
				$backup = $this->legacy_backup_path_for( $file['path'] );
			}
			if ( $backup && ( file_exists( $backup ) || is_link( $backup ) ) ) {
				$candidates[ $key ] = array( 'source' => $file['path'], 'backup' => $backup );
			}
		}
		return array_values( $candidates );
	}

	/**
	 * Optimize one attachment.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param bool       $force Whether to rebuild existing sidecars.
	 * @param array|null $metadata Optional attachment metadata.
	 * @param array      $override_options Optional runtime options.
	 * @return array
	 */
	public function optimize_attachment( $attachment_id, $force = false, $metadata = null, $override_options = array() ) {
		return $this->with_mutation_lock( function () use ( $attachment_id, $force, $metadata, $override_options ) {
			return $this->optimize_attachment_owned( $attachment_id, $force, $metadata, $override_options );
		} );
	}

	private function optimize_attachment_owned( $attachment_id, $force = false, $metadata = null, $override_options = array() ) {
		$options = wp_parse_args( is_array( $override_options ) ? $override_options : array(), $this->get_options() );
		$result = array(
			'attachment_id' => absint( $attachment_id ),
			'schema'        => self::META_SCHEMA,
			'version'       => YOOHW_MEDIA_OPTIMIZER_VERSION,
			'updated_at'    => current_time( 'mysql' ),
			'options'       => array(
				'generate_webp_sidecars' => empty( $options['generate_webp_sidecars'] ) ? 0 : 1,
				'generate_avif_sidecars' => empty( $options['generate_avif_sidecars'] ) ? 0 : 1,
				'optimize_originals'     => empty( $options['optimize_originals'] ) ? 0 : 1,
				'use_external_binaries'  => empty( $options['use_external_binaries'] ) ? 0 : 1,
				'compression_mode'       => sanitize_key( $options['compression_mode'] ?? 'balanced' ),
				'metadata_policy'        => sanitize_key( $options['metadata_policy'] ?? 'remove' ),
				'max_width'              => absint( $options['max_width'] ?? 0 ),
				'max_height'             => absint( $options['max_height'] ?? 0 ),
			),
			'files'         => array(),
			'summary'       => array(
				'created'            => 0,
				'updated'            => 0,
				'existing'           => 0,
				'skipped_larger'     => 0,
				'failed'             => 0,
				'unsupported'        => 0,
				'disabled'           => 0,
				'avif_created'       => 0,
				'avif_updated'       => 0,
				'avif_existing'      => 0,
				'avif_skipped_larger' => 0,
				'avif_failed'        => 0,
				'avif_unsupported'   => 0,
				'avif_disabled'      => 0,
				'original_optimized' => 0,
				'original_skipped'   => 0,
				'original_failed'    => 0,
				'backed_up'          => 0,
			),
		);

		if ( ! $this->is_supported_attachment( $attachment_id ) ) {
			++$result['summary']['unsupported'];
			return $result;
		}

		$files = $this->collect_attachment_files( $attachment_id, $metadata );
		$previous_tracking = get_post_meta( $attachment_id, self::META_KEY, true );
		$previous_files    = array();

		if ( is_array( $previous_tracking ) && ! empty( $previous_tracking['files'] ) && is_array( $previous_tracking['files'] ) ) {
			foreach ( $previous_tracking['files'] as $previous_file ) {
				if ( ! empty( $previous_file['source'] ) && is_string( $previous_file['source'] ) ) {
					$previous_file['_tracking_options'] = is_array( $previous_tracking['options'] ?? null ) ? $previous_tracking['options'] : array();
					$previous_files[ wp_normalize_path( $previous_file['source'] ) ] = $previous_file;
				}
			}
		}
		$metadata_changed = false;

		foreach ( $files as $file ) {
			$source_key  = wp_normalize_path( $this->relative_upload_path( $file['path'] ?? '' ) );
			$previous    = $source_key && isset( $previous_files[ $source_key ] ) ? $previous_files[ $source_key ] : array();
			$file_result = $this->optimize_file( $file, $force, $options, $previous );
			$status      = $file_result['status'] ?? 'failed';

			$result['files'][] = $file_result;

			if ( isset( $result['summary'][ $status ] ) ) {
				++$result['summary'][ $status ];
			}

			$avif_status = $file_result['avif_status'] ?? '';
			$avif_key    = $avif_status ? 'avif_' . $avif_status : '';

			if ( $avif_key && isset( $result['summary'][ $avif_key ] ) ) {
				++$result['summary'][ $avif_key ];
			}

			$original_status = $file_result['original_status'] ?? 'disabled';

			if ( 'optimized' === $original_status ) {
				++$result['summary']['original_optimized'];
				$metadata_changed = true;
			} elseif ( 'failed' === $original_status ) {
				++$result['summary']['original_failed'];
			} elseif ( 'disabled' !== $original_status ) {
				++$result['summary']['original_skipped'];
			}

			if ( ! empty( $file_result['backup_path'] ) ) {
				++$result['summary']['backed_up'];
			}
		}

		$tracking = $result;
		unset( $tracking['metadata'] );

		if ( empty( $options['optimize_originals'] ) ) {
			foreach ( $tracking['files'] as $index => $tracked_file ) {
				$previous = $previous_files[ wp_normalize_path( $tracked_file['source'] ) ] ?? array();
				$path     = $files[ $index ]['path'] ?? '';

				if ( empty( $previous ) ) {
					continue;
				}

				// Retain recovery references and identity even if the source changed or disappeared.
				foreach ( array( 'backup_path', 'original_input_hash', 'optimized_hash', 'original_options_fingerprint' ) as $key ) {
					if ( isset( $previous[ $key ] ) ) {
						$tracking['files'][ $index ][ $key ] = $previous[ $key ];
					}
				}

				$current_hash    = $path ? $this->file_hash( $path ) : '';
				$previous_status = sanitize_key( $previous['original_status'] ?? '' );

				// Upgrade verifiable legacy evidence before this run replaces its option provenance.
				if (
					$current_hash
					&& in_array( $previous_status, array( 'optimized', 'existing', 'no_change', 'skipped_larger' ), true )
					&& $this->legacy_original_tracking_matches( $previous, $previous['_tracking_options'] ?? array(), $path )
				) {
					$legacy_options = $previous['_tracking_options'];
					// Non-custom modes ignore this unstored value, but fingerprints include it.
					$legacy_options['jpeg_quality'] = absint( $options['jpeg_quality'] ?? 82 );
					$previous['optimized_hash'] = $current_hash;
					$tracking['files'][ $index ]['optimized_hash'] = $current_hash;
					$tracking['files'][ $index ]['original_options_fingerprint'] = $this->original_options_fingerprint( $legacy_options );
				}

				$unchanged = $current_hash && ! empty( $previous['optimized_hash'] )
					&& hash_equals( (string) $previous['optimized_hash'], $current_hash );

				if ( $unchanged ) {
					// Keep original history separate from sidecar outcomes and current-run counters.
					foreach ( array( 'original_status', 'original_message', 'original_size', 'saved_bytes', 'saved_percent', 'engine', 'mode', 'metadata_policy' ) as $key ) {
						if ( isset( $previous[ $key ] ) ) {
							$tracking['files'][ $index ][ $key ] = $previous[ $key ];
						}
					}
				} elseif ( $current_hash && 'restored' === $previous_status ) {
					$tracking['files'][ $index ]['original_status'] = 'restored';
				}
			}
		}

		$this->checkpoint_if_owned();
		update_post_meta( $attachment_id, self::META_KEY, $tracking );
		$this->invalidate_savings_cache( $attachment_id );

		if ( $metadata_changed ) {
			$result['metadata'] = $this->refresh_attachment_file_metadata( $attachment_id, $metadata, null === $metadata );
		}

		return $result;
	}

	/**
	 * Optimize one physical file.
	 *
	 * @param array $file File data.
	 * @param bool  $force Whether to rebuild existing sidecars.
	 * @param array $options Runtime options.
	 * @param array $previous Previous tracking data for this file.
	 * @return array
	 */
	private function optimize_file( $file, $force, $options, $previous = array() ) {
		$path   = $file['path'] ?? '';
		$result = array(
			'label'            => $file['label'] ?? '',
			'source'           => $this->relative_upload_path( $path ),
			'source_size'      => 0,
			'original_size'    => 0,
			'optimized_size'   => 0,
			'saved_bytes'      => 0,
			'saved_percent'    => 0,
			'source_mtime'     => 0,
			'sidecar'          => '',
			'webp_sidecar'     => '',
			'sidecar_size'     => 0,
			'webp_size'        => 0,
			'webp_engine'      => '',
			'avif_sidecar'     => '',
			'avif_size'        => 0,
			'avif_engine'      => '',
			'backup_path'      => '',
			'engine'           => '',
			'mode'             => sanitize_key( $options['compression_mode'] ?? 'balanced' ),
			'metadata_policy'  => sanitize_key( $options['metadata_policy'] ?? 'remove' ),
			'original_status'  => 'disabled',
			'original_message' => '',
			'status'           => 'disabled',
			'message'          => '',
			'avif_status'      => 'disabled',
			'avif_message'     => '',
		);

		if ( $path && ! empty( $options['optimize_originals'] ) && ! $this->relative_upload_path_for_storage( $path ) ) {
			$result['status'] = 'failed';
			$result['original_status'] = 'failed';
			$result['message'] = __( 'The original path is outside safe uploads storage or contains a symlink.', 'yoohw-media-optimizer' );
			$result['original_message'] = $result['message'];
			return $result;
		}

		if ( ! $path || ! file_exists( $path ) ) {
			$result['message'] = __( 'Source file is missing.', 'yoohw-media-optimizer' );
			$result['status']  = 'failed';

			if ( ! empty( $options['optimize_originals'] ) ) {
				$result['original_status']  = 'failed';
				$result['original_message'] = $result['message'];
			}

			return $result;
		}

		$result['source_size']    = (int) filesize( $path );
		$result['original_size']  = $result['source_size'];
		$result['optimized_size'] = $result['source_size'];
		$result['source_mtime']   = (int) filemtime( $path );

		if ( ! $this->is_supported_source_path( $path ) ) {
			$result['status']           = 'unsupported';
			$result['message']          = __( 'Only JPEG and PNG sources are supported in this version.', 'yoohw-media-optimizer' );
			$result['original_status']  = ! empty( $options['optimize_originals'] ) ? 'unsupported' : 'disabled';
			$result['original_message'] = $result['message'];
			return $result;
		}

		if ( ! empty( $options['optimize_originals'] ) ) {
			$original = $this->optimize_original_file( $path, $options, $force, $previous );

			$result['original_status']  = $original['status'];
			$result['original_message'] = $original['message'];
			$result['backup_path']      = $original['backup_path'];
			$result['engine']           = $original['engine'];
			$result['original_size']    = absint( $original['original_size'] );
			$result['optimized_size']   = absint( $original['optimized_size'] );
			$result['saved_bytes']      = absint( $original['saved_bytes'] );
			$result['saved_percent']    = (float) $original['saved_percent'];
			$result['original_input_hash'] = $original['input_hash'] ?? '';
			$result['optimized_hash']      = $original['optimized_hash'] ?? '';
			$result['original_options_fingerprint'] = $original['options_fingerprint'] ?? '';

			if ( file_exists( $path ) ) {
				$result['source_size']    = (int) filesize( $path );
				$result['optimized_size'] = (int) filesize( $path );
				$result['source_mtime']   = (int) filemtime( $path );
			}
		}

		if ( ! empty( $options['generate_webp_sidecars'] ) ) {
			$sidecar                 = $this->sidecar_path( $path );
			$result['sidecar']       = $this->relative_upload_path( $sidecar );
			$result['webp_sidecar']  = $result['sidecar'];

			if ( ! $force && file_exists( $sidecar ) && filemtime( $sidecar ) >= filemtime( $path ) ) {
				$result['status']       = 'existing';
				$result['sidecar_size'] = (int) filesize( $sidecar );
				$result['webp_size']    = $result['sidecar_size'];
			} else {
				$generated = $this->generate_webp_sidecar( $path, $sidecar );

				$result['status']       = $generated['status'];
				$result['message']      = $generated['message'];
				$result['webp_engine']  = $generated['engine'] ?? '';
				$result['sidecar_size'] = file_exists( $sidecar ) ? (int) filesize( $sidecar ) : 0;
				$result['webp_size']    = $result['sidecar_size'];
			}
		}

		if ( ! empty( $options['generate_avif_sidecars'] ) ) {
			$avif                  = $this->format_sidecar_path( $path, 'avif' );
			$result['avif_sidecar'] = $this->relative_upload_path( $avif );

			if ( ! $force && file_exists( $avif ) && filemtime( $avif ) >= filemtime( $path ) ) {
				$result['avif_status'] = 'existing';
				$result['avif_size']   = (int) filesize( $avif );
			} else {
				$generated = $this->generate_avif_sidecar( $path, $avif );

				$result['avif_status']  = $generated['status'];
				$result['avif_message'] = $generated['message'];
				$result['avif_engine']  = $generated['engine'] ?? '';
				$result['avif_size']    = file_exists( $avif ) ? (int) filesize( $avif ) : 0;
			}
		}

		return $result;
	}

	/**
	 * Generate a WebP sidecar for one source image.
	 *
	 * @param string $source_path Source path.
	 * @param string $sidecar_path Sidecar path.
	 * @return array
	 */
	private function generate_webp_sidecar( $source_path, $sidecar_path ) {
		$options = $this->get_options();

		if ( ! $this->webp_supported() ) {
			if ( ! $this->external_binary_available( 'cwebp' ) ) {
				return array(
					'status'  => 'failed',
					'message' => __( 'The active image editor cannot write WebP files and cwebp is unavailable.', 'yoohw-media-optimizer' ),
					'engine'  => '',
				);
			}
		}

		if ( ! $this->webp_generation_supported() ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'The active image editor cannot write WebP files.', 'yoohw-media-optimizer' ),
				'engine'  => '',
			);
		}

		$existed = file_exists( $sidecar_path );
		$tmp     = $sidecar_path . '.tmp-' . wp_generate_password( 8, false, false ) . '.webp';
		$saved   = $this->save_webp_file( $source_path, $tmp, $options['webp_quality'] );

		if ( is_wp_error( $saved ) ) {
			wp_delete_file( $tmp );
			return array(
				'status'  => 'failed',
				'message' => $saved->get_error_message(),
				'engine'  => '',
			);
		}

		$saved_path = ! empty( $saved['path'] ) ? $saved['path'] : $tmp;
		$engine     = ! empty( $saved['engine'] ) ? $saved['engine'] : '';

		if ( ! file_exists( $saved_path ) ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'WebP file was not created by the image editor.', 'yoohw-media-optimizer' ),
				'engine'  => $engine,
			);
		}

		$source_size = filesize( $source_path );
		$webp_size   = filesize( $saved_path );

		if ( ! empty( $options['skip_larger_files'] ) && $source_size > 0 && $webp_size >= $source_size ) {
			wp_delete_file( $saved_path );

			return array(
				'status'  => 'skipped_larger',
				'message' => __( 'Generated WebP was larger than the source file.', 'yoohw-media-optimizer' ),
				'engine'  => $engine,
			);
		}

		wp_mkdir_p( dirname( $sidecar_path ) );

		if ( ! $this->move_file( $saved_path, $sidecar_path ) ) {
			wp_delete_file( $saved_path );

			return array(
				'status'  => 'failed',
				'message' => __( 'Could not move the generated WebP file into place.', 'yoohw-media-optimizer' ),
				'engine'  => $engine,
			);
		}

		return array(
			'status'  => $existed ? 'updated' : 'created',
			'message' => '',
			'engine'  => $engine,
		);
	}

	/**
	 * Generate an AVIF sidecar for one source image.
	 *
	 * @param string $source_path Source path.
	 * @param string $sidecar_path Sidecar path.
	 * @return array
	 */
	private function generate_avif_sidecar( $source_path, $sidecar_path ) {
		$options = $this->get_options();

		if ( ! $this->avif_supported() && false === $this->png_sidecar_profile( $source_path, 'avif_alpha_mode' ) ) {
			return array(
				'status'  => 'unsupported',
				'message' => __( 'The active image editor cannot write AVIF files and avifenc is unavailable.', 'yoohw-media-optimizer' ),
				'engine'  => '',
			);
		}

		$existed = file_exists( $sidecar_path );
		$tmp     = $sidecar_path . '.tmp-' . wp_generate_password( 8, false, false ) . '.avif';
		$saved   = $this->save_avif_file( $source_path, $tmp, $options['avif_quality'] );

		if ( is_wp_error( $saved ) ) {
			wp_delete_file( $tmp );
			return array(
				'status'  => 'failed',
				'message' => $saved->get_error_message(),
				'engine'  => '',
			);
		}

		$saved_path = ! empty( $saved['path'] ) ? $saved['path'] : $tmp;
		$engine     = ! empty( $saved['engine'] ) ? $saved['engine'] : '';

		if ( ! file_exists( $saved_path ) ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'AVIF file was not created by the selected engine.', 'yoohw-media-optimizer' ),
				'engine'  => $engine,
			);
		}

		$source_size = filesize( $source_path );
		$avif_size   = filesize( $saved_path );

		if ( ! empty( $options['skip_larger_files'] ) && $source_size > 0 && $avif_size >= $source_size ) {
			wp_delete_file( $saved_path );

			return array(
				'status'  => 'skipped_larger',
				'message' => __( 'Generated AVIF was larger than the source file.', 'yoohw-media-optimizer' ),
				'engine'  => $engine,
			);
		}

		wp_mkdir_p( dirname( $sidecar_path ) );

		if ( ! $this->move_file( $saved_path, $sidecar_path ) ) {
			wp_delete_file( $saved_path );

			return array(
				'status'  => 'failed',
				'message' => __( 'Could not move the generated AVIF file into place.', 'yoohw-media-optimizer' ),
				'engine'  => $engine,
			);
		}

		return array(
			'status'  => $existed ? 'updated' : 'created',
			'message' => '',
			'engine'  => $engine,
		);
	}

	/** Resolve a requested native PNG profile without reducing high-bit-depth input. */
	private function png_sidecar_profile( $source, $option ) {
		if ( 'lossless' !== $this->get_options()[ $option ] ) {
			return false;
		}
		$image = wp_getimagesize( $source );
		if ( ! is_array( $image ) || IMAGETYPE_PNG !== $image[2] ) {
			return false;
		}
		if ( empty( $image['bits'] ) || ! in_array( (int) $image['bits'], array( 1, 2, 4, 8 ), true ) ) {
			return new WP_Error( 'yoohw_mo_png_profile_depth', __( 'Advanced PNG sidecar modes require a valid PNG with at most 8 bits per channel. The source was left unchanged.', 'yoohw-media-optimizer' ) );
		}
		return true;
	}

	/** Read JPEG display orientation without assuming an unavailable reader means upright. */
	private function jpeg_orientation( $path ) {
		if ( ! $this->is_jpeg_path( $path ) ) {
			return 1;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( ! is_callable( 'exif_read_data' ) || in_array( 'exif_read_data', $disabled, true ) ) {
			return new WP_Error( 'yoohw_mo_exif_unavailable', __( 'JPEG encoding needs PHP EXIF support to preserve display orientation. The source was left unchanged.', 'yoohw-media-optimizer' ) );
		}
		$exif = @exif_read_data( $path );
		$orientation = isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;
		return $orientation >= 1 && $orientation <= 8 ? $orientation : new WP_Error( 'yoohw_mo_exif_orientation', __( 'The JPEG orientation cannot be normalized safely.', 'yoohw-media-optimizer' ) );
	}

	/** Keep the normal editor order while preserving ICC in core Imagick PNG sizes. */
	public function thumbnail_image_editors( $editors ) {
		if ( ! class_exists( 'WP_Image_Editor_Imagick' ) || ! in_array( 'WP_Image_Editor_Imagick', $editors, true ) ) { return $editors; }
		require_once __DIR__ . '/class-yoohw-media-optimizer-imagick.php';
		require_once __DIR__ . '/class-yoohw-media-optimizer-thumbnail-imagick.php';
		return array_map( static function ( $editor ) { return 'WP_Image_Editor_Imagick' === $editor ? 'YoOhw_Media_Optimizer_Thumbnail_Imagick' : $editor; }, $editors );
	}

	/** Select the normal WordPress editor with a narrow Imagick save adapter. */
	private function color_image_editor( $source, $args = array() ) {
		$filter = static function ( $editors ) {
			if ( ! in_array( 'WP_Image_Editor_Imagick', $editors, true ) && ! in_array( 'YoOhw_Media_Optimizer_Thumbnail_Imagick', $editors, true ) ) { return $editors; }
			// WordPress loads its editor base classes before applying this filter.
			require_once __DIR__ . '/class-yoohw-media-optimizer-imagick.php';
			return array_map( static function ( $editor ) { return in_array( $editor, array( 'WP_Image_Editor_Imagick', 'YoOhw_Media_Optimizer_Thumbnail_Imagick' ), true ) ? 'YoOhw_Media_Optimizer_Imagick' : $editor; }, $editors );
		};
		add_filter( 'wp_image_editors', $filter, PHP_INT_MAX );
		try { return wp_get_image_editor( $source, $args ); }
		finally { remove_filter( 'wp_image_editors', $filter, PHP_INT_MAX ); }
	}

	/** Strip privacy metadata in memory before the one save; never inject an ICC. */
	private function save_color_editor( $editor, $source, $destination, $mime, $options, $quality = null ) {
		$color = YoOhw_Media_Optimizer_Color::inspect( $source );
		if ( is_wp_error( $color ) ) { return $color; }
		if ( $editor instanceof YoOhw_Media_Optimizer_Imagick ) {
			$prepared = $editor->prepare_color( $color['icc'], 'remove' === $options['metadata_policy'] );
			if ( is_wp_error( $prepared ) ) { return $prepared; }
			if ( null !== $quality && 'image/avif' === $mime && '444' === ( $options['avif_imagick_chroma'] ?? 'default' ) ) {
				$chroma = $editor->request_avif444();
				if ( is_wp_error( $chroma ) ) { return $chroma; }
			}
		} elseif ( $color['icc'] ) {
			return new WP_Error( 'yoohw_mo_color_editor', __( 'The active image editor cannot preserve this color profile. The source was left unchanged.', 'yoohw-media-optimizer' ) );
		}
		if ( null === $quality ) { return $editor->save( $destination, $mime ); }
		// WordPress resets quality while resolving a new output MIME during save().
		$filter = static function ( $default, $output_mime ) use ( $mime, $quality ) {
			return $mime === $output_mime ? $quality : $default;
		};
		add_filter( 'wp_editor_set_quality', $filter, PHP_INT_MAX, 2 );
		try {
			$set = $editor->set_quality( $quality );
			if ( is_wp_error( $set ) ) { return $set; }
			return $editor->save( $destination, $mime );
		} finally {
			remove_filter( 'wp_editor_set_quality', $filter, PHP_INT_MAX );
		}
	}

	/** Normalize pixels through the existing editor instead of dropping the orientation tag. */
	private function save_oriented_sidecar( $source, $destination, $quality, $mime ) {
		$editor = $this->color_image_editor( $source, array( 'mime_type' => $mime ) );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$rotated = method_exists( $editor, 'maybe_exif_rotate' ) ? $editor->maybe_exif_rotate() : false;
		if ( is_wp_error( $rotated ) || true !== $rotated ) {
			return is_wp_error( $rotated ) ? $rotated : new WP_Error( 'yoohw_mo_exif_rotation', __( 'The image editor could not normalize JPEG display orientation.', 'yoohw-media-optimizer' ) );
		}
		$saved = $this->save_color_editor( $editor, $source, $destination, $mime, $this->get_options(), $quality );
		if ( ! is_wp_error( $saved ) ) {
			$saved['engine'] = get_class( $editor );
		}
		return $saved;
	}

	/**
	 * Save a source image as WebP.
	 *
	 * @param string $source_path Source image path.
	 * @param string $destination Destination WebP path.
	 * @param int    $quality WebP quality.
	 * @return array|WP_Error
	 */
	private function save_webp_file( $source_path, $destination, $quality ) {
		$color = YoOhw_Media_Optimizer_Color::inspect( $source_path );
		if ( is_wp_error( $color ) ) { return $color; }
		$saved = $this->save_webp_file_unverified( $source_path, $destination, $quality );
		if ( is_wp_error( $saved ) ) { return $saved; }
		if ( ! empty( $saved['path'] ) && $destination !== $saved['path'] ) {
			return new WP_Error( 'yoohw_mo_color_output_path', __( 'The image editor returned an unexpected output path.', 'yoohw-media-optimizer' ) );
		}
		$verified = YoOhw_Media_Optimizer_Color::verify( $source_path, $destination, 'image/webp', 'remove' === $this->get_options()['metadata_policy'] );
		if ( is_wp_error( $verified ) ) { wp_delete_file( $destination ); return $verified; }
		return $saved;
	}

	private function save_webp_file_unverified( $source_path, $destination, $quality ) {
		$profile = $this->png_sidecar_profile( $source_path, 'webp_png_mode' );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$orientation = $this->jpeg_orientation( $source_path );
		if ( is_wp_error( $orientation ) ) {
			return $orientation;
		}
		if ( $orientation > 1 ) {
			return $this->save_oriented_sidecar( $source_path, $destination, $quality, 'image/webp' );
		}

		$saved = $this->save_webp_file_with_cwebp( $source_path, $destination, $quality, $profile );

		if ( $profile || ! is_wp_error( $saved ) ) {
			return $saved;
		}

		$color = YoOhw_Media_Optimizer_Color::inspect( $source_path );
		$saved = ! is_wp_error( $color ) && ! $color['icc']
			? $this->save_webp_file_with_gd( $source_path, $destination, $quality )
			: new WP_Error( 'yoohw_mo_gd_unavailable', 'GD cannot retain ICC profiles.' );

		if ( ! is_wp_error( $saved ) || 'yoohw_mo_gd_unavailable' !== $saved->get_error_code() ) {
			return $saved;
		}

		$editor = $this->color_image_editor( $source_path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$saved = $this->save_color_editor( $editor, $source_path, $destination, 'image/webp', $this->get_options(), $quality );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$saved['engine'] = is_object( $editor ) ? get_class( $editor ) : 'wp_image_editor';

		return $saved;
	}

	/**
	 * Save a source image as WebP with cwebp.
	 *
	 * @param string $source_path Source image path.
	 * @param string $destination Destination WebP path.
	 * @param int    $quality WebP quality.
	 * @param bool   $profile Use the lossless PNG profile.
	 * @return array|WP_Error
	 */
	private function save_webp_file_with_cwebp( $source_path, $destination, $quality, $profile = false ) {
		$binary = $this->external_binary_path( 'cwebp' );

		if ( ! $binary ) {
			return new WP_Error( 'yoohw_mo_cwebp_unavailable', __( 'cwebp is unavailable.', 'yoohw-media-optimizer' ) );
		}

		wp_mkdir_p( dirname( $destination ) );

		$command = array(
			$binary,
			'-quiet',
			'-metadata',
			'preserve_all' === $this->get_options()['metadata_policy'] ? 'all' : 'icc',
		);
		$encoding = $profile ? array( '-lossless', '-q', '75', '-m', '4' ) : array( '-q', (string) max( 1, min( 100, absint( $quality ) ) ) );
		$command = array_merge( $command, $encoding, array(
			$source_path,
			'-o',
			$destination,
		) );
		$run     = $this->run_external_binary( $command, 45 );

		if ( is_wp_error( $run ) ) {
			wp_delete_file( $destination );
			return $run;
		}

		if ( ! file_exists( $destination ) ) {
			return new WP_Error( 'yoohw_mo_cwebp_missing_output', __( 'cwebp did not create a WebP file.', 'yoohw-media-optimizer' ) );
		}

		return array(
			'path'   => $destination,
			'engine' => 'cwebp',
		);
	}

	/**
	 * Save a source image as AVIF.
	 *
	 * @param string $source_path Source image path.
	 * @param string $destination Destination AVIF path.
	 * @param int    $quality AVIF quality.
	 * @return array|WP_Error
	 */
	private function save_avif_file( $source_path, $destination, $quality ) {
		$color = YoOhw_Media_Optimizer_Color::inspect( $source_path );
		if ( is_wp_error( $color ) ) { return $color; }
		$saved = $this->save_avif_file_unverified( $source_path, $destination, $quality );
		if ( is_wp_error( $saved ) ) { return $saved; }
		if ( ! empty( $saved['path'] ) && $destination !== $saved['path'] ) {
			return new WP_Error( 'yoohw_mo_color_output_path', __( 'The image editor returned an unexpected output path.', 'yoohw-media-optimizer' ) );
		}
		$verified = YoOhw_Media_Optimizer_Color::verify( $source_path, $destination, 'image/avif', 'remove' === $this->get_options()['metadata_policy'] );
		if ( is_wp_error( $verified ) ) { wp_delete_file( $destination ); return $verified; }
		return $saved;
	}

	private function save_avif_file_unverified( $source_path, $destination, $quality ) {
		$profile = $this->png_sidecar_profile( $source_path, 'avif_alpha_mode' );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$orientation = $this->jpeg_orientation( $source_path );
		if ( is_wp_error( $orientation ) ) {
			return $orientation;
		}
		if ( $orientation > 1 ) {
			return $this->save_oriented_sidecar( $source_path, $destination, $quality, 'image/avif' );
		}

		$saved = $this->save_avif_file_with_avifenc( $source_path, $destination, $quality, $profile );

		if ( $profile || ! is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( ! $this->avif_editor_supported() ) {
			return new WP_Error( 'yoohw_mo_avif_unavailable', __( 'AVIF support is unavailable.', 'yoohw-media-optimizer' ) );
		}

		$editor = $this->color_image_editor( $source_path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$saved = $this->save_color_editor( $editor, $source_path, $destination, 'image/avif', $this->get_options(), $quality );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$saved['engine'] = is_object( $editor ) ? get_class( $editor ) : 'wp_image_editor';

		return $saved;
	}

	/**
	 * Save a source image as AVIF with avifenc.
	 *
	 * @param string $source_path Source image path.
	 * @param string $destination Destination AVIF path.
	 * @param int    $quality AVIF quality.
	 * @param bool   $profile Use lossless PNG alpha.
	 * @return array|WP_Error
	 */
	private function save_avif_file_with_avifenc( $source_path, $destination, $quality, $profile = false ) {
		$binary = $this->external_binary_path( 'avifenc' );

		if ( ! $binary ) {
			return new WP_Error( 'yoohw_mo_avifenc_unavailable', __( 'avifenc is unavailable.', 'yoohw-media-optimizer' ) );
		}

		wp_mkdir_p( dirname( $destination ) );
		$privacy = 'remove' === $this->get_options()['metadata_policy'] ? array( '--ignore-exif', '--ignore-xmp' ) : array();

		$run = $this->run_external_binary(
			array_merge( array( $binary ), $privacy, $profile ? array( '--minalpha', '0', '--maxalpha', '0', '--jobs', '1' ) : array(), array(
				'-q',
				(string) max( 1, min( 100, absint( $quality ) ) ),
				$source_path,
				$destination,
			) ),
			120
		);

		if ( is_wp_error( $run ) ) {
			wp_delete_file( $destination );
			return $run;
		}

		if ( ! file_exists( $destination ) ) {
			return new WP_Error( 'yoohw_mo_avifenc_missing_output', __( 'avifenc did not create an AVIF file.', 'yoohw-media-optimizer' ) );
		}

		return array(
			'path'   => $destination,
			'engine' => 'avifenc',
		);
	}

	/**
	 * Save JPEG/PNG as WebP with GD while normalizing palette PNGs.
	 *
	 * @param string $source_path Source image path.
	 * @param string $destination Destination WebP path.
	 * @param int    $quality WebP quality.
	 * @return array|WP_Error
	 */
	private function save_webp_file_with_gd( $source_path, $destination, $quality ) {
		$color = YoOhw_Media_Optimizer_Color::inspect( $source_path );
		if ( is_wp_error( $color ) ) { return $color; }
		if ( $color['icc'] ) { return new WP_Error( 'yoohw_mo_color_editor', 'GD cannot retain ICC profiles.' ); }

		if ( ! function_exists( 'imagewebp' ) ) {
			return new WP_Error( 'yoohw_mo_gd_unavailable', __( 'GD WebP support is unavailable.', 'yoohw-media-optimizer' ) );
		}

		$extension = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
		$image     = false;

		if ( in_array( $extension, array( 'jpg', 'jpeg' ), true ) ) {
			if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
				return new WP_Error( 'yoohw_mo_gd_unavailable', __( 'GD JPEG support is unavailable.', 'yoohw-media-optimizer' ) );
			}

			$image = @imagecreatefromjpeg( $source_path );
		} elseif ( 'png' === $extension ) {
			if ( ! function_exists( 'imagecreatefrompng' ) ) {
				return new WP_Error( 'yoohw_mo_gd_unavailable', __( 'GD PNG support is unavailable.', 'yoohw-media-optimizer' ) );
			}

			$image = @imagecreatefrompng( $source_path );

			if ( $image && function_exists( 'imageistruecolor' ) && function_exists( 'imagepalettetotruecolor' ) && ! imageistruecolor( $image ) ) {
				imagepalettetotruecolor( $image );
			}

			if ( $image && function_exists( 'imagealphablending' ) ) {
				imagealphablending( $image, true );
			}

			if ( $image && function_exists( 'imagesavealpha' ) ) {
				imagesavealpha( $image, true );
			}
		} else {
			return new WP_Error( 'yoohw_mo_gd_unavailable', __( 'GD conversion is unavailable for this source type.', 'yoohw-media-optimizer' ) );
		}

		if ( ! $image ) {
			return new WP_Error( 'yoohw_mo_image_load_failed', __( 'Could not load source image for WebP conversion.', 'yoohw-media-optimizer' ) );
		}

		$saved = imagewebp( $image, $destination, $quality );

		if ( function_exists( 'imagedestroy' ) ) {
			imagedestroy( $image );
		}

		if ( ! $saved || ! file_exists( $destination ) ) {
			return new WP_Error( 'yoohw_mo_image_save_failed', __( 'Could not save the generated WebP file.', 'yoohw-media-optimizer' ) );
		}

		return array(
			'path'   => $destination,
			'engine' => 'gd',
		);
	}

	/**
	 * Resize/recompress one original JPEG/PNG file after creating a backup.
	 *
	 * @param string $path Source file path.
	 * @param array  $options Runtime options.
	 * @param bool   $force Whether this pass was forced.
	 * @param array  $previous Previous tracking data for this file.
	 * @return array
	 */
	private function optimize_original_file( $path, $options, $force, $previous = array() ) {
		$safe_source = $this->relative_upload_path_for_storage( $path );
		$source_size  = $safe_source && is_file( $path ) ? (int) filesize( $path ) : 0;
		$backup_path  = $this->backup_path_for( $path );

		if ( $backup_path && ! file_exists( $backup_path ) ) {
			$legacy_backup = $this->legacy_backup_path_for( $path );

			if ( $legacy_backup && file_exists( $legacy_backup ) ) {
				$migrated = $this->backup_file( $path );

				if ( ! is_wp_error( $migrated ) && ! empty( $migrated['path'] ) ) {
					$backup_path = $migrated['path'];
				}
			}
		}

		$backup_value = $backup_path && file_exists( $backup_path ) ? $this->backup_reference_for( $backup_path ) : '';
		$options_fingerprint = $this->original_options_fingerprint( $options );
		$input_hash    = $safe_source ? $this->file_hash( $path ) : '';
		$result       = array(
			'status'         => 'no_change',
			'message'        => '',
			'engine'         => '',
			'backup_path'    => $backup_value,
			'original_size'  => $source_size,
			'optimized_size' => $source_size,
			'saved_bytes'    => 0,
			'saved_percent'  => 0,
			'input_hash'      => $input_hash,
			'optimized_hash'  => $input_hash,
			'options_fingerprint' => $options_fingerprint,
		);

		if ( $backup_path && file_exists( $backup_path ) && $source_size > 0 ) {
			$backup_size = (int) filesize( $backup_path );

			if ( $backup_size > $source_size ) {
				$result['original_size']  = $backup_size;
				$result['optimized_size'] = $source_size;
				$result['saved_bytes']    = $backup_size - $source_size;
				$result['saved_percent']  = ( $result['saved_bytes'] / $backup_size ) * 100;
			}
		}

		if ( ! $safe_source ) {
			$result['status'] = 'failed';
			$result['message'] = __( 'The original path is outside safe uploads storage or contains a symlink.', 'yoohw-media-optimizer' );
			return $result;
		}

		if ( empty( $options['backup_originals'] ) ) {
			$result['status']  = 'failed';
			$result['message'] = __( 'Original-file optimization requires backups to be enabled.', 'yoohw-media-optimizer' );
			return $result;
		}

		if ( ! $path || ! file_exists( $path ) ) {
			$result['status']  = 'failed';
			$result['message'] = __( 'Source file is missing.', 'yoohw-media-optimizer' );
			return $result;
		}

		if ( ! $this->is_supported_source_path( $path ) ) {
			$result['status']  = 'unsupported';
			$result['message'] = __( 'Only JPEG and PNG sources can be optimized locally.', 'yoohw-media-optimizer' );
			return $result;
		}

		$previous_status      = sanitize_key( $previous['original_status'] ?? '' );
		$previous_fingerprint = (string) ( $previous['original_options_fingerprint'] ?? '' );
		$previous_output_hash = (string) ( $previous['optimized_hash'] ?? '' );
		$fingerprint_matches  = $input_hash
			&& $previous_fingerprint
			&& $previous_output_hash
			&& hash_equals( $options_fingerprint, $previous_fingerprint )
			&& hash_equals( $input_hash, $previous_output_hash );
		$legacy_matches       = $this->legacy_original_tracking_matches( $previous, $options, $path );

		if (
			! $force
			&& ( $fingerprint_matches || $legacy_matches )
			&& in_array( $previous_status, array( 'optimized', 'existing', 'no_change', 'skipped_larger' ), true )
		) {
			$result['status']  = 'existing';
			$result['engine']  = sanitize_text_field( $previous['engine'] ?? '' );
			$result['message'] = __( 'Original file already matches the current optimization settings.', 'yoohw-media-optimizer' );
			return $result;
		}

		$color = YoOhw_Media_Optimizer_Color::inspect( $path );
		if ( is_wp_error( $color ) ) {
			$result['status'] = 'failed'; $result['message'] = $color->get_error_message(); return $result;
		}

		$orientation = $this->jpeg_orientation( $path );
		if ( is_wp_error( $orientation ) ) {
			$result['status'] = 'failed';
			$result['message'] = $orientation->get_error_message();
			return $result;
		}

		$dimensions = $this->image_dimensions( $path );

		if ( empty( $dimensions['width'] ) || empty( $dimensions['height'] ) ) {
			$result['status']  = 'failed';
			$result['message'] = __( 'Could not read image dimensions.', 'yoohw-media-optimizer' );
			return $result;
		}

		if ( $orientation >= 5 ) {
			$dimensions = array( 'width' => $dimensions['height'], 'height' => $dimensions['width'] );
		}

		$target       = $this->constrained_dimensions( $dimensions['width'], $dimensions['height'], absint( $options['max_width'] ?? 0 ), absint( $options['max_height'] ?? 0 ) );
		$needs_resize = $target['width'] < $dimensions['width'] || $target['height'] < $dimensions['height'];
		$mode         = sanitize_key( $options['compression_mode'] ?? 'balanced' );
		$metadata_policy = sanitize_key( $options['metadata_policy'] ?? 'remove' );
		$needs_encode = $needs_resize || 'lossless' !== $mode || 'remove' === $metadata_policy || $force;

		if ( ! $needs_encode ) {
			return $result;
		}

		if ( $orientation > 1 && ! $needs_resize && 'lossless' === $mode && 'remove' === $metadata_policy ) {
			$result['message'] = __( 'The JPEG was left unchanged because removing its orientation without a lossless display transform would change its appearance.', 'yoohw-media-optimizer' );
			return $result;
		}

		$saved_path    = '';
		$binary_result = $orientation > 1 && 'remove' === $metadata_policy
			? new WP_Error( 'yoohw_mo_orientation_editor', 'Display normalization requires the image editor.' )
			: $this->optimize_original_file_with_external_binary( $path, $options, $needs_resize );

		if ( ! is_wp_error( $binary_result ) && ! empty( $binary_result['path'] ) && file_exists( $binary_result['path'] ) ) {
			$saved_path        = $binary_result['path'];
			$result['engine']  = $binary_result['engine'];
		}

		if ( ! $saved_path && ! $needs_resize && 'lossless' === $mode && $this->is_jpeg_path( $path ) ) {
			$result['message'] = __( 'The JPEG was left unchanged because no smaller lossless output was available and resizing was not needed.', 'yoohw-media-optimizer' );
			return $result;
		}

		if ( ! $saved_path ) {
			$editor = $this->color_image_editor( $path );

			if ( is_wp_error( $editor ) ) {
				$result['status']  = 'failed';
				$result['message'] = $editor->get_error_message();
				return $result;
			}

			$result['engine'] = is_object( $editor ) ? get_class( $editor ) : '';

			if ( $orientation > 1 && ! method_exists( $editor, 'maybe_exif_rotate' ) ) {
				$result['status'] = 'failed';
				$result['message'] = __( 'The image editor cannot normalize JPEG display orientation.', 'yoohw-media-optimizer' );
				return $result;
			}

			if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
				$rotated = $editor->maybe_exif_rotate();

				if ( is_wp_error( $rotated ) || ( $orientation > 1 && true !== $rotated ) ) {
					$result['status']  = 'failed';
					$result['message'] = is_wp_error( $rotated ) ? $rotated->get_error_message() : __( 'The image editor could not normalize JPEG display orientation.', 'yoohw-media-optimizer' );
					return $result;
				}
			}

			$display_size = $editor->get_size();
			$target = $this->constrained_dimensions( $display_size['width'], $display_size['height'], absint( $options['max_width'] ?? 0 ), absint( $options['max_height'] ?? 0 ) );
			$needs_resize = $target['width'] < $display_size['width'] || $target['height'] < $display_size['height'];

			if ( $needs_resize ) {
				$resized = $editor->resize( $target['width'], $target['height'], false );

				if ( is_wp_error( $resized ) ) {
					$result['status']  = 'failed';
					$result['message'] = $resized->get_error_message();
					return $result;
				}
			}

			if ( method_exists( $editor, 'set_quality' ) && $this->is_jpeg_path( $path ) ) {
				$editor->set_quality( $this->jpeg_quality_for_mode( $mode, absint( $options['jpeg_quality'] ?? 82 ) ) );
			}

			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$mime      = $this->mime_for_path( $path );
			$tmp       = trailingslashit( dirname( $path ) ) . '.' . wp_basename( $path ) . '.yoohw-mo-' . wp_generate_password( 8, false, false ) . '.' . $extension;
			$saved     = $this->save_color_editor( $editor, $path, $tmp, $mime, $options );

			if ( is_wp_error( $saved ) ) {
				wp_delete_file( $tmp );
				$result['status']  = 'failed';
				$result['message'] = $saved->get_error_message();
				return $result;
			}

			if ( ! empty( $saved['path'] ) && $tmp !== $saved['path'] ) {
				wp_delete_file( $tmp ); $result['status'] = 'failed'; $result['message'] = __( 'The image editor returned an unexpected output path.', 'yoohw-media-optimizer' ); return $result;
			}

			$saved_path = ! empty( $saved['path'] ) ? $saved['path'] : $tmp;

			$binary_result = $this->optimize_temp_file_with_external_binary( $saved_path, $options );

			if ( ! is_wp_error( $binary_result ) && ! empty( $binary_result['path'] ) && file_exists( $binary_result['path'] ) ) {
				if ( ! empty( $binary_result['replaced'] ) ) {
					$result['engine'] .= '+' . $binary_result['engine'];
				}

				$saved_path = $binary_result['path'];
			}
		}

		if ( ! file_exists( $saved_path ) ) {
			$result['status']  = 'failed';
			$result['message'] = __( 'Optimized file was not created by the image editor.', 'yoohw-media-optimizer' );
			return $result;
		}

		$verified = YoOhw_Media_Optimizer_Color::verify( $path, $saved_path, $this->mime_for_path( $path ), 'remove' === $metadata_policy );
		if ( is_wp_error( $verified ) ) {
			wp_delete_file( $saved_path ); $result['status'] = 'failed'; $result['message'] = $verified->get_error_message(); return $result;
		}

		$optimized_size = (int) filesize( $saved_path );

		if ( $source_size > 0 && $optimized_size >= $source_size ) {
			wp_delete_file( $saved_path );

			$result['status']         = 'skipped_larger';
			$result['optimized_size'] = $source_size;
			$result['message']        = __( 'Optimized file was not smaller than the source.', 'yoohw-media-optimizer' );
			return $result;
		}

		$backup = $this->backup_file( $path );

		if ( is_wp_error( $backup ) ) {
			wp_delete_file( $saved_path );

			$result['status']  = 'failed';
			$result['message'] = $backup->get_error_message();
			return $result;
		}

		if ( ! $this->replace_original_file( $saved_path, $path ) ) {
			wp_delete_file( $saved_path );

			$result['status']  = 'failed';
			$result['message'] = __( 'Could not replace the source file with the optimized version.', 'yoohw-media-optimizer' );
			return $result;
		}

		wp_delete_file( $saved_path );
		clearstatcache( true, $path );

		$result['status']         = 'optimized';
		$result['backup_path']    = $backup['relative'];
		$result['optimized_size'] = (int) filesize( $path );
		$result['optimized_hash'] = $this->file_hash( $path );
		$result['original_size']  = file_exists( $backup['path'] ) ? (int) filesize( $backup['path'] ) : $source_size;
		$result['saved_bytes']    = max( 0, $result['original_size'] - $result['optimized_size'] );
		$result['saved_percent']  = $result['original_size'] > 0 ? ( $result['saved_bytes'] / $result['original_size'] ) * 100 : 0;

		return $result;
	}

	/**
	 * Build a stable fingerprint for options that affect original-file output.
	 *
	 * @param array $options Runtime options.
	 * @return string
	 */
	private function original_options_fingerprint( $options ) {
		$relevant = array(
			'compression_mode'      => sanitize_key( $options['compression_mode'] ?? 'balanced' ),
			'jpeg_quality'          => absint( $options['jpeg_quality'] ?? 82 ),
			'metadata_policy'       => sanitize_key( $options['metadata_policy'] ?? 'remove' ),
			'max_width'             => absint( $options['max_width'] ?? 0 ),
			'max_height'            => absint( $options['max_height'] ?? 0 ),
			'use_external_binaries' => empty( $options['use_external_binaries'] ) ? 0 : 1,
			'optimizer_version'     => YOOHW_MEDIA_OPTIMIZER_VERSION,
		);

		return hash( 'sha256', wp_json_encode( $relevant ) );
	}

	/**
	 * Safely recognize tracking written before file hashes were introduced.
	 *
	 * @param array  $previous Previous file tracking.
	 * @param array  $options Current runtime options.
	 * @param string $path Current source path.
	 * @return bool
	 */
	private function legacy_original_tracking_matches( $previous, $options, $path ) {
		$tracked_options = is_array( $previous['_tracking_options'] ?? null ) ? $previous['_tracking_options'] : array();
		$mode            = sanitize_key( $options['compression_mode'] ?? 'balanced' );

		if (
			empty( $previous ) || 'custom' === $mode || empty( $tracked_options['optimize_originals'] ) || ! file_exists( $path )
			|| ! empty( $previous['original_options_fingerprint'] ) || ! empty( $previous['optimized_hash'] ) || ! empty( $previous['original_input_hash'] )
		) {
			return false;
		}

		$matches_options = $mode === sanitize_key( $tracked_options['compression_mode'] ?? '' )
			&& sanitize_key( $options['metadata_policy'] ?? 'remove' ) === sanitize_key( $tracked_options['metadata_policy'] ?? '' )
			&& absint( $options['max_width'] ?? 0 ) === absint( $tracked_options['max_width'] ?? 0 )
			&& absint( $options['max_height'] ?? 0 ) === absint( $tracked_options['max_height'] ?? 0 )
			&& ( empty( $options['use_external_binaries'] ) ? 0 : 1 ) === ( empty( $tracked_options['use_external_binaries'] ) ? 0 : 1 );

		return $matches_options
			&& absint( $previous['optimized_size'] ?? 0 ) === (int) filesize( $path )
			&& absint( $previous['source_mtime'] ?? 0 ) === (int) filemtime( $path );
	}

	/**
	 * Hash a file without emitting filesystem warnings.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function file_hash( $path ) {
		if ( ! $path || ! is_readable( $path ) ) {
			return '';
		}

		$hash = hash_file( 'sha256', $path );

		return is_string( $hash ) ? $hash : '';
	}

	/**
	 * Try to optimize an original file directly with external binaries.
	 *
	 * @param string $path Source path.
	 * @param array  $options Runtime options.
	 * @param bool   $needs_resize Whether the file needs resizing first.
	 * @return array|WP_Error
	 */
	private function optimize_original_file_with_external_binary( $path, $options, $needs_resize ) {
		if ( $needs_resize ) {
			return new WP_Error( 'yoohw_mo_binary_resize_unsupported', __( 'External binary pass skipped because resize is required first.', 'yoohw-media-optimizer' ) );
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$tmp       = trailingslashit( dirname( $path ) ) . '.' . wp_basename( $path ) . '.yoohw-mo-bin-' . wp_generate_password( 8, false, false ) . '.' . $extension;

		if ( $this->is_jpeg_path( $path ) ) {
			return $this->optimize_jpeg_with_external_binary( $path, $tmp, $options );
		}

		if ( 'png' === $extension ) {
			return $this->optimize_png_with_external_binary( $path, $tmp, $options );
		}

		return new WP_Error( 'yoohw_mo_binary_unsupported_type', __( 'No external optimizer is available for this file type.', 'yoohw-media-optimizer' ) );
	}

	/**
	 * Run external binaries on a temporary file created by WP_Image_Editor.
	 *
	 * @param string $path Temporary path.
	 * @param array  $options Runtime options.
	 * @return array|WP_Error
	 */
	private function optimize_temp_file_with_external_binary( $path, $options ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'yoohw_mo_binary_temp_missing', __( 'Temporary image file is missing.', 'yoohw-media-optimizer' ) );
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$tmp       = trailingslashit( dirname( $path ) ) . '.' . wp_basename( $path ) . '.bin-' . wp_generate_password( 8, false, false ) . '.' . $extension;
		$result    = $this->is_jpeg_path( $path )
			? $this->optimize_jpeg_with_external_binary( $path, $tmp, $options, false )
			: ( 'png' === $extension ? $this->optimize_png_with_external_binary( $path, $tmp, $options ) : new WP_Error( 'yoohw_mo_binary_unsupported_type', __( 'No external optimizer is available for this file type.', 'yoohw-media-optimizer' ) ) );

		if ( is_wp_error( $result ) || empty( $result['path'] ) || ! file_exists( $result['path'] ) ) {
			return $result;
		}

		if ( filesize( $result['path'] ) >= filesize( $path ) ) {
			wp_delete_file( $result['path'] );

			return new WP_Error( 'yoohw_mo_binary_no_gain', __( 'External optimizer did not improve the temporary image.', 'yoohw-media-optimizer' ) );
		}

		$this->checkpoint_if_owned();
		wp_delete_file( $path );
		$result['replaced'] = true;

		return $result;
	}

	/**
	 * Optimize JPEG with the best available external binary.
	 *
	 * @param string $source Source path.
	 * @param string $destination Destination path.
	 * @param array  $options Runtime options.
	 * @param bool   $allow_lossy Whether lossy JPEG binaries can run.
	 * @return array|WP_Error
	 */
	private function optimize_jpeg_with_external_binary( $source, $destination, $options, $allow_lossy = true ) {
		$color = YoOhw_Media_Optimizer_Color::inspect( $source );
		if ( is_wp_error( $color ) ) { return $color; }

		if ( ! $allow_lossy ) {
			$options['compression_mode'] = 'lossless';
		}

		$mode        = sanitize_key( $options['compression_mode'] ?? 'balanced' );
		$last_error  = new WP_Error( 'yoohw_mo_jpeg_binary_unavailable', __( 'No JPEG external optimizer is available.', 'yoohw-media-optimizer' ) );
		$candidates  = array();

		if ( $this->external_binary_available( 'jpegoptim' ) ) {
			$candidates[] = 'jpegoptim';
		}

		if ( ! $color['icc'] && 'lossless' !== $mode && $this->external_binary_available( 'cjpeg' ) && $this->external_binary_available( 'djpeg' ) ) {
			$candidates[] = 'cjpeg';
		}

		if ( $this->external_binary_available( 'jpegtran' ) ) {
			$candidates[] = 'jpegtran';
		}

		foreach ( array_unique( $candidates ) as $candidate ) {
			$tmp = $destination;

			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			if ( 'jpegoptim' === $candidate ) {
				$result = $this->optimize_jpeg_with_jpegoptim( $source, $tmp, $options );
			} elseif ( 'cjpeg' === $candidate ) {
				$result = $this->optimize_jpeg_with_cjpeg( $source, $tmp, $options );
			} else {
				$result = $this->optimize_jpeg_with_jpegtran( $source, $tmp, $options );
			}

			if ( ! is_wp_error( $result ) ) {
				$verified = YoOhw_Media_Optimizer_Color::verify( $source, $destination, 'image/jpeg', 'remove' === $options['metadata_policy'] );
				if ( is_wp_error( $verified ) ) { wp_delete_file( $destination ); $last_error = $verified; continue; }
				clearstatcache( true, $source );
				clearstatcache( true, $destination );

				if ( file_exists( $destination ) && filesize( $destination ) < filesize( $source ) ) {
					return $result;
				}

				wp_delete_file( $destination );
				$last_error = new WP_Error( 'yoohw_mo_jpeg_binary_no_gain', __( 'JPEG external optimizer did not improve the file.', 'yoohw-media-optimizer' ) );
				continue;
			}

			wp_delete_file( $destination );
			$last_error = $result;
		}

		return $last_error;
	}

	/**
	 * Optimize JPEG with jpegoptim.
	 *
	 * @param string $source Source path.
	 * @param string $destination Destination path.
	 * @param array  $options Runtime options.
	 * @return array|WP_Error
	 */
	private function optimize_jpeg_with_jpegoptim( $source, $destination, $options ) {
		$binary = $this->external_binary_path( 'jpegoptim' );

		if ( ! $binary ) {
			return new WP_Error( 'yoohw_mo_jpegoptim_unavailable', __( 'jpegoptim is unavailable.', 'yoohw-media-optimizer' ) );
		}

		if ( ! $this->copy_file( $source, $destination ) ) {
			return new WP_Error( 'yoohw_mo_jpegoptim_copy_failed', __( 'Could not create a temporary JPEG for jpegoptim.', 'yoohw-media-optimizer' ) );
		}

		$mode            = sanitize_key( $options['compression_mode'] ?? 'balanced' );
		$metadata_policy = sanitize_key( $options['metadata_policy'] ?? 'remove' );
		$command         = array(
			$binary,
			'--quiet',
			'--all-progressive',
		);

		$command[] = 'remove' === $metadata_policy ? '--strip-all' : '--strip-none';
		if ( 'remove' === $metadata_policy ) { $command[] = '--keep-icc'; }

		if ( 'lossless' !== $mode ) {
			$command[] = '--max=' . $this->jpeg_quality_for_mode( $mode, absint( $options['jpeg_quality'] ?? 82 ) );
		}

		$command[] = $destination;
		$run       = $this->run_external_binary( $command, 45 );

		if ( is_wp_error( $run ) ) {
			wp_delete_file( $destination );
			return $run;
		}

		if ( ! file_exists( $destination ) ) {
			return new WP_Error( 'yoohw_mo_jpegoptim_missing_output', __( 'jpegoptim did not create an optimized file.', 'yoohw-media-optimizer' ) );
		}

		return array(
			'path'   => $destination,
			'engine' => 'jpegoptim',
		);
	}

	/**
	 * Re-encode JPEG with djpeg and cjpeg.
	 *
	 * @param string $source Source path.
	 * @param string $destination Destination path.
	 * @param array  $options Runtime options.
	 * @return array|WP_Error
	 */
	private function optimize_jpeg_with_cjpeg( $source, $destination, $options ) {
		$color = YoOhw_Media_Optimizer_Color::inspect( $source );
		if ( is_wp_error( $color ) ) { return $color; }
		if ( $color['icc'] ) { return new WP_Error( 'yoohw_mo_cjpeg_color_unsupported', __( 'cjpeg cannot retain the source color profile safely.', 'yoohw-media-optimizer' ) ); }

		$cjpeg = $this->external_binary_path( 'cjpeg' );
		$djpeg = $this->external_binary_path( 'djpeg' );

		if ( ! $cjpeg || ! $djpeg ) {
			return new WP_Error( 'yoohw_mo_cjpeg_unavailable', __( 'cjpeg and djpeg are unavailable.', 'yoohw-media-optimizer' ) );
		}

		if ( 'lossless' === sanitize_key( $options['compression_mode'] ?? 'balanced' ) ) {
			return new WP_Error( 'yoohw_mo_cjpeg_lossless_unsupported', __( 'cjpeg is skipped for lossless JPEG mode.', 'yoohw-media-optimizer' ) );
		}

		if ( 'remove' !== sanitize_key( $options['metadata_policy'] ?? 'remove' ) ) {
			return new WP_Error( 'yoohw_mo_cjpeg_metadata_unsupported', __( 'cjpeg cannot preserve JPEG metadata in this optimizer pass.', 'yoohw-media-optimizer' ) );
		}

		$ppm = trailingslashit( dirname( $destination ) ) . '.' . wp_basename( $destination ) . '.ppm-' . wp_generate_password( 8, false, false ) . '.ppm';
		$run = $this->run_external_binary(
			array(
				$djpeg,
				'-outfile',
				$ppm,
				$source,
			),
			60
		);

		if ( is_wp_error( $run ) ) {
			wp_delete_file( $ppm );
			return $run;
		}

		if ( ! file_exists( $ppm ) ) {
			return new WP_Error( 'yoohw_mo_djpeg_missing_output', __( 'djpeg did not create a temporary PPM file.', 'yoohw-media-optimizer' ) );
		}

		$run = $this->run_external_binary(
			array(
				$cjpeg,
				'-quality',
				(string) $this->jpeg_quality_for_mode( sanitize_key( $options['compression_mode'] ?? 'balanced' ), absint( $options['jpeg_quality'] ?? 82 ) ),
				'-optimize',
				'-progressive',
				'-outfile',
				$destination,
				$ppm,
			),
			90
		);

		wp_delete_file( $ppm );

		if ( is_wp_error( $run ) ) {
			wp_delete_file( $destination );
			return $run;
		}

		if ( ! file_exists( $destination ) ) {
			return new WP_Error( 'yoohw_mo_cjpeg_missing_output', __( 'cjpeg did not create an optimized JPEG file.', 'yoohw-media-optimizer' ) );
		}

		return array(
			'path'   => $destination,
			'engine' => 'djpeg+cjpeg',
		);
	}

	/**
	 * Optimize JPEG with jpegtran.
	 *
	 * @param string $source Source path.
	 * @param string $destination Destination path.
	 * @param array  $options Runtime options.
	 * @return array|WP_Error
	 */
	private function optimize_jpeg_with_jpegtran( $source, $destination, $options ) {
		$binary = $this->external_binary_path( 'jpegtran' );

		if ( ! $binary ) {
			return new WP_Error( 'yoohw_mo_jpegtran_unavailable', __( 'jpegtran is unavailable.', 'yoohw-media-optimizer' ) );
		}

		$copy_mode = 'remove' === sanitize_key( $options['metadata_policy'] ?? 'remove' ) ? 'icc' : 'all';
		$command   = array(
			$binary,
			'-copy',
			$copy_mode,
			'-optimize',
			'-progressive',
			'-outfile',
			$destination,
			$source,
		);
		$run       = $this->run_external_binary( $command, 30 );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( ! file_exists( $destination ) ) {
			return new WP_Error( 'yoohw_mo_jpegtran_missing_output', __( 'jpegtran did not create an optimized file.', 'yoohw-media-optimizer' ) );
		}

		return array(
			'path'   => $destination,
			'engine' => 'jpegtran',
		);
	}

	/**
	 * Optimize PNG with pngquant and/or oxipng/optipng.
	 *
	 * @param string $source Source path.
	 * @param string $destination Destination path.
	 * @param array  $options Runtime options.
	 * @return array|WP_Error
	 */
	private function optimize_png_with_external_binary( $source, $destination, $options ) {
		$color = YoOhw_Media_Optimizer_Color::inspect( $source );
		if ( is_wp_error( $color ) ) { return $color; }

		$mode          = sanitize_key( $options['compression_mode'] ?? 'balanced' );
		$engine        = '';
		$lossy_done    = false;
		$lossless_done = false;

		if ( ! $color['icc'] && 'lossless' !== $mode && $this->external_binary_available( 'pngquant' ) ) {
			$quality = $this->pngquant_quality_for_mode( $mode, absint( $options['jpeg_quality'] ?? 82 ) );
			$run     = $this->run_external_binary(
				array(
					$this->external_binary_path( 'pngquant' ),
					'--force',
					'--quality',
					$quality,
					'--output',
					$destination,
					'--',
					$source,
				),
				45
			);

			if ( ! is_wp_error( $run ) && file_exists( $destination ) ) {
				$engine     = 'pngquant';
				$lossy_done = true;
			}
		}

		if ( ! $lossy_done ) {
			if ( ! $this->external_binary_available( 'oxipng' ) && ! $this->external_binary_available( 'optipng' ) ) {
				return new WP_Error( 'yoohw_mo_png_binary_unavailable', __( 'No PNG external optimizer is available.', 'yoohw-media-optimizer' ) );
			}

			if ( ! $this->copy_file( $source, $destination ) ) {
				return new WP_Error( 'yoohw_mo_png_copy_failed', __( 'Could not create a temporary PNG for optimization.', 'yoohw-media-optimizer' ) );
			}
		}

		if ( $this->external_binary_available( 'oxipng' ) ) {
			$command = array(
				$this->external_binary_path( 'oxipng' ),
				'-q',
				'-o',
				'2',
			);

			if ( 'remove' === sanitize_key( $options['metadata_policy'] ?? 'remove' ) ) {
				$command[] = '--strip';
				$command[] = 'tEXt,zTXt,iTXt,eXIf';
			}

			$command[] = $destination;
			$run       = $this->run_external_binary( $command, 60 );

			if ( ! is_wp_error( $run ) ) {
				$engine       = $engine ? $engine . '+oxipng' : 'oxipng';
				$lossless_done = true;
			} elseif ( ! $lossy_done && ! $this->external_binary_available( 'optipng' ) ) {
				wp_delete_file( $destination );
				return $run;
			}
		}

		if ( ! $lossless_done && $this->external_binary_available( 'optipng' ) ) {
			$run = $this->run_external_binary(
				array(
					$this->external_binary_path( 'optipng' ),
					'-quiet',
					'-o2',
					$destination,
				),
				45
			);

			if ( is_wp_error( $run ) && ! $lossy_done ) {
				wp_delete_file( $destination );
				return $run;
			}

			if ( ! is_wp_error( $run ) ) {
				$engine = $engine ? $engine . '+optipng' : 'optipng';
			}
		}

		if ( ! file_exists( $destination ) ) {
			return new WP_Error( 'yoohw_mo_png_missing_output', __( 'PNG external optimizer did not create an output file.', 'yoohw-media-optimizer' ) );
		}

		$verified = YoOhw_Media_Optimizer_Color::verify( $source, $destination, 'image/png', 'remove' === $options['metadata_policy'] );
		if ( is_wp_error( $verified ) ) { wp_delete_file( $destination ); return $verified; }

		return array(
			'path'   => $destination,
			'engine' => $engine,
		);
	}

	/**
	 * Resolve pngquant quality range by mode.
	 *
	 * @param string $mode Compression mode.
	 * @param int    $custom_quality Custom quality.
	 * @return string
	 */
	private function pngquant_quality_for_mode( $mode, $custom_quality ) {
		if ( 'aggressive' === $mode ) {
			return '45-75';
		}

		if ( 'custom' === $mode ) {
			$quality = max( 1, min( 100, absint( $custom_quality ) ) );
			$min     = max( 1, $quality - 20 );

			return $min . '-' . $quality;
		}

		return '65-85';
	}

	/**
	 * Calculate constrained dimensions while preserving aspect ratio.
	 *
	 * @param int $width Current width.
	 * @param int $height Current height.
	 * @param int $max_width Max width. Zero means unlimited.
	 * @param int $max_height Max height. Zero means unlimited.
	 * @return array
	 */
	private function constrained_dimensions( $width, $height, $max_width, $max_height ) {
		$width     = max( 1, absint( $width ) );
		$height    = max( 1, absint( $height ) );
		$max_width = absint( $max_width );
		$max_height = absint( $max_height );
		$ratio     = 1;

		if ( $max_width > 0 && $width > $max_width ) {
			$ratio = min( $ratio, $max_width / $width );
		}

		if ( $max_height > 0 && $height > $max_height ) {
			$ratio = min( $ratio, $max_height / $height );
		}

		return array(
			'width'  => max( 1, (int) round( $width * $ratio ) ),
			'height' => max( 1, (int) round( $height * $ratio ) ),
		);
	}

	/**
	 * Read image dimensions.
	 *
	 * @param string $path File path.
	 * @return array
	 */
	private function image_dimensions( $path ) {
		$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $path ) : @getimagesize( $path );

		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return array();
		}

		return array(
			'width'  => absint( $size[0] ),
			'height' => absint( $size[1] ),
		);
	}

	/**
	 * Resolve JPEG quality by compression mode.
	 *
	 * @param string $mode Compression mode.
	 * @param int    $custom_quality Custom quality.
	 * @return int
	 */
	private function jpeg_quality_for_mode( $mode, $custom_quality ) {
		if ( 'aggressive' === $mode ) {
			return 72;
		}

		if ( 'custom' === $mode ) {
			return max( 1, min( 100, absint( $custom_quality ) ) );
		}

		if ( 'lossless' === $mode ) {
			return 100;
		}

		return 82;
	}

	/**
	 * Whether a path points to a JPEG file.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_jpeg_path( $path ) {
		return (bool) preg_match( '/\.jpe?g$/i', $path );
	}

	/**
	 * Infer an image MIME type from the extension.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function mime_for_path( $path ) {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, array( 'jpg', 'jpeg' ), true ) ) {
			return 'image/jpeg';
		}

		if ( 'png' === $extension ) {
			return 'image/png';
		}

		if ( 'webp' === $extension ) {
			return 'image/webp';
		}

		if ( 'avif' === $extension ) {
			return 'image/avif';
		}

		return '';
	}

	/**
	 * Publish an immutable, verified backup outside the document root.
	 *
	 * @param string $path Source path.
	 * @return array|WP_Error
	 */
	private function backup_file( $path ) {
		$backup_path = $this->backup_path_for( $path );
		$legacy_path = $this->legacy_backup_path_for( $path );
		$uploads = wp_get_upload_dir();
		$relative = $this->relative_upload_path_for_storage( $path );
		$raw_legacy = trailingslashit( $uploads['basedir'] ) . self::BACKUP_DIR . '/' . $relative;
		if ( $relative && ! $legacy_path && ( file_exists( $raw_legacy ) || is_link( $raw_legacy ) ) ) {
			return new WP_Error( 'yoohw_mo_backup_legacy_path', __( 'The legacy backup path is unsafe. Recovery data was left unchanged.', 'yoohw-media-optimizer' ) );
		}
		$legacy = $legacy_path && ( file_exists( $legacy_path ) || is_link( $legacy_path ) );
		$origin = $legacy ? $legacy_path : $path;

		if ( ! $backup_path || ! $this->readable_snapshot( $origin ) ) {
			return new WP_Error( 'yoohw_mo_backup_path', __( 'Could not safely resolve a readable backup source and private destination.', 'yoohw-media-optimizer' ) );
		}

		$created = false;
		if ( ! file_exists( $backup_path ) && ! is_link( $backup_path ) ) {
			if ( ! wp_mkdir_p( dirname( $backup_path ) ) || $backup_path !== $this->backup_path_for( $path ) ) {
				return new WP_Error( 'yoohw_mo_backup_path', __( 'Could not safely create the private backup directory.', 'yoohw-media-optimizer' ) );
			}
			$stage = $this->stage_verified_copy( $origin, $backup_path, 0600 );
			if ( ! $stage ) {
				return new WP_Error( 'yoohw_mo_backup_failed', __( 'Could not create and verify the image backup.', 'yoohw-media-optimizer' ) );
			}
			// A hard link publishes a complete snapshot exclusively, without replacing another backup.
			$this->checkpoint_if_owned();
			$created = $backup_path === $this->backup_path_for( $path ) && function_exists( 'link' ) && @link( $stage, $backup_path );
			wp_delete_file( $stage );
			if ( ! $created && ! file_exists( $backup_path ) ) {
				return new WP_Error( 'yoohw_mo_backup_publish_failed', __( 'Could not safely publish the backup. The backup filesystem must support hard links.', 'yoohw-media-optimizer' ) );
			}
		}

		if ( $backup_path !== $this->backup_path_for( $path ) || ! $this->readable_snapshot( $backup_path ) ) {
			return new WP_Error( 'yoohw_mo_backup_invalid', __( 'The existing backup is not a safe readable snapshot; it was left unchanged.', 'yoohw-media-optimizer' ) );
		}
		if ( $legacy ) {
			if ( ! hash_equals( $this->file_hash( $legacy_path ), $this->file_hash( $backup_path ) ) ) {
				return new WP_Error( 'yoohw_mo_backup_migration_conflict', __( 'Private and legacy backups differ. Both recovery files were preserved.', 'yoohw-media-optimizer' ) );
			}
			$this->checkpoint_if_owned();
			wp_delete_file( $legacy_path );
			if ( file_exists( $legacy_path ) ) {
				return new WP_Error( 'yoohw_mo_backup_migration_failed', __( 'The verified private backup is safe, but the legacy copy could not be removed.', 'yoohw-media-optimizer' ) );
			}
		}
		return array( 'path' => $backup_path, 'relative' => $this->backup_reference_for( $backup_path ), 'created' => $created && ! $legacy );
	}

	/** Resolve a backup destination in this site's private namespace. */
	private function backup_path_for( $path ) {
		$relative = $this->relative_upload_path_for_storage( $path );
		$base = $this->backup_base_dir();
		return $relative && $base ? $this->contained_path( trailingslashit( $base ) . $relative, $base ) : '';
	}

	/** Resolve a safely contained legacy backup without migrating it. */
	private function legacy_backup_path_for( $path ) {
		$relative = $this->relative_upload_path_for_storage( $path );
		$uploads = wp_get_upload_dir();
		return $relative ? $this->contained_path( trailingslashit( $uploads['basedir'] ) . self::BACKUP_DIR . '/' . $relative, $uploads['basedir'] ) : '';
	}

	/** Return a safe source-relative path, excluding the legacy backup namespace. */
	private function relative_upload_path_for_storage( $path ) {
		$uploads = wp_get_upload_dir();
		$safe = $this->contained_path( $path, $uploads['basedir'] );
		$relative = $safe ? substr( $safe, strlen( trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) ) ) : '';
		return $relative && self::BACKUP_DIR !== $relative && 0 !== strpos( $relative, self::BACKUP_DIR . '/' ) ? $relative : '';
	}

	/** Canonicalize a directory, including a missing suffix, without creating it. */
	private function canonical_directory( $path ) {
		if ( ! is_string( $path ) || false !== strpos( $path, "\0" ) ) {
			return '';
		}
		$path = untrailingslashit( wp_normalize_path( $path ) );
		if ( ! preg_match( '#^(?:[A-Za-z]:)?/#', $path ) || preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path ) ) {
			return '';
		}
		$missing = array();
		$cursor = $path;
		while ( ! file_exists( $cursor ) && ! is_link( $cursor ) ) {
			$parent = dirname( $cursor );
			if ( $parent === $cursor ) {
				return '';
			}
			array_unshift( $missing, wp_basename( $cursor ) );
			$cursor = $parent;
		}
		$real = realpath( $cursor );
		return $real && is_dir( $cursor ) ? untrailingslashit( wp_normalize_path( $real ) ) . ( $missing ? '/' . implode( '/', $missing ) : '' ) : '';
	}

	/** Allow a trusted root alias, but reject every link or unsafe component below it. */
	private function contained_path( $path, $root ) {
		if ( ! is_string( $path ) || false !== strpos( $path, "\0" ) || ! $this->canonical_directory( $root ) ) {
			return '';
		}
		$path = wp_normalize_path( $path );
		$root = trailingslashit( wp_normalize_path( $root ) );
		if ( 0 !== strpos( $path, $root ) ) {
			return '';
		}
		$parts = explode( '/', substr( $path, strlen( $root ) ) );
		$cursor = untrailingslashit( $root );
		foreach ( $parts as $index => $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || false !== strpos( $part, ':' ) ) {
				return '';
			}
			$cursor .= '/' . $part;
			if ( is_link( $cursor ) || ( $index < count( $parts ) - 1 && file_exists( $cursor ) && ! is_dir( $cursor ) ) ) {
				return '';
			}
		}
		return $path;
	}

	/** Resolve the existing per-blog namespace outside the canonical document root. */
	private function backup_base_dir() {
		$default = trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . self::BACKUP_DIR;
		$root = apply_filters( 'yoohw_media_optimizer_backup_dir', $default );
		$canonical = $this->canonical_directory( $root );
		$webroot = $this->canonical_directory( ABSPATH );
		$uploads = wp_get_upload_dir();
		$upload_root = $this->canonical_directory( $uploads['basedir'] );
		if ( ! $canonical || ! $webroot || ! $upload_root ) {
			return '';
		}
		foreach ( array( $webroot, $upload_root ) as $boundary ) {
			if ( 0 === strpos( trailingslashit( $canonical ), trailingslashit( $boundary ) ) || 0 === strpos( trailingslashit( $boundary ), trailingslashit( $canonical ) ) ) {
				return '';
			}
		}
		return $this->contained_path( trailingslashit( wp_normalize_path( $root ) ) . 'site-' . get_current_blog_id(), $root );
	}

	/** Store only references belonging to this site's private namespace. */
	private function backup_reference_for( $path ) {
		$base = $this->backup_base_dir();
		$safe = $base ? $this->contained_path( $path, $base ) : '';
		return $safe ? 'private:' . substr( $safe, strlen( trailingslashit( $base ) ) ) : '';
	}

	/** Resolve backup references without filesystem mutation; optionally bind them to a source. */
	private function backup_reference_to_path( $reference, $source = '' ) {
		if ( ! is_string( $reference ) ) {
			return '';
		}
		$reference = wp_normalize_path( $reference );
		$private = 0 === strpos( $reference, 'private:' );
		$legacy = 0 === strpos( $reference, self::BACKUP_DIR . '/' );
		if ( ! $private && ! $legacy ) {
			return '';
		}
		$relative = substr( $reference, $private ? 8 : strlen( self::BACKUP_DIR ) + 1 );
		if ( '' === $relative || '/' === substr( $relative, 0, 1 ) ) {
			return '';
		}
		if ( $source && $relative !== $this->relative_upload_path_for_storage( $source ) ) {
			return '';
		}
		$base = $this->backup_base_dir();
		$private_path = $base ? $this->contained_path( trailingslashit( $base ) . $relative, $base ) : '';
		if ( $private ) {
			return $private_path;
		}
		$uploads = wp_get_upload_dir();
		$legacy_path = $this->contained_path( trailingslashit( $uploads['basedir'] ) . $reference, $uploads['basedir'] );
		return $legacy_path ? ( file_exists( $legacy_path ) ? $legacy_path : $private_path ) : '';
	}

	/** Check snapshot readability without treating current optimized bytes as its provenance. */
	private function readable_snapshot( $path ) {
		return $path && ! is_link( $path ) && is_file( $path ) && is_readable( $path ) && filesize( $path ) > 0 && '' !== $this->file_hash( $path );
	}

	/** Stage a complete verified snapshot beside the destination; never write over the destination. */
	private function stage_verified_copy( $source, $destination, $mode ) {
		if ( ! function_exists( 'tempnam' ) || ! function_exists( 'chmod' ) || ! $this->readable_snapshot( $source ) || ! is_dir( dirname( $destination ) ) ) {
			return '';
		}
		$hash = $this->file_hash( $source );
		$size = filesize( $source );
		$stage = @tempnam( dirname( $destination ), '.yoohw-mo-stage-' );
		if ( ! $stage ) {
			return '';
		}
		if ( realpath( dirname( $stage ) ) !== realpath( dirname( $destination ) ) || ! $this->copy_file( $source, $stage ) ) {
			wp_delete_file( $stage );
			return '';
		}
		clearstatcache( true, $stage );
		$filesystem = new WP_Filesystem_Direct( array() );
		if ( filesize( $stage ) !== $size || ! hash_equals( $hash, $this->file_hash( $stage ) ) || ! $mode || ! $filesystem->chmod( $stage, $mode ) ) {
			wp_delete_file( $stage );
			return '';
		}
		return $stage;
	}

	/** Publish an original atomically; rename success is the commit point, with no copy fallback. */
	private function replace_original_file( $source, $destination ) {
		if ( ! $this->relative_upload_path_for_storage( $destination ) || ! wp_mkdir_p( dirname( $destination ) ) || ! $this->relative_upload_path_for_storage( $destination ) ) {
			return false;
		}
		$mode = file_exists( $destination ) ? fileperms( $destination ) & 0777 : ( defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
		$stage = $this->stage_verified_copy( $source, $destination, $mode );
		if ( ! $stage ) {
			return false;
		}
		$this->checkpoint_if_owned();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem::move pre-deletes the original; atomic commit must preserve it on failure.
		$committed = $this->relative_upload_path_for_storage( $destination ) && function_exists( 'rename' ) && @rename( $stage, $destination );
		if ( ! $committed ) {
			wp_delete_file( $stage );
		}
		return (bool) $committed;
	}

	/**
	 * Collect original and generated image sizes for one attachment.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata Optional attachment metadata.
	 * @return array
	 */
	private function collect_attachment_files( $attachment_id, $metadata = null ) {
		$metadata      = is_array( $metadata ) ? $metadata : wp_get_attachment_metadata( $attachment_id );
		$attached_file = get_attached_file( $attachment_id );
		$files         = array();
		$seen          = array();

		if ( $attached_file ) {
			$this->add_file_candidate( $files, $seen, 'full', $attached_file );
		} elseif ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
			$uploads = wp_get_upload_dir();
			$this->add_file_candidate( $files, $seen, 'full', trailingslashit( $uploads['basedir'] ) . $metadata['file'] );
		}

		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) ) {
			return $files;
		}

		$base_dir = '';

		if ( $attached_file ) {
			$base_dir = trailingslashit( dirname( $attached_file ) );
		} elseif ( ! empty( $metadata['file'] ) ) {
			$uploads  = wp_get_upload_dir();
			$base_dir = trailingslashit( trailingslashit( $uploads['basedir'] ) . dirname( $metadata['file'] ) );
		}

		if ( ! $base_dir ) {
			return $files;
		}

		foreach ( $metadata['sizes'] as $size_name => $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}

			$this->add_file_candidate( $files, $seen, sanitize_key( $size_name ), $base_dir . $size['file'] );
		}

		return $files;
	}

	/**
	 * Refresh attachment metadata dimensions/filesizes from the current files.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata Optional metadata.
	 * @param bool       $write Whether to persist metadata immediately.
	 * @return array
	 */
	private function refresh_attachment_file_metadata( $attachment_id, $metadata = null, $write = true ) {
		$attachment_id = absint( $attachment_id );
		$metadata      = is_array( $metadata ) ? $metadata : wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$attached_file = get_attached_file( $attachment_id );

		if ( $attached_file && file_exists( $attached_file ) ) {
			$dimensions = $this->image_dimensions( $attached_file );

			if ( ! empty( $dimensions ) ) {
				$metadata['width']    = $dimensions['width'];
				$metadata['height']   = $dimensions['height'];
				$metadata['filesize'] = (int) filesize( $attached_file );
			}
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_dir = '';

			if ( $attached_file ) {
				$base_dir = trailingslashit( dirname( $attached_file ) );
			} elseif ( ! empty( $metadata['file'] ) ) {
				$uploads  = wp_get_upload_dir();
				$base_dir = trailingslashit( trailingslashit( $uploads['basedir'] ) . dirname( $metadata['file'] ) );
			}

			if ( $base_dir ) {
				foreach ( $metadata['sizes'] as $size_name => $size ) {
					if ( empty( $size['file'] ) ) {
						continue;
					}

					$size_path = wp_normalize_path( $base_dir . $size['file'] );

					if ( ! file_exists( $size_path ) ) {
						continue;
					}

					$dimensions = $this->image_dimensions( $size_path );

					if ( empty( $dimensions ) ) {
						continue;
					}

					$metadata['sizes'][ $size_name ]['width']    = $dimensions['width'];
					$metadata['sizes'][ $size_name ]['height']   = $dimensions['height'];
					$metadata['sizes'][ $size_name ]['filesize'] = (int) filesize( $size_path );
				}
			}
		}

		if ( $write ) {
			$this->metadata_refreshing[ $attachment_id ] = true;
			$this->checkpoint_if_owned();
			update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
			unset( $this->metadata_refreshing[ $attachment_id ] );
		}

		return $metadata;
	}

	/**
	 * Add one unique file candidate.
	 *
	 * @param array  $files File list.
	 * @param array  $seen Seen normalized paths.
	 * @param string $label File label.
	 * @param string $path File path.
	 * @return void
	 */
	private function add_file_candidate( &$files, &$seen, $label, $path ) {
		$path = wp_normalize_path( $path );

		if ( isset( $seen[ $path ] ) ) {
			return;
		}

		$seen[ $path ] = true;
		$files[]       = array(
			'label' => $label,
			'path'  => $path,
		);
	}

	/**
	 * Whether an attachment is supported.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_supported_attachment( $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );

		return in_array( $mime, array( 'image/jpeg', 'image/png' ), true );
	}

	/**
	 * Whether a source path can be converted.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_supported_source_path( $path ) {
		return (bool) preg_match( '/\.(jpe?g|png)$/i', $path );
	}

	/**
	 * Get sidecar path.
	 *
	 * @param string $path Source path.
	 * @return string
	 */
	private function sidecar_path( $path ) {
		return $this->format_sidecar_path( $path, 'webp' );
	}

	/**
	 * Get a sidecar path for a specific modern format.
	 *
	 * @param string $path Source path.
	 * @param string $format Sidecar format.
	 * @return string
	 */
	private function format_sidecar_path( $path, $format ) {
		$format = sanitize_key( $format );

		if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
			$format = 'webp';
		}

		return $path . '.' . $format;
	}

	/**
	 * Convert an absolute upload path to a relative upload path.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private function relative_upload_path( $path ) {
		if ( ! $path ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$path    = wp_normalize_path( $path );

		if ( 0 === strpos( $path, $basedir ) ) {
			return ltrim( substr( $path, strlen( $basedir ) ), '/' );
		}

		return wp_basename( $path );
	}

	/**
	 * Convert a relative upload path to an absolute path.
	 *
	 * @param string $relative Relative upload path.
	 * @return string
	 */
	private function upload_relative_to_path( $relative ) {
		$relative = ltrim( wp_normalize_path( (string) $relative ), '/' );

		if ( '' === $relative || 0 === strpos( $relative, '../' ) || false !== strpos( $relative, '/../' ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();

		return wp_normalize_path( trailingslashit( $uploads['basedir'] ) . $relative );
	}

	/**
	 * Convert an upload URL to an absolute filesystem path.
	 *
	 * @param string $url Upload URL.
	 * @return string
	 */
	private function upload_url_to_path( $url ) {
		$uploads = wp_get_upload_dir();
		$target  = $this->delivery_url_parts( $url );
		$base    = $this->delivery_url_parts( $uploads['baseurl'] );
		if ( ! $this->same_delivery_origin( $target, $base ) ) {
			return '';
		}
		$path      = $this->delivery_url_path( $target['path'] ?? '/' );
		$base_path = $this->delivery_url_path( $base['path'] ?? '/' );
		$prefix    = trailingslashit( $base_path );
		if ( '' === $path || '' === $base_path || 0 !== strpos( $path, $prefix ) ) {
			return '';
		}
		$relative = substr( $path, strlen( $prefix ) );
		$local = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . $relative );
		return $relative && $this->relative_upload_path_for_storage( $local ) ? $local : '';
	}

	/** Parse a strict HTTP(S) URL, resolving root-relative references at the home origin. */
	private function delivery_url_parts( $url ) {
		if ( ! is_string( $url ) || '' === $url || preg_match( '/[\x00-\x20\x7f\\\\]|%(?![a-f0-9]{2})/i', $url ) ) {
			return array();
		}
		if ( '/' === $url[0] ) {
			$home = wp_parse_url( home_url( '/' ) );
			if ( empty( $home['scheme'] ) || empty( $home['host'] ) || isset( $home['user'] ) || isset( $home['pass'] ) ) {
				return array();
			}
			$url = 0 === strpos( $url, '//' ) ? $home['scheme'] . ':' . $url : $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $url;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return array();
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && ( $parts['port'] < 1 || $parts['port'] > 65535 ) ) ) {
			return array();
		}
		$parts['scheme'] = strtolower( $parts['scheme'] );
		$parts['host']   = strtolower( $parts['host'] );
		$parts['port']   = $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 );
		$parts['url']    = $url;
		return $parts;
	}

	/** Compare normalized scheme, host and effective port without accepting aliases. */
	private function same_delivery_origin( $first, $second ) {
		return $first && $second && $first['scheme'] === $second['scheme'] && $first['host'] === $second['host'] && $first['port'] === $second['port'];
	}

	/** Decode each URL path segment once, refusing traversal and ambiguous escapes. */
	private function delivery_url_path( $path ) {
		if ( '' === $path || '/' !== $path[0] ) {
			return '';
		}
		$segments = explode( '/', $path );
		foreach ( $segments as &$segment ) {
			$segment = rawurldecode( $segment );
			if ( '.' === $segment || '..' === $segment || preg_match( '/[\x00-\x1f\x7f\/\\\\]|%[a-f0-9]{2}/i', $segment ) ) {
				return '';
			}
		}
		return implode( '/', $segments );
	}

	/**
	 * Get the best available modern sidecar URL for an upload URL.
	 *
	 * @param string $url Source image URL.
	 * @return string
	 */
	private function modern_sidecar_url( $url ) {
		foreach ( $this->enabled_delivery_formats() as $format ) {
			$sidecar = $this->format_sidecar_url( $url, $format );

			if ( $sidecar ) {
				return $sidecar;
			}
		}

		return '';
	}

	/**
	 * Get a sidecar URL for a specific modern format.
	 *
	 * @param string $url Source image URL.
	 * @param string $format Sidecar format.
	 * @return string
	 */
	private function format_sidecar_url( $url, $format ) {
		$format = sanitize_key( $format );

		if ( ! $url || ! in_array( $format, array( 'avif', 'webp' ), true ) || preg_match( '/\\.(?:avif|webp)(?:[?#].*)?$/i', $url ) ) {
			return '';
		}

		$path = $this->upload_url_to_path( $url );

		if ( ! $path || ! $this->is_supported_source_path( $path ) ) {
			return '';
		}

		$sidecar_path = $this->format_sidecar_path( $path, $format );

		if ( ! $this->fresh_sidecar_exists( $path, $sidecar_path ) ) {
			return '';
		}

		return $this->append_sidecar_extension_to_url( $url, $format );
	}

	/**
	 * Whether a sidecar exists and is not older than its source.
	 *
	 * @param string $source_path Source path.
	 * @param string $sidecar_path Sidecar path.
	 * @return bool
	 */
	private function fresh_sidecar_exists( $source_path, $sidecar_path ) {
		foreach ( array( $source_path, $sidecar_path ) as $path ) {
			if ( ! $this->relative_upload_path_for_storage( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				return false;
			}
		}
		$source_time  = filemtime( $source_path );
		$sidecar_time = filemtime( $sidecar_path );
		return false !== $source_time && false !== $sidecar_time && $sidecar_time >= $source_time;
	}

	/**
	 * Append a sidecar extension before any query string or fragment.
	 *
	 * @param string $url Source URL.
	 * @param string $format Sidecar format.
	 * @return string
	 */
	private function append_sidecar_extension_to_url( $url, $format ) {
		$cut_positions = array();
		$query_pos     = strpos( $url, '?' );
		$fragment_pos  = strpos( $url, '#' );

		if ( false !== $query_pos ) {
			$cut_positions[] = $query_pos;
		}

		if ( false !== $fragment_pos ) {
			$cut_positions[] = $fragment_pos;
		}

		if ( empty( $cut_positions ) ) {
			return $url . '.' . $format;
		}

		$cut      = min( $cut_positions );
		$base_url = substr( $url, 0, $cut );
		$suffix   = substr( $url, $cut );

		return $base_url . '.' . $format . $suffix;
	}

	/**
	 * Find an optimized image that can be used for delivery tests.
	 *
	 * @return array
	 */
	private function delivery_sample() {
		$query   = $this->query_supported_attachment_ids( 200, 0, false );
		$uploads = wp_get_upload_dir();
		$baseurl = trailingslashit( $uploads['baseurl'] );

		foreach ( $query['ids'] as $attachment_id ) {
			$files = $this->collect_attachment_files( $attachment_id );

			foreach ( $files as $file ) {
				if ( empty( $file['path'] ) || ! file_exists( $file['path'] ) || ! $this->is_supported_source_path( $file['path'] ) ) {
					continue;
				}

				$avif_path = $this->format_sidecar_path( $file['path'], 'avif' );
				$webp_path = $this->sidecar_path( $file['path'] );
				$has_avif  = $this->fresh_sidecar_exists( $file['path'], $avif_path );
				$has_webp  = $this->fresh_sidecar_exists( $file['path'], $webp_path );

				if ( ! $has_avif && ! $has_webp ) {
					continue;
				}

				$source = $this->relative_upload_path_for_storage( $file['path'] );
				$avif   = $has_avif ? $this->relative_upload_path_for_storage( $avif_path ) : '';
				$webp   = $has_webp ? $this->relative_upload_path_for_storage( $webp_path ) : '';

				if ( ! $source || ( ! $avif && ! $webp ) ) {
					continue;
				}

				return array(
					'attachment_id' => absint( $attachment_id ),
					'source'        => $source,
					'sidecar'       => $webp,
					'avif'          => $avif,
					'webp'          => $webp,
					'source_url'    => $baseurl . implode( '/', array_map( 'rawurlencode', explode( '/', $source ) ) ),
					'avif_url'      => $avif ? $baseurl . implode( '/', array_map( 'rawurlencode', explode( '/', $avif ) ) ) : '',
					'webp_url'      => $webp ? $baseurl . implode( '/', array_map( 'rawurlencode', explode( '/', $webp ) ) ) : '',
				);
			}
		}

		return array();
	}

	/**
	 * Check one URL with a HEAD request.
	 *
	 * @param string $url URL to test.
	 * @param array  $headers Optional request headers.
	 * @param array  $expected_formats Permitted modern response formats.
	 * @return array
	 */
	private function remote_head_report( $url, $headers = array(), $expected_formats = array( 'avif', 'webp' ) ) {
		$report = array(
			'ok'          => false,
			'status'      => 0,
			'contentType' => '',
			'isAvif'      => false,
			'isWebp'      => false,
			'kind'        => 'unverified',
			'message'     => __( 'No URL is available for this format.', 'yoohw-media-optimizer' ),
		);
		if ( ! $url ) {
			return $report;
		}
		$target = $this->delivery_url_parts( $url );
		$home   = $this->delivery_url_parts( home_url( '/' ) );
		if ( ! $this->same_delivery_origin( $target, $home ) || ! $this->upload_url_to_path( $url ) ) {
			$report['message'] = __( 'This URL is outside the safe site uploads boundary; it was not requested.', 'yoohw-media-optimizer' );
			return $report;
		}
		$response = wp_safe_remote_head(
			$target['url'],
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			$report['message'] = $response->get_error_message();
			return $report;
		}
		$report['status']      = (int) wp_remote_retrieve_response_code( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$report['contentType'] = is_array( $content_type ) ? implode( ', ', $content_type ) : (string) $content_type;
		$mime = is_array( $content_type ) ? '' : strtolower( trim( explode( ';', $report['contentType'], 2 )[0] ) );
		$report['isAvif'] = 'image/avif' === $mime;
		$report['isWebp'] = 'image/webp' === $mime;
		$format = $report['isAvif'] ? 'avif' : ( $report['isWebp'] ? 'webp' : '' );
		$report['ok'] = 200 === $report['status'] && '' !== $format && in_array( $format, $expected_formats, true );
		if ( $report['ok'] ) {
			$report['kind']    = 'modern';
			$report['message'] = '';
		} elseif ( 200 === $report['status'] && in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			$report['kind']    = 'fallback';
			$report['message'] = __( 'Original fallback; modern image delivery was not confirmed.', 'yoohw-media-optimizer' );
		} elseif ( $report['status'] >= 300 && $report['status'] < 400 ) {
			$report['message'] = __( 'The response redirects; redirects are not followed, so delivery is unverified.', 'yoohw-media-optimizer' );
		} else {
			$report['message'] = __( 'Expected HTTP 200 with the requested modern image Content-Type; delivery is unverified.', 'yoohw-media-optimizer' );
		}
		return $report;
	}

	/**
	 * Delete generated sidecars and optionally remove optimizer metadata.
	 *
	 * @param bool $delete_tracking Whether to delete optimizer meta.
	 * @return array
	 */
	private function cleanup_sidecars( $delete_tracking ) {
		return $this->with_mutation_lock( function () use ( $delete_tracking ) {
			return $this->cleanup_sidecars_owned( $delete_tracking );
		} );
	}

	private function cleanup_sidecars_owned( $delete_tracking ) {
		$query  = $this->query_supported_attachment_ids( 0, 0, false );
		$result = array(
			'deleted' => 0,
			'failed'  => 0,
		);

		foreach ( $query['ids'] as $attachment_id ) {
			$sidecars = $this->tracked_sidecar_paths( $attachment_id );

			foreach ( $this->collect_attachment_files( $attachment_id ) as $file ) {
				if ( empty( $file['path'] ) || ! $this->is_supported_source_path( $file['path'] ) ) {
					continue;
				}

				$sidecars[] = $this->sidecar_path( $file['path'] );
				$sidecars[] = $this->format_sidecar_path( $file['path'], 'avif' );
			}

			foreach ( array_unique( array_filter( $sidecars ) ) as $sidecar ) {
				if ( ! file_exists( $sidecar ) || ! $this->is_safe_sidecar_path( $sidecar ) ) {
					continue;
				}

				$this->checkpoint_if_owned();
				wp_delete_file( $sidecar );

				if ( file_exists( $sidecar ) ) {
					++$result['failed'];
				} else {
					++$result['deleted'];
				}
			}

			if ( $delete_tracking ) {
				$this->checkpoint_if_owned();
				delete_post_meta( $attachment_id, self::META_KEY );
			}
		}

		$this->invalidate_savings_cache();

		return $result;
	}

	/**
	 * Get tracked sidecar filesystem paths for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function tracked_sidecar_paths( $attachment_id ) {
		$tracked = get_post_meta( $attachment_id, self::META_KEY, true );

		if ( ! is_array( $tracked ) || empty( $tracked['files'] ) || ! is_array( $tracked['files'] ) ) {
			return array();
		}

		$paths = array();

		foreach ( $tracked['files'] as $file ) {
			foreach ( array( 'sidecar', 'webp_sidecar', 'avif_sidecar' ) as $key ) {
				if ( empty( $file[ $key ] ) || ! is_string( $file[ $key ] ) ) {
					continue;
				}

				$path = $this->upload_relative_to_path( $file[ $key ] );

				if ( ! $path ) {
					continue;
				}

				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Replace every srcset candidate with one modern format.
	 *
	 * @param string $srcset Srcset attribute.
	 * @param string $format Modern image format.
	 * @return string
	 */
	private function replace_srcset_with_format_urls( $srcset, $format ) {
		$candidates = array_map( 'trim', explode( ',', $srcset ) );
		$updated    = array();
		$format     = sanitize_key( $format );

		if ( ! in_array( $format, array( 'avif', 'webp' ), true ) ) {
			return '';
		}

		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			$parts = preg_split( '/\\s+/', $candidate, 2 );
			$url   = $parts[0] ?? '';
			$tail  = $parts[1] ?? '';
			$modern = $this->format_sidecar_url( $url, $format );

			if ( ! $modern ) {
				return '';
			}

			$updated[] = trim( $modern . ( $tail ? ' ' . $tail : '' ) );
		}

		return implode( ', ', $updated );
	}

	/**
	 * Read a sanitized query arg without treating the URL as trusted form data.
	 *
	 * @param string $key Query arg key.
	 * @return string
	 */
	private function get_query_arg( $key ) {
		$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );

		if ( null === $value || false === $value ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * Admin page URL.
	 *
	 * @return string
	 */
	private function admin_page_url() {
		return admin_url( 'upload.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Move a generated file into place through WordPress filesystem APIs.
	 *
	 * @param string $source Source file.
	 * @param string $destination Destination file.
	 * @return bool
	 */
	private function move_file( $source, $destination ) {
		$this->checkpoint_if_owned();
		if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		}

		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		$filesystem = new WP_Filesystem_Direct( array() );

		return $filesystem->move( $source, $destination, true );
	}

	/**
	 * Copy a file through WordPress filesystem APIs.
	 *
	 * @param string $source Source file.
	 * @param string $destination Destination file.
	 * @return bool
	 */
	private function copy_file( $source, $destination ) {
		if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		}

		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		$filesystem = new WP_Filesystem_Direct( array() );

		return $filesystem->copy( $source, $destination, true );
	}

	/**
	 * Delete tracked sidecars when an attachment is removed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function delete_attachment_sidecars( $attachment_id ) {
		return $this->with_mutation_lock( function () use ( $attachment_id ) {
			return $this->delete_attachment_sidecars_owned( $attachment_id );
		} );
	}

	private function delete_attachment_sidecars_owned( $attachment_id ) {
		$files = $this->collect_attachment_files( $attachment_id );

		foreach ( $files as $file ) {
			if ( empty( $file['path'] ) || ! $this->is_supported_source_path( $file['path'] ) ) {
				continue;
			}

			$this->delete_sidecars_for_path( $file['path'] );
		}

		$this->invalidate_savings_cache( $attachment_id );
	}

	/**
	 * Delete known sidecars for a source path and report any remaining files.
	 *
	 * @param string $path Source path.
	 * @return bool Whether all associated sidecars are absent.
	 */
	private function delete_sidecars_for_path( $path ) {
		$removed = true;
		foreach ( array( $this->sidecar_path( $path ), $this->format_sidecar_path( $path, 'avif' ) ) as $sidecar ) {
			if ( file_exists( $sidecar ) && $this->is_safe_sidecar_path( $sidecar ) ) {
				$this->checkpoint_if_owned();
				wp_delete_file( $sidecar );
			}
			clearstatcache( true, $sidecar );
			if ( file_exists( $sidecar ) || is_link( $sidecar ) ) {
				$removed = false;
			}
		}
		return $removed;
	}

	/**
	 * Ensure a destructive sidecar target resolves inside uploads.
	 *
	 * @param string $path Existing sidecar path.
	 * @return bool
	 */
	private function is_safe_sidecar_path( $path ) {
		if ( ! is_string( $path ) || ! preg_match( '/\.(?:webp|avif)$/i', $path ) ) {
			return false;
		}

		$uploads = wp_get_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		$target  = realpath( $path );

		if ( false === $base || false === $target ) {
			return false;
		}

		$base   = wp_normalize_path( trailingslashit( $base ) );
		$target = wp_normalize_path( $target );

		return 0 === strpos( $target, $base );
	}

	/**
	 * Register WP-CLI command when available.
	 *
	 * @return void
	 */
	private function register_cli_command() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'yoohw-media', 'YoOhw_Media_Optimizer_CLI_Command' );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * WP-CLI command for Media Optimizer.
	 */
	class YoOhw_Media_Optimizer_CLI_Command extends WP_CLI_Command {
		/**
		 * Show optimization status.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<limit>]
		 * : Number of attachments to scan. Default scans all.
		 *
		 * @param array $args Positional args.
		 * @param array $assoc_args Assoc args.
		 * @return void
		 */
		public function status( $args, $assoc_args ) {
			$limit   = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
			$summary = YoOhw_Media_Optimizer::instance()->scan_library( $limit, 0 );

			WP_CLI\Utils\format_items(
				'table',
				array(
					array(
						'attachments' => $summary['attachments'],
						'files'       => $summary['files'],
						'optimized'   => $summary['optimized'],
						'missing'     => $summary['missing'],
						'stale'       => $summary['stale'],
						'originals'   => $summary['original_optimized'],
						'backups'     => $summary['backed_up'],
						'failed'      => $summary['failed'],
					),
				),
				array( 'attachments', 'files', 'optimized', 'missing', 'stale', 'originals', 'backups', 'failed' )
			);
		}

		/**
		 * Analyze media and queue status.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<limit>]
		 * : Number of attachments to scan. Default scans all.
		 *
		 * @param array $args Positional args.
		 * @param array $assoc_args Assoc args.
		 * @return void
		 */
		public function analyze( $args, $assoc_args ) {
			$optimizer = YoOhw_Media_Optimizer::instance();

			$this->status( $args, $assoc_args );

			$queue = $optimizer->queue_status();

			WP_CLI::line( '' );
			WP_CLI\Utils\format_items(
				'table',
				array(
					array(
						'total'   => $queue['total'],
						'pending' => $queue['pending'],
						'running' => $queue['running'],
						'done'    => $queue['done'],
						'failed'  => $queue['failed'],
					),
				),
				array( 'total', 'pending', 'running', 'done', 'failed' )
			);

			WP_CLI::line( '' );
			WP_CLI\Utils\format_items(
				'table',
				array_map(
					static function( $binary ) {
						return array(
							'binary'    => $binary['name'],
							'available' => ! empty( $binary['available'] ) ? 'yes' : 'no',
							'path'      => $binary['path'],
						);
					},
					$optimizer->external_binary_report()
				),
				array( 'binary', 'available', 'path' )
			);
		}

		/**
		 * Optimize existing media.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<limit>]
		 * : Number of attachments to process. Default 50.
		 *
		 * [--offset=<offset>]
		 * : Attachment query offset. Default 0.
		 *
		 * [--force]
		 * : Rebuild existing modern sidecars.
		 *
		 * @param array $args Positional args.
		 * @param array $assoc_args Assoc args.
		 * @return void
		 */
		public function optimize( $args, $assoc_args ) {
			$this->run_optimization( 'Optimizing media', $assoc_args, array() );
		}

		/**
		 * Optimize original JPEG/PNG files only.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<limit>]
		 * : Number of attachments to process. Default 50.
		 *
		 * [--offset=<offset>]
		 * : Attachment query offset. Default 0.
		 *
		 * [--force]
		 * : Re-encode even when no resize is needed.
		 *
		 * @param array $args Positional args.
		 * @param array $assoc_args Assoc args.
		 * @return void
		 */
		public function optimize_originals( $args, $assoc_args ) {
			$this->run_optimization(
				'Optimizing original files',
				$assoc_args,
				array(
					'optimize_originals'     => 1,
					'backup_originals'       => 1,
					'generate_webp_sidecars' => 0,
					'generate_avif_sidecars' => 0,
				)
			);
		}

		/**
		 * Restore backed up originals.
		 *
		 * ## OPTIONS
		 *
		 * [<attachment-id>]
		 * : Restore one attachment. Omit to process a batch.
		 *
		 * [--limit=<limit>]
		 * : Number of attachments to check when no ID is passed. Default 100.
		 *
		 * @param array $args Positional args.
		 * @param array $assoc_args Assoc args.
		 * @return void
		 */
		public function restore( $args, $assoc_args ) {
			$optimizer = YoOhw_Media_Optimizer::instance();
			$result    = array(
				'restored' => 0,
				'failed'   => 0,
			);

			if ( ! empty( $args[0] ) ) {
				$result = $optimizer->restore_attachment( absint( $args[0] ) );
			} else {
				$limit    = isset( $assoc_args['limit'] ) ? max( 1, absint( $assoc_args['limit'] ) ) : 100;
				$query    = $optimizer->query_supported_attachment_ids( $limit, 0, false );
				$progress = \WP_CLI\Utils\make_progress_bar( 'Restoring media backups', count( $query['ids'] ) );

				foreach ( $query['ids'] as $attachment_id ) {
					$restored = $optimizer->restore_attachment( $attachment_id );
					if ( ! empty( $restored['error'] ) ) {
						WP_CLI::error( $restored['message'] );
					}

					$result['restored'] += absint( $restored['restored'] ?? 0 );
					$result['failed']   += absint( $restored['failed'] ?? 0 );
					$progress->tick();
				}

				$progress->finish();
			}

			if ( ! empty( $result['error'] ) ) {
				WP_CLI::error( $result['message'] );
			}
			WP_CLI::success(
				sprintf(
					'Restored: %1$d. Failed: %2$d.',
					$result['restored'],
					$result['failed']
				)
			);
		}

		/**
		 * Manage the persistent optimization queue.
		 *
		 * ## OPTIONS
		 *
		 * <status|build|run>
		 * : Queue action.
		 *
		 * [--limit=<limit>]
		 * : Batch size for run. Default 25.
		 *
		 * [--force]
		 * : Force rebuild sidecars while processing the queue.
		 *
		 * @param array $args Positional args.
		 * @param array $assoc_args Assoc args.
		 * @return void
		 */
		public function queue( $args, $assoc_args ) {
			$optimizer = YoOhw_Media_Optimizer::instance();
			$action    = isset( $args[0] ) ? sanitize_key( $args[0] ) : 'status';

			if ( 'status' === $action ) {
				$queue = $optimizer->queue_status();

				WP_CLI\Utils\format_items(
					'table',
					array(
						array(
							'total'   => $queue['total'],
							'pending' => $queue['pending'],
							'running' => $queue['running'],
							'done'    => $queue['done'],
							'failed'  => $queue['failed'],
						),
					),
					array( 'total', 'pending', 'running', 'done', 'failed' )
				);
				return;
			}

			if ( 'build' === $action ) {
				$result = $optimizer->build_queue();
				if ( ! empty( $result['error'] ) ) {
					WP_CLI::error( $result['message'] );
				}
				WP_CLI::success( sprintf( 'Queued: %d.', absint( $result['queued'] ?? 0 ) ) );
				return;
			}

			if ( in_array( $action, array( 'run', 'process' ), true ) ) {
				$limit  = isset( $assoc_args['limit'] ) ? max( 1, absint( $assoc_args['limit'] ) ) : YoOhw_Media_Optimizer::DEFAULT_LIMIT;
				$force  = array_key_exists( 'force', $assoc_args ) ? (bool) $assoc_args['force'] : null;
				$result = $optimizer->process_queue_batch( $limit, $force );

				if ( ! empty( $result['error'] ) ) {
					WP_CLI::error( $result['message'] );
					return;
				}

				WP_CLI::success(
					sprintf(
						'Processed: %1$d. Done: %2$d. Failed: %3$d.',
						absint( $result['processed'] ?? 0 ),
						absint( $result['done'] ?? 0 ),
						absint( $result['batchFailed'] ?? 0 )
					)
				);
				return;
			}

			WP_CLI::error( 'Unknown queue action. Use status, build, or run.' );
		}

		/**
		 * Shared optimizer runner.
		 *
		 * @param string $label Progress label.
		 * @param array  $assoc_args Assoc args.
		 * @param array  $override_options Runtime options.
		 * @return void
		 */
		private function run_optimization( $label, $assoc_args, $override_options ) {
			$optimizer = YoOhw_Media_Optimizer::instance();
			$limit     = isset( $assoc_args['limit'] ) ? max( 1, absint( $assoc_args['limit'] ) ) : 50;
			$offset    = isset( $assoc_args['offset'] ) ? absint( $assoc_args['offset'] ) : 0;
			$force     = ! empty( $assoc_args['force'] );
			$query     = $optimizer->query_supported_attachment_ids( $limit, $offset, true );
			$progress  = \WP_CLI\Utils\make_progress_bar( $label, count( $query['ids'] ) );
			$totals    = array(
				'created'            => 0,
				'updated'            => 0,
				'existing'           => 0,
				'skipped_larger'     => 0,
				'failed'             => 0,
				'avif_created'       => 0,
				'avif_updated'       => 0,
				'avif_existing'      => 0,
				'avif_skipped_larger' => 0,
				'avif_failed'        => 0,
				'original_optimized' => 0,
				'original_skipped'   => 0,
				'original_failed'    => 0,
				'backed_up'          => 0,
			);

			foreach ( $query['ids'] as $attachment_id ) {
				$result = $optimizer->optimize_attachment( $attachment_id, $force, null, $override_options );
				if ( ! empty( $result['error'] ) ) {
					WP_CLI::error( $result['message'] );
				}

				foreach ( $totals as $key => $value ) {
					$totals[ $key ] += absint( $result['summary'][ $key ] ?? 0 );
				}

				$progress->tick();
			}

			$progress->finish();

			WP_CLI::success(
				sprintf(
					'WebP created: %1$d. WebP updated: %2$d. WebP existing: %3$d. WebP skipped: %4$d. WebP failed: %5$d. AVIF created: %6$d. AVIF updated: %7$d. AVIF existing: %8$d. AVIF skipped: %9$d. AVIF failed: %10$d. Originals optimized: %11$d. Original skipped: %12$d. Original failed: %13$d. Backups: %14$d.',
					$totals['created'],
					$totals['updated'],
					$totals['existing'],
					$totals['skipped_larger'],
					$totals['failed'],
					$totals['avif_created'],
					$totals['avif_updated'],
					$totals['avif_existing'],
					$totals['avif_skipped_larger'],
					$totals['avif_failed'],
					$totals['original_optimized'],
					$totals['original_skipped'],
					$totals['original_failed'],
					$totals['backed_up']
				)
			);
		}
	}
}
