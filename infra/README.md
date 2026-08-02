# Testbed infrastructure

Docker config for `http://100.127.55.45:8080`. Source of truth is here; the deployed copy
lives at `Z:\ruslan\wordpress-test\`.

## Applying it

```powershell
powershell -File C:\AI_IMAGE\tools\sync.ps1 -InfraToo
```

Then on the server:

```bash
cd ~/wordpress-test && docker compose up -d --build
```

First build takes a few minutes if Imagick has to be compiled. Subsequent starts are instant.

## What changed from the original compose, and why

| Change | Reason |
|---|---|
| `build:` + `Dockerfile` instead of `image: wordpress:latest` | Guarantees Imagick; pins WordPress to 7.0.2 so it cannot upgrade mid-project |
| `./plugins/ai-cake-topper` mounted | Previously only `./themes` was mounted — plugin development was impossible |
| `./aicake-files → /var/lib/aicake` | Generated images land outside the webroot (HTTP cannot reach them) while staying openable from Windows |
| `memory_limit 512M`, `max_execution_time 300` | 128 MB fatals on an A4 300 DPI image (8.7M pixels, ~35 MB per copy in memory) |
| `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY=false` | Errors to a file. Displaying them would corrupt REST/AJAX JSON responses |
| `env_file: .env` + `AICAKE_*` constants | API keys as constants, never in `wp_options`, so they stay out of DB backups |
| `wpcli` service (profile `tools`) | `docker compose run --rm wpcli wp …` |
| `mailpit` service | Read WooCommerce order emails at :8025 without mailing a real customer |
| `healthcheck` + `depends_on: condition` | WordPress no longer races the database on boot |
| `extra_hosts: host.docker.internal` | For testing WordPress loopback requests, which the job dispatcher relies on |

## Safety notes

- The plugin mount targets **one subdirectory**, not `wp-content/plugins`. Mounting the parent
  would hide WooCommerce and every other installed plugin, since those live in the `wp_data`
  named volume.
- `themes/` is unchanged and belongs to the separate theme project. Do not sync over it.
- `db_data` and `wp_data` volumes are untouched, so the existing site and database survive a
  rebuild.
- `00-dev-mail.php` redirects **all** outgoing mail to Mailpit. It must never be deployed to
  production.

## Verifying after the rebuild

```bash
docker compose exec wordpress php -r "var_dump(extension_loaded('imagick'), extension_loaded('gd'), ini_get('memory_limit'), ini_get('max_execution_time'));"
```

Expect: `true`, `true`, `"512M"`, `"300"`.

```bash
docker compose exec wordpress sh -c 'ls -la /var/lib/aicake && touch /var/lib/aicake/.write-test && rm /var/lib/aicake/.write-test && echo WRITABLE'
```

If it is not writable, `chown -R 33:33` the host directory (`33` is `www-data`).

## Rolling back

The original compose is preserved in git history. `docker compose down && docker compose up -d`
with the old file restores the previous state — the database and WordPress files are in named
volumes and are not affected.
