#!/usr/bin/env bash
# rollback.sh — run ON THE VPS to instantly revert to a previous release.
# No rebuild needed: old releases are kept on disk (see KEEP_RELEASES in
# activate_release.sh), so rollback is just a symlink swap — a few
# milliseconds of "downtime" at most.
#
# Usage:
#   List available releases:
#     DEPLOY_PATH=/home/youruser/deployments bash rollback.sh
#   Roll back to the one just before the current one:
#     DEPLOY_PATH=/home/youruser/deployments bash rollback.sh --previous
#   Roll back to a specific release:
#     DEPLOY_PATH=/home/youruser/deployments bash rollback.sh 20260719120000

set -euo pipefail

DEPLOY_PATH="${DEPLOY_PATH:-$HOME/deployments}"
cd "$DEPLOY_PATH/releases"

CURRENT_TARGET="$(readlink -f "$DEPLOY_PATH/current" || true)"
CURRENT_NAME="$(basename "${CURRENT_TARGET:-}")"

if [ "${1:-}" = "" ]; then
  echo "Currently live: ${CURRENT_NAME:-unknown}"
  echo ""
  echo "Available releases (newest first):"
  # shellcheck disable=SC2012
  ls -1t | nl -ba
  echo ""
  echo "Usage: bash rollback.sh --previous | bash rollback.sh <release_name>"
  exit 0
fi

if [ "$1" = "--previous" ]; then
  # shellcheck disable=SC2012
  TARGET="$(ls -1t | grep -A1 -x "$CURRENT_NAME" | tail -n1)"
  if [ -z "$TARGET" ] || [ "$TARGET" = "$CURRENT_NAME" ]; then
    echo "ERROR: no earlier release found to roll back to." >&2
    exit 1
  fi
else
  TARGET="$1"
fi

TARGET_DIR="$DEPLOY_PATH/releases/$TARGET"
if [ ! -d "$TARGET_DIR" ]; then
  echo "ERROR: release not found: $TARGET_DIR" >&2
  exit 1
fi

echo "Rolling back: $CURRENT_NAME -> $TARGET"
ln -sfn "$TARGET_DIR" "$DEPLOY_PATH/current.tmp"
mv -T "$DEPLOY_PATH/current.tmp" "$DEPLOY_PATH/current"
echo "Done. Live release is now: $TARGET"
echo "Note: this does NOT revert database changes (e.g. schema migrations) — only application code."
