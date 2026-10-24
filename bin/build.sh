#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="tk-currency-manager"
VERSION=$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$ROOT_DIR/tk-currency-manager.php")
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo 'Cannot read plugin version' >&2
  exit 1
fi
WORK_DIR=$(mktemp -d)
trap 'rm -rf "$WORK_DIR"' EXIT
mkdir -p "$ROOT_DIR/dist" "$WORK_DIR/$SLUG"
# Explicit allowlist: never ship test stores, credentials or development caches.
tar -C "$ROOT_DIR" -cf - LICENSE README.md readme.txt CHANGELOG.md tk-currency-manager.php uninstall.php includes assets languages docs/images | tar -C "$WORK_DIR/$SLUG" -xf -
if command -v zip >/dev/null 2>&1; then
  output="$ROOT_DIR/dist/$SLUG-$VERSION.zip"
  rm -f "$output"
  (cd "$WORK_DIR" && zip -rq "$output" "$SLUG")
else
  output="$ROOT_DIR/dist/$SLUG-$VERSION.tar.gz"
  tar -C "$WORK_DIR" -czf "$output" "$SLUG"
fi
echo "Built $output"
