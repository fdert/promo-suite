<?php
/**
 * Copy this file to api/config.php and fill in your real values.
 * api/config.php should NEVER be committed to git or included in a public
 * zip — add it to .gitignore. On Hostinger, you can also set these as
 * actual environment variables from hPanel instead of editing this file.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

/**
 * Shared secret for server-to-server (cron job) calls to endpoints like
 * cron-delivery-delay, cron-payment-delay, generate-recurring-expenses.
 * Generate a long random value, e.g.: php -r "echo bin2hex(random_bytes(32));"
 * Your Hostinger cron job command should then pass it, e.g.:
 *   curl -H "X-Cron-Secret: PASTE_THE_SAME_VALUE_HERE" \
 *        "https://yourdomain.com/fdert/api/index.php?service=functions&action=cron-delivery-delay"
 */
define('CRON_SECRET', 'change-me-to-a-long-random-value');

/**
 * Moyasar payment gateway (subscription billing). Get these from your
 * Moyasar dashboard: https://dashboard.moyasar.com/ -> Settings -> API Keys.
 * - PUBLISHABLE_KEY is safe to expose to the browser (used only to create a
 *   charge, never to read/refund one) — the frontend billing page needs it.
 * - SECRET_KEY must stay server-side only. Never send it to the frontend.
 * - WEBHOOK_SECRET: set the SAME value here and in Moyasar's dashboard
 *   webhook configuration, so incoming webhook calls can be verified.
 */
define('MOYASAR_PUBLISHABLE_KEY', '');
define('MOYASAR_SECRET_KEY', '');
define('MOYASAR_WEBHOOK_SECRET', '');
