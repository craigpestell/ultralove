# Infrastructure Architecture Runbook

Last updated: 2026-09-30

How ultralove.ca (production) and dev.ultralove.ca (development) are built, where everything runs, and the
known gaps. Every fact carries its source and date, because the servers have changed a lot since April:

| Source | Covers | As of |
|---|---|---|
| DigitalOcean API (`doctl`) | Droplets, sizes, IPs, VPCs, cloud firewalls, managed database, alerts | 2026-09-30 |
| Public DNS (`dig`) | Nameservers, web, mail and email-auth records | 2026-09-30 |
| SSH to dev (read-only) | Dev OS, services, listeners, active plugins | 2026-09-30 |
| This repo | Deploy pipeline, dev build scripts, custom code, nginx reference config | 2026-09-30 |
| Earlier SSH inspection of prod | Prod OS, services, UFW, config paths, cron, backups | 2026-09-14 (**not re-verified**) |

The previous version of this document (droplet appendix, step-by-step performance logs) is in git history.

## 1) Topology

```
                     Visitors                          Craig / client (email PIN)
                        |                                         |
              Cloudflare (DNS, proxy, WAF, cache)        Cloudflare Access + Tunnel
                        |                                         |  (outbound-only tunnel)
  default-sfo2 VPC 10.120.0.0/20                      ultralove-dev-vpc 10.140.0.0/20
  +--------------------------------------------+      +-----------------------------------+
  | PRODUCTION-WEB-ultralove.ca  (10.120.0.7)  |      | ultralove-dev       (10.140.0.2)  |
  |  nginx + PHP-FPM 8.3 + WordPress + Redis   |----->|  nginx(127.0.0.1) + PHP 8.3 +     |
  |          |                                  | push |  MySQL 8.0 + Redis, all on one box|
  |          v  (trusted source only)           | key  +-----------------------------------+
  | db-mysql-sfo2-16643  managed MySQL 8       |        no route to prod or mail
  |                                            |
  | PRODUCTION-mail-2026  (10.120.0.2)         |<---- MX mail.ultralove.ca (not proxied)
  |  Postfix + Dovecot                          |
  +--------------------------------------------+
  WordPress outbound mail -> Mailgun (mg.ultralove.ca)    Media -> Cloudinary    Backups -> DO Spaces
```

All resources are in DigitalOcean **sfo2**.

### 1.1 DigitalOcean inventory (doctl, 2026-09-30)

| Name | ID | Size | Public IPv4 | Private IPv4 | VPC | Created | Tags |
|---|---:|---|---|---|---|---|---|
| PRODUCTION-WEB-ultralove.ca | 565420744 | s-8vcpu-16gb (8 vCPU, 16 GB, 320 GB) | 178.128.187.1, reserved IP 167.99.26.122 | 10.120.0.7 | default-sfo2 | 2026-04-17 | Production, nginx, php, php-fpm, wordpress |
| PRODUCTION-mail-2026-ubuntu-24-LTS | 568349152 | s-2vcpu-4gb (2 vCPU, 4 GB, 80 GB) | 143.110.140.137 | 10.120.0.2 | default-sfo2 | 2026-05-01 | Production, Postfix, Dovecot |
| ultralove-dev | 603274190 | s-4vcpu-8gb (4 vCPU, 8 GB) | 138.197.211.58 | 10.140.0.2 | ultralove-dev-vpc | 2026-09-24 | ultralove, Development, auto-sleep |
| db-mysql-sfo2-16643 (managed DB) | ecfe4ad3-80b5-485d-a947-edda138a7a31 | db-s-2vcpu-4gb, 1 node, 60 GB | n/a (trusted sources) | in default-sfo2 | default-sfo2 | 2026-04-18 | |

Notes:
- The prod droplet was resized from s-4vcpu-8gb (April) to s-8vcpu-16gb. Its droplet name changed from
  `ultralove-prod-app`; the SSH alias `ultralove-prod-app` is still what the deploy and dev scripts use.
- The prod droplet has two public addresses: its own (178.128.187.1, also its outbound source address, which
  `ops/dev/config.env` relies on as `PROD_PUBLIC_IP`) and a reserved IP (167.99.26.122).
- Droplet backups (the DigitalOcean feature) are **off on every droplet**. See section 9.

### 1.2 Retired since the April snapshot

| Was | Replaced by |
|---|---|
| PRODUCTION-DB-ultralove.ca droplet (10.120.0.4, self-run MySQL) | Managed MySQL cluster `db-mysql-sfo2-16643` |
| PRODUCTION-MAIL-mail.ultralove.ca droplet (Ubuntu 20.04, 10.120.0.3) | PRODUCTION-mail-2026-ubuntu-24-LTS (2026-05-01) |
| ultralove-dev-app + ultralove-dev-db droplets, on prod's private network with prod's keys | `ultralove-dev`, one box in its own VPC. The old dev was **compromised on 2026-09-18 and destroyed** |
| Elementor, Slider Revolution, Contact Form 7 | Theme templates (`front-page.php`), UltraLove Banners, Gravity Forms |

### 1.3 Networks and firewalls

| VPC | Range | Members |
|---|---|---|
| default-sfo2 | 10.120.0.0/20 | prod web, mail, managed DB |
| ultralove-dev-vpc | 10.140.0.0/20 | dev only, deliberately isolated from prod and mail |
| default-sfo3 | 10.124.0.0/20 | nothing (unused default) |

| Firewall | Applies to | Inbound |
|---|---|---|
| DO cloud firewall `ultralove-dev-fw` | ultralove-dev | 22/tcp from anywhere |
| DO managed-DB trusted sources | db-mysql-sfo2-16643 | droplet 565420744 (prod web) only |
| UFW on prod (as of 2026-09-14) | prod web | 22, 80, 443/tcp; default deny |
| UFW on dev (2026-09-30) | dev | OpenSSH only |

No DigitalOcean cloud firewall is attached to the prod web or mail droplets. Prod relies on UFW alone, and
the mail droplet's firewall has not been inspected.

### 1.4 Monitoring alerts (DigitalOcean)

| Alert | Scope | Threshold | Notifies |
|---|---|---|---|
| Droplet CPU | tag `Production` | > 70% for 5 min | craigpestell@gmail.com, Slack #general |
| DB CPU | managed DB | > 90% for 5 min | ultralove@telus.net |
| DB disk | managed DB | > 90% for 5 min | ultralove@telus.net |
| DB memory | managed DB | > 90% for 5 min | ultralove@telus.net |

## 2) Production web droplet

Server internals below are from the 2026-09-14 inspection and were not re-checked for this update.

### 2.1 Platform and services

- Ubuntu 24.04 LTS; WordPress root `/var/www/html`.
- nginx 1.24.0, php8.3-fpm (PHP 8.3), Redis 7.0 (loopback only, 127.0.0.1:6379), certbot.
- Listeners: nginx on 80 and 443, sshd on 22, Redis on loopback.

### 2.2 Key configuration locations

| What | Where |
|---|---|
| nginx global | `/etc/nginx/nginx.conf` |
| nginx vhost | `/etc/nginx/sites-available/ultralove.ca.conf` (reference copy: `ops/nginx/ultralove.ca.conf`) |
| nginx rate-limit zones | reference copy: `ops/nginx/99-ultralove-rate-limit-zones.conf` |
| PHP-FPM pool | `/etc/php/8.3/fpm/pool.d/www.conf` |
| Redis | `/etc/redis/redis.conf` |
| WordPress config | `/var/www/html/wp-config.php` |
| Root crontab | `/var/spool/cron/crontabs/root` |
| Deploy state, backups and log | `/var/lib/ultralove-deploy/` |
| nginx config backups | `/root/nginx-backups/` |

The nginx files in `ops/nginx/` are reference copies and are **not** deployed automatically.

### 2.3 nginx behaviour (from the reference config)

- HTTP/2 on the TLS listener (`listen 443 ssl http2;`). nginx 1.24 predates the `http2 on;` syntax, so don't use it.
- gzip with an explicit `gzip_types` list (css, js, json, xml, svg, fonts, wasm), `gzip_vary on`, level 5.
- Static assets (css, js, images, fonts): `Cache-Control: public, max-age=31536000, immutable`. Deliberately
  without `always`, so 404s are not cached for a year.
- `wp-admin/`, `my-account/`, `cart/`, `checkout/`, `wp-login.php`: `no-store, no-cache`.
- Per-IP rate limits: expensive `wc-ajax` actions (2 r/s, burst 8), add-to-wishlist (1 r/s, burst 4),
  Cloudinary queue REST endpoint (1 r/s, burst 5).
- Blocks `/documentation/`, dotfiles, and `.sql`, `.md`, `.log`, `.sh`, `.bak` and similar files.
- No `fastcgi_cache` or `proxy_cache`: anonymous HTML is cached at Cloudflare's edge, not by nginx.

### 2.4 PHP and object cache

- OPcache on, 128 MB. Prod does not pick up changed PHP files without a restart, so `deploy/deploy.sh`
  restarts php8.3-fpm whenever a deploy changes PHP.
- Redis object cache: `redis-cache` plugin plus the `wp-content/object-cache.php` drop-in (tracked in git),
  `WP_REDIS_HOST=127.0.0.1`, database 0, `WP_REDIS_MAXTTL=3600`.
- `DISABLE_WP_CRON` is true; WordPress cron runs from system cron.

## 3) Database

- **DigitalOcean Managed MySQL 8**, cluster `db-mysql-sfo2-16643`: db-s-2vcpu-4gb, a single node (no standby,
  so no automatic failover), 60 GB storage, attached to the default-sfo2 VPC.
- Databases: `wordpress` (the live site), `ultralove_rewrite`, `defaultdb`. Users: `doadmin`, `ultralove_wp`, `ultra`.
- **Trusted sources:** only the prod web droplet. DigitalOcean's "Authorize specific inbound connections" banner
  on the cluster page is a generic tip; the restriction is already in place. Dev is intentionally **not** a
  trusted source: it has its own local database and gets data only by a push from prod (section 5).
- WordPress should reach the cluster over the private VPC hostname. `DB_HOST` in `wp-config.php` was not
  re-read for this update. Get it from the live config; never from this document or from history.
- Maintenance window: Sundays 10:00 UTC. An update is pending as of 2026-09-30, so expect a short restart in
  the next window.
- Backups: DigitalOcean's automatic daily backups of the cluster, with point-in-time restore inside the
  retention window. Restoring creates a new cluster; WordPress's `DB_HOST` then has to be repointed.
- Never print the connection string or credentials. Test connectivity through WordPress (`wp db check`) or a
  client reading a root-only option file.

## 4) Mail

| Path | How |
|---|---|
| Inbound mail, mailboxes | MX `mail.ultralove.ca` -> 143.110.140.137, the Postfix + Dovecot droplet (DNS only, not proxied by Cloudflare) |
| WordPress outbound mail | Mailgun plugin, sending domain `mg.ultralove.ca` (SPF `include:mailgun.org`, MX to Mailgun) |

DNS (2026-09-30):
- Root SPF is `v=spf1 ~all` (no senders authorized) while DMARC is `p=quarantine`
  (`rua=mailto:ultra@ultralove.ca`). Mail sent **as @ultralove.ca from the mail droplet** has no SPF pass, so
  it depends on DKIM alignment to avoid quarantine. See open items.
- The mail droplet was built from a snapshot named `2vcpu-4gb-sfo2-mail-backup-to-be-updated-...` (since
  deleted). Its internals, SSH access and backup arrangements are undocumented.

## 5) Development environment

Full details and scripts: `ops/dev/README.md`; settings: `ops/dev/config.env`.

- **Private and disposable.** Rebuilt from scratch by `ops/dev/rebuild.sh` (2026-09-24, after the old dev was
  compromised on 2026-09-18).
- One droplet, `ultralove-dev`: nginx (bound to 127.0.0.1), PHP 8.3.6, MySQL 8.0.46, Redis 7.0.15,
  `cloudflared`, fail2ban, unattended-upgrades. Ubuntu 24.04.5 (verified 2026-09-30).
- Only SSH is open (DO firewall and UFW, key-only). Web access goes through a Cloudflare Tunnel behind
  Cloudflare Access (email PIN) for the addresses in `DEV_ACCESS_EMAILS`.
- Own VPC with no route to prod or mail. Prod can **push** to dev with a dedicated key that dev accepts only
  from 178.128.187.1, with no forwarding or pty; dev has no access to prod (`ops/dev/setup-prod-push.sh`).
- Data: an anonymized copy of prod (`ops/dev/refresh-db.sh`), except the accounts in `DEV_KEEP_EMAILS`.
  Uploads aren't copied; nginx redirects missing `/wp-content/uploads/*` to ultralove.ca.
- Safety: the `00-ultralove-environment-guard` MU plugin suppresses all email; plugins that hold live
  credentials are deactivated after every refresh (`DEV_DISABLED_PLUGINS`); outbound HTTP is blocked except
  wordpress.org; `DISALLOW_FILE_MODS`, so plugins are installed by copying them in.
- The droplet is tagged `auto-sleep`.

## 6) Code and deployment

- Repo: `craigpestell/ultralove` on GitHub; `main` is what runs on prod. Only custom code is tracked (an
  allowlist `.gitignore`); WordPress core, vendor plugins, uploads and `wp-config.php` are not.
- What the repo owns on the servers is `deploy/owned-paths.rules`: `mu-plugins/` (except ManageWP's
  `0-worker.php`), `themes/martfury`, `themes/martfury-child`, `plugins/hide-price-until-login`,
  `plugins/ultralove-*`, `object-cache.php` and the Cloudinary maintenance scripts.
- Flow: PR -> CI (`.github/workflows/ci.yml`: PHP 8.3 lint, shellcheck, no dumps, archives or large files) ->
  merge -> **"Deploy to dev"** Action deploys `main` to dev automatically -> `deploy/deploy.sh prod` from a
  trusted machine. Prod refuses any commit that isn't on `origin/main` and live on dev.
- Every deploy: drift check (stops if a server was edited outside git), server-side `php -l`, tarball backup,
  mirror of the owned paths, php-fpm restart when PHP changed, smoke test. Rollback: `deploy/rollback.sh <env>`.
  The `ultralove-deploy` skill has the details.
- **Critical CSS** Action (`.github/workflows/critical-css.yml`): after each dev deploy and every Monday, renders
  dev pages through an SSH tunnel and opens a "Regenerate critical CSS" PR when the result changes
  (`ops/critical-css/README.md`).
- Deployed to dev as of 2026-09-30: `1519ccf`. Prod status: `documentation/pending-release.md`.
- Secrets for CI live only in the GitHub `dev` environment (`DEV_SSH_KEY`, `DEV_SSH_KNOWN_HOSTS`, `DEV_HOST`).
  No CI job has access to prod.

## 7) WordPress application

### 7.1 Core and theme

- WordPress 6.9.4, WooCommerce. Theme `martfury-child` (parent `martfury`, a vendor theme with local fixes,
  both tracked in git).
- **Elementor has been removed** (2026-09-29): the home page is the child theme's `front-page.php`. Old
  `_elementor_data` post meta is still in the database, unused.
- Home and header banners: **UltraLove Banners** (in-house plugin, replacing Slider Revolution).
- Forms: Gravity Forms with **Cloudflare Turnstile** (2026-10-01; replaced reCAPTCHA v2) and the User
  Registration add-on.

### 7.2 Active plugins

Dev's active list (2026-09-30), which matches prod apart from the plugins dev deactivates:

advanced-custom-fields-pro, advanced-woo-labels, am-collect-media, autoptimize,
back-in-stock-notifier-for-woocommerce, custom-shipping-methods-for-woocommerce, gravityforms,
gravityformsturnstile, gravityformsuserregistration, hide-price-until-login, hide-updates, kirki,
martfury-addons, meta-box, ni-woocommerce-custom-order-status, quantity-field-on-shop-page-for-woocommerce,
redirection, redis-cache, relevanssi, relevanssi-live-ajax-search, support-webp, ultralove-backdrops,
ultralove-banners, ultralove-product-export-sync, ultralove-product-stock-export, user-role-editor,
user-switching, widget-shortcode, woocommerce, woocommerce-extra-product-sorting-options,
woocommerce-xml-csv-product-import, woo-add-gtin, woo-update-manager, wp-all-import-pro, wp-useronline,
yith-woocommerce-wishlist.

Also on prod (deactivated on dev, from `DEV_DISABLED_PLUGINS`), where installed and active: ManageWP
`worker`, `mailgun`, `mailchimp-for-wp`, `updraftplus`, Cloudflare plugins, the Cloudinary plugin,
`woocommerce-services`. Run `ops/dev/parity.sh` for an exact prod/dev comparison.

Deactivated on prod, not deleted (2026-09-14): the four WebToffee import/export plugins (superseded by WP All
Import/Export) and Nelio A/B Testing (its Autoptimize compatibility shim disabled JS optimization).

### 7.3 Custom code (all in git)

| Code | Purpose |
|---|---|
| `plugins/hide-price-until-login` | Wholesale: prices and cart only for approved, logged-in customers |
| `plugins/ultralove-banners` | Home/header banner carousels (replaces RevSlider) |
| `plugins/ultralove-backdrops` | Page backdrops |
| `plugins/ultralove-product-export-sync`, `ultralove-product-stock-export` | CSV product and stock export automation |
| `mu-plugins/00-ultralove-environment-guard.php` | Dev safety: suppresses email and outbound side effects outside production |
| `mu-plugins/00-close-php-session-for-rest.php` | Closes PHP sessions before REST/loopback calls (avoids session-lock stalls) |
| `mu-plugins/01-*`, `05-shop-cache-ttl.php`, `40-media-delete-fixes.php` | WooCommerce deprecation bypass, YITH wishlist lookup cache, shop cache TTL, media delete fixes |
| `mu-plugins/02-lazy-load-gravity-forms.php` | Loads Gravity Forms and its captcha only with a form |
| `mu-plugins/20-customer-ip-unblock.php` | Customer IP unblock tool |
| `mu-plugins/30`-`34-*.php` | Front-end performance (section 8) |
| `mu-plugins/martfury-mobile-detect-override.php` | Early override of Martfury's mobile detection |
| `mu-plugins/ultralove-wc-analytics-top-brands.php` (+ `.js`) | WooCommerce Analytics top-brands report |
| `object-cache.php`, `cloudinary-*.php`, `run-cloudinary-workers.sh` | Redis drop-in; Cloudinary sync and audit maintenance scripts |

The page-by-page map of what renders where is in the `ultralove-page-sources` skill.

### 7.4 Local modifications to vendor plugins (lost on update)

- Cloudinary plugin 3.3.2: cloud-name regex fix, "Unsync all Cloudinary media" bulk action and
  `wp cloudinary unsync` (see `ops/dev/README.md`).
- Slider Revolution: Cloudinary `f_auto,q_auto:eco` URL rewriting in `includes/output.class.php`. Moot now that
  UltraLove Banners renders the banners, but still on disk if RevSlider is installed.

## 8) Caching and front-end performance

### 8.1 Layers, outside in

1. **Cloudflare edge:** caches anonymous HTML and static assets. Browser Cache TTL is set to *Respect Existing
   Headers* (it was overriding the origin's 1-year header down to 4 hours until 2026-09-14).
2. **nginx:** gzip and long-lived static headers; no page cache (section 2.3).
3. **WordPress:** Redis object cache and OPcache (section 2.4).
4. **Front-end assets:**
   - **Autoptimize:** CSS aggregated with inline CSS included (`autoptimize_css_aggregate`,
     `autoptimize_css_include_inline` = `on`, 2026-09-30), placed at the end of `<head>` after the preloads.
     JS **minified per file, not aggregated** (see 8.2). Cart, checkout and my-account aren't optimized.
   - **Critical CSS** (`34-critical-css.php` + `martfury-child/css/critical.css`, logged-out visitors only):
     the first screen's CSS is inlined, the full stylesheet loads without blocking. Generated, never hand-edited
     (`ops/critical-css/`).
   - **Trimmed stylesheets:** the child theme serves purged copies of Martfury, the icon fonts and WooCommerce
     CSS under their original handles (Martfury 660 KB -> ~243 KB). Generated by `ops/css/`; regenerate after a
     Martfury or WooCommerce update.
   - `31-trim-frontend-scripts.php` / `33-trim-frontend-styles.php`: drop unused JS/CSS (PhotoSwipe only on
     product pages, no Google Fonts, no block CSS). `32-defer-jquery.php`: defers jQuery on optimized pages.
   - Fonts: Work Sans' two Latin files and the icon font are preloaded.
   - Images: Cloudinary delivery. `30-woocommerce-loop-image-sizes.php` gives product-grid images a correct
     `sizes` attribute; the home page product cards and banners lazy-load and preload where it matters.

### 8.2 Lessons learned (2026-09-14 performance work)

- **Don't aggregate JS on this site.** Combining ~65 scripts into one ~593 KB bundle cut requests from 79 to 14
  but raised Lighthouse Total Blocking Time from 170 ms to 1,030 ms (score 53 -> 33). Always compare TBT after
  a change, not just request count or LCP.
- **Cloudinary's own lazy load broke product grids** (blank cards for real visitors) and was cached at the edge.
  It stays off (`use_lazy_load = off`). After any change visible to visitors, check the edge copy and purge
  Cloudflare if a broken version was cached.
- **Autoptimize disables JS optimization for any logged-in editor** on every page (its Elementor compatibility
  shim). Always verify optimization while logged out (incognito or `curl` without cookies).
- **Live filter hooks can override options:** Nelio A/B Testing silently disabled Autoptimize's JS through a
  filter, invisible to option-only tests. Test through a real request.
- The "non-composited animations" Lighthouse finding is Martfury's `transition: all` rules touching
  `visibility` on product cards, not real animations. Low priority.

### 8.3 Rollbacks

| Change | Undo |
|---|---|
| Autoptimize entirely | `wp plugin deactivate autoptimize --allow-root`, then purge Cloudflare |
| CSS aggregation | set `autoptimize_css_aggregate` and `autoptimize_css_include_inline` to `''`, `wp autoptimize clear` |
| Critical CSS | empty or remove `critical.css` via a deploy (pages fall back to normal CSS) |
| Any mu-plugin | revert in git and deploy (php-fpm restarts automatically) |

## 9) Backups and recovery

| What | How | Where | Verified |
|---|---|---|---|
| Database | DigitalOcean managed-DB daily backups + point-in-time restore | DigitalOcean | cluster online 2026-09-30 |
| WordPress files and DB (plugin) | UpdraftPlus -> `/root/updraft_spaces_sync.sh` at 03:20 daily, retention 3 days daily + weekly to day 33 | Spaces `s3://ultralove-web-backups/updraft/` | 2026-09-14: cron ran with **`DRY_RUN=1`**, so nothing was actually uploaded. Re-check |
| Server config | `/root/monthly_system_backup.sh` at 02:40 on the 1st (nginx, PHP, letsencrypt, cron, systemd, scripts, wp-config) | `/root/system_monthly_backups/` and Spaces `system-config/` | 2026-09-14 |
| Custom code | git; each deploy keeps a tarball of the previous files | GitHub; `/var/lib/ultralove-deploy/backups/` | 2026-09-30 |
| Droplets | **none**: DigitalOcean droplet backups are off on prod web, mail and dev | | 2026-09-30 |
| Mail droplet data | unknown | | |

Dev needs no backups: it is rebuilt from scripts and refreshed from prod.

## 10) Cloudflare

- Nameservers `andy`/`arya.ns.cloudflare.com`; `ultralove.ca` and `www` are proxied. The `ultralove.ca` zone
  that also exists in DigitalOcean DNS is unused (not the authoritative nameservers).
- WAF custom rule **Challenge outsiders**: Managed Challenge for `(ip.src.country ne "CA" and not cf.client.bot)`.
  The verified-bot exception keeps Googlebot and other crawlers working.
- Caching: Browser Cache TTL *Respect Existing Headers*.
- **Turnstile:** Gravity Forms forms 1 (footer), 2 (registration) and 3 (contact), plus the back-in-stock notifier.
- **Tunnel `ultralove-dev` + Access** protect dev.ultralove.ca (`ops/dev/cloudflare-setup.sh`).
- Not documented here: SSL/TLS mode, other cache, page or transform rules, bot settings.

## 11) Cloudinary

- Cloudinary plugin (3.3.2, locally modified; section 7.4) serves media; cloud `dp4dg7god`. WordPress
  intermediate sizes are not generated; Cloudinary transforms on delivery.
- Sync and audit maintenance scripts live in `wp-content/` (tracked in git); the queue endpoint is rate-limited
  in nginx.
- Deactivated on dev, so dev images come from prod via the uploads redirect.
- Secrets are never recorded here.

## 12) Open items and findings

Security and resilience:
1. **No droplet backups** on prod web or mail. Only the database, code and (if the sync really runs) Updraft
   archives are recoverable. Consider DigitalOcean weekly backups, at least for mail.
2. **Updraft -> Spaces sync was in dry-run mode** (`DRY_RUN=1`) at the last check. Confirm whether off-site
   file backups actually exist.
3. **Prod and mail have no DigitalOcean cloud firewall**; prod relies on UFW and the mail droplet hasn't been
   inspected. A cloud firewall would allow 80/443 only from Cloudflare's ranges and SSH only from known
   addresses. Do this with care: the dev push and deploy run over SSH.
4. After the 2026-09-18 dev compromise, keys that had lived on the old dev (including any with prod access)
   should be confirmed rotated. Not verifiable from here.
5. **Managed DB is a single node**: there is no standby during maintenance or a failure.

Mail:
6. Root SPF `v=spf1 ~all` authorizes no servers, while DMARC is `p=quarantine`. Add the mail droplet
   (`ip4:143.110.140.137` or `a:mail.ultralove.ca`) once confirmed it sends as @ultralove.ca, and check its DKIM.
7. Document the mail droplet: SSH access, Postfix/Dovecot config, TLS, backups.

Housekeeping:
8. Re-inspect the prod droplet (OS patch level, UFW, services, cron, `DB_HOST`) and update sections 2 and 9;
   they date from 2026-09-14.
9. Unused DigitalOcean resources: the `ultralove.ca` DNS zone, the `default-sfo3` VPC, and the
   `ultralove_rewrite` and `defaultdb` databases (confirm before removing).
10. Delete the deactivated WebToffee and Nelio plugins, and remove Elementor's leftover post meta and RevSlider
    if no longer needed.
11. Pre-existing front-end bug: an inline "CSV export" script runs on every page and throws on missing
    `#exportMe`/`#export` elements.

## 13) Collecting this information again

```bash
# DigitalOcean (from a workstation with doctl)
doctl compute droplet list --format Name,ID,Region,Status,PublicIPv4,PrivateIPv4,Tags
doctl vpcs list; doctl compute firewall list; doctl compute reserved-ip list
doctl databases list; doctl databases firewalls list <cluster-id>; doctl monitoring alert list

# DNS
dig +short NS ultralove.ca; dig +short MX ultralove.ca; dig +short TXT ultralove.ca _dmarc.ultralove.ca mg.ultralove.ca

# Each server
hostnamectl; ufw status verbose; systemctl is-active nginx php8.3-fpm redis-server mysql cloudflared fail2ban
ss -ltn; crontab -l
cd /var/www/html && wp core version --allow-root && wp plugin list --status=active --allow-root

# Prod vs dev versions
ops/dev/parity.sh
```

Never paste credentials, salts or full config files into this document.
