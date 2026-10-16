#!/usr/bin/env bash
# hautoo dist-bridge — short entrypoint
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
exec php "$ROOT/bin/hdb.php" "$@"
