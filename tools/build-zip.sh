#!/usr/bin/env bash
# Build a release zip (flurfunk-<version>.zip) of the tracked files, without tests, tools and dev files.
set -euo pipefail
cd "$(dirname "$0")/.."
V=$(sed -n "s/^const FLURFUNK_VERSION = '\(.*\)';/\1/p" flurfunk.php)
python3 tools/strings.py >/dev/null && python3 tools/l10n.py >/dev/null
OUT="$(pwd)/dist" && rm -rf "$OUT" && mkdir -p "$OUT/flurfunk"
# Only files Git knows (as they are in the working tree), so stray local files never ship.
SRC=$(mktemp -d) && trap 'rm -rf "$SRC"' EXIT
git ls-files -z | tar --null -cf - -T - | tar -xf - -C "$SRC"
rsync -a --exclude-from=.distignore "$SRC/" "$OUT/flurfunk/"
(cd "$OUT" && zip -qr "flurfunk-$V.zip" flurfunk && rm -rf flurfunk)
echo "$OUT/flurfunk-$V.zip"
