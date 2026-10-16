#!/usr/bin/env bash
# Pack Astro dist/ into a ZIP for hautoo-dist-bridge pull deploy.
# Usage: ./scripts/pack-dist.sh [/path/to/hautuu-project]
# Env:   DIST_BRIDGE_ZIP_OUT=/path/to/out.zip

set -euo pipefail

PROJECT_ROOT="${1:-.}"
DIST_DIR="${PROJECT_ROOT%/}/dist"

if [[ ! -d "$DIST_DIR" ]]; then
  echo "error: dist/ not found at $DIST_DIR (run npm run build first)" >&2
  exit 1
fi

OUT="${DIST_BRIDGE_ZIP_OUT:-${PROJECT_ROOT%/}/dist-bridge.zip}"
OUT_ABS="$(cd "$(dirname "$OUT")" && pwd)/$(basename "$OUT")"

rm -f "$OUT_ABS"
(
  cd "$DIST_DIR"
  # Contents of dist/ at ZIP root (not a nested "dist/" folder)
  zip -r -q "$OUT_ABS" .
)

echo "wrote $OUT_ABS ($(du -h "$OUT_ABS" | cut -f1))"
