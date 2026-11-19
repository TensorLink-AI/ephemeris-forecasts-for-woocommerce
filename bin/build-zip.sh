#!/usr/bin/env bash
# Build the installable plugin zip: only the files WordPress needs, in a folder named after the slug.
set -euo pipefail
SLUG=ephemeris-forecasts-for-woocommerce
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"
mkdir -p "$STAGE/$SLUG" "$OUT"
cp -r "$ROOT/$SLUG.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/LICENSE" "$ROOT/includes" "$STAGE/$SLUG/"
rm -f "$OUT/$SLUG.zip"
(cd "$STAGE" && zip -qr "$OUT/$SLUG.zip" "$SLUG")
rm -rf "$STAGE"
echo "$OUT/$SLUG.zip"
