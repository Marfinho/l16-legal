#!/usr/bin/env bash
# Baut das installierbare Plugin-Paket: dist/lindenweg16-legal-suite-<version>.zip + .sha256
# Enthält nur, was auf den Server gehört (keine Tests, Entwicklungswerkzeuge, vendor/).
#   Aufruf: bin/build-zip.sh [Zielordner]
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
SLUG=lindenweg16-legal-suite
VERSION="$(grep -m1 -E "^ \* Version:" "$HERE/$SLUG.php" | sed -E 's/.*Version:\s*//' | tr -d '[:space:]')"
CONST="$(grep -m1 "define( 'LL16_VERSION'" "$HERE/$SLUG.php" | sed -E "s/.*'LL16_VERSION', '([^']+)'.*/\1/")"
[ "$VERSION" = "$CONST" ] || { echo "Versionskonflikt: Header $VERSION ≠ LL16_VERSION $CONST" >&2; exit 1; }
OUT="${1:-$HERE/dist}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG" "$OUT"
cd "$HERE"
cp -r src assets templates uninstall.php "$SLUG.php" README.md "$STAGE/$SLUG/"
find "$STAGE" -name '.DS_Store' -delete
(cd "$STAGE" && zip -qr "$OUT/$SLUG-$VERSION.zip" "$SLUG")
(cd "$OUT" && sha256sum "$SLUG-$VERSION.zip" > "$SLUG-$VERSION.zip.sha256")
echo "gebaut: $OUT/$SLUG-$VERSION.zip"
cat "$OUT/$SLUG-$VERSION.zip.sha256"
