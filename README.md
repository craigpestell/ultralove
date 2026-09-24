# Ultralove Website Repository

Source of truth for the custom code running on the Ultralove WordPress/WooCommerce
site: **ultralove.ca** (prod) and **dev.ultralove.ca** (dev). `main` is what's live on prod.

## Layout

| Path | What |
| --- | --- |
| `wp-content/themes/martfury/` | Parent theme (vendor, with local fixes) |
| `wp-content/themes/martfury-child/` | Child theme customizations |
| `wp-content/plugins/hide-price-until-login/` | Custom plugin |
| `wp-content/plugins/ultralove-*/` | Custom plugins (banners, product export/stock export) |
| `wp-content/mu-plugins/` | Custom MU plugins (ManageWP's `0-worker.php` excluded) |
| `wp-content/object-cache.php`, `cloudinary-*.php`, `run-cloudinary-workers.sh` | Redis drop-in and Cloudinary maintenance scripts |
| `deploy/` | Deploy pipeline (see below) |
| `ops/dev/` | Private, disposable dev environment: build, refresh, parity (see `ops/dev/README.md`) |
| `ops/nginx/` | Reference copy of prod nginx config (not auto-deployed) |
| `ops/do-migration/` | DigitalOcean provisioning/migration scripts (`.env` not tracked) |
| `documentation/` | Architecture and work log |
| `.claude/skills/ultralove-deploy/` | Claude Code skill describing the deploy workflow |
| `.github/skills/ultralove-infrastructure/` | Infrastructure operations runbook |

Not tracked: WordPress core, vendor plugins, `wp-config.php`, uploads, caches, DB dumps,
backups. `.gitignore` is an allowlist; new custom code must be added there **and** to
`deploy/owned-paths.rules`.

## Deploying

Requires GNU rsync (`brew install rsync`) and SSH access to `ultralove-prod-app` /
`ultralove-dev-app`.

```bash
# 1. PR → CI (PHP 8.3 lint, shellcheck, no dumps/archives). Try it on dev:
deploy/deploy.sh dev --ref my-branch --dry-run
deploy/deploy.sh dev --ref my-branch

# 2. After verifying on dev: merge. GitHub Actions deploys main to dev automatically.
# 3. Ship to prod from a trusted machine:
git checkout main && git pull
deploy/deploy.sh prod        # refuses SHAs not on origin/main or not live on dev
```

Every deploy: drift check (stops if the server was edited outside git), server-side
`php -l` on changed files, tarball backup, mirror of owned paths, php-fpm restart when
PHP changed, smoke test. State lives in `/var/lib/ultralove-deploy/` on each server.

| Script | Purpose |
| --- | --- |
| `deploy/deploy.sh <env>` | Deploy a commit (`--ref`, `--dry-run`, `--yes`, `--adopt`, `--skip-dev-gate`) |
| `deploy/pull-live.sh <env>` | Copy live owned files into the working tree to commit server-side drift |
| `deploy/rollback.sh <env> [backup]` | Restore the latest (or given) pre-deploy backup |
| `deploy/smoke-test.sh <env>` | HTTP smoke test of key pages |

Never edit files directly on the servers. If it happens anyway, the next deploy stops
on drift; run `deploy/pull-live.sh` and commit it.

## Notes

- If Elementor styling differs for guests vs logged-in users, check for stale generated CSS
  in `wp-content/uploads/elementor/css/` (fix: Elementor → Tools → Clear Files & Data, then
  purge caches).
- PHP-FPM opcache on prod doesn't revalidate timestamps; deploy.sh restarts FPM on PHP changes.
