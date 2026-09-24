# VPS deployment (api.hypermed.co.tz / app.hypermed.co.tz)

Railway-style deploys on the Hostinger VPS (CyberPanel / OpenLiteSpeed,
Ubuntu 26.04). A push to `master` in **hypermed-api** or **hypermed-web**
runs `.github/workflows/deploy-vps.yml`, which streams the source to the VPS
over SSH; the server-side scripts here do the rest. These files are the
source of truth for what's installed on the server — edit here, then copy
to the server.

| File | Installed at | Purpose |
|---|---|---|
| `hypermed-deploy` | `/usr/local/bin/` | release dirs, composer (retried), migrate, `deploy/post-deploy.sh`, caches, atomic `current` switch, graceful OLS restart, health check, auto-rollback, keeps 5 releases |
| `hypermed-deploy-ssh` | `/usr/local/bin/` | forced command for CI keys; each key pinned to one app (`deploy <sha>` / `rollback` / `status`) |
| `hypermed-publish-update` | `/usr/local/bin/` | forced command for the desktop-release key; accepts only installer/tarball/`latest.json(.sig)`, publishes the feed last |
| `api.conf.example`, `web.conf.example` | `/etc/hypermed-deploy/{api,web}.conf` | per-app settings |
| `hypermed-api-queue.service` | `/etc/systemd/system/` | queue worker (restarted on every deploy) |
| `99-hypermed.ini` | `/usr/local/lsws/lsphp84/etc/php/8.4/mods-available/` | 20MB uploads for the PHP 8.4 sites |

**Layout per site** (`/home/<site>/app`): `releases/<time>-<sha7>/`,
`shared/.env`, `shared/storage/` (+ web: `shared/database.sqlite`,
`shared/updates/`), `current -> releases/…`. The CyberPanel site's
`public_html` is a symlink to `app/current/public`.

**Access**: user `hmdeploy` has SSH keys only, each with a forced command,
and sudo for exactly `hypermed-deploy` and `hypermed-publish-update`
(`/etc/sudoers.d/hmdeploy`). Secrets live in the repos' GitHub Actions
settings (`VPS_HOST`, `VPS_KNOWN_HOSTS`, `VPS_DEPLOY_KEY` / `VPS_UPDATES_KEY`).

**Other server pieces**: PostgreSQL 18 (localhost only, DB/role
`hypermed_api`, password in `/root/hypermed-secrets/`), Redis on localhost
(API uses DBs 2/3 with a `hypermed_api_` prefix), scheduler via the API site
user's crontab (`schedule:run` every minute).

**By hand (as root on the VPS)**:

    hypermed-deploy api status
    hypermed-deploy api rollback
    git archive --format=tar.gz HEAD | ssh root@<vps> "hypermed-deploy api deploy $(git rev-parse HEAD)"
    KEEP_FAILED=1 hypermed-deploy …   # leave a failed release in place to debug

**Gotcha**: OpenLiteSpeed caches `.htaccess` rules by real path, so a
symlink switch needs a (graceful) `lswsctrl restart` — the deploy script
does this; a plain lsphp restart is not enough (you get OLS 404s on every
Laravel route).
