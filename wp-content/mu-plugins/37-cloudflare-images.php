<?php
/**
 * Plugin Name: UltraLove Hosted Images
 * Description: Account-bound Cloudflare Images copies; original uploads and backups remain intact.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ul_cf_images_url( int $attachment_id, array $options ): string {
	$hash = get_option( 'ul_cf_images_account_hash', '' );
	$mapping = get_post_meta( $attachment_id, '_ul_cf_images', true );
	$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
	$path = get_attached_file( $attachment_id, true );
	if ( ! is_string( $hash ) || ! preg_match( '/^[A-Za-z0-9_-]{10,100}$/D', $hash )
		|| ! is_array( $mapping ) || ( $mapping['account_hash'] ?? null ) !== $hash
		|| ! is_string( $mapping['image_id'] ?? null ) || ! preg_match( '~^ultralove/[a-f0-9]{64}$~D', $mapping['image_id'] )
		|| ( $mapping['source_file'] ?? null ) !== $file
		|| get_post_meta( $attachment_id, '_ul_cf_images_dirty', true )
		|| ! is_string( $path ) || ! is_file( $path )
		|| ( $mapping['source_mtime'] ?? null ) !== filemtime( $path )
		|| ( $mapping['source_size'] ?? null ) !== filesize( $path ) ) {
		static $reported = array();
		if ( ! isset( $reported[$attachment_id] ) ) {
			error_log( '[ultralove-hosted-images] Attachment ' . $attachment_id . ' is not synchronized; retaining existing image delivery.' );
			$reported[$attachment_id] = true;
		}
		return '';
	}
	$parameters = array();
	foreach ( $options as $name => $value ) {
		if ( in_array( $name, array( 'width', 'height', 'quality', 'trim.left', 'trim.top', 'trim.width', 'trim.height' ), true ) ) {
			$minimum = in_array( $name, array( 'trim.left', 'trim.top' ), true ) ? 0 : 1;
			if ( ! is_int( $value ) || $value < $minimum || $value > ( $name === 'quality' ? 100 : 12000 ) ) {
				throw new InvalidArgumentException( 'Unsupported hosted image dimensions or quality.' );
			}
		} elseif ( ! ( ( $name === 'fit' && in_array( $value, array( 'cover', 'scale-down' ), true ) )
			|| ( $name === 'gravity' && $value === 'auto' ) ) ) {
			throw new InvalidArgumentException( 'Unsupported hosted image parameter.' );
		}
		$parameters[] = $name . '=' . $value;
	}
	if ( ! $parameters ) {
		throw new InvalidArgumentException( 'Hosted images require explicit display parameters.' );
	}
	return 'https://imagedelivery.net/' . $hash . '/' . $mapping['image_id'] . '/' . implode( ',', $parameters );
}

function ul_cf_images_is_url( string $url ): bool {
	$hash = get_option( 'ul_cf_images_account_hash', '' );
	return is_string( $hash ) && preg_match( '/^[A-Za-z0-9_-]{10,100}$/D', $hash )
		&& str_starts_with( $url, 'https://imagedelivery.net/' . $hash . '/ultralove/' );
}

function ul_cf_images_mark_dirty( int $attachment_id ): void {
	if ( get_option( 'ul_cf_images_sync_enabled', false )
		&& in_array( get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		update_post_meta( $attachment_id, '_ul_cf_images_dirty', bin2hex( random_bytes( 16 ) ) );
	}
}

add_filter( 'wp_update_attachment_metadata', static function ( $metadata, $attachment_id ) {
	ul_cf_images_mark_dirty( (int) $attachment_id );
	return $metadata;
}, 20, 2 );
add_filter( 'update_attached_file', static function ( $file, $attachment_id ) {
	ul_cf_images_mark_dirty( (int) $attachment_id );
	return $file;
}, 20, 2 );

add_action( 'init', static function () {
	if ( get_option( 'ul_cf_images_sync_enabled', false ) && ! wp_next_scheduled( 'ul_cf_images_sync' ) ) {
		wp_schedule_event( time() + 60, 'hourly', 'ul_cf_images_sync' );
	} elseif ( ! get_option( 'ul_cf_images_sync_enabled', false ) && wp_next_scheduled( 'ul_cf_images_sync' ) ) {
		wp_clear_scheduled_hook( 'ul_cf_images_sync' );
	}
} );

add_action( 'ul_cf_images_sync', static function () {
	if ( ! get_option( 'ul_cf_images_sync_enabled', false ) ) {
		return;
	}
	require_once __DIR__ . '/ultralove-cloudflare-images/sync.php';
	try {
		$sync = new UltraLove_CF_Images_Sync();
		$result = $sync->sync( UltraLove_CF_Images_Sync::ids( 0, 50, true ) );
		if ( $result['failed'] ) {
			error_log( '[ultralove-hosted-images] ' . wp_json_encode( $result['failed'] ) );
		}
	} catch ( RuntimeException $error ) {
		error_log( '[ultralove-hosted-images] Background synchronization failed: ' . $error->getMessage() );
	}
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/ultralove-cloudflare-images/sync.php';
	WP_CLI::add_command( 'ultralove-images sync', static function ( $args, $options ) {
		try {
			$after = filter_var( $options['after-id'] ?? 0, FILTER_VALIDATE_INT );
			$limit = filter_var( $options['limit'] ?? 100, FILTER_VALIDATE_INT );
			if ( $after === false || $after < 0 || $limit === false || $limit < 1 || $limit > 500 ) {
				throw new InvalidArgumentException( 'Use a nonnegative --after-id and --limit between 1 and 500.' );
			}
			$sync = new UltraLove_CF_Images_Sync();
			$ids = UltraLove_CF_Images_Sync::ids( $after, $limit, isset( $options['pending'] ) );
			$result = $sync->sync( $ids, isset( $options['dry-run'] ) );
			WP_CLI::log( wp_json_encode( $result ) );
			if ( $result['failed'] ) {
				WP_CLI::halt( 1 );
			}
		} catch ( RuntimeException | InvalidArgumentException $error ) {
			WP_CLI::error( $error->getMessage() );
		}
	} );
}
