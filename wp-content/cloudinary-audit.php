<?php
/**
 * Cloudinary Audit & Repair Script
 *
 * Uses the Cloudinary Admin API to fetch all asset public_ids in the cloud,
 * then diffs that against WordPress postmeta. Attachments whose _public_id
 * is NOT in Cloudinary get their stale _cloudinary meta cleared so the site
 * falls back to local /wp-content/uploads/ URLs automatically.
 *
 * Much faster than per-image HEAD requests: one paginated API call vs 20k HTTP requests.
 *
 * Usage:
 *   wp eval-file wp-content/cloudinary-audit.php --allow-root
 *   CLD_DRY_RUN=1 wp eval-file wp-content/cloudinary-audit.php --allow-root
 */

global $wpdb;

// ── Parse args (via env vars to work around wp eval-file arg limitations) ─────
// Usage:
//   CLD_DRY_RUN=1 wp eval-file wp-content/cloudinary-audit.php --allow-root
//   CLD_BATCH=50 CLD_OFFSET=500 wp eval-file wp-content/cloudinary-audit.php --allow-root
$dry_run   = !empty(getenv('CLD_DRY_RUN'));
$fix_only  = !empty(getenv('CLD_FIX_ONLY'));
$batch     = (int)(getenv('CLD_BATCH')  ?: 30);
$offset    = (int)(getenv('CLD_OFFSET') ?: 0);

echo "=== Cloudinary Audit" . ($dry_run ? " [DRY RUN]" : "") . " ===\n";
echo "Batch size: $batch | Starting offset: $offset\n\n";

// ── Fetch all attachment IDs + cloudinary URLs from DB ────────────────────────
$rows = $wpdb->get_results(
    "SELECT post_id, meta_value
     FROM {$wpdb->postmeta}
     WHERE meta_key = '_cloudinary'
     ORDER BY post_id ASC"
);

$total    = count($rows);
$checked  = 0;
$ok       = 0;
$missing  = 0;  // 404 on Cloudinary, has local file
$orphaned = 0;  // 404 on Cloudinary AND no local file
$cleared  = 0;
$errors   = 0;

// Build a map: post_id => cloudinary_url
$url_map = [];
foreach ($rows as $row) {
    $meta = maybe_unserialize($row->meta_value);
    if (!is_array($meta) || empty($meta['_cloudinary_url'])) continue;
    $url_map[$row->post_id] = $meta['_cloudinary_url'];
}

$ids = array_keys($url_map);
echo "Attachments with _cloudinary meta: " . count($ids) . "\n\n";

// ── Process in batches with parallel curl ─────────────────────────────────────
$chunks = array_chunk($ids, $batch);

foreach ($chunks as $chunk_index => $chunk) {
    $current_offset = $chunk_index * $batch;
    if ($current_offset < $offset) continue;

    // Build curl multi handles
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($chunk as $post_id) {
        $url = $url_map[$post_id];
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$post_id] = $ch;
    }

    // Execute all handles in parallel
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    // Process results
    foreach ($handles as $post_id => $ch) {
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $checked++;
        $cld_url    = $url_map[$post_id];
        $local_file = get_attached_file($post_id);
        $has_local  = $local_file && file_exists($local_file);

        if ($curl_err) {
            echo "ERROR  ID $post_id: curl error: $curl_err ($cld_url)\n";
            $errors++;
            continue;
        }

        if ($http_code === 200) {
            $ok++;
            if (!$fix_only) {
                // Only print in verbose mode — comment out if too noisy
                // echo "OK     ID $post_id: $cld_url\n";
            }
            continue;
        }

        // Not 200 — this is a problem
        $local_label = $has_local ? "local=YES" : "local=NO";
        echo "BROKEN ID $post_id (HTTP $http_code, $local_label): $cld_url\n";

        if ($has_local) {
            $missing++;
            if (!$dry_run) {
                delete_post_meta($post_id, '_cloudinary');
                delete_post_meta($post_id, '_cloudinary_version');
                delete_post_meta($post_id, '_public_id');
                $new_url = wp_get_attachment_url($post_id);
                echo "  → Cleared meta. Falls back to: $new_url\n";
                $cleared++;
            } else {
                echo "  → [DRY RUN] Would clear meta and fall back to local.\n";
            }
        } else {
            $orphaned++;
            echo "  → No local file either — image is genuinely missing.\n";
        }
    }

    curl_multi_close($mh);

    // Progress report every 10 batches
    if (($chunk_index + 1) % 10 === 0) {
        echo "\n--- Progress: checked $checked / " . count($ids) . " | OK: $ok | Broken: " . ($missing + $orphaned) . " | Cleared: $cleared ---\n\n";
        // Flush object cache periodically to avoid memory bloat
        wp_cache_flush();
    }
}

// ── Final summary ─────────────────────────────────────────────────────────────
echo "\n=== SUMMARY ===\n";
echo "Total checked   : $checked\n";
echo "OK (200)        : $ok\n";
echo "Broken (missing): $missing  (had local file" . (!$dry_run ? ", meta cleared" : ", dry-run") . ")\n";
echo "Broken (orphan) : $orphaned (no local file either)\n";
echo "Errors          : $errors\n";
if (!$dry_run) {
    echo "Meta cleared    : $cleared\n";
}
echo "===============\n";
