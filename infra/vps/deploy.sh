#!/usr/bin/env bash
# Zero-downtime deploy: new release directory -> checks -> atomic `current` symlink -> health check,
# with automatic rollback when the new release is unhealthy. Run on the server as the ricemill user:
#   bash deploy.sh <git-ref>        (REPO defaults to the origin of the current release)
# A mill server without internet installs an update package instead (built by make-offline-release.sh):
#   bash deploy.sh /media/usb/ricemill-release-<commit>.tar.gz
set -euo pipefail

REF="${1:?usage: deploy.sh <git-ref> | <ricemill-release-*.tar.gz>}"
APP_ROOT="${APP_ROOT:-/srv/ricemill}"
PACKAGE=""
if [[ "$REF" == *.tar.gz && -f "$REF" ]]; then
  PACKAGE="$REF"
else
  REPO="${REPO:-$(git -C "$APP_ROOT/current" remote get-url origin 2>/dev/null || true)}"
  : "${REPO:?set REPO for the first deploy}"
fi
PHP_BIN="${PHP_BIN:-php}"
FPM_SERVICE="${FPM_SERVICE:-php8.3-fpm}"
APP_URL="$(grep -E '^APP_URL=' /etc/ricemill/ricemill.env | cut -d= -f2-)"
DOMAIN="${APP_URL#https://}"
KEEP=5
STAMP="$(date -u +%Y%m%d%H%M%S)"
RELEASE="$APP_ROOT/releases/$STAMP"
PREVIOUS="$(readlink -f "$APP_ROOT/current" 2>/dev/null || true)"

log() { printf '\n== %s\n' "$*"; }
artisan() { (cd "$1/app" && "$PHP_BIN" artisan "${@:2}"); }
switch_to() {
  ln -sfn "$1" "$APP_ROOT/current.tmp"
  mv -T "$APP_ROOT/current.tmp" "$APP_ROOT/current"
  sudo /usr/bin/systemctl reload "$FPM_SERVICE"
  sudo /usr/bin/supervisorctl restart 'ricemill-worker:*'
}
fail() {
  echo "!! $*" >&2
  rm -rf "$RELEASE"
  exit 1
}

if [[ -n "$PACKAGE" ]]; then
  log "unpack offline package $(basename "$PACKAGE")"
  if [[ -f "$PACKAGE.sha256" ]]; then
    (cd "$(dirname "$PACKAGE")" && sha256sum -c "$(basename "$PACKAGE").sha256") || fail "the package is damaged (checksum)"
  fi
  mkdir -p "$RELEASE"
  tar -xzf "$PACKAGE" -C "$RELEASE"
  echo "commit $(cat "$RELEASE/RELEASE_COMMIT" 2>/dev/null || echo unknown)"
else
  log "fetch $REF"
  git clone --quiet --no-checkout "$REPO" "$RELEASE"
  git -C "$RELEASE" checkout --quiet "$REF"
  echo "commit $(git -C "$RELEASE" rev-parse --short=12 HEAD)"
fi

log "link shared state (secrets stay in /etc/ricemill, uploads and backups in shared/storage)"
rm -rf "$RELEASE/app/storage"
ln -s "$APP_ROOT/shared/storage" "$RELEASE/app/storage"
ln -s /etc/ricemill/ricemill.env "$RELEASE/app/.env"

log "dependencies"
if [[ -n "$PACKAGE" && -f "$RELEASE/app/vendor/autoload.php" ]]; then
  echo "included in the package"
else
  (cd "$RELEASE/app" && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress) \
    || fail "composer install failed"
fi

log "preflight (any FAIL blocks the deploy; new migrations are expected at this point)"
artisan "$RELEASE" ricemill:preflight --before-migrate || fail "preflight failed; nothing was changed"

if [[ -n "$PREVIOUS" ]]; then
  log "backup before migrating"
  artisan "$PREVIOUS" backup:run || fail "pre-deploy backup failed; nothing was changed"
fi

log "migrate"
artisan "$RELEASE" migrate --force || fail "migration failed; the previous release is still live"

log "preflight after migrating (row-level security on the new tables, nothing pending)"
artisan "$RELEASE" ricemill:preflight || fail "preflight after migrating failed; the previous release is still live"

log "caches"
artisan "$RELEASE" optimize
artisan "$RELEASE" filament:optimize || true

log "switch"
switch_to "$RELEASE"

log "health check"
healthy=0
for _ in $(seq 1 10); do
  # Right after a first install the scheduler / backup checks may still be "degraded";
  # the deploy only requires the application, database and cache to answer.
  body="$(curl -sS --max-time 10 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/health" || true)"
  if grep -q '"database":{"ok":true' <<<"$body" && grep -q '"cache":{"ok":true' <<<"$body"; then
    healthy=1
    break
  fi
  sleep 3
done

if [[ $healthy -ne 1 ]]; then
  echo "!! the new release is unhealthy" >&2
  if [[ -n "$PREVIOUS" ]]; then
    echo "!! rolling back to $PREVIOUS (migrations are additive, so the old code runs on the new schema)" >&2
    switch_to "$PREVIOUS"
  fi
  exit 1
fi

log "clean up old releases (keep $KEEP)"
find "$APP_ROOT/releases" -mindepth 1 -maxdepth 1 -type d | sort -r | tail -n +$((KEEP + 1)) | xargs -r rm -rf
echo "Deployed $REF as release $STAMP."
