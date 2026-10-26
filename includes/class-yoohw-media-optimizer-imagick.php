<?php
/** Narrow WordPress save adapter: no color conversion, no second JPEG encode.
 * @package YoOhw_Media_Optimizer
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class YoOhw_Media_Optimizer_Imagick extends WP_Image_Editor_Imagick {
	/** Check the loaded profile before stripping private metadata in memory. */
	public function prepare_color( $icc, $remove_private ) {
		try {
			$profiles = $this->image->getImageProfiles( '*', true );
			$loaded = $profiles['icc'] ?? ( $profiles['icm'] ?? '' );
			if ( ! hash_equals( $icc, $loaded ) || ( $icc && ! in_array( $this->image->getImageColorspace(), array( Imagick::COLORSPACE_RGB, Imagick::COLORSPACE_SRGB ), true ) ) ) {
				return new WP_Error( 'yoohw_mo_color_editor', __( 'The image editor cannot retain the source color profile safely.', 'yoohw-media-optimizer' ) );
			}
			if ( $remove_private ) {
				foreach ( $profiles as $name => $value ) {
					if ( ! in_array( strtolower( $name ), array( 'icc', 'icm' ), true ) ) { $this->image->removeImageProfile( $name ); }
				}
				foreach ( $this->image->getImageProperties( '*', false ) as $name ) { $this->image->deleteImageProperty( $name ); }
				$this->image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'yoohw_mo_color_editor', $e->getMessage() );
		}
	}
}
