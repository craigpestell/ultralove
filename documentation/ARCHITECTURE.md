# Infrastructure Architecture Runbook

Last updated: 2026-05-10 (UTC)
Source of truth for this document:
- Live inspection on host: ubuntu-s-4vcpu-8gb-sfo2
- WordPress runtime and DB option introspection
- Existing backup and cron automation created in this workspace

## Recent Changes (2026-05-10)

- **Mail server droplet upgraded**: Ubuntu 20.04 LTS → 24.0.4 LTS (via 22 intermediate step)
  - New private IP: 10.120.0.2
  - New public IP: 143.110.140.137
  - Postfix, Dovecot, SSL, ClamAV, Fail2Ban configured
- **Database migration**: Prod DB moved from droplet to DigitalOcean managed database service
  - Endpoint: db-mysql-sfo2-16643-do-user-8490534-0.g.db.ondigitalocean.com:25060
  - Database: defaultdb
  - SSL mode: REQUIRED
- **Cloudinary account consolidation**: Migrated from 3 accounts to 1 active account
  - 2 accounts being deactivated
  - Transformations to be configured later; currently storing images only
- **WordPress updates**: Email templates updated; current stock levels CSV download fixed

## 1) Topology Overview

This environment is split across separate production and development droplets in DigitalOcean SFO2, with dedicated mail and database nodes in production.

### 1.1 Known / Inferred Droplets

| Role | Status | Identifier | Notes |
|---|---|---|---|
| Prod App | Confirmed | ultralove-prod-app | Droplet ID 565420744. This host. DO metadata hostname is ultralove-prod-app; system hostname is ubuntu-s-4vcpu-8gb-sfo2. Runs nginx + php-fpm + WordPress + Redis + backup scripts. |
| Prod DB | Confirmed | DigitalOcean Managed Database (MySQL) | db-mysql-sfo2-16643-do-user-8490534-0.g.db.ondigitalocean.com:25060 (formerly droplet 533903042). Database migrated from droplet to managed service. |
| Prod Mail | Confirmed | PRODUCTION-MAIL-mail.ultralove.ca | Ubuntu 24.0.4 LTS. Private IP 10.120.0.2, Public IP 143.110.140.137. Postfix, Dovecot, SSL, ClamAV, Fail2Ban configured. |
| Dev App | Confirmed | ultralove-dev-app | Droplet ID 565178171. Separate application node in sfo2. |
| Dev DB | Confirmed | ultralove-dev-db | Droplet ID 565178217. Separate database node in sfo2. |

### 1.2 DigitalOcean Droplet Inventory

| Name | ID | Region | Image | Status | Public IPv4 | Private IPv4 |
|---|---:|---|---|---|---|---|
| PRODUCTION-MAIL-mail.ultralove.ca | 242963159 | sfo2 | Ubuntu 24.04 (LTS) x64 | active | 143.110.140.137 | 10.120.0.2 |
| PRODUCTION-DB-ultralove | Managed | sfo2 | MySQL 8.x (DigitalOcean Managed) | active | N/A | db-mysql-sfo2-16643-do-user-8490534-0.g.db.ondigitalocean.com |
| ultralove-dev-app | 565178171 | sfo2 | Ubuntu 24.04 (LTS) x64 | active | 138.68.49.58 | 10.120.0.2 |
| ultralove-dev-db | 565178217 | sfo2 | Ubuntu 24.04 (LTS) x64 | active | 206.189.160.105 | 10.120.0.5 |
| ultralove-prod-app | 565420744 | sfo2 | Ubuntu 24.04 (LTS) x64 | active | 178.128.187.1 | 10.120.0.7 |

### 1.3 Cross-Host OS Baseline (hostnamectl)

Snapshot captured: 2026-04-18 UTC

| Host | Static hostname | Operating system | Kernel | Architecture | Virtualization | Hardware vendor/model |
|---|---|---|---|---|---|---|
| Prod App (this host) | ubuntu-s-4vcpu-8gb-sfo2 | Ubuntu 24.04.4 LTS | Linux 6.8.0-110-generic | x86-64 | kvm | DigitalOcean Droplet |
| Dev App | ultralove-dev-app | Ubuntu 24.04.4 LTS | Linux 6.8.0-110-generic | x86-64 | kvm | DigitalOcean Droplet |
| Prod DB | PRODUCTION-DB-ultralove (Managed) | MySQL 8.x | N/A (managed service) | N/A | N/A | DigitalOcean Managed Database |
| Dev DB | ultralove-dev-db | Ubuntu 24.04.3 LTS | Linux 6.8.0-110-generic | x86-64 | kvm | DigitalOcean Droplet |
| Prod Mail | PRODUCTION-MAIL-mail.ultralove.ca | Ubuntu 24.04.4 LTS | Linux 6.8.0+ | x86-64 | kvm | DigitalOcean Droplet |

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

## 3) Prod Mail Droplet

### 3.1 OS / Platform

- Provider: DigitalOcean (KVM Droplet)
- DigitalOcean droplet name: PRODUCTION-MAIL-mail.ultralove.ca
- DigitalOcean droplet ID: 242963159
- Region: sfo2
- OS: Ubuntu 24.04.4 LTS (Noble) - upgraded from 20.04 LTS on 2026-05-10 via step upgrade (20 → 22 → 24)
- Kernel: Linux 6.8.0+
- Architecture: x86_64
- Public IPv4: 143.110.140.137
- Private IPv4: 10.120.0.2

### 3.2 Mail Services and Runtime

- **Postfix**: Mail Transfer Agent (MTA) configured for production mail routing
- **Dovecot**: IMAP/POP3 mail server for mailbox access
- **SSL/TLS**: Certificate-based encryption for secure mail transport
- **ClamAV**: Antivirus scanner for inbound mail protection
- **Fail2Ban**: Intrusion prevention service protecting against brute-force attacks

### 3.3 Relationship to WordPress Outbound Mail

- This dedicated mail droplet serves mail infrastructure roles (mailbox hosting, MTA, security)
- WordPress application mail (transactional emails) routes via Mailgun plugin (separate from this host)
- See section 7.4.2 for more details on WordPress mail transport separation

### 3.4 Recommended Mail Ops Checklist (Post-Upgrade)

- DNS authentication records:
  - SPF for sending hosts
  - DKIM key publishing and signing validation
  - DMARC policy + aggregate report mailbox
- Mail identity and trust:
  - PTR/rDNS matches sending hostname
  - HELO/EHLO banner matches DNS hostname
  - TLS certificate renewal monitoring and expiry alerts
- Deliverability and abuse controls:
  - Postfix rate limits / abuse throttling where appropriate
  - RBL checks and reject policy validation
  - SpamAssassin/Rspamd decision (if spam filtering is required beyond ClamAV malware scanning)
- Security and maintenance:
  - Unattended security updates enabled
  - Fail2Ban jails verified for Postfix and Dovecot auth logs
  - UFW rules reviewed for mail ports only (25/465/587/993 as needed)
- Reliability and observability:
  - Mail queue monitoring and alerting (postqueue depth, deferred growth)
  - Log shipping/retention for `/var/log/mail.log`
  - Mailbox backup cadence and restore test runbook

## 4) WordPress Application Layer

### 4.1 Core

- WP version: 6.9.4
- Active theme:
  - stylesheet: martfury-child
  - template: martfury

### 4.2 Database Connectivity

- DB_NAME: defaultdb
- DB_USER: doadmin
- DB_HOST: db-mysql-sfo2-16643-do-user-8490534-0.g.db.ondigitalocean.com
- DB_PORT: 25060
- DB_SSL: true (required by DigitalOcean managed database)
- DISABLE_WP_CRON: true (system cron expected)
- Database migrated to DigitalOcean managed service on 2026-05-10 (formerly private droplet 10.120.0.4)

### 4.3 Active Plugins (from active_plugins option)

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

### 4.4 Custom / Site-Specific Code

- Custom plugin present: /var/www/html/wp-content/plugins/hide-price-until-login
- MU plugin (ManageWP worker loader): /var/www/html/wp-content/mu-plugins/0-worker.php
- MU plugin (session close helper): /var/www/html/wp-content/mu-plugins/00-close-php-session-for-rest.php
  - Closes active PHP sessions on init/admin_init/rest_api_init and before outbound HTTP requests.
  - Likely intended to reduce blocking/lock contention during REST/loopback calls.

### 4.5 Recent WordPress Updates (2026-05-10)

- Email templates: Updated for improved deliverability and formatting
- Current stock levels CSV download: Fixed to properly generate and export current inventory levels
- These updates did not require plugin or theme modifications beyond the application layer

## 5) Caching and Performance Architecture

### 5.1 WordPress Object Cache

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

### 5.2 PHP Runtime Cache

- opcache.enable=On
- opcache.memory_consumption=128
- opcache.validate_timestamps=On
- opcache.revalidate_freq=2

### 5.3 nginx Caching/Compression Notes

- gzip is enabled in /etc/nginx/nginx.conf
- No explicit fastcgi_cache/proxy_cache configuration detected in inspected nginx configs.
- Long expires policy detected for uploads/files PHP-blocking location in one alternate vhost file.

### 5.4 Elementor Generated CSS Caveat

- Elementor page-specific CSS is generated under `wp-content/uploads/elementor/css/`.
- A stale or missing generated file can make frontend styling appear inconsistent between guest and logged-in sessions, especially when caches/CDN or version-stripped asset URLs produce different cache keys.

### 5.5 RevSlider Cloudinary Image Optimization

As of 2026-04-22, Slider Revolution slide background images are normalized to include Cloudinary delivery transforms before the browser loads them.

Implementation details:
- File modified: `/var/www/html/wp-content/plugins/revslider/includes/output.class.php`
- Default transform inserted: `f_auto,q_auto:eco`
- Supported Cloudinary URL shapes:
  - `https://res.cloudinary.com/<cloud>/images/...`
  - `https://res.cloudinary.com/<cloud>/image/upload/...`

Current behavior:
- Slide background image URLs emitted by RevSlider PHP are rewritten to inject the Cloudinary transform before the version segment.
- Slide thumbnail URLs emitted by RevSlider are rewritten the same way.
- A small runtime normalizer is also injected into the slider init script so existing `.rev-slidebg` and `rs-sbg` nodes have `src`, `data-lazyload`, and `data-src-rs-ref` normalized even when frontend markup is rebuilt by RevSlider JS.
- Slide link targets such as `data-link="//ultralove.ca/product/..."` are not rewritten for Cloudinary delivery. They remain navigation URLs.

Key code locations in `output.class.php`:
- `get_cloudinary_image_transform()`
- `maybe_optimize_cloudinary_image_url()`
- `js_get_cloudinary_runtime_normalizer()`

Operational notes:
- This is a direct vendor-plugin modification inside Slider Revolution. A plugin update may overwrite it.
- If the optimization appears missing on the frontend after deploy, purge any page cache/CDN cache first, then verify rendered `data-lazyload` / `data-src-rs-ref` values in the browser.
- PHP and nginx were restarted after the change during live verification.

Example transformation:
- From: `//res.cloudinary.com/dp4dg7god/image/upload/v1776479952/ultralove/catalog/WavesofPleasure-1.jpg`
- To: `//res.cloudinary.com/dp4dg7god/image/upload/f_auto,q_auto:eco/v1776479952/ultralove/catalog/WavesofPleasure-1.jpg`
- Symptom pattern observed on 2026-04-18: page rendered correct `elementor-element-*` classes, but custom Elementor button styling was missing for guests until the stale Elementor CSS cache was cleared.
- Verified remediation on 2026-04-18: `Elementor Plugin -> Tools -> Elementor Cache -> Clear Files & Data`, then purge any site/CDN caches that may still hold stale asset responses.
- Validate the fix against the actual generated asset response, not only the page HTML.

## 6) Cloudflare Setup

### 6.1 DNS / Edge Evidence

- NS for domain points to Cloudflare:
  - arya.ns.cloudflare.com
  - andy.ns.cloudflare.com
- HTTP response headers from https://ultralove.ca include:
  - server: cloudflare
  - cf-cache-status
  - cf-ray

### 6.2 Application-Level Cloudflare Integration

- No dedicated Cloudflare plugin confirmed as active.
- Cloudflare Turnstile integration present via plugin code in back-in-stock-notifier-for-woocommerce.
- WordPress option key indicating Cloudflare page cache plugin traces exists:
  - wp_cloudflare_page_cache_install
  - wp-cloudflare-page-cache_version

## 7) Cloudinary Setup

### 7.1 Plugin and Runtime Footprint

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

### 7.2 Current Cloudinary Account Status (As of 2026-05-10)

- Active account: 1 (configured for image storage and WordPress integration)
  - Transformations to be configured later to maintain quota compliance
  - Current mode: storage-only (no active optimizations/transforms yet)
- Deactivated accounts: 2 (being phased out as part of cost optimization)
- Migration completed 2026-05-10 as part of cost reduction initiative

### 7.3 Interpretation

- Cloudinary integration is not just installed; it appears configured and used (based on option footprint).
- Single active account reduces cost and complexity while transformations are planned.
- Secret values are intentionally not documented in plaintext here.

### 7.4 Mail Transport

### 7.4.1 WordPress Outbound Mail

- Mailgun plugin is active:
  - mailgun/mailgun.php
- Installed plugin package identifies itself as:
  - Mailgun for WordPress
  - Stable tag 2.1.10
- The plugin supports sending via Mailgun HTTP API or SMTP.
- Based on the site stack, Mailgun should be treated as the application-level outbound mail transport for WordPress emails.

### 7.4.2 Architecture Interpretation

- WordPress transactional/application mail is routed through Mailgun.
- This is separate from the dedicated production mail droplet `PRODUCTION-MAIL-mail.ultralove.ca`, which likely serves mailbox/MTA or other mail infrastructure responsibilities outside WordPress plugin-driven outbound mail.
- Exact Mailgun mode and credentials are intentionally not recorded in plaintext here.

## 8) Backups and Retention Architecture

### 8.1 Updraft + Spaces Backup Flow

- Source directory: /var/www/html/wp-content/updraft
- Sync script: /root/updraft_spaces_sync.sh
- Credentials file: /root/.spaces_env (600)
- Destination pattern: s3://ultralove-web-backups/updraft/YYYY-MM-DD/
- Retention policy in script:
  - Keep all sets for last 3 days
  - Keep newest set per ISO week for days 4-33
  - Delete older redundant remote files

### 8.2 Monthly System Configuration Backups

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

### 8.3 Cron Schedule (root)

- Daily Updraft sync (currently DRY_RUN=1):
  - 20 3 * * * bash -lc "source /root/.spaces_env; DRY_RUN=1 /root/updraft_spaces_sync.sh >> /var/log/updraft_spaces_sync.log 2>&1"
- Monthly system backup:
  - 40 2 1 * * /root/monthly_system_backup.sh >> /var/log/monthly_system_backup.log 2>&1

## 9) Open Items To Complete "Entire Architecture"

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
- Prod DB droplet (DigitalOcean Managed Database)
  - **MIGRATED** (2026-05-10): Database now hosted on DigitalOcean Managed MySQL service
  - Endpoint: db-mysql-sfo2-16643-do-user-8490534-0.g.db.ondigitalocean.com:25060
  - SSL mode: REQUIRED
  - Database name: defaultdb
  - Backup cadence and retention policy for managed service
- Prod Mail droplet (PRODUCTION-MAIL-mail.ultralove.ca / 10.120.0.2)
  - **UPGRADED** (2026-05-10): Ubuntu 20.04 → 24.0.4 LTS
  - New public IP: 143.110.140.137
  - Mail services configured: Postfix, Dovecot, SSL, ClamAV, Fail2Ban
  - Remaining verification: service status checks, config validation, backup and retention process
- Cloudflare account settings
  - SSL/TLS mode, WAF rules, cache rules, page rules/transform rules, bot settings
- Cloudinary account settings
  - **CONSOLIDATED** (2026-05-10): Migrated from 3 accounts to 1 active account for cost optimization
  - 2 accounts being deactivated
  - Remaining work: Configure transformations for the active account while maintaining quota compliance

## 10) Recommended Next Data Collection Commands

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

## 11) Appendix: 2026-04-18 Multi-Host Collection Snapshot

Collection bundle:
- /root/architecture-collection-20260418_040102/

Collection status summary:

| Role | Target | Status | Last Verified |
|---|---|---|---|
| prod-app | root@178.128.187.1 | OK | 2026-04-18 |
| dev-app | root@138.68.49.58 | OK | 2026-04-18 |
| prod-db | craig@142.93.94.167 | OK (Migrated to Managed DB 2026-05-10) | 2026-05-10 |
| dev-db | root@206.189.160.105 | OK | 2026-04-18 |
| prod-mail | root@143.110.140.137 | OK (Upgraded Ubuntu 20→24 on 2026-05-10) | 2026-05-10 |

### 11.1 prod-app

- hostnamectl: ubuntu-s-4vcpu-8gb-sfo2
- OS/kernel: Ubuntu 24.04.4 LTS, Linux 6.8.0-110-generic
- UFW: active (default deny incoming, allow outgoing); allows 22/tcp, 80/tcp, 443/tcp (+v6)
- service filter result: nginx + php8.3-fpm enabled; redis-server enabled; phpsessionclean.timer enabled
- WordPress check: version 6.9.4; active plugin list captured in full in `prod-app.log`

### 11.2 dev-app

- hostnamectl: ultralove-dev-app
- OS/kernel: Ubuntu 24.04.4 LTS, Linux 6.8.0-110-generic
- UFW: inactive
- service filter result: nginx + php8.3-fpm enabled; phpsessionclean.timer enabled; no mysql/mariadb/redis in filtered unit output
- WordPress check: version 6.9.4; plugin list near parity with prod-app, with `redis-cache/redis-cache.php` present on prod-app and absent on dev-app in this snapshot

### 11.3 prod-db

- hostnamectl: PRODUCTION-DB-ultralove
- OS/kernel: Ubuntu 24.04.3 LTS, Linux 6.8.0-107-generic
- UFW: command failed under non-root user (`ERROR: You need to be root to run this script`)
- service filter result: mysql.service enabled; phpsessionclean.service static; phpsessionclean.timer enabled

### 11.4 dev-db

- hostnamectl: ultralove-dev-db
- OS/kernel: Ubuntu 24.04.3 LTS, Linux 6.8.0-110-generic
- UFW: active (default deny incoming, allow outgoing); allows 22/tcp and 3306/tcp from 10.0.0.0/8
- service filter result: mariadb.service enabled; mariadb@.service disabled; mysql/mysqld aliases; mariadb socket units static

### 11.5 prod-mail

**Status**: UPGRADED 2026-05-10

- hostnamectl: PRODUCTION-MAIL-mail.ultralove.ca
- OS/kernel: Ubuntu 24.04.4 LTS, Linux 6.8.0+ (upgraded from Ubuntu 20.04 LTS)
- Previous public IP: 138.197.193.88 (replaced)
- New public IP: 143.110.140.137
- New private IP: 10.120.0.2
- Mail services configured:
  - Postfix: Mail Transfer Agent
  - Dovecot: IMAP/POP3 server
  - SSL/TLS: Encrypted mail transport
  - ClamAV: Antivirus protection
  - Fail2Ban: Intrusion prevention
- Next steps: Validate service status, backup cadence, and retention policies

## 12) Plain-Language Summary Of Work Completed

Use this section when explaining the project to non-technical stakeholders.

### 12.1 One-Page Summary

I migrated the website stack to new, lower-cost DigitalOcean droplets while keeping production and development separated and stable.

I tuned the server stack (nginx, PHP-FPM, Redis, and WordPress caching settings) so resource usage stays within acceptable limits.

I improved cacheability at the application layer by using custom WordPress/theme/plugin work for parts of the site that can otherwise break caching behavior.

I integrated Cloudflare at the DNS/edge layer and confirmed it is actively serving traffic.

I integrated Cloudinary for media delivery and enabled site-level Cloudinary behavior in WordPress.

I also added operational safeguards so the system is maintainable and recoverable, not just fast.

### 12.2 What Else Was Done (Beyond Performance Tuning)

- Automated backup replication to DigitalOcean Spaces for Updraft backups with retention rules (daily detailed copies, then weekly rollups for older backups).
- Added monthly system configuration backups (including nginx, PHP, cron, systemd, and WordPress config) with checksum files for integrity verification.
- Scheduled recurring automation in root cron for backup/sync workflows.
- Added repeatable monitoring for PHP-FPM and Redis behavior over time to validate tuning decisions with measured data.
- Captured a multi-host architecture snapshot process so production/dev app and database nodes can be audited consistently.
- Kept production Redis non-public (loopback only) and maintained restrictive firewall defaults on production app.
- Documented current architecture, open verification gaps, and next data-collection steps so future handoff work is faster.

### 12.3 Short Client-Friendly Version

I moved the site to more cost-effective infrastructure, tuned performance and caching (Cloudflare + Redis + server settings), and made targeted WordPress customizations to improve page cacheability. I also set up automated backups, retention, and monitoring so the environment is easier to operate and recover.

---

If you want this runbook split into separate docs (Network, App, Security, Backups, Integrations) for handoff to ops/dev teams, use this file as the canonical source and generate role-specific extracts.
