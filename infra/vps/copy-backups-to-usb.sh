#!/usr/bin/env bash
# Copies the encrypted backups of the mill server to a USB disk (the second copy that does not need the
# internet). Run as root with the disk plugged in:
#   sudo bash copy-backups-to-usb.sh               (uses the first disk mounted under /media)
#   sudo bash copy-backups-to-usb.sh /media/usb    (a given mount point)
# Backups are already encrypted with RICEMILL_BACKUP_KEY; the disk alone does not reveal anything.
set -euo pipefail

SRC="${SRC:-/srv/ricemill/shared/storage/backups}"
DEST_ROOT="${1:-$(find /media /mnt -mindepth 1 -maxdepth 2 -type d 2>/dev/null | while read -r d; do mountpoint -q "$d" && echo "$d"; done | head -1)}"
[[ -n "$DEST_ROOT" && -d "$DEST_ROOT" ]] || { echo "No USB disk found. Plug it in (and mount it) or give its path." >&2; exit 1; }
mountpoint -q "$DEST_ROOT" || { echo "$DEST_ROOT is not a mounted disk." >&2; exit 1; }

DEST="$DEST_ROOT/ricemill-backups"
mkdir -p "$DEST"
copied=0
for f in "$SRC"/*.rmbak; do
  [[ -e "$f" ]] || continue
  name="$(basename "$f")"
  if [[ ! -f "$DEST/$name" ]] || ! cmp -s "$f.sha256" "$DEST/$name.sha256" 2>/dev/null; then
    cp "$f" "$f.sha256" "$DEST/"
    (cd "$DEST" && sha256sum -c "$name.sha256" >/dev/null) || { echo "Copy of $name is damaged; try another disk." >&2; exit 1; }
    copied=$((copied + 1))
  fi
done
# Keep the newest 60 backups on the disk.
ls -1t "$DEST"/*.rmbak 2>/dev/null | tail -n +61 | while read -r old; do rm -f "$old" "$old.sha256"; done
sync
echo "Copied $copied new backup(s) to $DEST. $(ls -1 "$DEST"/*.rmbak 2>/dev/null | wc -l) on the disk. Safe to unplug."
