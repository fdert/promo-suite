<?php
/**
 * recover_core_schema.php — ONE-TIME recovery migration.
 *
 * WHY THIS EXISTS: the original codebase creates its own settings/config
 * tables automatically (users, ai_agent_settings, whatsapp_*, tenants, ...)
 * but was always written assuming the CORE business tables — customers,
 * orders, order_items, payments, expenses, service_types, installment_plans,
 * installment_payments, evaluations, invoices, employee_tasks — already
 * existed in the database. On a brand-new database (like this VPS's), they
 * never get created, causing "Table doesn't exist" errors on almost every
 * page. This script creates them, reconstructed from how the code actually
 * queries and inserts into them (column names, types, and relations).
 *
 * Safe to run more than once (CREATE TABLE IF NOT EXISTS everywhere).
 *
 * Usage (SSH, from the project's api/ directory):
 *   php recover_core_schema.php
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  echo "CLI only.\n";
  exit(1);
}

$CFG = ['host' => getenv('DB_HOST') ?: 'localhost', 'name' => getenv('DB_NAME') ?: '', 'user' => getenv('DB_USER') ?: '', 'pass' => getenv('DB_PASS') ?: ''];
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
  include $configFile;
  if (defined('DB_HOST')) $CFG['host'] = DB_HOST;
  if (defined('DB_NAME')) $CFG['name'] = DB_NAME;
  if (defined('DB_USER')) $CFG['user'] = DB_USER;
  if (defined('DB_PASS')) $CFG['pass'] = DB_PASS;
}
if ($CFG['name'] === '' || $CFG['user'] === '') {
  fwrite(STDERR, "Missing DB config. Make sure api/config.php exists.\n");
  exit(1);
}

echo "Connecting to {$CFG['name']}@{$CFG['host']}...\n";
$pdo = new PDO(
  "mysql:host={$CFG['host']};dbname={$CFG['name']};charset=utf8mb4",
  $CFG['user'], $CFG['pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Every core business table gets tenant_id VARCHAR(36) NULL from the start
// (so the existing multi-tenant isolation layer works immediately — no
// separate migrate_tenant.php pass needed for these specific tables).

$statements = [

'customers' => "CREATE TABLE IF NOT EXISTS customers (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(36) NULL,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(32) NULL,
  whatsapp VARCHAR(32) NULL,
  email VARCHAR(255) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'service_types' => "CREATE TABLE IF NOT EXISTS service_types (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(36) NULL,
  name VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'orders' => "CREATE TABLE IF NOT EXISTS orders (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(36) NULL,
  order_number VARCHAR(64) NULL,
  customer_id VARCHAR(36) NULL,
  service_type_id VARCHAR(36) NULL,
  status VARCHAR(64) NOT NULL DEFAULT 'قيد التنفيذ',
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  delivery_date DATE NULL,
  estimated_delivery_time VARCHAR(64) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_customer_id (customer_id),
  INDEX idx_order_number (order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'order_items' => "CREATE TABLE IF NOT EXISTS order_items (
  id VARCHAR(36) PRIMARY KEY,
  order_id VARCHAR(36) NOT NULL,
  item_name VARCHAR(255) NULL,
  description TEXT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order_id (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'payments' => "CREATE TABLE IF NOT EXISTS payments (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(36) NULL,
  order_id VARCHAR(36) NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_type VARCHAR(64) NULL,
  payment_method VARCHAR(64) NULL,
  payment_date DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_order_id (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'expenses' => "CREATE TABLE IF NOT EXISTS expenses (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(36) NULL,
  expense_type VARCHAR(64) NULL,
  category VARCHAR(64) NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  expense_date DATE NULL,
  description TEXT NULL,
  notes TEXT NULL,
  payment_method VARCHAR(64) NULL,
  source_type VARCHAR(32) NULL,
  source_ref_id VARCHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'installment_plans' => "CREATE TABLE IF NOT EXISTS installment_plans (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(36) NULL,
  order_id VARCHAR(36) NULL,
  customer_id VARCHAR(36) NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  number_of_installments INT NOT NULL DEFAULT 1,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_order_id (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'installment_payments' => "CREATE TABLE IF NOT EXISTS installment_payments (
  id VARCHAR(36) PRIMARY KEY,
  installment_plan_id VARCHAR(36) NOT NULL,
  installment_number INT NOT NULL DEFAULT 1,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  due_date DATE NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  reminder_sent_2days TINYINT(1) NULL DEFAULT 0,
  reminder_sent_1day TINYINT(1) NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_plan_id (installment_plan_id),
  INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'evaluations' => "CREATE TABLE IF NOT EXISTS evaluations (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(36) NULL,
  customer_id VARCHAR(36) NULL,
  order_id VARCHAR(36) NULL,
  evaluation_token VARCHAR(64) NULL,
  overall_rating TINYINT NULL,
  service_quality_rating TINYINT NULL,
  delivery_time_rating TINYINT NULL,
  communication_rating TINYINT NULL,
  price_value_rating TINYINT NULL,
  would_recommend TINYINT(1) NULL,
  feedback_text TEXT NULL,
  suggestions TEXT NULL,
  rating TINYINT NULL,
  comment TEXT NULL,
  sent_at DATETIME NULL,
  submitted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_token (evaluation_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'invoices' => "CREATE TABLE IF NOT EXISTS invoices (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(36) NULL,
  invoice_number VARCHAR(64) NULL,
  order_id VARCHAR(36) NULL,
  customer_id VARCHAR(36) NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'غير مدفوعة',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_order_id (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'employee_tasks' => "CREATE TABLE IF NOT EXISTS employee_tasks (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(36) NULL,
  employee_id VARCHAR(36) NULL,
  title VARCHAR(255) NULL,
  description TEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  due_date DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

foreach ($statements as $table => $sql) {
  try {
    $pdo->exec($sql);
    echo "  ✓ $table ready\n";
  } catch (Throwable $e) {
    echo "  ✗ $table FAILED: " . $e->getMessage() . "\n";
  }
}

// Foreign keys are intentionally NOT enforced (ADD CONSTRAINT) to avoid
// migration failures on any pre-existing partial data; indexes above give
// the same query performance without the strictness risk.

echo "\nDone. Reload the app — the 'table doesn't exist' errors should be gone.\n";
echo "If you use tenant isolation, existing rows in these tables (if any)\n";
echo "will need a tenant_id backfill — but since these tables were just\n";
echo "created empty, there is nothing to backfill right now.\n";
