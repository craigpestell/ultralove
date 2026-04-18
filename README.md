# Ultralove Website Repository

This repository tracks the site-specific code and operational scripts for the Ultralove WordPress installation in `/var/www/html`.

## What This Repository Contains

- `wp-content/themes/martfury/`: tracked parent theme source.
- `wp-content/themes/martfury-child/`: tracked child theme customizations.
- `wp-content/plugins/hide-price-until-login/`: tracked custom plugin code.
- `wp-content/mu-plugins/00-close-php-session-for-rest.php`: tracked MU plugin.
- `wp-content/object-cache.php`: Redis object cache drop-in.
- `wp-content/cloudinary-*.php`: Cloudinary maintenance and recovery scripts.
- `wp-content/run-cloudinary-workers.sh`: helper to run Cloudinary sync workers.
- `.htaccess`: web-server rewrite and cache directives as deployed on this host.

## What Is Intentionally Not Tracked

- WordPress core files.
- `wp-config.php` and other secret-bearing configuration.
- `wp-content/uploads/`, cache directories, backup directories, and generated exports.
- Plugin ZIP archives and backup artifacts.

The root `.gitignore` uses an allowlist approach, so only approved files and directories are versioned.

## Working Model

This repository reflects the live site codebase on the server. Make changes carefully and review diffs before committing.

Common workflow:

```bash
cd /var/www/html
git status
git add <paths>
git commit -m "Describe change"
git push origin main
```

## Notes

- The primary application is WordPress.
- The active customizations are centered around the `martfury` theme stack and the `hide-price-until-login` plugin.
- If additional custom plugins or MU plugins should be versioned later, update `.gitignore` first so tracking stays deliberate.