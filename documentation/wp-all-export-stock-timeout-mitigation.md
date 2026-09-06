# WP All Export Stock Timeout Mitigation

## Problem
Manual stock exports run in a browser request for up to ~45 minutes and frequently fail with a Cloudflare Bad Gateway response.

## Findings
- WP All Export Pro is active and supports trigger/processing cron URLs.
- Long browser-driven export requests are vulnerable to proxy/request timeout limits.
- A custom plugin had been creating duplicate recurring cron entries, increasing background load and making long export windows less stable.

## Fixes Applied In This Repository
1. Patched custom plugin scheduling logic:
   - File: `wp-content/plugins/ultralove-product-export-sync/ultralove-product-export-sync.php`
   - Removed argument-based recurring scheduling that caused duplicate cron events.
   - Added one-time schedule repair helper for legacy schedules.
   - Kept source tracking via transient instead of cron args.

2. Added server-side WP All Export runner script:
   - File: `scripts/run_wpae_export.sh`
   - Executes WP All Export via short trigger + processing calls from the server origin (`127.0.0.1` with Host header), avoiding long browser requests.

## How To Use The New Script

### 1) Get the export ID
From WordPress Admin:
- All Export -> Manage Exports -> open the stock export
- Note the export ID from the URL (`id=...`)

Or via WP-CLI:

```bash
cd /var/www/html
wp db query "SELECT id, friendly_name FROM wp_pmxe_exports ORDER BY id;" --allow-root
```

### 2) Run the stock export from server side

```bash
cd /var/www/html
./scripts/run_wpae_export.sh --export-id <STOCK_EXPORT_ID> --iterations 180 --sleep-seconds 5 --allow-root
```

Notes:
- The script reads the WP All Export cron key from `PMXE_Plugin_Options` automatically.
- The script calls trigger once, then processing in short iterations.
- This is resilient to long-running job duration because each request is short.

### 3) (Recommended) Automate with system cron
Run trigger/processing regularly from cron instead of manual browser runs:

```cron
# Run stock export processing every 2 minutes
*/2 * * * * cd /var/www/html && /var/www/html/scripts/run_wpae_export.sh --export-id <STOCK_EXPORT_ID> --iterations 1 --sleep-seconds 0 --allow-root >> /var/log/wpae-stock-export.log 2>&1

# Trigger fresh export state once daily (optional)
10 3 * * * cd /var/www/html && /var/www/html/scripts/run_wpae_export.sh --export-id <STOCK_EXPORT_ID> --iterations 1 --sleep-seconds 0 --allow-root >> /var/log/wpae-stock-export.log 2>&1
```

If your export is configured as recurring in WP All Export, the frequent processing call is usually the key requirement.

## Operational Checks
- Confirm export output file timestamp updates after run.
- Confirm no recurring growth of duplicate `ultralove_export_sync_wp_recurring` events.
- If export still feels slow, reduce selected fields and avoid expensive custom PHP functions inside export templates.
