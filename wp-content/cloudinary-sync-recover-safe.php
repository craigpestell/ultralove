<?php
/**
 * Safe Cloudinary recovery sync for large backlogs.
 *
 * Purpose:
 * - Targets attachments that are not fully synced to Cloudinary
 *   (no _cloudinary_url in serialized _cloudinary meta blob).
 * - Forces local-file upload path to avoid stale remote URL fetches.
 * - Skips empty or oversize files and records a clear _cld_error message.
 * - Uses throttling controls for long-running batches.
 *
 * Usage:
 *   CLD_DRY_RUN=1 wp eval-file wp-content/cloudinary-sync-recover-safe.php --allow-root
 *   CLD_DRY_RUN=0 CLD_LIMIT=200 wp eval-file wp-content/cloudinary-sync-recover-safe.php --allow-root
 *
 * Env vars:
 *   CLD_DRY_RUN=1            Default: 1 (safe preview)
 *   CLD_DELAY_MS=500         Delay between items (default: 500)
 *   CLD_BATCH_PAUSE_MS=3000  Pause every batch (default: 3000)
 *   CLD_BATCH_SIZE=20        Items per batch before pause (default: 20)
 *   CLD_LIMIT=0              Max items per run (default: 0 = unlimited)
 *   CLD_OFFSET=0             Skip first N matching items (default: 0)
 *   CLD_WORKER_TOTAL=1        Total parallel workers (default: 1)
 *   CLD_WORKER_INDEX=0        This worker index, 0-based (default: 0)
 */

global $wpdb;

@ini_set( 'output_buffering', 'off' );
@ini_set( 'zlib.output_compression', '0' );
if ( function_exists( 'apache_setenv' ) ) {
	@apache_setenv( 'no-gzip', '1' );
}
while ( function_exists( 'ob_get_level' ) && ob_get_level() > 0 ) {
	@ob_end_flush();
}
if ( function_exists( 'ob_implicit_flush' ) ) {
	ob_implicit_flush( true );
}

/**
 * Emit log line immediately to STDERR to avoid WP-CLI/stdout buffering delays.
 *
 * @param string $line    Text to log.
 * @param bool   $newline Whether to append a newline.
 */
function cld_log( $line = '', $newline = true ) {
	$payload = $newline ? $line . PHP_EOL : $line;
	fwrite( STDERR, $payload );
	if ( function_exists( 'flush' ) ) {
		@flush();
	}
}

$dry_run     = (string) getenv( 'CLD_DRY_RUN' );
$dry_run     = '' === $dry_run ? true : ! empty( $dry_run ) && '0' !== $dry_run;
$delay_us    = (int) ( getenv( 'CLD_DELAY_MS' ) !== false ? getenv( 'CLD_DELAY_MS' ) : 500 ) * 1000;
$batch_pause = (int) ( getenv( 'CLD_BATCH_PAUSE_MS' ) !== false ? getenv( 'CLD_BATCH_PAUSE_MS' ) : 3000 ) * 1000;
$batch_size  = (int) ( getenv( 'CLD_BATCH_SIZE' ) !== false ? getenv( 'CLD_BATCH_SIZE' ) : 20 );
$limit       = (int) ( getenv( 'CLD_LIMIT' ) !== false ? getenv( 'CLD_LIMIT' ) : 0 );
$offset      = (int) ( getenv( 'CLD_OFFSET' ) !== false ? getenv( 'CLD_OFFSET' ) : 0 );
$worker_total = (int) ( getenv( 'CLD_WORKER_TOTAL' ) !== false ? getenv( 'CLD_WORKER_TOTAL' ) : 1 );
$worker_index = (int) ( getenv( 'CLD_WORKER_INDEX' ) !== false ? getenv( 'CLD_WORKER_INDEX' ) : 0 );

if ( $worker_total < 1 ) {
	$worker_total = 1;
}
if ( $worker_index < 0 || $worker_index >= $worker_total ) {
	cld_log( "ERROR: Invalid worker index {$worker_index} for worker total {$worker_total}." );
	exit( 1 );
}

cld_log( "=== Cloudinary Safe Recovery Sync" . ( $dry_run ? " [DRY RUN]" : '' ) . " ===" );
cld_log( "Delay between items  : {$delay_us}us (" . ( $delay_us / 1000 ) . "ms)" );
cld_log( "Batch size           : {$batch_size} items, then {$batch_pause}us pause" );
cld_log( "Limit                : " . ( $limit ? $limit : 'unlimited' ) );
cld_log( "Offset               : {$offset}" );
cld_log( "Worker               : {$worker_index}/" . ( $worker_total - 1 ) . " (total={$worker_total})" );
cld_log();

if ( ! function_exists( 'Cloudinary\\get_plugin_instance' ) ) {
	require_once __DIR__ . '/../plugins/cloudinary-image-management-and-manipulation-in-the-cloud-cdn/instance.php';
}

$plugin = \Cloudinary\get_plugin_instance();
if ( ! $plugin ) {
	cld_log( 'ERROR: Cloudinary plugin not active or not loaded.' );
	exit( 1 );
}

$sync        = $plugin->get_component( 'sync' );
$upload_sync = $sync->managers['upload'] ?? null;
$media       = $plugin->get_component( 'media' );
$connect     = $plugin->get_component( 'connect' );

if ( ! $sync || ! $upload_sync || ! $media || ! $connect ) {
	cld_log( 'ERROR: Missing required Cloudinary plugin components.' );
	exit( 1 );
}

// Force local file upload path; avoids stale source fetches from remote URL.
if ( ! $dry_run ) {
	set_transient( '_cld_disable_http_upload', 1, DAY_IN_SECONDS );
}

cld_log( 'Querying attachments missing Cloudinary URL...' );

$ids = $wpdb->get_col(
	"SELECT p.ID
	 FROM {$wpdb->posts} p
	 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_cloudinary'
	 WHERE p.post_type = 'attachment'
	   AND p.post_status = 'inherit'
	   AND (pm.meta_id IS NULL OR pm.meta_value NOT LIKE '%_cloudinary_url%')
	 ORDER BY p.ID ASC"
);

$total = count( $ids );
cld_log( "Total candidates: {$total}" );

if ( $offset > 0 ) {
	$ids = array_slice( $ids, $offset );
	cld_log( "After offset ({$offset}): " . count( $ids ) );
}
if ( $limit > 0 ) {
	$ids = array_slice( $ids, 0, $limit );
	cld_log( 'After limit: ' . count( $ids ) );
}

if ( $worker_total > 1 ) {
	$worker_ids = array();
	foreach ( $ids as $position => $candidate_id ) {
		if ( $position % $worker_total === $worker_index ) {
			$worker_ids[] = $candidate_id;
		}
	}
	$ids = $worker_ids;
	cld_log( "After worker shard {$worker_index}/" . ( $worker_total - 1 ) . ': ' . count( $ids ) );
}
cld_log();

if ( empty( $ids ) ) {
	cld_log( 'Nothing to process.' );
	exit( 0 );
}

$done        = 0;
$skipped     = 0;
$errors      = 0;
$start       = microtime( true );
$image_limit = (int) ( $connect->usage['media_limits']['image_max_size_bytes'] ?? 0 );
$video_limit = (int) ( $connect->usage['media_limits']['video_max_size_bytes'] ?? 0 );
$total_run   = count( $ids );

// Emit a heartbeat summary every N processed items so long runs show liveness.
$heartbeat_every = 10;

foreach ( $ids as $id ) {
	$item_start = microtime( true );
	$index      = $done + $skipped + $errors + 1;
	cld_log( '[' . $index . '/' . $total_run . "] ID {$id} start" );

	$id   = (int) $id;
	$file = get_attached_file( $id, true );

	if ( ! $file || ! file_exists( $file ) ) {
		cld_log( "  SKIP {$id}: file missing" );
		$skipped++;
		$seconds = round( microtime( true ) - $item_start, 2 );
		cld_log( "  DONE {$id}: skipped in {$seconds}s" );
		continue;
	}

	$size      = (int) filesize( $file );
	$is_image  = (bool) wp_attachment_is_image( $id );
	$max_bytes = $is_image ? $image_limit : $video_limit;

	if ( 0 === $size ) {
		if ( ! $dry_run ) {
			update_post_meta( $id, '_cld_error', 'Empty file. Kept local-only.' );
		}
		cld_log( "  SKIP {$id}: empty file" );
		$skipped++;
		$seconds = round( microtime( true ) - $item_start, 2 );
		cld_log( "  DONE {$id}: skipped in {$seconds}s" );
		continue;
	}

	if ( $max_bytes > 0 && $size > $max_bytes ) {
		if ( ! $dry_run ) {
			update_post_meta( $id, '_cld_error', "File too large for Cloudinary limit ({$size} > {$max_bytes}). Kept local-only." );
		}
		cld_log( "  SKIP {$id}: oversize {$size} > {$max_bytes}" );
		$skipped++;
		$seconds = round( microtime( true ) - $item_start, 2 );
		cld_log( "  DONE {$id}: skipped in {$seconds}s" );
		continue;
	}

	if ( $dry_run ) {
		cld_log( "  [DRY RUN] Would upload {$id}: " . basename( $file ) . " ({$size} bytes)" );
		$done++;
	} else {
		// Clear stale metadata for clean retry state.
		$sync->delete_cloudinary_meta( $id );

		cld_log( "  Uploading {$id}: " . basename( $file ) );
		$result = $upload_sync->upload_asset( $id, 'file' );

		if ( is_wp_error( $result ) ) {
			cld_log( '  ERROR: ' . $result->get_error_message() );
			$errors++;
		} else {
			$public_id = $result['public_id'] ?? '?';
			cld_log( "  OK -> {$public_id}" );
			$done++;
		}

		if ( $delay_us > 0 ) {
			usleep( $delay_us );
		}
	}

	$seconds = round( microtime( true ) - $item_start, 2 );
	cld_log( "  DONE {$id}: {$seconds}s" );

	if ( $batch_size > 0 && ( $done + $skipped + $errors ) % $batch_size === 0 ) {
		$elapsed = round( microtime( true ) - $start, 1 );
		cld_log( "  --- Batch pause: done={$done}, skipped={$skipped}, errors={$errors}, elapsed={$elapsed}s ---" );
		if ( ! $dry_run && $batch_pause > 0 ) {
			usleep( $batch_pause );
		}
	}

	$processed = $done + $skipped + $errors;
	if ( $processed > 0 && 0 === ( $processed % $heartbeat_every ) ) {
		$elapsed = round( microtime( true ) - $start, 1 );
		$rate    = $elapsed > 0 ? round( $processed / $elapsed, 2 ) : 0;
		$remaining_items = max( 0, $total_run - $processed );
		$eta_seconds     = ( $rate > 0 ) ? (int) round( $remaining_items / $rate ) : 0;
		$eta_hms         = gmdate( 'H:i:s', $eta_seconds );
		cld_log( "  >>> HEARTBEAT: processed={$processed}/{$total_run}, done={$done}, skipped={$skipped}, errors={$errors}, elapsed={$elapsed}s, rate={$rate}/s, eta={$eta_hms}" );
	}
}

$elapsed = round( microtime( true ) - $start, 1 );

cld_log();
cld_log( '=== SUMMARY' . ( $dry_run ? ' [DRY RUN]' : '' ) . ' ===' );
cld_log( "Total candidates     : {$total}" );
cld_log( "Processed (attempted): {$done}" );
cld_log( "Skipped              : {$skipped}" );
cld_log( "Errors               : {$errors}" );
cld_log( "Time                 : {$elapsed}s" );
if ( $done > 0 && $elapsed > 0 ) {
	cld_log( 'Rate                 : ' . round( $done / $elapsed, 2 ) . ' items/sec' );
}
cld_log( '===============' );
