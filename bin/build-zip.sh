#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="tk-currency-manager"
MAIN="$ROOT_DIR/tk-currency-manager.php"

if [[ ! -f "$MAIN" ]]; then
  echo "Cannot find $MAIN" >&2
  exit 1
fi

# Extract version from plugin header
VERSION=$(grep -E "^\s*\*\s*Version:\s*" "$MAIN" | sed -E 's/^[^:]+:\s*([^ ]+).*/\1/')
if [[ -z "${VERSION:-}" ]]; then
  echo "Failed to read version from plugin header" >&2
  exit 1
fi

OUT_ZIP="$ROOT_DIR/../${SLUG}-${VERSION}.zip"
OUT_TGZ="$ROOT_DIR/../${SLUG}-${VERSION}.tar.gz"
WORK_DIR="$(mktemp -d)" || exit 1
PKG_DIR="$WORK_DIR/$SLUG"
mkdir -p "$PKG_DIR"

echo "Packaging $SLUG v$VERSION → $OUT_ZIP"

# Copy files excluding dev-only via tar (portable)
tar -C "$ROOT_DIR" \
    --exclude-vcs \
    --exclude='./bin' \
    --exclude='./.github' \
    --exclude='./.gitattributes' \
    --exclude='./.gitignore' \
    --exclude='./CHANGELOG.md' \
    -cf - . | tar -C "$PKG_DIR" -xf -

if command -v zip >/dev/null 2>&1; then
  (cd "$WORK_DIR" && zip -r "$OUT_ZIP" "$SLUG" >/dev/null)
  echo "Done: $OUT_ZIP"
else
  echo "'zip' not found; creating tar.gz instead" >&2
  (cd "$WORK_DIR" && tar -czf "$OUT_TGZ" "$SLUG")
  echo "Done: $OUT_TGZ"
fi
