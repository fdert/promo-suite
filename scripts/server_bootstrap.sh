#!/usr/bin/env bash
# server_bootstrap.sh — run ONCE by hand over SSH on a fresh Hostinger VPS,
# before the first automated deploy. Creates the directory layout that every
# future deploy relies on:
#
#   ~/deployments/
#     releases/            <- one timestamped folder per deploy (versioned code)
#     shared/               <- persistent data that must survive every deploy:
#       api/config.php       (DB credentials + secrets — never in git)
#       uploads/              (customer files, WhatsApp media, etc.)
#       logs/                 (PHP error log)
#     current -> releases/<latest>   (symlink your web server's DocumentRoot points to)
#
# Usage (as the deploy user, e.g. via `ssh youruser@your-vps 'bash -s' < server_bootstrap.sh`):
#   DEPLOY_PATH=/home/youruser/deployments bash server_bootstrap.sh

set -euo pipefail

DEPLOY_PATH="${DEPLOY_PATH:-$HOME/deployments}"

echo "Bootstrapping deploy structure at: $DEPLOY_PATH"

mkdir -p "$DEPLOY_PATH/releases"
mkdir -p "$DEPLOY_PATH/shared/api"
mkdir -p "$DEPLOY_PATH/shared/uploads/whatsapp"
mkdir -p "$DEPLOY_PATH/shared/logs"

# Placeholder config.php so the very first deploy has *something* to symlink
# to (you MUST edit this with real DB credentials before going live).
if [ ! -f "$DEPLOY_PATH/shared/api/config.php" ]; then
  cat > "$DEPLOY_PATH/shared/api/config.php" << 'PHP'
<?php
// EDIT THIS FILE with your real values. See backend/api/config.example.php
// for the full list of settings (DB_*, CRON_SECRET, MOYASAR_*).
define('DB_HOST', 'localhost');
define('DB_NAME', 'CHANGE_ME');
define('DB_USER', 'CHANGE_ME');
define('DB_PASS', 'CHANGE_ME');
define('CRON_SECRET', 'CHANGE_ME_TO_A_LONG_RANDOM_VALUE');
define('MOYASAR_PUBLISHABLE_KEY', '');
define('MOYASAR_SECRET_KEY', '');
define('MOYASAR_WEBHOOK_SECRET', '');
PHP
  echo "Created placeholder shared/api/config.php — EDIT IT before going live."
fi

chmod 750 "$DEPLOY_PATH/shared/api/config.php"
chmod -R 775 "$DEPLOY_PATH/shared/uploads" "$DEPLOY_PATH/shared/logs"

echo ""
echo "Done. Directory tree:"
find "$DEPLOY_PATH" -maxdepth 3 | sort

echo ""
echo "Next steps:"
echo "  1. Edit $DEPLOY_PATH/shared/api/config.php with your real DB credentials."
echo "  2. Point your Apache VirtualHost's DocumentRoot to: $DEPLOY_PATH/current"
echo "     (see deploy/apache-vhost.conf.example in this deploy tool)."
echo "  3. Run your first deploy (push to main, or run scripts/deploy_from_ci.sh manually)."
echo "  4. AFTER the first successful deploy, run the multi-tenant migration ONCE:"
echo "       cd $DEPLOY_PATH/current/api && php migrate_tenant.php"
echo "     and create your platform admin account:"
echo "       cd $DEPLOY_PATH/current/api && php create_platform_admin.php you@email.com 'StrongPass123' 'Your Name'"
