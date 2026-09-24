# Dev environment (dev.ultralove.ca)

Dev is **private and disposable**: it can be rebuilt from these scripts in well under
an hour, nobody on the public internet can reach it, and it holds no real customer
data or live credentials. (The previous dev box was public, shared prod's private
network and had prod's keys; it was compromised on 2026-09-18 and destroyed.)

## Shape

| | |
|---|---|
| Droplet | `ultralove-dev` (s-4vcpu-8gb, sfo2): nginx + PHP 8.3 + MySQL 8.0 + Redis on one box |
| Network | Own VPC `ultralove-dev-vpc` (10.140.0.0/20), no route to prod or mail |
| Inbound | DO firewall + UFW: **SSH only** (key-only, fail2ban). nginx listens on 127.0.0.1 |
| Web access | Cloudflare Tunnel (`cloudflared`, outbound only) → Cloudflare Access login (email PIN) for `DEV_ACCESS_EMAILS` |
| Data | Anonymized copy of prod. `DEV_KEEP_EMAILS` accounts (Craig, client) kept intact to log in |
| Uploads | Not copied; nginx 302-redirects missing `/wp-content/uploads/*` to ultralove.ca |
| Code | Custom code: `deploy/deploy.sh dev` (auto on merge to main). Core/vendor plugins: copied from prod |
| wp-config | Dev-only: own DB creds + salts, `WP_ENVIRONMENT_TYPE=development`, `DISALLOW_FILE_MODS`, WP-cron off, outbound HTTP blocked except wordpress.org |
| Side effects | mu-plugin `00-ultralove-environment-guard` suppresses all email; plugins holding live credentials (Mailgun, Mailchimp, Updraft, Cloudflare, Cloudinary, ManageWP, Jetpack) are deactivated; webhooks disabled; pending scheduled actions cancelled |

## Scripts

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
