#!/usr/bin/env bash
# activate_release.sh — run ON THE VPS (via SSH from CI, or by hand) AFTER a
# new release folder has already been uploaded to releases/<name>/.
#
# What it does, in order:
#   1. Symlinks the persistent shared resources (config.php, uploads/, logs/)
#      INTO the new release, so they're never overwritten by a deploy and
#      never duplicated on disk.
#   2. Atomically re-points the `current` symlink to the new release.
#      (Symlink swap is atomic on Linux — visitors never see a half-deployed
#      site, and if any step above fails, `current` is simply never touched
#      and the previous release keeps serving traffic.)
#   3. Deletes releases older than the last N (default 5), so disk usage
#      doesn't grow forever — but always keeps enough for a quick rollback.
#
# Usage:
#   DEPLOY_PATH=/home/youruser/deployments bash activate_release.sh 20260719120000

set -euo pipefail

DEPLOY_PATH="${DEPLOY_PATH:-$HOME/deployments}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
RELEASE_NAME="${1:?Usage: activate_release.sh <release_name>}"
RELEASE_DIR="$DEPLOY_PATH/releases/$RELEASE_NAME"
SHARED_DIR="$DEPLOY_PATH/shared"

if [ ! -d "$RELEASE_DIR" ]; then
  echo "ERROR: release directory not found: $RELEASE_DIR" >&2
  exit 1
fi

echo "Linking shared resources into release $RELEASE_NAME ..."

# Remove whatever placeholder came from git (if any) and symlink to the
# persistent shared copy instead.
rm -f "$RELEASE_DIR/api/config.php"
ln -s "$SHARED_DIR/api/config.php" "$RELEASE_DIR/api/config.php"

rm -rf "$RELEASE_DIR/uploads"
ln -s "$SHARED_DIR/uploads" "$RELEASE_DIR/uploads"

rm -rf "$RELEASE_DIR/logs"
ln -s "$SHARED_DIR/logs" "$RELEASE_DIR/logs"

echo "Verifying the new release's PHP files at least parse before going live..."
if command -v php >/dev/null 2>&1; then
  if ! php -l "$RELEASE_DIR/api/index.php" > /tmp/deploy_lint_$$.log 2>&1; then
    echo "ERROR: api/index.php failed php -l syntax check. Aborting activation — 'current' left untouched." >&2
    cat /tmp/deploy_lint_$$.log >&2
    rm -f /tmp/deploy_lint_$$.log
    exit 1
  fi
  rm -f /tmp/deploy_lint_$$.log
else
  echo "WARNING: php CLI not found on this server — skipping syntax pre-check."
fi

echo "Switching 'current' -> releases/$RELEASE_NAME ..."
ln -sfn "$RELEASE_DIR" "$DEPLOY_PATH/current.tmp"
mv -T "$DEPLOY_PATH/current.tmp" "$DEPLOY_PATH/current"

echo "Live. Pruning old releases (keeping last $KEEP_RELEASES) ..."
cd "$DEPLOY_PATH/releases"
# shellcheck disable=SC2012
ls -1t | tail -n "+$((KEEP_RELEASES + 1))" | while read -r old; do
  echo "  removing old release: $old"
  rm -rf "${old:?}"
done

echo "Deploy of $RELEASE_NAME activated successfully."
