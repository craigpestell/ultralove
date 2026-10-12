# Infrastructure Architecture Runbook

Last updated: 2026-10-02 (UTC) - production moved to a new droplet (Ubuntu 26.04, PHP 8.5, s-4vcpu-8gb).
Source of truth for sections 1, 2, 4.1-4.3, 5.1, 7 and 8: live inspection on 2026-10-02 (`doctl`, the hosts
themselves). Sections marked as dated (4.4 onwards, 10, 11) are historical notes from earlier work.

Server configuration that used to exist only on the hosts is now in the repo: `ops/nginx/`, `ops/php/`
(production nginx and PHP-FPM), `ops/prod/` (backup scripts) and `ops/do-migration/bootstrap-app-server.sh`,
which builds a production app droplet from them; `ops/dev/` builds dev.

## 1) Topology Overview

Production is one app droplet in DigitalOcean SFO2 behind Cloudflare, reached through a reserved IP, with a
DigitalOcean managed MySQL database and a separate mail droplet. Development is one self-contained droplet
(app + MySQL) behind a Cloudflare Tunnel.

```
visitors -> Cloudflare (proxy, APO, WAF) -> reserved IP 167.99.26.122 -> PRODUCTION-WEB-ultralove.ca
                                                                          (nginx, PHP 8.5, Redis, WordPress)
                                                                              | private network (VPC)
                                                                          managed MySQL db-mysql-sfo2-16643
```

### 1.1 Hosts and Services

| Role | Name | ID | Size | OS | Notes |
|---|---|---:|---|---|---|
| Prod app | PRODUCTION-WEB-ultralove.ca | 605458201 | s-4vcpu-8gb | Ubuntu 26.04.1 LTS | nginx 1.28, PHP 8.5, Redis 8, WordPress. Since 2026-10-02. |
| Prod DB | db-mysql-sfo2-16643 | ecfe4ad3-80b5-485d-a947-edda138a7a31 | db-s-2vcpu-4gb, 1 node | managed MySQL 8 | DigitalOcean managed database, private VPC endpoint; trusts only the prod app droplet. |
| Prod mail | PRODUCTION-mail-2026-ubuntu-24-LTS | 568349152 | s-2vcpu-4gb | Ubuntu 24.04 | Mailbox/MTA host (not WordPress mail - that goes through Mailgun, see 6.5). |
| Dev | ultralove-dev | 603274190 | s-4vcpu-8gb | Ubuntu 26.04.1 LTS | Built by `ops/dev`; nginx, PHP 8.5, MySQL 8.4 and Redis on one box; private, via Cloudflare Tunnel + Access. DO still labels its image "24.04" (upgraded in place with `do-release-upgrade`). |

### 1.2 DigitalOcean Inventory (2026-10-02)

| Name | ID | Region | Status | Public IPv4 | Private IPv4 |
|---|---:|---|---|---|---|
| PRODUCTION-WEB-ultralove.ca | 605458201 | sfo2 | active | 165.22.169.132 (+ reserved IP 167.99.26.122) | 10.120.0.4 |
| PRODUCTION-mail-2026-ubuntu-24-LTS | 568349152 | sfo2 | active | 143.110.140.137 | 10.120.0.2 |
| ultralove-dev | 603274190 | sfo2 | active | 138.197.211.58 | 10.140.0.2 |

- **Reserved IP 167.99.26.122** is production's address for Cloudflare (the origin). Moving production to another
  droplet = `doctl compute reserved-ip-action assign 167.99.26.122 <droplet-id>`; no DNS change.
- **Managed DB trusted sources**: droplet 605458201 only. Add any new app droplet before it can connect.
- **Cloud firewalls**: `ultralove-dev-fw` (dev: SSH only). Production uses UFW on the droplet (2.3).
- **Snapshot** `RETIRED-2026-10-02-PRODUCTION-WEB-ultralove.ca-ubuntu24.04-php8.3` (31.64 GiB, minimum disk 320 GB):
  the previous production droplet (ID 565420744, s-8vcpu-16gb, Ubuntu 24.04 / PHP 8.3, public 178.128.187.1),
  deleted after the move. It also holds that droplet's `/root` (pre-change backups made by hand, e.g.
  `gf-forms-before-turnstile-20261001022554.json`), which was not copied to the new droplet.
- No longer exist: the self-hosted prod DB droplet (533903042), the old mail droplet (242963159), and the separate
  dev app / dev DB droplets (565178171, 565178217).

### 1.3 Production Move of 2026-10-02

The previous droplet (s-8vcpu-16gb) averaged ~4% CPU (peak ~16%) and ~1.6 GB of 16 GB RAM, and a droplet resize
can't shrink a disk, so production was rebuilt on a new, smaller droplet with Ubuntu 26.04 / PHP 8.5 from
`ops/do-migration` (bootstrap + the repo's nginx/PHP config), evaluated as a dry run against the live database
(mail and cron blocked on it), then switched over by moving the reserved IP (~04:56 UTC). Cron and backups ran on
exactly one droplet throughout. Steps: `ops/do-migration/README.md` ("Replacing the production droplet").

### 1.4 Networks and Firewalls (2026-10-02)

| VPC | Range | Members |
|---|---|---|
| default-sfo2 | 10.120.0.0/20 | prod web, mail, managed DB |
| ultralove-dev-vpc | 10.140.0.0/20 | dev only - deliberately isolated from prod and mail |
| default-sfo3 | 10.124.0.0/20 | nothing (unused default) |

| Firewall | Applies to | Inbound |
|---|---|---|
| UFW on prod web | PRODUCTION-WEB-ultralove.ca | 22/tcp from anywhere; 80,443/tcp from Cloudflare's ranges only; default deny |
| Managed DB trusted sources | db-mysql-sfo2-16643 | droplet 605458201 (prod web) only |
| DO cloud firewall `ultralove-dev-fw` | ultralove-dev | 22/tcp from anywhere |
| UFW on dev | ultralove-dev | SSH only (web arrives through the Cloudflare Tunnel) |
| UFW on mail | PRODUCTION-mail-2026-ubuntu-24-LTS | 22, 25, 110, 143, 465, 587, 993, 995 from anywhere; 80 denied |

No DigitalOcean cloud firewall is attached to prod web or mail.

The mail droplet's SSH was meant to be reachable from the private network only, but on 2026-10-02 UFW allowed
22/tcp from anywhere and logins from the public internet succeeded (section 8).

SSH (2026-10-02): prod web and dev accept only current keys (the maintainer's laptop; on dev also the GitHub Actions
deploy key and prod's push key, the latter only from prod's IP). The mail droplet's root `authorized_keys` still
lists 11 entries, some stale (section 8).

### 1.5 Monitoring Alerts (DigitalOcean)

| Alert | Scope | Threshold | Notifies |
|---|---|---|---|
| Droplet CPU | tag `Production` (covers the new prod droplet) | > 70% for 5 min | email + Slack |
| DB CPU | managed DB | > 90% for 5 min | email |
| DB disk | managed DB | > 90% for 5 min | email |
| DB memory | managed DB | > 90% for 5 min | email |

A droplet built for production must carry the `Production` tag to be monitored (`provision-droplet.sh` sets it).

### 1.6 Shopify/WooCommerce integration lab (2026-10-08 UTC)

Dedicated synthetic-data supplier backend, created with approval at USD 12/month
under DigitalOcean team `ultralove`. This is separate from production and the
existing development environment; no database, credentials, or uploads were copied.

| Resource | Value |
|---|---|
| Droplet | `ultralove-integration-lab`, ID `607169886`, SFO2, `s-1vcpu-2gb` |
| Public / private IPv4 | `104.248.211.144` / `10.150.0.2` |
| VPC | `ultralove-integration-lab-vpc`, `455858af-fe9d-4c22-806a-77508a14bd4c`, `10.150.0.0/20` |
| Cloud firewall | `ultralove-integration-lab-fw`, `ed6e74c4-90b5-4b5e-89c7-a8d1704876c9`, lab tag only |
| Supplier URL | `https://ultralove-lab.104-248-211-144.sslip.io` (temporary IP-derived hostname; no UltraLove DNS changes) |
| Shopify retailer | `https://admin.shopify.com/store/ultralove-integration-lab`, development store, test gateway listed |
| Stack | Ubuntu 24.04.5, nginx 1.24, PHP 8.5.11 FPM/CLI (Ondrej PHP PPA), MariaDB (loopback only), WordPress 7.1.3, WooCommerce 11.2.0 |
| WordPress root | `/var/www/integration-lab` |
| Admin | `lab-admin`; password stored only in root-readable `/root/.ultralove-integration-lab/admin-password` |

Cloud firewall and UFW restrict SSH to administrator IPv4 `154.20.223.24`.
Nginx restricts `/wp-admin/` and `/wp-login.php` to that address and loopback.
HTTP/HTTPS are public for integration callbacks; HTTPS uses a Let's Encrypt
certificate with automatic renewal. Update both firewalls and Nginx if the
administrator IP changes. MySQL is not exposed.

Outbound RFC1918 traffic is denied. Public DNS (`1.1.1.1`, `1.0.0.1`, global
`~.` route) avoids the otherwise blocked private VPC DNS resolver. The lab
MU plugin suppresses and logs all attempted email. Search indexing and customer
registration are disabled; no payment gateway is enabled. Two synthetic products
have SKU `LAB-SIMPLE-001` (stock 10) and `LAB-SIMPLE-002` (stock 5).

Provisioning sources: [cloud-init](../ops/integration-lab/cloud-init.yaml) and
[bootstrap](../ops/integration-lab/bootstrap.sh). The bootstrap refuses an existing
WordPress configuration and requires the lab-only host marker. It is not a
production deployment target. WordPress is extracted with system tar because
WP-CLI's extractor truncated long paths in this release; core and WooCommerce
checksums were verified after correcting that extraction.

Baseline verification: run [verify.php](../ops/integration-lab/verify.php) using
`wp eval-file` on this host. System cron runs WordPress jobs every five minutes,
explicitly with `/usr/bin/php8.5`. Nginx uses `/run/php/php8.5-fpm.sock`; FPM and
CLI share the lab's memory/upload limits and error-display restrictions.
PHP 8.3 packages remain installed for rollback, but its FPM service is stopped
and disabled. Pre-upgrade Nginx, cron, and lab PHP configuration are saved in
root-only `/root/.ultralove-integration-lab/php85-before-20261008T051303Z`.
Production connector installation and full production-plugin compatibility
have **not** been validated; custom lab-only flow results are recorded below.
Keep real data, payment
credentials, and warehouse/carrier automation out of this lab. No paid backups
were enabled; recreate synthetic data rather than treating this as durable commerce.

**Syncerize evaluation (2026-10-08 UTC): rejected as shipped.** Its
[WooCommerce overview](https://syncerize.tawk.help/article/syncerize-overview-for-woocommerce)
documents cross-platform order forwarding and fulfillment, but excludes partial
fulfillment on WooCommerce. The official live package and update manifest both
identified version `1.2.50`. Package SHA-256:
`900f7a1f742f2f48e90e5a1373dc0c6a882876d76c8a23725964cab1b9e99892`.

Before activation, only the vendor's helper functions and order controller were
loaded in a private WP-CLI process, with the current user explicitly set to zero.
A REST dispatch with no Authorization header returned HTTP 200 and changed a
new synthetic order from pending to cancelled. This confirmed an access-control
failure, not just a suspicious route declaration. The synthetic order was
deleted in a finally block; the baseline checks passed afterward. No plugin
activation, public vendor routes, store connection, paid plan, or live orders
were involved. Catalog sync, mixed-cart routing, wholesale accounting, and
tracking tests were not run after this failed prerequisite. Do not install
this package on production or expose its routes on the public lab.

**Syncee preparation (2026-10-08 UTC): both apps installed, stores not connected.** Official
`syncee-for-suppliers` version `1.0.26` is active only on the supplier lab, with
WordPress.org package checksums verified. All seven installer endpoints reject
anonymous requests; actual HTTPS requests to the installer data endpoint and an
unsolicited token callback returned HTTP 401. Its WooCommerce, WordPress,
permalink, administrator email, store-name, and cURL prerequisites passed on
PHP 8.5.11. The full lab baseline still passes with synthetic inventory unchanged,
no orders, no enabled gateways, and email suppression intact.

Run [verify-syncee.php](../ops/integration-lab/verify-syncee.php) through
`wp eval-file` on this host to repeat the **unconnected** installer checks.
The plugin's administrator setup is at
`/wp-admin/admin.php?page=syncee-for-suppliers`. No WooCommerce API authorization,
supplier account registration, or supplier access tokens have been created.

The user restored the authenticated Shopify session. **Syncee AI Dropship**
was then authorized on `ultralove-integration-lab` and verified in Shopify's
installed-app list and installation details. Its permissions cover customer data,
store-owner data, products, orders, and other store data; no production store was
authorized. Shopify warned that the app does not yet support ship-and-pickup
in one order. This is a compatibility warning, not proof about ordinary
mixed-supplier shipped carts.

Retailer onboarding did not complete: "Skip all steps" repeatedly returned to
step 3, including after reopening the app. Browser resource timing recorded
HTTP 404 for the default retailer catalog and HTTP 500 for
`entry.syncee.com/syncee-product-service/api/v3/suppliers/search`.
The cause of the onboarding loop is not established. No suggested marketplace
products were deliberately imported and no paid plan or trial was approved.

The [Shopify app listing](https://apps.shopify.com/syncee-1) advertises 25 imports
on Free, but lists **Private suppliers** and **Suppliers with Retailer Approval**
under Basic at **US$39.99/month** (with a trial/promotion). A free development-store
exception has not been established; installing the app alone does not demonstrate
access to a private supplier. Supplier onboarding should proceed only if an
explicitly synthetic/private test catalog is allowed without a paid charge or
live payment provider. Syncee's documented order process requires the retailer
to pay before forwarding. Test payment support and supplier approval remain
unresolved; do not submit this synthetic lab as a real public marketplace supplier
or claim end-to-end automation is verified.
Product import, inventory synchronization, mixed-cart routing, wholesale order
accounting, and tracking return remain untested **for Syncee**.

**Legacy plugin cleanup (2026-10-09 UTC):** the unconnected
`syncee-for-suppliers` plugin was deactivated on the isolated supplier lab after
the custom dev product-feed/Shopify workflow superseded this evaluation.
All three installer/token options were still absent, and the old lab bridge
cron was absent. The active-plugin list was backed up root-only at
`/root/.ultralove-integration-lab/plugin-cleanup/active-plugins-before-20261009.json`.
At the user's subsequent request, all 18 plugin files were deleted with
`wp plugin delete syncee-for-suppliers`; the plugin directory is confirmed
absent. A root-only recovery archive outside the webroot is retained at
`/root/.ultralove-integration-lab/plugin-cleanup/syncee-for-suppliers-1.0.26-before-delete-20261009T1042Z.tgz`.
Deletion did not run the plugin's database uninstall routine.
Nginx and PHP 8.5 FPM remained active and the lab origin returned HTTP 200.
Rollback: on the lab only, extract that archive into
`/var/www/integration-lab/wp-content/plugins`, restore ownership to `www-data`,
then run
`wp plugin activate syncee-for-suppliers --allow-root` from
`/var/www/integration-lab`. This does not uninstall the separate Shopify
Syncee app, change billing, destroy the lab, or change dev/production plugins.
The earlier Syncee verification results above describe the historical active
evaluation; file restoration and reactivation are required to repeat its
installer checks.

**Custom test connector (2026-10-08 UTC): connected and verified.**

The free, own-organization Shopify app **UltraLove Dropshipping Lab**
(Dev Dashboard app `433204330497`, organization `239653376`) is installed only
on `ultralove-integration-lab.myshopify.com`. It uses Admin GraphQL API `2026-10`
with `write_products`, `write_inventory`, `read_locations`, `write_orders`,
`write_merchant_managed_fulfillment_orders`, and `write_fulfillments`.
Client credentials are in separate root-only files
`/root/.ultralove-integration-lab/shopify-client-id` and
`/root/.ultralove-integration-lab/shopify-client-secret`, outside the web root
and repository. An initial secret surfaced in the browser's accessibility
snapshot despite the field being masked; it was rotated and revoked before
use. The replacement was transferred through the clipboard and SSH without
displaying its contents, and the clipboard was cleared. The worker exchanges
credentials for an ephemeral token each invocation; it does not persist tokens.
This own-organization grant is **not** authentication for independent retailers.

[shopify-bridge.php](../ops/integration-lab/shopify-bridge.php) is a root-only
WP-CLI worker, not a public WordPress plugin or webhook endpoint. It refuses
other hosts, databases, currencies, and environment types. It targets exactly
the two lab SKUs and Shopify's merchant-owned **Shop location**; the development
store also has sample locations that must not be selected automatically.
Stable product handles and saved variant IDs keep reimports idempotent.
Initial retail prices use a synthetic 50% markup; subsequent synchronization
preserves retailer-edited prices. Inventory uses compare-and-swap
`changeFromQuantity` and the required `@idempotent` directive.

The worker polls paid **test** Shopify orders tagged
`ultralove-integration-lab`, forwards only mapped variants, and attributes them
to the synthetic WooCommerce customer `shopify-lab-retailer`.
The recipient must be `Synthetic Recipient` at `123 Synthetic Street` with a
complete Canadian delivery address. Billing and notification metadata use
`retailer@example.invalid`. Supplier lines use WooCommerce prices rather than
retail prices. Orders begin **on hold**, with no supplier payment recorded;
Shopify test payment is not wholesale payment. The worker's exclusive process
lock, source-order metadata, and import-completion marker prevent duplicate
creation; incomplete imports stop with an explicit reconciliation error.
For completed synthetic supplier orders, setting `_ul_lab_tracking_number`
to a `LAB-` number returns tracking for mapped lines only, with
`notifyCustomer: false`. It never completes unrelated retailer lines.

The deployed worker and verifier are in `/root/.ultralove-integration-lab/`.
[shopify-bridge.cron](../ops/integration-lab/shopify-bridge.cron) is installed as
`/etc/cron.d/integration-lab-shopify-bridge` and runs every five minutes under
PHP 8.5. Logs are root-only at
`/root/.ultralove-integration-lab/shopify-bridge.log`; the WordPress option
`ul_lab_shopify_last_success` records a successful cycle. To pause the bridge,
remove only that cron file; catalog products and mappings are retained.
The existing system-managed WordPress cron and safety MU plugin are unchanged.
An actual scheduler-triggered successful cycle was verified at
`2026-10-08T16:20:07+00:00`, not just a manual invocation. Nginx, PHP 8.5 FPM,
MariaDB, and cron were active; credential and log permissions were `0600`.
Shopify's browser product list also showed the two synthetic imports with
restored stock quantities of 10 and 5.

Run the live test through WP-CLI on the lab:

```sh
cd /var/www/integration-lab
wp --allow-root eval-file /root/.ultralove-integration-lab/verify-shopify-bridge.php
wp --allow-root eval-file /root/.ultralove-integration-lab/verify.php
```

[verify-shopify-bridge.php](../ops/integration-lab/verify-shopify-bridge.php)
verified actual API effects: catalog reimport identity, retailer-price
preservation, inventory change and restoration, mixed-line isolation,
retailer attribution and recipient address, wholesale totals, unpaid supplier
status, once-only stock reduction, duplicate polling, unpaid/unmapped/non-test
order rejection, invalid stock/address rejection, tracking return, partial
fulfillment, duplicate tracking and lost-acknowledgement replay,
disabled gateways, and suppressed email. It creates explicitly synthetic
Shopify orders through `orderCreate(test: true)`, then deletes only its own
orders on both platforms and restores stock. The imported catalog and synthetic
retailer account remain. No real funds, emails, parcels, or production data
are used.

**Scope:** this is a working single-store API-flow test environment, not the
production dropshipping service. It does not publish products to the Online
Store channel or prove a browser checkout. Independent-retailer OAuth,
merchant approval, configurable catalogs/pricing, production wholesale taxes
and shipping, supplier settlement, cancellations/refunds, variants,
multi-location allocation, robust queue recovery/monitoring, and real warehouse
integration still need a separately scoped production implementation.
Do not remove the test-only guards to deploy this worker to production.

**Dev supplier connected (2026-10-08 UTC): existing products only.**

**Embedded Shopify interface (2026-10-09 UTC): catalog, pricing and draft jobs live.**

The same **UltraLove Dropshipping Lab** installation now opens
`https://shopify-lab.104-248-211-144.sslip.io` inside Shopify admin.
Active app version is `ultralove-lab-embedded-foundation`; its API version and
six existing access scopes are unchanged. The only redirect allowlisted is
`https://shopify-lab.104-248-211-144.sslip.io/auth/callback`.
Real authenticated embedded rendering passed, and a subsequent dev product-only
worker cycle synced the original three SKUs while preserving retailer prices.
No new app, production change, order, payment, email or shipment was created.

The interface has a separate private source repository,
`craigpestell/ultralove-shopify-app`, and local workspace
`/Users/craig/work/ultralove-shopify-app`. Its source is published privately:
`main` contains the deployed foundation and `feat/embedded-catalog` contains the
catalog/hosting increment for review. Runtime deployment used git archives and
does not imply that the review branch has been merged.
Pricing/import release `13306ab` is hosted on the existing isolated integration-lab
droplet as non-root systemd service `ultralove-shopify`, using checksum-verified
Node 24.21.0, a loopback-only listener on port 3097, a separate Nginx HTTPS vhost,
and a Let's Encrypt certificate with automatic renewal. No firewall or UltraLove
DNS policy changed. The existing WordPress lab vhost is unchanged.

Code is under `/srv/ultralove-shopify/releases`, selected by `current`.
Mode-0600 persistent SQLite sessions are under `/var/lib/ultralove-shopify`;
the root-only environment is `/etc/ultralove-shopify/app.env`.
The service reuses the installed app identity without reading the root worker's
credential files itself. Its access logging excludes request query parameters.
The deployed UI browses 50 published parents per page, searches names/SKUs,
filters categories and saves at most 50 explicitly selected parent IDs in the
app-owned SQLite database. Saving does not import, publish, synchronize inventory
or approve order forwarding.

The live transport uses a separate SSH key, pinned host key and forced PHP
command on verified dev host `138.68.43.251` (`ultralove-dev-managed`), not the
historical dev IP in the topology table. SSH account `ultralove-catalog-proxy`
is restricted to source `104.248.211.144` and command `catalog-read`; an attempted
`id` was rejected. The proxy uses the existing authenticated loopback HTTP feed
with a separate revocable password on the catalog-only customer account.
It grants no shell, forwarding or administrator access and changes no firewall
or Cloudflare Access policy.
Routine authentication/feed database bookkeeping is allowed, as confirmed by
the user on 2026-10-09. This connection does not request the optional strict
database-read mode. No WordPress plugin/MU-plugin runtime changes were deployed;
the earlier local strict-read bookkeeping fix was removed before deployment.

Live browser checks verified paging, SKU `1513`, combined category/SKU filtering,
selection persistence after reload, saving exactly 50 parents, disabled
checkboxes at that limit and visible rejection of a forged 51-parent selection.
Release `05f1160` additionally passed exact category-label access, readable entity
decoding and clearing the unsaved selection. Existing saved selections were
restored after import verification. Twenty-three offline tests, type-check, lint, app/worker build and
migrations passed; npm audit reported zero vulnerabilities. Final app, Nginx and
lab PHP-FPM checks were healthy, the listener remained loopback-only, foreign-shop
requests returned 403 and invalid-signature webhooks returned 401.
The embedded UI now previews immutable CAD pricing for 1-50 saved parents and
queues confirmed draft-import jobs. The source branch `feat/draft-import-jobs`
is based on the catalog review branch. A separate non-root
`ultralove-shopify-imports` service processes persistent jobs/items and cross-job
creation claims in the app database. The provisioner takes protected SQLite
backups under `/srv/ultralove-shopify/backups` before additive migrations.
The source-restricted proxy's read-only `import-plan` operation reuses the
unchanged Shopify Supplier plugin's payload builder; Shopify mutations happen
only in the app worker, never through the root PHP importer behind HTTP.
The plugin remains required for this planning code and the original dev sync.

Live checks reused the original five listings with prices, content, publication
state and inventory unchanged. Source `17539` (SKU `SE1414-50 BX`) created draft
`8424579366975`: CAD 7.95 at 25% markup became CAD 9.94, stock 2, barcode
`716770016270`, and one READY media item. Native Shopify admin confirmed it was
not published anywhere. A second 30% preview reused it without repricing or
duplication. An interrupted-job simulation preserved its creation claim and
worker restart recovered the same draft through read-only reconciliation;
exactly one listing existed. All three jobs completed, and the original five
saved selections were restored. The draft and job history are retained for review.
The original three-product sync still passed with retailer prices preserved.
New imports do not enable ongoing selection sync, publication or order forwarding.

Before any pre-jobs code rollback or return to the old app version, run
`systemctl disable --now ultralove-shopify-imports` on the lab host. Preserve the
database, creation claims, backups and additive migrations. Catalog release
`05f1160` is the verified pre-jobs code rollback.
App configuration rollback: restore existing version `ultralove-dropshipping-lab-2` in the
Dev Dashboard, returning to the previous lab URL and `embedded=false`.
Do not uninstall/recreate the app or rotate the worker's credentials.
Hosting release `a419640` failed service read permissions and must not be used
as a code rollback. The verified `a271a77` foundation remains an earlier rollback
without catalog browsing; changing code does not undo persistent migrations.

Following the user's request to use dev's catalog instead of synthetic products,
the old lab cron entry was removed and only its two verified Shopify fixtures
(`LAB-SIMPLE-001`, `LAB-SIMPLE-002`) were archived. Other Shopify products were
not changed. The earlier lab cycle results above are historical; that worker
is now paused.

The new `ultralove-shopify-supplier` plugin is deployed and active on dev, with shared
bridge code extracted to its `includes/class-test-bridge.php`; the old lab
entry point retains a compatibility wrapper in git. The dev pilot uses
existing product IDs `17186`, `17188`, `17192` (SKUs `1513`, `2455`, `70072`),
selected from the existing dev catalog. No synthetic products were created.
Its administrator controls select existing products, an approved retailer and
initial markup, show status/mappings, and expose nonce-protected test tracking
on bridge-created WooCommerce orders. The plugin defaults off and refuses
production even if its options are copied.

The client confirmed that resellers may add **any UltraLove catalog product**
to their own catalog. No per-product dropshipping listing flag or supplier
listing approval is required. Keep reseller product selection separate from
stock/order availability. The three-product selection, 25-product cap, and
simple-product checks are technical pilot limits only; the test connector is
not yet a full-catalog reseller browsing/import interface.

Feature branch `feat/dev-shopify-supplier` / draft PR #138 deployed through the
normal drift-safe pipeline; the first deployment was commit `0a43a88` and added
only the two new supplier PHP files. CI and origin smoke tests passed.
Twenty isolated configuration-policy assertions, PHP syntax checks, and
28 live dev assertions pass, including administrator controls/tracking,
catalog identity, stock changes, mixed-line routing, wholesale attribution,
duplicate protection, tracking return and lost-acknowledgement replay.
All three genuine Shopify imports are drafts with ready images. Verification
removed its own test orders and restored stock to `327`, `125`, and `414`;
retailer-edited prices remain preserved.

A dedicated approved test retailer, `shopify-dev-retailer`, owns forwarded
orders; no existing customer profile was changed. App credentials and logs
are root-only under `/root/.ultralove-shopify-dev/` (directory `0700`,
credential/log files `0600`). `WP_HTTP_BLOCK_EXTERNAL` remains enabled; only
`ultralove-integration-lab.myshopify.com` was appended to the existing allowed
hosts, and an unrelated outbound host was verified blocked. The five-minute
worker is installed at `/etc/cron.d/ultralove-shopify-dev`; an actual
scheduler-triggered cycle succeeded at `2026-10-08T17:05:07+00:00`, confirmed
by its root-only log and `ul_dev_shopify_last_success`.
Email remains suppressed, gateway settings unchanged, and Access/network
isolation intact. No main merge, production deployment, real payment or
shipment occurred. Independent-retailer OAuth and production order handling
remain outside this test workflow; see
[the dev workflow](../ops/dev/README.md#shopify-supplier-test-workflow).

**Legacy development bridge retirement, 2026-10-12:** the old five-minute
WordPress bridge is superseded for catalog imports and inventory by the
UltraLove Dropshipping Lab Shopify app. Duplicate reads with the WordPress
user-agent and unchanged journal cursor came from its constructor plus its
catalog cycle, not pagination by the new app. The guarded retirement operation
archives only `/etc/cron.d/ultralove-shopify-dev`, disables the legacy settings
and persists `ultralove_shopify_supplier_retired`. It waits for the original
bridge lock, preserving completed work; protected backups remain under
`/root/.ultralove-shopify-dev/retirement-*`.
The legacy admin page is read-only and legacy dev CLI/setup/tracking paths
cannot reactivate it. Shared feed/payload code, fixture identities, mappings,
orders, logs and credentials remain intact. The plugin must stay active for the
current restricted catalog proxy; never revoke shared app/feed credentials
as part of this retirement. The earlier paused `source=lab` harness is unchanged.
The current app does not yet replace legacy synthetic order forwarding/tracking.
See the dev workflow for scoped rollback and live verification evidence.

The droplet is billable until explicitly destroyed. Teardown must target only
droplet `607169886`, its lab firewall, tag, and VPC after confirming they have no
other members; never use production/dev cleanup scripts.

### 1.7 Supplier product and inventory feed (dev)

The feed-first milestone adds the separate `ultralove-product-feed` plugin to
dev; implementation revision `ff51422` was deployed through the normal
drift-safe pipeline on `2026-10-08`. It is hard-guarded by the development
environment, exact dev home URL and `wordpress_dev` database. Production is
unchanged. The existing CSV exporter and three-product Shopify test connector
remain separate and their source was not changed by this milestone.

The authenticated read-only namespace is `/wp-json/ultralove-feed/v1`:
`/products`, `/products/{id}`, `/inventory`, `/changes`, and `/openapi`.
The OpenAPI 3.1 contract uses WooCommerce-compatible operational field names
with explicit supplier extensions; it is not a drop-in `wc/v3` replacement.
All published products, including out-of-stock products, can be listed;
there is no per-product eligibility flag or Shopify-pilot catalog cap.
Published variations require published parents and expose shared stock identity.

Native WordPress application passwords authenticate scoped customer accounts
over HTTPS; `ultralove_read_product_feed` grants catalog access independently
of dropshipping approval. Staff grant/revoke access from a nonce-protected
user-profile control. Dev Cloudflare Access still applies. Responses are
`private, no-store`, with a 300-request/account/minute quota and `Retry-After`.
Only whitelisted product data is exposed; unknown stock is null, prices remain
decimal strings, and missing images produce explicit warnings rather than
discarding products.

Three dedicated tables hold feed bookkeeping: `ultralove_feed_changes`,
`ultralove_feed_state`, and `ultralove_feed_rates`. Producers enqueue within
their transactions; a database-locked publisher assigns delivery sequence
numbers only after commit. Clients save the first catalog page's generation
and cursor, finish the initial import, then replay current-state upserts and
removals idempotently. Seven-day contiguous-prefix retention and generation
checks require a full resync when a cursor expires or the journal is rebuilt.
After database restoration, operators explicitly rotate generation and reset
the reconciliation checkpoint.

`/etc/cron.d/ultralove-product-feed` runs a 500-record reconciliation batch
every minute, refreshing product caches to detect changes that bypass hooks.
A completed scan also detects removals. The initial full scan covered all
6,430 published products in 13 batches/105.257 seconds and completed at
`2026-10-08T18:24:33+00:00`, with 6,430 fingerprint records and checkpoint zero.
The scheduler was installed at `2026-10-08T18:24:34Z`; its root-only log is
bounded by `/etc/logrotate.d/ultralove-product-feed` (daily, seven rotations,
5 MB maximum-size trigger). Runtime credentials and evidence are under
`/root/.ultralove-product-feed/`, directory `0700`, private files `0600`.
Actual cron launches at `18:25:01` and `18:26:01` each successfully processed
500 records; the checkpoint advanced to product ID 22460 without manual runs.

Verification passed 335 isolated feed assertions, 20 existing supplier-policy
assertions, schema validation and 33 live HTTP checks against the full genuine
catalog. Dev has zero published variations; isolated tests cover their DTOs
and stock inheritance. Six benchmark checks under five concurrent clients
measured cold catalog/inventory pages at 0.572/0.380 seconds and warm p95 at
1.176/0.779 seconds, below the two-second target. Thirty-five products have
pre-existing missing attachment references; these remain importable with
`data_warnings`, without modifying source media. Read-only traversal preserved
CSV bytes; mutation tests restored stock/status and account scope. The existing
Shopify scheduler continued succeeding at `2026-10-08T18:25:06+00:00`.

No synthetic products, orders, real payment, email, shipment, main merge or
production deployment was introduced by this feed milestone. Shopify adaptation
and transactional dropshipping remain future phases. See the
[feed runbook](../ops/dev/README.md#supplier-product-and-inventory-feed) and
[OpenAPI contract](../wp-content/plugins/ultralove-product-feed/openapi.json).

## 2) Prod App Droplet (PRODUCTION-WEB-ultralove.ca)

### 2.1 OS / Platform

- DigitalOcean droplet PRODUCTION-WEB-ultralove.ca (ID 605458201), s-4vcpu-8gb (4 vCPU, 8 GB RAM, 160 GB disk), sfo2
- OS: Ubuntu 26.04.1 LTS, kernel 7.0.0-31-generic, x86_64
- System hostname: `ultralove-prod-web` (set 2026-10-02; the SSH alias is still `ultralove-prod-app`; `/etc/cloud/cloud.cfg.d/99-ultralove-hostname.cfg` stops
  cloud-init resetting it)
- Public IPv4 165.22.169.132 (also its outbound address); reserved IP 167.99.26.122; private IPv4 10.120.0.4
- DO features: monitoring, droplet agent, private networking (VPC 13c4a945-3cb1-4b91-a483-ce0364f55dba); no IPv6
- Tags: Production, nginx, php, php-fpm, wordpress
- SSH: alias `ultralove-prod-app` (deploy tooling: `deploy/envs/prod.env`)

### 2.2 Core Services and Runtime

- nginx 1.28.3
- PHP 8.5.4: `php8.5-fpm` (OPcache is built into PHP since 8.5 - there is no separate package)
- Redis 8.0.5 (local object cache)
- fail2ban, sysstat, atop, AWS CLI v2 (for Spaces), WP-CLI
- No certbot: TLS is a Cloudflare Origin CA certificate (2.4)

PHP-FPM pool (`ops/php/www.conf`), sized for 8 GB from measurements on the previous droplet (workers ~160-190 MB;
at most 10 busy at once):

- pm dynamic, max_children 24, start 6, spares 4-10, process_idle_timeout 5s, max_requests 500
- status page `/status` (served as http://127.0.0.1/fpm-status by `localhost-admin.conf`)
- slow log `/var/log/php8.5-fpm.www-slow.log` at 5s (rotated weekly); request_terminate_timeout 45s

PHP settings (`ops/php/99-ultralove-production.ini`): memory_limit 384M, max_execution_time / max_input_time 300,
max_input_vars 3000, post/upload 100M, expose_php off; OPcache 512 MB, 64 MB interned strings, 100k files,
`validate_timestamps=0` (deploys restart PHP-FPM); realpath cache TTL 600 s (size stays at PHP's 4M default); tracing JIT with a 64 MB buffer.

Measured on 2026-10-02 (requests on the droplet itself): uncached catalog pages 1.5-2 s each, as on the previous
droplet; 8 concurrent clients -> 3.5 req/s, p95 3.2 s, no errors, at most 9 workers busy, ~1.3 GB RAM used.

### 2.3 Network Exposure and Firewall (UFW)

- Default: deny incoming, allow outgoing
- Allowed: 22/tcp from anywhere (SSH); 80,443/tcp **only from Cloudflare's IPv4 ranges** (15 rules, from
  https://www.cloudflare.com/ips-v4 at bootstrap)
- Listeners: nginx 0.0.0.0:80/443, sshd :22, Redis 127.0.0.1/::1:6379 (not public)
- nginx takes the visitor's IP from `CF-Connecting-IP` for Cloudflare's ranges (`conf.d/20-cloudflare-real-ip.conf`),
  so logs and the per-IP rate limits see visitors, not Cloudflare (the previous droplet logged Cloudflare's IPs).

### 2.4 Key Configuration Locations

| What | Path on the droplet | Source in the repo |
|---|---|---|
| nginx site | /etc/nginx/sites-available/ultralove.ca.conf | `ops/nginx/ultralove.ca.conf` |
| nginx http tuning (keepalive, 100M uploads, gzip) | /etc/nginx/conf.d/10-ultralove-http.conf | `ops/nginx/10-ultralove-http.conf` |
| nginx FastCGI page cache (anonymous HTML, 5 min; needs `mkdir -p /var/cache/nginx`) | /etc/nginx/conf.d/11-ultralove-page-cache.conf | `ops/nginx/11-ultralove-page-cache.conf` |
| Cloudflare client IPs | /etc/nginx/conf.d/20-cloudflare-real-ip.conf | generated by the bootstrap |
| Rate-limit zones | /etc/nginx/conf.d/99-ultralove-rate-limit-zones.conf | `ops/nginx/99-ultralove-rate-limit-zones.conf` |
| FPM status page (127.0.0.1 only) | /etc/nginx/sites-available/localhost-admin.conf | `ops/nginx/localhost-admin.conf` |
| Unknown hostnames (444) | /etc/nginx/sites-available/default | written by the bootstrap |
| TLS (Cloudflare Origin CA, expires 2041-09-21) | /etc/ssl/cloudflare/ultralove.ca.{pem,key} | not in the repo (secret); in the monthly backup |
| PHP-FPM pool | /etc/php/8.5/fpm/pool.d/www.conf | `ops/php/www.conf` |
| PHP settings | /etc/php/8.5/fpm/conf.d/99-ultralove-production.ini | `ops/php/99-ultralove-production.ini` |
| Redis | /etc/redis/redis.conf (maxmemory 1gb, allkeys-lfu, appended) | written by the bootstrap |
| WordPress config | /var/www/html/wp-config.php | not in the repo (secret); in the monthly backup |
| WP cron runner | /etc/cron.d/ultralove-wp-cron | written by the bootstrap |
| Backup scripts | /root/monthly_system_backup.sh, /root/uploads_spaces_sync.sh | `ops/prod/` |
| Spaces credentials | /root/.spaces_env | not in the repo (secret) |
| Deploy state | /var/lib/ultralove-deploy (deployed_sha, deploy.log, backups/) | written by `deploy/deploy.sh` |

### 2.5 Important Permissions Snapshot (2026-10-02)

- 644 root:root /etc/nginx/sites-available/ultralove.ca.conf
- 644 root:root /etc/php/8.5/fpm/pool.d/www.conf, /etc/php/8.5/fpm/conf.d/99-ultralove-production.ini
- 640 redis:redis /etc/redis/redis.conf
- 600 www-data:www-data /var/www/html/wp-config.php
- 600 root:crontab /var/spool/cron/crontabs/root
- 700 root:root /root/monthly_system_backup.sh, /root/uploads_spaces_sync.sh
- 600 root:root /root/.spaces_env, /etc/ssl/cloudflare/ultralove.ca.key
- 644 root:root /etc/ssl/cloudflare/ultralove.ca.pem

### 2.6 Access and Deployment

- Code reaches the servers only through `deploy/deploy.sh` (README "Deploying"; the `ultralove-deploy` skill):
  PR -> CI (PHP 8.5 lint, shellcheck, no dumps/archives) -> merge -> the "Deploy to dev" Action deploys `main` to
  dev -> `deploy/deploy.sh prod` from a trusted machine, which refuses commits not on `origin/main` and live on
  dev. What the repo owns on the servers: `deploy/owned-paths.rules`.
- CI secrets live only in the GitHub `dev` environment (`DEV_SSH_KEY`, `DEV_SSH_KNOWN_HOSTS`, `DEV_HOST`); no CI
  job can reach prod.
- Prod -> dev push (`ops/dev/refresh-db.sh`, `sync-code.sh`): prod's key `/root/.ssh/ultralove-dev-push`, accepted
  on dev only from prod's public IP (`restrict`); dev has no access to prod.

## 3) WordPress Application Layer

### 3.1 Core

- WP version: 6.9.4
- Active theme:
  - stylesheet: martfury-child
  - template: martfury

### 3.2 Database Connectivity

- DB_NAME: wordpress
- DB_HOST: the managed MySQL cluster's private VPC hostname (db-mysql-sfo2-16643; value in wp-config.php)
- DISABLE_WP_CRON: true - WP-Cron runs from the system cron every 5 minutes (`/etc/cron.d/ultralove-wp-cron`)

### 3.3 Active Plugins (live on production, 2026-10-02)

Custom (in the repo): hide-price-until-login, ultralove-backdrops (1.6.0), ultralove-banners (2.1.3),
ultralove-product-export-sync, ultralove-product-stock-export.

Vendor: advanced-custom-fields-pro, advanced-woo-labels, am-collect-media, autoptimize, back-in-stock-notifier-for-woocommerce,
cloudflare, cloudinary-image-management-and-manipulation-in-the-cloud-cdn, contact-form-7,
custom-shipping-methods-for-woocommerce, gravityforms, gravityformsturnstile, gravityformsuserregistration, hide-updates,
kirki, mailchimp-for-wp, mailgun, martfury-addons, meta-box, ni-woocommerce-custom-order-status,
quantity-field-on-shop-page-for-woocommerce, redirection, redis-cache, relevanssi, relevanssi-live-ajax-search,
support-webp, updraftplus, user-role-editor, user-switching, widget-shortcode, woo-add-gtin, woo-update-manager,
woocommerce (10.7.0), woocommerce-extra-product-sorting-options, woocommerce-xml-csv-product-import, wp-all-export-pro,
wp-all-import-pro, wp-useronline, wpae-woocommerce-add-on, yith-woocommerce-wishlist (4.14.0).

Drop-in: `object-cache.php` (Redis). No longer active since the April inventory: elementor (removed 2026-09-29; its
`_elementor_data` post meta is still in the database, unused), revslider (banners are UltraLove Banners),
admin-site-enhancements, woocommerce-services, product-import-export-for-woo and the wt-import-export-for-woo add-ons.
The MU plugin `10-elementor-font-display-swap.php` (Elementor's Google Fonts) was removed with Elementor's other leftovers.

### 3.4 Custom / Site-Specific Code

- Custom plugin present: /var/www/html/wp-content/plugins/hide-price-until-login
- MU plugin (ManageWP worker loader): /var/www/html/wp-content/mu-plugins/0-worker.php
- MU plugin (session close helper): /var/www/html/wp-content/mu-plugins/00-close-php-session-for-rest.php
  - Closes active PHP sessions on init/admin_init/rest_api_init and before outbound HTTP requests.
  - Likely intended to reduce blocking/lock contention during REST/loopback calls.

### 3.5 Local Modifications to Vendor Plugins (lost on update)

- Cloudinary plugin 3.3.2: cloud-name regex fix, an "Unsync all Cloudinary media" bulk action and
  `wp cloudinary unsync` (see `ops/dev/README.md`). Re-apply or re-check after a Cloudinary update.
- (Slider Revolution's Cloudinary URL rewriting, section 4.5, is gone with the plugin - it's no longer on disk.)

### 3.6 Removed Plugins and Leftovers (2026-10-02)

Deleted from production: admin-site-enhancements, nelio-ab-testing, woocommerce-services, product-import-export-for-woo
and the WebToffee add-ons wt-import-export-for-woo, -product and -product_review (all inactive), plus their leftover
options and tables (`wp_wt_iew_*`), and Elementor's leftovers: 44 `elementor_library` templates, all `_elementor*` post
meta, `elementor*` options, the `wp_e_events` table and `uploads/elementor/`. Backups taken first, on production in
`/root/backups/`: `db-before-cleanup-20261002.sql.gz` (full database) and `inactive-plugins-20261002.tgz`.
Dev still has them until its next refresh from prod (`ops/dev/refresh-db.sh`, `sync-code.sh`).

## 4) Caching and Performance Architecture

Layers, outside in (current):

1. **Cloudflare edge:** APO caches anonymous HTML; static assets cached with the origin's headers (Browser Cache TTL
   is *Respect Existing Headers* since 2026-09-14 - it had been cutting the origin's 1-year header to 4 hours).
2. **nginx:** gzip and long-lived static-asset headers, plus a 5-minute FastCGI cache of anonymous HTML that Cloudflare APO
   skips (query-string URLs such as `?orderby=`, crawlers, APO misses). Logged-in, cart, wishlist-session and comment
   cookies, POSTs, ajax, wp-json and cart/checkout/my-account bypass it; `X-Cache` shows HIT/MISS/BYPASS; `deploy.sh`
   empties it (section 2.4, `ops/nginx/`).
3. **WordPress / PHP:** Redis object cache (4.1) and OPcache (4.2).
4. **Front-end assets:**
   - **Autoptimize:** CSS aggregated with inline CSS included (`autoptimize_css_aggregate`,
     `autoptimize_css_include_inline` = `on`), placed at the end of `<head>` after the preloads. JS is minified per
     file, **not aggregated** (aggregation was a measurable regression, 4.11). Cart, checkout and my-account aren't
     optimized.
   - **Critical CSS** (`34-critical-css.php` + `martfury-child/css/critical.css`, logged-out visitors only): the first
     screen's CSS inlined, the full stylesheet loaded without blocking. Generated by `ops/critical-css/` (the
     "Critical CSS" workflow), never hand-edited. The Backdrops plugin's CSS stays inline and out of it.
   - **Trimmed stylesheets:** purged copies of Martfury's, the icon fonts' and WooCommerce's CSS under their
     original handles (Martfury 660 KB -> ~240 KB), built by `ops/css/`; regenerate after a Martfury or WooCommerce
     update.
   - `31-trim-frontend-scripts.php` / `33-trim-frontend-styles.php` drop unused JS/CSS (PhotoSwipe only on product
     pages, no Google Fonts, no block CSS); `32-defer-jquery.php` defers jQuery on optimized pages.
   - Fonts: Work Sans (two Latin files) and the icon font are self-hosted and preloaded.
   - Images: Cloudinary delivery where synced; `30-woocommerce-loop-image-sizes.php` gives grid images correct
     `sizes`.

Rollbacks:

| Change | Undo |
|---|---|
| Autoptimize entirely | `wp plugin deactivate autoptimize`, then purge Cloudflare |
| CSS aggregation | set `autoptimize_css_aggregate` and `autoptimize_css_include_inline` to `''`, `wp autoptimize clear` |
| Critical CSS | empty `critical.css` and deploy (pages fall back to the normal stylesheet) |
| Any MU plugin | revert in git and deploy (PHP-FPM restarts automatically) |

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

- OPcache (built into PHP 8.5): 512 MB, 64 MB interned strings, 100,000 files, `validate_timestamps=0` - code only
  changes through `deploy/deploy.sh`, which restarts PHP-FPM. Set in `ops/php/99-ultralove-production.ini`.
- The previous droplet also set `opcache.huge_code_pages` and `opcache.file_cache`; neither did anything there
  (no huge pages reserved; the cache directory didn't exist), so they weren't carried over.

### 4.3 nginx Caching/Compression Notes

Current configuration: section 2.4 (repo: `ops/nginx/`). The dated notes below record how it got there on the
previous droplet; they still apply, except that nginx 1.28 uses `http2 on;` (the 1.24 `listen 443 ssl http2;` form
is deprecated) and certbot is gone (Cloudflare Origin CA certificate).

- gzip is enabled in /etc/nginx/nginx.conf.
- As of 2026-09-14: `gzip_types` was previously commented out entirely in `/etc/nginx/nginx.conf`, so only nginx's default `text/html` was being compressed — CSS, JS, JSON, SVG, and font responses were served uncompressed. Fixed by setting an explicit `gzip_types` list (css, js, json, xml, svg, fonts, wasm), enabling `gzip_vary on` and `gzip_proxied any`, and raising `gzip_comp_level` from 3 to 5.
- As of 2026-09-14: added a dedicated static-asset `location` block in `/etc/nginx/sites-available/ultralove.ca.conf` matching css/js/mjs/images/fonts, sending `Cache-Control: public, max-age=31536000, immutable` and `expires 365d`. Previously no static assets had any explicit browser-cache headers set at the origin.
- As of 2026-09-14: enabled HTTP/2 on the origin TLS listener (`listen 443 ssl http2;`). Note this nginx build is 1.24.0, which predates the newer `http2 on;` directive syntax (introduced in 1.25.1) — that syntax fails `nginx -t` on this host. Real-user traffic already gets HTTP/2/3 from Cloudflare's edge regardless of the origin listener; this change mainly benefits direct-origin access and Cloudflare-to-origin efficiency.
- Scope note: `cart/`, `checkout/`, `my-account/`, and `wp-admin/` routes are explicitly excluded from caching in this same vhost (`Cache-Control: no-store, no-cache, must-revalidate, max-age=0`), so those are exactly the requests that bypass Cloudflare's edge cache and hit the origin directly. The gzip fix benefits those WooCommerce hot paths most — Cloudflare already recompresses/serves cached anonymous pages (e.g. the homepage) at the edge regardless of the origin `gzip_types` gap, so that fix has limited effect on the anonymous homepage experience specifically.
- Config backups from immediately before this change are kept at `/root/nginx-backups/` on prod-app (timestamped copies of `nginx.conf` and `ultralove.ca.conf`).
- No explicit fastcgi_cache/proxy_cache configuration detected in inspected nginx configs (unchanged — Cloudflare APO/edge cache handles anonymous HTML caching instead; see 5.1).
- Long expires policy detected for uploads/files PHP-blocking location in one alternate vhost file (pre-existing note, unchanged).
- Bug found and fixed same day (2026-09-14): the static-asset `add_header Cache-Control ... always;` line applied the 1-year cache header to *every* response code, including 404s, because `always` overrides nginx's default restriction of `add_header` to success/redirect status codes. Confirmed via `curl` that a nonexistent asset URL returned `HTTP/2 404` with `cache-control: public, max-age=31536000, immutable`. Fixed by removing `always` from that one `add_header` line (the `expires 365d;` directive on the same block was never affected — it already followed nginx's default status-code restriction). Verified live: a 404 on the same location block no longer carries the cache header, a real static asset still does. Note Cloudflare had already cached one bad 404 response (from an earlier manual test hitting a wrong path) with the buggy header before the fix landed — that specific stale edge-cached entry may persist for a while, but it is not a URL any real page links to, so no live traffic is affected.
- Found and fixed same day (2026-09-14): Cloudflare's zone-level Browser Cache TTL was overriding the origin's `Cache-Control: max-age=31536000` down to `max-age=14400` (4 hours) on cache-HIT responses for real, referenced static assets (confirmed via `curl` on `wp-includes/js/jquery/jquery.min.js` and `wp-content/themes/martfury/style.css` — both returned `cache-control: max-age=14400` with `cf-cache-status: HIT`). This was the single largest remaining performance opportunity by estimated savings (~1MB per PageSpeed Insights). Fixed via the Cloudflare dashboard (Caching → Configuration → Browser Cache TTL, set to **Respect Existing Headers**, available even on this zone's Free plan) rather than the API — credential access was intentionally avoided this session; the fix was applied directly through an already-authenticated browser session instead. Verified via Custom Purge on the two test URLs above, followed by a fresh `curl`: `cf-cache-status: MISS` on the re-fetch, then `cache-control: public, max-age=31536000, immutable` correctly reaching the client with no override.
- Side effect found and fixed during that verification: the static-asset block had both an explicit `add_header Cache-Control ...` and an `expires 365d;` directive, which nginx emits as two separate `Cache-Control` response headers (harmless but redundant/non-standard). Removed the `expires` line, keeping only the explicit `add_header`, since it already specifies `public` and `immutable` which `expires` alone does not add. Verified live: single clean `Cache-Control` header, gzip still applies, cart/my-account still correctly `no-store`.

### 4.4 Elementor Generated CSS Caveat (historical: Elementor was removed on 2026-09-29)

- Elementor page-specific CSS is generated under `wp-content/uploads/elementor/css/`.
- A stale or missing generated file can make frontend styling appear inconsistent between guest and logged-in sessions, especially when caches/CDN or version-stripped asset URLs produce different cache keys.

### 4.5 RevSlider Cloudinary Image Optimization

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

### 4.6 Performance Audit (2026-09-14)

Ran a Lighthouse/PageSpeed Insights audit against `https://ultralove.ca/` to identify low-hanging performance fixes.

Baseline results:
- CrUX field data (real users, desktop, 28-day window): Core Web Vitals **Failed** — LCP 4.1s, TTFB 3.5s, CLS 0.26, INP 45ms.
- Lighthouse lab data (mobile, Moto G Power emulation, Slow 4G throttling): Performance score ~47-53/100, LCP 17.7s, total page weight 6.4MB.

Fixes applied and verified (see 4.3 for the nginx-side detail):
- nginx `gzip_types` fix, static-asset `Cache-Control`/`expires` headers, HTTP/2 on the origin listener.
- Verified live via `nginx -t` before reload, then `curl` checks confirming `content-encoding: gzip` on JS/CSS, `cache-control: public, max-age=31536000, immutable` on static assets, and that `cart/`, `my-account/` responses still correctly send `no-store`/`private` (unaffected by the new caching rule).

Findings not yet acted on at the time of the initial audit (WordPress/application-layer, higher-impact but higher-risk — needs a deliberate follow-up rather than a same-session fix):
- 48 active plugins (corrected count — an earlier pass in this same audit undercounted this as 46), including several with overlapping import/export functionality — see 4.7 for the plugin audit and cleanup performed the same day.
- No CSS/JS minification, combination, defer, or unused-code-removal plugin active (no Autoptimize/Perfmatters/WP Rocket-equivalent) — this is the largest single driver of the 6.4MB page weight and Lighthouse's ~4.25s "render-blocking requests" finding. **Resolved same day — see 4.8.**
- Elementor reports 62 animated elements on the homepage — likely theme/demo-content entrance animations left enabled, contributing to both script weight and the field CLS score of 0.26. Still open; Elementor also controls page layout rendering site-wide, so any change here needs care and should not touch the plugin/layout engine itself, only the animation setting.
- Redis object cache, Cloudflare, and Cloudinary are already correctly in place and not implicated in these findings — the gap is specifically plugin/theme-level asset bloat, not the caching/CDN layer covered elsewhere in this section.

Recommended next phase (not yet scheduled): evaluate/install a CSS-JS optimization plugin compatible with Elementor + Martfury (done same day, see 4.8), and review/reduce Elementor's global entrance-animation usage (still open).

### 4.7 Import/Export Plugin Audit and Cleanup (2026-09-14)

Followed up the same day on the plugin-count finding in 4.6 by auditing the active import/export plugins for overlap.

Of the 10 plugins with import/export-adjacent naming, most turned out not to be redundant:
- `wp-all-import-pro`, `wp-all-export-pro`, `woocommerce-xml-csv-product-import` (WP All Import's WooCommerce add-on), and `wpae-woocommerce-add-on` (WP All Export's WooCommerce add-on) are four parts of one Soflyy system, all required companions of each other. Confirmed actively used by the client — kept.
- `ultralove-product-export-sync` and `ultralove-product-stock-export` are custom in-house plugins (author: UltraLove) purpose-built for CSV export automation and fast direct-DB stock export — kept.

The remaining four were a separate, dormant **WebToffee** toolchain doing the same WooCommerce product/review import-export job as the Soflyy suite above:
- `wt-import-export-for-woo` (wrapper), `wt-import-export-for-woo-product` (product add-on), `wt-import-export-for-woo-product_review` (review add-on), and `product-import-export-for-woo` (WebToffee's own separate standalone/basic edition of the same tool — a third overlapping WebToffee install).

Evidence used to confirm these were safe to deactivate:
- DB table `wp_wt_iew_action_history`: last action **2026-07-20** (~2 months before this audit).
- DB table `wp_wt_iew_cron`: 0 rows — no active scheduled WebToffee jobs.
- By contrast, `wp_pmxi_history` (WP All Import): last run **2026-09-10**. `wp_pmxe_exports.last_activity` (WP All Export): **2026-09-11**. Both in active, current use.
- Checked the WebToffee plugins' front-end-facing code (`public/class-wt-import-export-for-woo-public.php`): the JS enqueue is explicitly commented out with the note *"For the time being this file is not needed as we are not doing any front end activities."* — confirms these never affected front-end page weight/performance; the value in removing them is wp-admin clutter, background PHP overhead on every request, and not keeping three stale, unpatched commercial plugins active.

Action taken: deactivated (not deleted) all four WebToffee plugins via `wp plugin deactivate`. Active plugin count dropped from 48 to 44. Verified live: homepage/shop return 200, wp-admin login redirect (302) unaffected, WP All Import/Export plugins remain active, and nginx error log shows only pre-existing unrelated WooCommerce core deprecation notices (`wc_enqueue_js`), not new errors from the deactivation.

Not yet done: deleting the plugin files themselves. Deactivation is fully reversible from the WordPress admin at any time; deletion should wait for a monitoring period.

#### Catalog CSV consolidation (2026-10-09)

The standard retailer and Shopify product CSVs have one owner:
`ultralove-product-export-sync`, displayed as **UltraLove Product CSV Export**
version 1.1.0. Its installation path, option keys and job hooks are preserved.
**WooCommerce > Product CSV Export** provides generation status, both downloads,
and a nonce-protected **Queue Export Now** action. The old WooCommerce
**Update Product CSV** and Tools **Product Export Generations** pages redirect
to this page without adding visible duplicate menu entries.

The child theme's separate full-catalog writers and manual export form were
removed. The plugin remains responsible for debounced product/stock-change
updates and the 15-minute fallback; it clears obsolete
`ultralove_refresh_product_exports_hourly` events, including events with
arguments. Customer-account download links, CSV filenames, column order,
pagination, gallery rows, and Shopify draft status are preserved.

This does **not** merge or disable the distinct stock-only plugin,
`[exportstock]` shortcode, WP All Import/Export suite, authenticated product
feed, manual dropshipping, or Shopify supplier connector. CSV downloads still
serve customers independently of the API feed, which is inactive on production.
The four legacy WebToffee plugins remain inactive on dev and absent on production.
No active dev/production plugin was made redundant by the menu/code consolidation.

Verification: `php ops/product-export/test.php` (also run in CI), followed by
`wp eval-file - --allow-root < ops/product-export/verify.php` on dev.
Keep the existing export-sync plugin active when deploying the cleaned-up
theme. Rollback restores the preceding plugin/theme release through the normal
deployment pipeline; no plugin activation migration or source product edits
are required.

### 4.8 CSS/JS Optimization Plugin — Autoptimize (2026-09-14)

Followed up the same day on the largest remaining finding from 4.6 (no CSS/JS minification/combination plugin active) by installing and configuring **Autoptimize** (free, v3.1.15.1) directly on production, as a staged rollout rather than testing on the dev environment first.

**Configuration chosen (deliberately conservative for a first rollout):**
- `autoptimize_css = 1`, `autoptimize_js = 1` — both enabled.
- `autoptimize_css_aggregate = ""`, `autoptimize_js_aggregate = ""` — aggregation/combination left **off**. Autoptimize still minifies each file individually and rewrites it to a cached, hashed URL (`wp-content/cache/autoptimize/autoptimize_single_<hash>.php`), but does not combine multiple files into one bundle. This was a deliberate choice to get the minification win with much lower risk of script-order/dependency breakage, appropriate for a first production rollout.
- `autoptimize_css_exclude = "elementor"`, `autoptimize_js_exclude = "elementor"` — Elementor's own CSS/JS (`webpack.runtime.min.js`, `frontend-modules.min.js`, `frontend.min.js`, and the theme's `martfury-addons/elementor.js`) are excluded from optimization entirely and served untouched, since Elementor controls page layout site-wide and is the highest-risk plugin to interfere with.

**Bug found and fixed the same day: JS optimization was completely inert.** After configuration, CSS optimization worked immediately (verified via cached `autoptimize_single_*.php` URLs replacing original stylesheet links), but JS files were being served completely unprocessed — the JS cache directory (`wp-content/cache/autoptimize/js/`) stayed empty. Root-caused via direct `wp eval` testing of `autoptimizeScripts::read()` against real page HTML fetched straight from the origin (bypassing Cloudflare) with the exact options array production uses:
- Config values, per-post overrides, Autoptimize's and Elementor's own mutual compatibility shims, the `dontmove` exclusion list, and the script-tag regex used by `read()` were all individually tested and ruled out — each behaved correctly in isolation.
- The actual cause: the **Nelio A/B Testing** plugin ships its own Autoptimize compatibility file (`wp-content/plugins/nelio-ab-testing/includes/hooks/compat/cache/autoptimize/index.php`) that hooks `autoptimize_filter_js_exclude` and force-sets `autoptimize_filter_js_minify_excluded` to `__return_false` on every request. Because these are live WordPress filter hooks (not raw option values), they only manifest through the real request pipeline — an isolated test that reads Autoptimize's option values directly, without also triggering `apply_filters()`, will not reproduce the problem, which is why the first round of isolated `read()` testing appeared to succeed.
- Checked Nelio A/B Testing for any in-flight experiment before touching it: one experiment existed (`improved thumbnails`, post type `nab_experiment`) but its status was `nab_ready` (configured, not currently running) — no active test in flight. Deactivated the plugin (`wp plugin deactivate nelio-ab-testing`; not deleted, fully reversible). JS optimization then worked immediately on the next request with no other change required.

**Verification performed after the fix:**
- Confirmed live, direct-from-origin (bypassing Cloudflare) that JS files are now served via hashed `autoptimize_single_*.php` URLs, correctly minified, with valid content-type headers (`text/javascript`) and intact content (spot-checked `fitvids.min.js` output byte-for-byte against source, no truncation/corruption).
- Confirmed Elementor's JS/CSS remain completely untouched (still served from their original theme/plugin paths, not rewritten by Autoptimize).
- Confirmed via Cloudflare edge (`cf-cache-status: MISS` on a live fetch through `https://ultralove.ca/`) that real visitors are already receiving the optimized JS — no Cloudflare purge was needed since the new asset URLs are unique hashed paths Cloudflare had never cached before.
- Spot-checked homepage, `/shop/`, `/cart/`, `/my-account/` — all return `200` with no new errors.

**Rollback procedure (if a problem surfaces later):**
1. `wp plugin deactivate autoptimize --allow-root` — immediately stops all CSS/JS rewriting; WordPress reverts to serving original, unoptimized asset URLs on the very next page load. Fully reversible, no data loss.
2. Optionally clear Autoptimize's own disk cache: `wp eval 'autoptimizeCache::clearall();' --allow-root`, or delete the contents of `wp-content/cache/autoptimize/` directly (safe — Autoptimize regenerates it automatically if reactivated).
3. Purge the Cloudflare cache for the site (Custom Purge in the dashboard, or `wp cache flush --allow-root` plus a Cloudflare purge) if any stale optimized-asset HTML has been cached at the edge in the meantime.
4. No nginx, database schema, or theme changes were made as part of this work — rollback is entirely a WordPress plugin-level action, isolated from the rest of the stack.

**Still open / not yet done:** aggregation (combining multiple CSS/JS files into fewer requests) remains off — the biggest additional win still available from Autoptimize, deferred pending a monitoring period on the current conservative configuration. Re-run PageSpeed Insights after a monitoring period to quantify the actual improvement.

### 4.9 Autoptimize JS Aggregation Enabled (2026-09-14)

Followed up the same day after noticing the homepage was still loading ~79-87 individual JS requests despite 4.8's minification work — expected, since aggregation had deliberately been left off in the initial conservative rollout (minification reduces file size per request, not request count).

**Change made:** enabled `autoptimize_js_aggregate` (combines eligible JS files into one bundle). Also enabled `autoptimize_js_trycatch`, which wraps each aggregated script segment in its own `try/catch` so one broken script can't take down the rest of the bundle — an extra safety net specifically for this first aggregation rollout. Elementor's own JS remains excluded from the bundle (unaffected by this change, still served untouched from its original path).

**Result:** homepage script requests dropped from 79 distinct `<script src>` tags to 14 — one aggregated bundle (~593KB, minified) covering jQuery, WordPress core dist scripts, Gravity Forms, and Slider Revolution, plus the still-separately-served Elementor files and one individually-minified theme file (`martfury-addons/elementor.js`, excluded from the bundle by name-match but still minified standalone, unchanged from 4.8's behavior).

**Verification performed:**
- Downloaded the aggregated bundle and syntax-checked it with `node --check` — valid, not truncated (confirmed the trailing `try/catch` wrapper closes cleanly).
- Checked every script/asset URL referenced on the live homepage via `curl` — all return `200`.
- Loaded the site in a real browser (homepage, a product page, and the shop archive) and read the console for errors. Found one recurring `TypeError: Cannot read properties of null (reading 'addEventListener')`, present identically on all three pages tested, at a fixed line in an inline footer script that looks for `document.getElementById('exportMe')` / `getElementById('export')` (an unrelated CSV-export feature apparently enqueued sitewide rather than only where those elements exist). Confirmed via git-independent evidence (identical error, same code shape, present on every page including ones untouched by today's changes) that this is a **pre-existing bug unrelated to the Autoptimize/aggregation change**, not a regression — it's a self-contained inline script that doesn't depend on or interact with any bundled/aggregated file, so it fails the same way with or without aggregation. Not fixed as part of this work since it's outside the scope of the performance task; flagged below as a separate item for the client/dev team to address.
- Visually confirmed the "On Sale" and "Latest Adult Products" carousels, product image galleries, and product detail pages all render correctly with the aggregated bundle in place.
- Could not fully test the add-to-cart/checkout JS flow end-to-end, since this is a login-gated wholesale site (pricing and cart require a registered account) and no test credentials were available in this session. Nothing observed suggests cart JS is affected — the add-to-cart button markup and handlers are unrelated to the files now being aggregated — but this should be spot-checked with a real login when convenient.

**Rollback:** identical to 4.8 — `wp plugin deactivate autoptimize --allow-root` fully reverts both minification and aggregation in one step. To back out only aggregation while keeping minification, set `autoptimize_js_aggregate` back to empty via `wp option update autoptimize_js_aggregate "" --allow-root` and clear Autoptimize's cache.

**Separate finding, not part of this work:** the sitewide inline "CSV export" script referenced above throws a console error on every page because its target elements don't exist outside whatever admin/reporting page it was written for. Harmless to shoppers (self-contained, doesn't block other scripts) but worth a cleanup pass — likely just needs to check the elements exist before attaching the listener, or be scoped to load only on the intended page.

### 4.10 Important Caveat: JS Optimization Looks "Off" When Logged In (2026-09-14)

Came up the same day when checking shop/product pages and initially appearing to see no optimized JS there at all, unlike the homepage. Root cause: **Autoptimize disables all JS optimization sitewide for any logged-in user who can edit posts**, on every page — not just page-specific, and not just inside the Elementor editor.

This comes from Autoptimize's own built-in compatibility shim for Elementor (`wp-content/plugins/autoptimize/classes/autoptimizeCompatibility.php`, line ~35):

```php
if ( defined( 'ELEMENTOR_VERSION' ) && is_user_logged_in() && current_user_can( 'edit_posts' ) && apply_filters( 'autoptimize_filter_compatibility_editelementor_active', true ) ) {
    add_filter( 'autoptimize_filter_js_noptimize', '__return_true' );
}
```

The plugin's own comment explains the intent: *"Edit with Elementor in frontend admin menu (so for editors/administrators) needs JS opt. disabled to appear & function."* It fires whenever a logged-in, edit-capable user views **any** frontend page — simply so the "Edit with Elementor" link in the admin toolbar always works, regardless of which page they're currently viewing. It is unrelated to the separate Elementor-side shim in `elementor/includes/compatibility.php`, which is scoped only to actual Elementor edit-mode.

**Practical implication:** if you (or any admin/editor account) check the site's JS optimization while logged into wp-admin, it will look completely absent — on every page, homepage included — even though it's fully active. This is expected, by design, and not a bug. **Verified as a logged-out visitor** (the actual shopper experience) that home, `/shop/`, and a product page all correctly serve their own aggregated/minified JS bundle. Always verify Autoptimize's output using a logged-out session (incognito window, or `curl` without cookies) — checking while logged in will give a false negative.

### 4.11 JS Aggregation Reverted — Caused a Measurable Performance Regression (2026-09-14)

Ran a fresh PageSpeed Insights pass after 4.9/4.10 to get an updated baseline and pick the next optimization target. Result was worse than expected: **Performance score 33/100** (down from the post-audit baseline of ~47-53), driven by **Total Blocking Time of 1,030ms** — LCP had actually improved to 13.8s (from 17.7s originally), but the TBT spike was severe enough to tank the composite score.

Hypothesis: combining ~65 separate script files into one ~593KB bundle (4.9) meant the browser had to execute that much JavaScript as a much larger, more continuous block of main-thread work. Under Lighthouse's mobile CPU throttling (4x slowdown), this shows up as a large Total Blocking Time increase — a known tradeoff of file aggregation that doesn't always pay off, especially on a page with this much third-party/plugin JS. Fewer requests does not necessarily mean less main-thread cost.

**Confirmed via controlled A/B test:** disabled `autoptimize_js_aggregate` (kept minification, `autoptimize_js` and `autoptimize_js_exclude` unchanged), cleared Autoptimize's cache and the object cache, and re-ran PageSpeed Insights on the same URL immediately after:

| | Aggregation ON (4.9) | Aggregation OFF |
|---|---|---|
| Performance score | 33 | 53 |
| Total Blocking Time | 1,030 ms | 170 ms |
| LCP | 13.8 s | 16.6 s |

TBT dropped by ~86% and the overall score improved by 20 points with aggregation off; LCP was noisier between runs (both lab tests, some run-to-run variance expected) but stayed in the same poor range either way, so it wasn't the deciding factor.

**Decision: JS aggregation is reverted and left off going forward.** Per-file minification (4.8) stays enabled and is unaffected by this — it was never implicated in the TBT regression and remains a clean, verified win. `autoptimize_js_trycatch` was left enabled (harmless, no-op without aggregation). This changes the "still open" item at the end of 4.8/4.9: aggregation is not simply deferred pending monitoring, it's a **tested and rejected** option for this specific site, given its current JS footprint (48 active plugins, Elementor, WooCommerce). It could be revisited later only alongside real reduction in total JS volume (e.g. after plugin cleanup), not as a request-count optimization on its own.

**Lesson for future performance work on this site:** always re-run PageSpeed Insights immediately after any change and compare Total Blocking Time specifically, not just the headline score or LCP — a change that looks like a clear win (79→14 requests) can still be a net loss if it concentrates execution cost. Rollback for this specific change was a single `wp option update autoptimize_js_aggregate ""` plus a cache clear; no plugin deactivation was needed.

### 4.12 Image Delivery Optimization — Cloudinary `sizes` Fix Kept, Lazy Load Reverted After Live Breakage (2026-09-14)

Followed up on the audit's "Improve image delivery" finding (~451 KiB estimated savings). Two changes were evaluated; one was kept, one broke the site live and was reverted immediately.

**Root cause found:** WooCommerce product loop thumbnails (shop archive, category/tag archives, search results, and the homepage's "On Sale"/"Latest Adult Products" grids) correctly request the registered `woocommerce_thumbnail` size (300×300) from the theme, but the Cloudinary plugin (`cloudinary-image-management-and-manipulation-in-the-cloud-cdn`) bypasses WordPress's per-size thumbnail generation entirely (`intermediate_image_sizes_advanced` filtered to `no_sizes`) and instead serves the image via its own dynamic URL transformation with a WordPress-core-generated responsive `srcset`. That `srcset` was correct (100w/300w/370w/600w/768w/1000w candidates), but the accompanying `sizes` attribute was WordPress core's generic default — `"(max-width: 1000px) 100vw, 1000px"` — which assumes the image renders at near-full viewport width. In reality the Martfury theme's product grid is 2 columns below 768px, 3 columns from 768–1199px, and 5 columns at 1200px+ (`.col-mf-5`, confirmed in `wp-content/themes/martfury/css/responsive.scss`, against a 1170px Bootstrap `.container` at that breakpoint — roughly 230px per column after gutters). Browsers were being told to fetch the ~1000px candidate for an image displayed at ~230px.

**Cloudinary plugin settings reviewed first** (`cloudinary_media_display` option) to see how much of this was fixable via configuration alone rather than code:
- `crop_sizes` already had a correctly-configured `300x300 → c_auto,g_auto,w_300,h_300` mapping, confirming the plugin knows the right target size — it just wasn't being applied to the responsive `sizes` calculation, which is a WordPress/theme layout fact the plugin has no way to infer on its own.
- `enable_breakpoints` was off; left off, since more `srcset` candidates don't help if the `sizes` attribute that picks between them is still wrong.
- `use_lazy_load` was off — see the reverted change below.

**Fix kept: corrected `sizes` attribute via mu-plugin.** Added `wp-content/mu-plugins/30-woocommerce-loop-image-sizes.php`, hooking WordPress core's `wp_calculate_image_sizes` filter. Scoped to `is_shop() || is_product_taxonomy() || is_search() || is_front_page() || is_home()` (the known grid-rendering contexts) and only overrides for images with an intrinsic width ≥700px (leaving genuinely small images, like a small logo, untouched), returning `(max-width: 767px) 50vw, (max-width: 1199px) 33vw, 230px` instead of the default. Verified: real requests confirm the new value renders on shop/homepage; thumbnails remain visually sharp (zoomed screenshot check) at grid size; PageSpeed Insights re-run after this change (with aggregation still off from 4.11) scored 53/100 with TBT 160ms and CLS 0 — consistent with the pre-existing healthy baseline, no regression introduced. Note: PHP-FPM must be restarted after deploying/editing this file, since opcache on this server does not auto-reload changed files.

**Change reverted: Cloudinary's native lazy load.** Flipping `use_lazy_load` from off to on was expected to be a safe, pure-config win (defers below-the-fold images, native plugin feature). Live testing found otherwise: enabled images are replaced with an animated SVG placeholder (`data:image/svg+xml;base64,...`) plus `data-srcset`/`data-cloudinary="lazy"` attributes, meant to be swapped for the real image by the plugin's own lazy-load JS on scroll-into-view — but that swap did not happen. The homepage's "Latest Adult Products" section (and other below-the-fold grids) rendered as **completely blank product cards** — no image, not even the placeholder animation — for real visitors, confirmed both in raw HTML (real Cloudinary URLs present in `data-srcset` but never promoted to `src`) and visually in-browser after scrolling the section into view and waiting. This was live and affecting real shoppers before being caught. Reverted immediately (`use_lazy_load` back to `off`, object cache flushed) and **Cloudflare's cache was purged (Purge Everything)** via the dashboard, since the broken version had already been cached at the edge (`cf-cache-status: HIT`) and would have kept serving broken images to visitors even after the origin was fixed. Verified post-purge: `cf-cache-status: MISS` on next fetch, zero SVG placeholders in the response, images rendering correctly in-browser.

**Root cause of the lazy-load breakage was not further investigated** given it was reverted — plausible causes include a JS conflict with another plugin/script on the page (the same category of issue found with Nelio A/B Testing in 4.8, though Nelio itself is already deactivated), an IntersectionObserver threshold/timing issue, or a genuine bug in this Cloudinary plugin version (3.3.2) specific to this theme's markup. If lazy loading is revisited later, it needs thorough scroll-and-wait visual verification across multiple grid sections before being considered safe, not just a HTTP-status/console-error check.

**Rollback for 4.12:** delete or rename `wp-content/mu-plugins/30-woocommerce-loop-image-sizes.php` and restart PHP-FPM to fully revert the `sizes` fix (no database changes involved). The lazy-load setting is already reverted (`use_lazy_load` = `off`) as the shipped state of this change.

### 4.13 Correction: "Animated Elements" Finding Is Not Elementor, and Is Deprioritized (2026-09-14)

Earlier entries in this document (4.6, 4.11) referred to "62 Elementor-animated elements" / "61 animated elements found" as a next-phase opportunity, inferred from the PageSpeed "Avoid non-composited animations" diagnostic count without opening its detail. That inference was wrong and is corrected here.

Actually inspecting the audit's element list (via the report's accessibility tree, not Elementor at all): of the ~59-61 flagged elements, only **one** is a slider element (a RevSlider slide link); the rest are ordinary WooCommerce product-grid elements — product title links, thumbnail links, the "quick view" overlay button, and "Add to cart" buttons — repeated across every product card on the page. Every flagged element shares the same reason: `Unsupported CSS Property: visibility`.

Root cause: `wp-content/themes/martfury/style.css` uses the broad `transition: all 0.5s` shorthand in roughly 30 places instead of listing specific animatable properties. Because `transition: all` applies to every property change, and `visibility` specifically cannot be GPU-composited, any element under one of these rules that also changes `visibility` on hover (e.g. the product-card "quick view"/"add to cart" overlay, which is hidden via `visibility` until hovered) gets flagged — even though nothing actually plays a visible animation; it's a routine, imperceptible hover-state CSS transition, not an entrance animation or autoplay effect. This is consistent with there being no visible animation on the site to notice.

**Decision: deprioritized.** Fixing this properly would mean auditing which of the ~30 `transition: all` rules in a 28,000+ line theme stylesheet actually touch `visibility`, and narrowing each to an explicit property list (or delaying the `visibility` change to the end of the transition instead of animating it) without breaking legitimate hover transitions elsewhere in the theme. The payoff is mostly a Lighthouse score/CLS book-keeping improvement, not a change visitors would notice, so it's not worth the risk/effort relative to the remaining render-blocking CSS opportunity. Left as a documented, low-priority item; not scheduled.

## 5) Cloudflare Setup

### 5.1 DNS / Edge Evidence

- NS for domain points to Cloudflare:
  - arya.ns.cloudflare.com
  - andy.ns.cloudflare.com
- HTTP response headers from https://ultralove.ca include:
  - server: cloudflare
  - cf-cache-status
  - cf-ray
- Origin: proxied records point at the reserved IP 167.99.26.122 (production droplet, 1.2). The origin presents a
  Cloudflare Origin CA certificate (valid to 2041-09-21), and its firewall only accepts HTTP/S from Cloudflare.
- Anonymous HTML is cached at the edge by APO; after a deploy that changes page HTML, purge Cloudflare.

### 5.2 Zone Settings (Free plan, read 2026-10-02)

| Area | Setting |
|---|---|
| SSL/TLS | Full (strict); always HTTPS; automatic HTTPS rewrites; min TLS 1.2; TLS 1.3 with 0-RTT; HSTS on, max-age 6 months, no includeSubDomains (`mail.`, `mg.` and Mailgun's tracking host aren't all HTTPS), no preload, no nosniff (min TLS and HSTS set 2026-10-02) |
| Caching | APO on (`ultralove.ca`, `www`), **cache by device type on**, via the WordPress plugin; cache level aggressive; Browser Cache TTL "Respect Existing Headers"; edge cache TTL 2 h; no cache rules; no page rules |
| Speed | Brotli, HTTP/2, HTTP/3, Early Hints on; Rocket Loader, Polish, Mirage, minify, WebP off |
| Security | security level medium; browser integrity check on; Bot Fight Mode off; email obfuscation on; max upload 100 MB |

### 5.3 WAF Custom Rules (in order)

| Rule | Match | Action |
|---|---|---|
| IP whitelist (skip managed rules) | `ip.src in $customers_ip` (account list, 22 IPs) | skip managed rules and Super Bot Fight Mode |
| Allow Shopify product_image fetches | GET/HEAD of image files under `/wp-content/uploads/` | skip managed rules and SBFM |
| WP logged in (**disabled**) | `wordpress_logged_in_*` cookie | skip the remaining custom rules |
| Skip challenge for public uploads (Shopify imports) | path contains `/wp-content/uploads/` | skip the remaining custom rules, managed rules, SBFM, rate limiting |
| Challenge outsiders | `(ip.src.country ne "CA" and not cf.client.bot)` or a `product_brand=` query listing several brands | Managed Challenge |

The verified-bot exclusion lets Googlebot and other legitimate crawlers through; the multi-brand filter clause stops
crawlers walking every brand combination.

### 5.4 Application-Level Cloudflare Integration

- The Cloudflare WordPress plugin is active; it runs APO (purges on content changes).
- Cloudflare Turnstile protects Gravity Forms (Turnstile add-on) and back-in-stock-notifier-for-woocommerce.

## 6) Cloudinary Setup

### 6.1 Settings (read 2026-10-02)

- Plugin `cloudinary-image-management-and-manipulation-in-the-cloud-cdn` 3.3.2 (local changes, 3.5); status ok.
- Media sync: auto sync on, folder `ultralove/catalog`, storage `dual_full` (Cloudinary *and* local copies kept -
  local uploads are still the source of truth, and are backed up to Spaces, section 7).
- Image delivery and optimization on, but **format and quality are both "none"** - no `f_auto`/`q_auto`, so
  images aren't converted to WebP/AVIF or recompressed (section 8). Crop size 300x300 uses `c_auto,g_auto`.
- Off: Cloudinary lazy loading, responsive breakpoints (see 4.12 - grid images get their `sizes` from
  `30-woocommerce-loop-image-sizes.php`), video delivery, SVG.
- Only part of the media library has synced so far (sync is ongoing).
- Credentials live in the `cloudinary_connect` option and are not recorded here.

### 6.2 Product images via Cloudflare (built, not switched on)

`wp-content/mu-plugins/38-product-images-cloudflare.php` serves product images (cards, single-product
gallery, thumbnails, zoom image) from `/cdn-cgi/image/` on the local upload instead of Cloudinary, so
Cloudinary can be dropped. It does nothing until `ul_product_cdn` is `cloudflare`.

- Switch: `wp option update ul_product_cdn cloudflare` (back: `cloudinary`). An editor can preview either
  on any page with `?ul_product_cdn=cloudflare|cloudinary` (separate from the banners' `?ub_cdn=`).
- Source is always the local original (`dual_full` keeps it). Cards are cut square with `gravity=auto`
  (as Cloudinary's `c_fill,g_auto` was) at 300/450/600 px; single 600/900/1200; thumbnails 100/200; the
  zoom image 1600. Few widths on purpose: Cloudflare bills each unique (image, parameters) pair once a
  month (5,000 free, then $0.50 per 1,000), and `onerror=redirect` serves the full-size original if the
  quota runs out. Change the widths in `ul_pcdn_profiles()`.
- Front end only: not wp-admin, REST (the retailer feed keeps original URLs), WP-CLI or cron. JPEG, PNG
  and WebP only.
- Cutover: (1) Cloudinary plugin: image delivery off and auto-sync off; (2) set the option; (3) purge
  Cloudflare, restart PHP-FPM. Rollback: set the option back and purge.
- Not yet verified on a live server: the `/cdn-cgi/` URLs for product images, dev (behind Cloudflare
  Access) and the `cloudinary_pre_image_tag` safety net.

### 6.3 Hosted Cloudflare Images copies

Production was confirmed on 2026-10-12 to use `cloudflare` for both
`ul_product_cdn` and `ub_banner_cdn`. This is remote-source transformation delivery,
not Cloudflare Images storage.

The `cloudflare-images` mode instead delivers account-bound copies uploaded to
Cloudflare Images. Its flexible variants retain existing product widths, square
cropping, automatic gravity, banner crop coordinates and responsive sources.
Hosted delivery incurs Images Stored/Delivered billing, not the remote-source
Images Transformed metric. CDN caching is not a guarantee that a derived variant
will never be regenerated.

`37-cloudflare-images.php` provides shared delivery and a resumable CLI command:

```sh
wp ultralove-images sync --after-id=0 --limit=100 --dry-run
wp ultralove-images sync --after-id=0 --limit=500
wp ultralove-images sync --pending --limit=50
```

Repeat bounded batches using each JSON result's `next_after_id` until
`processed` is zero. Nonzero exit status and `failed` identify exceptions; never
count those attachments as uploaded. IDs derive from the source SHA-256, so retries
and identical files reuse one stored image. Mappings are accepted only after
verifying account, content identity, public delivery URLs and unchanged source
bytes. Uploads use the Images batch API with four concurrent requests.

Credentials are provisioned outside the webroot in
`/etc/ultralove/cloudflare-images.json` (root:www-data, 0640; parent 0750), with
`account_id`, `account_hash` and a dedicated Images-edit `token`. The public
`ul_cf_images_account_hash` option must match; neither API token nor credentials
belong in git or attachment metadata. Existing IP-allowlisting credentials are
not reused or broadened.

After migration verification, set `ul_product_cdn` and `ub_banner_cdn` to
`cloudflare-images`, and enable `ul_cf_images_sync_enabled`. WordPress media
updates queue copies; the existing system-driven WP cron runs the bounded hourly
`ul_cf_images_sync` event. Pending, missing or replaced sources are logged and
retain the previous working transformation delivery until synchronized.
REST/feed URLs, admin screens, local uploads and DigitalOcean Spaces backups are
unchanged. Roll back both delivery options to `cloudflare` and disable the sync
option; do not delete local files, Spaces objects, or hosted copies.

The initial inventory contains 20,601 JPEG/PNG/WebP attachments: 20,599 readable
sources (about 2.5 GiB) and two already-missing originals (20595, 20597). The 62
animated GIF attachments remain on their original delivery path; ten exceed the
hosted 10 MB limit. They are not silently flattened, resized or removed to fit.

#### Dev pilot verified (2026-10-12)

Implementation revision `e8b0cefd3bce787c14ca62650c8a1a4d08539749` was pushed
on `feat/cloudflare-hosted-images` and deployed through the guarded pipeline.
There was no server drift; server PHP lint, the deployment backup, all four HTTP
smoke checks and GitHub CI passed. Offline coverage passed 57 hosted-image and
398 existing catalog assertions.

Dev intentionally has no readable local catalog originals (20,406 still-image
attachment records were checked). The pilot therefore uses a generated
1000×800 PNG, attachment 97164, retained exclusively on dev for repeatable checks.
Its local original is 4,710 bytes; no production upload was copied to dev.
Protected Images credentials were provisioned only on dev.

The real batch upload accepted the content-addressed ID and source metadata.
Removing only its mapping and retrying verified duplicate-ID recovery through
the encoded details endpoint. A subsequent sync reported one unchanged image
and zero uploads. Cloudflare still reported exactly one stored image out of
100,000 allowed: responsive variants and recovery did not consume extra storage.

Sixteen public delivery requests (eight profiles with WebP and AVIF Accept
headers) returned HTTP 200 with exact expected dimensions: 300/450/600 square
cards, a 600×480 gallery image, 100×100 thumbnail, 1000×800 zoom, 600×150 saved
banner crop and 320×256 backdrop. Resized responses negotiated WebP/AVIF;
Cloudflare retained the smaller original PNG for the full-size image.
Banner markup retained its crop dimensions, responsive sources and rewrite
protection marker. The generated local source remained unchanged.

Both dev delivery options were restored to `cloudflare` after testing;
automatic synchronization remains disabled. No system WP-cron trigger was
found on dev, so automatic maintenance must not be claimed operational there.
Production credentials, image copies, delivery options and Spaces backups were
not modified. Production migration, system-cron verification and cutover are
separate, approval-gated steps.

#### Production migration started (2026-10-12)

Release `562670c820` (PR #157) was deployed to production through the guarded
pipeline after the dev gate and drift check passed. Server PHP lint and all four
HTTP smoke checks passed; the previous code is backed up at
`/var/lib/ultralove-deploy/backups/20261012T165423Z-ada96ae130.tgz`.
The public homepage's inline critical CSS matches the release after
Autoptimize's removal of the generated-file comment.

Protected production Images credentials and the matching public account-hash
option were already provisioned; this run did not copy or change credentials.
The first bounded batch processed 500 attachments through ID 19427, accepting
497 verified hosted copies. Attachments 17567, 18655 and 19383 were rejected:
their WordPress dimensions are absent, and `getimagesize()` could not establish
dimensions from their local sources. The batch returned exit status 1 to expose
these three exceptions; they were not counted as copied or silently repaired.
This is an initial batch, not a completed catalog migration.

All 497 original SHA-256 hashes, paths, modification times and sizes remained
unchanged, and every saved account/source mapping was verified. Repeating those
497 IDs reported 497 unchanged, zero uploads and no failures. Six public requests
covering the first, middle and last copied images returned HTTP 200 at exactly
300x300, negotiating WebP and AVIF (5,210-12,378 bytes). Account storage was
498 images out of 100,000 allowed, including the retained dev fixture.

Root-only run evidence (source manifest, batch result, original/mapping checks,
delivery checks and storage count) is under
`/var/lib/ultralove-images/20261012-production-first-batch/`.
Resume the next bounded batch with:

```sh
wp ultralove-images sync --after-id=19427 --limit=500 --dry-run
wp ultralove-images sync --after-id=19427 --limit=500
```

Run from `/var/www/html` as the site user, or add `--allow-root` when operating
as root. Preserve each result and investigate its `failed` entries; advancing
the cursor does not resolve earlier exceptions.

Production's system WordPress maintenance trigger was verified in
`/etc/cron.d/ultralove-wp-cron`: it runs due events as `www-data` every five
minutes. Both delivery options remain `cloudflare`, automatic hosted-image sync
remains disabled, and no hosted-image cron event is scheduled. Nginx, PHP-FPM and
Redis were active after deployment. Local uploads and Spaces backups were not
modified. Complete the remaining source batches and resolve or explicitly
exclude invalid sources before the separate delivery cutover and hourly-sync
enablement.

#### Bulk production copy job (2026-10-12)

At the user's request, the remaining source scan was started after ID 19427 as
the transient systemd service `ultralove-images-migration.service`. It runs
WP-CLI on the production host with a nonblocking
`/var/lib/ultralove-images/migration.lock`, four concurrent uploads and
100-attachment batches. The service continues independently of the SSH session;
it is not a scheduled job and does not automatically resume after a reboot.
No new site code, delivery option or automatic-sync setting was changed.

The root-only `/var/lib/ultralove-images/migration.jsonl` contains the initial
pilot result followed by timestamped `started`, `batch_started` and
`batch_result` records. The runner records source exceptions and continues the
ordered scan. Transient upload failures are logged and retried up to five times
with 15/30/60/120-second delays, reusing already-confirmed copies. If uploads
remain unconfirmed after the retry budget, the updated runner persists the
identified failures to its separate ledger and advances the scan. Global
failures without an attachment result, or inability to persist failure
evidence, still stop the job. Terminal records distinguish `completed`,
`completed_with_exceptions` and `stopped_with_error`; completion of the scan
does not mean rejected attachments were copied.

```sh
ssh ultralove-prod-app 'tail -n 10 -F /var/lib/ultralove-images/migration.jsonl'
ssh ultralove-prod-app 'systemctl is-active ultralove-images-migration.service'
ssh ultralove-prod-app 'journalctl -u ultralove-images-migration.service -n 20 --no-pager'
```

The operational runner is passed as a literal `wp eval` argument.
`systemd-run --expand-environment=no` is required: its default argument
expansion otherwise interprets PHP dollar-prefixed variables, preventing
startup. An initial failed launch was diagnosed and corrected before any
additional attachments were processed. The first upload batch then encountered
an HTTP 520 after 99 accepted copies; the stopped job was resumed at the same
cursor with bounded retries rather than skipping the unconfirmed attachment.
Do not start manual copy batches
alongside this service; the lock protects other invocations using the same
lock, not standalone `wp ultralove-images sync` commands.

At 17:27 UTC, five retries were exhausted on attachment 23086 with HTTP 413.
Its original is a 2,757,318-byte animated GIF
(`2021/04/products-PD3651-23_09.gif`, 1932x2500), despite its WordPress
`image/jpeg` MIME record. Increasing the retry budget cannot fix this permanent
rejection. The bulk runner now checks the actual local MIME using
`mime_content_type()` before upload, explicitly logs unsupported file formats
as `source_excluded`, and keeps their files and existing delivery unchanged.
It resumes from the stopped cursor 22897, reusing the 99 accepted copies in that
batch. Cursor and processed counts cover all scanned IDs, including a batch
containing only excluded sources.

This is an operational bulk-runner guard, not a correction of WordPress
attachment metadata or a change to the deployed synchronization helper.
Before enabling the separate hourly synchronization, account for unsupported
files whose WordPress MIME records incorrectly classify them as still images.

At 18:30 UTC, the job stopped after five HTTP 413 retries on animated WebP
attachments 78414, 78416 and 78417. Their 1600x925 canvases contain 112, 128
and 258 frames. A diagnostic request for 78414 confirmed Cloudflare error 5443:
at this resolution, at most 67 frames fit the 100-million aggregate-pixel
animation limit. Attachments 78415 and 78418 are already zero-byte sources,
not oversized images. No source was resized, flattened, repaired or deleted.

At the user's request, the operational runner now logs failures separately and
continues the scan. It checks extended WebP's animation flag and excludes
animated WebP from this still-image migration, preserves HTTP 413 rejections
without pointless retries, and defers identified upload failures after five
transient attempts. Each failure records the attachment ID, error, reason,
timestamp and `copied: false` in the root-only
`/var/lib/ultralove-images/failures.jsonl`. Forty-three unresolved historical
attachment failures were imported from the progress log, excluding failures
whose source/account mapping was subsequently verified. Ledger entries may
repeat across restarts; use attachment IDs when counting unique exceptions.

The job resumed after ID 77877, reusing the 95 accepted copies from its blocked
batch. Failed attachments are not counted as uploads, and completed scanning
with exceptions is not full migration success. The ledger is the follow-up
worklist; it does not automatically repair or retry excluded sources.

```sh
ssh ultralove-prod-app 'tail -n 20 -F /var/lib/ultralove-images/failures.jsonl'
```

#### Dev HTTP flow verification and review preview (2026-10-12)

After the production source scan completed at 18:57 UTC, the user requested
dev verification before any production delivery cutover. Dev and production
both run release `562670c820`; no code deployment was needed.

Dev delivery options were changed to `cloudflare-images` and remain enabled
for review. Automatic synchronization remains disabled and its hourly event
was cleared after the controlled test. Production options were rechecked and
remain `cloudflare`, with automatic synchronization disabled.

Dev still has no readable catalog originals. Existing generated attachment
97164 (1000x800 PNG) was reused; no production media was copied. The following
dev-only records were created:

- 97165: hidden, non-purchasable product using the verified hosted fixture;
- 97166: unsynchronized attachment referencing the same local generated file;
- 97167: hidden, non-purchasable fallback product;
- 97168: banner slide in the isolated `hosted-flow-test` group, with saved crop
  coordinates 100,100,800,200;
- 97169: `/hosted-images-dev-check/`, rendering the two explicit hidden-product
  cards and test banner.

Gallery preview: `/product/hosted-images-dev-test/`; fallback preview:
`/product/hosted-images-dev-fallback/`. Fixture products have no price and
cannot be purchased. The banner is not added to normal home/header groups.

Sixteen assertions against actual guest origin HTTP responses passed: hosted
card and gallery URLs, responsive candidates, exact markup dimensions,
original-width clamping, hosted zoom, saved banner crop and rewrite marker,
and unmapped card/gallery fallback routing. Forty additional checks passed:
sixteen public image requests across eight profiles with WebP/AVIF Accept
headers, exact delivered dimensions and format negotiation; real authenticated
product-page delivery; original REST media URLs and CLI/feed source behavior;
dirty-generation creation through the real metadata-update hook; HTTP
fallback while dirty; unchanged-source sync clearing that generation without
an upload; HTTP rollback; original byte integrity; and non-purchasability.
The full-size 1000x800 response retains the smaller 4,710-byte original PNG
instead of converting it. All 57 offline hosted-image regression assertions
also passed.

The previous flags and attachment-parent state, fixture IDs and 40-check
verification report are retained under the dev host's root-only
`/var/lib/ultralove-images/dev-flow-20261012/`. To restore dev delivery, use the
saved `before.json` values (both were `cloudflare`), keep automatic sync off,
and restore attachment 97164's saved parent if retiring the test products.
Attachment 97166 shares 97164's file: do not delete its media files during
cleanup. No source bytes were changed.

Browser navigation reached Cloudflare Access login, so interactive visual,
carousel and zoom behavior at desktop/mobile viewports still requires an
authorized reviewer. The origin checks exercised real WordPress templates,
not a simulated CLI frontend. Unmapped fallback URLs remain directed to the
Access-protected dev zone; successful edge retrieval of those originals was
not verified. Existing missing catalog images and vendor PHP deprecation
notices are dev baseline limitations, not successful catalog-migration tests.
Production cutover and automatic-sync activation remain separate approvals.

#### Browser-confirmed dev catalog fallback (2026-10-12)

After the user authenticated the browser through Cloudflare Access, ordinary
dev shop thumbnails were confirmed to follow the fallback path. For attachment
96655 (`2026/09/W637756.jpg`), dev has neither a readable original nor a hosted
mapping; its recorded source dimensions are 1000x1000. The browser requested a
600x600 `/cdn-cgi/image/` URL, received HTTP 307 to the dev original, then HTTP
302 to the production original, which returned HTTP 200. Thus a 1000x1000
production-hosted original in the browser is not proof of hosted-image delivery,
and ordinary dev catalog thumbnails remain outside the generated-fixture test.

The isolated preview was also verified in that browser: the mapped fixture
loaded a physical 600x600 image from `imagedelivery.net` on desktop and selected
a physical 300x300 image at a 390-pixel viewport. The deliberately unmapped card
loaded its 1000x800 generated original through the fallback. The hosted cropped
banner loaded successfully. Physical dimensions were measured using a separate
image without `srcset`, since the DOM's `naturalWidth` for a responsive image
can be density-corrected rather than the downloaded file's pixel width.

Do not describe the complete dev catalog as migrated or visually verified:
the real catalog lacks the local sources and verified mappings required by
the deployed hosted-delivery helper. Enabling the official Cloudflare WordPress
plugin does not supply either. No production delivery settings were changed.

#### Real-catalog remote preview on dev

To test the real catalog without copying production uploads into dev,
`37-cloudflare-images.php` now supports separate `_ul_cf_images_dev_preview`
records. This path requires all three guards: the explicit
`ul_cf_images_dev_remote_preview` option, WordPress's `development` environment
type, and the exact `dev.ultralove.ca` hostname. It cannot operate on production,
even if the option is accidentally enabled. Normal `_ul_cf_images` mappings
and their local modification-time/size checks remain unchanged.

Remote previews are used only when the dev source file is absent. Account,
content-addressed SHA-256 identity, attachment filename, dimensions, MIME and
dirty state must match. Media-update hooks remove a preview record even with
automatic synchronization disabled. A newly present local file must pass the
normal local synchronization checks instead. Preview metadata is not considered
a successfully synchronized local source by the copy worker.

The CLI operational helper `ops/product-images/dev-preview.php` exports only
currently verified production image identities and imports them separately on
dev. Export rehashes each local source and checks its current account mapping;
the snapshot contains no image bytes, credentials, titles or customer data.
Import requires a trusted production snapshot for the configured account and
matching dev attachment ID, path, MIME and geometry; mismatches are recorded
and retain fallback. It saves prior preview metadata and its enable flag before
writing. Store snapshots root-only outside the webroot and transfer through the
trusted operator, never by allowing dev to connect to production.

Run through WordPress CLI with the helper available outside the webroot:

```sh
wp eval-file /root/ultralove-images-dev-preview.php export /var/lib/ultralove-images/snapshot.json
# Transfer only snapshot.json to dev through the operator, then:
wp eval-file /root/ultralove-images-dev-preview.php import /var/lib/ultralove-images/snapshot.json
```

Use `/var/www/html` and add `--allow-root` only when operating as root.
The helper's source is versioned under `ops/`; it is not a site plugin and must
not be placed in the webroot. The import preserves normal hosted-copy mappings
and leaves automatic synchronization and both delivery options unchanged.
Delivery mode must already be `cloudflare-images` to exercise the preview.
Disable `ul_cf_images_dev_remote_preview` to roll back the remote preview
without deleting any media. Restore its metadata from the saved `.before.json`
if needed. Regression coverage includes production and wrong-host denial,
account/source mismatch, dirty generations and newly present local originals.

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
- This is separate from the dedicated production mail droplet `PRODUCTION-mail-2026-ubuntu-24-LTS` (568349152), which likely serves mailbox/MTA or other mail infrastructure responsibilities outside WordPress plugin-driven outbound mail.
- Exact Mailgun mode and credentials are intentionally not recorded in plaintext here.

### 6.5.3 Mail Server and DNS (2026-10-02)

| Path | How |
|---|---|
| Inbound mail, mailboxes | MX `mail.ultralove.ca` -> the mail droplet, 143.110.140.137 (Ubuntu 24.04; Postfix + Dovecot + rspamd; Let's Encrypt certificate; DNS only, not proxied by Cloudflare) |
| Mail sent by the mail server | relayed through Mailgun (`relayhost = [smtp.mailgun.org]:587`, SASL credentials in `/etc/postfix/sasl_passwd`) - it doesn't deliver to the internet itself |
| WordPress outbound mail | Mailgun plugin, sending domain `mg.ultralove.ca` (SPF `include:mailgun.org`, DKIM selector `mx._domainkey.mg`) |

- Root SPF is `v=spf1 ~all` and DMARC is `p=quarantine` (`adkim=r; aspf=r`; aggregate reports to
  `ultra@ultralove.ca`, which receives them on the mail droplet). That's fine because everything sent as
  @ultralove.ca goes out through Mailgun: the DMARC reports for the 30 days to 2026-10-02 show ~2,750 messages from
  Mailgun with SPF and DKIM aligned and passing, and nothing quarantined. Copies re-sent by recipients' forwarders fail
  SPF but pass on the surviving DKIM signature. (4 messages from `vps.industrialuv.com` failed both.)
- If anything ever sends as @ultralove.ca *without* Mailgun, add it to the root SPF first.
- Mail logs: `/var/log/mail.log` (all recent deliveries are inbound, to Dovecot LMTP).
- Backups: DigitalOcean weekly droplet backups, enabled 2026-10-02.

## 7) Backups and Retention Architecture

All off-site backups go to the DigitalOcean Spaces bucket `ultralove-web-backups` (sfo3). The scripts are in the
repo (`ops/prod/`) and installed by the bootstrap; credentials are in `/root/.spaces_env` (600; replaced
2026-10-02 - the previous key had been revoked around May, so script uploads failed from then until that date).

| What | How | When | Where in Spaces |
|---|---|---|---|
| Database, plugins, themes, "others" | UpdraftPlus (WordPress plugin), its own S3-compatible remote storage | daily | `updraft/` (about the last 4 days kept) |
| Media library (`wp-content/uploads`, ~17 GB) | `/root/uploads_spaces_sync.sh`: `aws s3 sync`, new/changed files only, deletions not mirrored, lowest priority | 03:30 UTC nightly | `uploads/` |
| Server config and secrets | `/root/monthly_system_backup.sh`: tar of nginx, PHP, Cloudflare Origin CA cert, Redis, UFW, cron, systemd, the deploy record, the backup scripts, `.spaces_env`, `wp-config.php` (+ checksum, inventory) | 02:40 UTC on the 1st | `system-config/YYYY-MM/` |

- UpdraftPlus's backups leave uploads out, and only part of the media is on Cloudinary - hence the uploads sync
  (added 2026-10-02; the first full sync ran that day).
- The previous droplet's nightly `updraft_spaces_sync.sh` (copied UpdraftPlus archives to Spaces) is gone:
  UpdraftPlus uploads to Spaces itself, and the script had failed every night since May.
- Local copies: monthly archives in `/root/system_monthly_backups/`; deploy rollback tarballs in
  `/var/lib/ultralove-deploy/backups/` (last 20).
- Not enabled: DigitalOcean droplet backups (whole-disk restore points; extra cost).
- The previous production droplet is kept as a snapshot (1.2).

### 7.1 Cron Schedule (production)

- root: `40 2 1 * * /root/monthly_system_backup.sh >> /var/log/monthly_system_backup.log 2>&1`
- root: `30 3 * * * /root/uploads_spaces_sync.sh >> /var/log/uploads_spaces_sync.log 2>&1`
- www-data (`/etc/cron.d/ultralove-wp-cron`): `*/5 * * * *` `wp cron event run --due-now` (log:
  `/var/log/wp-cron-runner.log`)

## 8) Open Items

- **Mail droplet SSH:** root's `authorized_keys` lists 11 entries, including the retired prod's key
  (`root@ultralove-prod-app`, `1edSPOaA...`, twice), a key from an old server (`craig@wordpressonubuntu2204-...`),
  `rsa-key-20220121`, and personal keys (Lance, Paul Chiu, several of Craig's). Remove the ones no longer needed; the
  DigitalOcean account's SSH keys (added to new droplets) deserve the same review.
- **Mail droplet SSH is open to the internet** (UFW `22/tcp ALLOW Anywhere`), though it was meant to be private-network
  only. Restricting it to 10.120.0.0/20 means reaching it through prod web, which has no key for it yet (the retired
  prod's key was the hop).
- **Cloudinary f_auto/q_auto** are off (6.1): turning them on would shrink most images; test on dev first.
- **Cloudflare APO:** after the Martfury mobile version is switched off (one responsive design), turn off "cache by
  device type" so desktop and phone share one cached copy.
- UpdraftPlus: confirm that leaving uploads out is intended now that `uploads/` is synced separately.
- Spaces: the monthly script archives `.spaces_env` into the same bucket it can write to - fine for recovery, but a
  leaked key exposes its own backups; consider a separate restore-only key.
- **Managed DB is a single node:** no standby during maintenance or a failure.
- Unused DigitalOcean resources: the `ultralove.ca` DNS zone (DNS is on Cloudflare), the `default-sfo3` VPC, and the
  `defaultdb` and `ultralove_rewrite` databases on the cluster (confirm before removing).

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

Historical: of the hosts below only the mail role still exists, on a different droplet. The prod app, prod DB, dev
app and dev DB droplets have all been replaced (section 1).

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
