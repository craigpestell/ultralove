<?php
/**
 * Export verified public image identities on production; import separately for dev-only previews.
 * Run with wp eval-file outside the webroot. Never exports credentials, image bytes or customer data.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run through WordPress CLI.' );
}
$mode = $args[0] ?? '';
$path = $args[1] ?? '';
if ( ! in_array( $mode, array( 'export', 'import' ), true ) || ! is_string( $path ) || ! str_starts_with( $path, '/' ) ) {
	WP_CLI::error( 'Usage: wp eval-file dev-preview.php export|import /absolute/snapshot.json' );
}
$write = static function ( string $target, array $data ): void {
	if ( file_exists( $target ) ) { throw new RuntimeException( 'Refusing to overwrite existing evidence: ' . $target ); }
	$handle = fopen( $target, 'xb' );
	if ( $handle === false ) { throw new RuntimeException( 'Cannot create evidence file.' ); }
	try {
		if ( ! chmod( $target, 0600 ) ) { throw new RuntimeException( 'Cannot protect evidence file.' ); }
		$json = wp_json_encode( $data );
		if ( ! is_string( $json ) || fwrite( $handle, $json ) !== strlen( $json ) || ! fflush( $handle ) ) {
			throw new RuntimeException( 'Cannot persist complete image-preview evidence.' );
		}
	} finally {
		fclose( $handle );
	}
};
try {
	if ( $mode === 'export' ) {
		if ( wp_parse_url( home_url(), PHP_URL_HOST ) !== 'ultralove.ca' ) {
			throw new RuntimeException( 'Export must run on production; it does not change delivery.' );
		}
		$sync = new UltraLove_CF_Images_Sync();
		$records = array();
		$excluded = array();
		$after = 0;
		while ( $ids = UltraLove_CF_Images_Sync::ids( $after, 500 ) ) {
			foreach ( $ids as $id ) {
				try {
					$source = $sync->source( $id );
					if ( ! $sync->mapped( $source ) || $source['dirty_generation'] !== '' ) {
						throw new RuntimeException( 'Source does not have a current verified hosted mapping.' );
					}
					$metadata = wp_get_attachment_metadata( $id );
					$records[] = array(
						'id' => $id, 'account_hash' => get_option( 'ul_cf_images_account_hash' ),
						'image_id' => $source['image_id'], 'source_sha256' => $source['source_sha256'],
						'source_file' => $source['source_file'], 'source_mime' => $source['mime'],
						'source_width' => (int) $metadata['width'], 'source_height' => (int) $metadata['height'],
					);
				} catch ( RuntimeException $error ) {
					$excluded[] = array( 'id' => $id, 'error' => $error->getMessage() );
				}
			}
			$after = max( $ids );
		}
		$write( $path, array( 'version' => 1, 'source_host' => 'ultralove.ca', 'created_at' => gmdate( 'c' ),
			'account_hash' => get_option( 'ul_cf_images_account_hash' ), 'records' => $records, 'excluded' => $excluded ) );
		WP_CLI::log( wp_json_encode( array( 'exported' => count( $records ), 'excluded' => count( $excluded ), 'path' => $path ) ) );
		return;
	}
	if ( wp_get_environment_type() !== 'development' || wp_parse_url( home_url(), PHP_URL_HOST ) !== 'dev.ultralove.ca' ) {
		throw new RuntimeException( 'Preview import is restricted to the development environment and dev hostname.' );
	}
	if ( ! is_readable( $path ) ) { throw new RuntimeException( 'Trusted image snapshot is not readable.' ); }
	$snapshot = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $snapshot ) || ( $snapshot['version'] ?? null ) !== 1
		|| ( $snapshot['source_host'] ?? null ) !== 'ultralove.ca'
		|| ( $snapshot['account_hash'] ?? null ) !== get_option( 'ul_cf_images_account_hash' )
		|| ! is_array( $snapshot['records'] ?? null ) || ! $snapshot['records'] ) {
		throw new RuntimeException( 'Snapshot identity, account or records are invalid.' );
	}
	$matches = array();
	$skipped = array();
	$previous = array();
	$seen = array();
	foreach ( $snapshot['records'] as $record ) {
		if ( ! is_array( $record ) || ! is_int( $record['id'] ?? null ) || $record['id'] < 1
			|| ! is_string( $record['source_sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $record['source_sha256'] )
			|| ( $record['image_id'] ?? null ) !== 'ultralove/' . $record['source_sha256']
			|| ( $record['account_hash'] ?? null ) !== $snapshot['account_hash']
			|| ! is_string( $record['source_file'] ?? null )
			|| ! in_array( $record['source_mime'] ?? null, array( 'image/jpeg', 'image/png', 'image/webp' ), true )
			|| ! is_int( $record['source_width'] ?? null ) || $record['source_width'] < 1
			|| ! is_int( $record['source_height'] ?? null ) || $record['source_height'] < 1
			|| isset( $seen[$record['id']] ) ) {
			throw new RuntimeException( 'Malformed or duplicate source record; no preview mappings were changed.' );
		}
		$id = $record['id'];
		$seen[$id] = true;
		$metadata = wp_get_attachment_metadata( $id );
		$local = get_attached_file( $id, true );
		if ( get_post_type( $id ) !== 'attachment'
			|| get_post_meta( $id, '_wp_attached_file', true ) !== $record['source_file']
			|| get_post_mime_type( $id ) !== $record['source_mime']
			|| (int) ( $metadata['width'] ?? 0 ) !== $record['source_width']
			|| (int) ( $metadata['height'] ?? 0 ) !== $record['source_height']
			|| get_post_meta( $id, '_ul_cf_images_dirty', true )
			|| ( is_string( $local ) && is_file( $local ) ) ) {
			$skipped[] = array( 'id' => $id, 'reason' => 'Absent, changed, dirty or locally present dev source; existing delivery retained.' );
			continue;
		}
		$matches[$id] = $record;
		$previous[$id] = get_post_meta( $id, '_ul_cf_images_dev_preview', true );
	}
	if ( ! $matches ) { throw new RuntimeException( 'No matching dev sources; preview was not enabled.' ); }
	$write( $path . '.before.json', array( 'preview_enabled' => get_option( 'ul_cf_images_dev_remote_preview', false ), 'mappings' => $previous ) );
	foreach ( $matches as $id => $record ) {
		update_post_meta( $id, '_ul_cf_images_dev_preview', $record );
		if ( get_post_meta( $id, '_ul_cf_images_dev_preview', true ) !== $record ) {
			throw new RuntimeException( 'Preview mapping could not be saved for attachment ' . $id . '; recovery snapshot retained.' );
		}
	}
	update_option( 'ul_cf_images_dev_remote_preview', true );
	if ( ! ul_cf_images_dev_preview_enabled() ) { throw new RuntimeException( 'Dev remote-preview flag could not be enabled.' ); }
	$report = array( 'imported' => count( $matches ), 'skipped' => $skipped, 'created_at' => gmdate( 'c' ),
		'preview_enabled' => true, 'source_snapshot' => $snapshot['created_at'] ?? null );
	$write( $path . '.result.json', $report );
	WP_CLI::log( wp_json_encode( array( 'imported' => count( $matches ), 'skipped' => count( $skipped ), 'preview_enabled' => true ) ) );
} catch ( RuntimeException | JsonException $error ) {
	WP_CLI::error( $error->getMessage() );
}
