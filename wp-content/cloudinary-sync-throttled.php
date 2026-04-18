<?php
/**
 * Rate-limited Cloudinary sync script.
 *
 * Uploads all local-only attachments (no _cloudinary meta) to Cloudinary
 * using the plugin's own upload_asset() method, with a configurable delay
 * between uploads to stay within API rate limits.
 *
 * Usage:
 *   wp eval-file wp-content/cloudinary-sync-throttled.php --allow-root
 *
 * Env vars:
 *   CLD_DRY_RUN=1         Report what would happen without uploading.
 *   CLD_DELAY_MS=500      Milliseconds to sleep between uploads (default: 500).
 *   CLD_BATCH_PAUSE_MS=3000  Extra pause every CLD_BATCH_SIZE uploads (default: 3000).
 *   CLD_BATCH_SIZE=20     Uploads per batch before longer pause (default: 20).
 *   CLD_LIMIT=0           Max uploads this run, 0 = unlimited (default: 0).
 *   CLD_OFFSET=0          Skip first N unsynced attachments (default: 0).
 */

global $wpdb;

$dry_run      = !empty(getenv('CLD_DRY_RUN'));
$delay_us     = (int)(getenv('CLD_DELAY_MS') !== false ? getenv('CLD_DELAY_MS') : 500) * 1000;
$batch_pause  = (int)(getenv('CLD_BATCH_PAUSE_MS') !== false ? getenv('CLD_BATCH_PAUSE_MS') : 3000) * 1000;
$batch_size   = (int)(getenv('CLD_BATCH_SIZE') !== false ? getenv('CLD_BATCH_SIZE') : 20);
$limit        = (int)(getenv('CLD_LIMIT') !== false ? getenv('CLD_LIMIT') : 0);
$offset       = (int)(getenv('CLD_OFFSET') !== false ? getenv('CLD_OFFSET') : 0);

echo "=== Cloudinary Rate-Limited Sync" . ($dry_run ? " [DRY RUN]" : "") . " ===\n";
echo "Delay between uploads : {$delay_us}µs (" . ($delay_us/1000) . "ms)\n";
echo "Batch size            : $batch_size uploads, then {$batch_pause}µs pause\n";
echo "Limit                 : " . ($limit ? $limit : "unlimited") . "\n";
echo "Offset                : $offset\n\n";

// ── 1. Get Cloudinary plugin instance ─────────────────────────────────────────
if (!function_exists('Cloudinary\get_plugin_instance')) {
    require_once __DIR__ . '/../plugins/cloudinary-image-management-and-manipulation-in-the-cloud-cdn/instance.php';
}
$plugin = \Cloudinary\get_plugin_instance();
if (!$plugin) {
    echo "ERROR: Cloudinary plugin not active or not loaded.\n";
    exit(1);
}

$sync        = $plugin->get_component('sync');
$upload_sync = $sync->managers['upload'] ?? null;
if (!$upload_sync) {
    echo "ERROR: Could not get Upload_Sync manager from plugin.\n";
    exit(1);
}

// ── 2. Find all attachments WITHOUT _cloudinary meta ──────────────────────────
echo "Querying unsynced attachments...\n";

$ids = $wpdb->get_col(
    "SELECT p.ID
     FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_cloudinary'
     WHERE p.post_type = 'attachment'
       AND p.post_status = 'inherit'
       AND pm.meta_value IS NULL
     ORDER BY p.ID ASC"
);

$total = count($ids);
echo "Total unsynced attachments: $total\n";

if ($offset) {
    $ids = array_slice($ids, $offset);
    echo "After offset ($offset): " . count($ids) . " remaining\n";
}
if ($limit) {
    $ids = array_slice($ids, 0, $limit);
    echo "Capped to limit: " . count($ids) . "\n";
}
echo "\n";

if (empty($ids)) {
    echo "Nothing to sync.\n";
    exit(0);
}

// ── 3. Upload each attachment with rate limiting ───────────────────────────────
$done      = 0;
$skipped   = 0;
$errors    = 0;
$start     = microtime(true);

foreach ($ids as $id) {
    $id   = (int)$id;
    $file = get_attached_file($id, true);

    if (!$file || !file_exists($file)) {
        echo "  SKIP $id: no local file\n";
        $skipped++;
        continue;
    }

    if ($dry_run) {
        echo "  [DRY RUN] Would upload ID $id: " . basename($file) . "\n";
        $done++;
    } else {
        echo "  Uploading ID $id: " . basename($file) . " ... ";
        $result = $upload_sync->upload_asset($id);

        if (is_wp_error($result)) {
            echo "ERROR: " . $result->get_error_message() . "\n";
            $errors++;
        } else {
            $public_id = $result['public_id'] ?? '?';
            echo "OK → $public_id\n";
            $done++;
        }

        // Per-upload delay
        if ($delay_us > 0) {
            usleep($delay_us);
        }
    }

    // Batch pause every $batch_size uploads
    if ($batch_size > 0 && ($done + $skipped + $errors) % $batch_size === 0) {
        $elapsed = round(microtime(true) - $start, 1);
        $rate    = $done > 0 ? round($done / $elapsed, 2) : 0;
        echo "  --- Batch pause: {$done} uploaded, {$errors} errors, {$elapsed}s elapsed ({$rate}/s) ---\n";
        if (!$dry_run && $batch_pause > 0) {
            usleep($batch_pause);
        }
    }
}

// ── 4. Summary ────────────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $start, 1);
echo "\n=== SUMMARY" . ($dry_run ? " [DRY RUN]" : "") . " ===\n";
echo "Total unsynced found : $total\n";
echo "Uploaded             : $done\n";
echo "Skipped (no file)    : $skipped\n";
echo "Errors               : $errors\n";
echo "Time                 : {$elapsed}s\n";
if ($done > 0 && $elapsed > 0) {
    echo "Rate                 : " . round($done / $elapsed, 2) . " uploads/sec\n";
}
echo "===============\n";
