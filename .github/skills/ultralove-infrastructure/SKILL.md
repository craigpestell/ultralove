---
name: ultralove-infrastructure
description: 'Operate and troubleshoot the UltraLove WordPress production or development infrastructure: PHP 8.3 FPM, Nginx, Redis, Cloudflare, DigitalOcean droplets, managed MySQL, backups, WordPress CLI, performance, 502/504 errors, and deployment validation. Use when changing or diagnosing server, PHP-FPM, Nginx, cache, cron, WordPress runtime, DigitalOcean, or backup behavior for this workspace.'
argument-hint: 'Describe the infrastructure change, symptom, or host to investigate'
---

# UltraLove Infrastructure Operations

Use this skill for targeted operations on this WordPress/WooCommerce environment. Treat production as live: identify the host and request path first, make the smallest reversible change, validate it, and preserve evidence in the relevant documentation when the architecture changes.

## Environment Map

- Production app: `ultralove-prod-app` in DigitalOcean `sfo2`; WordPress root is `/var/www/html`.
- Production services: `nginx`, `php8.3-fpm`, and `redis-server`.
- Nginx vhost: `/etc/nginx/sites-available/ultralove.ca.conf`.
- PHP-FPM pool: `/etc/php/8.3/fpm/pool.d/www.conf`.
- WordPress configuration: `/var/www/html/wp-config.php`.
- Development and production are separate DigitalOcean droplets. Do not assume service or plugin parity.
- The current database endpoint must be obtained from the active WordPress configuration or runtime. Do not rely on a historical private-IP record.

For fuller topology, service locations, backup design, and known verification gaps, read [the architecture runbook](../../../documentation/ARCHITECTURE.md).

## Safety Rules

1. Confirm the target environment before changing anything. Production app, production DB, development app, development DB, and mail each have separate responsibilities.
2. Never print, commit, or copy credentials, API tokens, salts, private keys, or unredacted configuration files. Use filtered output and describe values by presence only.
3. Validate configuration before reload: `nginx -t` before `systemctl reload nginx`; `php-fpm8.3 -t` before `systemctl reload php8.3-fpm` when PHP-FPM configuration changes.
4. Prefer `reload` over `restart` for Nginx. Restart PHP-FPM only when required, and check service health immediately afterward.
5. Do not change managed database, firewall, DNS, Cloudflare, DigitalOcean, or backup-retention settings based on this app host alone. Confirm the target resource and current state first.
6. Keep WordPress changes in the child theme, a custom plugin, or an MU plugin. Do not edit WordPress core or vendor plugins unless the task explicitly requires it and the update-overwrite risk is documented.
7. Do not run destructive database, cache, backup, object-storage, or process commands without a scoped dry run or an explicit backup/recovery path.

## First Response: Web Errors Or Slowness

Start with read-only checks, then choose the owning layer. Do not change all services at once.

```bash
systemctl is-active nginx php8.3-fpm redis-server
systemctl --no-pager --full status nginx php8.3-fpm redis-server
journalctl -u nginx -u php8.3-fpm --since '30 minutes ago' --no-pager
tail -n 200 /var/log/nginx/error.log
free -h
df -h /
```

For a specific failing URL, compare a public request with an origin-local request when safe, record status, response time, cache headers, and correlation/request IDs. Inspect Nginx error logs and PHP-FPM journal entries around that exact time.

### Decision Guide

- Nginx has an upstream error, timeout, or connection refusal: inspect PHP-FPM status, pool configuration, saturation, and PHP errors.
- PHP-FPM is healthy but the request is slow: isolate WordPress/plugin/database calls, object-cache behavior, and external HTTP/API calls.
- Anonymous and authenticated output differ: inspect WordPress cacheability, Elementor generated CSS, Redis object cache, Cloudflare cache rules, and browser/CDN cache state.
- Browser receives a Cloudflare error: retain the `cf-ray` and timestamp, then determine whether the origin returned an error before changing Cloudflare settings.

## Safe Change Procedure

1. Capture the relevant current state and error evidence.
2. Check a configuration file before editing it. Back up an OS configuration file with a timestamped root-only copy if the change is not already controlled elsewhere.
3. Apply one scoped change.
4. Run the service-specific syntax or lint check.
5. Reload only the owning service.
6. Confirm active status, review fresh logs, and exercise the affected URL or WordPress path.
7. For user-facing cache or asset changes, verify guest behavior as well as logged-in behavior and purge only the cache layers necessary for the changed response.
8. Record durable infrastructure changes in `documentation/ARCHITECTURE.md` without secrets.

## Nginx And PHP-FPM Validation

```bash
nginx -t && systemctl reload nginx
php-fpm8.3 -t && systemctl reload php8.3-fpm
systemctl is-active nginx php8.3-fpm
journalctl -u nginx -u php8.3-fpm --since '10 minutes ago' --no-pager
```

Check the Nginx vhost before changing FastCGI parameters, timeouts, redirects, or caching. There is no known explicit `fastcgi_cache` or `proxy_cache` configuration, so do not infer that page responses are origin-cached by Nginx.

When adjusting PHP-FPM capacity, collect current process counts, memory pressure, request backlog indicators, and error-log evidence. Set pool values from observed workload and available memory, not generic defaults.

## WordPress And WooCommerce Checks

Run WordPress CLI from `/var/www/html` with `--allow-root` only when operating as root. Use targeted queries; do not dump options because they may contain secrets.

```bash
wp core version --allow-root
wp theme list --status=active --allow-root
wp plugin list --status=active --allow-root
wp cache type --allow-root
wp cron event list --allow-root --fields=hook,next_run_relative --format=table
php -l wp-content/themes/martfury-child/functions.php
```

- The active theme is `martfury-child` with parent `martfury`.
- `DISABLE_WP_CRON` is enabled, so investigate system cron before assuming WordPress scheduled tasks execute on web requests.
- Redis is local and the Redis object-cache drop-in is present. Verify object-cache changes without flushing production cache broadly.
- Elementor CSS is generated under `wp-content/uploads/elementor/css/`; stale CSS can affect guest rendering. Verify actual generated asset responses before clearing Elementor and CDN caches.
- Slider Revolution contains an intentional Cloudinary optimization customization in vendor code. Treat plugin updates as a potential overwrite event and validate rendered image URLs after updates.

## DigitalOcean, Cloudflare, And Database Boundaries

- Use `doctl` inventory output to confirm droplet ID, region, and IP before a DigitalOcean action.
- Cloudflare fronts public traffic. Capture cache status and `cf-ray` when diagnosing public failures; do not purge or alter WAF/cache rules without a measured reason.
- The production database is not local to the app. Test connectivity through WordPress or a credential-safe client invocation; never expose its connection string, username, or password in logs or commits.
- Redis is loopback-only on production. Do not widen its network listener or firewall access without a reviewed security change.
- Production firewall defaults to deny inbound and permits only SSH, HTTP, and HTTPS. Confirm current UFW rules before any firewall modification.

## Backups, Cron, And Recovery

- Updraft backup source: `/var/www/html/wp-content/updraft`.
- Remote sync: `/root/updraft_spaces_sync.sh`; its environment file is root-only and must never be displayed.
- System configuration backup: `/root/monthly_system_backup.sh`.
- WordPress cron is disabled and backup jobs are scheduled through root cron.

Before any retention or remote deletion change, run the script in dry-run mode, inspect the exact candidate object list, then perform an explicit post-run count and date check. Preserve the newest backup set per required retention interval; never delete merely because a prefix appears redundant.

## Completion Evidence

Report the target environment, changed files/services, syntax/lint results, service state, targeted endpoint or WordPress behavior checked, relevant cache actions, and remaining risks. Do not include secrets in the report.