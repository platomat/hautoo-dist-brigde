#!/usr/bin/env bash
# Pack Astro dist/ into a ZIP for hautoo-dist-bridge pull deploy.
# Usage: ./scripts/pack-dist.sh [/path/to/hautuu-project]
#
# Cloudflare / CI: set HAUTOO_DIST_BRIDGE=1 so this script packs and embeds
# the ZIP into dist/ for same-deploy download (e.g. /dist-bridge.zip).
# Without HAUTOO_DIST_BRIDGE (or 0/false/no): exit 0, no ZIP.
#
# Env:
#   HAUTOO_DIST_BRIDGE=1
#   DIST_BRIDGE_ZIP_OUT=/path/to/out.zip
#   DIST_BRIDGE_ZIP_NAME=dist-bridge.zip

set -euo pipefail

flag_on() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

if ! flag_on "${HAUTOO_DIST_BRIDGE:-}"; then
  echo "HAUTOO_DIST_BRIDGE not set (or off): skip pack" >&2
  exit 0
fi

PROJECT_ROOT="${1:-.}"
DIST_DIR="${PROJECT_ROOT%/}/dist"
ZIP_NAME="${DIST_BRIDGE_ZIP_NAME:-dist-bridge.zip}"

if [[ ! -d "$DIST_DIR" ]]; then
  echo "error: dist/ not found at $DIST_DIR (run npm run build first)" >&2
  exit 1
fi

OUT="${DIST_BRIDGE_ZIP_OUT:-${PROJECT_ROOT%/}/${ZIP_NAME}}"
OUT_ABS="$(cd "$(dirname "$OUT")" && pwd)/$(basename "$OUT")"

# Drop stale copy inside dist so the new archive does not nest itself
rm -f "${DIST_DIR}/${ZIP_NAME}"

rm -f "$OUT_ABS"
(
  cd "$DIST_DIR"
  # Contents of dist/ at ZIP root (not a nested "dist/" folder); never include the bridge ZIP
  zip -r -q "$OUT_ABS" . -x "./${ZIP_NAME}" -x "${ZIP_NAME}"
)

cp -f "$OUT_ABS" "${DIST_DIR}/${ZIP_NAME}"
echo "wrote $OUT_ABS ($(du -h "$OUT_ABS" | cut -f1))"
echo "embedded ${DIST_DIR}/${ZIP_NAME} (deploy dist/ → /${ZIP_NAME})"
