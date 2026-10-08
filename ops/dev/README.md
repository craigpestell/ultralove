# Dev environment (dev.ultralove.ca)

Dev is **private and disposable**: it can be rebuilt from these scripts in well under
an hour, nobody on the public internet can reach it, and it holds no real customer
data or live credentials. (The previous dev box was public, shared prod's private
network and had prod's keys; it was compromised on 2026-09-18 and destroyed.)

## Shape

| | |
|---|---|
| Droplet | `ultralove-dev-managed` (id 606134262, sfo2): Ubuntu 26.04, nginx + PHP 8.5 + Redis, database on prod's DigitalOcean managed MySQL cluster (`db-mysql-sfo2-16643`, database `wordpress_dev`), the same arrangement as production. Live since 2026-10-05; it replaced `ultralove-dev` (local MySQL) |
| Network | Own VPC `ultralove-dev-vpc` (10.140.0.0/20), no route to prod or mail |
| Inbound | DO firewall + UFW: **SSH only** (key-only, fail2ban). nginx listens on 127.0.0.1 |
| Web access | Cloudflare Tunnel (`cloudflared`, outbound only) → Cloudflare Access login (email PIN) for `DEV_ACCESS_EMAILS` |
| Data | Anonymized copy of prod. `DEV_KEEP_EMAILS` accounts (Craig, client) kept intact to log in |
| Uploads | Not copied; nginx 302-redirects missing `/wp-content/uploads/*` to ultralove.ca |
| Code | Custom code: `deploy/deploy.sh dev` (auto on merge to main). Core/vendor plugins: copied from prod |
| wp-config | Dev-only: own DB creds + salts, `WP_ENVIRONMENT_TYPE=development`, `DISALLOW_FILE_MODS`, WP-cron off, outbound HTTP blocked except the existing WordPress hosts and the exact Shopify integration test-store hostname |
| Side effects | mu-plugin `00-ultralove-environment-guard` suppresses all email; plugins holding live credentials (Mailgun, Mailchimp, Updraft, Cloudflare, Cloudinary, ManageWP, Jetpack) are deactivated; webhooks disabled; pending scheduled actions cancelled |

## Scripts

The scripts below were written for the earlier single-box dev (local MySQL). `ultralove-dev-managed` was built by hand
on top of that setup: `rebuild.sh`, `bootstrap.sh` and `refresh-db.sh` still install and load a local MySQL, so
they need a managed-database variant (`DB_HOST`/`DB_NAME` in `wp-config.php`, restore into the cluster) before the
next rebuild. `bootstrap.sh` already installs the nginx page cache (`ops/nginx/11-ultralove-page-cache.conf`).
`setup-prod-push.sh` has not been re-run for the new droplet, so `sync-code.sh` and `refresh-db.sh` (prod -> dev)
won't work until it is.

| Script | Does |
|---|---|
| `rebuild.sh` | Everything below in order: a brand-new dev from nothing |
| `provision.sh` | VPC, firewall, droplet; points the `ultralove-dev-app` ssh alias at it |
| `bootstrap.sh` | Server setup (runs on the droplet; `rebuild.sh` pipes it over ssh) |
| `setup-prod-push.sh` | Lets prod push to dev directly (prod key accepted only from prod's IP; dev gets no access to prod) |
| `sync-code.sh` | Prod → dev copy of core/vendor code, then core + plugin checksum verification |
| `refresh-db.sh` | Prod DB → dev (streamed prod→dev), anonymize, verify, rewrite URLs, disable risky plugins, `dev-admin` login |
| `setup-ci-deploy.sh` | Deploy key + GitHub `dev` environment secrets for the auto-deploy Action |
| `cloudflare-setup.sh` | Tunnel, DNS and Access (needs `CLOUDFLARE_API_TOKEN`) |
| `parity.sh` | Compare core/plugin/theme versions and status between prod and dev |

Config lives in `config.env` (no secrets). Secrets live only on servers: the dev DB
password in `/root/.ultralove-dev-db.cnf`, the fallback admin login in
`/root/.ultralove-dev-admin`, and CI keys in GitHub environment secrets.

## Common tasks

```bash
ops/dev/refresh-db.sh          # fresh anonymized data (drops dev DB)
ops/dev/sync-code.sh           # after updating WordPress/plugins on prod
ops/dev/parity.sh              # are dev and prod on the same versions?
ADOPT_REF=origin/main ops/dev/rebuild.sh && CLOUDFLARE_API_TOKEN=... ops/dev/cloudflare-setup.sh   # start over
ssh ultralove-dev-app 'tail -f /var/log/wordpress/debug.log'   # PHP notices (+ suppressed emails)
ssh ultralove-dev-app 'cd /var/www/html && wp --allow-root cron event run --due-now'   # run cron by hand
```

Plugin/WordPress updates: update on dev first by copying the new version into dev's
`wp-content/plugins` (wp-admin installs are disabled), test, then update prod, then
`sync-code.sh` + `parity.sh` to confirm they match.

## Known local modifications to vendor plugins (lost on plugin update)

- `cloudinary-image-management-and-manipulation-in-the-cloud-cdn` 3.3.2: connection-string
  regex allows digits in cloud names; "Unsync all Cloudinary media" bulk action and
  `wp cloudinary unsync` CLI (`php/class-connect.php`, `php/sync/class-unsync.php`,
  `php/traits/trait-cli.php`; May 2026). Re-apply or confirm upstream fixes after updating.
- `revslider`: Cloudinary optimization customization (see documentation/ARCHITECTURE.md).

## Shopify supplier test workflow

**Status: deployed, connected, and live-tested on dev (2026-10-08 UTC).**
Feature branch `feat/dev-shopify-supplier` is under draft PR #138; it has not
been merged or deployed to production. Twenty isolated policy assertions and
28 live workflow assertions pass. Root-only credentials, the narrow Shopify
egress exception, and the five-minute cron entry are installed.

**WooCommerce > Shopify Supplier** controls the dev-only
`ultralove-shopify-supplier` plugin. It remains disabled on production regardless
of copied options. Only the Shopify development store
`ultralove-integration-lab.myshopify.com` is supported; no other Shopify hostname
is permitted by the outbound allowlist.

Select up to 25 **existing** published simple products with SKUs, prices, and
managed stock, an approved dropshipping retailer, and an initial retail markup.
No synthetic products are created on dev. The initial pilot selects existing
product IDs `17186`, `17188`, and `17192` (SKUs `1513`, `2455`, `70072`).
Imports are Shopify drafts with dev-specific handles, variant mappings, and the
tag `ultralove-dev-supplier`. Stock sync preserves retailer-edited prices.
All three imported images reached Shopify's ready state; titles/SKUs match and
stock was restored to `327`, `125`, and `414` after verification.
The earlier lab's two synthetic Shopify products remain archived.
Deselection stops forwarding; manually archive a Shopify draft if it is no
longer needed.

The CLI command `wp ultralove shopify sync` runs under the root-only system
worker, not as a browser callback or public webhook. Credentials and logs live
in `/root/.ultralove-shopify-dev/` with permissions `0600`; credentials are never
stored in WordPress options or displayed by the admin page. The five-minute cron
entry is `/etc/cron.d/ultralove-shopify-dev`. The earlier integration-lab worker
is paused. An actual scheduler-triggered cycle succeeded at
`2026-10-08T17:05:07+00:00`, confirmed by the root worker log and
`ul_dev_shopify_last_success`, not just a manual invocation.

Only paid Shopify **test** orders tagged `ultralove-dev-supplier` are accepted.
Use the fictional delivery recipient `Synthetic Recipient` at
`123 Synthetic Street` and a complete Canadian test address. Product lines are
real catalog items; test customers/orders are deliberately not real transactions.
Orders belong to the chosen approved retailer and use its billing email and
WooCommerce product prices. They start on hold without supplier payment.
Shipping and tax are excluded from these integration-test totals.
Email suppression, payment configuration, Access, and disabled external
integrations remain unchanged.

Bridge-created orders expose **Shopify test tracking** under their shipping
details. Enter a `LAB-` number and mark the test order completed to return
tracking for supplier-owned lines only, without notifying the recipient.
Already-forwarded tracking cannot be changed silently.

Verification:

```sh
php ops/dropshipping/test-shopify-supplier.php
# On dev, with the committed verifier uploaded outside the web root:
wp --allow-root --path=/var/www/html eval-file /root/.ultralove-shopify-dev/verify-shopify-bridge.php
wp --allow-root --path=/var/www/html ultralove shopify sync
```

The live verifier uses selected existing products, temporarily adjusts/restores
dev stock, creates/deletes only its own test orders, and checks mixed-line
isolation, wholesale attribution, stock synchronization, retry behavior, and
tracking. It does not create products, change production inventory, enable a
payment gateway, or make a real supplier payment. Run it with at least two
selected products and positive stock on the first product.

Disable the connection on **WooCommerce > Shopify Supplier** to stop the worker
without deleting mappings/orders. Remove only `/etc/cron.d/ultralove-shopify-dev`
to pause scheduling. Revoke the app credential separately if retiring the
connection. This is not independent-retailer OAuth or a production rollout.
