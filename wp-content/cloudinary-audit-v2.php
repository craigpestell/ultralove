<?php
/**
 * Cloudinary Audit & Repair Script (API-based)
 *
 * Uses the Cloudinary Admin API to fetch all asset public_ids stored in the
 * cloud, then diffs against WordPress postmeta. Attachments whose _public_id
 * is NOT present in Cloudinary get their stale _cloudinary meta cleared so the
 * site falls back to local /wp-content/uploads/ URLs automatically.
 *
 * Much faster than per-image HEAD requests: ~N/500 API calls vs N HTTP requests.
 *
 * Usage:
 *   wp eval-file wp-content/cloudinary-audit-v2.php --allow-root
 *   CLD_DRY_RUN=1 wp eval-file wp-content/cloudinary-audit-v2.php --allow-root
 *
 * Env vars:
 *   CLD_DRY_RUN=1   Report problems without making changes.
 *   CLD_PREFIX=...  Limit API scan to a folder prefix (default: ultralove/).
 */

global $wpdb;

$dry_run = !empty(getenv('CLD_DRY_RUN'));
// 'ultralove' (no trailing slash) matches both ultralove/catalog/... and ultralove.ca/...
$prefix  = getenv('CLD_PREFIX') ?: 'ultralove';

// ── 1. Load Cloudinary credentials from WordPress options ─────────────────────
$connect = get_option('cloudinary_connect', []);
if (empty($connect['cloudinary_url'])) {
    echo "ERROR: cloudinary_connect option not found or missing cloudinary_url.\n";
    exit(1);
}
// Format: cloudinary://api_key:api_secret@cloud_name
if (!preg_match('#cloudinary://(\d+):([^@]+)@(\S+)#', $connect['cloudinary_url'], $m)) {
    echo "ERROR: Could not parse cloudinary_url: " . $connect['cloudinary_url'] . "\n";
    exit(1);
}
$api_key    = $m[1];
$api_secret = $m[2];
$cloud_name = $m[3];

echo "=== Cloudinary Audit (Admin API)" . ($dry_run ? " [DRY RUN]" : "") . " ===\n";
echo "Cloud : $cloud_name\n";
echo "Prefix: $prefix\n\n";

// ── 2. Fetch all asset public_ids from Cloudinary Admin API ───────────────────
echo "Fetching asset list from Cloudinary Admin API...\n";

$cloudinary_public_ids = [];
$next_cursor           = null;
$api_calls             = 0;
$base_url              = "https://api.cloudinary.com/v1_1/{$cloud_name}/resources/image/upload";

do {
    $params = http_build_query(array_filter([
        'max_results' => 500,
        'prefix'      => $prefix,
        'next_cursor' => $next_cursor,
    ]));

    $ch = curl_init("$base_url?$params");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$api_key:$api_secret",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $api_calls++;

    if ($http !== 200) {
        echo "ERROR: Admin API returned HTTP $http\n$response\n";
        exit(1);
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "ERROR: JSON decode failed\n";
        exit(1);
    }

    foreach ($data['resources'] as $asset) {
        $cloudinary_public_ids[$asset['public_id']] = true;
    }

    $next_cursor = $data['next_cursor'] ?? null;

    if ($api_calls % 10 === 0 || !$next_cursor) {
        echo "  API calls: $api_calls | Assets fetched so far: " . count($cloudinary_public_ids) . "\n";
    }

} while ($next_cursor);

$total_in_cloud = count($cloudinary_public_ids);
echo "\nTotal assets in Cloudinary ($cloud_name): $total_in_cloud\n\n";

// ── 3. Load all WordPress attachments with _cloudinary meta ──────────────────
echo "Loading WordPress attachment meta...\n";

$rows = $wpdb->get_results(
    "SELECT post_id, meta_value
     FROM {$wpdb->postmeta}
     WHERE meta_key = '_cloudinary'
     ORDER BY post_id ASC"
);

$total_wp      = count($rows);
$missing       = [];  // in WP, not in Cloudinary
$orphaned      = [];  // not in Cloudinary AND no local file
$ok            = 0;

foreach ($rows as $row) {
    $meta      = maybe_unserialize($row->meta_value);
    $public_id = is_array($meta) ? ($meta['_public_id'] ?? null) : null;

    if (!$public_id) {
        continue; // No public_id stored, skip
    }

    if (isset($cloudinary_public_ids[$public_id])) {
        $ok++;
    } else {
        // Not in Cloudinary — check for local fallback
        $local_file = get_attached_file($row->post_id);
        $has_local  = $local_file && file_exists($local_file);
        if ($has_local) {
            $missing[] = ['post_id' => $row->post_id, 'public_id' => $public_id, 'local' => $local_file];
        } else {
            $orphaned[] = ['post_id' => $row->post_id, 'public_id' => $public_id];
        }
    }
}

echo "WordPress attachments with _cloudinary meta : $total_wp\n";
echo "Found in Cloudinary (OK)                    : $ok\n";
echo "Missing from Cloudinary (has local fallback): " . count($missing) . "\n";
echo "Missing from Cloudinary (no local file)     : " . count($orphaned) . "\n\n";

// ── 4. Report orphans ─────────────────────────────────────────────────────────
if (!empty($orphaned)) {
    echo "=== ORPHANED (no local file, not in Cloudinary) ===\n";
    foreach ($orphaned as $item) {
        echo "  ID {$item['post_id']}: {$item['public_id']}\n";
    }
    echo "\n";
}

// ── 5. Fix: clear stale meta for missing-but-local attachments ───────────────
if (empty($missing)) {
    echo "No stale _cloudinary meta to clear.\n";
} else {
    echo "=== " . ($dry_run ? "[DRY RUN] Would clear" : "Clearing") . " stale _cloudinary meta ===\n";
    $cleared = 0;

    foreach ($missing as $item) {
        $id = $item['post_id'];
        echo "  ID $id: {$item['public_id']}\n";

        if (!$dry_run) {
            delete_post_meta($id, '_cloudinary');
            delete_post_meta($id, '_cloudinary_version');
            delete_post_meta($id, '_public_id');
            $new_url = wp_get_attachment_url($id);
            echo "    → Now: $new_url\n";
            $cleared++;
        }
    }

    if (!$dry_run) {
        wp_cache_flush();
        echo "\nCleared $cleared attachment(s). Cache flushed.\n";
    }}

// ── 6. Summary ────────────────────────────────────────────────────────────────
echo "\n=== SUMMARY ===\n";
echo "Cloudinary assets fetched (API calls: $api_calls) : $total_in_cloud\n";
echo "WP attachments with cloudinary meta              : $total_wp\n";
echo "OK (present in Cloudinary)                       : $ok\n";
echo "Fixed (meta cleared, fallback to local)          : " . ($dry_run ? count($missing) . " (dry-run)" : (isset($cleared) ? $cleared : 0)) . "\n";
echo "Orphaned (missing everywhere)                    : " . count($orphaned) . "\n";
echo "===============\n";
