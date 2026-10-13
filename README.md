# Ultralove Website Repository

Source of truth for the custom code and server configuration of the Ultralove WordPress/WooCommerce site:
**ultralove.ca** (prod) and **dev.ultralove.ca** (dev). `main` is what's live on prod.

## Servers

| | Prod | Dev |
| --- | --- | --- |
| Droplet | `PRODUCTION-WEB-ultralove.ca` (s-4vcpu-8gb, sfo2) | `ultralove-dev` (s-4vcpu-8gb, sfo2) |
| Stack | Ubuntu 26.04, nginx 1.28, PHP 8.5 (`php8.5-fpm`), Redis | the same, plus MySQL 8.4 on the box |
| Database | DigitalOcean managed MySQL (`db-mysql-sfo2-16643`) | local, anonymized copy of prod (`ops/dev/refresh-db.sh`) |
| In front | Cloudflare (proxy, APO, WAF) -> reserved IP `167.99.26.122` | Cloudflare Tunnel + Access (private) |
| SSH alias | `ultralove-prod-app` | `ultralove-dev-app` |
| Built by | `ops/do-migration/bootstrap-app-server.sh` | `ops/dev/rebuild.sh` |

Details (config locations, firewall, PHP-FPM sizing, backups): `documentation/ARCHITECTURE.md`.

## Layout

| Path | What |
| --- | --- |
| `wp-content/themes/martfury/` | Parent theme (vendor, with local fixes) |
| `wp-content/themes/martfury-child/` | Child theme customizations, incl. `css/critical.css` (generated - see `ops/critical-css/`) |
| `wp-content/plugins/hide-price-until-login/` | Custom plugin |
| `wp-content/plugins/ultralove-*/` | Custom plugins: Banners, Backdrops, product export sync, product stock export |
| `wp-content/plugins/ultralove-dropshipping/` | Approved-retailer manual checkout, default off on each environment; see [dropshipping runbook](documentation/dropshipping-pilot.md) |
| `wp-content/mu-plugins/` | Custom MU plugins (ManageWP's `0-worker.php` excluded) |
| `wp-content/object-cache.php`, `cloudinary-*.php`, `run-cloudinary-workers.sh` | Redis drop-in and Cloudinary maintenance scripts |
| `deploy/` | Deploy pipeline (see below) |
| `ops/do-migration/` | Building a production app droplet: provisioning, bootstrap, file sync, cutover steps (`.env` not tracked) |
| `ops/nginx/`, `ops/php/` | Production nginx and PHP-FPM configuration, installed by the bootstrap |
| `ops/prod/` | Production backup scripts (monthly config archive, nightly media sync to Spaces) |
| `ops/dev/` | Private, disposable dev environment: build, refresh, parity (see `ops/dev/README.md`) |
| `ops/critical-css/` | Critical CSS generator (run by the "Critical CSS" workflow; see its README) |
| `ops/css/` | Builds the trimmed copies of Martfury's / WooCommerce's / icon fonts' CSS (see its README) |
| `ops/gravityforms/`, `ops/content/` | One-off maintenance scripts (Turnstile switch, header menu cleanup) |
| `documentation/` | Architecture runbook, pending-release checklist, and [client picture-review reports](documentation/reports/image-migration-2026-10-12/client-product-picture-review.html) ([CSV](documentation/reports/image-migration-2026-10-12/client-product-picture-review.csv)) |
| `.claude/skills/` | Claude Code skills: deploy workflow, where everything on a page comes from |
| `.github/skills/ultralove-infrastructure/` | Infrastructure operations runbook |

Not tracked: WordPress core, vendor plugins, `wp-config.php`, uploads, caches, DB dumps, backups and other secrets.
`.gitignore` is an allowlist; new custom code must be added there **and** to `deploy/owned-paths.rules`.

## Deploying

Requires GNU rsync (`brew install rsync`) and SSH access to `ultralove-prod-app` / `ultralove-dev-app`.

```bash
# 1. PR -> CI (PHP 8.5 lint, shellcheck, no dumps/archives). Try it on dev:
deploy/deploy.sh dev --ref my-branch --dry-run
deploy/deploy.sh dev --ref my-branch

# 2. After verifying on dev: merge. GitHub Actions deploys main to dev automatically.
# 3. Ship to prod from a trusted machine:
git checkout main && git pull
deploy/deploy.sh prod        # refuses SHAs not on origin/main or not live on dev
```

Every deploy: drift check (stops if the server was edited outside git), server-side `php -l` on changed files,
tarball backup, mirror of owned paths, php-fpm restart when PHP changed, smoke test. State lives in
`/var/lib/ultralove-deploy/` on each server.

| Script | Purpose |
| --- | --- |
| `deploy/deploy.sh <env>` | Deploy a commit (`--ref`, `--dry-run`, `--yes`, `--adopt`, `--skip-dev-gate`) |
| `deploy/ship-dev.sh` | Push the current branch and deploy it to dev, printing one result line (full output in a temp log) |
| `deploy/pull-live.sh <env>` | Copy live owned files into the working tree to commit server-side drift |
| `deploy/rollback.sh <env> [backup]` | Restore the latest (or given) pre-deploy backup |
| `deploy/smoke-test.sh <env>` | HTTP smoke test of key pages |

Never edit files directly on the servers. If it happens anyway, the next deploy stops on drift; run
`deploy/pull-live.sh` and commit it.

## Notes

- **Style changes ship with their critical CSS.** Logged-out pages inline `css/critical.css` and load the full
  stylesheet after it. After a CSS change merges, the "Critical CSS" workflow opens a "Regenerate critical CSS" PR
  if the inlined CSS changed; merge it (and let its dev deploy finish) before deploying to prod.
- **Purge Cloudflare** after a prod deploy that changes page HTML: APO serves cached HTML until then.
- PHP-FPM OPcache on prod doesn't revalidate timestamps; `deploy.sh` restarts PHP-FPM when PHP files change.
- Backups: UpdraftPlus (database, plugins, themes) and the `ops/prod/` scripts (media library, server config) go
  to the Spaces bucket `ultralove-web-backups` - see `documentation/ARCHITECTURE.md` section 7.
