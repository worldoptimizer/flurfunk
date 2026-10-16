#!/usr/bin/env bash
# Build a release zip (flurfunk-<version>.zip) without tests, tools and dev files.
set -euo pipefail
cd "$(dirname "$0")/.."
V=$(sed -n "s/^const FLURFUNK_VERSION = '\(.*\)';/\1/p" flurfunk.php)
python3 tools/strings.py >/dev/null && python3 tools/l10n.py >/dev/null
OUT="$(pwd)/dist" && rm -rf "$OUT" && mkdir -p "$OUT/flurfunk"
rsync -a --exclude-from=.distignore ./ "$OUT/flurfunk/"
(cd "$OUT" && zip -qr "flurfunk-$V.zip" flurfunk && rm -rf flurfunk)
echo "$OUT/flurfunk-$V.zip"
