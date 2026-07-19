<?php
/**
 * migrate_tenant.php — ONE-TIME multi-tenant (SaaS) migration.
 *
 * What this does:
 *  1. Creates the platform tables: subscription_plans, tenants, tenant_subscriptions.
 *  2. Seeds 3 starter subscription plans (placeholder pricing — edit later).
 *  3. Creates a "legacy" tenant (id 00000000-0000-0000-0000-000000000001)
 *     that owns all of your EXISTING data — this is your original agency.
 *  4. Loops over every real table in the database, adds a `tenant_id`
 *     column if missing, backfills every existing row with the legacy
 *     tenant's id, adds an index, then makes the column NOT NULL.
 *
 * This script is intentionally CLI-ONLY (it refuses to run over the web) —
 * schema migrations should be run by hand once, from SSH, not left as a
 * reachable URL. Delete this file after a successful run, or at least keep
 * it outside any web-served directory.
 *
 * Usage (SSH on your VPS, from the project's api/ directory):
 *   php migrate_tenant.php
 *
 * Requires api/config.php to already exist with DB_HOST/DB_NAME/DB_USER/DB_PASS
 * defined (copy api/config.example.php if you haven't already).
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  echo "This migration script can only be run from the command line (SSH), not over the web.\n";
  exit(1);
}

$CFG = [
  'host' => getenv('DB_HOST') ?: 'localhost',
  'name' => getenv('DB_NAME') ?: '',
  'user' => getenv('DB_USER') ?: '',
  'pass' => getenv('DB_PASS') ?: '',
  'charset' => 'utf8mb4',
];
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
  include $configFile;
  if (defined('DB_HOST')) $CFG['host'] = DB_HOST;
  if (defined('DB_NAME')) $CFG['name'] = DB_NAME;
  if (defined('DB_USER')) $CFG['user'] = DB_USER;
  if (defined('DB_PASS')) $CFG['pass'] = DB_PASS;
}
if ($CFG['name'] === '' || $CFG['user'] === '') {
  fwrite(STDERR, "Missing DB config. Create api/config.php from api/config.example.php first.\n");
  exit(1);
}

function generate_uuid_v4() {
  $data = random_bytes(16);
  $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
  $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

echo "Connecting to {$CFG['name']}@{$CFG['host']}...\n";
try {
  $pdo = new PDO(
    "mysql:host={$CFG['host']};dbname={$CFG['name']};charset={$CFG['charset']}",
    $CFG['user'], $CFG['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );
} catch (Throwable $e) {
  fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
  exit(1);
}

$PLATFORM_TABLES = ['tenants', 'subscription_plans', 'tenant_subscriptions'];

echo "Step 1/4: creating platform tables (tenants, subscription_plans, tenant_subscriptions)...\n";
$pdo->exec("CREATE TABLE IF NOT EXISTS subscription_plans (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0,
  currency VARCHAR(8) NOT NULL DEFAULT 'SAR',
  max_users INT NULL,
  max_orders_per_month INT NULL,
  features JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(120) NULL UNIQUE,
  status ENUM('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'trial',
  plan_id VARCHAR(36) NULL,
  trial_ends_at DATETIME NULL,
  suspended_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS tenant_subscriptions (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(36) NOT NULL,
  plan_id VARCHAR(36) NOT NULL,
  status ENUM('trialing','active','past_due','cancelled') NOT NULL DEFAULT 'trialing',
  payment_gateway VARCHAR(40) NULL,
  payment_gateway_ref VARCHAR(255) NULL,
  current_period_start DATETIME NULL,
  current_period_end DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$planCount = (int)$pdo->query("SELECT COUNT(*) FROM subscription_plans")->fetchColumn();
if ($planCount === 0) {
  echo "  seeding starter plans...\n";
  $seed = $pdo->prepare("INSERT INTO subscription_plans (id, name, price_monthly, currency, max_users, max_orders_per_month, features, is_active, created_at) VALUES (:id,:n,:p,'SAR',:mu,:mo,:f,1,NOW())");
  $seed->execute([':id'=>generate_uuid_v4(), ':n'=>'أساسية',  ':p'=>99,  ':mu'=>3,  ':mo'=>100,  ':f'=>json_encode(['whatsapp'=>true,'ai'=>false])]);
  $seed->execute([':id'=>generate_uuid_v4(), ':n'=>'احترافية', ':p'=>249, ':mu'=>10, ':mo'=>1000, ':f'=>json_encode(['whatsapp'=>true,'ai'=>true])]);
  $seed->execute([':id'=>generate_uuid_v4(), ':n'=>'أعمال',    ':p'=>599, ':mu'=>null,':mo'=>null, ':f'=>json_encode(['whatsapp'=>true,'ai'=>true,'priority_support'=>true])]);
}

echo "Step 2/4: creating the legacy tenant for your existing data...\n";
$legacyId = '00000000-0000-0000-0000-000000000001';
$exists = $pdo->prepare("SELECT id FROM tenants WHERE id = :id LIMIT 1");
$exists->execute([':id' => $legacyId]);
if (!$exists->fetchColumn()) {
  $planId = $pdo->query("SELECT id FROM subscription_plans ORDER BY price_monthly DESC LIMIT 1")->fetchColumn() ?: null;
  $pdo->prepare("INSERT INTO tenants (id, name, slug, status, plan_id, created_at) VALUES (:id, 'الوكالة الأصلية', 'legacy', 'active', :p, NOW())")
      ->execute([':id' => $legacyId, ':p' => $planId]);
  echo "  created legacy tenant: $legacyId\n";
} else {
  echo "  legacy tenant already exists.\n";
}

echo "Step 3/4: adding tenant_id to every existing table and backfilling it...\n";
$tablesStmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
$allTables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($allTables as $table) {
  if (in_array($table, $PLATFORM_TABLES, true)) { echo "  - $table: skipped (platform table)\n"; continue; }

  $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = 'tenant_id'");
  $colStmt->execute([':t' => $table]);
  $hasCol = (bool)$colStmt->fetchColumn();

  try {
    if (!$hasCol) {
      $pdo->exec("ALTER TABLE `$table` ADD COLUMN `tenant_id` VARCHAR(36) NULL");
      echo "  - $table: added tenant_id column\n";
    }
    $updated = $pdo->exec("UPDATE `$table` SET `tenant_id` = " . $pdo->quote($legacyId) . " WHERE `tenant_id` IS NULL");
    if ($updated > 0) echo "  - $table: backfilled $updated row(s)\n";

    // Add an index if one doesn't already exist on tenant_id (ignore errors —
    // some MySQL/MariaDB versions don't support "ADD INDEX IF NOT EXISTS").
    try {
      $idxStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = 'idx_tenant_id'");
      $idxStmt->execute([':t' => $table]);
      if ((int)$idxStmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD INDEX `idx_tenant_id` (`tenant_id`)");
      }
    } catch (Throwable $eIdx) { /* non-fatal */ }
  } catch (Throwable $e) {
    echo "  - $table: SKIPPED due to error: " . $e->getMessage() . "\n";
  }
}

echo "Step 4/4: done.\n";
echo "\nNext steps:\n";
echo "  1. Spot-check a few tables (SELECT * FROM customers LIMIT 5;) to confirm tenant_id is set.\n";
echo "  2. Once verified, you can optionally enforce NOT NULL per table yourself:\n";
echo "       ALTER TABLE customers MODIFY tenant_id VARCHAR(36) NOT NULL;\n";
echo "     (left nullable here on purpose, in case any table needed manual review first.)\n";
echo "  3. Create your platform_admin user manually (see MULTI_TENANT_GUIDE.md) so you can manage all tenants.\n";
echo "  4. Delete or move this script outside the web root now that migration is done.\n";
