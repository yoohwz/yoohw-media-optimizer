<?php
/**
 * Delivery regressions with isolated upload fixtures and a mocked HTTP boundary.
 *
 * @package YoOhw_Media_Optimizer
 */

return static function( $optimizer, $invoke, $assert ) {
	$uploads          = wp_get_upload_dir();
	$root             = trailingslashit( $uploads['basedir'] ) . 'yoohw-mo-delivery-' . wp_generate_password( 8, false, false );
	$baseurl          = 'https://delivery.example.test/media/uploads';
	$original_options = get_option( YoOhw_Media_Optimizer::OPTION_KEY, array() );
	$original_accept  = $_SERVER['HTTP_ACCEPT'] ?? null;
	$paths            = array();
	$attachments      = array();
	$sample_ids       = array();
	$requests         = array();
	$response         = array();
	$upload_filter    = static function( $value ) use ( $root, &$baseurl ) {
		$value['basedir'] = $root;
		$value['baseurl'] = $baseurl;
		$value['path']    = $root;
		$value['url']     = $baseurl;
		$value['subdir']  = '';
		$value['error']   = false;
		return $value;
	};
	$home_filter = static function() {
		return 'https://delivery.example.test/';
	};
	$query_filter = static function( $posts, $query ) use ( &$sample_ids ) {
		return 'attachment' === $query->get( 'post_type' ) && 'ids' === $query->get( 'fields' ) ? $sample_ids : $posts;
	};
	$http_filter = static function( $preempt, $args, $url ) use ( &$requests, &$response ) {
		$requests[] = array( 'url' => $url, 'args' => $args );
		return $response;
	};
	$directory = static function( $path ) use ( &$paths, $assert ) {
		$assert( mkdir( $path ), 'Could not create a delivery fixture directory.' );
		$paths[] = $path;
	};
	$file = static function( $path, $mtime = 1000000000 ) use ( &$paths, $assert ) {
		$assert( false !== file_put_contents( $path, 'delivery fixture' ), 'Could not create a delivery fixture file.' );
		$paths[] = $path;
		touch( $path, $mtime );
		clearstatcache( true, $path );
	};
	$reply = static function( $status, $mime = '' ) {
		return array(
			'headers'  => $mime ? array( 'content-type' => $mime ) : array(),
			'body'     => '',
			'response' => array( 'code' => $status, 'message' => 'Fixture response' ),
			'cookies'  => array(),
		);
	};
	$attachment = static function( $path ) use ( &$attachments, $assert ) {
		$id = wp_insert_attachment( array( 'post_title' => 'Delivery fixture', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ) );
		$assert( ! is_wp_error( $id ) && $id > 0, 'Could not create a delivery fixture attachment.' );
		$attachments[] = $id;
		update_post_meta( $id, '_wp_attached_file', $path );
		return $id;
	};

	try {
		$directory( $root );
		add_filter( 'upload_dir', $upload_filter );
		add_filter( 'home_url', $home_filter );
		add_filter( 'posts_pre_query', $query_filter, 10, 2 );
		add_filter( 'pre_http_request', $http_filter, 10, 3 );
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, wp_parse_args( array( 'generate_webp_sidecars' => 1, 'generate_avif_sidecars' => 1, 'delivery_mode' => YoOhw_Media_Optimizer::DELIVERY_HTML ), $original_options ) );

		$source = $root . '/image.png';
		$url    = $baseurl . '/image.png';
		$file( $source );
		$file( $source . '.webp', 1000000010 );
		$file( $source . '.avif', 1000000010 );
		$valid = array(
			$url,
			'https://DELIVERY.EXAMPLE.TEST:443/media/uploads/image.png',
			'/media/uploads/image.png',
			'//delivery.example.test/media/uploads/image.png',
			$url . '?cache=one#preview',
		);
		foreach ( $valid as $candidate ) {
			$assert( $source === $invoke( 'upload_url_to_path', array( $candidate ) ), 'A trusted normalized upload URL did not resolve: ' . $candidate );
		}
		$assert( $url . '.avif?cache=one#preview' === $invoke( 'format_sidecar_url', array( $url . '?cache=one#preview', 'avif' ) ), 'Sidecar selection did not preserve the URL query and fragment.' );
		$rejected = array(
			'https://outside.invalid/media/uploads/image.png',
			'http://delivery.example.test/media/uploads/image.png',
			'https://delivery.example.test:444/media/uploads/image.png',
			'https://delivery.example.test:0/media/uploads/image.png',
			'https://delivery.example.test:65536/media/uploads/image.png',
			'https://user:secret@delivery.example.test/media/uploads/image.png',
			'https://delivery.example.test@outside.invalid/media/uploads/image.png',
			'https://delivery.example.test/media/uploads-other/image.png',
			'https://delivery.example.test/media/uploads',
			'media/uploads/image.png',
			'ftp://delivery.example.test/media/uploads/image.png',
			$baseurl . '/../image.png',
			$baseurl . '/%2e%2e/image.png',
			$baseurl . '/%2E/image.png',
			$baseurl . '/nested%2fimage.png',
			$baseurl . '/nested%5Cimage.png',
			$baseurl . '/image%00.png',
			$baseurl . '/image%0a.png',
			$baseurl . '/image%7f.png',
			$baseurl . '/image%.png',
			$baseurl . '/image%2.png',
			$baseurl . '/image%GG.png',
			$baseurl . '/%252e%252e/image.png',
			$baseurl . '/nested%252fimage.png',
			$baseurl . '/image\\.png',
			$baseurl . '/image .png',
			$baseurl . "/image\t.png",
			$baseurl . "/image\n.png",
			$baseurl . "/image\0.png",
		);
		foreach ( $rejected as $candidate ) {
			$assert( '' === $invoke( 'upload_url_to_path', array( $candidate ) ), 'An untrusted or ambiguous upload URL resolved locally: ' . bin2hex( $candidate ) );
			$assert( '' === $invoke( 'format_sidecar_url', array( $candidate, 'webp' ) ), 'An untrusted URL selected a sidecar.' );
		}

		$directory( $root . '/nested space' );
		$special = 'café 100% #.png';
		$file( $root . '/nested space/' . $special );
		$file( $root . '/nested space/' . $special . '.webp', 1000000010 );
		$encoded = $baseurl . '/nested%20space/' . rawurlencode( $special );
		$assert( $root . '/nested space/' . $special === $invoke( 'upload_url_to_path', array( $encoded ) ), 'A single-encoded space/Unicode/percent/hash filename did not resolve.' );
		$assert( $encoded . '.webp' === $invoke( 'format_sidecar_url', array( $encoded, 'webp' ) ), 'The selected sidecar changed the encoded filename.' );
		$baseurl = 'https://cdn.example.test/media/uploads';
		$assert( $source === $invoke( 'upload_url_to_path', array( $baseurl . '/image.png' ) ), 'The configured external uploads origin was rejected for frontend delivery.' );
		$assert( '' === $invoke( 'upload_url_to_path', array( '/media/uploads/image.png' ) ), 'A root-relative home URL incorrectly inherited the configured CDN origin.' );
		$assert( $source === $invoke( 'upload_url_to_path', array( '//cdn.example.test/media/uploads/image.png' ) ), 'A protocol-relative configured CDN URL was rejected.' );
		$baseurl = 'http://delivery.example.test/media/uploads';
		$assert( $source === $invoke( 'upload_url_to_path', array( 'http://DELIVERY.EXAMPLE.TEST:80/media/uploads/image.png' ) ), 'An explicit default HTTP port did not normalize.' );
		$baseurl = 'https://delivery.example.test';
		$assert( $source === $invoke( 'upload_url_to_path', array( '/image.png' ) ), 'An uploads URL rooted at the origin did not resolve.' );
		$baseurl = 'https://delivery.example.test/media/uploads';

		$file( $root . '/stale.png' );
		$file( $root . '/stale.png.webp', 999999990 );
		$file( $root . '/missing-sidecar.png' );
		$file( $root . '/missing-source.png.webp', 1000000010 );
		$directory( $root . '/directory.png' );
		$file( $root . '/directory.png.webp', 1000000010 );
		$file( $root . '/directory-sidecar.png' );
		$directory( $root . '/directory-sidecar.png.webp' );
		$directory( $root . '/' . YoOhw_Media_Optimizer::BACKUP_DIR );
		$file( $root . '/' . YoOhw_Media_Optimizer::BACKUP_DIR . '/backup.png' );
		$file( $root . '/' . YoOhw_Media_Optimizer::BACKUP_DIR . '/backup.png.webp', 1000000010 );
		foreach ( array( 'stale.png', 'missing-sidecar.png', 'missing-source.png', 'directory.png', 'directory-sidecar.png', YoOhw_Media_Optimizer::BACKUP_DIR . '/backup.png' ) as $relative ) {
			$assert( '' === $invoke( 'format_sidecar_url', array( $baseurl . '/' . $relative, 'webp' ) ), 'An unavailable, stale, nonregular or backup sidecar was selected: ' . $relative );
		}
		$assert( symlink( $source, $root . '/linked.png' ), 'Could not create a source symlink fixture.' );
		$paths[] = $root . '/linked.png';
		$file( $root . '/linked.png.webp', 1000000010 );
		$file( $root . '/linked-sidecar.png' );
		$assert( symlink( $source . '.webp', $root . '/linked-sidecar.png.webp' ), 'Could not create a sidecar symlink fixture.' );
		$paths[] = $root . '/linked-sidecar.png.webp';
		$assert( symlink( dirname( $root ), $root . '/escaped' ), 'Could not create a directory symlink fixture.' );
		$paths[] = $root . '/escaped';
		foreach ( array( 'linked.png', 'linked-sidecar.png', 'escaped/' . wp_basename( $root ) . '/image.png' ) as $relative ) {
			$assert( '' === $invoke( 'format_sidecar_url', array( $baseurl . '/' . $relative, 'webp' ) ), 'A descendant symlink was selected for delivery.' );
		}

		$image = '<img class="fixture" src="' . $url . '?v=1#fallback" alt="Original" srcset="' . $url . ' 1x, ' . $url . '?v=2 2x" sizes="100vw">';
		$_SERVER['HTTP_ACCEPT'] = 'image/avif,image/webp';
		$picture = $invoke( 'build_picture_html', array( $image ) );
		$_SERVER['HTTP_ACCEPT'] = 'image/jpeg';
		$assert( $picture === $invoke( 'build_picture_html', array( $image ) ), 'Picture markup changed with request Accept and is unsafe for shared caches.' );
		$assert( false !== strpos( $picture, $image ), 'Picture generation changed the original img fallback.' );
		$assert( false !== strpos( $picture, 'type="image/avif"' ) && false !== strpos( $picture, 'type="image/webp"' ) && strpos( $picture, 'type="image/avif"' ) < strpos( $picture, 'type="image/webp"' ), 'Fresh AVIF and WebP were not both emitted in preference order.' );
		$assert( false !== strpos( $picture, '.png.webp 1x, ' . $url . '.webp?v=2 2x' ), 'Modern srcset descriptors or URL suffixes were lost.' );
		$assert( 3 === substr_count( $picture, 'sizes="100vw"' ), 'Modern picture sources lost the sizes attribute.' );
		$mixed = '<img src="' . $url . '" srcset="' . $url . ' 1x, https://outside.invalid/media/uploads/image.png 2x">';
		$assert( $mixed === $invoke( 'build_picture_html', array( $mixed ) ), 'A mixed-origin srcset was partially rewritten.' );
		touch( $source . '.avif', 999999990 );
		clearstatcache();
		$webp_picture = $invoke( 'build_picture_html', array( '<img src="' . $url . '">' ) );
		$assert( false === strpos( $webp_picture, 'image/avif' ) && false !== strpos( $webp_picture, 'image/webp' ), 'A stale AVIF prevented the fresh WebP fallback.' );
		touch( $source . '.webp', 999999990 );
		clearstatcache();
		$plain = '<img src="' . $url . '">';
		$assert( $plain === $invoke( 'build_picture_html', array( $plain ) ), 'An image without fresh sidecars did not remain unchanged.' );
		touch( $source . '.webp', 1000000010 );
		touch( $source . '.avif', 1000000010 );
		clearstatcache();

		$sample_ids = array( $attachment( 'nested space/' . $special ) );
		$sample     = $invoke( 'delivery_sample' );
		$assert( isset( $sample['source_url'] ) && $encoded === $sample['source_url'], 'Delivery sample did not encode each path segment.' );
		$assert( $encoded . '.webp' === $sample['webp_url'], 'Delivery sample sidecar URL was not encoded safely.' );
		$sample_ids = array( $attachment( $root . '/escaped/' . wp_basename( $root ) . '/image.png' ) );
		$assert( array() === $invoke( 'delivery_sample' ), 'Delivery sample accepted a symlink escape from attachment metadata.' );
		$sample_ids = array( $attachment( YoOhw_Media_Optimizer::BACKUP_DIR . '/backup.png' ) );
		$assert( array() === $invoke( 'delivery_sample' ), 'Delivery sample selected a backup namespace file.' );

		$response = $reply( 200, 'image/webp' );
		foreach ( array_merge( $rejected, array( '', 'https://delivery.example.test/outside/image.png', 'https://delivery.example.test/media/uploads-other/image.png', $baseurl . '/linked.png', $baseurl . '/linked-sidecar.png.webp', $baseurl . '/escaped/' . wp_basename( $root ) . '/image.png', $baseurl . '/' . YoOhw_Media_Optimizer::BACKUP_DIR . '/backup.png' ) ) as $candidate ) {
			$before = count( $requests );
			$report = $invoke( 'remote_head_report', array( $candidate, array(), array( 'webp' ) ) );
			$assert( empty( $report['ok'] ) && $before === count( $requests ), 'An unsafe diagnostic URL reached the HTTP boundary.' );
		}
		$baseurl = 'https://cdn.example.test/media/uploads';
		$before  = count( $requests );
		$report  = $invoke( 'remote_head_report', array( $baseurl . '/image.png.webp', array(), array( 'webp' ) ) );
		$assert( empty( $report['ok'] ) && $before === count( $requests ), 'Configured external uploads bypassed the diagnostic home-origin boundary.' );
		$baseurl = 'https://delivery.example.test/media/uploads';

		$cases = array(
			array( 200, 'image/webp', array( 'webp' ), true, 'modern' ),
			array( 200, 'IMAGE/WEBP; charset=binary', array( 'webp' ), true, 'modern' ),
			array( 200, ' IMAGE/AVIF ; charset=binary', array( 'avif' ), true, 'modern' ),
			array( 200, 'image/webp', array( 'avif', 'webp' ), true, 'modern' ),
			array( 200, 'image/avif', array( 'webp' ), false, 'unverified' ),
			array( 200, 'image/webp', array( 'avif' ), false, 'unverified' ),
			array( 200, 'text/html; charset=UTF-8', array( 'webp' ), false, 'unverified' ),
			array( 200, 'image/webp-mismatch', array( 'webp' ), false, 'unverified' ),
			array( 200, 'text/plain; note=image/webp', array( 'webp' ), false, 'unverified' ),
			array( 200, '', array( 'webp' ), false, 'unverified' ),
			array( 200, 'image/jpeg', array( 'avif', 'webp' ), false, 'fallback' ),
			array( 200, 'image/png', array( 'webp' ), false, 'fallback' ),
		);
		foreach ( array( 204, 206, 301, 302, 307, 404, 500 ) as $status ) {
			$cases[] = array( $status, 'image/webp', array( 'webp' ), false, 'unverified' );
		}
		foreach ( $cases as $case ) {
			list( $status, $mime, $expected, $ok, $kind ) = $case;
			$response = $reply( $status, $mime );
			$response['headers']['location'] = 'https://outside.invalid/must-not-follow';
			$before  = count( $requests );
			$headers = array( 'Accept' => 'image/avif;q=0,image/webp,image/*,*/*;q=0.8' );
			$report  = $invoke( 'remote_head_report', array( $url . '.webp', $headers, $expected ) );
			$assert( $ok === $report['ok'] && $kind === $report['kind'], 'Diagnostic MIME/status classification failed: ' . $status . ' ' . $mime . ' expected ' . implode( '/', $expected ) );
			$assert( $status === $report['status'] && $mime === $report['contentType'], 'Diagnostic report lost the observed status or Content-Type.' );
			$assert( $before + 1 === count( $requests ), 'A diagnostic followed a redirect or attempted an additional request.' );
			$request = end( $requests );
			$assert( $url . '.webp' === $request['url'] && 'HEAD' === $request['args']['method'], 'Diagnostic target or method changed.' );
			$assert( 0 === $request['args']['redirection'] && true === $request['args']['reject_unsafe_urls'] && 8 === $request['args']['timeout'], 'Diagnostic did not retain safe HEAD/timeout/no-redirect transport settings.' );
			$assert( $headers['Accept'] === $request['args']['headers']['Accept'], 'Diagnostic changed the negotiated Accept header.' );
			$assert( $ok || '' !== $report['message'], 'An unverified response had no explanatory message.' );
		}
		$response = $reply( 200 );
		$response['headers']['content-type'] = array( 'image/webp; charset=binary', 'text/html' );
		$report = $invoke( 'remote_head_report', array( $url . '.webp', array(), array( 'webp' ) ) );
		$assert( empty( $report['ok'] ) && 200 === $report['status'] && '' !== $report['message'], 'Conflicting duplicate Content-Type headers confirmed modern delivery.' );

		$response = new WP_Error( 'delivery_fixture_error', '<script>untrusted error</script>' );
		$report   = $invoke( 'remote_head_report', array( $url, array(), array( 'webp' ) ) );
		$assert( empty( $report['ok'] ) && 0 === $report['status'] && '' !== $report['message'], 'A transport error was not reported as unverified.' );

		// A modern rewrite is valid even when conversion is dynamic and no local WebP exists.
		$response = $reply( 200, 'image/webp' );
		$report   = $invoke( 'remote_head_report', array( $baseurl . '/missing-sidecar.png', array( 'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8' ), array( 'avif', 'webp' ) ) );
		$assert( ! empty( $report['ok'] ) && ! empty( $report['isWebp'] ) && empty( $report['isAvif'] ), 'AVIF-preferred negotiation rejected dynamically converted WebP.' );
	} finally {
		remove_filter( 'pre_http_request', $http_filter, 10 );
		remove_filter( 'posts_pre_query', $query_filter, 10 );
		foreach ( $attachments as $id ) {
			// Avoid WordPress attachment deletion hooks removing deliberately unsafe fixture paths.
			delete_post_meta( $id, '_wp_attached_file' );
			wp_delete_post( $id, true );
		}
		remove_filter( 'home_url', $home_filter );
		remove_filter( 'upload_dir', $upload_filter );
		update_option( YoOhw_Media_Optimizer::OPTION_KEY, $original_options );
		if ( null === $original_accept ) {
			unset( $_SERVER['HTTP_ACCEPT'] );
		} else {
			$_SERVER['HTTP_ACCEPT'] = $original_accept;
		}
		// Attachment-deletion hooks may leave the plugin's inert mutex in this isolated root.
		if ( is_file( $root . '/.yoohw-media-optimizer.lock' ) && ! is_link( $root . '/.yoohw-media-optimizer.lock' ) ) {
			unlink( $root . '/.yoohw-media-optimizer.lock' );
		}
		foreach ( array_reverse( $paths ) as $path ) {
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				rmdir( $path );
			}
		}
	}
};
