<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UltraLove_CF_Images_Sync {
	private array $credentials;

	public function __construct( ?array $credentials = null ) {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! wp_doing_cron() ) {
			throw new RuntimeException( 'Images synchronization is restricted to CLI and scheduled maintenance.' );
		}
		if ( $credentials === null ) {
			$path = '/etc/ultralove/cloudflare-images.json';
			if ( ! is_readable( $path ) ) {
				throw new RuntimeException( 'Protected Cloudflare Images credentials are not configured.' );
			}
			try {
				$credentials = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
			} catch ( JsonException $error ) {
				throw new RuntimeException( 'Protected Cloudflare Images configuration is invalid JSON.', 0, $error );
			}
		}
		if ( ! is_array( $credentials )
			|| ! is_string( $credentials['account_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/Di', $credentials['account_id'] )
			|| ! is_string( $credentials['account_hash'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{10,100}$/D', $credentials['account_hash'] )
			|| ! is_string( $credentials['token'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{20,200}$/D', $credentials['token'] ) ) {
			throw new RuntimeException( 'Cloudflare Images credentials are incomplete or malformed.' );
		}
		if ( get_option( 'ul_cf_images_account_hash', '' ) !== $credentials['account_hash'] ) {
			throw new RuntimeException( 'Cloudflare Images account hash does not match the configured delivery account.' );
		}
		$this->credentials = $credentials;
	}

	public static function ids( int $after, int $limit, bool $pending = false ): array {
		global $wpdb;
		$where = '';
		if ( $pending ) {
			$where = " AND (NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_ul_cf_images')
				OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_ul_cf_images_dirty' AND m.meta_value<>''))";
		}
		$query = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type='attachment'
				AND p.post_mime_type IN ('image/jpeg','image/png','image/webp') AND p.ID>%d
				$where ORDER BY p.ID ASC LIMIT %d", $after, $limit
		);
		$ids = $wpdb->get_col( $query );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read the attachment synchronization queue.' );
		}
		return array_map( 'intval', $ids );
	}

	public function source( int $id ): array {
		if ( $id < 1 || ! in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			throw new RuntimeException( 'Attachment is not a supported still image.' );
		}
		$file = get_post_meta( $id, '_wp_attached_file', true );
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || ! is_string( $file ) || $file === '' ) {
			throw new RuntimeException( 'Attachment source metadata is unavailable.' );
		}
		$base = realpath( $uploads['basedir'] );
		$path = get_attached_file( $id, true );
		$path = is_string( $path ) ? realpath( $path ) : false;
		if ( ! $base || ! $path || ! str_starts_with( $path, $base . DIRECTORY_SEPARATOR ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( 'Attachment original is missing or outside the uploads directory.' );
		}
		clearstatcache( true, $path );
		$size = filesize( $path );
		$mtime = filemtime( $path );
		if ( ! is_int( $size ) || $size < 1 || $size > 10000000 || ! is_int( $mtime ) ) {
			throw new RuntimeException( 'Attachment original exceeds the hosted 10 MB limit or has invalid file metadata.' );
		}
		$metadata = wp_get_attachment_metadata( $id );
		$width = (int) ( $metadata['width'] ?? 0 );
		$height = (int) ( $metadata['height'] ?? 0 );
		if ( $width < 1 || $height < 1 || $width > 12000 || $height > 12000 || $width * $height > 100000000 ) {
			throw new RuntimeException( 'Attachment dimensions exceed the hosted image limits or are unavailable.' );
		}
		$hash = hash_file( 'sha256', $path );
		if ( ! is_string( $hash ) ) {
			throw new RuntimeException( 'Attachment original could not be hashed.' );
		}
		return array( 'id' => $id, 'path' => $path, 'mime' => get_post_mime_type( $id ),
			'image_id' => 'ultralove/' . $hash, 'source_sha256' => $hash,
			'source_file' => $file, 'source_mtime' => $mtime, 'source_size' => $size,
			'dirty_generation' => get_post_meta( $id, '_ul_cf_images_dirty', true ) );
	}

	public function mapped( array $source ): bool {
		$mapping = get_post_meta( $source['id'], '_ul_cf_images', true );
		return is_array( $mapping ) && ( $mapping['account_hash'] ?? null ) === $this->credentials['account_hash']
			&& ( $mapping['image_id'] ?? null ) === $source['image_id']
			&& ( $mapping['source_file'] ?? null ) === $source['source_file']
			&& ( $mapping['source_mtime'] ?? null ) === $source['source_mtime']
			&& ( $mapping['source_size'] ?? null ) === $source['source_size'];
	}

	private function response( string $body, int $status ): array {
		try {
			$data = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new RuntimeException( 'Images API returned invalid JSON (HTTP ' . $status . ').', 0, $error );
		}
		if ( ! is_array( $data ) || ! is_bool( $data['success'] ?? null ) ) {
			throw new RuntimeException( 'Images API returned an invalid response envelope.' );
		}
		return array( 'status' => $status, 'data' => $data );
	}

	private function request( string $url, string $token ): array {
		$handle = curl_init( $url );
		curl_setopt_array( $handle, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => array( 'Authorization: Bearer ' . $token ) ) );
		$body = curl_exec( $handle );
		if ( $body === false ) {
			throw new RuntimeException( 'Images API transport failed: curl ' . curl_errno( $handle ) . '.' );
		}
		return $this->response( $body, (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) );
	}

	public function confirm( array $source, array $response ): void {
		$data = $response['data'];
		$result = $data['result'] ?? null;
		$prefix = 'https://imagedelivery.net/' . $this->credentials['account_hash'] . '/' . $source['image_id'] . '/';
		if ( $response['status'] !== 200 || $data['success'] !== true || ! is_array( $result )
			|| ( $result['id'] ?? null ) !== $source['image_id'] || ( $result['requireSignedURLs'] ?? null ) !== false
			|| ! is_array( $result['variants'] ?? null ) || ! $result['variants']
			|| ( $result['meta']['source_sha256'] ?? null ) !== $source['source_sha256'] ) {
			throw new RuntimeException( 'Images API did not confirm the exact public image and source identity (HTTP ' . $response['status'] . ').' );
		}
		foreach ( $result['variants'] as $url ) {
			if ( ! is_string( $url ) || ! str_starts_with( $url, $prefix ) ) {
				throw new RuntimeException( 'Images API returned a delivery URL for another account or image.' );
			}
		}
		clearstatcache( true, $source['path'] );
		if ( ! is_file( $source['path'] ) || filemtime( $source['path'] ) !== $source['source_mtime']
			|| filesize( $source['path'] ) !== $source['source_size']
			|| hash_file( 'sha256', $source['path'] ) !== $source['source_sha256']
			|| get_post_meta( $source['id'], '_wp_attached_file', true ) !== $source['source_file'] ) {
			throw new RuntimeException( 'Attachment source changed during upload; the image mapping was not accepted.' );
		}
		$mapping = array_intersect_key( $source, array_flip( array( 'image_id', 'source_sha256', 'source_file', 'source_mtime', 'source_size' ) ) );
		$mapping['account_hash'] = $this->credentials['account_hash'];
		update_post_meta( $source['id'], '_ul_cf_images', $mapping );
		if ( get_post_meta( $source['id'], '_ul_cf_images', true ) !== $mapping ) {
			throw new RuntimeException( 'Hosted image mapping could not be saved.' );
		}
		if ( $source['dirty_generation'] !== '' ) {
			delete_post_meta( $source['id'], '_ul_cf_images_dirty', $source['dirty_generation'] );
		}
	}

	public function sync( array $ids, bool $dry_run = false ): array {
		if ( count( $ids ) > 500 || count( array_unique( $ids ) ) !== count( $ids )
			|| array_filter( $ids, static fn( $id ) => ! is_int( $id ) || $id < 1 ) ) {
			throw new InvalidArgumentException( 'Synchronize at most 500 distinct positive attachment IDs.' );
		}
		$result = array( 'processed' => count( $ids ), 'uploaded' => 0, 'unchanged' => 0, 'pending' => 0,
			'failed' => array(), 'next_after_id' => $ids ? max( $ids ) : 0, 'dry_run' => $dry_run );
		$queue = array();
		foreach ( $ids as $id ) {
			try {
				$source = $this->source( $id );
				if ( $this->mapped( $source ) ) {
					if ( ! $dry_run && $source['dirty_generation'] !== '' ) {
						delete_post_meta( $id, '_ul_cf_images_dirty', $source['dirty_generation'] );
					}
					++$result['unchanged'];
				} else {
					if ( ! $dry_run ) {
						if ( $source['dirty_generation'] === '' ) {
							$source['dirty_generation'] = bin2hex( random_bytes( 16 ) );
							update_post_meta( $id, '_ul_cf_images_dirty', $source['dirty_generation'] );
							if ( get_post_meta( $id, '_ul_cf_images_dirty', true ) !== $source['dirty_generation'] ) {
								throw new RuntimeException( 'Attachment synchronization retry could not be queued.' );
							}
						}
					}
					$queue[] = $source;
					++$result['pending'];
				}
			} catch ( RuntimeException $error ) {
				$result['failed'][] = array( 'id' => $id, 'error' => $error->getMessage() );
			}
		}
		if ( $dry_run || ! $queue ) {
			return $result;
		}
		$token_response = $this->request( 'https://api.cloudflare.com/client/v4/accounts/' . $this->credentials['account_id'] . '/images/v1/batch_token', $this->credentials['token'] );
		$token = $token_response['data']['result']['token'] ?? null;
		$expires = $token_response['data']['result']['expiresAt'] ?? null;
		if ( $token_response['status'] !== 200 || ! $token_response['data']['success'] || ! is_string( $token ) || ! $token
			|| ! is_string( $expires ) || strtotime( $expires ) < time() + 60 ) {
			throw new RuntimeException( 'Could not obtain an Images batch-upload token.' );
		}
		$multi = curl_multi_init();
		$active = array();
		try {
			do {
				while ( $queue && count( $active ) < 4 ) {
					$source = array_shift( $queue );
					$handle = curl_init( 'https://batch.imagedelivery.net/images/v1' );
					curl_setopt_array( $handle, array( CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
						CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
						CURLOPT_HTTPHEADER => array( 'Authorization: Bearer ' . $token ),
						CURLOPT_POSTFIELDS => array( 'file' => new CURLFile( $source['path'], $source['mime'], basename( $source['path'] ) ),
							'id' => $source['image_id'], 'requireSignedURLs' => 'false',
							'metadata' => wp_json_encode( array( 'site' => 'ultralove.ca', 'source_sha256' => $source['source_sha256'] ) ) ) ) );
					curl_multi_add_handle( $multi, $handle );
					$active[spl_object_id( $handle )] = array( 'handle' => $handle, 'source' => $source );
				}
				$status = curl_multi_exec( $multi, $running );
				if ( $status !== CURLM_OK ) {
					throw new RuntimeException( 'Images parallel upload transport failed: curl multi ' . $status . '.' );
				}
				while ( $message = curl_multi_info_read( $multi ) ) {
					$handle = $message['handle'];
					$source = $active[spl_object_id( $handle )]['source'];
					try {
						if ( $message['result'] !== CURLE_OK ) {
							throw new RuntimeException( 'Images upload transport failed: curl ' . $message['result'] . '.' );
						}
						$response = $this->response( curl_multi_getcontent( $handle ), (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) );
						if ( $response['status'] === 409 ) {
							$response = $this->request( 'https://batch.imagedelivery.net/images/v1/' . rawurlencode( $source['image_id'] ), $token );
						}
						$this->confirm( $source, $response );
						++$result['uploaded'];
						--$result['pending'];
					} catch ( RuntimeException $error ) {
						$result['failed'][] = array( 'id' => $source['id'], 'error' => $error->getMessage() );
					}
					curl_multi_remove_handle( $multi, $handle );
					unset( $active[spl_object_id( $handle )] );
				}
				if ( $active && curl_multi_select( $multi, 1.0 ) === -1 ) {
					usleep( 100000 );
				}
			} while ( $queue || $active );
		} finally {
			foreach ( $active as $item ) {
				curl_multi_remove_handle( $multi, $item['handle'] );
			}
		}
		return $result;
	}
}
