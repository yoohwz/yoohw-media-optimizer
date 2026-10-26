<?php
/** Bounded inspection of the RGB color declarations supported by the optimizer.
 * @package YoOhw_Media_Optimizer
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class YoOhw_Media_Optimizer_Color {
	const MAX_ICC_BYTES = 1048576;
	const MAX_HEADERS = 4096;

	private static function error() {
		return new WP_Error( 'yoohw_mo_color_unsupported', __( 'The image color profile is unsupported or invalid. The source was left unchanged.', 'yoohw-media-optimizer' ) );
	}
	private static function uint( $bytes ) { return unpack( 'N', $bytes )[1]; }

	/** Inspect JPEG/PNG without decoding pixels or loading the complete image. */
	public static function inspect( $path ) {
		$stream = @fopen( $path, 'rb' );
		if ( ! $stream ) { return self::error(); }
		try {
			$signature = fread( $stream, 8 );
			rewind( $stream );
			if ( "\x89PNG\r\n\x1a\n" === $signature ) {
				return self::png( $stream );
			}
			if ( "\xff\xd8" === substr( $signature, 0, 2 ) ) {
				return self::jpeg( $stream );
			}
			return self::error();
		} finally { fclose( $stream ); }
	}

	private static function jpeg( $stream ) {
		fseek( $stream, 2 );
		$parts = array(); $total = 0; $bytes = 0; $channels = 0; $private = false;
		for ( $i = 0; $i < self::MAX_HEADERS; ++$i ) {
			if ( "\xff" !== fread( $stream, 1 ) ) { return self::error(); }
			do { $marker = fread( $stream, 1 ); } while ( "\xff" === $marker && ++$bytes < self::MAX_ICC_BYTES * 8 );
			if ( '' === $marker ) { return self::error(); }
			$marker = ord( $marker );
			if ( 0xda === $marker ) { break; }
			if ( in_array( $marker, array( 0x00, 0xd8, 0xd9 ), true ) || ( $marker >= 0xd0 && $marker <= 0xd7 ) ) { return self::error(); }
			$length = fread( $stream, 2 );
			if ( 2 !== strlen( $length ) ) { return self::error(); }
			$length = unpack( 'n', $length )[1] - 2;
			$bytes += $length + 4;
			if ( $length < 0 || $bytes > self::MAX_ICC_BYTES * 8 ) { return self::error(); }
			$data = $length ? fread( $stream, $length ) : '';
			if ( strlen( $data ) !== $length ) { return self::error(); }
			if ( 0xe2 === $marker && 0 === strpos( $data, 'ICC_PROFILE' ) ) {
				if ( $length < 14 || "ICC_PROFILE\0" !== substr( $data, 0, 12 ) ) { return self::error(); }
				$sequence = ord( $data[12] ); $count = ord( $data[13] );
				if ( ! $sequence || ! $count || $sequence > $count || isset( $parts[$sequence] ) || ( $total && $total !== $count ) ) { return self::error(); }
				$total = $count; $parts[$sequence] = substr( $data, 14 );
			}
			if ( in_array( $marker, array( 0xc0, 0xc1, 0xc2 ), true ) ) {
				if ( $channels || $length < 6 || 8 !== ord( $data[0] ) ) { return self::error(); }
				$channels = ord( $data[5] );
				if ( ! in_array( $channels, array( 1, 3 ), true ) || $length !== 6 + $channels * 3 ) { return self::error(); }
			}
			if ( 0xee === $marker && 0 === strpos( $data, 'Adobe' ) && ( $length < 12 || ord( $data[11] ) > 1 ) ) { return self::error(); }
			if ( 0xfe === $marker || ( $marker >= 0xe1 && $marker <= 0xed && ! ( 0xe2 === $marker && 0 === strpos( $data, "ICC_PROFILE\0" ) ) ) || 0xef === $marker ) { $private = true; }
		}
		if ( 0xda !== $marker || ! $channels || count( $parts ) !== $total ) { return self::error(); }
		ksort( $parts ); $icc = implode( '', $parts );
		if ( $total && ( 3 !== $channels || ! self::profile( $icc ) ) ) { return self::error(); }
		return array( 'icc' => $icc, 'private' => $private );
	}

	private static function png( $stream ) {
		fseek( $stream, 8 ); $icc = ''; $seen = array(); $private = false; $color = -1; $idat = false;
		$size = fstat( $stream )['size'];
		for ( $i = 0; $i < self::MAX_HEADERS; ++$i ) {
			$head = fread( $stream, 8 );
			if ( 8 !== strlen( $head ) ) { return self::error(); }
			$length = self::uint( substr( $head, 0, 4 ) ); $type = substr( $head, 4 );
			if ( $length > $size - ftell( $stream ) - 4 ) { return self::error(); }
			$relevant = in_array( $type, array( 'IHDR', 'iCCP', 'gAMA', 'cHRM', 'sRGB', 'cICP', 'mDCV', 'cLLI' ), true );
			if ( $relevant ) {
				if ( isset( $seen[$type] ) || $length > self::MAX_ICC_BYTES || $idat ) { return self::error(); }
				$seen[$type] = true;
				$data = $length ? fread( $stream, $length ) : '';
				$crc = fread( $stream, 4 );
				if ( strlen( $data ) !== $length || $crc !== hash( 'crc32b', $type . $data, true ) ) { return self::error(); }
				if ( 'IHDR' === $type ) {
					if ( 0 !== $i || 13 !== $length ) { return self::error(); }
					$color = ord( $data[9] );
				}
				if ( 'sRGB' === $type && ( 1 !== $length || ord( $data[0] ) > 3 ) ) { return self::error(); }
				// Only the exact ordinary sRGB declarations have a supported untagged interpretation.
				if ( 'gAMA' === $type ) { $seen['standard_gamma'] = 4 === $length && pack( 'N', 45455 ) === $data; }
				if ( 'cHRM' === $type ) { $seen['standard_chroma'] = pack( 'N8', 31270, 32900, 64000, 33000, 30000, 60000, 15000, 6000 ) === $data; }
				if ( 'iCCP' === $type ) {
					$end = strpos( $data, "\0" );
					if ( false === $end || $end < 1 || $end > 79 || strlen( $data ) < $end + 3 || "\0" !== $data[$end+1] || ! function_exists( 'gzuncompress' ) ) { return self::error(); }
					$icc = @gzuncompress( substr( $data, $end + 2 ), self::MAX_ICC_BYTES );
					if ( ! is_string( $icc ) || ! self::profile( $icc ) ) { return self::error(); }
				}
			} else { fseek( $stream, $length + 4, SEEK_CUR ); }
			if ( in_array( $type, array( 'tEXt', 'zTXt', 'iTXt', 'eXIf' ), true ) ) { $private = true; }
			if ( 'IDAT' === $type ) { $idat = true; }
			if ( 'IEND' === $type ) {
				if ( ! $idat || ! isset( $seen['IHDR'] ) || ( $icc && ( ! in_array( $color, array( 2, 3, 6 ), true ) || isset( $seen['sRGB'] ) ) ) ) { return self::error(); }
				if ( isset( $seen['cICP'] ) || isset( $seen['mDCV'] ) || isset( $seen['cLLI'] ) || ( ! $icc && ( ( isset( $seen['gAMA'] ) && ! $seen['standard_gamma'] ) || ( isset( $seen['cHRM'] ) && ! $seen['standard_chroma'] ) ) ) ) { return self::error(); }
				return array( 'icc' => $icc, 'private' => $private );
			}
		}
		return self::error();
	}

	/** A deliberately narrow structural matrix RGB v2/v4 check, not full ICC conformance. */
	private static function profile( $icc ) {
		$size = strlen( $icc );
		if ( $size < 132 || $size > self::MAX_ICC_BYTES || self::uint( substr( $icc, 0, 4 ) ) !== $size || 'acsp' !== substr( $icc, 36, 4 ) || 'RGB ' !== substr( $icc, 16, 4 ) || 'XYZ ' !== substr( $icc, 20, 4 ) || ! in_array( ord( $icc[8] ), array( 2, 4 ), true ) || ! in_array( substr( $icc, 12, 4 ), array( 'mntr', 'scnr' ), true ) ) { return false; }
		$count = self::uint( substr( $icc, 128, 4 ) ); $tags = array(); $ranges = array();
		if ( $count < 7 || $count > 256 || 132 + $count * 12 > $size ) { return false; }
		for ( $i = 0; $i < $count; ++$i ) {
			$entry = substr( $icc, 132 + 12 * $i, 12 ); $name = substr( $entry, 0, 4 );
			$offset = self::uint( substr( $entry, 4, 4 ) ); $length = self::uint( substr( $entry, 8, 4 ) );
			if ( isset( $tags[$name] ) || $offset % 4 || $offset < 132 + $count * 12 || $length < 8 || $length > $size - $offset ) { return false; }
			foreach ( $ranges as $range ) {
				if ( $offset < $range[0] + $range[1] && $range[0] < $offset + $length && array( $offset, $length ) !== $range ) { return false; }
			}
			$ranges[] = array( $offset, $length );
			$tags[$name] = substr( $icc, $offset, $length );
		}
		foreach ( array( 'rXYZ', 'gXYZ', 'bXYZ', 'wtpt' ) as $tag ) {
			if ( ! isset( $tags[$tag] ) || strlen( $tags[$tag] ) !== 20 || 'XYZ ' !== substr( $tags[$tag], 0, 4 ) ) { return false; }
		}
		foreach ( array( 'rTRC', 'gTRC', 'bTRC' ) as $tag ) {
			if ( ! isset( $tags[$tag] ) || strlen( $tags[$tag] ) < 12 ) { return false; }
			$type = substr( $tags[$tag], 0, 4 ); $length = strlen( $tags[$tag] );
			if ( 'curv' === $type ) {
				if ( self::uint( substr( $tags[$tag], 8, 4 ) ) > ( $length - 12 ) / 2 ) { return false; }
			} elseif ( 'para' === $type ) {
				$function = unpack( 'n', substr( $tags[$tag], 8, 2 ) )[1]; $parameters = array( 1, 3, 4, 5, 7 );
				if ( ! isset( $parameters[$function] ) || $length < 12 + $parameters[$function] * 4 ) { return false; }
			} else { return false; }
		}
		foreach ( array( 'A2B0', 'A2B1', 'A2B2', 'B2A0', 'B2A1', 'B2A2', 'D2B0', 'D2B1', 'D2B2', 'D2B3', 'B2D0', 'B2D1', 'B2D2', 'B2D3' ) as $tag ) { if ( isset( $tags[$tag] ) ) { return false; } }
		return true;
	}

	private static function webp( $path ) {
		$stream = @fopen( $path, 'rb' );
		if ( ! $stream ) { return self::error(); }
		try {
			$head = fread( $stream, 12 ); $size = fstat( $stream )['size'];
			if ( 12 !== strlen( $head ) || 'RIFF' !== substr( $head, 0, 4 ) || 'WEBP' !== substr( $head, 8, 4 ) || unpack( 'V', substr( $head, 4, 4 ) )[1] + 8 !== $size ) { return self::error(); }
			$icc = ''; $private = false; $seen = false;
			for ( $i = 0; $i < self::MAX_HEADERS && ftell( $stream ) < $size; ++$i ) {
				$head = fread( $stream, 8 );
				if ( strlen( $head ) !== 8 ) { return self::error(); }
				$type = substr( $head, 0, 4 ); $length = unpack( 'V', substr( $head, 4 ) )[1];
				if ( $length + ( $length % 2 ) > $size - ftell( $stream ) ) { return self::error(); }
				if ( 'ICCP' === $type ) {
					if ( $seen || $length > self::MAX_ICC_BYTES ) { return self::error(); }
					$seen = true; $icc = $length ? fread( $stream, $length ) : '';
					if ( ! self::profile( $icc ) ) { return self::error(); }
				} else { fseek( $stream, $length, SEEK_CUR ); }
				if ( $length % 2 ) { fseek( $stream, 1, SEEK_CUR ); }
				if ( in_array( $type, array( 'EXIF', 'XMP ' ), true ) ) { $private = true; }
			}
			return ftell( $stream ) === $size ? array( 'icc' => $icc, 'private' => $private ) : self::error();
		} finally { fclose( $stream ); }
	}

	/** Validate the candidate before any publication; never edits either file. */
	public static function verify( $source, $output, $mime, $remove_private = false ) {
		$before = self::inspect( $source );
		if ( is_wp_error( $before ) ) { return $before; }
		if ( 'image/webp' === $mime ) { $after = self::webp( $output ); }
		elseif ( 'image/avif' === $mime ) {
			if ( ! class_exists( 'Imagick' ) || ! Imagick::queryFormats( 'AVIF' ) ) {
				return $before['icc'] ? self::error() : true;
			}
			try {
				$image = new Imagick(); $image->readImage( $output );
				$profiles = $image->getImageProfiles( '*', true );
				$after = array( 'icc' => $profiles['icc'] ?? ( $profiles['icm'] ?? '' ), 'private' => ! empty( array_intersect( array_keys( $profiles ), array( 'exif', 'xmp', 'iptc' ) ) ) );
				$image->clear();
			} catch ( Exception $e ) { return self::error(); }
		} else { $after = self::inspect( $output ); }
		if ( is_wp_error( $after ) ) { return $after; }
		return hash_equals( $before['icc'], $after['icc'] ) && ( ! $remove_private || ! $after['private'] ) ? true : self::error();
	}
}
