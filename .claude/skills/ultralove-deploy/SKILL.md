---
name: ultralove-deploy
description: Deploy Ultralove WordPress code (child/parent theme, custom plugins, MU plugins) to dev.ultralove.ca or ultralove.ca from this git repo, pull server-side drift back into git, or roll back a bad deploy. Use whenever a theme/plugin/mu-plugin change needs to go live, when someone asks to "deploy", "push to dev/prod", "ship", "sync with the server", or when a server has been edited directly and git needs to catch up.
---

# Ultralove deploys

All site code lives in this repo under `wp-content/`. Servers get it **only** through
`deploy/deploy.sh`, never through hand-edits, scp, or `git pull` in the webroot.

What the repo owns on the servers is defined in `deploy/owned-paths.rules`:
`mu-plugins/` (except ManageWP's `0-worker.php`), `themes/martfury`, `themes/martfury-child`,
`plugins/hide-price-until-login`, `plugins/ultralove-*`, and the Cloudinary/object-cache
files in `wp-content/`. Everything else (uploads, vendor plugins, caches) is never touched.
Adding a new custom plugin means updating **both** `.gitignore` and `owned-paths.rules`.

## Standard change → dev → prod

1. Branch from `main`, make the change, commit, push, open a PR. CI lints PHP (8.3),
   shellchecks `deploy/`, and blocks SQL dumps/archives/backups/large files.
2. Deploy the branch to dev and let the user verify it there:
   ```bash
   deploy/deploy.sh dev --ref <branch-or-sha> --dry-run   # review the change list
   deploy/deploy.sh dev --ref <branch-or-sha> --yes
   ```
3. After the user confirms dev, merge the PR. **The "Deploy to dev" GitHub Action
   deploys the merge commit to dev automatically** (check it passed: `gh run list
   --workflow deploy-dev.yml -L 1`). Then deploy to prod from a trusted machine. Prod
   refuses any SHA that is not on `origin/main` and not already recorded as live on dev:
   ```bash
   git checkout main && git pull
   deploy/deploy.sh prod --dry-run
   deploy/deploy.sh prod --yes
   ```
   `--skip-dev-gate "reason"` exists for emergencies or while dev is down. Say so
   explicitly to the user and log a real reason; never use it silently.

Even a one-line CSS fix follows dev → prod. Don't push to prod first and backfill dev.

### Style changes ship with their critical CSS

Logged-out pages inline `martfury-child/css/critical.css` (the first screen's CSS) and load the full
stylesheet afterwards (`mu-plugins/34-critical-css.php`, `ops/critical-css/README.md`). A change to anything on the
first screen - header, menu, banner, toolbar, product cards, page container - deployed without a regenerated
critical CSS shows the old styles first and then jumps when the full stylesheet arrives. This happened on
2026-10-01: #46's header menu fix went to prod before its critical CSS, and the menu wrapped, then unwrapped.

So for any change to CSS (child `style.css`, trimmed copies, plugin stylesheets, markup the first screen uses):

1. Merge it. The "Critical CSS" workflow runs after the dev deploy (~5-10 minutes) and opens or updates the PR
   "Regenerate critical CSS" (branch `auto/critical-css`) if the inlined CSS changes.
2. Check before deploying to prod: `gh run list --workflow critical-css.yml -L 1` (wait for it to finish) and
   `gh pr list --head auto/critical-css`. If a regeneration PR is open, have it merged (and its dev deploy pass)
   first, then deploy both together.
3. After a prod deploy that changed CSS, confirm the live inlined critical CSS has the change (fetch a page with a
   cache-busting query string and look inside `<style id="aoatfcss">`).

## What deploy.sh does (so you can explain failures)

1. Resolves the ref to a SHA; requires it to be pushed (and on `origin/main` for prod).
2. **Drift check:** exports the last deployed SHA (`/var/lib/ultralove-deploy/deployed_sha`
   on the server) and compares it to live files with `rsync --checksum -n`. Any difference
   means someone edited the server directly, and the deploy stops. Git-ignored junk
   (`*.bak*`, `*.zip`, `desktop.ini`) found in owned paths is moved to
   `/var/lib/ultralove-deploy/quarantine/` instead of blocking.
3. Shows the change list (`>f` = create/update, `*deleting` = remove) and asks to confirm.
4. Uploads to `/var/lib/ultralove-deploy/staging` and runs the server's `php -l` on every
   changed PHP file; aborts before touching the webroot if any fail.
5. Tars the current owned files to `/var/lib/ultralove-deploy/backups/<ts>-<prevsha>.tgz`
   (keeps 20), mirrors staging into `wp-content/`, `chown www-data`.
6. **Restarts php8.3-fpm when any PHP file changed.** Opcache on these servers does not
   revalidate timestamps, so without this the old code keeps running.
7. Records the SHA plus a line in `deploy.log`, then runs `deploy/smoke-test.sh`
   (`/`, `/shop/`, `/my-account/`, `/wp-login.php` must return 200 with no PHP fatal text).

## When the drift check fails

The server was edited outside git. **The live files are authoritative.** Never
overwrite them to get a deploy through.

```bash
git checkout -b sync/<env>-drift-<date> <deployed-sha>
deploy/pull-live.sh <env>          # copies live owned files into wp-content/
git diff                           # show the user what was changed on the server
git commit -am "sync: capture live <env> edits" && git push   # then PR → main
```
Then rebase or merge the pending change onto that and redeploy.

## Rollback

- Preferred: redeploy the previous good commit: `deploy/deploy.sh <env> --ref <good-sha> --yes`
  (for prod, the dev gate applies; use `--skip-dev-gate "rollback"` if dev already moved on).
- Emergency (no git needed): `deploy/rollback.sh <env>` restores the newest backup tarball,
  restarts php-fpm and smoke-tests. `deploy/rollback.sh <env> <file.tgz>` picks an older one.

## First deploy to a fresh server (e.g. rebuilt dev)

A server with no `deployed_sha` must be baselined once:
- Server already has the right code: `deploy/deploy.sh <env> --adopt` (verifies live == HEAD
  of main, records it, writes nothing else).
- Brand-new server: copy the code with `ops/do-migration/sync-site-files.sh`, then `--adopt`.

## Rules

- Never commit or deploy DB dumps, `wp-config.php`, `.env`, uploads or credentials. Nginx
  on prod returns 403 for `/documentation/`, `*.sql`, `*.md`, `*.bak*` and dotfiles as a
  backstop, but don't rely on it.
- Don't put anything but deployable site code in the webroot; docs, scripts and SQL
  belong in the repo or in `/root`, never in `/var/www/html`.
- Hosts come from `deploy/envs/<env>.env` plus `~/.ssh/config` (`ultralove-prod-app`,
  `ultralove-dev-app`). Deploys need GNU rsync 3.x locally (`brew install rsync`).
- For infrastructure work (nginx, PHP-FPM, Redis, Cloudflare, backups), see
  `.github/skills/ultralove-infrastructure/SKILL.md` and `documentation/ARCHITECTURE.md`.
- Well-scoped deploys: act, verify, report. Check in with the user before prod,
  before `--skip-dev-gate`, and before anything destructive.

## Dev environment

dev.ultralove.ca is private (Cloudflare Tunnel + Access; SSH is the only open port) and
disposable. Everything about building, refreshing and checking it is in `ops/dev/`
(see `ops/dev/README.md`): `refresh-db.sh` for fresh anonymized data, `sync-code.sh` after
vendor/core updates on prod, `parity.sh` to compare versions, `rebuild.sh` to start over.
Dev smoke tests run on the server against nginx (`SMOKE_MODE=origin`), so they don't
need to get past Access. Never copy prod's `wp-config.php`, uploads or unanonymized
data to dev, and never give dev network or SSH access to prod (prod pushes to dev, not
the other way round).
