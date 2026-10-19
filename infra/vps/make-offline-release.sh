#!/usr/bin/env bash
# Builds an update package for a mill server WITHOUT internet: the code of a Git ref plus all PHP
# packages, in one file to carry on a USB stick. Run on any Linux PC (or the internet server) that
# has git, PHP 8.3 and composer, from a checkout of this repository:
#   bash infra/vps/make-offline-release.sh main
# Then on the mill server:  bash /srv/ricemill/current/infra/vps/deploy.sh /media/usb/ricemill-release-<commit>.tar.gz
set -euo pipefail

REF="${1:?usage: make-offline-release.sh <git-ref>}"
ROOT="$(git rev-parse --show-toplevel)"
COMMIT="$(git -C "$ROOT" rev-parse --short=12 "$REF")"
WORK="$(mktemp -d)"
OUT="${OUT:-$PWD}/ricemill-release-${COMMIT}.tar.gz"
trap 'rm -rf "$WORK"' EXIT

echo "== code of $REF ($COMMIT)"
git -C "$ROOT" archive --format=tar "$REF" | tar -x -C "$WORK"
echo "$COMMIT" > "$WORK/RELEASE_COMMIT"

echo "== PHP packages (no dev tools)"
(cd "$WORK/app" && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress)

echo "== package"
tar -czf "$OUT" -C "$WORK" .
sha256sum "$OUT" | tee "$OUT.sha256"
echo "Copy $(basename "$OUT") and its .sha256 to the mill server (USB stick)."
