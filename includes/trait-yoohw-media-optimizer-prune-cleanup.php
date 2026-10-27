<?php
/**
 * Explicit, bounded cleanup of proven sidecars after a completed Prune.
 *
 * @package YoOhw_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait YoOhw_Media_Optimizer_Prune_Cleanup {
	/** Uncached, unfiltered tracking; duplicates never confer destructive authority. */
	private function raw_optimizer_tracking( $attachment_id, $allow_empty = false ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $attachment_id, self::META_KEY ) );
		if ( $wpdb->last_error || count( $rows ) > 1 || ( ! $rows && ! $allow_empty ) ) {
			throw new RuntimeException( 'Optimizer ownership tracking is unavailable or ambiguous.', 409 );
		}
		$value = $rows ? maybe_unserialize( $rows[0] ) : array();
		if ( ! is_array( $value ) || ( $value && ! is_array( $value['files'] ?? null ) ) ) {
			throw new RuntimeException( 'Optimizer ownership tracking is invalid.', 409 );
		}
		return $value;
	}

	/** Freshness markers intentionally include no-change and partially failed attempts. */
	public function get_source_revision( $attachment_id = 0 ) {
		try {
			if ( $attachment_id ) {
				global $wpdb;
				$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $attachment_id, self::SOURCE_REVISION_META ) );
				if ( $wpdb->last_error || count( $rows ) > 1 ) {
					throw new RuntimeException( 'Source revision is ambiguous.' );
				}
				$value = $rows ? maybe_unserialize( $rows[0] ) : array( 'token' => '', 'changing' => false );
			} else {
				$value = $this->fresh_option( self::SOURCE_REVISION_OPTION, array( 'token' => '', 'changing' => false ) );
			}
			if ( ! is_array( $value ) || ! is_string( $value['token'] ?? null ) || ! is_bool( $value['changing'] ?? null ) ) {
				throw new RuntimeException( 'Source revision is invalid.' );
			}
			if ( $attachment_id ) {
				$site = $this->get_source_revision();
				$value['changing'] = $value['changing'] || $site['changing'];
			}
			return array( 'token' => $value['token'], 'changing' => $value['changing'] );
		} catch ( RuntimeException $error ) {
			return array( 'token' => '', 'changing' => true );
		}
	}

	private function rotate_source_revision( $attachment_id, $changing ) {
		$this->mutation_checkpoint();
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			throw new RuntimeException( 'A source-changing attempt requires an attachment ID.' );
		}
		$value = array( 'token' => wp_generate_uuid4(), 'changing' => (bool) $changing );
		// Persist the site uncertainty first; a failed pre-marker cannot publish sources.
		if ( $changing ) {
			$this->write_verified_option( self::SOURCE_REVISION_OPTION, $value );
		}
		update_post_meta( $attachment_id, self::SOURCE_REVISION_META, $value );
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $attachment_id, self::SOURCE_REVISION_META ) );
		if ( $wpdb->last_error || count( $rows ) !== 1 || maybe_unserialize( $rows[0] ) !== $value ) {
			throw new RuntimeException( 'Could not persist the attachment source revision.' );
		}
		if ( ! $changing ) {
			// The site remains changing until the final attachment marker is durable.
			// Public attachment reads also inherit that site uncertainty on a crash/failure.
			$this->write_verified_option( self::SOURCE_REVISION_OPTION, $value );
		}
	}

	/** A node replacement with identical bytes must still invalidate old approval. */
	private function cleanup_node( $path ) {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		return $stat && ( $stat['mode'] & 0170000 ) === 0100000 ? array( 'dev' => (string) $stat['dev'], 'ino' => (string) $stat['ino'] ) : array();
	}

	private function cleanup_path( $relative ) {
		if ( ! is_string( $relative ) || '' === $relative || preg_match( '#[\\\\\x00-\x1f\x7f]|^(?:/|[A-Za-z]:)|(?:^|/)\.{1,2}(?:/|$)#', $relative ) ) {
			throw new RuntimeException( 'The uploads-relative path is invalid.', 409 );
		}
		$path = $this->upload_relative_to_path( $relative );
		if ( ! $path || $this->relative_upload_path_for_storage( $path ) !== $relative ) {
			throw new RuntimeException( 'The path is outside safe uploads storage or contains a symlink.', 409 );
		}
		return $path;
	}

	/** No discovery/backfill: only an actual successful publisher can create proof. */
	private function sidecar_ownership_for_result( $source, $previous, $published ) {
		$proofs = array();
		try {
			$relative = $this->relative_upload_path_for_storage( $source );
			if ( ! $relative || $this->cleanup_path( $relative ) !== $source ) {
				return $proofs;
			}
			$source_hash = $this->file_hash( $source );
			foreach ( array( 'webp', 'avif' ) as $format ) {
				$sidecar = $source . '.' . $format;
				$this->cleanup_path( $relative . '.' . $format );
				$node = $this->cleanup_node( $sidecar );
				$hash = $this->file_hash( $sidecar );
				if ( ! $source_hash || ! $hash || ! $node ) {
					continue;
				}
				$proof = array( 'schema' => 1, 'site' => get_current_blog_id(), 'source' => $relative, 'source_sha256' => $source_hash, 'path' => $relative . '.' . $format, 'sha256' => $hash, 'node' => $node );
				if ( ( $published[ $format ] ?? null ) === $proof || ( $previous['sidecar_ownership'][ $format ] ?? null ) === $proof ) {
					$proofs[ $format ] = $proof;
				}
			}
		} catch ( RuntimeException $error ) {
			// Unverifiable paths never acquire cleanup ownership.
		}
		return $proofs;
	}


	/** Bind ownership to the staged bytes and source used by the successful publisher. */
	private function published_sidecar_proof( $source, $sidecar, $source_hash, $published_hash ) {
		try {
			$relative = $this->relative_upload_path_for_storage( $source );
			$path = $this->relative_upload_path_for_storage( $sidecar );
			if ( ! $relative || ! $path || $this->cleanup_path( $relative ) !== $source || $this->cleanup_path( $path ) !== $sidecar || ! $source_hash || ! $published_hash || ! hash_equals( $source_hash, $this->file_hash( $source ) ) || ! hash_equals( $published_hash, $this->file_hash( $sidecar ) ) ) { return array(); }
			$node = $this->cleanup_node( $sidecar );
			return $node ? array( 'schema' => 1, 'site' => get_current_blog_id(), 'source' => $relative, 'source_sha256' => $source_hash, 'path' => $path, 'sha256' => $published_hash, 'node' => $node ) : array();
		} catch ( RuntimeException $error ) { return array(); }
	}

	private function cleanup_authorize() {
		if ( ! current_user_can( self::CAPABILITY ) || ! get_current_user_id() ) {
			throw new RuntimeException( 'An authorized signed-in administrator is required.', 403 );
		}
	}


	private function with_prune_cleanup_lock( $callback ) {
		try { $this->cleanup_authorize(); }
		catch ( RuntimeException $error ) { return $this->mutation_error( $error->getMessage(), 403 ); }
		return $this->with_mutation_lock( $callback );
	}

	private function prune_cleanup_source( $token, $page = 1, $item_key = '' ) {
		$this->cleanup_authorize();
		foreach ( array( 'yotm_optimizer_prune_handoff', 'yotm_optimizer_with_cleanup_guard', 'yotm_media_reference_path_owners' ) as $api ) {
			if ( ! is_callable( $api ) ) {
				throw new RuntimeException( 'Activate a compatible Thumbnail Manager to prepare or continue this cleanup. Saved results can still be discarded.', 409 );
			}
		}
		$source = yotm_optimizer_prune_handoff( $token, $page, $item_key );
		if ( is_wp_error( $source ) ) {
			throw new RuntimeException( $source->get_error_message(), 409 );
		}
		if ( ! is_array( $source ) || 1 !== ( $source['schema'] ?? null ) || ! is_string( $token ) || '' === $token || ( $source['job_token'] ?? null ) !== $token || ! is_array( $source['items'] ?? null ) || count( $source['items'] ) > 100 || ! is_int( $source['pages'] ?? null ) || ! is_int( $source['page'] ?? null ) || ! is_int( $source['total'] ?? null ) || $source['page'] !== $page || $page < 1 || $source['pages'] < 0 || $page > max( 1, $source['pages'] ) || $source['total'] < 0 || ! is_string( $source['source_url'] ?? null ) || ! is_string( $source['scope_label'] ?? null ) ) {
			throw new RuntimeException( 'The completed Prune evidence is invalid or incompatible.', 409 );
		}
		$seen = array();
		foreach ( $source['items'] as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['key'] ?? null ) || '' === $item['key'] || isset( $seen[ $item['key'] ] ) || ! is_int( $item['attachment_id'] ?? null ) || $item['attachment_id'] < 1 || ! is_string( $item['path'] ?? null ) || ! is_string( $item['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $item['sha256'] ) ) {
				throw new RuntimeException( 'The deleted source identity is invalid.', 409 );
			}
			$seen[ $item['key'] ] = true;
		}
		return $source;
	}

	private function cleanup_proof( $item, $format ) {
		$tracking = $this->raw_optimizer_tracking( $item['attachment_id'] );
		$matches = array();
		foreach ( $tracking['files'] as $row ) {
			if ( is_array( $row ) && ( $row['source'] ?? null ) === $item['path'] ) {
				$matches[] = $row;
			}
		}
		$proof = count( $matches ) === 1 ? ( $matches[0]['sidecar_ownership'][ $format ] ?? null ) : null;
		if ( ! is_array( $proof ) || 1 !== ( $proof['schema'] ?? null ) || get_current_blog_id() !== ( $proof['site'] ?? null ) || $item['path'] !== ( $proof['source'] ?? null ) || $item['sha256'] !== ( $proof['source_sha256'] ?? null ) || $item['path'] . '.' . $format !== ( $proof['path'] ?? null ) || ! is_string( $proof['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $proof['sha256'] ) || ! is_array( $proof['node'] ?? null ) || ! is_string( $proof['node']['dev'] ?? null ) || ! is_string( $proof['node']['ino'] ?? null ) ) {
			throw new RuntimeException( 'No exact Optimizer ownership proof exists; legacy or ambiguous sidecars are preserved.', 409 );
		}
		return $proof;
	}

	/** Called inside the TM source fence and both sorted path locks. */
	private function validate_cleanup_candidate( $candidate, $token, $page ) {
		$item = $candidate['source'];
		$source = $this->prune_cleanup_source( $token, $page, $item['key'] );
		if ( count( $source['items'] ) !== 1 || $source['items'][0] !== $item ) {
			throw new RuntimeException( 'The deleted source evidence changed or expired.', 409 );
		}
		$proof = $this->cleanup_proof( $item, $candidate['format'] );
		if ( $proof !== $candidate['proof'] ) {
			throw new RuntimeException( 'Optimizer ownership changed after preview; the sidecar is preserved.', 409 );
		}
		$original = $this->cleanup_path( $item['path'] );
		$sidecar = $this->cleanup_path( $proof['path'] );
		clearstatcache( true, $original );
		if ( file_exists( $original ) || is_link( $original ) ) {
			throw new RuntimeException( 'The deleted source exists again; its sidecar is preserved.', 409 );
		}
		foreach ( array( $original, $sidecar ) as $path ) {
			$owners = yotm_media_reference_path_owners( $path );
			if ( is_wp_error( $owners ) || ! is_array( $owners ) || ! isset( $owners['protected'], $owners['generated'] ) || ! is_array( $owners['protected'] ) || ! is_array( $owners['generated'] ) || $owners['protected'] || $owners['generated'] ) {
				throw new RuntimeException( 'A WordPress source/generated reference exists or reference safety is unavailable; preserved.', 409 );
			}
		}
		clearstatcache( true, $sidecar );
		if ( ! file_exists( $sidecar ) && ! is_link( $sidecar ) ) {
			return false;
		}
		if ( $proof['node'] !== $this->cleanup_node( $sidecar ) || ! hash_equals( $proof['sha256'], $this->file_hash( $sidecar ) ) ) {
			throw new RuntimeException( 'The sidecar bytes or regular-file identity changed; preserved.', 409 );
		}
		return $sidecar;
	}

	private function cleanup_manifest_hash( $run ) {
		return hash( 'sha256', serialize( array( $run['id'], $run['owner'], $run['site'], $run['created'], $run['expires'], $run['token'], $run['page'], $run['source_pages'], $run['source_total'], $run['items'] ) ) );
	}

	private function read_prune_cleanup( $run_id = '', $hash = '', $require_live = true ) {
		$this->cleanup_authorize();
		$run = $this->fresh_option( self::PRUNE_CLEANUP_OPTION, array() );
		if ( ! is_array( $run ) || 1 !== ( $run['schema'] ?? null ) || ! is_string( $run['id'] ?? null ) || ! is_string( $run['hash'] ?? null ) || ! is_int( $run['created'] ?? null ) || ! is_int( $run['expires'] ?? null ) || ! is_int( $run['cursor'] ?? null ) || ! is_int( $run['page'] ?? null ) || ! is_int( $run['source_pages'] ?? null ) || ! is_int( $run['source_total'] ?? null ) || ! is_string( $run['token'] ?? null ) || ! is_array( $run['items'] ?? null ) || ! is_array( $run['outcomes'] ?? null ) || count( $run['items'] ) > 200 || $run['cursor'] < 0 || $run['cursor'] > count( $run['items'] ) || get_current_user_id() !== ( $run['owner'] ?? null ) || get_current_blog_id() !== ( $run['site'] ?? null ) || ( $run_id && $run_id !== $run['id'] ) || ! hash_equals( $run['hash'], $this->cleanup_manifest_hash( $run ) ) || ( $hash && ! hash_equals( $run['hash'], $hash ) ) || ( $require_live && ( time() >= $run['expires'] || $run['expires'] !== $run['created'] + 900 ) ) ) {
			throw new RuntimeException( 'This cleanup preview is missing, changed, expired, or belongs to another user/site. Discard your saved preview and prepare it again.', 409 );
		}
		return $run;
	}

	private function save_prune_cleanup( $run ) {
		$this->mutation_checkpoint();
		$this->write_verified_option( self::PRUNE_CLEANUP_OPTION, $run );
	}

	/** POST only. A preview never changes files or the original Prune report. */
	public function prepare_prune_cleanup( $token, $page = 1 ) {
		return $this->with_prune_cleanup_lock( function () use ( $token, $page ) {
			$this->cleanup_authorize();
			if ( $this->fresh_option( self::PRUNE_CLEANUP_OPTION, array() ) ) {
				throw new RuntimeException( 'A cleanup preview is already saved. Open or explicitly discard it before preparing another page.', 409 );
			}
			$source = $this->prune_cleanup_source( $token, $page );
			$run = array( 'schema' => 1, 'id' => wp_generate_uuid4(), 'owner' => get_current_user_id(), 'site' => get_current_blog_id(), 'created' => time(), 'expires' => time() + 900, 'token' => $token, 'page' => $page, 'source_pages' => $source['pages'], 'source_total' => $source['total'], 'source_url' => $source['source_url'], 'scope_label' => $source['scope_label'], 'items' => array(), 'preserved' => array(), 'outcomes' => array(), 'cursor' => 0, 'approved' => false );
			$run['expires'] = $run['created'] + 900;
			foreach ( $source['items'] as $item ) {
				foreach ( array( 'webp', 'avif' ) as $format ) {
					try {
						$proof = $this->cleanup_proof( $item, $format );
						$candidate = array( 'source' => $item, 'format' => $format, 'proof' => $proof );
						$paths = array( $this->cleanup_path( $item['path'] ), $this->cleanup_path( $proof['path'] ) );
						$valid = yotm_optimizer_with_cleanup_guard( $paths, function () use ( $candidate, $token, $page ) { return $this->validate_cleanup_candidate( $candidate, $token, $page ); } );
						if ( is_wp_error( $valid ) || ! $valid ) {
							throw new RuntimeException( is_wp_error( $valid ) ? $valid->get_error_message() : 'The sidecar is already absent; no cleanup is needed.' );
						}
						$run['items'][] = $candidate;
					} catch ( RuntimeException $error ) {
						$run['preserved'][] = array( 'path' => $item['path'] . '.' . $format, 'message' => $error->getMessage() );
					}
				}
			}
			$run['hash'] = $this->cleanup_manifest_hash( $run );
			$this->save_prune_cleanup( $run );
			return $run;
		} );
	}

	public function approve_prune_cleanup( $run_id, $hash ) {
		return $this->with_prune_cleanup_lock( function () use ( $run_id, $hash ) {
			if ( ! is_string( $run_id ) || ! $run_id || ! is_string( $hash ) || ! $hash ) { throw new RuntimeException( 'The exact cleanup preview and hash are required.', 409 ); }
			$run = $this->read_prune_cleanup( $run_id, $hash );
			$this->prune_cleanup_source( $run['token'], $run['page'] );
			$run['approved'] = true;
			$this->save_prune_cleanup( $run );
			return $run;
		} );
	}

	public function process_prune_cleanup( $run_id, $hash ) {
		return $this->with_prune_cleanup_lock( function () use ( $run_id, $hash ) {
			if ( ! is_string( $run_id ) || ! $run_id || ! is_string( $hash ) || ! $hash ) { throw new RuntimeException( 'The exact cleanup preview and hash are required.', 409 ); }
			$run = $this->read_prune_cleanup( $run_id, $hash );
			if ( true !== ( $run['approved'] ?? false ) ) { throw new RuntimeException( 'Approve this exact preview before deleting any sidecars.', 409 ); }
			$this->prune_cleanup_source( $run['token'], $run['page'] );
			for ( $processed = 0; $processed < 8 && $run['cursor'] < count( $run['items'] ); ++$processed ) {
				$index = $run['cursor'];
				$candidate = $run['items'][ $index ];
				$run['outcomes'][ $index ] = array( 'path' => $candidate['proof']['path'], 'status' => 'running', 'message' => 'The frozen file identity will be checked again before deletion.' );
				$this->save_prune_cleanup( $run );
				try {
					$paths = array( $this->cleanup_path( $candidate['source']['path'] ), $this->cleanup_path( $candidate['proof']['path'] ) );
					$outcome = yotm_optimizer_with_cleanup_guard( $paths, function ( $checkpoint ) use ( $candidate, $run ) {
						$path = $this->validate_cleanup_candidate( $candidate, $run['token'], $run['page'] );
						if ( ! $path ) { return array( 'status' => 'absent', 'message' => 'Already absent; no file was deleted on this request.' ); }
						// Re-read the durable approval after all final source/reference/proof checks.
						$this->read_prune_cleanup( $run['id'], $run['hash'] );
						$guard = $checkpoint();
						if ( true !== $guard ) { throw new RuntimeException( is_wp_error( $guard ) ? $guard->get_error_message() : 'Thumbnail Manager lock ownership was lost.', 503 ); }
						$this->mutation_checkpoint();
						// Native unlink cannot be redirected by wp_delete_file filters.
						$deleted = @unlink( $path );
						clearstatcache( true, $path );
						return array( 'status' => $deleted && ! file_exists( $path ) && ! is_link( $path ) ? 'deleted' : 'failed', 'message' => $deleted ? 'The approved sidecar was removed.' : 'The sidecar could not be removed.' );
					} );
					if ( is_wp_error( $outcome ) ) { throw new RuntimeException( $outcome->get_error_message(), 409 ); }
				} catch ( RuntimeException $error ) {
					if ( 503 === $error->getCode() ) { throw $error; }
					$outcome = array( 'status' => 'preserved', 'message' => $error->getMessage() );
				}
				$run['outcomes'][ $index ] = array_merge( array( 'path' => $candidate['proof']['path'] ), $outcome );
				++$run['cursor'];
				$this->save_prune_cleanup( $run );
				if ( in_array( $outcome['status'], array( 'deleted', 'absent' ), true ) ) { $this->invalidate_savings_cache( $candidate['source']['attachment_id'] ); }
			}
			$this->invalidate_savings_cache();
			return $run;
		} );
	}

	/** Can discard an owned saved preview even if TM is inactive or evidence expired. */
	public function discard_prune_cleanup( $run_id ) {
		return $this->with_prune_cleanup_lock( function () use ( $run_id ) {
			if ( ! is_string( $run_id ) || ! $run_id ) { throw new RuntimeException( 'The exact cleanup run ID is required.', 409 ); }
			$run = $this->fresh_option( self::PRUNE_CLEANUP_OPTION, array() );
			if ( ! is_array( $run ) || ( $run['id'] ?? null ) !== $run_id || get_current_user_id() !== ( $run['owner'] ?? null ) || get_current_blog_id() !== ( $run['site'] ?? null ) ) { throw new RuntimeException( 'Only the saved cleanup owner on this site may discard this exact run.', 403 ); }
			$this->mutation_checkpoint();
			delete_option( self::PRUNE_CLEANUP_OPTION );
			if ( $this->fresh_option( self::PRUNE_CLEANUP_OPTION, false ) ) { throw new RuntimeException( 'Could not discard the cleanup preview.' ); }
			return array( 'discarded' => true );
		} );
	}

	/** Every action has a separate native WordPress nonce and explicit POST form. */
	public function handle_prune_cleanup() {
		if ( ! current_user_can( self::CAPABILITY ) || ! get_current_user_id() ) {
			wp_die( esc_html__( 'You do not have permission to manage media optimization.', 'yoohw-media-optimizer' ), '', array( 'response' => 403 ) );
		}
		$action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$operation = str_replace( 'yoohw_mo_prune_', '', $action );
		if ( ! in_array( $operation, array( 'prepare', 'approve', 'process', 'discard' ), true ) ) { wp_die( 'Invalid cleanup operation.' ); }
		check_admin_referer( $action );
		$run_id = isset( $_POST['run_id'] ) && is_string( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$hash = isset( $_POST['manifest_hash'] ) && is_string( $_POST['manifest_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['manifest_hash'] ) ) : '';
		$token = isset( $_POST['yotm_prune'] ) && is_string( $_POST['yotm_prune'] ) ? sanitize_text_field( wp_unslash( $_POST['yotm_prune'] ) ) : '';
		$page = isset( $_POST['yotm_page'] ) ? max( 1, absint( $_POST['yotm_page'] ) ) : 1;
		if ( 'prepare' === $operation ) { $result = $this->prepare_prune_cleanup( $token, $page ); }
		elseif ( 'approve' === $operation ) { $result = $this->approve_prune_cleanup( $run_id, $hash ); }
		elseif ( 'process' === $operation ) { $result = $this->process_prune_cleanup( $run_id, $hash ); }
		else { $result = $this->discard_prune_cleanup( $run_id ); }
		$args = array();
		if ( ! empty( $result['error'] ) ) { $args['yotm_cleanup_error'] = $result['message']; }
		if ( 'discard' !== $operation || ! empty( $result['error'] ) ) {
			if ( ! empty( $result['id'] ) || $run_id ) { $args['yotm_cleanup_run'] = $result['id'] ?? $run_id; }
			else { $args['yotm_prune'] = $token; $args['yotm_page'] = $page; }
		}
		wp_safe_redirect( add_query_arg( $args, $this->admin_page_url() ) );
		exit;
	}

	private function prune_cleanup_form( $operation, $label, $fields ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0">';
		$action = 'yoohw_mo_prune_' . $operation;
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		foreach ( $fields as $name => $value ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">'; }
		echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
	}


	private function render_saved_prune_cleanup_notice() {
		try {
			$run = $this->fresh_option( self::PRUNE_CLEANUP_OPTION, array() );
			if ( is_array( $run ) && ! empty( $run['id'] ) && get_current_user_id() === ( $run['owner'] ?? null ) && get_current_blog_id() === ( $run['site'] ?? null ) ) {
				echo '<div class="notice notice-info"><p><a href="' . esc_url( add_query_arg( 'yotm_cleanup_run', $run['id'], $this->admin_page_url() ) ) . '">' . esc_html__( 'Open your saved Thumbnail Manager sidecar cleanup preview and results', 'yoohw-media-optimizer' ) . '</a></p></div>';
			}
		} catch ( RuntimeException $error ) { /* The normal dashboard remains independently usable. */ }
	}

	/** GET reads only. Results and owner discard remain available without TM. */
	private function render_prune_cleanup() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Sidecar cleanup after Thumbnail Manager Prune', 'yoohw-media-optimizer' ) . '</h1>';
		echo '<p>' . esc_html__( 'This is a separate cleanup. Only unchanged WebP/AVIF files with exact Optimizer ownership proof can be removed. Originals, backups and the Prune report are preserved.', 'yoohw-media-optimizer' ) . '</p>';
		$error = $this->get_query_arg( 'yotm_cleanup_error' );
		if ( $error ) { echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>'; }
		try {
			$saved = $this->fresh_option( self::PRUNE_CLEANUP_OPTION, array() );
			$run_id = $this->get_query_arg( 'yotm_cleanup_run' );
			if ( $saved || $run_id ) {
				$run = $this->read_prune_cleanup( $run_id, '', false );
				echo '<p><a href="' . esc_url( $run['source_url'] ) . '">' . esc_html__( 'Back to the saved Thumbnail Manager result', 'yoohw-media-optimizer' ) . '</a></p>';
				echo '<p>' . esc_html( $run['scope_label'] ) . ' — ' . esc_html( sprintf( __( 'Source page %1$d of %2$d; %3$d deleted source items in the full report. Preview candidates: %4$d. Processed: %5$d.', 'yoohw-media-optimizer' ), $run['page'], max( 1, $run['source_pages'] ), $run['source_total'], count( $run['items'] ), $run['cursor'] ) ) . '</p>';
				echo '<p>' . esc_html( sprintf( __( 'Approval deadline (UTC): %s. Legacy sidecars without exact ownership evidence are preserved.', 'yoohw-media-optimizer' ), gmdate( 'Y-m-d H:i:s', $run['expires'] ) ) ) . '</p>';
				if ( ! $run['items'] ) { echo '<p><strong>' . esc_html__( 'No eligible sidecars were found on this page. Nothing will be deleted.', 'yoohw-media-optimizer' ) . '</strong></p>'; }
				elseif ( $run['cursor'] === count( $run['items'] ) ) { echo '<p><strong>' . esc_html__( 'Cleanup complete. Saved results are shown below.', 'yoohw-media-optimizer' ) . '</strong></p>'; }

				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'File', 'yoohw-media-optimizer' ) . '</th><th>' . esc_html__( 'Result / reason', 'yoohw-media-optimizer' ) . '</th></tr></thead><tbody>';
				foreach ( $run['items'] as $index => $candidate ) {
					$outcome = $run['outcomes'][ $index ] ?? array( 'status' => 'pending', 'message' => 'Unchanged ownership proof matches the deleted source. Awaiting explicit cleanup.' );
					echo '<tr><td>' . esc_html( $candidate['proof']['path'] ) . '</td><td>' . esc_html( $outcome['status'] . ': ' . $outcome['message'] ) . '</td></tr>';
				}
				foreach ( $run['preserved'] as $row ) { echo '<tr><td>' . esc_html( $row['path'] ) . '</td><td>' . esc_html( 'Preserved: ' . $row['message'] ) . '</td></tr>'; }
				echo '</tbody></table>';
				$fields = array( 'run_id' => $run['id'], 'manifest_hash' => $run['hash'] );
				if ( time() < $run['expires'] && $run['cursor'] < count( $run['items'] ) ) {
					try {
						$this->prune_cleanup_source( $run['token'], $run['page'] );
						if ( empty( $run['approved'] ) ) { $this->prune_cleanup_form( 'approve', __( 'Approve this exact cleanup preview', 'yoohw-media-optimizer' ), $fields ); }
						else { $this->prune_cleanup_form( 'process', __( 'Delete next batch (up to 8 sidecars)', 'yoohw-media-optimizer' ), $fields ); }
					} catch ( RuntimeException $unavailable ) { echo '<p>' . esc_html( $unavailable->getMessage() ) . '</p>'; }
				} elseif ( time() >= $run['expires'] && $run['cursor'] < count( $run['items'] ) ) { echo '<p>' . esc_html__( 'Approval expired. Discard this preview and prepare it again; no further files will be deleted.', 'yoohw-media-optimizer' ) . '</p>'; }
				$this->prune_cleanup_form( 'discard', __( 'Discard saved cleanup results', 'yoohw-media-optimizer' ), $fields );
			} else {
				$token = $this->get_query_arg( 'yotm_prune' );
				$page = max( 1, absint( $this->get_query_arg( 'yotm_page' ) ) );
				$source = $this->prune_cleanup_source( $token, $page );
				echo '<p><a href="' . esc_url( $source['source_url'] ) . '">' . esc_html__( 'Back to the saved Thumbnail Manager result', 'yoohw-media-optimizer' ) . '</a></p>';
				echo '<p>' . esc_html( sprintf( __( 'Page %1$d of %2$d: %3$d deleted source items. Preparing a preview does not delete files.', 'yoohw-media-optimizer' ), $page, max( 1, $source['pages'] ), count( $source['items'] ) ) ) . '</p>';
				$this->prune_cleanup_form( 'prepare', __( 'Prepare separate sidecar cleanup preview', 'yoohw-media-optimizer' ), array( 'yotm_prune' => $token, 'yotm_page' => $page ) );
				foreach ( array( $page - 1 => __( 'Previous page', 'yoohw-media-optimizer' ), $page + 1 => __( 'Next page', 'yoohw-media-optimizer' ) ) as $target => $label ) {
					if ( $target > 0 && $target <= $source['pages'] ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'yotm_prune' => $token, 'yotm_page' => $target ), $this->admin_page_url() ) ) . '">' . esc_html( $label ) . '</a> '; }
				}
			}
		} catch ( RuntimeException $failure ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $failure->getMessage() ) . '</p></div>';
			if ( is_array( $saved ?? null ) && is_string( $saved['id'] ?? null ) && get_current_user_id() === ( $saved['owner'] ?? null ) && get_current_blog_id() === ( $saved['site'] ?? null ) ) { $this->prune_cleanup_form( 'discard', __( 'Discard invalid saved cleanup preview', 'yoohw-media-optimizer' ), array( 'run_id' => $saved['id'] ) ); }
		}
		echo '<p><a href="' . esc_url( $this->admin_page_url() ) . '">' . esc_html__( 'Media Optimizer dashboard', 'yoohw-media-optimizer' ) . '</a></p></div>';
	}
}
