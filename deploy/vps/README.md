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
| `hypermed-api-reverb.service` | `/etc/systemd/system/` | live chat websocket server, Laravel Reverb on 127.0.0.1 (restarted on every deploy) |
| `setup-reverb.sh` | run once as root | installs the Reverb unit, `.env` keys, the OLS websocket proxy and the new `hypermed-deploy`; safe to re-run |
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

## Live chat (Reverb)

In-app messaging pushes messages over a websocket. Laravel Reverb runs as
`hypermed-api-reverb` on `127.0.0.1:<port>` (8080 unless taken). OpenLiteSpeed
proxies `wss://api.hypermed.co.tz/app/<REVERB_APP_KEY>` to it (a `websocket
/app/` block in the API vhost). Apps sign in to private channels at
`POST https://api.hypermed.co.tz/api/broadcasting/auth` with their Sanctum
token. Laravel publishes events to Reverb over the loopback (`REVERB_HOST=127.0.0.1`).

One-time setup, as root on the VPS, from a checkout of this repo:

    bash deploy/vps/setup-reverb.sh

It is safe to re-run. It keeps existing keys, and it backs up `.env` and the
vhost config before changing them. If Reverb is down, chat still works: messages
save, and apps catch up when they reconnect or poll.

Check it: `systemctl status hypermed-api-reverb`, `journalctl -u hypermed-api-reverb -n 50`.
