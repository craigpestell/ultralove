# Infrastructure Architecture Runbook

Last updated: 2026-04-18 (UTC)
Source of truth for this document:
- Live inspection on host: ubuntu-s-4vcpu-8gb-sfo2
- WordPress runtime and DB option introspection
- Existing backup and cron automation created in this workspace

## 1) Topology Overview

This environment is split across separate production and development droplets in DigitalOcean SFO2, with dedicated mail and database nodes in production.

### 1.1 Known / Inferred Droplets

| Role | Status | Identifier | Notes |
|---|---|---|---|
| Prod App | Confirmed | ultralove-prod-app | Droplet ID 565420744. This host. DO metadata hostname is ultralove-prod-app; system hostname is ubuntu-s-4vcpu-8gb-sfo2. Runs nginx + php-fpm + WordPress + Redis + backup scripts. |
| Prod DB | Confirmed | PRODUCTION-DB-ultralove.ca | Droplet ID 533903042. Private IP 10.120.0.4 matches WordPress DB_HOST. |
| Prod Mail | Confirmed | PRODUCTION-MAIL-mail.ultralove.ca | Droplet ID 242963159. Dedicated mail host. |
| Dev App | Confirmed | ultralove-dev-app | Droplet ID 565178171. Separate application node in sfo2. |
| Dev DB | Confirmed | ultralove-dev-db | Droplet ID 565178217. Separate database node in sfo2. |

### 1.2 DigitalOcean Droplet Inventory

| Name | ID | Region | Image | Status | Public IPv4 | Private IPv4 |
|---|---:|---|---|---|---|---|
| PRODUCTION-MAIL-mail.ultralove.ca | 242963159 | sfo2 | Ubuntu 20.04 (LTS) x64 | active | 138.197.193.88 | 10.120.0.3 |
| PRODUCTION-DB-ultralove.ca | 533903042 | sfo2 | Ubuntu MySQL on Ubuntu 24.04 | active | 142.93.94.167 | 10.120.0.4 |
| ultralove-dev-app | 565178171 | sfo2 | Ubuntu 24.04 (LTS) x64 | active | 138.68.49.58 | 10.120.0.2 |
| ultralove-dev-db | 565178217 | sfo2 | Ubuntu 24.04 (LTS) x64 | active | 206.189.160.105 | 10.120.0.5 |
| ultralove-prod-app | 565420744 | sfo2 | Ubuntu 24.04 (LTS) x64 | active | 178.128.187.1 | 10.120.0.7 |

### 1.3 Cross-Host OS Baseline (hostnamectl)

Snapshot captured: 2026-04-18 UTC

| Host | Static hostname | Operating system | Kernel | Architecture | Virtualization | Hardware vendor/model |
|---|---|---|---|---|---|---|
| Prod App (this host) | ubuntu-s-4vcpu-8gb-sfo2 | Ubuntu 24.04.4 LTS | Linux 6.8.0-110-generic | x86-64 | kvm | DigitalOcean Droplet |
| Dev App | ultralove-dev-app | Ubuntu 24.04.4 LTS | Linux 6.8.0-110-generic | x86-64 | kvm | DigitalOcean Droplet |
| Prod DB | PRODUCTION-DB-ultralove | Ubuntu 24.04.3 LTS | Linux 6.8.0-107-generic | x86-64 | kvm | DigitalOcean Droplet |
| Dev DB | ultralove-dev-db | Ubuntu 24.04.3 LTS | Linux 6.8.0-110-generic | x86-64 | kvm | DigitalOcean Droplet |

## 2) Prod App Droplet

### 2.1 OS / Platform

- Provider: DigitalOcean (KVM Droplet)
- DigitalOcean droplet name: ultralove-prod-app
- System hostname: ubuntu-s-4vcpu-8gb-sfo2
- Region: sfo2
- OS: Ubuntu 24.04.4 LTS (Noble)
- Kernel: 6.8.0-110-generic
- Architecture: x86_64
- Public IPv4: 178.128.187.1
- Private IPv4: 10.120.0.7

### 2.2 Core Services and Runtime

- nginx: 1.24.0 (service enabled + active)
- php-fpm: php8.3-fpm service enabled + active
- PHP CLI: 8.3.6
- Redis: 7.0.15 (enabled + active)
- certbot: installed (python3-certbot-nginx present)
- UFW: enabled + active

Systemd unit-file snapshot (captured 2026-04-18 UTC via `systemctl list-unit-files | rg -i 'nginx|php|mysql|mariadb|redis'`):

| Unit file | State | Preset |
|---|---|---|
| nginx.service | enabled | enabled |
| php8.3-fpm.service | enabled | enabled |
| phpsessionclean.service | static | - |
| redis-server.service | enabled | enabled |
| redis-server@.service | disabled | enabled |
| redis.service | alias | - |
| phpsessionclean.timer | enabled | enabled |

### 2.3 Network Exposure and Firewall (UFW)

Default policy:
- Incoming: deny
- Outgoing: allow
- Routed: disabled

Allowed inbound:
- 22/tcp (SSH)
- 80/tcp (HTTP)
- 443/tcp (HTTPS)

Observed listeners:
- nginx on 0.0.0.0:80 and 0.0.0.0:443
- sshd on 0.0.0.0:22
- redis on 127.0.0.1:6379 and ::1:6379 (not public)

### 2.4 Key Configuration Locations

- nginx global: /etc/nginx/nginx.conf
- nginx vhost: /etc/nginx/sites-available/ultralove.ca.conf
- nginx symlink: /etc/nginx/sites-enabled/ultralove.ca.conf
- php-fpm pool: /etc/php/8.3/fpm/pool.d/www.conf
- redis: /etc/redis/redis.conf
- wordpress config: /var/www/html/wp-config.php
- root crontab: /var/spool/cron/crontabs/root

### 2.5 Important Permissions Snapshot

- 644 root:root /etc/nginx/nginx.conf
- 644 root:root /etc/nginx/sites-available/ultralove.ca.conf
- 644 root:root /etc/php/8.3/fpm/pool.d/www.conf
- 640 redis:redis /etc/redis/redis.conf
- 644 www-data:www-data /var/www/html/wp-config.php
- 600 root:crontab /var/spool/cron/crontabs/root
- 700 root:root /root/updraft_spaces_sync.sh
- 700 root:root /root/monthly_system_backup.sh
- 600 root:root /root/.spaces_env

## 3) WordPress Application Layer

### 3.1 Core

- WP version: 6.9.4
- Active theme:
  - stylesheet: martfury-child
  - template: martfury

### 3.2 Database Connectivity

- DB_NAME: wordpress
- DB_HOST: 10.120.0.4 (private network endpoint)
- DISABLE_WP_CRON: true (system cron expected)

### 3.3 Active Plugins (from active_plugins option)

- admin-site-enhancements
- advanced-custom-fields-pro
- advanced-woo-labels
- am-collect-media
- back-in-stock-notifier-for-woocommerce
- cloudinary-image-management-and-manipulation-in-the-cloud-cdn
- contact-form-7
- custom-shipping-methods-for-woocommerce
- elementor
- gravityforms
- gravityformsuserregistration
- hide-price-until-login
- hide-updates
- kirki
- mailchimp-for-wp
- mailgun
- martfury-addons
- meta-box
- ni-woocommerce-custom-order-status
- product-import-export-for-woo
- quantity-field-on-shop-page-for-woocommerce
- redirection
- redis-cache
- relevanssi
- relevanssi-live-ajax-search
- revslider
- support-webp
- updraftplus
- user-role-editor
- user-switching
- widget-shortcode
- woo-add-gtin
- woo-update-manager
- woocommerce
- woocommerce-extra-product-sorting-options
- woocommerce-services
- woocommerce-xml-csv-product-import
- wp-all-export-pro
- wp-all-import-pro
- wp-useronline
- wpae-woocommerce-add-on
- wt-import-export-for-woo
- wt-import-export-for-woo-product
- wt-import-export-for-woo-product_review
- yith-woocommerce-wishlist

### 3.4 Custom / Site-Specific Code

- Custom plugin present: /var/www/html/wp-content/plugins/hide-price-until-login
- MU plugin (ManageWP worker loader): /var/www/html/wp-content/mu-plugins/0-worker.php
- MU plugin (session close helper): /var/www/html/wp-content/mu-plugins/00-close-php-session-for-rest.php
  - Closes active PHP sessions on init/admin_init/rest_api_init and before outbound HTTP requests.
  - Likely intended to reduce blocking/lock contention during REST/loopback calls.

## 4) Caching and Performance Architecture

### 4.1 WordPress Object Cache

- Redis constants set in wp-config.php:
  - WP_REDIS_HOST=127.0.0.1
  - WP_REDIS_PORT=6379
  - WP_REDIS_DATABASE=0
  - WP_REDIS_TIMEOUT=1
  - WP_REDIS_READ_TIMEOUT=1
  - WP_REDIS_MAXTTL=3600
- object-cache drop-in present:
  - /var/www/html/wp-content/object-cache.php
- redis-cache plugin is active.

### 4.2 PHP Runtime Cache

- opcache.enable=On
- opcache.memory_consumption=128
- opcache.validate_timestamps=On
- opcache.revalidate_freq=2

### 4.3 nginx Caching/Compression Notes

- gzip is enabled in /etc/nginx/nginx.conf
- No explicit fastcgi_cache/proxy_cache configuration detected in inspected nginx configs.
- Long expires policy detected for uploads/files PHP-blocking location in one alternate vhost file.

### 4.4 Elementor Generated CSS Caveat

- Elementor page-specific CSS is generated under `wp-content/uploads/elementor/css/`.
- A stale or missing generated file can make frontend styling appear inconsistent between guest and logged-in sessions, especially when caches/CDN or version-stripped asset URLs produce different cache keys.
- Symptom pattern observed on 2026-04-18: page rendered correct `elementor-element-*` classes, but custom Elementor button styling was missing for guests until the stale Elementor CSS cache was cleared.
- Verified remediation on 2026-04-18: `Elementor Plugin -> Tools -> Elementor Cache -> Clear Files & Data`, then purge any site/CDN caches that may still hold stale asset responses.
- Validate the fix against the actual generated asset response, not only the page HTML.

## 5) Cloudflare Setup

### 5.1 DNS / Edge Evidence

- NS for domain points to Cloudflare:
  - arya.ns.cloudflare.com
  - andy.ns.cloudflare.com
- HTTP response headers from https://ultralove.ca include:
  - server: cloudflare
  - cf-cache-status
  - cf-ray

### 5.2 Application-Level Cloudflare Integration

- No dedicated Cloudflare plugin confirmed as active.
- Cloudflare Turnstile integration present via plugin code in back-in-stock-notifier-for-woocommerce.
- WordPress option key indicating Cloudflare page cache plugin traces exists:
  - wp_cloudflare_page_cache_install
  - wp-cloudflare-page-cache_version

## 6) Cloudinary Setup

### 6.1 Plugin and Runtime Footprint

- Cloudinary plugin active:
  - cloudinary-image-management-and-manipulation-in-the-cloud-cdn
- Cloudinary-related option keys exist in WP DB, including:
  - cloudinary_connect
  - cloudinary_cloud_name
  - cloudinary_setup
  - cloudinary_sync_media
  - cloudinary_urls
  - cloudinary_status
- Custom option detected:
  - ultralove_enable_cloudinary_overrides

### 6.2 Interpretation

- Cloudinary integration is not just installed; it appears configured and used (based on option footprint).
- Secret values are intentionally not documented in plaintext here.

## 6.5 Mail Transport

### 6.5.1 WordPress Outbound Mail

- Mailgun plugin is active:
  - mailgun/mailgun.php
- Installed plugin package identifies itself as:
  - Mailgun for WordPress
  - Stable tag 2.1.10
- The plugin supports sending via Mailgun HTTP API or SMTP.
- Based on the site stack, Mailgun should be treated as the application-level outbound mail transport for WordPress emails.

### 6.5.2 Architecture Interpretation

- WordPress transactional/application mail is routed through Mailgun.
- This is separate from the dedicated production mail droplet `PRODUCTION-MAIL-mail.ultralove.ca`, which likely serves mailbox/MTA or other mail infrastructure responsibilities outside WordPress plugin-driven outbound mail.
- Exact Mailgun mode and credentials are intentionally not recorded in plaintext here.

## 7) Backups and Retention Architecture

### 7.1 Updraft + Spaces Backup Flow

- Source directory: /var/www/html/wp-content/updraft
- Sync script: /root/updraft_spaces_sync.sh
- Credentials file: /root/.spaces_env (600)
- Destination pattern: s3://ultralove-web-backups/updraft/YYYY-MM-DD/
- Retention policy in script:
  - Keep all sets for last 3 days
  - Keep newest set per ISO week for days 4-33
  - Delete older redundant remote files

### 7.2 Monthly System Configuration Backups

- Script: /root/monthly_system_backup.sh
- Local output: /root/system_monthly_backups/YYYY-MM/
  - system-config-YYYY-MM.tar.gz
  - system-config-YYYY-MM.sha256
  - inventory-YYYY-MM.txt
- Spaces upload destination:
  - s3://ultralove-web-backups/system-config/YYYY-MM/
- Scope includes:
  - nginx, php, letsencrypt, cron configs, systemd overrides
  - root backup scripts and env file
  - wp-config.php

### 7.3 Cron Schedule (root)

- Daily Updraft sync (currently DRY_RUN=1):
  - 20 3 * * * bash -lc "source /root/.spaces_env; DRY_RUN=1 /root/updraft_spaces_sync.sh >> /var/log/updraft_spaces_sync.log 2>&1"
- Monthly system backup:
  - 40 2 1 * * /root/monthly_system_backup.sh >> /var/log/monthly_system_backup.log 2>&1

## 8) Open Items To Complete “Entire Architecture”

The following still cannot be fully verified from this host alone and should be completed from direct access or per-host inspection:

- Dev App droplet
  - partial verification (2026-04-18 UTC): hostnamectl + /etc/os-release confirm Ubuntu 24.04.4 LTS and kernel 6.8.0-110-generic
  - partial verification (2026-04-18 UTC): ufw status is inactive
  - partial verification (2026-04-18 UTC): `systemctl list-unit-files | rg -i 'nginx|php|mysql|mariadb|redis'` shows nginx.service and php8.3-fpm.service enabled; phpsessionclean.service static; phpsessionclean.timer enabled; no mysql/mariadb/redis unit file in this filtered list
  - partial verification (2026-04-18 UTC): WordPress runtime check returns WP 6.9.4; active plugin set is near-parity with prod but `redis-cache/redis-cache.php` is present on prod-app and absent on dev-app
  - nginx/php config paths
  - theme and broader app parity vs prod (beyond plugin list)
- Dev DB droplet
  - partial verification (2026-04-18 UTC): hostnamectl + /etc/os-release confirm Ubuntu 24.04.3 LTS and kernel 6.8.0-110-generic
  - partial verification (2026-04-18 UTC): ufw active; default deny incoming/allow outgoing; allows 22/tcp and 3306/tcp from 10.0.0.0/8
  - partial verification (2026-04-18 UTC): `systemctl list-unit-files | rg -i 'nginx|php|mysql|mariadb|redis'` shows mariadb.service enabled; mariadb@.service disabled; mysql.service and mysqld.service aliases; mariadb socket units static
  - bind/addressing
  - backup and restore process
- Prod DB droplet (PRODUCTION-DB-ultralove.ca / 10.120.0.4)
  - partial verification (2026-04-18 UTC): hostnamectl + /etc/os-release confirm Ubuntu 24.04.3 LTS and kernel 6.8.0-107-generic
  - partial verification (2026-04-18 UTC): systemctl list-unit-files filtered for nginx/php/mysql/mariadb/redis shows mysql.service enabled; phpsessionclean.service static; phpsessionclean.timer enabled
  - partial verification gap (2026-04-18 UTC): ufw status check failed under non-root SSH user (`craig`), so firewall state remains unverified here
  - engine process/service status
  - backup cadence and retention
  - replication or HA status (if any)
- Prod Mail droplet (PRODUCTION-MAIL-mail.ultralove.ca / 10.120.0.3)
  - data collection attempt (2026-04-18 UTC) failed: `root@138.197.193.88` returned `Permission denied (publickey,password)`
  - establish valid SSH principal/key for this host
  - mail stack (Postfix/Dovecot/etc.)
  - DNS/mail routing responsibilities
  - relationship to Mailgun for outbound application mail vs server mail
  - backup and retention process
- Cloudflare account settings
  - SSL/TLS mode, WAF rules, cache rules, page rules/transform rules, bot settings
- Cloudinary account settings
  - cloud name/account id, transformation presets, delivery mode, quota/usage alerts

## 9) Recommended Next Data Collection Commands

Run these from an operator workstation with appropriate access:

- DigitalOcean droplet inventory:
  - doctl compute droplet list --format Name,ID,Region,Image,Status,PublicIPv4,PrivateIPv4
- Per-droplet config snapshots:
  - hostnamectl
  - cat /etc/os-release
  - ufw status verbose
  - systemctl list-unit-files | rg -i 'nginx|php|mysql|mariadb|redis'
- WordPress runtime parity checks (each app node):
  - php -r 'chdir("/var/www/html"); require "wp-load.php"; echo get_bloginfo("version"),"\n";'
  - php -r 'chdir("/var/www/html"); require "wp-load.php"; print_r(get_option("active_plugins",[]));'

## 10) Appendix: 2026-04-18 Multi-Host Collection Snapshot

Collection bundle:
- /root/architecture-collection-20260418_040102/

Collection status summary:

| Role | Target | Status |
|---|---|---|
| prod-app | root@178.128.187.1 | OK |
| dev-app | root@138.68.49.58 | OK |
| prod-db | craig@142.93.94.167 | OK |
| dev-db | root@206.189.160.105 | OK |
| prod-mail | root@138.197.193.88 | FAILED |

### 10.1 prod-app

- hostnamectl: ubuntu-s-4vcpu-8gb-sfo2
- OS/kernel: Ubuntu 24.04.4 LTS, Linux 6.8.0-110-generic
- UFW: active (default deny incoming, allow outgoing); allows 22/tcp, 80/tcp, 443/tcp (+v6)
- service filter result: nginx + php8.3-fpm enabled; redis-server enabled; phpsessionclean.timer enabled
- WordPress check: version 6.9.4; active plugin list captured in full in `prod-app.log`

### 10.2 dev-app

- hostnamectl: ultralove-dev-app
- OS/kernel: Ubuntu 24.04.4 LTS, Linux 6.8.0-110-generic
- UFW: inactive
- service filter result: nginx + php8.3-fpm enabled; phpsessionclean.timer enabled; no mysql/mariadb/redis in filtered unit output
- WordPress check: version 6.9.4; plugin list near parity with prod-app, with `redis-cache/redis-cache.php` present on prod-app and absent on dev-app in this snapshot

### 10.3 prod-db

- hostnamectl: PRODUCTION-DB-ultralove
- OS/kernel: Ubuntu 24.04.3 LTS, Linux 6.8.0-107-generic
- UFW: command failed under non-root user (`ERROR: You need to be root to run this script`)
- service filter result: mysql.service enabled; phpsessionclean.service static; phpsessionclean.timer enabled

### 10.4 dev-db

- hostnamectl: ultralove-dev-db
- OS/kernel: Ubuntu 24.04.3 LTS, Linux 6.8.0-110-generic
- UFW: active (default deny incoming, allow outgoing); allows 22/tcp and 3306/tcp from 10.0.0.0/8
- service filter result: mariadb.service enabled; mariadb@.service disabled; mysql/mysqld aliases; mariadb socket units static

### 10.5 prod-mail

- collection failure: SSH authentication failed for root
- error: `root@138.197.193.88: Permission denied (publickey,password).`
- action needed: use correct SSH user/key and rerun collector for this host

## 11) Plain-Language Summary Of Work Completed

Use this section when explaining the project to non-technical stakeholders.

### 11.1 One-Page Summary

I migrated the website stack to new, lower-cost DigitalOcean droplets while keeping production and development separated and stable.

I tuned the server stack (nginx, PHP-FPM, Redis, and WordPress caching settings) so resource usage stays within acceptable limits.

I improved cacheability at the application layer by using custom WordPress/theme/plugin work for parts of the site that can otherwise break caching behavior.

I integrated Cloudflare at the DNS/edge layer and confirmed it is actively serving traffic.

I integrated Cloudinary for media delivery and enabled site-level Cloudinary behavior in WordPress.

I also added operational safeguards so the system is maintainable and recoverable, not just fast.

### 11.2 What Else Was Done (Beyond Performance Tuning)

- Automated backup replication to DigitalOcean Spaces for Updraft backups with retention rules (daily detailed copies, then weekly rollups for older backups).
- Added monthly system configuration backups (including nginx, PHP, cron, systemd, and WordPress config) with checksum files for integrity verification.
- Scheduled recurring automation in root cron for backup/sync workflows.
- Added repeatable monitoring for PHP-FPM and Redis behavior over time to validate tuning decisions with measured data.
- Captured a multi-host architecture snapshot process so production/dev app and database nodes can be audited consistently.
- Kept production Redis non-public (loopback only) and maintained restrictive firewall defaults on production app.
- Documented current architecture, open verification gaps, and next data-collection steps so future handoff work is faster.

### 11.3 Short Client-Friendly Version

I moved the site to more cost-effective infrastructure, tuned performance and caching (Cloudflare + Redis + server settings), and made targeted WordPress customizations to improve page cacheability. I also set up automated backups, retention, and monitoring so the environment is easier to operate and recover.

---

If you want this runbook split into separate docs (Network, App, Security, Backups, Integrations) for handoff to ops/dev teams, use this file as the canonical source and generate role-specific extracts.
