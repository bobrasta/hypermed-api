#!/bin/bash
# One-time (re-runnable) setup of live chat (Laravel Reverb) on the VPS.
# Run as root:  bash deploy/vps/setup-reverb.sh   (from a hypermed-api checkout)
#
#  1. picks a free loopback port (8080, else 8086/8087/8088)
#  2. adds BROADCAST_CONNECTION=reverb + REVERB_* keys to shared/.env
#     (keeps existing keys; backs .env up first)
#  3. installs hypermed-api-reverb.service and starts it
#  4. adds the `websocket /app/` proxy to the API vhost (backed up first)
#     and gracefully restarts OpenLiteSpeed
#  5. installs this repo's hypermed-deploy (restarts Reverb on deploys) and
#     REVERB_SERVICE in /etc/hypermed-deploy/api.conf
#  6. re-caches config and checks that the websocket answers through https
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"
APP_ROOT=/home/api.hypermed.co.tz/app
ENV="$APP_ROOT/shared/.env"
SITE_USER=apihy7135
PHP=/usr/local/lsws/lsphp84/bin/php
VHOST=/usr/local/lsws/conf/vhosts/api.hypermed.co.tz/vhost.conf
UNIT=/etc/systemd/system/hypermed-api-reverb.service
STAMP="$(date +%Y%m%d-%H%M%S)"
say() { printf '\033[1;36m==>\033[0m %s\n' "$*"; }

[ -f "$ENV" ] || { echo "missing $ENV" >&2; exit 1; }
[ -f "$VHOST" ] || { echo "missing $VHOST" >&2; exit 1; }
[ -f "$APP_ROOT/current/vendor/laravel/reverb/composer.json" ] || {
  echo "the live release has no laravel/reverb yet: deploy the messaging branch first" >&2; exit 1; }

envget() { grep -E "^$1=" "$ENV" | tail -1 | cut -d= -f2- | tr -d '"' || true; }
envset() {  # replace or append KEY=VALUE
  if grep -qE "^$1=" "$ENV"; then sed -i "s|^$1=.*|$1=$2|" "$ENV"; else printf '%s=%s\n' "$1" "$2" >> "$ENV"; fi
}

# 1. Port: keep the one already configured, else the first free one.
PORT="$(envget REVERB_SERVER_PORT)"
if [ -z "$PORT" ]; then
  for p in 8080 8086 8087 8088; do
    ss -ltn "( sport = :$p )" | grep -q ":$p" || { PORT=$p; break; }
  done
fi
[ -n "$PORT" ] || { echo "no free port among 8080 8086 8087 8088" >&2; exit 1; }
say "Reverb port: $PORT"

# 2. .env keys
cp -a "$ENV" "$ENV.bak-reverb-$STAMP"
[ -n "$(envget REVERB_APP_ID)" ]     || envset REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
[ -n "$(envget REVERB_APP_KEY)" ]    || envset REVERB_APP_KEY "$(openssl rand -hex 10)"
[ -n "$(envget REVERB_APP_SECRET)" ] || envset REVERB_APP_SECRET "$(openssl rand -hex 32)"
envset BROADCAST_CONNECTION reverb
envset REVERB_HOST 127.0.0.1
envset REVERB_PORT "$PORT"
envset REVERB_SCHEME http
envset REVERB_SERVER_HOST 127.0.0.1
envset REVERB_SERVER_PORT "$PORT"
envset MESSAGING_REALTIME_HOST api.hypermed.co.tz
envset MESSAGING_CALLS_ENABLED false
envset MESSAGING_PUSH_DRIVER null
envset MESSAGING_PRESENCE_CHECKER broadcaster
say ".env updated (backup: $(basename "$ENV").bak-reverb-$STAMP)"

# 3. systemd unit
sed "s|--port=8080|--port=$PORT|" "$HERE/hypermed-api-reverb.service" > "$UNIT"
systemctl daemon-reload
systemctl enable hypermed-api-reverb >/dev/null 2>&1

# 4. OpenLiteSpeed websocket proxy
if grep -qE '^\s*websocket\s+/app/' "$VHOST"; then
  sed -i "/^\s*websocket\s\+\/app\//,/}/ s|address\s\+127\.0\.0\.1:[0-9]\+|address                 127.0.0.1:$PORT|" "$VHOST"
  say "vhost already proxies /app/ (port set to $PORT)"
else
  cp -a "$VHOST" "$VHOST.bak-reverb-$STAMP"
  cat >> "$VHOST" <<EOF

websocket /app/ {
  address                 127.0.0.1:$PORT
  note                    Laravel Reverb - Hypermed live chat
}
EOF
  say "vhost: added websocket /app/ (backup: $(basename "$VHOST").bak-reverb-$STAMP)"
fi

# 5. deploy script + config
install -m 0755 "$HERE/hypermed-deploy" /usr/local/bin/hypermed-deploy
grep -q '^REVERB_SERVICE=' /etc/hypermed-deploy/api.conf || echo 'REVERB_SERVICE=hypermed-api-reverb' >> /etc/hypermed-deploy/api.conf

# 6. caches, restarts, check
runuser -u "$SITE_USER" -- "$PHP" "$APP_ROOT/current/artisan" config:cache >/dev/null
/usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
pkill -u "$SITE_USER" -f lsphp || true
systemctl restart hypermed-api-reverb hypermed-api-queue
sleep 3
systemctl is-active --quiet hypermed-api-reverb || { journalctl -u hypermed-api-reverb -n 30 --no-pager; echo "Reverb did not start" >&2; exit 1; }

KEY="$(envget REVERB_APP_KEY)"
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 --http1.1 \
  -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' \
  -H "Sec-WebSocket-Key: $(openssl rand -base64 16)" \
  --resolve api.hypermed.co.tz:443:127.0.0.1 "https://api.hypermed.co.tz/app/$KEY?protocol=7&client=check&version=8.4.0" || true)"
if [ "$code" = 101 ]; then
  say "OK: wss://api.hypermed.co.tz/app/<key> answers (HTTP 101)"
else
  echo "websocket check returned HTTP $code (expected 101); Reverb itself is running:" >&2
  ss -ltnp | grep ":$PORT" >&2 || true
  exit 1
fi
say "done. Apps get the connection details from GET /api/messaging/settings."
