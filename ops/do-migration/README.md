# DigitalOcean Migration Implementation

This folder contains scripts to perform a staged migration in this order:
1. Development environment first
2. Development validation gate
3. Production environment only after gate passes

## Target sizing
- Production app: s-2vcpu-4gb
- Development app: s-2vcpu-4gb
- Development DB: s-1vcpu-2gb

## Region layout
- Droplets (app + dev DB): sfo2
- Spaces backups bucket: sfo3

## 1) Prepare environment
1. Install doctl locally.
2. Authenticate: doctl auth init
3. Copy .env.example to .env and fill all required values.

## 2) Provision droplets
Run from this directory:

./provision-droplet.sh dev-app
./provision-droplet.sh dev-db
./provision-droplet.sh prod-app

## 3) Bootstrap development first
On the new dev app droplet:

sudo ./bootstrap-app-server.sh dev.ultralove.ca /var/www/html

Note: the app bootstrap script auto-detects an available PHP-FPM version from apt repos (8.4/8.3/8.2/8.1, then generic `php-fpm`) and skips `awscli` if not available.
You can force a version explicitly if needed, for example:

sudo ./bootstrap-app-server.sh dev.ultralove.ca /var/www/html 8.3

If AWS CLI was installed manually (zip installer), bootstrap will detect the existing `aws` binary and will not attempt apt installation.

On the new dev DB droplet:

sudo ./bootstrap-dev-db-server.sh wordpress_dev ultra_dev "<db_password>" "10.0.0.0/8"

## 4) Sync website files to the new development app
Run from your machine in this directory, or directly on the old production server.

From local files:

./sync-site-files.sh dev

From the old production server itself (no staging folder):

./sync-site-files.sh dev --from-source-server

From the old production web server (through your machine):
1. Set these in `.env`: `SRC_APP_IP`, `SRC_APP_SSH_USER`, `SRC_APP_SSH_PORT`, `SRC_WP_PATH`, `DEST_WP_ROOT`
2. Run:

./sync-site-files.sh dev --from-prod-server

By default this mode uses a persistent local staging directory so repeated runs are incremental with rsync. You can override it with `SYNC_STAGING_DIR` in `.env`.

## 5) Seed and sanitize development database
Run from an allowed host with DB connectivity:

Set target dev DB connection in `.env`:
- `DEV_DB_HOST=<dev-db-private-ip-or-hostname>`
- `DEV_DB_PORT=3306`

./migrate-dev-db.sh

## 6) Validate development and create promotion gate
After updating dev DNS to the new dev app droplet:

./validate-dev-and-gate.sh

This script verifies dev endpoints using DEV_NEW_APP_IP and writes:

.dev-validation-passed

## 7) Bootstrap and cut over production after gate
On the new prod app droplet:

sudo ./bootstrap-app-server.sh ultralove.ca /var/www/html

Before production DNS change, enforce gate:

./promote-to-prod.sh

This script exits non-zero unless .dev-validation-passed exists.

Then run production verification:

./cutover-verify.sh ultralove.ca <new_prod_app_ip>

After successful checks, switch production DNS.

## 8) Rollback window
1. Keep old dev and prod droplets running.
2. Monitor 24-72h.
3. Decommission old droplets only after stable metrics and backup confidence.

## Notes
- Existing migration script and nginx config remain useful:
  - ../nginx-migration/migrate.sh
  - ../nginx-migration/ultralove.ca.nginx.conf
- Existing Cloudinary validation script remains useful post-cutover:
  - ../cloudinary-verify-production.sh
- Rotate credentials currently present in scripts after migration.

## Troubleshooting
- Error: "Failed to resolve VPC"
1. Ensure `DO_VPC_UUID` has no trailing spaces in `.env`.
2. Verify the VPC exists: `doctl vpcs list --format ID,Name,Region,Default`
3. Ensure VPC region matches `DO_REGION` (for example both `sfo2`).
4. If needed, leave `DO_VPC_UUID` empty to use the region default VPC.

- Error: `Unknown collation: 'utf8mb4_0900_ai_ci'`
1. This is a MySQL 8 source dump being imported into a MariaDB target.
2. `migrate-dev-db.sh` now auto-normalizes unsupported collations in the dump before import.
3. Re-run `./migrate-dev-db.sh`.
