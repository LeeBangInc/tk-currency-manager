#!/usr/bin/env bash
# Backward-compatible entrypoint.
set -euo pipefail
exec bash "$(dirname "$0")/build.sh" "$@"
