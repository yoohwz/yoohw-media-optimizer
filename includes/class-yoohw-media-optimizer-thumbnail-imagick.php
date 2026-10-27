<?php
/**
 * Preserve color in core PNG thumbnails without changing optimizer save policy.
 *
 * @package YoOhw_Media_Optimizer
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class YoOhw_Media_Optimizer_Thumbnail_Imagick extends YoOhw_Media_Optimizer_Imagick {
	/** Core excludes all ancillary PNG chunks after resizing, including ICC. */
	protected function thumbnail_image( $dst_w, $dst_h, $filter_name = 'FILTER_TRIANGLE', $strip_meta = true ) {
		if ( 'image/png' !== $this->mime_type || wp_is_stream( $this->file ) || ! is_file( $this->file ) ) {
			return parent::thumbnail_image( $dst_w, $dst_h, $filter_name, $strip_meta );
		}
		$color = YoOhw_Media_Optimizer_Color::inspect( $this->file );
		if ( is_wp_error( $color ) ) { return $color; }
		if ( ! $color['icc'] ) { return parent::thumbnail_image( $dst_w, $dst_h, $filter_name, $strip_meta ); }
		try {
			$profiles = $this->image->getImageProfiles( '*', true );
			if ( ! hash_equals( $color['icc'], $profiles['icc'] ?? ( $profiles['icm'] ?? '' ) ) || $this->image->getImageOrientation() > 1 ) {
				return new WP_Error( 'yoohw_mo_color_editor', __( 'The image editor cannot retain the source color profile safely.', 'yoohw-media-optimizer' ) );
			}
			$result = parent::thumbnail_image( $dst_w, $dst_h, $filter_name, $strip_meta );
			if ( is_wp_error( $result ) ) { return $result; }
			// ICC writing needs tEXt support on some writers. Remove private data
			// before allowing it; never enable all chunks or inject a profile.
			$prepared = $this->prepare_color( $color['icc'], true );
			if ( is_wp_error( $prepared ) ) { return $prepared; }
			$include = $this->image->getOption( 'png:include-chunk' );
			if ( ! $this->image->setOption( 'png:include-chunk', trim( $include . ',iCCP,tEXt', ',' ) ) || ! $this->image->setOption( 'png:preserve-iCCP', 'true' ) ) {
				return new WP_Error( 'yoohw_mo_color_editor', __( 'The image editor cannot retain the source color profile safely.', 'yoohw-media-optimizer' ) );
			}
			return $result;
		} catch ( Exception $e ) {
			return new WP_Error( 'yoohw_mo_color_editor', $e->getMessage() );
		}
	}
}
