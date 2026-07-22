<?php
// Unified API router (db/auth/storage/functions) for MySQL on shared hosting
// Supports:
// - Simple CRUD
// - Relational select: relation(field[,field...]) with optional join hint !inner (default LEFT)
// - Relational filters using dotted notation (e.g., orders.customer_id)
// - Column/table existence checks via INFORMATION_SCHEMA
// - Graceful handling for missing tables/views (returns [])

// SECURITY: never show PHP errors/stack traces to clients in production —
// this used to leak file paths and internal details. Errors are still
// captured, just to a log file instead of the HTTP response.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
$__errorLogDir = __DIR__ . '/../logs';
if (!is_dir($__errorLogDir)) { @mkdir($__errorLogDir, 0750, true); }
if (is_dir($__errorLogDir)) { ini_set('error_log', $__errorLogDir . '/php-error.log'); }

require_once __DIR__ . '/security.php';
@session_start();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Riyadh');


// Config (override via env or public/api/config.php)
$CFG = [
  // 'host' => getenv('DB_HOST') ?: 'localhost',
  // 'name' => getenv('DB_NAME') ?: 'promo_suite',
  // 'user' => getenv('DB_USER') ?: 'root',
  // 'pass' => getenv('DB_PASS') ?: '',
  'host' => getenv('DB_HOST') ?: 'localhost',
  'name' => getenv('DB_NAME') ?: 'your_db_name',
  'user' => getenv('DB_USER') ?: 'your_db_user',
  'pass' => getenv('DB_PASS') ?: 'your_db_password',
  'charset' => 'utf8mb4',
];
$external = __DIR__ . '/config.php';
if (is_file($external)) {
  include_once $external;
  if (defined('DB_HOST')) $CFG['host'] = DB_HOST;
  if (defined('DB_NAME')) $CFG['name'] = DB_NAME;
  if (defined('DB_USER')) $CFG['user'] = DB_USER;
  if (defined('DB_PASS')) $CFG['pass'] = DB_PASS;
}

function respond($data = null, $error = null, $status = 200) {
  if ($status !== 200) http_response_code($status);
  echo json_encode(['data' => $data, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function pdo() {
  static $pdo = null; global $CFG;
  if ($pdo) return $pdo;
  // Attempt primary connection with charset in DSN, then fallback without charset and SET NAMES
  $host = $CFG['host'] ?? 'localhost';
  $name = $CFG['name'] ?? '';
  $user = $CFG['user'] ?? '';
  $pass = $CFG['pass'] ?? '';
  $charset = $CFG['charset'] ?? 'utf8mb4';
  try {
    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=' . $charset;
    $pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_PERSISTENT => false,
    ]);
    return $pdo;
  } catch (Throwable $e) {
    // Fallback for shared hosts with charsets not initialized (e.g., SQLSTATE[HY000] [2019])
    try {
      $dsn2 = 'mysql:host=' . $host . ';dbname=' . $name;
      $pdo = new PDO($dsn2, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
      ]);
      // Set names manually
      try { $pdo->exec("SET NAMES 'utf8mb4'"); } catch (Throwable $e1) { /* ignore */ }
      try { $pdo->exec("SET CHARACTER SET 'utf8mb4'"); } catch (Throwable $e2) { /* ignore */ }
      return $pdo;
    } catch (Throwable $e2) {
      respond(null, [ 'message' => 'DB connection failed: ' . $e2->getMessage(), 'code' => 'db_connect' ], 500);
    }
  }
}

function read_json_body() {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

// Route for service=functions placed after config and pdo() to avoid early $CFG access
$__service = $_GET['service'] ?? $_POST['service'] ?? null;

// Service: Database CRUD
if ($__service === 'db') {
  // SECURITY: this generic CRUD endpoint can read/write ANY table in the
  // database by name. It previously had NO login requirement at all, which
  // meant anyone on the internet who guessed a table name (customers,
  // orders, payments, employees, salary_payments, users, ...) could read or
  // write company data without authenticating. Every table now requires a
  // logged-in session. If you later need a genuinely public, read-only
  // endpoint (e.g. a customer-facing satisfaction survey), add a narrow,
  // explicit exception here rather than removing this check.
  require_auth();
  $body = read_json_body();
  $action = isset($body['action']) ? strtolower(trim((string)$body['action'])) : strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? '')));
  // Accept possible aliases like "orders o" or backticks and pick the base identifier only
  $rawTable = isset($body['table']) ? (string)$body['table'] : (string)($_GET['table'] ?? $_POST['table'] ?? '');
  if (preg_match('/^[`\s]*([A-Za-z0-9_]+)/', $rawTable, $mTbl)) {
    $rawTable = $mTbl[1];
  }
  // Allow RPC without requiring a table identifier
  $isRpc = ($action === 'rpc');
  $table = ($rawTable !== '' && !$isRpc) ? sanitize_ident($rawTable) : $rawTable;

  // SECURITY: ai_agent_settings stores the LLM API key — never expose it through the generic DB API.
  // All reads/writes go through the dedicated ai-settings-* endpoints (which mask the key).
  if ($table === 'ai_agent_settings') {
    respond(null, ['message' => 'هذا الجدول محمي ولا يمكن الوصول إليه مباشرة'], 403);
  }

  // SECURITY: these tables hold secrets (inbound webhook secret, WaSender DB credentials,
  // WhatsApp API keys). They were previously readable WITHOUT login, which let anyone on the
  // internet fetch the shared secret and then call the secret-gated admin endpoints.
  // Only a logged-in user (validated against the users table) may touch them.
  if (in_array($table, ['whatsapp_inbound_settings', 'whatsapp_api_settings'], true)) {
    if (!ai_request_user()) {
      respond(null, ['message' => 'يتطلب تسجيل الدخول'], 401);
    }
  }

  // Make sure WhatsApp-related tables (e.g. whatsapp_api_settings) exist before generic CRUD touches them
  if ($table === 'whatsapp_api_settings' || $table === 'whatsapp_messages' || $table === 'webhook_logs') {
    try { ensure_whatsapp_schema(); } catch (Throwable $eSchema) { /* ignore */ }
  }
  // Make sure HR/finance tables (employees, salary_payments, fixed_expense_templates, whatsapp_fallback_settings)
  // and the expenses traceability columns exist before generic CRUD touches them
  if (in_array($table, ['employees', 'salary_payments', 'fixed_expense_templates', 'whatsapp_fallback_settings', 'expenses'], true)) {
    try { ensure_hr_finance_schema(); } catch (Throwable $eSchema2) { /* ignore */ }
  }
  try {
    $pdo = pdo();

    // Helper: get columns of table
    $getCols = function($tbl) use ($pdo) {
      $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
      $stmt->execute([':t' => $tbl]);
      $cols = [];
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $cols[$r['COLUMN_NAME']] = true; }
      return $cols;
    };

    // Helper: upsert service_type by name and return id
    $ensureServiceTypeId = function($name) use ($pdo) {
      $name = trim((string)$name);
      if ($name === '') return null;
      $id = null;
      try {
        $st = $pdo->prepare("SELECT id FROM service_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(:n)) LIMIT 1");
        $st->execute([':n' => $name]);
        $id = $st->fetchColumn();
        if (!$id) {
          $id = generate_uuid_v4();
          $ins = $pdo->prepare("INSERT INTO service_types (id, name, created_at) VALUES (:id, :n, NOW())");
          $ins->execute([':id' => $id, ':n' => $name]);
        }
      } catch (Throwable $e) { /* ignore, return null if fails */ }
      return $id ?: null;
    };

    // RPC handlers (no table required)
    if ($action === 'rpc') {
      $fn = trim((string)($body['fn'] ?? $body['function'] ?? ''));
      if ($fn === '') {
        respond(null, ['message' => 'Missing fn', 'code' => 'bad_rpc'], 400);
      }
      switch ($fn) {
        case 'generate_order_number': {
          $prefixDate = date('Ymd');
          $prefix = 'ORD-' . $prefixDate . '-';
          $nextNum = 1;
          try {
            $st = $pdo->prepare("SELECT `order_number` FROM `orders` WHERE `order_number` LIKE :p ORDER BY `order_number` DESC LIMIT 1");
            $st->execute([':p' => $prefix . '%']);
            $last = (string)($st->fetchColumn() ?: '');
            if ($last !== '') {
              if (preg_match('/-(\d+)$/', $last, $m)) {
                $nextNum = max(1, (int)$m[1] + 1);
              }
            }
          } catch (Throwable $e) {
            $nextNum = 1;
          }
          $candidate = $prefix . str_pad((string)$nextNum, 5, '0', STR_PAD_LEFT);
          try {
            $retry = 0;
            while ($retry < 3) {
              $chk = $pdo->prepare("SELECT 1 FROM `orders` WHERE `order_number` = :n LIMIT 1");
              $chk->execute([':n' => $candidate]);
              if (!$chk->fetchColumn()) break;
              $nextNum++;
              $candidate = $prefix . str_pad((string)$nextNum, 5, '0', STR_PAD_LEFT);
              $retry++;
            }
          } catch (Throwable $e) { /* ignore duplicate check */ }
          respond(['result' => $candidate], null, 200);
        }
        default:
          respond(null, ['message' => 'Unknown rpc function', 'code' => 'bad_rpc_fn', 'fn' => $fn], 400);
      }
    }

    if ($action === 'insert') {
      $data = $body['data'] ?? null;
      if (!is_array($data)) respond(null, ['message' => 'Invalid data'], 400);
      $cols = $getCols($table);
      $__tenantId = tenant_guard($table, $cols); // SECURITY: null => platform_admin (no scoping) or table not tenant-scoped
      $postInsertRecalc = function($tbl, $payload) use ($pdo) {
        try {
          if ($tbl === 'payments') {
            // Support single payment object or array of rows
            $rows = [];
            if (is_array($payload)) {
              if (isset($payload[0]) && is_array($payload[0])) { $rows = $payload; } else { $rows = [$payload]; }
            }
            $orderIds = [];
            foreach ($rows as $r) {
              if (!is_array($r)) continue;
              $oid = $r['order_id'] ?? null;
              if ($oid && is_string($oid) && trim($oid) !== '') { $orderIds[$oid] = true; }
            }
            foreach (array_keys($orderIds) as $oid) {
              // Recalculate paid_amount and remaining for the order
              $sum = 0.0;
              try {
                $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = :id");
                $st->execute([':id' => $oid]);
                $sum = (float)($st->fetchColumn() ?: 0);
              } catch (Throwable $e) { $sum = 0.0; }
              try {
                $pdo->prepare("UPDATE orders SET paid_amount = :p, remaining_amount = GREATEST(COALESCE(total_amount,0)-:p,0), updated_at = NOW() WHERE id = :id")
                    ->execute([':p' => $sum, ':id' => $oid]);
              } catch (Throwable $e) { /* ignore */ }
            }
          }
        } catch (Throwable $e) { /* ignore */ }
      };

      // Case 1: single row insert (associative array)
      if (is_assoc($data)) {
        // Special handling for orders: map service_name -> service_type_id
        if ($table === 'orders' && isset($data['service_name'])) {
          $sid = $ensureServiceTypeId($data['service_name']);
          if ($sid) { $data['service_type_id'] = $sid; }
          unset($data['service_name']);
        }
        // Auto-generate id and timestamps when columns exist and not provided
        try {
          if (isset($cols['id']) && !isset($data['id'])) { $data['id'] = generate_uuid_v4(); }
          $nowTs = date('Y-m-d H:i:s');
          if (isset($cols['created_at']) && !isset($data['created_at'])) { $data['created_at'] = $nowTs; }
          if (isset($cols['updated_at']) && !isset($data['updated_at'])) { $data['updated_at'] = $nowTs; }
        } catch (Throwable $eAuto) { /* ignore */ }
        // SECURITY: always force the row's tenant_id to the caller's own tenant —
        // never trust a client-supplied tenant_id, or a tenant could write into
        // another agency's data by simply passing a different tenant_id.
        if (isset($cols['tenant_id']) && $__tenantId !== null) { $data['tenant_id'] = $__tenantId; }

        $fields = [];
        $params = [];
        foreach ($data as $k => $v) {
          if (!isset($cols[$k])) continue; // skip unknown columns
          $fields[] = "`$k`";
          $vv = is_array($v) && isset($v['result']) ? $v['result'] : $v;
          if (is_bool($vv)) $vv = $vv ? 1 : 0;
          $params[":".$k] = $vv;
        }
        if (empty($fields)) respond(null, ['message' => 'No valid columns'], 400);
        $sql = "INSERT INTO `$table` (" . implode(',', $fields) . ") VALUES (" . implode(',', array_keys($params)) . ")";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $postInsertRecalc($table, $data);
        respond(['success' => true, 'inserted' => 1], null, 200);
      }

      // Case 2: bulk insert (array of rows)
      $rows = $data;
      if (!is_array($rows) || empty($rows)) respond(null, ['message' => 'Invalid data rows'], 400);
      // Ensure each row is associative and filter columns
      $first = $rows[0];
      if (!is_assoc($first)) respond(null, ['message' => 'Invalid row shape'], 400);

      // Normalize rows: handle orders mapping and auto id/timestamps
      if ($table === 'orders') {
        foreach ($rows as &$r) {
          if (isset($r['service_name'])) {
            $sid = $ensureServiceTypeId($r['service_name']);
            if ($sid) { $r['service_type_id'] = $sid; }
            unset($r['service_name']);
          }
          // auto id and timestamps per row
          if (isset($cols['id']) && !isset($r['id'])) { $r['id'] = generate_uuid_v4(); }
          $nowTs = date('Y-m-d H:i:s');
          if (isset($cols['created_at']) && !isset($r['created_at'])) { $r['created_at'] = $nowTs; }
          if (isset($cols['updated_at']) && !isset($r['updated_at'])) { $r['updated_at'] = $nowTs; }
          // SECURITY: force tenant_id per row — see single-insert note above.
          if (isset($cols['tenant_id']) && $__tenantId !== null) { $r['tenant_id'] = $__tenantId; }
        }
        unset($r);
      } else {
        foreach ($rows as &$r2) {
          if (isset($cols['id']) && !isset($r2['id'])) { $r2['id'] = generate_uuid_v4(); }
          $nowTs2 = date('Y-m-d H:i:s');
          if (isset($cols['created_at']) && !isset($r2['created_at'])) { $r2['created_at'] = $nowTs2; }
          if (isset($cols['updated_at']) && !isset($r2['updated_at'])) { $r2['updated_at'] = $nowTs2; }
          // SECURITY: force tenant_id per row — see single-insert note above.
          if (isset($cols['tenant_id']) && $__tenantId !== null) { $r2['tenant_id'] = $__tenantId; }
        }
        unset($r2);
      }

      // Re-evaluate first row after normalization
      $first = $rows[0];

      // Determine fields by intersecting with table columns from the first row
      $fields = [];
      foreach ($first as $k => $v) {
        if (isset($cols[$k])) { $fields[] = $k; }
      }
      if (empty($fields)) respond(null, ['message' => 'No valid columns'], 400);

      // Build placeholders and params for multi-row insert
      $fieldSql = '`' . implode('`,`', $fields) . '`';
      $valuesSqlParts = [];
      $params = [];
      foreach ($rows as $i => $row) {
        if (!is_assoc($row)) continue; // skip malformed row
        $placeholders = [];
        foreach ($fields as $f) {
          $ph = ":r{$i}_{$f}";
          $placeholders[] = $ph;
          $val = $row[$f] ?? null;
          if (is_array($val) && isset($val['result'])) { $val = $val['result']; }
          $params[$ph] = $val;
        }
        if (!empty($placeholders)) {
          $valuesSqlParts[] = '(' . implode(',', $placeholders) . ')';
        }
      }
      if (empty($valuesSqlParts)) respond(null, ['message' => 'No valid rows to insert'], 400);

      $sql = "INSERT INTO `$table` ($fieldSql) VALUES " . implode(',', $valuesSqlParts);
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $postInsertRecalc($table, $rows);
      respond(['success' => true, 'inserted' => count($valuesSqlParts)], null, 200);
    }

    if ($action === 'update') {
      $values = $body['values'] ?? ($body['data'] ?? null);
      $where = $body['where'] ?? null;
      // Support legacy filters array like select() by mapping eq ops to simple where AND
      if (!is_array($where)) {
        $filters = $body['filters'] ?? null;
        if (is_array($filters) && !empty($filters)) {
          $mapped = [];
          foreach ($filters as $f) {
            if (!is_array($f)) continue;
            $col = isset($f['column']) ? trim((string)$f['column']) : '';
            $op = strtolower(trim((string)($f['op'] ?? 'eq')));
            if ($col !== '' && preg_match('/^[A-Za-z0-9_]+$/', $col) && ($op === 'eq')) {
              $mapped[$col] = $f['value'] ?? null;
            }
          }
          if (!empty($mapped)) { $where = $mapped; }
        }
      }
      if (!is_array($values) || !is_array($where)) respond(null, ['message' => 'Invalid update payload'], 400);

      // Special handling for orders: map service_name -> service_type_id
      if ($table === 'orders') {
        if (isset($values['service_name'])) {
          $sid = $ensureServiceTypeId($values['service_name']);
          if ($sid) { $values['service_type_id'] = $sid; }
          unset($values['service_name']);
        }
      }

      $cols = $getCols($table);
      $__tenantId = tenant_guard($table, $cols);
      $sets = [];
      $params = [];
      foreach ($values as $k => $v) {
        if (!isset($cols[$k])) continue; // skip unknown columns
        // SECURITY: never allow a tenant user to move a row to another tenant
        // by changing its tenant_id through a generic update payload.
        if ($k === 'tenant_id' && $__tenantId !== null) continue;
        if (is_bool($v)) $v = $v ? 1 : 0;
        $sets[] = "`$k` = :set_".$k;
        $params[":set_".$k] = $v;
      }
      if (empty($sets)) respond(null, ['message' => 'No valid columns to update'], 400);

      // Very simple WHERE builder: supports equality ANDed
      $conds = [];
      foreach ($where as $k => $v) {
        if (is_bool($v)) $v = $v ? 1 : 0;
        $op = '=';
        $paramKey = ":w_".$k;
        $conds[] = "`$k` $op $paramKey";
        $params[$paramKey] = $v;
      }
      // SECURITY: scope the update to the caller's own tenant so a request
      // that only supplies e.g. {"id": "..."} in `where` cannot touch another
      // tenant's row even if the id happens to collide/be guessed.
      if ($__tenantId !== null) { $conds[] = "`tenant_id` = :tenant_scope"; $params[':tenant_scope'] = $__tenantId; }
      if (empty($conds)) respond(null, ['message' => 'Missing where'], 400);

      $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE " . implode(' AND ', $conds) . " LIMIT 1000";
      $st = $pdo->prepare($sql);
      $st->execute($params);

      // If updating payments rows, recalc affected orders
      if ($table === 'payments') {
        try {
          // Determine affected order_ids from WHERE when simple equality on order_id or id is used
          $orderIds = [];
          if (isset($where['order_id'])) {
            $oid = (string)$where['order_id']; if ($oid !== '') { $orderIds[$oid] = true; }
          } elseif (isset($where['id'])) {
            // look up payment by id
            $pid = (string)$where['id'];
            if ($pid !== '') {
              try {
                $stp = $pdo->prepare("SELECT order_id FROM payments WHERE id = :id LIMIT 1");
                $stp->execute([':id' => $pid]);
                $oid = (string)($stp->fetchColumn() ?: '');
                if ($oid !== '') { $orderIds[$oid] = true; }
              } catch (Throwable $e2) { /* ignore */ }
            }
          }
          foreach (array_keys($orderIds) as $oid) {
            $sum = 0.0;
            try {
              $st2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = :id");
              $st2->execute([':id' => $oid]);
              $sum = (float)($st2->fetchColumn() ?: 0);
            } catch (Throwable $e3) { $sum = 0.0; }
            try {
              $pdo->prepare("UPDATE orders SET paid_amount = :p, remaining_amount = GREATEST(COALESCE(total_amount,0)-:p,0), updated_at = NOW() WHERE id = :id")
                  ->execute([':p' => $sum, ':id' => $oid]);
            } catch (Throwable $e4) { /* ignore */ }
          }
        } catch (Throwable $e) { /* ignore */ }
      }

      respond(['success' => true, 'updated' => $st->rowCount()], null, 200);
    }

    if ($action === 'select') {
      // Special case: dynamic summary for installment plans if view/table is empty or missing
      if ($table === 'installment_plans_summary') {
        try {
          $pdo = pdo();
          // SECURITY: this hand-written report query bypasses the generic
          // WHERE builder, so it needs its own explicit tenant filter.
          $__ipTenantId = tenant_guard($table, ['tenant_id' => true]); // treat as tenant-scoped by convention
          $__ipWhere = '';
          $__ipParams = [];
          if ($__ipTenantId !== null) { $__ipWhere = " WHERE ip.tenant_id = :tenant_scope"; $__ipParams[':tenant_scope'] = $__ipTenantId; }
          $sql = "SELECT 
                    ip.id,
                    ip.order_id,
                    ip.customer_id,
                    COALESCE(ip.total_amount, 0) AS total_amount,
                    COALESCE(ip.number_of_installments, 0) AS number_of_installments,
                    COALESCE(ip.status, 'active') AS plan_status,
                    -- remaining = plan total - SUM(payments.amount for the order)
                    (COALESCE(ip.total_amount, 0) - COALESCE((
                        SELECT SUM(p.amount)
                        FROM payments p
                        WHERE p.order_id = ip.order_id
                    ), 0)) AS remaining_amount,
                    SUM(CASE WHEN ipm.status = 'paid' THEN 1 ELSE 0 END) AS paid_installments,
                    SUM(CASE WHEN ipm.status = 'pending' THEN 1 ELSE 0 END) AS pending_installments,
                    SUM(CASE WHEN ipm.status = 'overdue' THEN 1 ELSE 0 END) AS overdue_installments,
                    COALESCE(ip.created_at, o.created_at, NOW()) AS created_at,
                    o.order_number,
                    c.name AS customer_name,
                    COALESCE(c.whatsapp, c.phone) AS customer_phone
                  FROM installment_plans ip
                  LEFT JOIN installment_payments ipm ON ipm.installment_plan_id = ip.id
                  LEFT JOIN orders o ON ip.order_id = o.id
                  LEFT JOIN customers c ON ip.customer_id = c.id" . $__ipWhere . "
                  GROUP BY
                    ip.id,
                    ip.order_id,
                    o.order_number,
                    ip.customer_id,
                    c.name,
                    c.whatsapp,
                    c.phone,
                    ip.total_amount,
                    ip.number_of_installments,
                    ip.status,
                    ip.created_at,
                    o.created_at
                  ORDER BY created_at DESC";
          $st = $pdo->prepare($sql);
          $st->execute($__ipParams);
          $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
          respond($rows, null, 200);
        } catch (Throwable $e) {
          // graceful empty
          respond([], null, 200);
        }
      }

      // Enhanced select with simple relation(...) support to mirror Supabase flavor
      $columnsStr = trim((string)($body['columns'] ?? '*'));
      $where = $body['where'] ?? [];
      $filters = $body['filters'] ?? [];
      $params = [];
      $__selCols = $getCols($table);
      $__tenantId = tenant_guard($table, $__selCols);

      // Map website_settings legacy column names if needed
      if ($table === 'website_settings') {
        try {
          $colsMeta = $getCols($table);
          if (!isset($colsMeta['setting_value']) && isset($colsMeta['value'])) {
            $columnsStr = str_replace('setting_value', 'value', $columnsStr);
          }
          if (!isset($colsMeta['setting_key']) && isset($colsMeta['key'])) {
            $columnsStr = str_replace('setting_key', 'key', $columnsStr);
          }
          if (is_array($where)) {
            $mappedWhere = [];
            foreach ($where as $k => $v) {
              if ($k === 'setting_key' && isset($colsMeta['key'])) $k = 'key';
              if ($k === 'setting_value' && isset($colsMeta['value'])) $k = 'value';
              $mappedWhere[$k] = $v;
            }
            $where = $mappedWhere;
          }
          if (is_array($filters)) {
            foreach ($filters as &$f) {
              if (!is_array($f)) continue;
              if (($f['column'] ?? '') === 'setting_key' && isset($colsMeta['key'])) $f['column'] = 'key';
              if (($f['column'] ?? '') === 'setting_value' && isset($colsMeta['value'])) $f['column'] = 'value';
            }
            unset($f);
          }
        } catch (Throwable $e) { /* ignore */ }
      }

      $selectParts = [];
      $joins = [];
      $aliasCount = 0;

      $addJoin = function($key, $sql) use (&$joins) {
        if (!isset($joins[$key])) { $joins[$key] = $sql; }
      };

      // Helper: parse relation tokens like customers(name,phone) or service_types(name)
      $parseColumns = function($baseTable, $columnsStr) use (&$selectParts, &$addJoin, &$aliasCount) {
        $makeTokens = function($s) {
          $s = trim($s);
          $tokens = [];
          $buf = '';
          $depth = 0;
          $len = strlen($s);
          for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === '(') { $depth++; $buf .= $ch; continue; }
            if ($ch === ')') { if ($depth > 0) $depth--; $buf .= $ch; continue; }
            if ($ch === ',' && $depth === 0) { $tok = trim($buf); if ($tok !== '') $tokens[] = $tok; $buf = ''; continue; }
            $buf .= $ch;
          }
          $tok = trim($buf);
          if ($tok !== '') $tokens[] = $tok;
          return $tokens;
        };
        $tokens = $makeTokens($columnsStr);
        foreach ($tokens as $tok) {
          if ($tok === '' ) continue;
          // relation(table.fields)
          if (preg_match('/^(relation\(|)([A-Za-z0-9_]+)\(([^)]*)\)\)?$/', $tok, $m)) {
            $rel = $m[2];
            $fields = array_filter(array_map('trim', explode(',', $m[3])));
            if ($rel === 'customers') {
              $alias = 'c';
              $addJoin('customers', "LEFT JOIN `customers` $alias ON `$baseTable`.`customer_id` = $alias.`id`");
              foreach ($fields as $f) {
                if ($f === '*' || $f === '') continue;
                $as = ($f === 'name') ? 'customer_name' : (($f === 'whatsapp' || $f === 'phone') ? 'customer_' . $f : $f);
                $selectParts[] = "$alias.`$f` AS `$as`";
              }
            } elseif ($rel === 'service_types') {
              $alias = 's';
              $addJoin('service_types', "LEFT JOIN `service_types` $alias ON `$baseTable`.`service_type_id` = $alias.`id`");
              foreach ($fields as $f) {
                if ($f === '*' || $f === '') continue;
                $as = ($f === 'name') ? 'service_name' : $rel . '_' . $f;
                $selectParts[] = "$alias.`$f` AS `$as`";
              }
            } elseif ($rel === 'order_items') {
              // Aggregate one-to-many relation as JSON array of selected fields
              $aliasItems = 'oi';
              $jsonFields = [];
              foreach ($fields as $f) {
                if ($f === '' || $f === '*') continue;
                $safe = preg_replace('/[^A-Za-z0-9_]/', '', $f);
                $jsonFields[] = "'" . $safe . "', $aliasItems.`$safe`";
              }
              if (!empty($jsonFields)) {
                $json = 'JSON_ARRAYAGG(JSON_OBJECT(' . implode(', ', $jsonFields) . '))';
              } else {
                $json = 'JSON_ARRAYAGG(JSON_OBJECT(' . "'id', $aliasItems.`id`" . '))';
              }
              $selectParts[] = "(SELECT $json FROM `order_items` $aliasItems WHERE $aliasItems.`order_id` = `$baseTable`.`id`) AS `order_items`";
            } else {
              // Unknown relation: ignore to avoid SQL function call errors
            }
            continue;
          }
          // plain field or *
          if ($tok === '*' || preg_match('/^[A-Za-z0-9_.*]+$/', $tok)) {
            if ($tok === '*') {
              $selectParts[] = "`$baseTable`.*";
            } else {
              // support qualified like orders.* as well
              if ($tok === $baseTable . '.*') { $selectParts[] = "`$baseTable`.*"; }
              else { $selectParts[] = "`$baseTable`.`$tok`"; }
            }
          } else {
            // Skip unsafe tokens
          }
        }
      };

      $parseColumns($table, $columnsStr);
      if (empty($selectParts)) { $selectParts[] = "`$table`.*"; }

      $sql = "SELECT " . implode(', ', $selectParts) . " FROM `$table`";
      foreach ($joins as $j) { $sql .= " " . $j; }

      $clauses = [];
      if (is_array($where) && !empty($where)) {
        foreach ($where as $k => $v) {
          $p = ":w_".$k; $clauses[] = "`$table`.`$k` = $p"; $params[$p] = $v;
        }
      } elseif (is_array($filters) && !empty($filters)) {
        $i = 0;
        foreach ($filters as $f) {
          if (!is_array($f)) { $i++; continue; }
          $op = strtolower(trim((string)($f['op'] ?? 'eq')));
          $col = trim((string)($f['column'] ?? ''));
          $val = $f['value'] ?? null;
          if ($col === '' || !preg_match('/^[A-Za-z0-9_]+$/', $col)) { $i++; continue; }
          $qualified = "`$table`.`$col`";
          if ($op === 'eq') {
            $p = ":f_{$i}_{$col}"; $clauses[] = "$qualified = $p"; $params[$p] = $val;
          } elseif ($op === 'in' && is_array($val)) {
            $vals = (array)$val; $phs = []; $j = 0;
            foreach ($vals as $vv) { $ph = ":f_{$i}_{$col}_{$j}"; $phs[] = $ph; $params[$ph] = $vv; $j++; }
            if (!empty($phs)) { $clauses[] = "$qualified IN (" . implode(',', $phs) . ")"; }
          } elseif ($op === 'like' || $op === 'ilike') {
            $p = ":f_{$i}_{$col}"; $clauses[] = "$qualified LIKE $p"; $params[$p] = $val ?? '';
          } elseif ($op === 'gte') {
            $p = ":f_{$i}_{$col}"; $clauses[] = "$qualified >= $p"; $params[$p] = $val;
          } elseif ($op === 'lte') {
            $p = ":f_{$i}_{$col}"; $clauses[] = "$qualified <= $p"; $params[$p] = $val;
          } elseif ($op === 'gt') {
            $p = ":f_{$i}_{$col}"; $clauses[] = "$qualified > $p"; $params[$p] = $val;
          } elseif ($op === 'lt') {
            $p = ":f_{$i}_{$col}"; $clauses[] = "$qualified < $p"; $params[$p] = $val;
          } elseif ($op === 'between') {
            // value can be [start, end]
            if (is_array($val) && count($val) >= 2) {
              $p1 = ":f_{$i}_{$col}_from"; $p2 = ":f_{$i}_{$col}_to";
              $clauses[] = "$qualified BETWEEN $p1 AND $p2"; $params[$p1] = $val[0]; $params[$p2] = $val[1];
            }
          }
          $i++;
        }
      }
      // SECURITY: scope every select to the caller's own tenant (unless the
      // caller is a platform_admin, or the table isn't tenant-scoped yet).
      if ($__tenantId !== null) {
        $clauses[] = "`$table`.`tenant_id` = :tenant_scope";
        $params[':tenant_scope'] = $__tenantId;
      }
      if (!empty($clauses)) { $sql .= " WHERE " . implode(' AND ', $clauses); }
      // ORDER BY support (accepts direction or ascending boolean)
      $orderSpec = $body['order'] ?? ($body['orderBy'] ?? []);
      if (is_array($orderSpec) && !empty($orderSpec)) {
        $orderClauses = [];
        foreach ($orderSpec as $ob) {
          if (!is_array($ob)) continue;
          $col = isset($ob['column']) ? trim((string)$ob['column']) : '';
          if ($col === '') continue;
          // support either 'direction' or boolean 'ascending'
          $dir = 'ASC';
          if (isset($ob['direction'])) {
            $d = strtoupper(trim((string)$ob['direction']));
            if ($d === 'ASC' || $d === 'DESC') { $dir = $d; }
          } elseif (array_key_exists('ascending', $ob)) {
            $asc = (bool)$ob['ascending'];
            $dir = $asc ? 'ASC' : 'DESC';
          }
          if (preg_match('/^[A-Za-z0-9_]+$/', $col)) {
            $orderClauses[] = "`$table`.`$col` $dir";
          }
        }
        if (!empty($orderClauses)) { $sql .= ' ORDER BY ' . implode(', ', $orderClauses); }
      }

      $st = $pdo->prepare($sql);
      $st->execute($params);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      respond($rows, null, 200);
    }

    if ($action === 'delete') {
      $where = $body['where'] ?? null;
      if (!is_array($where)) respond(null, ['message' => 'Invalid delete payload'], 400);
      $__delCols = $getCols($table);
      $__tenantId = tenant_guard($table, $__delCols);
      $params = [];
      $clauses = [];
      foreach ($where as $k => $v) { $p = ":w_".$k; $clauses[] = "`$k` = $p"; $params[$p] = $v; }
      // SECURITY: scope the delete to the caller's own tenant — otherwise a
      // guessed/enumerated id in `where` could delete another tenant's row.
      if ($__tenantId !== null) { $clauses[] = "`tenant_id` = :tenant_scope"; $params[':tenant_scope'] = $__tenantId; }
      if (empty($clauses)) respond(null, ['message' => 'Missing where'], 400);
      $sql = "DELETE FROM `$table` WHERE " . implode(' AND ', $clauses) . " LIMIT 1000";
      $st = $pdo->prepare($sql);
      $st->execute($params);
      respond(['success' => true, 'deleted' => $st->rowCount()], null, 200);
    }

    respond(null, ['message' => 'Unsupported action'], 400);
  } catch (Throwable $e) {
    respond(null, ['message' => $e->getMessage(), 'code' => 'db_runtime'], 500);
  }
}

if ($__service === 'functions') {
  $body = read_json_body();
  $req = array_merge(is_array($_GET ?? null) ? $_GET : [], is_array($_POST ?? null) ? $_POST : [], is_array($body) ? $body : []);
  // Normalize action from multiple potential fields (action/function/name/event)
  $action = '';
  if (isset($req['action']) && $req['action'] !== '') { $action = (string)$req['action']; }
  elseif (isset($req['function']) && $req['function'] !== '') { $action = (string)$req['function']; }
  elseif (isset($req['name']) && $req['name'] !== '') { $action = (string)$req['name']; }
  elseif (isset($req['event']) && $req['event'] !== '') { $action = (string)$req['event']; }

  // SECURITY: this endpoint previously had NO login requirement at all,
  // covering ~50 actions including full-database SQL export/email
  // (send-daily-backup), full-database SQL RESTORE from an uploaded file
  // (restore-sql-backup), bulk order import, WhatsApp sending, and AI
  // provider settings (API keys). Anyone on the internet could call any of
  // these without authenticating.
  //
  // - cron-* / generate-recurring-expenses / process-*-queue: these are
  //   meant to be triggered by a server cron job, not a browser, so they
  //   accept either a logged-in session OR the CRON_SECRET shared secret.
  // - restore-sql-backup / send-daily-backup / preview-sql-backup /
  //   import-excel-orders / ai-settings-save: these can destroy or exfiltrate
  //   all company data, so they require an authenticated ADMIN, full stop.
  // - everything else just requires a logged-in session.
  $__cronActions = ['cron-delivery-delay','cron-payment-delay','generate-recurring-expenses','process_whatsapp_queue','process_pending_messages','process-whatsapp-queue','ai-cron-run'];
  // SECURITY (multi-tenant): these actions dump/restore the ENTIRE shared
  // database across ALL tenants, not just the caller's own agency. A
  // tenant's own "admin" role must NOT be able to trigger these — only the
  // SaaS platform_admin may. (import-excel-orders / ai-settings-save stay
  // tenant-admin-accessible since they only touch that tenant's own rows.)
  $__platformAdminOnlyActions = ['restore-sql-backup','send-daily-backup','preview-sql-backup'];
  $__tenantAdminOnlyActions = ['import-excel-orders','ai-settings-save'];
  // Public: the pricing page (no login yet) needs to read active plans, and
  // the payment gateway's server-to-server webhook has no session at all —
  // both verify themselves by other means (webhook signature) rather than login.
  $__publicActions = ['billing-plans', 'billing-webhook', 'platform-content-get'];
  if (in_array($action, $__publicActions, true)) {
    // no auth gate — handled inside the action itself
  } elseif (in_array($action, $__cronActions, true)) {
    allow_cron_or_auth();
  } elseif (in_array($action, $__platformAdminOnlyActions, true)) {
    require_role(['platform_admin']);
  } elseif (in_array($action, $__tenantAdminOnlyActions, true)) {
    require_role(['admin', 'platform_admin']);
  } else {
    require_auth();
  }

  try { ensure_whatsapp_schema(); } catch (Throwable $e) { /* ignore */ }
  try { ensure_hr_finance_schema(); } catch (Throwable $e) { /* ignore */ }
  try {
    // Lightweight email and SQL backup utilities (scoped inside functions service)
    $safe_email = function($email) {
      $e = trim((string)$email);
      if ($e === '') return '';
      if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return '';
      return $e;
    };
    $get_settings_email = function() use ($safe_email) {
      // 1) Explicit email column in follow_up_settings
      try {
        $st = pdo()->prepare("SELECT email FROM follow_up_settings WHERE email IS NOT NULL AND email <> '' ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1");
        $st->execute(); $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['email'])) {
          $e = $safe_email($row['email']); if ($e !== '') return $e;
        }
      } catch (Throwable $e) { /* ignore */ }
      // 2) website_settings JSON: website_content.contactInfo.email or primary_data.contact_email
      try {
        $st = pdo()->prepare("SELECT `key`, `value` FROM website_settings WHERE `key` IN ('website_content','primary_data') LIMIT 2");
        $st->execute();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
          $val = $row['value'] ?? '';
          if (!$val) continue;
          $conf = json_decode($val, true);
          if (!is_array($conf)) continue;
          // Try multiple common paths
          $candidates = [];
          if (isset($conf['contactInfo']['email'])) $candidates[] = $conf['contactInfo']['email'];
          if (isset($conf['contact_email'])) $candidates[] = $conf['contact_email'];
          foreach ($candidates as $cand) {
            $e = $safe_email($cand);
            if ($e !== '') return $e;
          }
        }
      } catch (Throwable $e) { /* ignore */ }
      return '';
    };
    $send_mail_with_attachment = function($to, $subject, $message, $attachments = []) {
      $boundary = "==Multipart_Boundary_x" . md5((string)microtime()) . "x";
      $headers = [];
      $headers[] = 'MIME-Version: 1.0';
      $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
      $headers[] = 'From: no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
      $body = "--$boundary\r\n";
      $body .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
      $body .= (string)$message . "\r\n";
      foreach ($attachments as $att) {
        $filename = $att['name'] ?? ('file_' . time());
        $data = isset($att['data']) ? $att['data'] : '';
        $type = $att['type'] ?? 'application/octet-stream';
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: $type; name=\"$filename\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n";
        $body .= chunk_split(base64_encode($data));
      }
      $body .= "--$boundary--";
      return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    };
    $export_sql = function() {
      $pdo = pdo();
      $out = "-- Promo Sync Suite MySQL backup\n-- Host: " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
      $tables = [];
      try { $st = $pdo->query("SHOW TABLES"); while ($r = $st->fetch(PDO::FETCH_NUM)) { if (!empty($r[0])) $tables[] = $r[0]; } } catch (Throwable $e) { /* ignore */ }
      foreach ($tables as $t) {
        try {
          $row = $pdo->query("SHOW CREATE TABLE `" . str_replace('`','',$t) . "`")->fetch(PDO::FETCH_NUM);
          if ($row && !empty($row[1])) {
            $out .= "\n-- ----------------------------------------\n-- Table structure for `{$t}`\n-- ----------------------------------------\n";
            $out .= "DROP TABLE IF EXISTS `{$t}`;\n";
            $out .= $row[1] . ";\n\n";
          }
        } catch (Throwable $e) { /* ignore */ }
        try {
          $st = $pdo->query("SELECT * FROM `" . str_replace('`','',$t) . "`");
          $cols = array_keys($st->fetch(PDO::FETCH_ASSOC) ?: []);
          if (!empty($cols)) {
            $st->closeCursor();
            $stmt = $pdo->query("SELECT * FROM `" . str_replace('`','',$t) . "`");
            $out .= "-- Data for table `{$t}`\n";
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
              $vals = [];
              foreach ($row as $v) {
                if ($v === null) { $vals[] = 'NULL'; }
                else { $vals[] = "'" . str_replace(["\\","'","\r","\n"],["\\\\","\\'","\\r","\\n"], (string)$v) . "'"; }
              }
              $out .= "INSERT INTO `{$t}` VALUES (" . implode(',', $vals) . ");\n";
            }
            $out .= "\n";
          }
        } catch (Throwable $e) { /* ignore */ }
      }
      return $out;
    };

    switch ($action) {
      case 'send-daily-backup': {
        $payload = $req; // merged inputs
        $to = $safe_email($payload['to'] ?? '') ?: $get_settings_email();
        if (!$to) respond(null, ['message' => 'No recipient email configured'], 400);
        $sqlDump = $export_sql();
        $dir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $fname = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        @file_put_contents($dir . DIRECTORY_SEPARATOR . $fname, $sqlDump);
        $ok = $send_mail_with_attachment($to, 'النسخة الاحتياطية اليومية - ' . date('Y-m-d'), 'مرفق نسخة احتياطية لقاعدة البيانات بتاريخ ' . date('Y-m-d H:i:s'), [ ['name' => $fname, 'data' => $sqlDump, 'type' => 'application/sql'] ]);
        respond(['success' => (bool)$ok, 'saved' => (bool)@file_exists($dir . DIRECTORY_SEPARATOR . $fname), 'filename' => $fname, 'path' => 'uploads/backups/' . $fname]);
        break;
      }
      case 'send-order-notifications': {
        $type = (string)($body['type'] ?? $body['event'] ?? '');
        $orderId = (string)($body['order_id'] ?? '');
        $force = (bool)($body['force_send'] ?? false);
        if ($type === '') { respond(['processed' => 0, 'errors' => 1, 'message' => 'Missing type'], null, 200); }
        if ($orderId !== '') {
          // Build a reliable context from DB for order_* events
          $lower = strtolower($type);
          $isOrderEvent = strpos($lower, 'order_') === 0;
          if ($isOrderEvent) {
            $ctx = get_order_context($orderId);
            // Merge any provided data (UI overrides DB)
            $extra = is_array($body['data'] ?? null) ? $body['data'] : [];
            if (!empty($extra)) { $ctx = array_merge($ctx, $extra); }
            // Payments details block
            $ctx['payments_details'] = build_payments_section_text($orderId);
            // Derive delivery_time if only estimated is available handled in get_delivery_time_formatted/render_template
            // Select appropriate template key
            $tplKey = $type;
            if (!$tplKey || !preg_match('/^order_/', $tplKey)) {
              $mapped = map_status_to_template_key($ctx['status'] ?? '');
              if ($mapped !== '') { $tplKey = $mapped; }
            }

            // For completed orders, use specialized sender to ensure evaluation link/code and proper formatting
            if (strtolower((string)$tplKey) === 'order_completed') {
              try { send_order_template_message($orderId, 'order_completed', []); } catch (Throwable $eS) { /* ignore and proceed */ }
              $limit = (int)($body['limit'] ?? 20);
              $summary = process_whatsapp_queue($limit > 0 ? $limit : 20);
              respond($summary, null, 200);
            }
            // Load template content
            $tpl = get_template_content($tplKey) ?: get_template_content('new_order_notification');
            if (!$tpl) { respond(['processed' => 0, 'errors' => 1, 'message' => 'Template not found for type: ' . $tplKey], null, 200); }
            $message = render_template($tpl, $ctx);
            // Destination number
            $to = (string)($body['to'] ?? ($ctx['customer_phone'] ?? ''));
            if (trim($to) === '') { $to = get_followup_number(); }
            if (trim($to) === '') { respond(['processed' => 0, 'errors' => 1, 'message' => 'Missing destination phone'], null, 200); }
            ensure_whatsapp_schema();
            $id = generate_uuid_v4();
            $st2 = pdo()->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, created_at, dedupe_key) VALUES (:id, 'system', :to, :type, :msg, 'pending', NOW(), :dk)");
            $st2->execute([
              ':id' => $id,
              ':to' => $to,
              ':type' => $tplKey,
              ':msg' => $message,
              ':dk' => 'customer|' . strtolower($tplKey) . '|' . md5($to . '|' . ($ctx['order_number'] ?? '')) . '|' . date('YmdHis')
            ]);
          } else {
            // Non-order events: for payment notifications, always enrich from DB and ensure destination
            $ctx = is_array($body['data'] ?? null) ? $body['data'] : [];
            if ($orderId !== '') { $ctx['order_id'] = $orderId; }
            $to = $body['to'] ?? null;
            $lt = strtolower((string)$type);
            if (in_array($lt, ['new_payment_notification','payment_logged_notification'], true) && $orderId !== '') {
              // Build rich context from DB and merge overrides
              $dbCtx = [];
              try { $dbCtx = get_order_context($orderId); } catch (Throwable $eCtx) { $dbCtx = []; }
              if (!empty($dbCtx)) { $ctx = array_merge($dbCtx, $ctx); }
              // Ensure payments section is present
              try { $ctx['payments_details'] = build_payments_section_text($orderId); } catch (Throwable $eP) { /* ignore */ }
              // Resolve destination: prefer explicit 'to', then follow-up number to guarantee enqueue
              if (trim((string)$to) === '') { $to = get_followup_number(); }
              // If still missing, return a clear message instead of silent no-op
              if (trim((string)$to) === '') { respond(['processed' => 0, 'errors' => 1, 'message' => 'Missing follow-up WhatsApp number'], null, 200); }
            }
            send_followup_event($type, $ctx, $to, $force);
          }
          $limit = (int)($body['limit'] ?? 20);
          $summary = process_whatsapp_queue($limit > 0 ? $limit : 20);
          respond($summary, null, 200);
        }
        // No order_id: render from provided data using template
        $dataCtx = is_array($body['data'] ?? null) ? $body['data'] : [];
        // Flatten {result: ...}
        foreach (['order_number','amount','total_amount','paid_amount','remaining_amount'] as $k) {
          if (isset($dataCtx[$k]) && is_array($dataCtx[$k]) && isset($dataCtx[$k]['result'])) { $dataCtx[$k] = $dataCtx[$k]['result']; }
        }
        // Try resolving order by order_number to enrich context with DB values and items
        $resolvedOrderId = '';
        $ordNo = trim((string)($dataCtx['order_number'] ?? ''));
        if ($ordNo !== '') {
          try {
            $stOrd = pdo()->prepare("SELECT id FROM orders WHERE order_number = :no LIMIT 1");
            $stOrd->execute([':no' => $ordNo]);
            $resolvedOrderId = (string)($stOrd->fetchColumn() ?: '');
          } catch (Throwable $e) { $resolvedOrderId = ''; }
        }
        if ($resolvedOrderId !== '') {
        $dbCtx = get_order_context($resolvedOrderId);
        if (!empty($dbCtx)) {
        // Merge DB context with provided data (data overrides DB)
        $dataCtx = array_merge($dbCtx, $dataCtx);
        }
        // Ensure items text
        if (empty($dataCtx['order_items']) || trim((string)$dataCtx['order_items']) === '') {
        $dataCtx['order_items'] = build_order_items_section($resolvedOrderId);
        }
        }
        // Finance fields normalization
        if (empty($dataCtx['total_amount']) && !empty($dataCtx['amount'])) { $dataCtx['total_amount'] = number_format((float)$dataCtx['amount'], 2); }
        // If paid missing in test mode without order_id, assume full payment for display
        if (empty($dataCtx['paid_amount']) && !empty($dataCtx['amount'])) { $dataCtx['paid_amount'] = number_format((float)$dataCtx['amount'], 2); }
        if (isset($dataCtx['paid_amount'])) { $dataCtx['paid_amount'] = number_format((float)$dataCtx['paid_amount'], 2); }
        if (empty($dataCtx['remaining_amount'])) {
        if (isset($dataCtx['total_amount'], $dataCtx['paid_amount'])) {
        $rem = (float)str_replace([','], [''], (string)$dataCtx['total_amount']) - (float)str_replace([','], [''], (string)$dataCtx['paid_amount']);
        $dataCtx['remaining_amount'] = number_format(max(0, $rem), 2);
        } elseif (!empty($dataCtx['amount'])) {
        $dataCtx['remaining_amount'] = number_format(0, 2);
        }
        }
        // Defaults for delay tests when no order_id is provided
        $lt = strtolower((string)$type);
        if ($lt === 'delivery_delay_notification') {
        if (empty($dataCtx['customer_name'])) { $dataCtx['customer_name'] = 'عميل تجريبي'; }
        if (empty($dataCtx['delivery_date'])) { $dataCtx['delivery_date'] = date('Y-m-d'); }
        if (empty($dataCtx['delay_days'])) { $dataCtx['delay_days'] = '1'; }
        }
        if ($lt === 'payment_delay_notification') {
        if (empty($dataCtx['customer_name'])) { $dataCtx['customer_name'] = 'عميل'; }
        if (empty($dataCtx['customer_whatsapp']) && !empty($dataCtx['customer_phone'])) { $dataCtx['customer_whatsapp'] = $dataCtx['customer_phone']; }
        if (empty($dataCtx['customer_whatsapp'])) { $dataCtx['customer_whatsapp'] = get_followup_number(); }
        if (empty($dataCtx['order_number'])) { $dataCtx['order_number'] = 'ORD-TEST-' . date('Ymd'); }
        if (empty($dataCtx['order_date'])) { $dataCtx['order_date'] = date('Y-m-d'); }
        if (empty($dataCtx['delay_days'])) { $dataCtx['delay_days'] = '7'; }
        }
        $tpl = get_template_content($type);
        if (!$tpl) {
          $map = [ 'order_created' => 'new_order_notification' ];
          $alt = $map[strtolower($type)] ?? '';
          if ($alt !== '') { $tpl = get_template_content($alt); $type = $alt; }
        }
        if (!$tpl) { respond(['processed' => 0, 'errors' => 1, 'message' => 'Template not found for type: ' . $type], null, 200); }
        $message = render_template($tpl, $dataCtx);
        $to = (string)($body['to'] ?? ($dataCtx['customer_phone'] ?? ''));
        if (trim($to) === '') { $to = get_followup_number(); }
        if (trim($to) === '') { respond(['processed' => 0, 'errors' => 1, 'message' => 'Missing destination phone'], null, 200); }
        ensure_whatsapp_schema();
        $id = generate_uuid_v4();
        $st2 = pdo()->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, created_at, dedupe_key) VALUES (:id, 'system', :to, :type, :msg, 'pending', NOW(), :dk)");
        $st2->execute([
          ':id' => $id,
          ':to' => $to,
          ':type' => $type,
          ':msg' => $message,
          ':dk' => 'customer|' . strtolower($type) . '|' . md5($to . '|' . ($dataCtx['order_number'] ?? '')) . '|' . date('YmdHis')
        ]);
        $limit = (int)($body['limit'] ?? 20);
        $summary = process_whatsapp_queue($limit > 0 ? $limit : 20);
        respond($summary, null, 200);
        break;
      }
      case 'process_whatsapp_queue': {
        $limit = (int)($body['limit'] ?? ($_GET['limit'] ?? 50));
        $res = process_whatsapp_queue($limit > 0 ? $limit : 50);
        respond($res, null, 200);
        break;
      }
      case 'process_pending_messages': {
        // Alias to process_whatsapp_queue (compatibility with Supabase/n8n)
        $limit = (int)($body['limit'] ?? ($_GET['limit'] ?? 50));
        $res = process_whatsapp_queue($limit > 0 ? $limit : 50);
        respond($res, null, 200);
        break;
      }
      case 'process-whatsapp-queue': {
        // Alias to process_whatsapp_queue (dash style)
        $limit = (int)($body['limit'] ?? ($_GET['limit'] ?? 50));
        $res = process_whatsapp_queue($limit > 0 ? $limit : 50);
        respond($res, null, 200);
        break;
      }
      case 'dashboard-stats': {
        // لوحة معلومات مجمعة: كل إحصائيات الصفحة الرئيسية في نداء واحد
        try {
          $pdo = pdo();
          // SECURITY (multi-tenant): every query below now filters by the
          // caller's tenant_id (platform_admin sees everything, unscoped).
          $__scopeTenant = tenant_is_platform_admin() ? null : tenant_current_id();
          if (!tenant_is_platform_admin() && !$__scopeTenant) respond(null, ['message' => 'no_tenant'], 403);
          $tsql = function($sql) use ($__scopeTenant) {
            // Appends a tenant_id condition to a query that already has a
            // WHERE (-> AND) or doesn't (-> WHERE), only when scoping applies.
            if ($__scopeTenant === null) return $sql;
            $cond = (stripos($sql, ' where ') !== false) ? ' AND tenant_id = ?' : ' WHERE tenant_id = ?';
            // Insert right before GROUP BY / ORDER BY / LIMIT if present, else append.
            if (preg_match('/\b(GROUP BY|ORDER BY|LIMIT)\b/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
              $pos = $m[0][1];
              return substr($sql, 0, $pos) . $cond . ' ' . substr($sql, $pos);
            }
            return $sql . $cond;
          };
          $tparam = function($params) use ($__scopeTenant) {
            if ($__scopeTenant === null) return $params;
            $params[] = $__scopeTenant;
            return $params;
          };
          $q = function($sql, $params = []) use ($pdo) {
            try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
            catch (Throwable $e) { return []; }
          };
          $q1 = function($sql, $params = []) use ($q) { $r = $q($sql, $params); return $r ? $r[0] : []; };

          $ACT = "'pending','in_progress','قيد التنفيذ','قيد المراجعة','قيد الانتظار'";
          $DONE = "'مكتمل','completed'";
          $CANC = "'ملغي','cancelled'";
          $READY = "'جاهز للتسليم','ready'";
          $ym = date('Y-m');
          $today = date('Y-m-d');
          $monthStart = $ym . '-01';

          // ===== KPIs =====
          $k = [];
          $c = $q1($tsql("SELECT COUNT(*) t, SUM(CASE WHEN SUBSTR(created_at,1,7) = ? THEN 1 ELSE 0 END) m FROM customers"), $tparam([$ym]));
          $k['customers_total'] = (int)($c['t'] ?? 0);
          $k['customers_new_month'] = (int)($c['m'] ?? 0);

          $o = $q1($tsql("SELECT COUNT(*) t,
              COALESCE(SUM(CASE WHEN status IN ($ACT) THEN 1 ELSE 0 END),0) act,
              COALESCE(SUM(CASE WHEN status IN ($DONE) THEN 1 ELSE 0 END),0) done,
              COALESCE(SUM(CASE WHEN status IN ($READY) THEN 1 ELSE 0 END),0) rdy,
              COALESCE(SUM(CASE WHEN status IN ($CANC) THEN 1 ELSE 0 END),0) canc,
              COALESCE(SUM(CASE WHEN SUBSTR(created_at,1,7) = ? THEN 1 ELSE 0 END),0) mth,
              COALESCE(SUM(CASE WHEN status NOT IN ($CANC) THEN COALESCE(total_amount,0) ELSE 0 END),0) val,
              COALESCE(SUM(CASE WHEN status NOT IN ($CANC) THEN GREATEST(COALESCE(total_amount,0) - COALESCE(paid_amount,0), 0) ELSE 0 END),0) outs
            FROM orders"), $tparam([$ym]));
          $k['orders_total'] = (int)($o['t'] ?? 0);
          $k['orders_active'] = (int)($o['act'] ?? 0);
          $k['orders_completed'] = (int)($o['done'] ?? 0);
          $k['orders_ready'] = (int)($o['rdy'] ?? 0);
          $k['orders_cancelled'] = (int)($o['canc'] ?? 0);
          $k['orders_month'] = (int)($o['mth'] ?? 0);
          $k['orders_value_total'] = (float)($o['val'] ?? 0);
          $k['outstanding_total'] = (float)($o['outs'] ?? 0);

          $p = $q1($tsql("SELECT COALESCE(SUM(amount),0) t, COALESCE(SUM(CASE WHEN payment_date >= ? THEN amount ELSE 0 END),0) m FROM payments"), $tparam([$monthStart]));
          $k['revenue_total'] = (float)($p['t'] ?? 0);
          $k['revenue_month'] = (float)($p['m'] ?? 0);

          $e = $q1($tsql("SELECT COALESCE(SUM(amount),0) t, COALESCE(SUM(CASE WHEN expense_date >= ? THEN amount ELSE 0 END),0) m FROM expenses"), $tparam([$monthStart]));
          $k['expenses_total'] = (float)($e['t'] ?? 0);
          $k['expenses_month'] = (float)($e['m'] ?? 0);
          $k['net_month'] = $k['revenue_month'] - $k['expenses_month'];

          $inv = $q1($tsql("SELECT COUNT(*) t,
              COALESCE(SUM(CASE WHEN status NOT IN ('paid','مدفوعة','مدفوع') THEN 1 ELSE 0 END),0) up,
              COALESCE(SUM(CASE WHEN status NOT IN ('paid','مدفوعة','مدفوع') THEN GREATEST(COALESCE(total_amount,0) - COALESCE(paid_amount,0), 0) ELSE 0 END),0) upa
            FROM invoices"), $tparam([]));
          $k['invoices_total'] = (int)($inv['t'] ?? 0);
          $k['invoices_unpaid_count'] = (int)($inv['up'] ?? 0);
          $k['invoices_unpaid_amount'] = (float)($inv['upa'] ?? 0);

          $k['late_deliveries'] = (int)($q1($tsql("SELECT COUNT(*) c FROM orders WHERE delivery_date IS NOT NULL AND delivery_date <> '' AND delivery_date < ? AND status NOT IN ($DONE) AND status NOT IN ($CANC)"), $tparam([$today]))['c'] ?? 0);
          $k['wa_pending'] = (int)($q1($tsql("SELECT COUNT(*) c FROM whatsapp_messages WHERE status = 'pending'"), $tparam([]))['c'] ?? 0);
          $k['complaints_open'] = (int)($q1($tsql("SELECT COUNT(*) c FROM ai_complaints WHERE status NOT IN ('resolved','closed','مغلقة','تم الحل')"), $tparam([]))['c'] ?? 0);
          $k['tasks_pending'] = (int)($q1($tsql("SELECT COUNT(*) c FROM employee_tasks WHERE status NOT IN ('completed','cancelled','مكتملة','ملغاة')"), $tparam([]))['c'] ?? 0);

          // ===== سلسلة شهرية (آخر 12 شهر) =====
          $months = [];
          for ($i = 11; $i >= 0; $i--) {
            $key = date('Y-m', strtotime($monthStart . " -$i months"));
            $months[$key] = ['ym' => $key, 'revenue' => 0.0, 'expenses' => 0.0, 'orders' => 0, 'customers' => 0];
          }
          $startYm = array_keys($months)[0];
          $startDate = $startYm . '-01';
          foreach ($q($tsql("SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, COALESCE(SUM(amount),0) s FROM payments WHERE payment_date >= ? GROUP BY 1"), $tparam([$startDate])) as $r) {
            if (isset($months[$r['ym']])) $months[$r['ym']]['revenue'] = (float)$r['s'];
          }
          foreach ($q($tsql("SELECT DATE_FORMAT(expense_date,'%Y-%m') ym, COALESCE(SUM(amount),0) s FROM expenses WHERE expense_date >= ? GROUP BY 1"), $tparam([$startDate])) as $r) {
            if (isset($months[$r['ym']])) $months[$r['ym']]['expenses'] = (float)$r['s'];
          }
          foreach ($q($tsql("SELECT SUBSTR(created_at,1,7) ym, COUNT(*) c FROM orders WHERE created_at >= ? GROUP BY 1"), $tparam([$startDate])) as $r) {
            if (isset($months[$r['ym']])) $months[$r['ym']]['orders'] = (int)$r['c'];
          }
          foreach ($q($tsql("SELECT SUBSTR(created_at,1,7) ym, COUNT(*) c FROM customers WHERE created_at >= ? GROUP BY 1"), $tparam([$startDate])) as $r) {
            if (isset($months[$r['ym']])) $months[$r['ym']]['customers'] = (int)$r['c'];
          }

          // ===== توزيعات ===== (joins: filter by the BASE table's tenant_id — `o`/orders)
          $statusDist = $q($tsql("SELECT status, COUNT(*) c, COALESCE(SUM(COALESCE(total_amount,0)),0) s FROM orders GROUP BY status ORDER BY c DESC"), $tparam([]));
          $topServices = $q(($__scopeTenant !== null
              ? "SELECT COALESCE(s.name,'غير محدد') name, COUNT(*) c, COALESCE(SUM(COALESCE(o.total_amount,0)),0) s
                 FROM orders o LEFT JOIN service_types s ON s.id = o.service_type_id
                 WHERE o.status NOT IN ($CANC) AND o.tenant_id = ? GROUP BY s.name ORDER BY s DESC LIMIT 6"
              : "SELECT COALESCE(s.name,'غير محدد') name, COUNT(*) c, COALESCE(SUM(COALESCE(o.total_amount,0)),0) s
                 FROM orders o LEFT JOIN service_types s ON s.id = o.service_type_id
                 WHERE o.status NOT IN ($CANC) GROUP BY s.name ORDER BY s DESC LIMIT 6"),
            $tparam([]));
          $topCustomers = $q(($__scopeTenant !== null
              ? "SELECT COALESCE(c.name,'غير محدد') name, COUNT(*) c, COALESCE(SUM(COALESCE(o.total_amount,0)),0) s
                 FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
                 WHERE o.status NOT IN ($CANC) AND o.tenant_id = ? GROUP BY c.name ORDER BY s DESC LIMIT 5"
              : "SELECT COALESCE(c.name,'غير محدد') name, COUNT(*) c, COALESCE(SUM(COALESCE(o.total_amount,0)),0) s
                 FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
                 WHERE o.status NOT IN ($CANC) GROUP BY c.name ORDER BY s DESC LIMIT 5"),
            $tparam([]));
          $paymentTypes = $q($tsql("SELECT COALESCE(payment_type,'غير محدد') t, COUNT(*) c, COALESCE(SUM(amount),0) s FROM payments GROUP BY payment_type ORDER BY s DESC"), $tparam([]));

          // ===== قوائم حديثة ===== (joins: filter by the BASE table's tenant_id — `o`/orders, `p`/payments)
          $recentOrders = $q(($__scopeTenant !== null
              ? "SELECT o.id, o.order_number, o.status, o.total_amount, o.paid_amount, o.created_at, c.name AS customer_name, s.name AS service_name
                 FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN service_types s ON s.id = o.service_type_id
                 WHERE o.tenant_id = ? ORDER BY o.created_at DESC LIMIT 6"
              : "SELECT o.id, o.order_number, o.status, o.total_amount, o.paid_amount, o.created_at, c.name AS customer_name, s.name AS service_name
                 FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN service_types s ON s.id = o.service_type_id
                 ORDER BY o.created_at DESC LIMIT 6"),
            $tparam([]));
          $recentPayments = $q(($__scopeTenant !== null
              ? "SELECT p.id, p.amount, p.payment_type, p.payment_date, p.created_at, o.order_number, c.name AS customer_name
                 FROM payments p LEFT JOIN orders o ON o.id = p.order_id LEFT JOIN customers c ON c.id = o.customer_id
                 WHERE p.tenant_id = ? ORDER BY p.created_at DESC LIMIT 6"
              : "SELECT p.id, p.amount, p.payment_type, p.payment_date, p.created_at, o.order_number, c.name AS customer_name
                 FROM payments p LEFT JOIN orders o ON o.id = p.order_id LEFT JOIN customers c ON c.id = o.customer_id
                 ORDER BY p.created_at DESC LIMIT 6"),
            $tparam([]));
          $upcoming = $q(($__scopeTenant !== null
              ? "SELECT o.id, o.order_number, o.status, o.delivery_date, o.total_amount, c.name AS customer_name, s.name AS service_name
                 FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN service_types s ON s.id = o.service_type_id
                 WHERE o.delivery_date IS NOT NULL AND o.delivery_date <> '' AND o.delivery_date >= ? AND o.status NOT IN ($DONE) AND o.status NOT IN ($CANC) AND o.tenant_id = ?
                 ORDER BY o.delivery_date ASC LIMIT 6"
              : "SELECT o.id, o.order_number, o.status, o.delivery_date, o.total_amount, c.name AS customer_name, s.name AS service_name
                 FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN service_types s ON s.id = o.service_type_id
                 WHERE o.delivery_date IS NOT NULL AND o.delivery_date <> '' AND o.delivery_date >= ? AND o.status NOT IN ($DONE) AND o.status NOT IN ($CANC)
                 ORDER BY o.delivery_date ASC LIMIT 6"),
            $__scopeTenant !== null ? [$today, $__scopeTenant] : [$today]);

          respond([
            'kpis' => $k,
            'monthly' => array_values($months),
            'status_dist' => $statusDist,
            'top_services' => $topServices,
            'top_customers' => $topCustomers,
            'payment_types' => $paymentTypes,
            'recent_orders' => $recentOrders,
            'recent_payments' => $recentPayments,
            'upcoming_deliveries' => $upcoming,
            'generated_at' => date('c'),
          ]);
        } catch (Throwable $e) {
          respond(null, [ 'message' => 'dashboard_stats_failed', 'error' => $e->getMessage() ], 500);
        }
        break;
      }
      case 'daily-financial-report': {
        // Replicate Supabase daily-financial-report behavior with optional test mode
        $isTest = (bool)($body['test'] ?? ($_GET['test'] ?? false));
        try {
          $pdo = pdo();
          // SECURITY (multi-tenant): scope every query below to the caller's
          // own tenant so each agency gets its own settings/numbers/figures.
          $__tid = tenant_is_platform_admin() ? null : tenant_current_id();
          if (!tenant_is_platform_admin() && !$__tid) respond(null, ['message' => 'no_tenant'], 403);
          // Fetch settings (per-tenant)
          $settingsSql = "SELECT * FROM follow_up_settings" . ($__tid !== null ? " WHERE tenant_id = :tid" : "") . " ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1";
          $st = $pdo->prepare($settingsSql);
          $st->execute($__tid !== null ? [':tid' => $__tid] : []);
          $settings = $st->fetch(PDO::FETCH_ASSOC) ?: [];
          $dailyEnabled = isset($settings['daily_financial_report']) ? (bool)$settings['daily_financial_report'] : true;
          $toNumber = trim((string)($settings['whatsapp_number'] ?? ''));

          if ((!$dailyEnabled || $toNumber === '') && !$isTest) {
            respond(['message' => 'Report disabled'], null, 200);
          }

          // Day boundaries
          $now = time();
          $start = date('Y-m-d 00:00:00', $now);
          $end = date('Y-m-d 23:59:59', $now);
          $todayDate = date('Y-m-d', $now);

          // Payments with order and customer info
          $payments = [];
          try {
            $sql = "SELECT p.amount, COALESCE(p.payment_type, p.payment_method) AS payment_type, p.order_id, o.order_number, o.total_amount, o.paid_amount, o.customer_id, c.name AS customer_name
                    FROM payments p
                    LEFT JOIN orders o ON p.order_id = o.id
                    LEFT JOIN customers c ON o.customer_id = c.id
                    WHERE (p.payment_date BETWEEN :s AND :e OR (p.created_at BETWEEN :s AND :e))" . ($__tid !== null ? " AND p.tenant_id = :tid" : "") . "
                    ORDER BY COALESCE(p.created_at, p.payment_date, p.id) DESC";
            $stp = $pdo->prepare($sql);
            $stp->execute($__tid !== null ? [':s' => $start, ':e' => $end, ':tid' => $__tid] : [':s' => $start, ':e' => $end]);
            $payments = $stp->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable $e) { $payments = []; }
          $totalPayments = 0.0;
          foreach ($payments as $p) { $totalPayments += (float)($p['amount'] ?? 0); }

          // Expenses
          $expenses = [];
          try {
            $stx = $pdo->prepare("SELECT amount, COALESCE(expense_type, category) AS expense_type, COALESCE(description, notes) AS description FROM expenses WHERE expense_date BETWEEN :s AND :e" . ($__tid !== null ? " AND tenant_id = :tid" : "") . " ORDER BY COALESCE(created_at, id) ASC");
            $stx->execute($__tid !== null ? [':s' => $start, ':e' => $end, ':tid' => $__tid] : [':s' => $start, ':e' => $end]);
            $expenses = $stx->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable $e) { $expenses = []; }
          $totalExpenses = 0.0;
          foreach ($expenses as $ex) { $totalExpenses += (float)($ex['amount'] ?? 0); }

          $netProfit = $totalPayments - $totalExpenses;

          // New orders today
          $newOrders = [];
          try {
            $stno = $pdo->prepare("SELECT o.order_number, o.total_amount, c.name AS customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.created_at BETWEEN :s AND :e" . ($__tid !== null ? " AND o.tenant_id = :tid" : "") . " ORDER BY o.created_at ASC");
            $stno->execute($__tid !== null ? [':s' => $start, ':e' => $end, ':tid' => $__tid] : [':s' => $start, ':e' => $end]);
            $newOrders = $stno->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable $e) { $newOrders = []; }

          // Delayed orders (delivery_date is today and not completed/cancelled)
          $delayedOrders = [];
          try {
            $stdo = $pdo->prepare("SELECT o.order_number, o.delivery_date, c.name AS customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.delivery_date = :d AND (LOWER(TRIM(o.status)) NOT IN ('completed','cancelled'))" . ($__tid !== null ? " AND o.tenant_id = :tid" : "") . " ORDER BY o.delivery_date ASC");
            $stdo->execute($__tid !== null ? [':d' => $todayDate, ':tid' => $__tid] : [':d' => $todayDate]);
            $delayedOrders = $stdo->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable $e) { $delayedOrders = []; }

          // Completed today count and details (by updated_at)
          $completedOrdersCount = 0; $completedOrdersToday = [];
          try {
            $stcc = $pdo->prepare("SELECT COUNT(1) FROM orders WHERE LOWER(TRIM(status)) = 'completed' AND updated_at BETWEEN :s AND :e" . ($__tid !== null ? " AND tenant_id = :tid" : ""));
            $stcc->execute($__tid !== null ? [':s' => $start, ':e' => $end, ':tid' => $__tid] : [':s' => $start, ':e' => $end]);
            $completedOrdersCount = (int)($stcc->fetchColumn() ?: 0);
          } catch (Throwable $e) { $completedOrdersCount = 0; }
          try {
            $stcd = $pdo->prepare("SELECT o.order_number, o.total_amount, c.name AS customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE LOWER(TRIM(o.status)) = 'completed' AND o.updated_at BETWEEN :s AND :e" . ($__tid !== null ? " AND o.tenant_id = :tid" : "") . " ORDER BY o.updated_at ASC");
            $stcd->execute($__tid !== null ? [':s' => $start, ':e' => $end, ':tid' => $__tid] : [':s' => $start, ':e' => $end]);
            $completedOrdersToday = $stcd->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable $e) { $completedOrdersToday = []; }

          // Helpers
          $mapPay = function($t) { return map_payment_type($t); };

          // Build sections matching Supabase format
          $paymentsSection = '';
          if (!empty($payments)) {
            $paymentsSection = "\n💰 *تفاصيل المدفوعات:*\n";
            $i = 1; foreach ($payments as $p) {
              $ord = $p['order_number'] ?? 'غير محدد';
              $cust = $p['customer_name'] ?? 'غير محدد';
              $paymentsSection .= $i . '. ' . $ord . ' - ' . $cust . "\n";
              $paymentsSection .= '   ' . $mapPay($p['payment_type'] ?? '') . ': ' . number_format((float)($p['amount'] ?? 0), 2) . ' ر.س' . "\n";
              $i++;
            }
          }

          $expensesSection = '';
          if (!empty($expenses)) {
            $expensesSection = "\n💸 *تفاصيل المصروفات:*\n";
            $i = 1; foreach ($expenses as $ex) {
              $desc = trim((string)($ex['description'] ?? ''));
              if ($desc === '') { $desc = (string)($ex['expense_type'] ?? 'مصروف'); }
              $expensesSection .= $i . '. ' . $desc . ': ' . number_format((float)($ex['amount'] ?? 0), 2) . ' ر.س' . "\n";
              $i++;
            }
          }

          $newOrdersSection = '';
          if (!empty($newOrders)) {
            $newOrdersSection = "\n📦 *الطلبات الجديدة:*\n";
            $i = 1; foreach ($newOrders as $o) {
              $cust = $o['customer_name'] ?? 'غير محدد';
              $amt = number_format((float)($o['total_amount'] ?? 0), 2);
              $newOrdersSection .= $i . '. ' . ($o['order_number'] ?? '—') . ' - ' . $cust . ': ' . $amt . ' ر.س' . "\n";
              $i++;
            }
          }

          $completedSection = '';
          if (!empty($completedOrdersToday)) {
            $completedSection = "\n✅ *الطلبات المكتملة:*\n";
            $i = 1; foreach ($completedOrdersToday as $o) {
              $cust = $o['customer_name'] ?? 'غير محدد';
              $amt = number_format((float)($o['total_amount'] ?? 0), 2);
              $completedSection .= $i . '. ' . ($o['order_number'] ?? '—') . ' - ' . $cust . ': ' . $amt . ' ر.س' . "\n";
              $i++;
            }
          }

          $delayedSection = '';
          if (!empty($delayedOrders)) {
            $delayedSection = "\n📅 *جاهزة للتسليم اليوم:*\n";
            $i = 1; foreach ($delayedOrders as $o) {
              $cust = $o['customer_name'] ?? 'غير محدد';
              $delayedSection .= $i . '. ' . ($o['order_number'] ?? '—') . ' - ' . $cust . "\n";
              $i++;
            }
          }

          $dateStr = date('Y-m-d');
          $timeStr = date('H:i');
          $msg = "📊 *التقرير المالي اليومي*\n" .
                 "📅 " . $dateStr . "\n\n" .
                 "━━━━━━━━━━━━━━━━━━━━\n\n" .
                 "📈 *الملخص المالي:*\n" .
                 '💰 المدفوعات: ' . number_format($totalPayments, 2) . " ر.س\n" .
                 '💸 المصروفات: ' . number_format($totalExpenses, 2) . " ر.س\n" .
                 '📊 صافي الربح: ' . number_format($netProfit, 2) . ' ر.س ' . ($netProfit >= 0 ? '✅' : '❌') . "\n\n" .
                 "━━━━━━━━━━━━━━━━━━━━\n\n" .
                 "📦 *إحصائيات:*\n" .
                 '• جديدة: ' . count($newOrders) . ' | مكتملة: ' . $completedOrdersCount . ' | للتسليم: ' . count($delayedOrders) . "\n" .
                 $paymentsSection . (!empty($paymentsSection) ? "━━━━━━━━━━━━━━━━━━━━\n" : '') .
                 $expensesSection . (!empty($expensesSection) ? "━━━━━━━━━━━━━━━━━━━━\n" : '') .
                 $newOrdersSection . (!empty($newOrdersSection) ? "━━━━━━━━━━━━━━━━━━━━\n" : '') .
                 $completedSection . (!empty($completedSection) ? "━━━━━━━━━━━━━━━━━━━━\n" : '') .
                 $delayedSection . (!empty($delayedSection) ? "━━━━━━━━━━━━━━━━━━━━\n" : '') .
                 '⏰ ' . $timeStr;

          $finalMessage = $isTest ? ("🧪 *هذه رسالة اختبار*\n\n" . $msg) : $msg;

          // Split into chunks <= 1000 chars and enqueue as separate messages
          $chunks = [];
          $remaining = $finalMessage;
          $max = 1000;
          while (mb_strlen($remaining, 'UTF-8') > $max) {
            // try to cut at separator
            $pos = mb_strrpos(mb_substr($remaining, 0, $max - 40, 'UTF-8'), "\n━━━━━━━━━━━━━━━━━━━━\n", 0, 'UTF-8');
            if ($pos === false) { $pos = mb_strrpos(mb_substr($remaining, 0, $max - 40, 'UTF-8'), "\n\n", 0, 'UTF-8'); }
            if ($pos === false) { $pos = mb_strrpos(mb_substr($remaining, 0, $max - 40, 'UTF-8'), "\n", 0, 'UTF-8'); }
            if ($pos === false) { $pos = $max - 40; }
            $chunk = trim(mb_substr($remaining, 0, $pos, 'UTF-8'));
            if ($chunk === '') { break; }
            $chunks[] = $chunk;
            $remaining = mb_substr($remaining, $pos, null, 'UTF-8');
          }
          if (trim($remaining) !== '') { $chunks[] = trim($remaining); }

          ensure_whatsapp_schema();
          $sentIds = [];
          $pdo->beginTransaction();
          try {
            for ($i = 0; $i < count($chunks); $i++) {
              $suffix = "\n\n— الجزء " . ($i + 1) . "/" . count($chunks);
              $content = $chunks[$i];
              if (mb_strlen($content . $suffix, 'UTF-8') > 1500) {
                $content = mb_substr($content, 0, 1500 - mb_strlen($suffix, 'UTF-8') - 3, 'UTF-8') . '...';
              }
              $partMessage = $content . (count($chunks) > 1 ? $suffix : '');
              $id = generate_uuid_v4();
              $dedupe = ($isTest ? 'daily_report_test' : 'daily_report') . '_' . date('c') . '_part_' . ($i + 1);
              if ($__tid !== null) {
                $stIns = $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, dedupe_key, tenant_id, created_at) VALUES (:id, 'system', :to, 'text', :msg, 'pending', :dk, :tid, NOW())");
                $stIns->execute([':id' => $id, ':to' => ($toNumber ?: get_followup_number() ?: '+966500000000'), ':msg' => $partMessage, ':dk' => $dedupe, ':tid' => $__tid]);
              } else {
                $stIns = $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, dedupe_key, created_at) VALUES (:id, 'system', :to, 'text', :msg, 'pending', :dk, NOW())");
                $stIns->execute([':id' => $id, ':to' => ($toNumber ?: get_followup_number() ?: '+966500000000'), ':msg' => $partMessage, ':dk' => $dedupe]);
              }
              $sentIds[] = $id;
            }
            $pdo->commit();
          } catch (Throwable $eIns) {
            try { $pdo->rollBack(); } catch (Throwable $eRb) {}
            respond(null, ['message' => 'Failed to insert daily report: ' . $eIns->getMessage()], 500);
          }

          // Process queue via webhook if available
          $summary = process_whatsapp_queue((int)($body['limit'] ?? 20));
          respond(['success' => true, 'message' => 'Daily report sent or queued', 'parts' => count($chunks), 'ids' => $sentIds, 'queue' => $summary, 'totals' => [
            'totalPayments' => (float)$totalPayments,
            'totalExpenses' => (float)$totalExpenses,
            'netProfit' => (float)$netProfit,
            'newOrdersCount' => count($newOrders),
            'completedOrdersCount' => $completedOrdersCount,
            'delayedOrdersCount' => count($delayedOrders)
          ]], null, 200);
        } catch (Throwable $e) {
          respond(null, ['message' => $e->getMessage(), 'code' => 'daily_report_error'], 500);
        }
        break;
      }
      case 'notify-new-order': {
        // If test mode, enqueue exact sample message to follow-up number
        $isTest = (bool)($body['test'] ?? ($_GET['test'] ?? false));
        if ($isTest) {
          ensure_whatsapp_schema();
          $to = get_followup_number();
          $msg = "🆕 طلب جديد\n\n" .
                 "📦 رقم الطلب: ORD-TEST-12345\n\n" .
                 "👤 اسم العميل: عميل تجريبي\n\n" .
                 "📋 بنود الطلب:\n\n" .
                 "1. منتج تجريبي 1\n\n" .
                 "الكمية: 2\n\n" .
                 "السعر: 500 ريال\n\n" .
                 "الإجمالي: 1000 ريال\n\n" .
                 "الوصف: وصف المنتج التجريبي الأول\n\n" .
                 "2. منتج تجريبي 2\n\n" .
                 "الكمية: 1\n\n" .
                 "السعر: 500 ريال\n\n" .
                 "الإجمالي: 500 ريال\n\n" .
                 "الوصف: وصف المنتج التجريبي الثاني\n\n" .
                 "💰 قيمة الطلب: 1,500.00 ريال\n\n" .
                 "📅 تاريخ التسليم المتوقع:\n\n" .
                 "📝 الملاحظات:\n\n" .
                 "⏰ وقت الطلب: " . date('Y-m-d H:i:s') . "\n\n" .
                 "في حالة وجود شكوى او ملاحظات يمكنكم التواصل على واتس الشكاوي\n\n" .
                 "+966541895145\n\n" .
                 "https://wa.me/message/Z3WDPFCBICGLN1\n\n" .
                 "وكالة ابداع واحتراف للدعاية والاعلان";
          enqueue_followup_message($to, $msg, 'text', 'test_new_order|' . date('YmdHis'));
          $summary = process_whatsapp_queue((int)($body['limit'] ?? 20));
          respond($summary, null, 200);
        } else {
          // Enrich context from DB to avoid empty fields in admin follow-up
          $orderId = trim((string)($body['order_id'] ?? ''));
          $ordNoBody = trim((string)($body['order_number'] ?? ''));
          if ($orderId === '' && $ordNoBody !== '') {
            try {
              $stOrd = pdo()->prepare("SELECT id FROM orders WHERE order_number = :no LIMIT 1");
              $stOrd->execute([':no' => $ordNoBody]);
              $orderId = (string)($stOrd->fetchColumn() ?: '');
            } catch (Throwable $e) { $orderId = ''; }
          }

          if ($orderId !== '') {
            // Build full context from DB
            $ctx = get_order_context($orderId);
            // Ensure items text
            if (empty($ctx['order_items']) || trim((string)$ctx['order_items']) === '' || $ctx['order_items'] === 'لا توجد بنود مسجلة') {
              $items = '';
              try { $items = build_order_items_section($orderId); } catch (Throwable $e) { $items = ''; }
              $ctx['order_items'] = ($items && trim($items) !== '' && $items !== 'لا توجد بنود مسجلة') ? $items : 'سيتم تزويدكم ببنود الطلب قريباً.';
            }
            // Send follow-up using template (no static text)
            send_followup_event('new_order_notification', $ctx, null);
          } else {
            // Fallback to minimal context if order not resolvable
            $ctx = [
              'id' => (string)($body['order_id'] ?? ''),
              'order_id' => (string)($body['order_id'] ?? ''),
              'order_number' => (string)($body['order_number'] ?? ''),
              'amount' => $body['amount'] ?? null,
            ];
            send_followup_event('new_order_notification', $ctx, null);
          }
          $summary = process_whatsapp_queue((int)($body['limit'] ?? 20));
          respond($summary, null, 200);
        }
        break;
      }
      case 'notify-delivery-delay': {
        // Exact test sample for delivery delay
        ensure_whatsapp_schema();
        $to = get_followup_number();
        $msg = "⚠ تنبيه: تجاوز فترة التسليم\n\n" .
               "📦 رقم الطلب: TEST-DEL-20251224\n\n" .
               "👤 اسم العميل: اختبار\n\n" .
               "📅 تاريخ التسليم المتوقع: 2025-12-24\n\n" .
               "⏱ تأخير: 1+ أيام\n\n" .
               "يرجى المتابعة الفورية مع العميل.";
        enqueue_followup_message($to, $msg, 'text', 'test_delivery_delay|' . date('YmdHis'));
        $summary = process_whatsapp_queue((int)($body['limit'] ?? 20));
        respond($summary, null, 200);
        break;
      }
      case 'notify-payment-delay': {
        // Exact test sample for payment delay
        ensure_whatsapp_schema();
        $to = get_followup_number();
        $msg = "💰 تنبيه: تأخير في الدفعات\n\n" .
               "👤 اسم العميل: اختبار\n\n" .
               "📱 رقم الواتساب: +249127128021\n\n" .
               "💵 الرصيد المستحق: 100.00 ريال\n\n" .
               "📦 أقدم طلب: TEST-PAY-20251224\n\n" .
               "📅 تاريخ الطلب: 2025-12-24\n\n" .
               "⏱ مر على الطلب: 2+ أيام\n\n" .
               "يرجى المتابعة مع العميل لتحصيل المستحقات.";
        enqueue_followup_message($to, $msg, 'text', 'test_payment_delay|' . date('YmdHis'));
        $summary = process_whatsapp_queue((int)($body['limit'] ?? 20));
        respond($summary, null, 200);
        break;
      }
      case 'notify-new-expense': {
        // Send real expense notification using template/new_expense_notification
        ensure_whatsapp_schema();
        $isTest = (bool)($body['test'] ?? ($_GET['test'] ?? false));
        if ($isTest) {
          $to = get_followup_number();
          $msg = "💸 مصروف جديد\n\n" .
                 "💰 المبلغ: 250.00 ريال\n\n" .
                 "📂 نوع المصروف: مصروف تجريبي\n\n" .
                 "📝 الوصف: مصروف اختبار لنظام الإشعارات\n\n" .
                 "📅 التاريخ: " . date('Y-m-d') . "\n\n" .
                 "📋 رقم الإيصال: EXP-TEST-001\n\n" .
                 "⏰ وقت التسجيل: " . date('Y-m-d H:i:s');
          enqueue_followup_message($to ?: (get_followup_number() ?: '+966500000000'), $msg, 'new_expense_notification', 'test_expense|' . date('YmdHis'));
          $summary = process_whatsapp_queue((int)($body['limit'] ?? 20));
          respond($summary, null, 200);
        } else {
          $ctx = is_array($body['data'] ?? null) ? $body['data'] : [];
          // Normalize
          if (isset($ctx['amount'])) { $ctx['amount'] = number_format((float)$ctx['amount'], 2); }
          if (empty($ctx['expense_date'])) { $ctx['expense_date'] = date('Y-m-d'); }
          $to = (string)($body['to'] ?? '');
          if (trim($to) === '') { $to = get_followup_number(); }
          if (trim($to) === '') { respond(['processed' => 0, 'errors' => 1, 'message' => 'Missing follow-up WhatsApp number'], null, 200); }
          send_followup_event('new_expense_notification', $ctx, $to, (bool)($body['force_send'] ?? true));
          $summary = process_whatsapp_queue((int)($body['limit'] ?? 20));
          respond($summary, null, 200);
        }
        break;
      }
      case 'notify-new-payment': {
        // If test mode, enqueue exact sample payment message
        $isTest = (bool)($body['test'] ?? ($_GET['test'] ?? false));
        if ($isTest) {
          ensure_whatsapp_schema();
          $to = get_followup_number();
          $msg = "💰 إشعار: تسجيل دفعة جديدة\n\n" .
                 "📦 رقم الطلب: ORD-TEST-12345\n\n" .
                 "👤 العميل: عميل تجريبي\n\n" .
                 "📱 واتساب العميل: +966501234567\n\n" .
                 "━━━━━━━━━━━━━━━━━━━━\n\n" .
                 "💵 تفاصيل الدفعة:\n\n" .
                 "• المبلغ المدفوع: 500.00 ر.س\n\n" .
                 "• طريقة الدفع: 🏦 تحويل بنكي\n\n" .
                 "• تاريخ الدفع: 2025-12-24\n\n" .
                 "• رقم المرجع: TEST-001\n\n" .
                 "━━━━━━━━━━━━━━━━━━━━\n\n" .
                 "📊 حالة الطلب:\n\n" .
                 "• إجمالي الطلب: 1,500.00 ر.س\n\n" .
                 "• المبلغ المدفوع: 1,000.00 ر.س\n\n" .
                 "• المتبقي: 500.00 ر.س\n\n" .
                 "• الحالة: ⏳ دفعة جزئية\n\n" .
                 "⏰ ٠٧:٠٥ م\n\n" .
                 "🧪 هذه رسالة اختبار";
          enqueue_followup_message($to, $msg, 'text', 'test_payment|' . date('YmdHis'));
          $summary = process_whatsapp_queue((int)($body['limit'] ?? 20));
          respond($summary, null, 200);
        } else {
          $ctx = [
            'order_id' => (string)($body['order_id'] ?? ''),
            'amount' => $body['amount'] ?? 0,
          ];
          send_followup_event('new_payment_notification', $ctx, null);
          $summary = process_whatsapp_queue((int)($body['limit'] ?? 20));
          respond($summary, null, 200);
        }
        break;
      }
      case 'enqueue_followup_message': {
        $to = (string)($body['to'] ?? '');
        $message = (string)($body['message'] ?? '');
        $type = (string)($body['type'] ?? 'follow_up');
        $dedupe = isset($body['dedupe_key']) ? (string)$body['dedupe_key'] : null;
        $res = enqueue_followup_message($to, $message, $type, $dedupe);
        respond($res, null, 200);
        break;
      }
      case 'send-installment-reminders': {
        try {
          ensure_whatsapp_schema();
          ensure_installments_schema();
          $pdo = pdo();
          $today = new DateTime('today');
          $targets = [
            ['offset' => 2, 'flag' => 'reminder_sent_2days'],
            ['offset' => 1, 'flag' => 'reminder_sent_1day']
          ];
          $total = 0; $details = [];
          foreach ($targets as $t) {
            $d = clone $today; $d->modify('+' . (int)$t['offset'] . ' day');
            $due = $d->format('Y-m-d');
            // Fetch pending installments due on target date and not yet reminded for this offset
            $sql = "SELECT ipm.id AS installment_id, ipm.installment_number, ipm.amount, ipm.due_date,
                           ip.order_id, o.order_number,
                           COALESCE(c.whatsapp, c.phone) AS customer_phone
                    FROM installment_payments ipm
                    LEFT JOIN installment_plans ip ON ipm.installment_plan_id = ip.id
                    LEFT JOIN orders o ON ip.order_id = o.id
                    LEFT JOIN customers c ON ip.customer_id = c.id
                    WHERE (LOWER(TRIM(ipm.status)) = 'pending' OR ipm.status IS NULL OR ipm.status = '')
                      AND ipm.due_date = :d
                      AND (COALESCE(ipm.`" . $t['flag'] . "`, 0) = 0)";
            $st = $pdo->prepare($sql);
            $st->execute([':d' => $due]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (empty($rows)) { $details[] = ['date' => $due, 'count' => 0]; continue; }

            // Prepare template once
            $tpl = get_template_content('installment_reminder');
            $sentIds = [];
            foreach ($rows as $r) {
              $to = trim((string)($r['customer_phone'] ?? ''));
              if ($to === '') continue;
              $ctx = [
                'order_number' => (string)($r['order_number'] ?? ''),
                'amount' => number_format((float)($r['amount'] ?? 0), 2),
                'due_date' => (string)($r['due_date'] ?? $due),
                'installment_number' => (string)($r['installment_number'] ?? ''),
                'customer_phone' => $to,
              ];
              $message = $tpl ? render_template($tpl, $ctx) : (
                "🔔 تذكير بموعد دفع القسط\n\n" .
                "📋 رقم الطلب: " . ($ctx['order_number'] ?: '-') . "\n\n" .
                "💰 المبلغ المطلوب: " . $ctx['amount'] . "\n\n" .
                "📅 موعد الاستحقاق: " . $ctx['due_date'] . "\n\n" .
                "📝 رقم القسط: " . $ctx['installment_number'] . "\n\n" .
                "يرجى السداد في الموعد المحدد. شكراً لك! 🙏"
              );
              $id = generate_uuid_v4();
              $dedupe = 'installment_reminder|' . md5($to . '|' . ($r['order_number'] ?? '') . '|' . ($r['installment_number'] ?? '') . '|' . $due);
              try {
                $ins = $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, dedupe_key, created_at) VALUES (:id, 'system', :to, 'installment_reminder', :msg, 'pending', :dk, NOW())");
                $ins->execute([':id' => $id, ':to' => $to, ':msg' => $message, ':dk' => $dedupe]);
                $sentIds[] = (string)$r['installment_id'];
                $total++;
              } catch (Throwable $eIns) { /* continue */ }
            }
            // Mark reminders as sent for this offset
            if (!empty($sentIds)) {
              $ph = [];$params = [];$i=0;
              foreach ($sentIds as $sid) { $k = ':id' . $i; $ph[] = $k; $params[$k] = $sid; $i++; }
              try { $pdo->prepare("UPDATE installment_payments SET `" . $t['flag'] . "` = 1 WHERE id IN (" . implode(',', $ph) . ")")->execute($params); } catch (Throwable $eUp) { /* ignore */ }
            }
            $details[] = ['date' => $due, 'count' => count($sentIds)];
          }
          // Process queue via webhook
          $summary = process_whatsapp_queue((int)($body['limit'] ?? 50));
          respond(['success' => true, 'total' => $total, 'details' => $details, 'queue' => $summary], null, 200);
        } catch (Throwable $e) {
          respond(null, ['message' => $e->getMessage(), 'code' => 'installment_reminders_error'], 500);
        }
        break;
      }
      case 'cron-delivery-delay': {
        // Cron-safe: notify once per order (ever) after delivery_date is passed and not completed/cancelled
        try {
          ensure_whatsapp_schema();
          $pdo = pdo();
          // Settings: enable flag + days threshold (optional)
          $settings = null; try { $stS = $pdo->query("SELECT * FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1"); $settings = $stS->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) { $settings = null; }
          $enabled = true;
          if ($settings && array_key_exists('notify_delivery_delay', $settings)) {
            $val = strtolower((string)$settings['notify_delivery_delay']);
            $enabled = !($val === '0' || $val === 'false' || $val === 'no');
          }
          if (!$enabled) { respond(['success' => true, 'message' => 'Notification disabled'], null, 200); }

          $today = date('Y-m-d H:i:s');
          $runDay = date('Ymd'); // for same-day dedupe
          // Select orders whose due datetime has already passed (by even 1 minute), not completed/cancelled, and not notified today
          $sql = "SELECT o.id, o.order_number, o.delivery_date, c.name AS customer_name
                  FROM orders o
                  LEFT JOIN customers c ON c.id = o.customer_id
                  WHERE o.delivery_date IS NOT NULL AND o.delivery_date <> ''
                    AND (
                      -- Only orders with a concrete time today that already passed
                      o.estimated_delivery_time IS NOT NULL AND o.estimated_delivery_time <> ''
                      AND DATE(o.delivery_date) = CURDATE()
                      AND STR_TO_DATE(
                            CONCAT(
                              DATE_FORMAT(o.delivery_date, '%Y-%m-%d'), ' ',
                              CASE WHEN LENGTH(o.estimated_delivery_time) = 5 THEN CONCAT(o.estimated_delivery_time, ':00') ELSE o.estimated_delivery_time END
                            ),
                            '%Y-%m-%d %H:%i:%s'
                          ) < NOW()
                    )
                    AND (LOWER(TRIM(o.status)) NOT IN ('completed','cancelled'))
                    AND NOT EXISTS (
                      SELECT 1 FROM whatsapp_messages wm
                      WHERE wm.message_type = 'delivery_delay_notification'
                        AND wm.dedupe_key = CONCAT('delivery_delay|', o.id, '|', :runDay)
                    )
                  ORDER BY o.delivery_date ASC
                  LIMIT 200";
          $st = $pdo->prepare($sql);
          $st->execute([':runDay' => $runDay]);
          $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
          if (empty($rows)) { respond(['success' => true, 'inserted' => 0, 'processed_queue' => 0], null, 200); }

          // Prepare template once
          $tpl = get_template_content('delivery_delay_notification');
          $to = get_followup_number();
          if (trim((string)$to) === '') { respond(['success' => false, 'error' => 'Missing follow-up WhatsApp number'], null, 200); }

          $inserted = 0;
          foreach ($rows as $r) {
            $orderId = (string)($r['id'] ?? '');
            $ordNo = (string)($r['order_number'] ?? '');
            $cust = (string)($r['customer_name'] ?? '');
            $delDate = (string)($r['delivery_date'] ?? '');
            // Compute delay days from actual difference
            $delayDays = 1;
            try {
              $d1 = new DateTime($delDate);
              $d2 = new DateTime($today);
              $delayDays = max(1, (int)$d1->diff($d2)->days);
            } catch (Throwable $eD) { $delayDays = 1; }

            $ctx = [
              'order_number' => $ordNo,
              'customer_name' => ($cust !== '' ? $cust : 'عميل'),
              'delivery_date' => $delDate,
              'delay_days' => (string)$delayDays,
              'customer_phone' => $to,
            ];
            $message = $tpl ? render_template($tpl, $ctx) : (
              "⚠️ _تنبيه: تجاوز فترة التسليم_\n\n" .
              "📦 رقم الطلب: " . $ordNo . "\n\n" .
              "👤 اسم العميل: " . ($cust !== '' ? $cust : 'عميل') . "\n\n" .
              "📅 تاريخ التسليم المتوقع: " . $delDate . "\n\n" .
              "⏱️ تأخير: " . $delayDays . "+ أيام\n\n" .
              "يرجى المتابعة الفورية مع العميل."
            );

            // Enqueue with strong dedupe: one-time per order
            $id = generate_uuid_v4();
            $dedupe = 'delivery_delay|' . $orderId . '|' . $runDay; // prevent duplicate sends within the same day
            try {
              // Double-check dedupe just in case racing cron
              $chk = $pdo->prepare("SELECT id FROM whatsapp_messages WHERE dedupe_key = :dk LIMIT 1");
              $chk->execute([':dk' => $dedupe]);
              if ($chk->fetchColumn()) { continue; }

              $ins = $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, dedupe_key, created_at) VALUES (:id, 'system', :to, 'delivery_delay_notification', :msg, 'pending', :dk, NOW())");
              $ins->execute([':id' => $id, ':to' => $to, ':msg' => $message, ':dk' => $dedupe]);
              $inserted++;
            } catch (Throwable $eIns) { /* skip */ }
          }

          // Process queue
          $processed = 0; if ($inserted > 0) { $summary = process_whatsapp_queue((int)($body['limit'] ?? 20)); $processed = (int)($summary['processed'] ?? 0); }
          respond(['success' => true, 'inserted' => $inserted, 'processed_queue' => $processed], null, 200);
        } catch (Throwable $e) {
          respond(null, ['message' => $e->getMessage(), 'code' => 'cron_delivery_delay_error'], 500);
        }
        break;
      }
      case 'cron-payment-delay': {
        // Cron-safe: notify once per customer (per day) when oldest unpaid order crosses the payment delay threshold today
        try {
          ensure_whatsapp_schema();
          $pdo = pdo();
          // Load settings: enable flag and delay days
          $settings = null; try { $stS = $pdo->query("SELECT * FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1"); $settings = $stS->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) { $settings = null; }
          $enabled = true; $delayDays = 7;
          if ($settings && array_key_exists('notify_payment_delay', $settings)) {
            $val = strtolower((string)$settings['notify_payment_delay']);
            $enabled = !($val === '0' || $val === 'false' || $val === 'no');
          }
          if ($settings && array_key_exists('payment_delay_days', $settings) && is_numeric($settings['payment_delay_days'])) {
            $delayDays = max(1, (int)$settings['payment_delay_days']);
          }
          if (!$enabled) { respond(['success' => true, 'message' => 'Payment delay notification disabled'], null, 200); }

          $daysInt = max(1, (int)$delayDays);

          $runDay = date('Ymd');
          // Compute customers with outstanding > 0 and whose oldest unpaid order becomes overdue today (oldest_date + delayDays days = today and <= now)
          $sql = "SELECT t.customer_id,
                         c.name AS customer_name,
                         COALESCE(c.whatsapp, c.phone) AS customer_phone,
                         t.outstanding_balance,
                         t.oldest_date,
                         (
                           SELECT o2.order_number
                           FROM orders o2
                           WHERE o2.customer_id = t.customer_id
                             AND GREATEST(COALESCE(o2.total_amount,0) - COALESCE(o2.paid_amount,0),0) > 0
                             AND (LOWER(TRIM(o2.status)) NOT IN ('cancelled'))
                           ORDER BY COALESCE(o2.created_at, o2.id) ASC
                           LIMIT 1
                         ) AS oldest_order
                  FROM (
                    SELECT o.customer_id,
                           SUM(GREATEST(COALESCE(o.total_amount,0) - COALESCE(o.paid_amount,0),0)) AS outstanding_balance,
                           MIN(COALESCE(o.created_at, o.delivery_date)) AS oldest_date
                    FROM orders o
                    WHERE GREATEST(COALESCE(o.total_amount,0) - COALESCE(o.paid_amount,0),0) > 0
                      AND (LOWER(TRIM(o.status)) NOT IN ('cancelled'))
                    GROUP BY o.customer_id
                  ) t
                  LEFT JOIN customers c ON c.id = t.customer_id
                  WHERE t.outstanding_balance > 0
                    AND DATE(DATE_ADD(t.oldest_date, INTERVAL " . (int)$daysInt . " DAY)) = CURDATE()
                    AND DATE_ADD(t.oldest_date, INTERVAL " . (int)$daysInt . " DAY) <= NOW()
                    AND NOT EXISTS (
                      SELECT 1 FROM whatsapp_messages wm
                      WHERE wm.message_type = 'payment_delay_notification'
                        AND wm.dedupe_key = CONCAT('payment_delay|', t.customer_id, '|', :runDay)
                    )
                  ORDER BY t.oldest_date ASC
                  LIMIT 500";
          $st = $pdo->prepare($sql);
          $st->execute([':runDay' => $runDay]);
          $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
          if (empty($rows)) { respond(['success' => true, 'inserted' => 0, 'processed_queue' => 0], null, 200); }

          $to = get_followup_number();
          if (trim((string)$to) === '') { respond(['success' => false, 'error' => 'Missing follow-up WhatsApp number'], null, 200); }

          $inserted = 0; $today = new DateTime('now');
          foreach ($rows as $r) {
            $custId = (string)($r['customer_id'] ?? '');
            $custName = trim((string)($r['customer_name'] ?? 'عميل'));
            $custPhone = trim((string)($r['customer_phone'] ?? ''));
            $outBal = number_format((float)($r['outstanding_balance'] ?? 0), 2);
            $oldestOrder = (string)($r['oldest_order'] ?? '');
            $oldestDateRaw = (string)($r['oldest_date'] ?? '');
            $orderDate = $oldestDateRaw !== '' ? date('Y-m-d', strtotime($oldestDateRaw)) : date('Y-m-d');
            // Actual days since oldest order date
            $delay = 1;
            try { $od = new DateTime($oldestDateRaw); $delay = max(1, (int)$od->diff(new DateTime('today'))->days); } catch (Throwable $eD) { $delay = $delayDays; }

            // Build message (override to ensure exact content as requested)
            $message = "💰 _تنبيه: تأخير في الدفعات_\n\n" .
                       "👤 اسم العميل: " . ($custName !== '' ? $custName : 'عميل') . "\n\n" .
                       "📱 رقم الواتساب: " . ($custPhone !== '' ? $custPhone : '-') . "\n\n" .
                       "💵 الرصيد المستحق: " . $outBal . " ريال\n\n" .
                       "📦 أقدم طلب: " . ($oldestOrder !== '' ? $oldestOrder : '-') . "\n\n" .
                       "📅 تاريخ الطلب: " . $orderDate . "\n\n" .
                       "⏱️ مر على الطلب: " . $delay . "+ أيام\n\n" .
                       "يرجى المتابعة مع العميل لتحصيل المستحقات.";

            $id = generate_uuid_v4();
            $dedupe = 'payment_delay|' . $custId . '|' . $runDay;
            try {
              $ins = $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, dedupe_key, created_at) VALUES (:id, 'system', :to, 'payment_delay_notification', :msg, 'pending', :dk, NOW())");
              $ins->execute([':id' => $id, ':to' => $to, ':msg' => $message, ':dk' => $dedupe]);
              $inserted++;
            } catch (Throwable $eIns) { /* skip */ }
          }

          $processed = 0; if ($inserted > 0) { $summary = process_whatsapp_queue((int)($body['limit'] ?? 50)); $processed = (int)($summary['processed'] ?? 0); }
          respond(['success' => true, 'inserted' => $inserted, 'processed_queue' => $processed], null, 200);
        } catch (Throwable $e) {
          respond(null, ['message' => $e->getMessage(), 'code' => 'cron_payment_delay_error'], 500);
        }
        break;
      }
      case 'search-orders-for-installment': {
        try {
          $pdo = pdo();
          $q = trim((string)($body['q'] ?? ($_GET['q'] ?? ($_POST['q'] ?? ''))));
          $limit = (int)($body['limit'] ?? ($_GET['limit'] ?? 20));
          if ($limit <= 0 || $limit > 100) $limit = 20;
          $like = '%' . $q . '%';
          // Sanitize and inline LIKE literal to avoid any parameter mismatch issues
          $safe = str_replace(["%","_","\\","'","\""], '', (string)$q);
          $likeLit = '%' . $safe . '%';
          $quotedLike = $pdo->quote($likeLit);
          $__tid = tenant_is_platform_admin() ? null : tenant_current_id();
          $tenantCond = $__tid !== null ? (" AND o.tenant_id = " . $pdo->quote($__tid)) : "";
          $sql = "SELECT o.id, o.order_number, o.total_amount, o.paid_amount,
                         (COALESCE(o.total_amount,0) - COALESCE(o.paid_amount,0)) AS remaining_amount,
                         COALESCE(o.status,'') AS status,
                         c.name AS customer_name, COALESCE(c.whatsapp, c.phone) AS customer_phone
                  FROM orders o
                  LEFT JOIN customers c ON c.id = o.customer_id
                  WHERE (o.order_number LIKE " . $quotedLike . "
                         OR c.name LIKE " . $quotedLike . "
                         OR COALESCE(c.whatsapp, c.phone) LIKE " . $quotedLike . ")" . $tenantCond . "
                  ORDER BY COALESCE(o.updated_at, o.created_at) DESC, o.order_number DESC
                  LIMIT " . (int)$limit;
          $st = $pdo->query($sql);
          $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
          // Optionally filter to unpaid only if requested (or by default when a query is provided)
          $unpaidOnly = (bool)($body['unpaid_only'] ?? false);
          if ($unpaidOnly) {
            $rows = array_values(array_filter($rows, function($r){
              $tot = (float)($r['total_amount'] ?? 0);
              $paid = (float)($r['paid_amount'] ?? 0);
              return ($tot - $paid) > 0.0001;
            }));
          }
          respond($rows, null, 200);
        } catch (Throwable $e) {
          respond(null, ['message' => $e->getMessage(), 'code' => 'search_installment_error'], 500);
        }
        break;
      }
      case 'pay-salary': {
        $employeeId = (string)($req['employee_id'] ?? '');
        $payMonth = trim((string)($req['pay_month'] ?? date('Y-m')));
        $bonus = (float)($req['bonus'] ?? 0);
        $deductions = (float)($req['deductions'] ?? 0);
        $notes = (string)($req['notes'] ?? '');
        if ($employeeId === '') respond(null, ['message' => 'employee_id مطلوب'], 400);
        if (!preg_match('/^\d{4}-\d{2}$/', $payMonth)) respond(null, ['message' => 'صيغة الشهر غير صحيحة، استخدم YYYY-MM'], 400);
        $result = pay_employee_salary($employeeId, $payMonth, $bonus, $deductions, $notes);
        if (!($result['ok'] ?? false)) respond(null, ['message' => $result['error'] ?? 'فشل صرف الراتب'], 400);
        respond($result);
        break;
      }
      case 'salary-report': {
        $employeeId = (string)($req['employee_id'] ?? '');
        $month = trim((string)($req['month'] ?? ''));
        $year = trim((string)($req['year'] ?? ''));
        $from = trim((string)($req['from'] ?? ''));
        $to = trim((string)($req['to'] ?? ''));
        $pdo = pdo(); ensure_hr_finance_schema();
        $__tid = tenant_is_platform_admin() ? null : tenant_current_id();
        $sql = "SELECT sp.*, e.full_name, e.position FROM salary_payments sp LEFT JOIN employees e ON e.id = sp.employee_id WHERE 1=1";
        $params = [];
        if ($__tid !== null) { $sql .= " AND sp.tenant_id = :tid"; $params[':tid'] = $__tid; }
        if ($employeeId !== '') { $sql .= " AND sp.employee_id = :eid"; $params[':eid'] = $employeeId; }
        if ($month !== '') { $sql .= " AND sp.pay_month = :m"; $params[':m'] = $month; }
        elseif ($year !== '') { $sql .= " AND sp.pay_month LIKE :y"; $params[':y'] = $year . '-%'; }
        elseif ($from !== '' && $to !== '') { $sql .= " AND sp.pay_month >= :f AND sp.pay_month <= :t"; $params[':f'] = $from; $params[':t'] = $to; }
        $sql .= " ORDER BY sp.pay_month DESC, e.full_name ASC";
        $st = $pdo->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $total = 0.0; foreach ($rows as $r) { $total += (float)($r['total_amount'] ?? 0); }
        respond(['rows' => $rows, 'total' => $total, 'count' => count($rows)]);
        break;
      }
      case 'generate-recurring-expenses': {
        $result = generate_recurring_fixed_expenses();
        respond($result);
        break;
      }
      case 'test-fallback-whatsapp': {
        $to = trim((string)($req['to'] ?? ''));
        $pdo = pdo(); ensure_hr_finance_schema();
        if ($to === '') {
          try {
            $stf = $pdo->query("SELECT whatsapp_number FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC LIMIT 1");
            $to = (string)($stf->fetchColumn() ?: '');
          } catch (Throwable $e) { /* ignore */ }
        }
        if ($to === '') respond(null, ['message' => 'لا يوجد رقم واتساب محدد للاختبار (أدخل رقم إدارة المتابعة أولاً)'], 400);
        $cfg = get_active_whatsapp_fallback_settings();
        if (!$cfg) respond(null, ['message' => 'لم يتم إعداد مزود واتساب البديل أو أنه غير مفعل'], 400);
        $msg = "✅ رسالة اختبار من نظام التنبيهات البديل (عند فشل WaSender)\nالوقت: " . date('Y-m-d H:i:s');
        $result = send_via_fallback_whatsapp($to, $msg, $cfg);
        if (!($result['ok'] ?? false)) respond(null, ['message' => 'فشل الإرسال: ' . ($result['error'] ?? 'غير معروف'), 'details' => $result], 502);
        respond(['sent' => true, 'response' => $result['resp'] ?? '']);
        break;
      }
      case 'test-whatsapp-alert':
      case 'test-failure-alert': {
        // On-demand test of the STAFF failure-alert path — the alert that must reach follow-up
        // management (via the fallback provider) when WaSender is down or an order-status message
        // fails. This runs the EXACT same code the queue uses, but bypasses the 30-minute debounce
        // so it can be triggered repeatedly. Open on the live server:
        //   /api/index.php?service=functions&action=test-whatsapp-alert
        // The structured result shows precisely why an alert did or didn't go out (skipped_reason,
        // throttled, notify_on, send_error). No phone numbers are exposed (to_masked only).
        $reason = trim((string)($req['reason'] ?? ''));
        if ($reason === '') $reason = 'اختبار يدوي: محاكاة فشل WaSender لإرسال تنبيه لإدارة المتابعة';
        $res = evaluate_and_send_whatsapp_alert($reason, ['message_type' => 'order_status_updated (اختبار)'], true);
        respond($res);
        break;
      }
      case 'wa-inbound-config': {
        // Returns the webhook URL to paste into WaSender + current connection state.
        // The webhook URL is shown to the logged-in admin UI. Forged inbound calls are still
        // rejected at the webhook itself (handle_wa_webhook returns 401 without the correct
        // 64-char random secret), which is the real trust boundary for inbound messages.
        // SECURITY: this response contains the raw secret — logged-in users only. Without this
        // check, anyone could fetch the secret and unlock all secret-gated admin endpoints.
        if (!ai_request_user()) respond(null, ['message'=>'يتطلب تسجيل الدخول'], 401);
        $cfg = wa_get_or_create_inbound_secret();
        $secret = (string)($cfg['inbound_secret'] ?? '');
        $counts = ['incoming'=>0, 'outgoing'=>0];
        try { $counts['incoming'] = (int)pdo()->query("SELECT COUNT(*) FROM whatsapp_messages WHERE direction='incoming'")->fetchColumn(); } catch (Throwable $e) { /* ignore */ }
        try { $counts['outgoing'] = (int)pdo()->query("SELECT COUNT(*) FROM whatsapp_messages WHERE direction='outgoing'")->fetchColumn(); } catch (Throwable $e) { /* ignore */ }
        respond([
          'webhook_url' => wa_public_base_url() . '/api/index.php?service=wa-webhook&secret=' . rawurlencode($secret),
          'secret' => $secret,
          'secret_masked' => wa_mask_secret($secret),
          'connected_number' => $cfg['connected_number'] ?? null,
          'api_connected' => (bool)get_active_whatsapp_api_settings(),
          'counts' => $counts,
        ]);
        break;
      }
      case 'wa-conversations': {
        // One row per counterpart with unread count + last-message preview. Single query,
        // aggregated in PHP (created_at is a corrupted VARCHAR so we only string-sort it).
        // DEFENSIVE: the LIVE (cPanel/MySQL 5.x) whatsapp_messages table may predate the two-way
        // inbox columns (direction/is_read/customer_id/contact_name). We select ONLY columns that
        // actually exist and derive direction in PHP, so a missing column can never 500 the whole
        // inbox (which previously surfaced as an empty "لا توجد محادثات" list in BOTH directions).
        // Opportunistic DB-pull (throttled inside): fetch fresh inbound messages from the
        // WaSender MySQL DB before rendering the list — webhook POSTs are WAF-blocked on the LIVE host.
        try { wa_pull_run(false); } catch (Throwable $e) { /* never break the inbox */ }
        // Lazy AI-agent cron (time-gated inside; never breaks the inbox)
        try { ai_agent_cron(); } catch (Throwable $e) { /* ignore */ }
        $pdo = pdo();
        $cols = get_table_columns('whatsapp_messages');
        $hasCol = function($c) use ($cols) { return isset($cols[$c]); };
        $sel = ['from_number','to_number','message_type','message_content','created_at'];
        foreach (['direction','is_read','customer_id','contact_name'] as $o) { if ($hasCol($o)) $sel[] = $o; }
        $where = $hasCol('direction')
          ? "WHERE direction IN ('incoming','outgoing')"
          : "WHERE (from_number = 'system' OR to_number = 'system')";
        $orderCol = $hasCol('created_at') ? 'created_at' : 'id';
        try {
          $st = $pdo->query("SELECT `" . implode('`,`', $sel) . "` FROM whatsapp_messages $where ORDER BY $orderCol DESC LIMIT 4000");
          $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { respond(null, ['message'=>'conv_query_failed: ' . $e->getMessage()], 500); break; }
        $map = [];
        foreach ($rows as $r) {
          $from = (string)($r['from_number'] ?? '');
          $to   = (string)($r['to_number'] ?? '');
          $dir  = (string)($r['direction'] ?? '');
          if ($dir !== 'incoming' && $dir !== 'outgoing') {
            // Legacy rows with no direction column: derive from the 'system' sentinel side.
            if ($to === 'system' && $from !== '' && $from !== 'system') $dir = 'incoming';
            elseif ($from === 'system' && $to !== '' && $to !== 'system') $dir = 'outgoing';
            else continue;
          }
          $peerRaw = $dir === 'incoming' ? $from : $to;
          if ($peerRaw === '' || $peerRaw === 'system') continue;
          // Group by CANONICAL phone so outgoing (local-format 05xx) and incoming
          // (international 9665xx) rows for the same person form ONE conversation.
          $peer = wa_canon_phone($peerRaw);
          if ($peer === '') $peer = $peerRaw;
          if (!isset($map[$peer])) {
            $map[$peer] = [
              'phone'=>$peer, 'contact_name'=>($r['contact_name'] ?? null) ?: null, 'customer_id'=>($r['customer_id'] ?? null) ?: null,
              'last_at'=>(string)($r['created_at'] ?? ''), 'last_message'=>(string)($r['message_content'] ?? ''),
              'last_message_type'=>(string)(($r['message_type'] ?? '') ?: 'text'), 'last_direction'=>$dir,
              'msg_count'=>0, 'unread'=>0,
            ];
          }
          $map[$peer]['msg_count']++;
          if ($dir === 'incoming' && (int)($r['is_read'] ?? 0) === 0) $map[$peer]['unread']++;
          if (empty($map[$peer]['contact_name']) && !empty($r['contact_name'])) $map[$peer]['contact_name'] = $r['contact_name'];
          if (empty($map[$peer]['customer_id']) && !empty($r['customer_id'])) $map[$peer]['customer_id'] = $r['customer_id'];
        }
        $out = array_values($map);
        usort($out, function($a, $b) { return strcmp((string)$b['last_at'], (string)$a['last_at']); });
        respond(['conversations'=>$out]);
        break;
      }
      case 'wa-thread': {
        // DEFENSIVE (see wa-conversations above): only select columns that exist on this table,
        // and derive `direction` per-row in PHP when the column is missing/NULL so a partially
        // migrated live schema still renders the full thread instead of 500ing into an empty view.
        $pdo = pdo();
        $phone = trim((string)($req['phone'] ?? ''));
        if ($phone === '') respond(null, ['message'=>'phone required'], 400);
        $limit = (int)($req['limit'] ?? 300); if ($limit <= 0 || $limit > 1000) $limit = 300;
        $cols = get_table_columns('whatsapp_messages');
        $hasCol = function($c) use ($cols) { return isset($cols[$c]); };
        $sel = ['id','from_number','to_number','message_type','message_content','status','created_at'];
        foreach (['direction','media_url','media_mime','media_filename','error_message','contact_name','provider_message_id','is_read'] as $o) { if ($hasCol($o)) $sel[] = $o; }
        // Match ALL stored variants of this phone (05xx / 9665xx / 5xx / +9665xx) so the
        // thread shows outgoing + incoming together regardless of stored format.
        $variants = wa_phone_variants($phone);
        if (!$variants) respond(null, ['message'=>'invalid phone'], 400);
        $phA = []; $phB = []; $bindP = [];
        foreach ($variants as $i => $vv) {
          $ka = ":va$i"; $kb = ":vb$i";
          $phA[] = $ka; $phB[] = $kb;
          $bindP[$ka] = $vv; $bindP[$kb] = $vv;
        }
        $inA = implode(',', $phA); $inB = implode(',', $phB);
        $where = $hasCol('direction')
          ? "WHERE direction IN ('incoming','outgoing') AND ((direction='incoming' AND from_number IN ($inA)) OR (direction='outgoing' AND to_number IN ($inB)))"
          : "WHERE (from_number IN ($inA) OR to_number IN ($inB))";
        $orderCol = $hasCol('created_at') ? 'created_at' : 'id';
        try {
          $st = $pdo->prepare("SELECT `" . implode('`,`', $sel) . "` FROM whatsapp_messages $where ORDER BY $orderCol DESC LIMIT :lim");
          foreach ($bindP as $k => $vv) $st->bindValue($k, $vv);
          $st->bindValue(':lim', $limit, PDO::PARAM_INT);
          $st->execute();
          $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { respond(null, ['message'=>'thread_query_failed: ' . $e->getMessage()], 500); break; }
        foreach ($rows as &$r) {
          $from = (string)($r['from_number'] ?? '');
          $to   = (string)($r['to_number'] ?? '');
          $dir  = (string)($r['direction'] ?? '');
          if ($dir !== 'incoming' && $dir !== 'outgoing') { $dir = ($from === 'system' || in_array($to, $variants, true)) ? 'outgoing' : 'incoming'; }
          $r['direction'] = $dir;
          $mu = (string)($r['media_url'] ?? '');
          $r['media_view_url'] = $mu !== '' ? ('api/index.php?service=wa-media&f=' . rawurlencode(basename($mu))) : null;
        }
        unset($r);
        if (!empty($req['mark_read']) && $hasCol('is_read')) {
          $sql = "UPDATE whatsapp_messages SET is_read=1 WHERE from_number IN ($inA) AND COALESCE(is_read,0)=0" . ($hasCol('direction') ? " AND direction='incoming'" : "");
          try { $st2 = $pdo->prepare($sql); foreach ($phA as $i => $k) $st2->bindValue($k, $variants[$i]); $st2->execute(); } catch (Throwable $e) { /* ignore */ }
        }
        respond(['messages'=>$rows]);
        break;
      }
      case 'wa-mark-read': {
        $phone = trim((string)($req['phone'] ?? ''));
        if ($phone === '') respond(null, ['message'=>'phone required'], 400);
        $mrHasDir = table_has_column('whatsapp_messages', 'direction');
        $mrVariants = wa_phone_variants($phone);
        if (!$mrVariants) respond(null, ['message'=>'invalid phone'], 400);
        $mrPh = []; $mrBind = [];
        foreach ($mrVariants as $i => $vv) { $k = ":v$i"; $mrPh[] = $k; $mrBind[$k] = $vv; }
        $mrSql = "UPDATE whatsapp_messages SET is_read=1 WHERE from_number IN (" . implode(',', $mrPh) . ") AND COALESCE(is_read,0)=0" . ($mrHasDir ? " AND direction='incoming'" : "");
        try { $mrSt = pdo()->prepare($mrSql); foreach ($mrBind as $k => $vv) $mrSt->bindValue($k, $vv); $mrSt->execute(); } catch (Throwable $e) { /* ignore */ }
        respond(['ok'=>true]);
        break;
      }
      // ==================== AI AGENT endpoints ====================
      case 'ai-settings-get': {
        if (!ai_request_user()) respond(null, ['message'=>'غير مصرح — يلزم تسجيل الدخول'], 401);
        ensure_ai_agent_schema();
        $cfg = ai_cfg();
        if (!$cfg) { respond(['settings'=>null]); break; }
        $hasKey = trim((string)($cfg['api_key'] ?? '')) !== '';
        unset($cfg['api_key']);
        $cfg['has_api_key'] = $hasKey;
        $cfg['has_fu_app_key'] = trim((string)($cfg['fu_app_key'] ?? '')) !== '';
        $cfg['has_fu_auth_key'] = trim((string)($cfg['fu_auth_key'] ?? '')) !== '';
        unset($cfg['fu_app_key'], $cfg['fu_auth_key']);
        respond(['settings'=>$cfg]);
        break;
      }
      case 'ai-settings-save': {
        if (!ai_request_user()) respond(null, ['message'=>'غير مصرح — يلزم تسجيل الدخول'], 401);
        ensure_ai_agent_schema();
        $pdo = pdo();
        $cur = ai_cfg();
        $flags = ['enabled','feat_summary','feat_customer_reg','feat_order_draft','feat_delivery_reminder','feat_complaints','feat_unregistered_alert'];
        $vals = [];
        foreach ($flags as $f) { $vals[$f] = !empty($req[$f]) && (string)$req[$f] !== '0' ? 1 : 0; }
        $provider = strtolower(trim((string)($req['provider'] ?? 'gemini')));
        if (!in_array($provider, ['gemini','openai','groq','deepseek'], true)) $provider = 'gemini';
        $model = trim((string)($req['model'] ?? ''));
        $followup = trim((string)($req['followup_whatsapp'] ?? ''));
        $status = trim((string)($req['status_whatsapp'] ?? ''));
        $newKey = (string)($req['api_key'] ?? '');
        // Empty api_key means "keep existing key"
        $apiKey = trim($newKey) !== '' ? trim($newKey) : (string)($cur['api_key'] ?? '');
        // Dedicated follow-up WhatsApp link (WaSender-style). Empty keys keep existing.
        $fuEnabled = !empty($req['fu_direct_enabled']) && (string)$req['fu_direct_enabled'] !== '0' ? 1 : 0;
        $fuProvider = trim((string)($req['fu_provider'] ?? 'WaSender'));
        if ($fuProvider === '') $fuProvider = 'WaSender';
        $fuApiUrl = trim((string)($req['fu_api_url'] ?? ''));
        $fuAppKey = trim((string)($req['fu_app_key'] ?? ''));
        if ($fuAppKey === '') $fuAppKey = (string)($cur['fu_app_key'] ?? '');
        $fuAuthKey = trim((string)($req['fu_auth_key'] ?? ''));
        if ($fuAuthKey === '') $fuAuthKey = (string)($cur['fu_auth_key'] ?? '');
        // Time windows + reply-delay alert (clamped to sane ranges).
        $scanWin = (int)($req['scan_window_hours'] ?? ($cur['scan_window_hours'] ?? 24));
        $scanWin = max(1, min(720, $scanWin));
        // Scan window in minutes (preferred over hours when provided).
        $scanMin = (int)($req['scan_window_minutes'] ?? ($cur['scan_window_minutes'] ?? 1440));
        $scanMin = max(5, min(43200, $scanMin));
        // Custom phrases the AI must treat as complaints/notes (one per line).
        $phrases = isset($req['complaint_phrases']) ? trim((string)$req['complaint_phrases']) : (string)($cur['complaint_phrases'] ?? '');
        $phrases = mb_substr($phrases, 0, 5000);
        $sumWin = (int)($req['summary_window_hours'] ?? ($cur['summary_window_hours'] ?? 72));
        $sumWin = max(1, min(720, $sumWin));
        $rdEnabled = !empty($req['reply_delay_enabled']) && (string)$req['reply_delay_enabled'] !== '0' ? 1 : 0;
        $rdMinutes = (int)($req['reply_delay_minutes'] ?? ($cur['reply_delay_minutes'] ?? 30));
        $rdMinutes = max(5, min(1440, $rdMinutes));
        if ($cur) {
          $st = $pdo->prepare("UPDATE ai_agent_settings SET enabled=:en, provider=:pv, api_key=:ak, model=:md,
            feat_summary=:f1, feat_customer_reg=:f2, feat_order_draft=:f3, feat_delivery_reminder=:f4, feat_complaints=:f5, feat_unregistered_alert=:f6,
            followup_whatsapp=:fw, status_whatsapp=:sw,
            fu_direct_enabled=:fe, fu_provider=:fp, fu_api_url=:fu, fu_app_key=:fk, fu_auth_key=:fa,
            scan_window_hours=:swh, scan_window_minutes=:swm, summary_window_hours=:smh, reply_delay_enabled=:rde, reply_delay_minutes=:rdm, complaint_phrases=:cph,
            updated_at=NOW() WHERE id=:id");
          $st->execute([':en'=>$vals['enabled'], ':pv'=>$provider, ':ak'=>$apiKey, ':md'=>$model,
            ':f1'=>$vals['feat_summary'], ':f2'=>$vals['feat_customer_reg'], ':f3'=>$vals['feat_order_draft'],
            ':f4'=>$vals['feat_delivery_reminder'], ':f5'=>$vals['feat_complaints'], ':f6'=>$vals['feat_unregistered_alert'],
            ':fw'=>$followup, ':sw'=>$status,
            ':fe'=>$fuEnabled, ':fp'=>$fuProvider, ':fu'=>$fuApiUrl, ':fk'=>$fuAppKey, ':fa'=>$fuAuthKey,
            ':swh'=>$scanWin, ':swm'=>$scanMin, ':smh'=>$sumWin, ':rde'=>$rdEnabled, ':rdm'=>$rdMinutes, ':cph'=>$phrases,
            ':id'=>(string)$cur['id']]);
          // Purge duplicate settings rows: with more than one row, "latest updated_at" can
          // flip between rows and the UI reads back stale values (looks like save is ignored).
          // SECURITY (multi-tenant): only purge THIS tenant's duplicates, never another tenant's row.
          try {
            $__tidPurge = tenant_is_platform_admin() ? null : tenant_current_id();
            if ($__tidPurge !== null) {
              $pdo->prepare("DELETE FROM ai_agent_settings WHERE id <> :id AND tenant_id = :tid")->execute([':id'=>(string)$cur['id'], ':tid'=>$__tidPurge]);
            } else {
              $pdo->prepare("DELETE FROM ai_agent_settings WHERE id <> :id")->execute([':id'=>(string)$cur['id']]);
            }
          } catch (Throwable $eDup) { /* ignore */ }
        } else {
          $__tidNew = tenant_is_platform_admin() ? null : tenant_current_id();
          $st = $pdo->prepare("INSERT INTO ai_agent_settings (id, enabled, provider, api_key, model, feat_summary, feat_customer_reg, feat_order_draft, feat_delivery_reminder, feat_complaints, feat_unregistered_alert, followup_whatsapp, status_whatsapp, fu_direct_enabled, fu_provider, fu_api_url, fu_app_key, fu_auth_key, scan_window_hours, scan_window_minutes, summary_window_hours, reply_delay_enabled, reply_delay_minutes, complaint_phrases, tenant_id)
            VALUES (:id,:en,:pv,:ak,:md,:f1,:f2,:f3,:f4,:f5,:f6,:fw,:sw,:fe,:fp,:fu,:fk,:fa,:swh,:swm,:smh,:rde,:rdm,:cph,:tid)");
          $st->execute([':id'=>generate_uuid_v4(), ':en'=>$vals['enabled'], ':pv'=>$provider, ':ak'=>$apiKey, ':md'=>$model,
            ':f1'=>$vals['feat_summary'], ':f2'=>$vals['feat_customer_reg'], ':f3'=>$vals['feat_order_draft'],
            ':f4'=>$vals['feat_delivery_reminder'], ':f5'=>$vals['feat_complaints'], ':f6'=>$vals['feat_unregistered_alert'],
            ':fw'=>$followup, ':sw'=>$status,
            ':fe'=>$fuEnabled, ':fp'=>$fuProvider, ':fu'=>$fuApiUrl, ':fk'=>$fuAppKey, ':fa'=>$fuAuthKey,
            ':swh'=>$scanWin, ':swm'=>$scanMin, ':smh'=>$sumWin, ':rde'=>$rdEnabled, ':rdm'=>$rdMinutes, ':cph'=>$phrases, ':tid'=>$__tidNew]);
        }
        respond(['saved'=>true]);
        break;
      }
      case 'ai-test-followup-send': {
        // Send a real test message through the dedicated follow-up WhatsApp link.
        if (!ai_request_user()) respond(null, ['message'=>'غير مصرح — يلزم تسجيل الدخول'], 401);
        ensure_ai_agent_schema();
        $cfg = ai_cfg();
        if (!$cfg) respond(null, ['message'=>'الرجاء حفظ الإعدادات أولاً'], 400);
        $fu = ai_fu_gateway($cfg);
        if (!$fu) respond(null, ['message'=>'الرجاء تفعيل الربط المباشر لواتساب إدارة المتابعة وإدخال رابط API ومفتاح App Key ثم الحفظ'], 400);
        $to = trim((string)($req['to'] ?? ''));
        if ($to === '') $to = trim((string)($cfg['followup_whatsapp'] ?? ''));
        if ($to === '') respond(null, ['message'=>'أدخل رقم اختبار أو احفظ رقم واتساب إدارة المتابعة أولاً'], 400);
        $r = send_via_whatsapp_api($to, "✅ رسالة اختبار من نظام وكيل الذكاء الاصطناعي — ربط واتساب إدارة المتابعة يعمل بنجاح", $fu);
        if (!is_array($r) || empty($r['ok'])) respond(null, ['message'=>'فشل الإرسال: ' . (is_array($r) ? (string)($r['error'] ?? 'unknown') : 'no_gateway')], 400);
        respond(['ok'=>true]);
        break;
      }
      case 'ai-test-key': {
        if (!ai_request_user()) respond(null, ['message'=>'غير مصرح — يلزم تسجيل الدخول'], 401);
        $cfg = ai_cfg();
        if (!$cfg) respond(null, ['message'=>'الرجاء حفظ الإعدادات أولاً'], 400);
        $r = ai_llm_call($cfg, 'أنت مساعد.', 'أجب بكلمة واحدة فقط: تم', false);
        if (empty($r['ok'])) respond(null, ['message'=>'فشل الاتصال: ' . (string)$r['error']], 400);
        respond(['ok'=>true, 'reply'=>(string)$r['text']]);
        break;
      }
      case 'ai-summarize-order': {
        $phone = trim((string)($req['phone'] ?? ''));
        if ($phone === '') respond(null, ['message'=>'phone required'], 400);
        $cfg = ai_cfg();
        if (!ai_feature_on($cfg, 'feat_summary')) respond(null, ['message'=>'خاصية تلخيص الطلب غير مفعّلة في إعدادات وكيل الذكاء الاصطناعي'], 400);
        $sumWin = max(1, min(720, (int)($cfg['summary_window_hours'] ?? 72) ?: 72));
        $thread = ai_thread_text($phone, 120, $sumWin * 60);
        if ($thread === '') respond(null, ['message'=>'لا توجد رسائل حديثة في هذه المحادثة خلال مدة الجلب المحددة (' . $sumWin . ' ساعة)'], 400);
        $sys = "أنت مساعد لوكالة دعاية وإعلان. لخّص طلب العميل من محادثة واتساب في خطوات مرتبة ومرقّمة بالعربية. يجب أن يتضمن الملخص: 1) تفاصيل الطلب المطلوب، 2) البنود والكميات إن ذُكرت، 3) السعر/المبلغ المتفق عليه إن ذُكر، 4) موعد التسليم إن ذُكر، 5) أي ملاحظات مهمة. اكتب نصًا عاديًا واضحًا بدون جداول، وإن لم تُذكر معلومة اكتب (غير مذكور).";
        $r = ai_llm_call($cfg, $sys, "المحادثة:\n" . $thread, false);
        if (empty($r['ok'])) respond(null, ['message'=>'فشل التلخيص: ' . (string)$r['error']], 500);
        respond(['summary'=>(string)$r['text'], 'attachments'=>ai_thread_media($phone, $sumWin * 60)]);
        break;
      }
      case 'ai-extract-customer': {
        $phone = trim((string)($req['phone'] ?? ''));
        if ($phone === '') respond(null, ['message'=>'phone required'], 400);
        $existing = ai_find_customer_by_phone($phone);
        if ($existing) { respond(['exists'=>true, 'customer'=>$existing]); break; }
        $cfg = ai_cfg();
        $draft = ['name'=>'', 'phone'=>wa_canon_phone($phone), 'address'=>'', 'notes'=>''];
        if (ai_feature_on($cfg, 'feat_customer_reg')) {
          $thread = ai_thread_text($phone, 80);
          if ($thread !== '') {
            $sys = "أنت مساعد لوكالة دعاية وإعلان. استخرج بيانات العميل من محادثة واتساب. أجب JSON فقط: {\"name\": \"اسم العميل إن ذُكر أو ظهر\", \"address\": \"العنوان إن ذُكر\", \"notes\": \"ملاحظات مفيدة عن العميل\"}";
            $r = ai_llm_call($cfg, $sys, "رقم العميل: " . $phone . "\nالمحادثة:\n" . $thread, true);
            if (!empty($r['ok'])) {
              $j = ai_parse_json($r['text']);
              if ($j) {
                $draft['name'] = (string)($j['name'] ?? '');
                $draft['address'] = (string)($j['address'] ?? '');
                $draft['notes'] = (string)($j['notes'] ?? '');
              }
            }
          }
        }
        // Fallback name from stored contact_name
        if ($draft['name'] === '') {
          try {
            $vs = wa_phone_variants($phone); $ph = []; $bd = [];
            foreach ($vs as $i => $v) { $ph[] = ":n$i"; $bd[":n$i"] = $v; }
            $st = pdo()->prepare("SELECT contact_name FROM whatsapp_messages WHERE from_number IN (" . implode(',', $ph) . ") AND contact_name IS NOT NULL AND contact_name <> '' ORDER BY created_at DESC LIMIT 1");
            $st->execute($bd);
            $cn = $st->fetchColumn();
            if ($cn) $draft['name'] = (string)$cn;
          } catch (Throwable $e) {}
        }
        respond(['exists'=>false, 'draft'=>$draft]);
        break;
      }
      case 'ai-extract-order': {
        $phone = trim((string)($req['phone'] ?? ''));
        if ($phone === '') respond(null, ['message'=>'phone required'], 400);
        $cfg = ai_cfg();
        if (!ai_feature_on($cfg, 'feat_order_draft')) respond(null, ['message'=>'خاصية تسجيل الطلب الذكي غير مفعّلة في إعدادات وكيل الذكاء الاصطناعي'], 400);
        $sumWin = max(1, min(720, (int)($cfg['summary_window_hours'] ?? 72) ?: 72));
        $thread = ai_thread_text($phone, 120, $sumWin * 60);
        if ($thread === '') respond(null, ['message'=>'لا توجد رسائل حديثة في هذه المحادثة خلال مدة الجلب المحددة (' . $sumWin . ' ساعة)'], 400);
        $customer = ai_find_customer_by_phone($phone);
        $sys = "أنت مساعد لوكالة دعاية وإعلان. استخرج تفاصيل طلب العميل من محادثة واتساب. أجب JSON فقط بهذه البنية: {\"details\": \"وصف الطلب\", \"delivery_date\": \"YYYY-MM-DD أو فارغ إن لم يُذكر\", \"total_amount\": رقم أو 0, \"items\": [{\"item_name\": \"اسم البند\", \"quantity\": رقم, \"unit_price\": رقم أو 0}], \"notes\": \"ملاحظات\", \"customer_name\": \"اسم العميل إن ظهر\"}. التاريخ اليوم: " . date('Y-m-d');
        $r = ai_llm_call($cfg, $sys, "المحادثة:\n" . $thread, true);
        if (empty($r['ok'])) respond(null, ['message'=>'فشل الاستخراج: ' . (string)$r['error']], 500);
        $j = ai_parse_json($r['text']);
        if (!$j) respond(null, ['message'=>'تعذر فهم استجابة الذكاء الاصطناعي'], 500);
        respond([
          'draft' => [
            'details' => (string)($j['details'] ?? ''),
            'delivery_date' => (string)($j['delivery_date'] ?? ''),
            'total_amount' => (float)($j['total_amount'] ?? 0),
            'items' => is_array($j['items'] ?? null) ? $j['items'] : [],
            'notes' => (string)($j['notes'] ?? ''),
            'customer_name' => (string)($j['customer_name'] ?? ''),
          ],
          'customer' => $customer,
          'phone' => wa_canon_phone($phone),
          'attachments' => ai_thread_media($phone, $sumWin * 60),
        ]);
        break;
      }
      case 'ai-scan-complaints-now': {
        $cfg = ai_cfg();
        if (!ai_feature_on($cfg, 'feat_complaints')) respond(null, ['message'=>'خاصية متابعة الشكاوى غير مفعّلة'], 400);
        // Manual scan always covers at least the last 24h regardless of the configured window.
        $n = ai_scan_complaints($cfg, 1440);
        try { pdo()->prepare("UPDATE ai_agent_settings SET last_complaint_scan = NOW() WHERE id = :id")->execute([':id'=>(string)$cfg['id']]); } catch (Throwable $e) {}
        respond(['found'=>$n]);
        break;
      }
      case 'ai-scan-unregistered-now': {
        $cfg = ai_cfg();
        if (!ai_feature_on($cfg, 'feat_unregistered_alert')) respond(null, ['message'=>'خاصية تنبيه الطلبات غير المسجلة غير مفعّلة'], 400);
        // Manual scan always covers at least the last 24h regardless of the configured window.
        $n = ai_scan_unregistered_orders($cfg, 1440);
        try { pdo()->prepare("UPDATE ai_agent_settings SET last_unreg_run = NOW() WHERE id = :id")->execute([':id'=>(string)$cfg['id']]); } catch (Throwable $e) {}
        respond(['found'=>$n]);
        break;
      }
      case 'ai-scan-reply-delays-now': {
        if (!ai_request_user()) respond(null, ['message'=>'غير مصرح — يلزم تسجيل الدخول'], 401);
        $cfg = ai_cfg();
        if (!$cfg || empty($cfg['reply_delay_enabled']) || (string)$cfg['reply_delay_enabled'] === '0') respond(null, ['message'=>'خاصية تنبيه تأخر الرد غير مفعّلة'], 400);
        $n = ai_scan_reply_delays($cfg);
        try { pdo()->prepare("UPDATE ai_agent_settings SET last_reply_delay_scan = NOW() WHERE id = :id")->execute([':id'=>(string)$cfg['id']]); } catch (Throwable $e) {}
        respond(['sent'=>$n]);
        break;
      }
      case 'ai-complaints-list': {
        ensure_ai_agent_schema();
        try {
          $rows = pdo()->query("SELECT * FROM ai_complaints ORDER BY created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: [];
          respond(['complaints'=>$rows]);
        } catch (Throwable $e) { respond(['complaints'=>[]]); }
        break;
      }
      case 'ai-complaint-update': {
        ensure_ai_agent_schema();
        $id = trim((string)($req['id'] ?? ''));
        if ($id === '') respond(null, ['message'=>'id required'], 400);
        $sets = []; $bind = [':id'=>$id];
        foreach (['status','ai_reply','ai_solution','summary','details'] as $f) {
          if (array_key_exists($f, $req)) { $sets[] = "$f = :$f"; $bind[":$f"] = (string)$req[$f]; }
        }
        if (!$sets) respond(null, ['message'=>'nothing to update'], 400);
        $sets[] = "updated_at = NOW()";
        try {
          pdo()->prepare("UPDATE ai_complaints SET " . implode(', ', $sets) . " WHERE id = :id")->execute($bind);
          respond(['updated'=>true]);
        } catch (Throwable $e) { respond(null, ['message'=>'update_failed: ' . $e->getMessage()], 500); }
        break;
      }
      case 'ai-complaint-send-reply': {
        ensure_ai_agent_schema();
        $id = trim((string)($req['id'] ?? ''));
        if ($id === '') respond(null, ['message'=>'id required'], 400);
        $st = pdo()->prepare("SELECT * FROM ai_complaints WHERE id = :id");
        $st->execute([':id'=>$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) respond(null, ['message'=>'الشكوى غير موجودة'], 404);
        $reply = trim((string)($req['reply'] ?? $c['ai_reply'] ?? ''));
        if ($reply === '') respond(null, ['message'=>'لا يوجد نص للرد'], 400);
        $r = ai_send_whatsapp((string)$c['phone'], $reply);
        if (empty($r['ok'])) respond(null, ['message'=>'فشل إرسال الرد: ' . (string)$r['error']], 500);
        try { pdo()->prepare("UPDATE ai_complaints SET status='replied', ai_reply=:rp, reply_sent_at=NOW(), updated_at=NOW() WHERE id=:id")->execute([':rp'=>$reply, ':id'=>$id]); } catch (Throwable $e) {}
        respond(['sent'=>true]);
        break;
      }
      case 'ai-cron-run': {
        ai_agent_cron();
        respond(['ok'=>true]);
        break;
      }
      case 'wa-send': {
        $to = trim((string)($req['to'] ?? $req['phone'] ?? ''));
        $message = (string)($req['message'] ?? $req['text'] ?? '');
        if ($to === '' || trim($message) === '') respond(null, ['message'=>'to and message are required'], 400);
        $toIntl = to_international_msisdn($to); if ($toIntl === '') $toIntl = preg_replace('/\D+/', '', $to);
        $cfg = get_active_whatsapp_api_settings();
        $result = $cfg ? send_via_whatsapp_api($toIntl, $message, $cfg) : null;
        $ok = (bool)($result['ok'] ?? false);
        $cust = wa_lookup_customer_by_phone($toIntl);
        $id = generate_uuid_v4(); $now = date('Y-m-d H:i:s');
        try { wa_insert_outgoing($id, $toIntl, 'text', $message, '', '', '', $ok ? 'sent' : 'failed', $ok ? '' : (string)($result['error'] ?? 'no_active_api'), ($cust['id'] ?? null), $now); } catch (Throwable $e) { /* ignore */ }
        if ($ok) respond(['sent'=>true, 'id'=>$id]);
        respond(null, ['message'=>'فشل إرسال الرسالة: ' . (string)($result['error'] ?? 'لا يوجد اتصال واتساب مفعل'), 'id'=>$id, 'details'=>$result], 502);
        break;
      }
      case 'wa-send-media': {
        // Accepts multipart ($_FILES['file']) — send ?service=functions&action=wa-send-media in the
        // query string and (to, caption, file) as form fields — or JSON file_base64.
        $to = trim((string)($req['to'] ?? $req['phone'] ?? ''));
        $caption = (string)($req['caption'] ?? $req['message'] ?? '');
        if ($to === '') respond(null, ['message'=>'to is required'], 400);
        $toIntl = to_international_msisdn($to); if ($toIntl === '') $toIntl = preg_replace('/\D+/', '', $to);

        $basename = ''; $mime = ''; $origName = '';
        if (isset($_FILES['file']) && is_array($_FILES['file']) && (int)($_FILES['file']['error'] ?? 1) === 0) {
          $tmp = (string)$_FILES['file']['tmp_name']; $origName = (string)($_FILES['file']['name'] ?? '');
          $size = (int)($_FILES['file']['size'] ?? 0);
          if ($size <= 0 || $size > 64 * 1024 * 1024) respond(null, ['message'=>'ملف غير صالح أو أكبر من 64MB'], 400);
          $mime = (string)($_FILES['file']['type'] ?? '');
          $ext = wa_safe_ext(pathinfo($origName, PATHINFO_EXTENSION));
          if ($ext === 'bin' && $mime !== '') { $mext = wa_ext_from_mime($mime); if ($mext !== 'bin') $ext = $mext; }
          if ($mime === '') $mime = wa_mime_from_ext($ext);
          $basename = 'out_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . '.' . $ext;
          if (!@move_uploaded_file($tmp, wa_uploads_dir() . '/' . $basename) && !@copy($tmp, wa_uploads_dir() . '/' . $basename)) respond(null, ['message'=>'تعذر حفظ الملف على الخادم'], 500);
        } elseif (!empty($req['file_base64'])) {
          $b64 = (string)$req['file_base64']; $origName = (string)($req['filename'] ?? 'file');
          if (strpos($b64, 'data:') === 0 && preg_match('#^data:([^;]+);base64,(.*)$#s', $b64, $dm)) { if ($mime === '') $mime = $dm[1]; $b64 = $dm[2]; }
          $bin = base64_decode($b64, true);
          if ($bin === false || strlen($bin) === 0 || strlen($bin) > 64 * 1024 * 1024) respond(null, ['message'=>'ملف base64 غير صالح'], 400);
          $ext = wa_safe_ext(pathinfo($origName, PATHINFO_EXTENSION));
          if ($ext === 'bin' && $mime !== '') { $mext = wa_ext_from_mime($mime); if ($mext !== 'bin') $ext = $mext; }
          if ($mime === '') $mime = wa_mime_from_ext($ext);
          $basename = 'out_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . '.' . $ext;
          if (@file_put_contents(wa_uploads_dir() . '/' . $basename, $bin) === false) respond(null, ['message'=>'تعذر حفظ الملف'], 500);
        } else {
          respond(null, ['message'=>'لم يتم إرفاق ملف'], 400);
        }

        $type = 'document';
        if (strpos($mime, 'image/') === 0) $type = 'image';
        elseif (strpos($mime, 'video/') === 0) $type = 'video';
        elseif (strpos($mime, 'audio/') === 0) $type = 'audio';

        $localPath = wa_uploads_dir() . '/' . $basename;
        $cfg = get_active_whatsapp_api_settings();
        $result = $cfg ? send_media_via_whatsapp_api($toIntl, $localPath, $type, $caption, $mime, ($origName ?: $basename), $cfg) : null;
        $ok = (bool)($result['ok'] ?? false);
        $cust = wa_lookup_customer_by_phone($toIntl);
        $id = generate_uuid_v4(); $now = date('Y-m-d H:i:s');
        try { wa_insert_outgoing($id, $toIntl, $type, $caption, 'uploads/whatsapp/' . $basename, $mime, ($origName ?: $basename), $ok ? 'sent' : 'failed', $ok ? '' : (string)($result['error'] ?? 'no_active_api'), ($cust['id'] ?? null), $now); } catch (Throwable $e) { /* ignore */ }
        $viewUrl = 'api/index.php?service=wa-media&f=' . rawurlencode($basename);
        if ($ok) respond(['sent'=>true, 'id'=>$id, 'media_view_url'=>$viewUrl, 'type'=>$type]);
        respond(null, ['message'=>'تعذر إرسال الوسائط عبر WaSender: ' . (string)($result['error'] ?? 'لا يوجد اتصال مفعل'), 'id'=>$id, 'media_view_url'=>$viewUrl, 'details'=>$result], 502);
        break;
      }
      case 'wa-diagnose-inbound': {
        // LIVE inbound diagnostic: webhook URL, connection state, counts, and the most recent RAW
        // webhook payloads (phone digit-runs masked) so the exact WaSender shape can be tuned.
        $pdo = pdo();
        $mask = function($s) { $s = (string)$s; $n = strlen($s); if ($n === 0) return '(empty)'; if ($n <= 4) return str_repeat('*', $n); return substr($s, 0, 2) . str_repeat('*', max(0, $n - 4)) . substr($s, -2); };
        $cfg = wa_get_or_create_inbound_secret();
        $report = [
          'code_version' => 'ai-agent-13',
          'server_time' => date('Y-m-d H:i:s'),
          'webhook_url' => wa_public_base_url() . '/api/index.php?service=wa-webhook&secret=' . rawurlencode((string)($cfg['inbound_secret'] ?? '')),
          'secret_masked' => $mask($cfg['inbound_secret'] ?? ''),
          'api_connected' => (bool)get_active_whatsapp_api_settings(),
        ];
        try {
          $report['counts'] = [
            'incoming' => (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_messages WHERE direction='incoming'")->fetchColumn(),
            'outgoing' => (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_messages WHERE direction='outgoing'")->fetchColumn(),
            'inbound_events_logged' => (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_inbound_log")->fetchColumn(),
          ];
        } catch (Throwable $e) { /* ignore */ }
        $n = (int)($req['limit'] ?? 10); if ($n <= 0 || $n > 50) $n = 10;
        try {
          $st = $pdo->prepare("SELECT received_at, remote_ip, secret_ok, event_type, stored_count, parsed_summary, raw_body FROM whatsapp_inbound_log ORDER BY received_at DESC LIMIT :l");
          $st->bindValue(':l', $n, PDO::PARAM_INT);
          $st->execute();
          $items = [];
          while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $rawMasked = preg_replace_callback('/\d{7,}/', function($mm) { $s = $mm[0]; return substr($s, 0, 3) . str_repeat('*', max(0, strlen($s) - 5)) . substr($s, -2); }, (string)$r['raw_body']);
            $items[] = [
              'received_at' => $r['received_at'], 'remote_ip' => $r['remote_ip'], 'secret_ok' => (int)$r['secret_ok'],
              'event_type' => $r['event_type'], 'stored_count' => (int)$r['stored_count'],
              'parsed_summary' => json_decode((string)$r['parsed_summary'], true),
              'raw_masked' => mb_substr((string)$rawMasked, 0, 4000),
            ];
          }
          $report['recent_inbound'] = $items;
        } catch (Throwable $e) { $report['recent_inbound_error'] = $e->getMessage(); }
        respond($report);
        break;
      }
      case 'wa-pull-config': {
        // Save WaSender DB-pull credentials. Accepts GET or POST params (GET passes the WAF, and
        // this endpoint is also reachable from the app UI which POSTs from the user's browser).
        // SECURITY: requires the inbound shared secret (same one used by the webhook URL).
        wa_pull_require_secret();
        $cfgRow = wa_get_or_create_inbound_secret();
        $pdo = pdo();
        $fields = [];
        $vals = [':id' => (string)$cfgRow['id']];
        $map = [
          'db_host' => 'pull_db_host', 'db_name' => 'pull_db_name',
          'db_user' => 'pull_db_user', 'db_pass' => 'pull_db_pass', 'table' => 'pull_table',
          'session_id' => 'pull_session_id',
        ];
        foreach ($map as $in => $col) {
          $v = $req[$in] ?? ($_GET[$in] ?? null);
          if ($v !== null) { $fields[] = "`$col` = :$col"; $vals[":$col"] = trim((string)$v); }
        }
        $en = $req['enabled'] ?? ($_GET['enabled'] ?? null);
        if ($en !== null) { $fields[] = "`pull_enabled` = :en"; $vals[':en'] = in_array(strtolower((string)$en), ['1','true','yes','on'], true) ? 1 : 0; }
        $reset = $req['reset_checkpoint'] ?? ($_GET['reset_checkpoint'] ?? null);
        if ($reset !== null && in_array(strtolower((string)$reset), ['1','true','yes','on'], true)) { $fields[] = "`pull_checkpoint` = NULL"; }
        // rotate_secret=1: generate a fresh inbound secret (invalidates the old one, which may
        // have been exposed while sensitive tables were publicly readable). Knowing the CURRENT
        // secret authorizes the rotation (wa_pull_require_secret already ran above).
        $newSecret = null;
        $rot = $req['rotate_secret'] ?? ($_GET['rotate_secret'] ?? null);
        if ($rot !== null && in_array(strtolower((string)$rot), ['1','true','yes','on'], true)) {
          $newSecret = bin2hex(random_bytes(16));
          $fields[] = "`inbound_secret` = :nsec";
          $vals[':nsec'] = $newSecret;
        }
        if (empty($fields)) respond(null, ['message'=>'no fields provided'], 400);
        $fields[] = "`updated_at` = NOW()";
        try {
          $pdo->prepare("UPDATE whatsapp_inbound_settings SET " . implode(', ', $fields) . " WHERE id = :id")->execute($vals);
        } catch (Throwable $e) { respond(null, ['message'=>'save_failed: ' . $e->getMessage()], 500); break; }
        respond($newSecret !== null ? ['saved' => true, 'new_secret' => $newSecret] : ['saved' => true]);
        break;
      }
      case 'wa-pull-diagnose': {
        // Introspect the configured WaSender DB: connection status, detected messages table,
        // its columns, and a few sanitized sample rows — so the field mapping can be verified live.
        // SECURITY: requires the inbound shared secret (exposes remote schema + sampled data).
        wa_pull_require_secret();
        $cfg = wa_pull_cfg();
        $mask = function($s) { $s = (string)$s; $n = strlen($s); if ($n === 0) return '(empty)'; if ($n <= 4) return str_repeat('*', $n); return substr($s, 0, 2) . str_repeat('*', max(0, $n - 4)) . substr($s, -2); };
        $report = [
          'code_version' => 'ai-agent-13',
          'server_time' => date('Y-m-d H:i:s'),
          'configured' => [
            'db_host' => (string)($cfg['pull_db_host'] ?? '') ?: 'localhost',
            'db_name' => (string)($cfg['pull_db_name'] ?? ''),
            'db_user' => (string)($cfg['pull_db_user'] ?? ''),
            'db_pass_masked' => $mask($cfg['pull_db_pass'] ?? ''),
            'table' => (string)($cfg['pull_table'] ?? ''),
            'session_id' => (string)($cfg['pull_session_id'] ?? ''),
            'enabled' => !empty($cfg['pull_enabled']) && (string)$cfg['pull_enabled'] !== '0',
            'checkpoint' => (string)($cfg['pull_checkpoint'] ?? ''),
            'last_run' => (string)($cfg['pull_last_run'] ?? ''),
            'last_result' => json_decode((string)($cfg['pull_last_result'] ?? ''), true),
          ],
        ];
        try {
          $xpdo = wa_pull_pdo($cfg);
          $report['connection'] = 'ok';
          $tables = [];
          foreach ($xpdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM) as $r) $tables[] = (string)$r[0];
          $report['tables'] = $tables;
          $table = wa_pull_find_table($xpdo, $cfg);
          $report['detected_table'] = $table;
          if ($table !== '') {
            $tableQ = wa_sql_ident($table);
            $report['columns'] = $xpdo->query("SHOW COLUMNS FROM `$tableQ`")->fetchAll(PDO::FETCH_ASSOC);
            // Media sample: latest rows that carry a media_url, so the path format is visible live.
            try {
              $mediaRows = [];
              foreach ($xpdo->query("SELECT * FROM `$tableQ` WHERE media_url IS NOT NULL AND media_url <> '' ORDER BY 1 DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                $mediaRows[] = ['id'=>$mr['id'] ?? null, 'type'=>$mr['type'] ?? null, 'direction'=>$mr['direction'] ?? null, 'media_url'=>$mr['media_url'] ?? null];
              }
              $report['media_sample'] = $mediaRows;
              $report['media_resolution_candidates'] = !empty($mediaRows[0]['media_url']) ? wa_media_candidates((string)$mediaRows[0]['media_url']) : [];
            } catch (Throwable $e) { $report['media_sample_error'] = $e->getMessage(); }
            $report['row_count'] = (int)$xpdo->query("SELECT COUNT(*) FROM `$tableQ`")->fetchColumn();
            $samples = [];
            foreach ($xpdo->query("SELECT * FROM `$tableQ` ORDER BY 1 DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC) as $row) {
              $srow = [];
              foreach ($row as $k => $v) {
                $s = (string)$v;
                $s = preg_replace_callback('/\d{7,}/', function($mm) { $x = $mm[0]; return substr($x, 0, 3) . str_repeat('*', max(0, strlen($x) - 5)) . substr($x, -2); }, $s);
                $srow[$k] = mb_substr($s, 0, 500);
              }
              $samples[] = $srow;
            }
            $report['sample_rows_masked'] = $samples;
          }
        } catch (Throwable $e) {
          $report['connection'] = 'failed';
          $report['connection_error'] = $e->getMessage();
        }
        respond($report);
        break;
      }
      case 'wa-pull-sessions': {
        // List the WhatsApp sessions present in the WaSender DB so the customer-service one can
        // be picked (pull_session_id). Annotates which session receives the follow-up team's
        // number so the "wrong" session is obvious. GET-safe (WAF), secret-gated.
        wa_pull_require_secret();
        $cfg = wa_pull_cfg();
        $out = ['code_version' => 'ai-agent-13', 'configured_session_id' => (string)($cfg['pull_session_id'] ?? '')];
        // Follow-up team's own WhatsApp number (from follow-up settings) for annotation
        $fuIntl = '';
        try {
          $fu = pdo()->query("SELECT whatsapp_number FROM follow_up_settings ORDER BY updated_at DESC LIMIT 1")->fetchColumn();
          $fuIntl = to_international_msisdn((string)$fu);
        } catch (Throwable $e) { /* ignore */ }
        try {
          $xpdo = wa_pull_pdo($cfg);
          $table = wa_pull_find_table($xpdo, $cfg);
          if ($table === '') respond(null, ['message'=>'messages_table_not_found'], 500);
          $tableQ = wa_sql_ident($table);
          $cols = array_map('strtolower', array_column($xpdo->query("SHOW COLUMNS FROM `$tableQ`")->fetchAll(PDO::FETCH_ASSOC), 'Field'));
          if (!in_array('session_id', $cols, true)) respond(['sessions'=>[], 'note'=>'no session_id column — single-session table']);
          $sessions = [];
          $q = $xpdo->query("SELECT session_id, COUNT(*) c, MIN(created_at) first_msg, MAX(created_at) last_msg FROM `$tableQ` GROUP BY session_id ORDER BY MIN(id) ASC");
          foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $sid = (string)$s['session_id'];
            $entry = [
              'session_id' => $sid, 'messages' => (int)$s['c'],
              'first_message' => (string)$s['first_msg'], 'last_message' => (string)$s['last_msg'],
              'is_configured' => ($sid !== '' && $sid === (string)($cfg['pull_session_id'] ?? '')),
            ];
            try {
              $st = $xpdo->prepare("SELECT `to`, COUNT(*) c FROM `$tableQ` WHERE session_id = :s AND direction = 'incoming' AND `to` NOT LIKE '%broadcast%' GROUP BY `to` ORDER BY c DESC LIMIT 3");
              $st->execute([':s'=>$sid]);
              $tos = [];
              $isFu = false;
              foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $num = preg_replace('/\D+/', '', (string)$t['to']);
                if ($fuIntl !== '' && $num === $fuIntl) $isFu = true;
                $tos[] = (strlen($num) > 5 ? substr($num, 0, 5) . str_repeat('*', strlen($num) - 7) . substr($num, -2) : $num) . ' (' . (int)$t['c'] . ')';
              }
              $entry['receives_at_numbers_masked'] = $tos;
              $entry['is_follow_up_session'] = $isFu;
            } catch (Throwable $e) { /* ignore */ }
            try {
              $st = $xpdo->prepare("SELECT push_name, COUNT(*) c FROM `$tableQ` WHERE session_id = :s AND direction='incoming' AND push_name IS NOT NULL AND push_name <> '' GROUP BY push_name ORDER BY c DESC LIMIT 5");
              $st->execute([':s'=>$sid]);
              $entry['top_contacts'] = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'push_name');
            } catch (Throwable $e) { /* ignore */ }
            $sessions[] = $entry;
          }
          $out['sessions'] = $sessions;
        } catch (Throwable $e) {
          respond(null, ['message'=>'sessions_failed: ' . $e->getMessage()], 500);
        }
        respond($out);
        break;
      }
      case 'wa-pull-peek': {
        // Show the most recent RAW rows of the WaSender messages table (from/to/session/direction)
        // so session ownership can be determined empirically instead of guessed from aggregates.
        // Optional: session=<sid> to filter, limit=<n> (max 50). GET-safe (WAF), secret-gated.
        wa_pull_require_secret();
        $cfg = wa_pull_cfg();
        $limit = max(1, min(50, (int)($req['limit'] ?? ($_GET['limit'] ?? 20))));
        $onlySid = trim((string)($req['session'] ?? ($_GET['session'] ?? '')));
        try {
          $xpdo = wa_pull_pdo($cfg);
          $table = wa_pull_find_table($xpdo, $cfg);
          if ($table === '') respond(null, ['message'=>'messages_table_not_found'], 500);
          $tableQ = wa_sql_ident($table);
          $whereSql = $onlySid !== '' ? "WHERE session_id = :s" : "";
          $st = $xpdo->prepare("SELECT * FROM `$tableQ` $whereSql ORDER BY id DESC LIMIT $limit");
          $st->execute($onlySid !== '' ? [':s'=>$onlySid] : []);
          $rows = [];
          foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = [];
            foreach ($r as $k => $v) {
              $kL = strtolower((string)$k);
              if (in_array($kL, ['content','body','text','message','caption'], true)) { $row[$k] = mb_substr((string)$v, 0, 60); continue; }
              if (in_array($kL, ['media_url','mediaurl'], true)) { $row[$k] = $v !== null && $v !== '' ? '(set)' : ''; continue; }
              $row[$k] = $v;
            }
            $rows[] = $row;
          }
          respond(['code_version'=>'ai-agent-13', 'table'=>$table, 'rows'=>$rows]);
        } catch (Throwable $e) {
          respond(null, ['message'=>'peek_failed: ' . $e->getMessage()], 500);
        }
        break;
      }
      case 'wa-pull-cleanup-session': {
        // Delete inbox messages that were wrongly imported from OTHER WaSender sessions (e.g. the
        // follow-up team's personal chats). Requires pull_session_id to be configured first.
        // dry_run=1 only counts. GET-safe (WAF), secret-gated.
        wa_pull_require_secret();
        $cfg = wa_pull_cfg();
        $keepSid = trim((string)($cfg['pull_session_id'] ?? ''));
        if ($keepSid === '') respond(null, ['message'=>'set pull_session_id first (wa-pull-config&session_id=...)'], 400);
        $dryRun = in_array(strtolower((string)($req['dry_run'] ?? ($_GET['dry_run'] ?? ''))), ['1','true','yes','on'], true);
        // Optional: target ONE specific foreign session (safer — old legit sessions keep their
        // history). Without it, ALL non-kept sessions are purged.
        $onlySid = trim((string)($req['session'] ?? ($_GET['session'] ?? '')));
        if ($onlySid !== '' && $onlySid === $keepSid) respond(null, ['message'=>'session equals the kept pull_session_id'], 400);
        // Optional: outgoing_self=1 — purge inbox rows that came from the KEPT session's own
        // OUTGOING phone messages (ingested as fake "incoming" before the direction mapping fix).
        $selfOut = in_array(strtolower((string)($req['outgoing_self'] ?? ($_GET['outgoing_self'] ?? ''))), ['1','true','yes','on'], true);
        try {
          $xpdo = wa_pull_pdo($cfg);
          $table = wa_pull_find_table($xpdo, $cfg);
          if ($table === '') respond(null, ['message'=>'messages_table_not_found'], 500);
          $tableQ = wa_sql_ident($table);
          $pdo = pdo();
          $foreignRows = 0; $matched = 0; $deleted = 0; $lastId = 0;
          if ($selfOut) {
            $where = "session_id = :s AND LOWER(direction) IN ('outgoing','out','outbound','sent','send')";
            $whereSid = $keepSid;
          } else {
            $where = $onlySid !== '' ? "session_id = :s" : "session_id <> :s";
            $whereSid = $onlySid !== '' ? $onlySid : $keepSid;
          }
          while (true) {
            $st = $xpdo->prepare("SELECT id, wa_message_id FROM `$tableQ` WHERE $where AND id > :last ORDER BY id ASC LIMIT 500");
            $st->execute([':s'=>$whereSid, ':last'=>$lastId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) break;
            $keys = [];
            foreach ($rows as $r) {
              $lastId = (int)$r['id'];
              $foreignRows++;
              $wid = trim((string)($r['wa_message_id'] ?? ''));
              if ($wid !== '') $keys[] = $wid;
              $keys[] = 'wspull:' . $table . ':' . (string)$r['id'];
            }
            if (empty($keys)) continue;
            foreach (array_chunk($keys, 400) as $chunk) {
              $in = implode(',', array_fill(0, count($chunk), '?'));
              if ($dryRun) {
                $c = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages WHERE direction = 'incoming' AND provider_message_id IN ($in)");
                $c->execute($chunk);
                $matched += (int)$c->fetchColumn();
              } else {
                $d = $pdo->prepare("DELETE FROM whatsapp_messages WHERE direction = 'incoming' AND provider_message_id IN ($in)");
                $d->execute($chunk);
                $deleted += $d->rowCount();
              }
            }
          }
          respond([
            'ok' => true, 'dry_run' => $dryRun, 'kept_session' => $keepSid,
            'purged_scope' => $selfOut ? 'kept-session-outgoing-self' : ($onlySid !== '' ? $onlySid : 'all-foreign-sessions'),
            'foreign_rows_in_wasender' => $foreignRows,
            'inbox_matches' => $dryRun ? $matched : null,
            'inbox_deleted' => $dryRun ? null : $deleted,
          ]);
        } catch (Throwable $e) {
          respond(null, ['message'=>'cleanup_failed: ' . $e->getMessage()], 500);
        }
        break;
      }
      case 'wa-media-probe': {
        // Locate WaSender's media storage on this same server: filesystem scan + localhost
        // port scan + settings hints. GET-safe (WAF), secret-gated.
        wa_pull_require_secret();
        $out = ['code_version' => 'ai-agent-13'];
        $cfg = wa_pull_cfg();
        $mediaId = trim((string)($req['id'] ?? ($_GET['id'] ?? '')));
        $mediaUrl = '';
        try {
          $xpdo = wa_pull_pdo($cfg);
          $table = wa_pull_find_table($xpdo, $cfg);
          if ($table !== '') {
            $tq = wa_sql_ident($table);
            $mr = $xpdo->query("SELECT media_url FROM `$tq` WHERE media_url IS NOT NULL AND media_url <> '' ORDER BY 1 DESC LIMIT 1")->fetchColumn();
            $mediaUrl = (string)$mr;
            if ($mediaId === '' && $mediaUrl !== '') $mediaId = basename(parse_url($mediaUrl, PHP_URL_PATH) ?: $mediaUrl);
          }
          // Hints from WaSender's own settings/session tables (masked values)
          try {
            $hints = [];
            foreach ($xpdo->query("SELECT * FROM `settings` LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) as $sr) {
              $rowh = [];
              foreach ($sr as $k => $v) { $s = (string)$v; $rowh[$k] = strlen($s) > 60 ? substr($s, 0, 30) . '…(' . strlen($s) . ')' : $s; }
              $hints[] = $rowh;
            }
            $out['wasender_settings'] = $hints;
          } catch (Throwable $e) { $out['wasender_settings_error'] = $e->getMessage(); }
        } catch (Throwable $e) { $out['wasender_db_error'] = $e->getMessage(); }
        $out['media_id'] = $mediaId;
        $out['media_url_sample'] = $mediaUrl;
        // ---- Filesystem scan (same cPanel account) ----
        $docroot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $home = (string)(getenv('HOME') ?: ($docroot !== '' ? dirname($docroot) : ''));
        $roots = array_values(array_unique(array_filter([$home, $docroot, $docroot !== '' ? dirname($docroot) : ''])));
        $out['roots'] = $roots;
        $fs = ['root_listings' => [], 'wasender_dirs' => [], 'media_hits' => []];
        foreach ($roots as $root) {
          $ls = @scandir($root) ?: [];
          $fs['root_listings'][$root] = array_values(array_slice(array_diff($ls, ['.','..']), 0, 60));
          foreach (@glob($root . '/*wasender*', GLOB_ONLYDIR) ?: [] as $wd) {
            $fs['wasender_dirs'][] = $wd;
            $sub = @scandir($wd) ?: [];
            $fs['root_listings'][$wd] = array_values(array_slice(array_diff($sub, ['.','..']), 0, 60));
            foreach (['media','uploads','storage','files','public/media','public/uploads','src/media','dist/media','sessions','data','data/media','data/uploads'] as $mdir) {
              $d = $wd . '/' . $mdir;
              if (!@is_dir($d)) continue;
              $fs['root_listings'][$d] = array_values(array_slice(array_diff(@scandir($d) ?: [], ['.','..']), 0, 40));
              if ($mediaId !== '') { foreach (array_merge(@glob($d . '/*' . $mediaId . '*') ?: [], @glob($d . '/*/*' . $mediaId . '*') ?: []) as $hit) $fs['media_hits'][] = $hit; }
            }
          }
          // media-id hit anywhere one level deep under root (cheap)
          if ($mediaId !== '') { foreach (@glob($root . '/*/*' . $mediaId . '*') ?: [] as $hit) $fs['media_hits'][] = $hit; }
        }
        $fs['media_hits'] = array_values(array_unique($fs['media_hits']));
        $out['fs'] = $fs;
        // ---- Localhost port scan for the WaSender node app ----
        $ports = [3000, 3001, 3333, 4000, 5000, 7000, 8000, 8080, 8443, 9000];
        $apiKey = '';
        try { $s = get_active_whatsapp_api_settings(); $apiKey = (string)($s['api_key'] ?? ''); } catch (Throwable $e) { /* ignore */ }
        $rel = $mediaUrl !== '' ? '/' . ltrim($mediaUrl, '/') : ('/api/media/' . $mediaId);
        $probe = [];
        foreach ($ports as $p) {
          $fp = @fsockopen('127.0.0.1', $p, $errno, $errstr, 1);
          if (!$fp) continue;
          fclose($fp);
          $entry = ['port' => $p, 'open' => true];
          if (function_exists('curl_init')) {
            $ch = curl_init('http://127.0.0.1:' . $p . $rel);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>4, CURLOPT_NOBODY=>false, CURLOPT_RANGE=>'0-256', CURLOPT_HTTPHEADER=>array_values(array_filter(['x-api-key: ' . $apiKey, 'Authorization: ' . $apiKey]))]);
            $body = (string)curl_exec($ch);
            $entry['http_code'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $entry['content_type'] = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $entry['body_head'] = substr($body, 0, 120);
            curl_close($ch);
          }
          $probe[] = $entry;
        }
        $out['localhost_probe'] = $probe;
        respond($out);
        break;
      }
      case 'wa-pull-now': {
        // Force an immediate pull (GET-safe). &reset=1 clears the checkpoint first (full re-scan).
        // SECURITY: requires the inbound shared secret.
        wa_pull_require_secret();
        $reset = $req['reset'] ?? ($_GET['reset'] ?? null);
        if ($reset !== null && in_array(strtolower((string)$reset), ['1','true','yes','on'], true)) {
          $cfg = wa_pull_cfg();
          if ($cfg) { try { pdo()->prepare("UPDATE whatsapp_inbound_settings SET pull_checkpoint = NULL WHERE id = :id")->execute([':id'=>(string)$cfg['id']]); } catch (Throwable $e) { /* ignore */ } }
        }
        respond(wa_pull_run(true));
        break;
      }
      case 'diagnose-whatsapp': {
        // Self-contained LIVE diagnostic. Open directly in a browser on the live server:
        //   /api/index.php?service=functions&action=diagnose-whatsapp
        // Add &to=9665XXXXXXXX to ALSO send a real end-to-end test via the fallback provider.
        // Read-only except the optional &to test send. Tokens are masked in the output.
        $pdo = pdo();
        ensure_hr_finance_schema();
        ensure_whatsapp_schema();
        $mask = function($s) {
          $s = (string)$s; $n = strlen($s);
          if ($n === 0) return '(empty)';
          if ($n <= 4) return str_repeat('*', $n);
          return substr($s, 0, 2) . str_repeat('*', max(0, $n - 4)) . substr($s, -2);
        };
        $report = [
          'code_version' => 'whatsapp-diagnostic-2026-07-04c-queue-priority',
          'server_time' => date('Y-m-d H:i:s'),
        ];

        // 1) Fallback (ultramsg) settings as stored in the LIVE database
        $fbRow = null;
        try { $st = $pdo->query("SELECT * FROM whatsapp_fallback_settings ORDER BY updated_at DESC, created_at DESC LIMIT 1"); $fbRow = $st->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
        $fbActive = get_active_whatsapp_fallback_settings();
        $instanceRaw = (string)($fbRow['instance_id'] ?? '');
        $instanceNorm = trim($instanceRaw);
        if ($instanceNorm !== '' && ctype_digit($instanceNorm)) $instanceNorm = 'instance' . $instanceNorm;
        $report['fallback_ultramsg'] = [
          'row_exists' => (bool)$fbRow,
          'is_active_raw' => $fbRow['is_active'] ?? null,
          'is_active_effective' => (bool)$fbActive,
          'instance_id_raw' => $instanceRaw,
          'instance_id_normalized' => $instanceNorm,
          'instance_id_was_corrected' => ($instanceRaw !== '' && $instanceNorm !== $instanceRaw),
          'token_present' => !empty($fbRow['token']),
          'token_masked' => $mask($fbRow['token'] ?? ''),
          'api_url' => $fbRow['api_url'] ?? null,
        ];

        // 2) Live ultramsg instance status (proves creds + WhatsApp session state)
        if ($fbRow && !empty($fbRow['token']) && $instanceNorm !== '') {
          $base = ultramsg_base_url($fbRow['api_url'] ?? '', $instanceNorm);
          $statusUrl = $base . '/' . $instanceNorm . '/instance/status?token=' . rawurlencode((string)$fbRow['token']);
          $report['fallback_ultramsg']['computed_send_url'] = $base . '/' . $instanceNorm . '/messages/chat';
          $sResp = ''; $sCode = 0; $sErr = '';
          try {
            $ch = curl_init($statusUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
            $sResp = curl_exec($ch); $sCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $en = curl_errno($ch);
            if ($en !== 0) $sErr = 'curl(' . $en . '): ' . curl_error($ch);
            curl_close($ch);
          } catch (Throwable $e) { $sErr = 'exception: ' . $e->getMessage(); }
          $report['ultramsg_live_status'] = ['http_code' => $sCode, 'error' => $sErr, 'response' => is_string($sResp) ? $sResp : ''];
        } else {
          $report['ultramsg_live_status'] = ['skipped' => 'no token/instance stored'];
        }

        // 3) WaSender (primary) settings
        $waRow = null;
        try { $st = $pdo->query("SELECT * FROM whatsapp_api_settings ORDER BY updated_at DESC, created_at DESC LIMIT 1"); $waRow = $st->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
        $waActive = get_active_whatsapp_api_settings();
        $report['wasender_primary'] = [
          'row_exists' => (bool)$waRow,
          'is_active_raw' => $waRow['is_active'] ?? null,
          'is_active_effective' => (bool)$waActive,
          'api_url' => $waRow['api_url'] ?? null,
          'app_key_present' => !empty($waRow['app_key']),
        ];

        // 4) Follow-up number (alert recipient + default test destination)
        $fuNum = ''; $fuNotify = null;
        try { $st = $pdo->query("SELECT whatsapp_number, notify_whatsapp_failure FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC LIMIT 1"); $r = $st->fetch(PDO::FETCH_ASSOC) ?: []; $fuNum = (string)($r['whatsapp_number'] ?? ''); $fuNotify = $r['notify_whatsapp_failure'] ?? null; } catch (Throwable $e) {}
        $report['followup_number'] = [
          'present' => ($fuNum !== ''),
          'raw_masked' => $mask($fuNum),
          'normalized_masked' => $mask(to_international_msisdn($fuNum)),
          'was_local_format' => (to_international_msisdn($fuNum) !== ltrim($fuNum, '+') && to_international_msisdn($fuNum) !== preg_replace('/\D+/', '', $fuNum)),
          'notify_whatsapp_failure' => $fuNotify,
        ];

        // 4b) Failure-alert (staff notification) state — shows whether the 30-minute debounce is
        // currently suppressing alerts, so "no alert arrived" can be explained at a glance.
        $alertState = ['debounce_minutes' => 30];
        try {
          $stA = $pdo->query("SELECT last_alert_sent_at, TIMESTAMPDIFF(MINUTE, last_alert_sent_at, NOW()) AS mins FROM whatsapp_fallback_settings WHERE (is_active = 1 OR is_active = '1' OR LOWER(is_active) IN ('true','t','yes','on')) ORDER BY updated_at DESC, created_at DESC LIMIT 1");
          $ra = $stA->fetch(PDO::FETCH_ASSOC) ?: [];
          $mins = ($ra && $ra['mins'] !== null) ? (int)$ra['mins'] : null;
          $alertState['last_alert_sent_at'] = $ra['last_alert_sent_at'] ?? null;
          $alertState['minutes_since_last_alert'] = $mins;
          $alertState['currently_throttled'] = ($mins !== null && $mins >= 0 && $mins < 30);
        } catch (Throwable $e) {}
        $report['failure_alert'] = $alertState;

        // 5) Queue + retry columns (proves the retry schema is deployed)
        $report['queue'] = [
          'retry_count_column_exists' => table_has_column('whatsapp_messages', 'retry_count'),
          'next_retry_at_column_exists' => table_has_column('whatsapp_messages', 'next_retry_at'),
        ];
        try { $report['queue']['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_messages WHERE (status IS NULL OR status = '' OR status = 'pending')")->fetchColumn(); } catch (Throwable $e) {}
        try { $report['queue']['failed'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_messages WHERE status = 'failed'")->fetchColumn(); } catch (Throwable $e) {}
        try { $report['queue']['sent'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_messages WHERE status = 'sent'")->fetchColumn(); } catch (Throwable $e) {}
        // How many 'failed' rows are still eligible for automatic retry. After the one-time backlog
        // neutralization this should be small (only recent failures) — a huge number here means the
        // old backlog is still competing with fresh customer messages.
        try { $report['queue']['retry_backlog_due'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_messages WHERE status = 'failed' AND COALESCE(retry_count,0) < 5 AND (next_retry_at IS NULL OR next_retry_at <= NOW())")->fetchColumn(); } catch (Throwable $e) {}
        // Sample the most recent failures so we can see WHY messages are failing (error text + type).
        // No phone numbers are included here — error_message values are technical strings like
        // "ultramsg_not_sent", "http_0", "wasender_api_failed", "Path not found", etc.
        try {
          $hasErr = table_has_column('whatsapp_messages', 'error_message');
          if ($hasErr) {
            $fr = [];
            $stf = $pdo->query("SELECT message_type, error_message, COALESCE(retry_count,0) AS retry_count FROM whatsapp_messages WHERE status = 'failed' ORDER BY updated_at DESC, created_at DESC LIMIT 5");
            while ($rf = $stf->fetch(PDO::FETCH_ASSOC)) {
              $fr[] = [
                'message_type' => $rf['message_type'] ?? '',
                'error' => mb_substr((string)($rf['error_message'] ?? ''), 0, 200),
                'retry_count' => (int)$rf['retry_count'],
              ];
            }
            $report['queue']['recent_failures'] = $fr;
          }
        } catch (Throwable $e) {}

        // 6) Optional real end-to-end test send
        $testTo = trim((string)($req['to'] ?? ''));
        if ($testTo !== '') {
          if (!$fbActive) {
            $report['test_send'] = ['attempted' => false, 'reason' => 'fallback not active/configured'];
          } else {
            $msg = "✅ اختبار تشخيصي من النظام\nالوقت: " . date('Y-m-d H:i:s');
            $res = send_via_fallback_whatsapp($testTo, $msg, $fbActive);
            $report['test_send'] = ['attempted' => true, 'to_sent_as' => to_international_msisdn($testTo), 'ok' => (bool)($res['ok'] ?? false), 'error' => $res['error'] ?? '', 'response' => $res['resp'] ?? ''];
          }
        } else {
          $report['test_send'] = ['attempted' => false, 'hint' => 'add &to=9665XXXXXXXX to the URL to send a real test'];
        }

        respond($report);
        break;
      }

      // ==================== SaaS billing / subscription endpoints ====================
      case 'billing-plans': {
        // Public: powers the pricing page before signup. No tenant data, no auth needed.
        try {
          $rows = pdo()->query("SELECT id, name, price_monthly, currency, max_users, max_orders_per_month, features FROM subscription_plans WHERE is_active = 1 ORDER BY price_monthly ASC")->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as &$r) { $r['features'] = json_decode((string)($r['features'] ?? '{}'), true) ?: []; }
          unset($r);
          respond(['plans' => $rows]);
        } catch (Throwable $e) {
          respond(null, ['message' => 'تعذر تحميل الباقات'], 500);
        }
        break;
      }

      case 'billing-status': {
        // The logged-in tenant's own subscription snapshot (for a "billing" tab in Settings).
        $tid = tenant_current_id();
        if (!$tid) respond(null, ['message' => 'no_tenant'], 403);
        try {
          $pdo = pdo();
          $st = $pdo->prepare("SELECT t.status, t.trial_ends_at, t.plan_id, p.name AS plan_name, p.price_monthly, p.currency
                                FROM tenants t LEFT JOIN subscription_plans p ON p.id = t.plan_id WHERE t.id = :id LIMIT 1");
          $st->execute([':id' => $tid]);
          $tenant = $st->fetch(PDO::FETCH_ASSOC) ?: null;
          $st2 = $pdo->prepare("SELECT status, current_period_end, payment_gateway FROM tenant_subscriptions WHERE tenant_id = :id ORDER BY created_at DESC LIMIT 1");
          $st2->execute([':id' => $tid]);
          $sub = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
          respond(['tenant' => $tenant, 'subscription' => $sub]);
        } catch (Throwable $e) {
          respond(null, ['message' => 'تعذر تحميل حالة الاشتراك'], 500);
        }
        break;
      }

      case 'billing-create-payment': {
        // Starts a checkout: records a pending tenant_subscriptions row and returns everything
        // the frontend needs to render the Moyasar payment form (publishable key is safe to
        // expose client-side — it can only be used to CREATE a charge, never to read/refund one).
        $tid = tenant_current_id();
        if (!$tid) respond(null, ['message' => 'no_tenant'], 403);
        $planId = trim((string)($req['plan_id'] ?? ''));
        if ($planId === '') respond(null, ['message' => 'plan_id مطلوب'], 400);
        try {
          $pdo = pdo();
          $st = $pdo->prepare("SELECT * FROM subscription_plans WHERE id = :id AND is_active = 1 LIMIT 1");
          $st->execute([':id' => $planId]);
          $plan = $st->fetch(PDO::FETCH_ASSOC);
          if (!$plan) respond(null, ['message' => 'باقة غير صالحة'], 404);

          $subId = generate_uuid_v4();
          $pdo->prepare("INSERT INTO tenant_subscriptions (id, tenant_id, plan_id, status, payment_gateway, created_at) VALUES (:id, :tid, :pid, 'trialing', 'moyasar', NOW())")
              ->execute([':id' => $subId, ':tid' => $tid, ':pid' => $planId]);

          $publishableKey = getenv('MOYASAR_PUBLISHABLE_KEY') ?: (defined('MOYASAR_PUBLISHABLE_KEY') ? MOYASAR_PUBLISHABLE_KEY : '');
          respond([
            'subscription_id' => $subId,
            'amount_halalas' => (int)round(((float)$plan['price_monthly']) * 100), // Moyasar amounts are in the smallest currency unit
            'currency' => $plan['currency'] ?: 'SAR',
            'plan_name' => $plan['name'],
            'publishable_key' => $publishableKey,
            'callback_url' => moyasar_callback_url($subId),
          ]);
        } catch (Throwable $e) {
          respond(null, ['message' => 'تعذر بدء عملية الدفع'], 500);
        }
        break;
      }

      case 'billing-confirm-payment': {
        // Called by the frontend right after Moyasar redirects back with ?id=<payment_id>.
        // We NEVER trust the client's claim that payment succeeded — we ask Moyasar directly
        // using the secret key (server-side only) before activating anything.
        $tid = tenant_current_id();
        if (!$tid) respond(null, ['message' => 'no_tenant'], 403);
        $subId = trim((string)($req['subscription_id'] ?? ''));
        $paymentId = trim((string)($req['payment_id'] ?? ''));
        if ($subId === '' || $paymentId === '') respond(null, ['message' => 'بيانات ناقصة'], 400);
        try {
          $result = moyasar_verify_and_activate($subId, $paymentId, $tid);
          respond($result);
        } catch (Throwable $e) {
          respond(null, ['message' => 'تعذر تأكيد الدفع: ' . $e->getMessage()], 500);
        }
        break;
      }

      case 'billing-webhook': {
        // Server-to-server callback from Moyasar. Verified by a shared secret header
        // (configure the same value in your Moyasar dashboard webhook settings), NOT by login.
        $secret = getenv('MOYASAR_WEBHOOK_SECRET') ?: (defined('MOYASAR_WEBHOOK_SECRET') ? MOYASAR_WEBHOOK_SECRET : '');
        $provided = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? ($_GET['secret'] ?? '');
        if ($secret === '' || !hash_equals($secret, (string)$provided)) {
          respond(null, ['message' => 'unauthorized'], 401);
        }
        $payload = read_json_body();
        $paymentId = (string)($payload['data']['id'] ?? $payload['id'] ?? '');
        $subId = (string)($payload['data']['metadata']['subscription_id'] ?? '');
        if ($paymentId === '' || $subId === '') respond(['ok' => true, 'ignored' => true]); // acknowledge, nothing to do
        try {
          // Look up the tenant for this subscription row ourselves — a webhook has no session.
          $st = pdo()->prepare("SELECT tenant_id FROM tenant_subscriptions WHERE id = :id LIMIT 1");
          $st->execute([':id' => $subId]);
          $tid = (string)($st->fetchColumn() ?: '');
          if ($tid !== '') { moyasar_verify_and_activate($subId, $paymentId, $tid); }
        } catch (Throwable $e) { /* log-and-ignore: Moyasar retries webhooks on non-2xx anyway */ }
        respond(['ok' => true]);
        break;
      }

      // ==================== Platform admin (SaaS operator) endpoints ====================
      // NOTE: tenants / subscription_plans / tenant_subscriptions are already fully readable
      // and writable by a platform_admin through the generic `service=db` CRUD endpoint (see
      // tenant_guard() in security.php, which returns null — i.e. no extra scoping — for that
      // role). No dedicated endpoints were needed for basic tenant/plan management.
      case 'platform-stats': {
        require_role(['platform_admin']);
        try {
          $pdo = pdo();
          $q = function($sql) use ($pdo) { try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };
          respond([
            'tenants_total' => $q("SELECT COUNT(*) FROM tenants"),
            'tenants_active' => $q("SELECT COUNT(*) FROM tenants WHERE status = 'active'"),
            'tenants_trial' => $q("SELECT COUNT(*) FROM tenants WHERE status = 'trial'"),
            'tenants_suspended' => $q("SELECT COUNT(*) FROM tenants WHERE status IN ('suspended','past_due','cancelled')"),
            'mrr_estimate' => (float)($pdo->query("SELECT COALESCE(SUM(p.price_monthly),0) FROM tenants t JOIN subscription_plans p ON p.id = t.plan_id WHERE t.status = 'active'")->fetchColumn() ?: 0),
          ]);
        } catch (Throwable $e) {
          respond(null, ['message' => 'تعذر تحميل إحصائيات المنصة'], 500);
        }
        break;
      }

      case 'platform-tenant-set-status': {
        require_role(['platform_admin']);
        $tid = trim((string)($req['tenant_id'] ?? ''));
        $status = trim((string)($req['status'] ?? ''));
        if (!in_array($status, ['trial','active','past_due','suspended','cancelled'], true)) respond(null, ['message' => 'حالة غير صالحة'], 400);
        if ($tid === '') respond(null, ['message' => 'tenant_id مطلوب'], 400);
        try {
          $sql = "UPDATE tenants SET status = :s" . ($status === 'suspended' ? ", suspended_at = NOW()" : "") . " WHERE id = :id";
          pdo()->prepare($sql)->execute([':s' => $status, ':id' => $tid]);
          respond(['ok' => true]);
        } catch (Throwable $e) {
          respond(null, ['message' => 'تعذر تحديث حالة الوكالة'], 500);
        }
        break;
      }

      // ==================== Editable public homepage content ====================
      case 'platform-content-get': {
        // Public: powers the landing page. Returns {} if nothing has been
        // customized yet — the frontend falls back to its built-in defaults.
        try {
          $pdo = pdo();
          $pdo->exec("CREATE TABLE IF NOT EXISTS platform_content (content_key VARCHAR(191) PRIMARY KEY, content_value LONGTEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
          $rows = $pdo->query("SELECT content_key, content_value FROM platform_content")->fetchAll(PDO::FETCH_KEY_PAIR);
          $out = [];
          foreach ($rows as $k => $v) {
            $decoded = json_decode((string)$v, true);
            $out[$k] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $v;
          }
          respond(['content' => $out]);
        } catch (Throwable $e) {
          respond(['content' => []]);
        }
        break;
      }

      case 'platform-content-save': {
        require_role(['platform_admin']);
        $updates = is_array($req['content'] ?? null) ? $req['content'] : null;
        if ($updates === null) respond(null, ['message' => 'content مطلوب (كائن مفاتيح/قيم)'], 400);
        try {
          $pdo = pdo();
          $pdo->exec("CREATE TABLE IF NOT EXISTS platform_content (content_key VARCHAR(191) PRIMARY KEY, content_value LONGTEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
          $st = $pdo->prepare("INSERT INTO platform_content (content_key, content_value, updated_at) VALUES (:k, :v, NOW()) ON DUPLICATE KEY UPDATE content_value = VALUES(content_value), updated_at = NOW()");
          foreach ($updates as $key => $value) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$key));
            if ($key === '') continue;
            $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $st->execute([':k' => $key, ':v' => $encoded]);
          }
          respond(['ok' => true]);
        } catch (Throwable $e) {
          respond(null, ['message' => 'تعذر حفظ المحتوى: ' . $e->getMessage()], 500);
        }
        break;
      }

      default:
        // Compatibility handler and flexible follow-up dispatcher
        $name = isset($req['name']) ? strtolower(trim((string)$req['name'])) : '';
        $whType = isset($req['webhook_type']) ? strtolower(trim((string)$req['webhook_type'])) : '';
        // 1) Accept send-whatsapp-simple for any webhook_type (generic simple send)
        if ($name === 'send-whatsapp-simple') {
          $to = (string)($req['phone'] ?? ($req['to'] ?? ''));
          if (trim($to) === '') { $to = get_followup_number(); }
          // Prefer raw 'message' if supplied; otherwise, try rendering a template by webhook_type
          $rawMsg = (string)($req['message'] ?? '');
          $msg = $rawMsg;
          if ($msg === '') {
            $ctx = is_array($req['template_vars'] ?? null) ? $req['template_vars'] : [];
            $tplKey = $whType !== '' ? $whType : 'outgoing';
            $tpl = get_template_content($tplKey) ?: get_template_content('outgoing') ?: get_template_content('outstanding_balance_report');
            if ($tpl) { $msg = render_template($tpl, $ctx); }
          }
          if ($msg === '') { respond(['processed' => 0, 'errors' => 1, 'message' => 'missing_message'], null, 200); }
          enqueue_followup_message($to, $msg, ($whType ?: 'outgoing'), null);
          $limit = (int)($req['limit'] ?? 20);
          $summary = process_whatsapp_queue($limit > 0 ? $limit : 20);
          respond($summary, null, 200);
        }
        // 2) Flexible action mapping for follow-up tests
        $rawAct = strtolower(trim((string)$action));
        $map = [
          'notify-new-order' => 'new_order_notification',
          'notify-delivery-delay' => 'delivery_delay_notification',
          'notify-payment-delay' => 'payment_delay_notification',
          'notify-new-expense' => 'new_expense_notification',
          'notify-new-payment' => 'new_payment_notification',
          'process-whatsapp-queue' => 'process_whatsapp_queue',
          'process_pending_messages' => 'process_whatsapp_queue',
          'test-follow-up' => 'test_follow_up_system',
        ];
        $cand = $map[$rawAct] ?? $rawAct;
        $known = [
          'new_order_notification','delivery_delay_notification','payment_delay_notification','new_expense_notification','new_payment_notification','test_follow_up_system','outstanding_balance_report'
        ];
        if (in_array($cand, $known, true)) {
          $to = (string)($req['to'] ?? ($req['phone'] ?? ''));
          $ctx = is_array($req['data'] ?? null) ? $req['data'] : (is_array($req['template_vars'] ?? null) ? $req['template_vars'] : []);
          $force = (bool)($req['force_send'] ?? false);
          send_followup_event($cand, $ctx, $to ?: null, $force);
          $limit = (int)($req['limit'] ?? 20);
          $summary = process_whatsapp_queue($limit > 0 ? $limit : 20);
          respond($summary, null, 200);
        }
        respond(['processed' => 0, 'errors' => 1, 'message' => 'Unknown function action', 'action' => $action], null, 400);
    }
  } catch (Throwable $e) {
    respond(null, [ 'message' => $e->getMessage(), 'code' => 'functions_runtime' ], 500);
  }
}

function generate_uuid_v4() {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function sanitize_ident($name) {
  $name = trim((string)$name);
  if ($name === '') respond(null, [ 'message' => 'Invalid identifier', 'code' => 'bad_identifier' ], 400);
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    respond(null, [ 'message' => 'Invalid identifier', 'code' => 'bad_identifier' ], 400);
  }
  return $name;
}

function is_assoc($arr) { return is_array($arr) && array_keys($arr) !== range(0, count($arr) - 1); }

// Global helpers to send WhatsApp notifications from db actions
function ensure_whatsapp_schema() {
  try {
    $pdo = pdo(); global $CFG;

    // Create whatsapp_messages if missing (minimal schema for queueing)
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_messages (
        id VARCHAR(255) PRIMARY KEY,
        from_number VARCHAR(64) NULL,
        to_number VARCHAR(64) NOT NULL,
        message_type VARCHAR(64) NULL,
        message_content TEXT NULL,
        status VARCHAR(32) NULL,
        error_message TEXT NULL,
        dedupe_key VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_whatsapp_status_created ON whatsapp_messages (status, created_at)");
    } catch (Throwable $e) { /* ignore */ }

    // Create webhook_logs if missing (optional logging)
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_logs (
        id VARCHAR(255) PRIMARY KEY,
        webhook_url TEXT NULL,
        request_body LONGTEXT NULL,
        response_status INT NULL,
        response_body LONGTEXT NULL,
        success TINYINT(1) NULL,
        message_id VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ignore */ }

    // Ensure correct types for whatsapp_messages columns
    $st = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'to_number' LIMIT 1");
    $st->execute([':db' => $CFG['name']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $type = strtolower((string)($row['DATA_TYPE'] ?? ''));
    if ($type !== '' && !in_array($type, ['varchar','char','text'])) {
      try { $pdo->exec("ALTER TABLE whatsapp_messages MODIFY to_number VARCHAR(32) NULL"); } catch (Throwable $e2) { /* ignore */ }
    }
    $st2 = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'from_number' LIMIT 1");
    $st2->execute([':db' => $CFG['name']]);
    $row2 = $st2->fetch(PDO::FETCH_ASSOC);
    $type2 = strtolower((string)($row2['DATA_TYPE'] ?? ''));
    if ($type2 !== '' && !in_array($type2, ['varchar','char','text'])) {
      try { $pdo->exec("ALTER TABLE whatsapp_messages MODIFY from_number VARCHAR(32) NULL"); } catch (Throwable $e3) { /* ignore */ }
    }
    $st3 = $pdo->prepare("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'dedupe_key' LIMIT 1");
    $st3->execute([':db' => $CFG['name']]);
    $row3 = $st3->fetch(PDO::FETCH_ASSOC);
    $type3 = strtolower((string)($row3['DATA_TYPE'] ?? ''));
    $len3 = (int)($row3['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
    if ($type3 !== '' && in_array($type3, ['varchar','char']) && $len3 > 0 && $len3 < 255) {
      try { $pdo->exec("ALTER TABLE whatsapp_messages MODIFY dedupe_key VARCHAR(255) NULL"); } catch (Throwable $e4) { /* ignore */ }
    }

    // Ensure message_content can store long WhatsApp texts (upgrade to LONGTEXT if it's VARCHAR/short TEXT)
    try {
      $stmc = $pdo->prepare("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'message_content' LIMIT 1");
      $stmc->execute([':db' => $CFG['name']]);
      $rowmc = $stmc->fetch(PDO::FETCH_ASSOC) ?: [];
      $typemc = strtolower((string)($rowmc['DATA_TYPE'] ?? ''));
      $lenmc = (int)($rowmc['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
      // If it's varchar or medium/small type, upgrade to LONGTEXT to be safe
      if ($typemc === 'varchar' || $typemc === 'char' || ($typemc === 'text' && $lenmc > 0 && $lenmc < 65000) || $typemc === 'tinytext' || $typemc === 'mediumtext') {
        try { $pdo->exec("ALTER TABLE whatsapp_messages MODIFY message_content LONGTEXT NULL"); } catch (Throwable $e5) { /* ignore */ }
      }
    } catch (Throwable $e) { /* ignore */ }

    // Ensure error_message is also long enough for diagnostics
    try {
      $sterr = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'error_message' LIMIT 1");
      $sterr->execute([':db' => $CFG['name']]);
      $typemerr = strtolower((string)($sterr->fetchColumn() ?? ''));
      if ($typemerr === 'varchar' || $typemerr === 'char' || $typemerr === 'text' || $typemerr === 'tinytext') {
        try { $pdo->exec("ALTER TABLE whatsapp_messages MODIFY error_message LONGTEXT NULL"); } catch (Throwable $e6) { /* ignore */ }
      }
    } catch (Throwable $e) { /* ignore */ }

    // Optional columns used by app; add if missing (safe on MySQL 8+ with IF NOT EXISTS)
    try { $pdo->exec("ALTER TABLE whatsapp_messages ADD COLUMN IF NOT EXISTS message_category VARCHAR(64) NULL"); } catch (Throwable $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE whatsapp_messages ADD COLUMN IF NOT EXISTS customer_id VARCHAR(255) NULL"); } catch (Throwable $e) { /* ignore */ }
    // retry_count/next_retry_at let process_whatsapp_queue() automatically re-attempt messages that
    // failed while WaSender/the fallback provider was down, instead of leaving them stuck as 'failed'
    // forever once the provider comes back online.
    try { $pdo->exec("ALTER TABLE whatsapp_messages ADD COLUMN IF NOT EXISTS retry_count INT NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE whatsapp_messages ADD COLUMN IF NOT EXISTS next_retry_at DATETIME NULL"); } catch (Throwable $e) { /* ignore */ }

    // One-time neutralization of the PRE-retry-era failed backlog.
    // Before retry_count/next_retry_at existed, historical 'failed' rows had no retry bookkeeping.
    // Adding those columns (retry_count DEFAULT 0, next_retry_at NULL) makes EVERY old failed row
    // instantly retry-eligible ("due now"), which (a) starves freshly-enqueued customer messages in
    // process_whatsapp_queue and (b) would re-deliver months-old order notifications to real customers
    // the moment WaSender recovers.
    // Discriminator: a 'failed' row with retry_count=0 AND next_retry_at IS NULL was NEVER touched by
    // the retry-aware code (which always sets retry_count>=1 and next_retry_at on any failure), so it
    // is definitionally legacy backlog. We deliberately do NOT compare created_at here: that column is
    // VARCHAR(255) and holds mixed/corrupted values from the old migration (e.g. ISO strings with a
    // timezone offset), so any datetime comparison on it raises MySQL error 1292 and aborts the query.
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS app_flags (flag_key VARCHAR(191) PRIMARY KEY, flag_value VARCHAR(255) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $bcDone = false;
      try { $bc = $pdo->prepare("SELECT 1 FROM app_flags WHERE flag_key = 'failed_backlog_neutralized_v1' LIMIT 1"); $bc->execute(); $bcDone = (bool)$bc->fetchColumn(); } catch (Throwable $e) { $bcDone = false; }
      if (!$bcDone) {
        try { $pdo->exec("UPDATE whatsapp_messages SET retry_count = 5 WHERE status = 'failed' AND COALESCE(retry_count,0) = 0 AND next_retry_at IS NULL"); } catch (Throwable $e) { /* ignore */ }
        try { $pdo->prepare("INSERT INTO app_flags (flag_key, flag_value) VALUES ('failed_backlog_neutralized_v1', :v)")->execute([':v' => date('Y-m-d H:i:s')]); } catch (Throwable $e) { /* ignore */ }
      }
    } catch (Throwable $e) { /* ignore */ }

    // Create whatsapp_api_settings if missing (direct API connection settings, e.g. WaSender)
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_api_settings (
        id VARCHAR(255) PRIMARY KEY,
        provider_name VARCHAR(100) NULL,
        app_key VARCHAR(255) NULL,
        auth_key VARCHAR(255) NULL,
        api_url VARCHAR(500) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ignore */ }

    // ===== Two-way inbox (incoming webhook + media) columns/tables =====
    // NOTE: this MySQL build does not reliably support "ADD COLUMN IF NOT EXISTS" /
    // "CREATE INDEX IF NOT EXISTS", so every change is guarded via INFORMATION_SCHEMA first.
    try {
      $db = $CFG['name'];
      // Load the real column list ONCE via INFORMATION_SCHEMA, falling back to SHOW COLUMNS if the
      // hosting provider restricts INFORMATION_SCHEMA access (seen on some shared cPanel plans).
      // Only when BOTH introspection paths come back empty do we assume "unknown" and skip adding
      // (matches the old conservative behavior) — otherwise a false "column missing" reading would
      // repeatedly attempt to add real columns and fail with duplicate-column errors every request.
      $wmCols = [];
      try {
        $stC = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_messages'");
        $stC->execute([':db' => $db]);
        foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $rC) { $wmCols[$rC['COLUMN_NAME']] = true; }
      } catch (Throwable $e) { /* try SHOW COLUMNS below */ }
      if (empty($wmCols)) {
        try {
          foreach ($pdo->query("SHOW COLUMNS FROM `whatsapp_messages`")->fetchAll(PDO::FETCH_ASSOC) as $rC2) {
            if (!empty($rC2['Field'])) $wmCols[$rC2['Field']] = true;
          }
        } catch (Throwable $e2) { /* ignore */ }
      }
      $colExists = function($col) use ($wmCols) { return empty($wmCols) ? true : isset($wmCols[$col]); };
      $add = [];
      if (!$colExists('direction'))            $add[] = "ADD COLUMN `direction` VARCHAR(16) NULL";
      if (!$colExists('media_url'))            $add[] = "ADD COLUMN `media_url` TEXT NULL";
      if (!$colExists('media_mime'))           $add[] = "ADD COLUMN `media_mime` VARCHAR(191) NULL";
      if (!$colExists('media_filename'))       $add[] = "ADD COLUMN `media_filename` VARCHAR(255) NULL";
      if (!$colExists('provider_message_id'))  $add[] = "ADD COLUMN `provider_message_id` VARCHAR(255) NULL";
      if (!$colExists('chat_id'))              $add[] = "ADD COLUMN `chat_id` VARCHAR(191) NULL";
      if (!$colExists('contact_name'))         $add[] = "ADD COLUMN `contact_name` VARCHAR(255) NULL";
      if (!$colExists('is_read'))              $add[] = "ADD COLUMN `is_read` TINYINT(1) NOT NULL DEFAULT 0";
      // customer_id is also attempted earlier via the version-fragile "ADD COLUMN IF NOT EXISTS"
      // (which silently no-ops on MySQL 5.x/older MariaDB); re-attempt it here version-safely so
      // wa-conversations' SELECT customer_id never breaks on hosts where that syntax is unsupported.
      if (!$colExists('customer_id'))          $add[] = "ADD COLUMN `customer_id` VARCHAR(255) NULL";
      if (!empty($add)) { try { $pdo->exec("ALTER TABLE whatsapp_messages " . implode(', ', $add)); } catch (Throwable $e) { /* ignore */ } }

      $idxExists = function($idx) use ($pdo, $db) {
        try {
          $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_messages' AND INDEX_NAME = :i LIMIT 1");
          $st->execute([':db' => $db, ':i' => $idx]);
          return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return true; }
      };
      if (!$idxExists('idx_wa_provider_msg')) { try { $pdo->exec("CREATE INDEX idx_wa_provider_msg ON whatsapp_messages (provider_message_id)"); } catch (Throwable $e) { /* ignore */ } }
      if (!$idxExists('idx_wa_direction'))    { try { $pdo->exec("CREATE INDEX idx_wa_direction ON whatsapp_messages (direction)"); } catch (Throwable $e) { /* ignore */ } }
      if (!$idxExists('idx_wa_chat'))         { try { $pdo->exec("CREATE INDEX idx_wa_chat ON whatsapp_messages (chat_id)"); } catch (Throwable $e) { /* ignore */ } }
    } catch (Throwable $e) { /* ignore */ }

    // Optional per-gateway media-send endpoint override (some WaSender builds use a separate URL)
    try {
      $db = $CFG['name'];
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_api_settings' AND COLUMN_NAME = 'media_api_url' LIMIT 1");
      $st->execute([':db' => $db]);
      if (!$st->fetchColumn()) { try { $pdo->exec("ALTER TABLE whatsapp_api_settings ADD COLUMN `media_api_url` VARCHAR(500) NULL"); } catch (Throwable $e) { /* ignore */ } }
    } catch (Throwable $e) { /* ignore */ }

    // Inbound webhook config (shared secret) + raw payload log for live diagnosis
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_inbound_settings (
        id VARCHAR(255) PRIMARY KEY,
        inbound_secret VARCHAR(64) NULL,
        connected_number VARCHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ignore */ }
    // DB-pull columns: read incoming messages DIRECTLY from the self-hosted WaSender MySQL DB on
    // the same server (bypasses Tiger Protect entirely — webhook POSTs are WAF-blocked on o2switch).
    foreach ([
      'pull_db_host' => "VARCHAR(128) NULL",
      'pull_db_name' => "VARCHAR(128) NULL",
      'pull_db_user' => "VARCHAR(128) NULL",
      'pull_db_pass' => "VARCHAR(255) NULL",
      'pull_table' => "VARCHAR(128) NULL",
      'pull_session_id' => "VARCHAR(128) NULL",
      'pull_enabled' => "TINYINT(1) NULL",
      'pull_checkpoint' => "VARCHAR(64) NULL",
      'pull_last_run' => "DATETIME NULL",
      'pull_last_result' => "TEXT NULL",
    ] as $c => $ddl) {
      try {
        $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_inbound_settings' AND COLUMN_NAME = :c LIMIT 1");
        $st->execute([':c' => $c]);
        if (!$st->fetchColumn()) { $pdo->exec("ALTER TABLE whatsapp_inbound_settings ADD COLUMN `$c` $ddl"); }
      } catch (Throwable $e) { /* ignore */ }
    }
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_inbound_log (
        id VARCHAR(255) PRIMARY KEY,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        remote_ip VARCHAR(64) NULL,
        secret_ok TINYINT(1) NULL,
        event_type VARCHAR(128) NULL,
        raw_body LONGTEXT NULL,
        parsed_summary LONGTEXT NULL,
        stored_count INT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ignore */ }
  } catch (Throwable $e) { /* ignore */ }
}

// Creates/upgrades tables for employees, salary payments, fixed recurring expense templates,
// and the alternate WhatsApp fallback-alert provider settings. Also adds traceability columns
// to `expenses` so auto-generated rows (salary/fixed-template) can be linked back to their source.
function ensure_hr_finance_schema() {
  try {
    $pdo = pdo();
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id VARCHAR(255) PRIMARY KEY,
        full_name VARCHAR(255) NOT NULL,
        position VARCHAR(255) NULL,
        phone VARCHAR(64) NULL,
        monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        bonus_default DECIMAL(12,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ignore */ }

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS salary_payments (
        id VARCHAR(255) PRIMARY KEY,
        employee_id VARCHAR(255) NOT NULL,
        pay_month VARCHAR(7) NOT NULL,
        base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
        deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_date DATE NULL,
        notes TEXT NULL,
        expense_id VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_employee_month (employee_id, pay_month)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ignore */ }

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS fixed_expense_templates (
        id VARCHAR(255) PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        category VARCHAR(64) NOT NULL DEFAULT 'custom',
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        employee_id VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        last_generated_month VARCHAR(7) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ignore */ }

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_fallback_settings (
        id VARCHAR(255) PRIMARY KEY,
        provider_name VARCHAR(100) NULL DEFAULT 'ultramsg',
        instance_id VARCHAR(255) NULL,
        token VARCHAR(255) NULL,
        api_url VARCHAR(500) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        last_alert_sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ignore */ }

    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN IF NOT EXISTS source_type VARCHAR(32) NULL"); } catch (Throwable $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN IF NOT EXISTS source_ref_id VARCHAR(255) NULL"); } catch (Throwable $e) { /* ignore */ }
  } catch (Throwable $e) { /* ignore */ }
}

// Pays one employee's salary for a given month (base + bonus - deductions), inserting a linked
// row into `expenses` (category "رواتب") and a row into `salary_payments`. Prevents double-payment
// for the same employee/month via a UNIQUE constraint check.
function pay_employee_salary($employeeId, $payMonth, $bonus = 0, $deductions = 0, $notes = '') {
  $pdo = pdo();
  ensure_hr_finance_schema();
  $st = $pdo->prepare("SELECT * FROM employees WHERE id = :id LIMIT 1");
  $st->execute([':id' => $employeeId]);
  $emp = $st->fetch(PDO::FETCH_ASSOC);
  if (!$emp) return ['ok' => false, 'error' => 'الموظف غير موجود'];

  $chk = $pdo->prepare("SELECT id FROM salary_payments WHERE employee_id = :e AND pay_month = :m LIMIT 1");
  $chk->execute([':e' => $employeeId, ':m' => $payMonth]);
  if ($chk->fetchColumn()) return ['ok' => false, 'error' => 'تم صرف راتب هذا الموظف لهذا الشهر مسبقاً'];

  $base = (float)$emp['monthly_salary'];
  $bonus = (float)$bonus;
  $deductions = (float)$deductions;
  $total = $base + $bonus - $deductions;
  $expenseId = generate_uuid_v4();
  $payId = generate_uuid_v4();
  $desc = 'راتب شهر ' . $payMonth . ' - ' . $emp['full_name'];

  try {
    $pdo->prepare("INSERT INTO expenses (id, expense_type, amount, expense_date, description, payment_method, notes, created_at, source_type, source_ref_id) VALUES (:id,'رواتب',:amt,:d,:desc,'تحويل بنكي',:notes,NOW(),'salary',:ref)")
      ->execute([':id' => $expenseId, ':amt' => $total, ':d' => date('Y-m-d'), ':desc' => $desc, ':notes' => $notes, ':ref' => $employeeId]);
  } catch (Throwable $e) { return ['ok' => false, 'error' => 'فشل إنشاء قيد المصروف: ' . $e->getMessage()]; }

  try {
    $pdo->prepare("INSERT INTO salary_payments (id, employee_id, pay_month, base_salary, bonus, deductions, total_amount, payment_date, notes, expense_id, created_at) VALUES (:id,:e,:m,:base,:bonus,:ded,:total,:pd,:notes,:exp,NOW())")
      ->execute([':id' => $payId, ':e' => $employeeId, ':m' => $payMonth, ':base' => $base, ':bonus' => $bonus, ':ded' => $deductions, ':total' => $total, ':pd' => date('Y-m-d'), ':notes' => $notes, ':exp' => $expenseId]);
  } catch (Throwable $e) {
    try { $pdo->prepare("DELETE FROM expenses WHERE id = :id")->execute([':id' => $expenseId]); } catch (Throwable $e2) { /* ignore */ }
    return ['ok' => false, 'error' => 'فشل إنشاء قيد الراتب: ' . $e->getMessage()];
  }

  return ['ok' => true, 'id' => $payId, 'expense_id' => $expenseId, 'total_amount' => $total, 'employee_name' => $emp['full_name']];
}

// Idempotently generates this calendar month's expense rows from active fixed_expense_templates
// (rent, utilities, per-employee residency renewal, commercial registry/municipality license, custom).
// Safe to call repeatedly — each template only generates once per calendar month.
function generate_recurring_fixed_expenses() {
  $pdo = pdo();
  ensure_hr_finance_schema();
  $curMonth = date('Y-m');
  $created = [];
  $catLabelMap = [
    'rent' => 'إيجار شهري',
    'utility' => 'مصروفات عامة',
    'residency' => 'تكلفة إقامة',
    'license' => 'رسوم تجديد سجل/رخصة',
    'custom' => 'مصروف ثابت',
  ];
  try {
    $st = $pdo->query("SELECT * FROM fixed_expense_templates WHERE (is_active = 1 OR is_active = '1')");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { $rows = []; }
  foreach ($rows as $t) {
    if ((string)($t['last_generated_month'] ?? '') === $curMonth) continue;
    $expenseId = generate_uuid_v4();
    $typeLabel = $catLabelMap[$t['category']] ?? 'مصروف ثابت';
    try {
      $pdo->prepare("INSERT INTO expenses (id, expense_type, amount, expense_date, description, notes, created_at, source_type, source_ref_id) VALUES (:id,:type,:amt,:d,:desc,:notes,NOW(),'fixed_template',:ref)")
        ->execute([':id' => $expenseId, ':type' => $typeLabel, ':amt' => (float)$t['amount'], ':d' => date('Y-m-d'), ':desc' => $t['name'], ':notes' => $t['notes'] ?? '', ':ref' => $t['id']]);
      $pdo->prepare("UPDATE fixed_expense_templates SET last_generated_month = :m, updated_at = NOW() WHERE id = :id")->execute([':m' => $curMonth, ':id' => $t['id']]);
      $created[] = ['template_id' => $t['id'], 'name' => $t['name'], 'expense_id' => $expenseId, 'amount' => $t['amount']];
    } catch (Throwable $e) { /* skip this template, continue with others */ }
  }
  return ['month' => $curMonth, 'generated' => count($created), 'items' => $created];
}

// Returns the currently active alternate WhatsApp provider used ONLY for failure alerts (e.g. UltraMsg),
// kept fully independent from the primary WaSender connection so it still works when WaSender is down.
function get_active_whatsapp_fallback_settings() {
  try {
    $pdo = pdo();
    ensure_hr_finance_schema();
    $st = $pdo->query("SELECT * FROM whatsapp_fallback_settings WHERE (is_active = 1 OR is_active = '1') ORDER BY updated_at DESC, created_at DESC LIMIT 1");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['token']) && !empty($row['instance_id'])) return $row;
  } catch (Throwable $e) { /* ignore */ }
  return null;
}

// Sends a WhatsApp message via the alternate provider (UltraMsg-style: POST {api_url}/{instance}/messages/chat).
// Normalize the ultramsg Instance ID to its full dashboard form, e.g. "183655" -> "instance183655".
function normalize_ultramsg_instance($instance) {
  $instance = trim((string)$instance);
  if ($instance !== '' && ctype_digit($instance)) $instance = 'instance' . $instance;
  return $instance;
}

// Build the correct ultramsg BASE origin (e.g. "https://api.ultramsg.com") from whatever the user
// saved in the api_url field, WITHOUT the instance id or endpoint path — because the caller always
// appends "/{instance}/messages/chat" itself. This is critical: the #1 real-world misconfiguration
// is pasting the full URL WITH the instance id into api_url (e.g. "https://api.ultramsg.com/instance183655"),
// which then gets the instance id appended AGAIN, producing a doubled path
// "https://api.ultramsg.com/instance183655/instance183655/messages/chat" that ultramsg rejects with
// {"error":"Path not found"} — so every message silently fails. Strip any trailing endpoint path and
// any trailing instance-id segment so the final URL is always correct regardless of what was pasted.
function ultramsg_base_url($apiUrl, $instance) {
  $base = trim((string)$apiUrl);
  if ($base === '') return 'https://api.ultramsg.com';
  // Drop any query string / fragment if the user pasted a full URL (".../messages/chat?token=...").
  $base = preg_replace('/[?#].*$/', '', $base);
  $base = rtrim($base, '/');
  // Drop a full endpoint path if the user pasted it (".../messages/chat", ".../instance/status", etc.)
  $base = preg_replace('#/(messages/chat|instance/status|messages/[A-Za-z]+)/?$#i', '', $base);
  $base = rtrim($base, '/');
  // Drop a trailing instance-id segment if it was included in the base URL, so we never double it.
  $slash = strrpos($base, '/');
  if ($slash !== false) {
    $last = substr($base, $slash + 1);
    $isInstanceSeg = ($last === $instance)
      || (ctype_digit($last) && ('instance' . $last) === $instance)
      || (bool)preg_match('/^instance[0-9A-Za-z]+$/i', $last);
    if ($isInstanceSeg) $base = substr($base, 0, $slash);
  }
  $base = rtrim($base, '/');
  if ($base === '' || !preg_match('#^https?://#i', $base)) return 'https://api.ultramsg.com';
  return $base;
}

function send_via_fallback_whatsapp($to, $message, $settings = null) {
  $cfg = $settings ?: get_active_whatsapp_fallback_settings();
  if (!$cfg) return ['ok' => false, 'error' => 'not_configured'];
  // ultramsg's Instance ID is the full string shown on their dashboard, e.g. "instance183655" —
  // it is NOT just the numeric part. Auto-correct a digits-only entry ("183655") so saved settings work.
  $instance = normalize_ultramsg_instance($cfg['instance_id'] ?? '');
  // Derive a clean base origin that does NOT already contain the instance id or an endpoint path,
  // so appending "/{instance}/messages/chat" below can never produce a doubled/invalid URL.
  $base = ultramsg_base_url($cfg['api_url'] ?? '', $instance);
  // ultramsg's own API requires the token as a GET query parameter on the URL itself — sending it
  // only in the POST body (as we did before) makes their server reply "Wrong token. Please provide
  // token as a GET parameter." even with a fully valid token. Confirmed against the live ultramsg
  // API while diagnosing this issue. Send it both in the query string (required) and the body
  // (harmless, some ultramsg accounts/proxies also read it from there).
  $token = (string)$cfg['token'];
  $url = $base . '/' . $instance . '/messages/chat?token=' . rawurlencode($token);
  // Force the destination into international MSISDN format. A locally-formatted number
  // (e.g. "0512345678") is the single most common reason a "sent" message never arrives.
  $to2 = to_international_msisdn($to);
  if ($to2 === '') { $to2 = ltrim((string)$to, '+'); }
  $payload = http_build_query(['token' => $token, 'to' => $to2, 'body' => (string)$message]);
  $ok = false; $code = 0; $resp = ''; $err = '';
  try {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $errmsg = curl_error($ch);
    $ok = ($resp !== false && $errno === 0 && $code >= 200 && $code < 400);
    if (!$ok) { $err = $errno !== 0 ? ('curl_error(' . $errno . '): ' . $errmsg) : ('http_' . $code . ': ' . substr((string)$resp, 0, 300)); }
    curl_close($ch);
    // ultramsg (and similar gateways) frequently return HTTP 200 even when the message was NOT
    // actually delivered — e.g. the instance's WhatsApp session is disconnected/not authenticated,
    // the "to" number is invalid, or the account is out of credit. The real outcome is embedded in
    // the JSON body, not the HTTP status. Without checking it, failures were silently reported as
    // success ("sent" toast shown, but no message ever arrives). Parse the body to catch this.
    if ($ok) {
      $json = json_decode((string)$resp, true);
      if (is_array($json)) {
        if (array_key_exists('error', $json) && $json['error'] !== null && $json['error'] !== '') {
          $ok = false;
          $err = 'ultramsg_error: ' . (is_string($json['error']) ? $json['error'] : json_encode($json['error'], JSON_UNESCAPED_UNICODE));
        } elseif (array_key_exists('sent', $json)) {
          $sentVal = $json['sent'];
          $sentOk = ($sentVal === true || $sentVal === 1 || $sentVal === '1' || (is_string($sentVal) && strtolower($sentVal) === 'true'));
          if (!$sentOk) {
            $ok = false;
            $err = 'ultramsg_not_sent: ' . (isset($json['message']) ? (string)$json['message'] : substr((string)$resp, 0, 300));
          }
        }
      }
    }
  } catch (Throwable $e) { $ok = false; $err = 'exception: ' . $e->getMessage(); }
  return ['ok' => $ok, 'code' => (int)$code, 'resp' => is_string($resp) ? $resp : '', 'error' => $err];
}

// Masks a phone number for safe display in diagnostics/logs (keeps first 2 + last 2 digits).
function mask_msisdn($s) {
  $s = (string)$s; $n = strlen($s);
  if ($n === 0) return '(empty)';
  if ($n <= 4) return str_repeat('*', $n);
  return substr($s, 0, 2) . str_repeat('*', max(0, $n - 4)) . substr($s, -2);
}

// Core failure-alert logic. Sends a one-off WhatsApp alert (via the alternate/fallback provider,
// e.g. ultramsg) to follow-up management's number when WaSender is down or an order-status message
// failed. Returns a STRUCTURED result so callers and the diagnostic endpoint can see EXACTLY why an
// alert was or wasn't delivered — the #1 support pain here was "no alert arrived and nobody could
// tell why". When $force is true the 30-minute debounce is bypassed (used by the on-demand test).
function evaluate_and_send_whatsapp_alert($reason, $context = [], $force = false) {
  $out = [
    'sent' => false,
    'forced' => (bool)$force,
    'skipped_reason' => null,
    'fallback_configured' => false,
    'notify_on' => null,
    'throttled' => false,
    'minutes_since_last_alert' => null,
    'to_masked' => null,
    'send_error' => null,
  ];
  try {
    $pdo = pdo();
    ensure_hr_finance_schema();
    $fallback = get_active_whatsapp_fallback_settings();
    if (!$fallback) { $out['skipped_reason'] = 'no_active_fallback_provider'; return $out; }
    $out['fallback_configured'] = true;

    // Timezone-SAFE debounce: compute elapsed minutes INSIDE the database (both last_alert_sent_at
    // and NOW() are on MySQL's clock). Computing this in PHP with strtotime()/time() was fragile:
    // raw `php -S` frequently runs in a different timezone than MySQL, which can make a recent alert
    // look like it happened "in the future", yielding a negative/small diff that wrongly suppressed
    // EVERY subsequent alert for 30 minutes — i.e. the exact "no alert arrives" symptom.
    try {
      $stMin = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, last_alert_sent_at, NOW()) AS mins FROM whatsapp_fallback_settings WHERE id = :id");
      $stMin->execute([':id' => $fallback['id']]);
      $minsRow = $stMin->fetch(PDO::FETCH_ASSOC);
      $mins = ($minsRow && $minsRow['mins'] !== null) ? (int)$minsRow['mins'] : null;
      $out['minutes_since_last_alert'] = $mins;
      if (!$force && $mins !== null && $mins >= 0 && $mins < 30) {
        $out['throttled'] = true;
        $out['skipped_reason'] = 'debounced_30min';
        return $out;
      }
    } catch (Throwable $e) { /* if the calc fails, fall through and still attempt the alert */ }

    $st = $pdo->query("SELECT * FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC LIMIT 1");
    $fu = $st->fetch(PDO::FETCH_ASSOC);
    if (!$fu || empty($fu['whatsapp_number'])) { $out['skipped_reason'] = 'no_followup_number'; return $out; }
    $notifyFlag = $fu['notify_whatsapp_failure'] ?? 1;
    $notifyOn = ($notifyFlag === 1 || $notifyFlag === '1' || $notifyFlag === true || strtolower((string)$notifyFlag) === 'true');
    $out['notify_on'] = $notifyOn;
    if (!$notifyOn) { $out['skipped_reason'] = 'notify_whatsapp_failure_disabled'; return $out; }

    $to = (string)$fu['whatsapp_number'];
    $out['to_masked'] = mask_msisdn($to);
    $msg = "⚠️ تنبيه نظام: تعذر إرسال رسائل واتساب\n\nالسبب: " . $reason;
    if (!empty($context['message_type'])) $msg .= "\nنوع الرسالة: " . $context['message_type'];
    if (!empty($context['to'])) $msg .= "\nإلى: " . $context['to'];
    $msg .= "\nالوقت: " . date('Y-m-d H:i:s');

    $result = send_via_fallback_whatsapp($to, $msg, $fallback);
    $out['sent'] = (bool)($result['ok'] ?? false);
    if (!$out['sent']) {
      $out['skipped_reason'] = 'fallback_send_failed';
      $out['send_error'] = (string)($result['error'] ?? 'unknown');
    }
    // Only start the 30-minute debounce window once an alert has ACTUALLY been delivered. Previously
    // the timestamp was set even when the send FAILED, so a single failed attempt silently muted
    // every subsequent alert for 30 minutes — during a real outage (exactly when the fallback is
    // most likely still misconfigured) NO failure alert ever got through. A failed alert now leaves
    // the window open to retry on the next queue run.
    if ($out['sent']) {
      try { $pdo->prepare("UPDATE whatsapp_fallback_settings SET last_alert_sent_at = NOW() WHERE id = :id")->execute([':id' => $fallback['id']]); } catch (Throwable $e) { /* ignore */ }
    }
    return $out;
  } catch (Throwable $e) {
    $out['skipped_reason'] = 'exception: ' . $e->getMessage();
    return $out;
  }
}

// Thin wrapper kept for existing callers (returns bool). Logs the structured outcome so operators can
// diagnose "no alert arrived" straight from whatsapp_debug.log without any code changes.
function maybe_alert_whatsapp_failure($reason, $context = []) {
  $res = evaluate_and_send_whatsapp_alert($reason, $context, false);
  try {
    file_put_contents(__DIR__ . '/whatsapp_debug.log', "Failure-alert outcome: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
  } catch (Throwable $e) { /* ignore */ }
  return (bool)($res['sent'] ?? false);
}

// Returns the currently active direct WhatsApp API connection (e.g. WaSender), or null if not configured/enabled
function get_active_whatsapp_api_settings($tenantId = null) {
  try {
    $pdo = pdo();
    ensure_whatsapp_schema();
    // SECURITY/CORRECTNESS (multi-tenant): each agency links its own WhatsApp
    // account (WaSender app_key/auth_key or Meta Business API credentials).
    // Without this filter, every tenant's outgoing messages were sent
    // through whichever agency's connection was configured/updated most
    // recently — i.e. one agency's customers could receive another agency's
    // WhatsApp messages (and vice versa).
    if ($tenantId === null && !tenant_is_platform_admin()) { $tenantId = tenant_current_id(); }
    $sql = "SELECT * FROM whatsapp_api_settings WHERE (is_active = 1 OR is_active = '1' OR LOWER(is_active) IN ('true','t','yes','on'))";
    $params = [];
    if ($tenantId !== null) { $sql .= " AND tenant_id = :tid"; $params[':tid'] = $tenantId; }
    $sql .= " ORDER BY updated_at DESC, created_at DESC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['api_url']) && !empty($row['app_key'])) return $row;
  } catch (Throwable $e) { /* ignore */ }
  return null;
}

// Sends a WhatsApp message via a direct API connection (e.g. WaSender) instead of a webhook relay.
// Returns ['ok' => bool, 'code' => int, 'resp' => string, 'error' => string] or null if no active connection is configured.
function send_via_whatsapp_api($toNumber, $message, $settings = null) {
  $cfg = $settings ?: get_active_whatsapp_api_settings();
  if (!$cfg) return null;
  $apiUrl = (string)$cfg['api_url'];
  $appKey = (string)$cfg['app_key'];
  $authKey = (string)($cfg['auth_key'] ?? '');
  $to = ltrim((string)$toNumber, '+');
  $payload = json_encode([ 'to' => $to, 'message' => (string)$message ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $headers = [ 'Content-Type: application/json', 'x-api-key: ' . $appKey ];
  if ($authKey !== '') { $headers[] = 'Authorization: ' . $authKey; }
  $ok = false; $code = 0; $resp = ''; $err = '';
  try {
    if (function_exists('curl_init')) {
      $ch = curl_init($apiUrl);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        // The WaSender gateway is a self-hosted server that commonly runs behind a self-signed
        // or mismatched-hostname TLS certificate. Without relaxing verification here, curl fails
        // every request at the TLS handshake step (before it even reaches WaSender), which looks
        // like a silent send failure. This is a trusted, user-owned endpoint, so this is safe.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
      ]);
      $resp = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlErrNo = curl_errno($ch);
      $curlErrMsg = curl_error($ch);
      $ok = ($resp !== false && $curlErrNo === 0 && $code >= 200 && $code < 400);
      if (!$ok) {
        if ($curlErrNo !== 0) { $err = 'curl_error(' . $curlErrNo . '): ' . $curlErrMsg; }
        elseif ($resp !== false) { $err = 'http_' . ($code ?: 0) . (is_string($resp) && $resp !== '' ? (': ' . substr($resp, 0, 500)) : ''); }
        else { $err = 'curl_failed_no_response'; }
      }
      curl_close($ch);
    } else {
      $opts = [
        'http' => [ 'method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n", 'content' => $payload, 'timeout' => 20, 'ignore_errors' => true ],
        'ssl' => [ 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true ],
      ];
      $ctx = stream_context_create($opts);
      $resp2 = @file_get_contents($apiUrl, false, $ctx);
      $ok = ($resp2 !== false);
      $resp = is_string($resp2) ? $resp2 : '';
      // Try to read the actual HTTP status from $http_response_header when available
      if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $mst)) {
        $code = (int)$mst[1];
        $ok = ($code >= 200 && $code < 400);
      }
      if (!$ok) { $err = 'http_stream_failed' . (is_string($resp2) ? '' : (': ' . (error_get_last()['message'] ?? ''))); }
    }
  } catch (Throwable $e) {
    $ok = false; $err = 'exception: ' . $e->getMessage();
  }
  return [ 'ok' => $ok, 'code' => (int)$code, 'resp' => is_string($resp) ? $resp : '', 'error' => $err, 'api_url' => $apiUrl ];
}

// Handler for service=whatsapp — direct/immediate sends via the configured API (WaSender), decoupled from webhook relays
function handle_whatsapp() {
  $body = read_json_body();
  $action = $body['action'] ?? '';
  if ($action === 'send') {
    $to = trim((string)($body['to'] ?? $body['phone'] ?? ''));
    $message = (string)($body['message'] ?? $body['text'] ?? '');
    if ($to === '' || $message === '') respond(null, [ 'message' => 'to and message are required' ], 400);
    $cfg = get_active_whatsapp_api_settings();
    if ($cfg) {
      $result = send_via_whatsapp_api($to, $message, $cfg);
      if ($result && $result['ok']) { respond([ 'sent' => true, 'via' => 'api', 'response' => $result['resp'] ]); }
      $reason = (string)($result['error'] ?? 'سبب غير معروف');
      respond(null, [ 'message' => 'فشل الإرسال عبر واجهة API: ' . $reason, 'details' => $result ], 502);
    }
    // Fallback: no active direct API connection configured — use legacy outgoing webhook if present
    $webhookUrl = resolve_webhook_for_message_type((string)($body['message_type'] ?? 'order_status_updated'));
    if ($webhookUrl) {
      $payload = json_encode([ 'event' => 'whatsapp_message_send', 'to' => $to, 'phone' => $to, 'message' => $message, 'text' => $message ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $ok = false; $code = 0;
      try {
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 15 ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ok = ($resp !== false && $code >= 200 && $code < 400);
        curl_close($ch);
      } catch (Throwable $e) { $ok = false; }
      if ($ok) respond([ 'sent' => true, 'via' => 'webhook' ]);
    }
    respond(null, [ 'message' => 'لا يوجد اتصال واتساب مفعل (لا API ولا ويب هوك نشط)' ], 400);
  }
  respond(null, [ 'message' => 'Unsupported whatsapp action' ], 400);
}

// ===================== WhatsApp two-way inbox (WaSender) =====================

// Absolute public base URL of this deployment, e.g. https://host/fdert (no trailing slash)
function wa_public_base_url() {
  $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  $isLocal = ($host === 'localhost' || strpos($host, 'localhost:') === 0 || strpos($host, '127.0.0.1') === 0);
  $scheme = 'https';
  if ($isLocal && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) $scheme = 'http';
  $script = $_SERVER['SCRIPT_NAME'] ?? '/api/index.php'; // live: /fdert/api/index.php
  $base = preg_replace('#/api/[^/]*$#', '', $script);
  if (!is_string($base)) $base = '';
  return $scheme . '://' . $host . $base;
}

function wa_uploads_dir() {
  $dir = dirname(__DIR__) . '/uploads/whatsapp'; // index.php lives in public/api/
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  return $dir;
}

// URL the frontend AND the gateway can use to fetch a stored media file (always served by this PHP).
function wa_media_public_url($basename) {
  return wa_public_base_url() . '/api/index.php?service=wa-media&f=' . rawurlencode($basename);
}

function wa_ext_from_mime($mime) {
  $mime = strtolower(trim((string)$mime));
  $map = [
    'image/jpeg'=>'jpg','image/jpg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp',
    'video/mp4'=>'mp4','video/3gpp'=>'3gp','video/quicktime'=>'mov','video/webm'=>'webm',
    'audio/ogg'=>'ogg','audio/opus'=>'ogg','audio/mpeg'=>'mp3','audio/mp4'=>'m4a','audio/aac'=>'aac',
    'audio/amr'=>'amr','audio/wav'=>'wav','audio/webm'=>'webm',
    'application/pdf'=>'pdf','application/msword'=>'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
    'application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
    'application/zip'=>'zip','text/plain'=>'txt',
  ];
  return $map[$mime] ?? 'bin';
}

function wa_mime_from_ext($ext) {
  $ext = strtolower(ltrim((string)$ext, '.'));
  $map = [
    'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',
    'mp4'=>'video/mp4','3gp'=>'video/3gpp','mov'=>'video/quicktime','webm'=>'video/webm',
    'ogg'=>'audio/ogg','opus'=>'audio/ogg','mp3'=>'audio/mpeg','m4a'=>'audio/mp4','aac'=>'audio/aac',
    'amr'=>'audio/amr','wav'=>'audio/wav',
    'pdf'=>'application/pdf','doc'=>'application/msword',
    'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip'=>'application/zip','txt'=>'text/plain',
  ];
  return $map[$ext] ?? 'application/octet-stream';
}

// Whitelist of safe, non-executable file extensions for stored media.
// Anything not in the list (e.g. php, phtml, phar, cgi, htaccess) is coerced to
// 'bin' so uploaded/received files can NEVER be executed on the host.
function wa_safe_ext($ext) {
  $ext = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$ext));
  $allowed = [
    'jpg','jpeg','png','gif','webp','bmp',
    'mp4','3gp','mov','webm','mkv','avi',
    'ogg','opus','mp3','m4a','aac','amr','wav',
    'pdf','doc','docx','xls','xlsx','ppt','pptx','csv','txt','rtf',
    'zip','rar','7z',
  ];
  return in_array($ext, $allowed, true) ? $ext : 'bin';
}

// Mask a secret for display to unauthenticated callers (keeps first/last 2 chars).
function wa_mask_secret($s) {
  $s = (string)$s; $n = strlen($s);
  if ($n === 0) return '';
  if ($n <= 4) return str_repeat('*', $n);
  return substr($s, 0, 2) . str_repeat('*', max(0, $n - 4)) . substr($s, -2);
}

// Download a media URL (self-signed TLS tolerated) into uploads/whatsapp. Returns [basename, mime] or null.
// WaSender stores media_url as a RELATIVE path (e.g. "media/xxx.jpg" or "/media/xxx.jpg"),
// not a full URL. Build candidate absolute URLs from the configured WaSender api_url
// (origin + its base path before /api), plus a local-filesystem candidate ("file:" prefix)
// since WaSender runs on the SAME server.
function wa_media_candidates($mu) {
  $mu = trim((string)$mu);
  if ($mu === '') return [];
  // Reject anything with parent-dir traversal or exotic schemes outright.
  if (strpos($mu, '..') !== false) return [];
  if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $mu) && !preg_match('#^https?://#i', $mu)) return [];
  $api = '';
  try { $s = get_active_whatsapp_api_settings(); $api = (string)($s['api_url'] ?? ''); } catch (Throwable $e) { /* ignore */ }
  $origin = ''; $path = '';
  if ($api !== '' && preg_match('#^(https?://[^/]+)(/.*)?$#i', $api, $m2)) { $origin = $m2[1]; $path = (string)($m2[2] ?? ''); }
  if (preg_match('#^https?://#i', $mu)) {
    // Absolute URL: only trust it if it points at the configured WaSender origin.
    if ($origin !== '' && stripos($mu, $origin . '/') === 0) return [$mu];
    return [];
  }
  $c = [];
  // WaSender-style "/api/media/<id>": the panel endpoint needs session auth, but the
  // files live on this same server under <wasender>/data/media — read them directly.
  if (preg_match('#^/?api/media/([A-Za-z0-9._-]+)$#', $mu, $mid)) {
    $id = $mid[1];
    $docroot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $home = (string)(getenv('HOME') ?: ($docroot !== '' ? dirname($docroot) : ''));
    $bases = array_values(array_unique(array_filter([
      $docroot !== '' ? $docroot . '/wasender' : '',
      $home !== '' ? $home . '/public_html/wasender' : '',
      $home !== '' ? $home . '/wasender' : '',
    ])));
    foreach ($bases as $b) {
      foreach (['/data/media', '/data/uploads', '/media', '/uploads', '/public/media'] as $sub) {
        $d = $b . $sub;
        if (!@is_dir($d)) continue;
        foreach (array_merge(@glob($d . '/' . $id . '*') ?: [], @glob($d . '/*/' . $id . '*') ?: []) as $hit) {
          if (@is_file($hit) && @is_readable($hit)) $c[] = 'file:' . $hit;
        }
      }
    }
  }
  // Local filesystem candidate (same server): relative paths only, never absolute.
  // Resolve against known roots (script cwd, document root) and require the
  // canonical path to stay inside that root (no traversal escapes).
  if ($mu[0] !== '/') {
    $roots = array_filter(array_unique([(string)@getcwd(), (string)($_SERVER['DOCUMENT_ROOT'] ?? '')]));
    foreach ($roots as $root) {
      $rootRp = (string)@realpath($root);
      if ($rootRp === '') continue;
      $cand = $root . '/' . $mu;
      if (!@is_file($cand) || !@is_readable($cand)) continue;
      $rp = (string)@realpath($cand);
      if ($rp !== '' && strpos($rp, $rootRp . '/') === 0) { $c[] = 'file:' . $rp; break; }
    }
  }
  if ($origin !== '') {
    $basePath = '';
    $pos = stripos($path, '/api');
    if ($pos !== false) $basePath = substr($path, 0, $pos);
    $rel = ltrim($mu, '/');
    if ($basePath !== '') $c[] = $origin . rtrim($basePath, '/') . '/' . $rel;
    $c[] = $origin . '/' . $rel;
  }
  return array_values(array_unique($c));
}

// Try every candidate (file: local copy, then http downloads) until one yields bytes.
function wa_fetch_media_any($mu, $hintMime = '') {
  foreach (wa_media_candidates($mu) as $cand) {
    if (strpos($cand, 'file:') === 0) {
      $p = substr($cand, 5);
      $data = @file_get_contents($p);
      if (is_string($data) && $data !== '' && strlen($data) < 64 * 1024 * 1024) {
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $mime = $hintMime !== '' ? $hintMime : ($ext !== '' ? wa_mime_from_ext($ext) : '');
        if ($ext === '') $ext = wa_ext_from_mime($mime);
        $ext = wa_safe_ext($ext);
        $name = 'in_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . '.' . $ext;
        if (@file_put_contents(wa_uploads_dir() . '/' . $name, $data) !== false) return [$name, $mime !== '' ? $mime : wa_mime_from_ext($ext)];
      }
      continue;
    }
    $dl = wa_download_media($cand, $hintMime);
    if ($dl) return $dl;
  }
  return null;
}

function wa_download_media($url, $hintMime = '') {
  $url = trim((string)$url);
  if ($url === '' || !preg_match('#^https?://#i', $url) || !function_exists('curl_init')) return null;
  try {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 15,
      CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_FOLLOWLOCATION => true,
    ]);
    $data = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || !is_string($data) || $data === '' || $code < 200 || $code >= 400) return null;
    if (strlen($data) > 64 * 1024 * 1024) return null;
    $mime = $hintMime !== '' ? $hintMime : trim(explode(';', $ctype)[0]);
    $ext = wa_ext_from_mime($mime);
    if ($ext === 'bin') {
      $p = parse_url($url, PHP_URL_PATH);
      $ue = strtolower(pathinfo((string)$p, PATHINFO_EXTENSION));
      if ($ue !== '') { $ext = $ue; if ($mime === '') $mime = wa_mime_from_ext($ext); }
    }
    if ($mime === '') $mime = wa_mime_from_ext($ext);
    $ext = wa_safe_ext($ext);
    $name = 'in_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . '.' . $ext;
    if (@file_put_contents(wa_uploads_dir() . '/' . $name, $data) === false) return null;
    return [$name, $mime];
  } catch (Throwable $e) { return null; }
}

function wa_first($arr, $keys, $default = null) {
  if (!is_array($arr)) return $default;
  foreach ($keys as $k) {
    if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') return $arr[$k];
  }
  return $default;
}

function wa_jid_to_phone($jid) {
  $jid = (string)$jid;
  if ($jid === '') return '';
  $jid = preg_replace('/[:@].*$/', '', $jid); // strip @s.whatsapp.net and device suffix :NN
  return preg_replace('/\D+/', '', (string)$jid);
}

// Saudi-aware phone variants for matching against the customers table (digits only).
function wa_phone_match_variants($intl) {
  $d = preg_replace('/\D+/', '', (string)$intl);
  $set = [];
  if ($d !== '') $set[$d] = true;
  if (strpos($d, '966') === 0) {
    $local = substr($d, 3);
    if ($local !== '') { $set[$local] = true; $set['0' . $local] = true; }
  } elseif (strlen($d) === 10 && strpos($d, '05') === 0) {
    $set['966' . substr($d, 1)] = true; $set[substr($d, 1)] = true;
  } elseif (strlen($d) === 9 && isset($d[0]) && $d[0] === '5') {
    $set['966' . $d] = true; $set['0' . $d] = true;
  }
  return array_keys($set);
}

function wa_lookup_customer_by_phone($intl) {
  try {
    $vs = wa_phone_match_variants($intl);
    if (empty($vs)) return null;
    $in = implode(',', array_fill(0, count($vs), '?'));
    $sql = "SELECT id, name FROM customers WHERE "
         . "REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,''),'+',''),' ',''),'-','') IN ($in) "
         . "OR REPLACE(REPLACE(REPLACE(COALESCE(phone,''),'+',''),' ',''),'-','') IN ($in) LIMIT 1";
    $st = pdo()->prepare($sql);
    $st->execute(array_merge($vs, $vs));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) { return null; }
}

function wa_is_message_like($v) {
  if (!is_array($v)) return false;
  return isset($v['message']) || isset($v['key']) || isset($v['body']) || isset($v['text'])
      || isset($v['from']) || isset($v['remoteJid']) || isset($v['chatId']) || isset($v['type']);
}

// Normalize a WaSender/Baileys-style webhook payload into a flat list of inbound messages.
// Written defensively (no vendor docs): handles data.messages[], messages[], single-message,
// and flat gateway shapes. Unknown/uninteresting events yield an empty list.
function wa_extract_incoming_messages($payload) {
  $out = [];
  if (!is_array($payload)) return $out;
  $candidates = [];
  $pushList = function($v) use (&$candidates) {
    if (!is_array($v)) return;
    if (wa_is_message_like($v)) { $candidates[] = $v; return; }
    $isList = array_keys($v) === range(0, count($v) - 1);
    if ($isList) { foreach ($v as $item) { if (is_array($item) && wa_is_message_like($item)) $candidates[] = $item; } }
  };
  if (isset($payload['data']['messages'])) $pushList($payload['data']['messages']);
  if (isset($payload['messages']))         $pushList($payload['messages']);
  if (isset($payload['data']))             $pushList($payload['data']);
  if (wa_is_message_like($payload))        $pushList($payload);
  // dedupe candidate identity to avoid double-processing overlapping shapes
  $seen = [];
  foreach ($candidates as $m) {
    $sig = md5(json_encode($m));
    if (isset($seen[$sig])) continue;
    $seen[$sig] = true;
    $norm = wa_normalize_one_message($m);
    if ($norm !== null) $out[] = $norm;
  }
  return $out;
}

function wa_normalize_one_message($m) {
  // fromMe
  $fm = wa_first($m, ['fromMe']);
  if ($fm === null && isset($m['key']['fromMe'])) $fm = $m['key']['fromMe'];
  $fromMe = is_bool($fm) ? $fm : ($fm !== null && in_array(strtolower((string)$fm), ['1','true','yes','on'], true));

  // chat id / jid
  $jid = wa_first($m, ['remoteJid','chatId','chat_id','jid']);
  if ($jid === null && isset($m['key']['remoteJid'])) $jid = $m['key']['remoteJid'];
  if ($jid === null) $jid = wa_first($m, ['from','sender','author'], '');
  $jid = (string)$jid;
  if ($jid !== '' && (strpos($jid, '@g.us') !== false || strpos($jid, 'broadcast') !== false || strpos($jid, '@newsletter') !== false)) return null;
  if (!empty($m['isGroup'])) return null;

  // WhatsApp "@lid" JIDs are anonymized linked IDs — their digits are NOT a phone
  // number (confirmed live from WaSender's native payload: from="...@lid").
  $isLid = (stripos($jid, '@lid') !== false);
  $phone = $isLid ? '' : wa_jid_to_phone($jid);
  if ($phone === '') {
    $cand = (string)wa_first($m, ['senderPn','participantPn','senderPhone','phone','number','waNumber'], '');
    if ($cand === '' && isset($m['raw']['key']) && is_array($m['raw']['key'])) {
      $cand = (string)wa_first($m['raw']['key'], ['senderPn','participantPn','remoteJid'], '');
    }
    if ($cand === '' && !$isLid) $cand = (string)wa_first($m, ['from','sender','author'], '');
    if (stripos($cand, '@lid') !== false) $cand = '';
    $phone = wa_jid_to_phone($cand);
  }
  // Last resort for LID senders: keep the LID digits so the message is not lost;
  // wa_store_message() will try to re-link the real customer by push name.
  if ($phone === '' && $isLid) $phone = wa_jid_to_phone($jid);
  if ($phone === '') return null;

  $pmid = wa_first($m, ['id','messageId','message_id']);
  if ($pmid === null && isset($m['key']['id'])) $pmid = $m['key']['id'];
  $pmid = (string)$pmid;

  $pushName = (string)wa_first($m, ['pushName','pushname','notifyName','senderName','contactName','name'], '');
  $ts = wa_first($m, ['messageTimestamp','timestamp','t','time']);

  $text = ''; $mediaType = ''; $mediaUrl = ''; $mediaMime = ''; $mediaB64 = ''; $mediaFilename = '';
  $mo = (isset($m['message']) && is_array($m['message'])) ? $m['message'] : $m;

  $text = (string)wa_first($mo, ['conversation','text','body','caption'], '');
  if ($text === '' && isset($mo['extendedTextMessage']['text'])) $text = (string)$mo['extendedTextMessage']['text'];

  $mediaMap = [
    'imageMessage'=>'image','videoMessage'=>'video','audioMessage'=>'audio',
    'documentMessage'=>'document','stickerMessage'=>'image','pttMessage'=>'audio','voiceMessage'=>'audio',
  ];
  foreach ($mediaMap as $key => $type) {
    if (isset($mo[$key]) && is_array($mo[$key])) {
      $sub = $mo[$key];
      $mediaType = $type;
      if ($text === '') $text = (string)wa_first($sub, ['caption'], '');
      $mediaMime = (string)wa_first($sub, ['mimetype','mime','mimeType'], '');
      $mediaFilename = (string)wa_first($sub, ['fileName','filename','title'], '');
      $mediaUrl = (string)wa_first($sub, ['url','mediaUrl','fileUrl','directPath'], '');
      $mediaB64 = (string)wa_first($sub, ['data','base64'], '');
      break;
    }
  }
  // WaSender native format nests the original Baileys message object under "raw".
  if (isset($m['raw']) && is_array($m['raw'])) {
    if ($text === '') {
      $text = (string)wa_first($m['raw'], ['conversation','text','body','caption'], '');
      if ($text === '' && isset($m['raw']['extendedTextMessage']['text'])) $text = (string)$m['raw']['extendedTextMessage']['text'];
    }
    if ($mediaType === '') {
      foreach ($mediaMap as $key => $type) {
        if (isset($m['raw'][$key]) && is_array($m['raw'][$key])) {
          $sub = $m['raw'][$key];
          $mediaType = $type;
          if ($text === '') $text = (string)wa_first($sub, ['caption'], '');
          $mediaMime = (string)wa_first($sub, ['mimetype','mime','mimeType'], '');
          $mediaFilename = (string)wa_first($sub, ['fileName','filename','title'], '');
          $mediaUrl = (string)wa_first($sub, ['url','mediaUrl','fileUrl','directPath'], '');
          $mediaB64 = (string)wa_first($sub, ['data','base64'], '');
          break;
        }
      }
    }
  }
  if ($mediaType === '') {
    $flatType = strtolower((string)wa_first($m, ['type','messageType','message_type'], ''));
    if (in_array($flatType, ['image','video','audio','voice','ptt','document','file','sticker'], true)) {
      $mediaType = ($flatType === 'voice' || $flatType === 'ptt') ? 'audio'
                 : ($flatType === 'file' ? 'document'
                 : ($flatType === 'sticker' ? 'image' : $flatType));
    }
    $u = (string)wa_first($m, ['mediaUrl','media_url','url','fileUrl','file_url','attachmentUrl'], '');
    if ($u !== '') { $mediaUrl = $u; if ($mediaType === '') $mediaType = 'document'; }
    $b = (string)wa_first($m, ['base64','media','fileBase64','data'], '');
    if ($b !== '' && strlen($b) > 100) { $mediaB64 = $b; if ($mediaType === '') $mediaType = 'document'; }
    if ($mediaMime === '') $mediaMime = (string)wa_first($m, ['mimetype','mime','mimeType'], '');
    if ($mediaFilename === '') $mediaFilename = (string)wa_first($m, ['fileName','filename'], '');
  }
  if ($mediaB64 !== '' && strpos($mediaB64, 'data:') === 0 && preg_match('#^data:([^;]+);base64,(.*)$#s', $mediaB64, $dm)) {
    if ($mediaMime === '') $mediaMime = $dm[1];
    $mediaB64 = $dm[2];
  }

  $msgType = $mediaType !== '' ? $mediaType : 'text';
  if ($text === '' && $mediaType === '') return null; // reactions/protocol/unsupported

  return [
    'from_me' => $fromMe, 'phone' => $phone, 'jid' => $jid, 'lid' => $isLid, 'provider_message_id' => $pmid,
    'push_name' => $pushName, 'timestamp' => $ts, 'text' => $text, 'message_type' => $msgType,
    'media_type' => $mediaType, 'media_url' => $mediaUrl, 'media_b64' => $mediaB64,
    'media_mime' => $mediaMime, 'media_filename' => $mediaFilename,
  ];
}

// Insert an outgoing (staff -> customer) row, tolerating older schemas via table_has_column().
function wa_insert_outgoing($id, $toPhone, $msgType, $content, $mediaUrlRel, $mediaMime, $mediaFilename, $status, $errorMsg, $customerId, $now, $tenantId = null) {
  if ($tenantId === null && !tenant_is_platform_admin()) { $tenantId = tenant_current_id(); }
  $pdo = pdo();
  $cols = ['id','from_number','to_number','message_type','message_content','status','created_at'];
  $ph   = [':id',':from',':to',':mt',':mc',':st',':ca'];
  $vals = [':id'=>$id, ':from'=>'system', ':to'=>$toPhone, ':mt'=>$msgType, ':mc'=>$content, ':st'=>$status, ':ca'=>$now];
  $addCol = function($name, $param, $value) use (&$cols, &$ph, &$vals) {
    if (table_has_column('whatsapp_messages', $name)) { $cols[] = $name; $ph[] = $param; $vals[$param] = $value; }
  };
  $addCol('direction', ':dir', 'outgoing');
  $addCol('is_read', ':rd', 1);
  if ($mediaUrlRel !== '') { $addCol('media_url', ':murl', $mediaUrlRel); $addCol('media_mime', ':mmime', $mediaMime); $addCol('media_filename', ':mfn', $mediaFilename); }
  if ($customerId) $addCol('customer_id', ':cust', $customerId);
  if ($tenantId !== null) $addCol('tenant_id', ':tid', $tenantId);
  $addCol('updated_at', ':ua', $now);
  if ($errorMsg !== '') $addCol('error_message', ':err', $errorMsg);
  $sql = "INSERT INTO whatsapp_messages (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
  $pdo->prepare($sql)->execute($vals);
}

// Persist one normalized incoming (or self-echo) message. Returns ['stored'=>bool, ...].
// $tenantId: pass explicitly when calling from a non-session context (webhook/DB-pull) where
// the tenant was resolved from the incoming account/instance rather than a login session.
function wa_store_message($norm, $tenantId = null) {
  if ($tenantId === null && !tenant_is_platform_admin()) { $tenantId = tenant_current_id(); }
  $pdo = pdo();
  $phone = to_international_msisdn($norm['phone']);
  if ($phone === '') $phone = preg_replace('/\D+/', '', (string)$norm['phone']);
  $pmid = (string)($norm['provider_message_id'] ?? '');

  if ($pmid !== '') {
    try {
      $selMedia = table_has_column('whatsapp_messages', 'media_url') ? ', media_url' : '';
      $altPmid = (string)($norm['alt_provider_message_id'] ?? '');
      if ($altPmid !== '' && $altPmid !== $pmid) {
        $st = $pdo->prepare("SELECT id$selMedia FROM whatsapp_messages WHERE provider_message_id IN (:p, :p2) LIMIT 1");
        $st->execute([':p' => $pmid, ':p2' => $altPmid]);
      } else {
        $st = $pdo->prepare("SELECT id$selMedia FROM whatsapp_messages WHERE provider_message_id = :p LIMIT 1");
        $st->execute([':p' => $pmid]);
      }
      $existing = $st->fetch(PDO::FETCH_ASSOC);
      if ($existing) {
        // MEDIA BACKFILL: earlier pulls stored media rows without the file (relative
        // media_url wasn't resolvable then). If we can fetch it now, attach it.
        if (($norm['media_type'] ?? '') !== '' && $selMedia !== '' && (string)($existing['media_url'] ?? '') === '' && !empty($norm['media_url'])) {
          $bf = wa_fetch_media_any($norm['media_url'], (string)($norm['media_mime'] ?? ''));
          if ($bf) {
            $upd = "UPDATE whatsapp_messages SET media_url = :mu" .
              (table_has_column('whatsapp_messages','media_mime') ? ", media_mime = :mm" : "") .
              (table_has_column('whatsapp_messages','media_filename') ? ", media_filename = :mf" : "") .
              " WHERE id = :id";
            $uv = [':mu' => 'uploads/whatsapp/' . $bf[0], ':id' => (string)$existing['id']];
            if (table_has_column('whatsapp_messages','media_mime')) $uv[':mm'] = $bf[1];
            if (table_has_column('whatsapp_messages','media_filename')) $uv[':mf'] = (string)($norm['media_filename'] ?? $bf[0]);
            try { $pdo->prepare($upd)->execute($uv); return ['stored' => false, 'reason' => 'duplicate', 'media_backfilled' => true]; } catch (Throwable $e) { /* ignore */ }
          }
        }
        return ['stored' => false, 'reason' => 'duplicate'];
      }
    } catch (Throwable $e) { /* ignore */ }
  }

  // media: prefer download; fall back to base64 write
  $mediaBasename = ''; $mediaMime = (string)($norm['media_mime'] ?? '');
  if (($norm['media_type'] ?? '') !== '') {
    if (!empty($norm['media_url'])) {
      $dl = wa_fetch_media_any($norm['media_url'], $mediaMime);
      if ($dl) { $mediaBasename = $dl[0]; if ($mediaMime === '') $mediaMime = $dl[1]; }
    }
    if ($mediaBasename === '' && !empty($norm['media_b64'])) {
      $bin = base64_decode((string)$norm['media_b64'], true);
      if ($bin !== false && strlen($bin) > 0 && strlen($bin) < 64 * 1024 * 1024) {
        $ext = wa_ext_from_mime($mediaMime);
        if ($ext === 'bin' && !empty($norm['media_filename'])) { $pe = strtolower(pathinfo((string)$norm['media_filename'], PATHINFO_EXTENSION)); if ($pe !== '') $ext = $pe; }
        $ext = wa_safe_ext($ext);
        $name = 'in_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . '.' . $ext;
        if (@file_put_contents(wa_uploads_dir() . '/' . $name, $bin) !== false) { $mediaBasename = $name; if ($mediaMime === '') $mediaMime = wa_mime_from_ext($ext); }
      }
    }
  }

  $cust = wa_lookup_customer_by_phone($phone);
  // LID sender whose digits are not a real phone: try to re-link the customer by
  // WhatsApp display name, and thread the message under that customer's real number.
  if (!$cust && !empty($norm['lid']) && empty($norm['from_me']) && (string)($norm['push_name'] ?? '') !== '') {
    $guess = guess_phone_from_name((string)$norm['push_name']);
    $gintl = to_international_msisdn($guess);
    if ($gintl !== '') {
      $c2 = wa_lookup_customer_by_phone($gintl);
      if ($c2) { $cust = $c2; $phone = $gintl; }
    }
  }
  $customerId = $cust['id'] ?? null;
  $contactName = (string)($norm['push_name'] ?? '');
  if ($contactName === '' && $cust) $contactName = (string)$cust['name'];

  $fromMe = (bool)($norm['from_me'] ?? false);
  $direction = $fromMe ? 'outgoing' : 'incoming';
  $mediaUrlRel = $mediaBasename !== '' ? ('uploads/whatsapp/' . $mediaBasename) : '';
  $now = date('Y-m-d H:i:s');
  $id = generate_uuid_v4();

  $cols = ['id','from_number','to_number','message_type','message_content','status','created_at'];
  $ph   = [':id',':from',':to',':mt',':mc',':st',':ca'];
  $vals = [
    ':id'=>$id, ':from'=> $fromMe ? 'system' : $phone, ':to'=> $fromMe ? $phone : 'system',
    ':mt'=>(string)($norm['message_type'] ?? 'text'), ':mc'=>(string)($norm['text'] ?? ''),
    ':st'=> $fromMe ? 'sent' : 'received', ':ca'=>$now,
  ];
  $addCol = function($name, $param, $value) use (&$cols, &$ph, &$vals) {
    if (table_has_column('whatsapp_messages', $name)) { $cols[] = $name; $ph[] = $param; $vals[$param] = $value; }
  };
  $addCol('direction', ':dir', $direction);
  $addCol('is_read', ':rd', $fromMe ? 1 : 0);
  if ($mediaUrlRel !== '') { $addCol('media_url', ':murl', $mediaUrlRel); $addCol('media_mime', ':mmime', $mediaMime); $addCol('media_filename', ':mfn', (string)($norm['media_filename'] ?? $mediaBasename)); }
  if ($pmid !== '') $addCol('provider_message_id', ':pmid', $pmid);
  if (!empty($norm['jid'])) $addCol('chat_id', ':chat', (string)$norm['jid']);
  if ($contactName !== '') $addCol('contact_name', ':cn', $contactName);
  if ($customerId) $addCol('customer_id', ':cust', $customerId);
  if ($tenantId !== null) $addCol('tenant_id', ':tid', $tenantId);
  $addCol('updated_at', ':ua', $now);

  $sql = "INSERT INTO whatsapp_messages (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
  $pdo->prepare($sql)->execute($vals);
  return ['stored' => true, 'id' => $id, 'direction' => $direction, 'has_media' => $mediaBasename !== ''];
}

// Send a media message via the active WhatsApp API (self-hosted WaSender).
// CONTRACT (confirmed live against /api/wa/create-message): media MUST be sent as
// multipart/form-data with the real binary in a `file` field — this gateway rejects
// JSON url/base64 payloads with http_400 "message or file is required". Text still
// uses JSON {to, message}; only media uses multipart. Caption travels in `message`.
function send_media_via_whatsapp_api($toNumber, $localPath, $type, $caption, $mime, $filename, $settings = null) {
  $cfg = $settings ?: get_active_whatsapp_api_settings();
  if (!$cfg) return null;
  $apiUrl = (string)(!empty($cfg['media_api_url']) ? $cfg['media_api_url'] : $cfg['api_url']);
  $appKey = (string)$cfg['app_key'];
  $authKey = (string)($cfg['auth_key'] ?? '');
  $to = ltrim((string)$toNumber, '+');
  $type = strtolower((string)$type);
  if (!in_array($type, ['image','video','audio','document'], true)) $type = 'document';

  if (!is_string($localPath) || $localPath === '' || !is_file($localPath)) {
    return ['ok' => false, 'code' => 0, 'resp' => '', 'error' => 'media_file_missing_on_server', 'api_url' => $apiUrl];
  }
  $sendMime = (string)$mime !== '' ? (string)$mime : 'application/octet-stream';
  $sendName = (string)$filename !== '' ? (string)$filename : basename($localPath);

  // Do NOT set Content-Type here — curl adds the multipart boundary automatically.
  $headers = ['x-api-key: ' . $appKey];
  if ($authKey !== '') $headers[] = 'Authorization: ' . $authKey;

  $fields = [
    'to' => $to,
    'type' => $type,
    'message' => (string)$caption,
    'caption' => (string)$caption,
    'fileName' => $sendName,
    'file' => new CURLFile($localPath, $sendMime, $sendName),
  ];

  $ok = false; $code = 0; $resp = ''; $err = '';
  try {
    if (function_exists('curl_init')) {
      $ch = curl_init($apiUrl);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $fields, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_FOLLOWLOCATION => true,
      ]);
      $resp = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $en = curl_errno($ch); $em = curl_error($ch);
      // Gateway returns 200 with {"status":"error",...} on logical failures, so inspect the body too.
      $bodyOk = true;
      if (is_string($resp) && $resp !== '') {
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['status']) && strtolower((string)$j['status']) === 'error') $bodyOk = false;
      }
      $ok = ($resp !== false && $en === 0 && $code >= 200 && $code < 400 && $bodyOk);
      if (!$ok) { $err = $en !== 0 ? ('curl_error(' . $en . '): ' . $em) : ('http_' . $code . (is_string($resp) && $resp !== '' ? (': ' . substr($resp, 0, 500)) : '')); }
      curl_close($ch);
    } else {
      $err = 'curl_unavailable';
    }
  } catch (Throwable $e) { $ok = false; $err = 'exception: ' . $e->getMessage(); }
  return ['ok' => $ok, 'code' => $code, 'resp' => is_string($resp) ? $resp : '', 'error' => $err, 'api_url' => $apiUrl];
}

function wa_get_or_create_inbound_secret($tenantId = null) {
  $pdo = pdo();
  ensure_whatsapp_schema();
  // SECURITY (multi-tenant): each tenant gets its OWN inbound secret + row,
  // so their WaSender/Meta webhook URL only ever authenticates as THAT
  // tenant. When $tenantId is null we fall back to the caller's session
  // tenant (interactive "show me my webhook URL" screens); webhook requests
  // themselves must always pass an explicit tenant id (see handle_wa_webhook).
  if ($tenantId === null && !tenant_is_platform_admin()) { $tenantId = tenant_current_id(); }
  try {
    $sql = "SELECT * FROM whatsapp_inbound_settings";
    $params = [];
    if ($tenantId !== null) { $sql .= " WHERE tenant_id = :tid"; $params[':tid'] = $tenantId; }
    $sql .= " ORDER BY updated_at DESC, created_at DESC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['inbound_secret'])) return $row;
  } catch (Throwable $e) { /* ignore */ }
  $secret = bin2hex(random_bytes(16));
  $id = generate_uuid_v4();
  try {
    if ($tenantId !== null) {
      $pdo->prepare("INSERT INTO whatsapp_inbound_settings (id, inbound_secret, tenant_id, created_at) VALUES (:id, :s, :tid, :c)")
          ->execute([':id'=>$id, ':s'=>$secret, ':tid'=>$tenantId, ':c'=>date('Y-m-d H:i:s')]);
    } else {
      $pdo->prepare("INSERT INTO whatsapp_inbound_settings (id, inbound_secret, created_at) VALUES (:id, :s, :c)")
          ->execute([':id'=>$id, ':s'=>$secret, ':c'=>date('Y-m-d H:i:s')]);
    }
  } catch (Throwable $e) { /* ignore */ }
  return ['id'=>$id, 'inbound_secret'=>$secret, 'connected_number'=>null, 'tenant_id'=>$tenantId];
}

// ============ WaSender DB PULL (WAF bypass) ============
// o2switch Tiger Protect blocks ALL webhook POSTs to this app (external, internal-localhost, any
// User-Agent — confirmed live 2026-07-06/07). WaSender saves every incoming message in its OWN
// MySQL DB on the SAME server, so we pull rows directly over a local MySQL connection instead.

// Gate for the pull-management endpoints: caller must present the same shared secret used by
// the webhook URL (?secret=...). 401s otherwise. The secret is only visible inside the app UI.
function wa_pull_require_secret() {
  $webhookTenantId = trim((string)($_GET['tenant'] ?? $_POST['tenant'] ?? ''));
  $cfg = wa_get_or_create_inbound_secret($webhookTenantId !== '' ? $webhookTenantId : null);
  $expected = (string)($cfg['inbound_secret'] ?? '');
  $provided = (string)($_GET['secret'] ?? $_POST['secret'] ?? ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? ''));
  if ($expected === '' || !hash_equals($expected, $provided)) {
    respond(null, ['message' => 'unauthorized: missing or invalid secret'], 401);
  }
  return $webhookTenantId !== '' ? $webhookTenantId : ($cfg['tenant_id'] ?? null);
}

// Escape a MySQL identifier for safe interpolation inside backticks.
function wa_sql_ident($name) { return str_replace('`', '``', (string)$name); }

// Canonical phone key for grouping conversations: international MSISDN when derivable
// (0554→966554 etc.), else bare digits. Ensures outgoing (often stored local-format) and
// incoming (stored international) rows for the SAME person land in ONE thread.
function wa_canon_phone($p) {
  $digits = preg_replace('/\D+/', '', (string)$p);
  if ($digits === '') return '';
  $intl = to_international_msisdn($digits);
  return $intl !== '' ? $intl : $digits;
}

// All storage variants a phone may appear under in whatsapp_messages (raw digits,
// international, 05-local, bare 5xxxxxxxx) — used to fetch a unified thread.
function wa_phone_variants($p) {
  $digits = preg_replace('/\D+/', '', (string)$p);
  $v = [];
  $add = function($x) use (&$v) { $x = (string)$x; if ($x !== '' && !in_array($x, $v, true)) $v[] = $x; };
  $add($digits);
  $intl = to_international_msisdn($digits); $add($intl);
  $base = $intl !== '' ? $intl : $digits;
  if (strpos($base, '966') === 0 && strlen($base) === 12) {
    $rest = substr($base, 3);   // 5XXXXXXXX
    $add($rest);
    $add('0' . $rest);          // 05XXXXXXXX
    $add('+' . $base);
  }
  if ((string)$p !== $digits) $add((string)$p);
  return $v;
}

// ==================== AI AGENT (وكيل الذكاء الاصطناعي) ====================
function ensure_ai_agent_schema() {
  static $done = false; if ($done) return; $done = true;
  try {
    $pdo = pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_agent_settings (
      id CHAR(36) NOT NULL PRIMARY KEY,
      enabled TINYINT(1) NOT NULL DEFAULT 0,
      provider VARCHAR(30) NOT NULL DEFAULT 'gemini',
      api_key TEXT NULL,
      model VARCHAR(120) NOT NULL DEFAULT '',
      feat_summary TINYINT(1) NOT NULL DEFAULT 1,
      feat_customer_reg TINYINT(1) NOT NULL DEFAULT 1,
      feat_order_draft TINYINT(1) NOT NULL DEFAULT 1,
      feat_delivery_reminder TINYINT(1) NOT NULL DEFAULT 0,
      feat_complaints TINYINT(1) NOT NULL DEFAULT 0,
      feat_unregistered_alert TINYINT(1) NOT NULL DEFAULT 0,
      followup_whatsapp VARCHAR(30) NOT NULL DEFAULT '',
      status_whatsapp VARCHAR(30) NOT NULL DEFAULT '',
      last_reminder_run DATETIME NULL,
      last_unreg_run DATETIME NULL,
      last_complaint_scan DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Dedicated follow-up-management WhatsApp gateway (separate WaSender-style direct link).
    // When enabled, ALL AI-agent messages (complaint replies, reminders, alerts) go out from
    // this WhatsApp number instead of the app's main gateway.
    foreach ([
      "ALTER TABLE ai_agent_settings ADD COLUMN fu_direct_enabled TINYINT(1) NOT NULL DEFAULT 0",
      "ALTER TABLE ai_agent_settings ADD COLUMN fu_provider VARCHAR(60) NOT NULL DEFAULT 'WaSender'",
      "ALTER TABLE ai_agent_settings ADD COLUMN fu_api_url TEXT NULL",
      "ALTER TABLE ai_agent_settings ADD COLUMN fu_app_key TEXT NULL",
      "ALTER TABLE ai_agent_settings ADD COLUMN fu_auth_key TEXT NULL",
      // Time-window + reply-delay settings (user-configurable):
      // scan_window_hours: only chats with inbound messages within this window are scanned
      //   for complaints/unregistered orders (prevents resurfacing old conversations).
      // summary_window_hours: order summary / order draft extraction only reads messages
      //   newer than this window (prevents the AI pulling old, already-registered orders).
      "ALTER TABLE ai_agent_settings ADD COLUMN scan_window_hours INT NOT NULL DEFAULT 24",
      // scan_window_minutes: preferred minutes-based scan window (overrides scan_window_hours).
      "ALTER TABLE ai_agent_settings ADD COLUMN scan_window_minutes INT NOT NULL DEFAULT 1440",
      // complaint_phrases: user-defined phrases (one per line) that must always count as complaint/note.
      "ALTER TABLE ai_agent_settings ADD COLUMN complaint_phrases TEXT NULL",
      "ALTER TABLE ai_agent_settings ADD COLUMN summary_window_hours INT NOT NULL DEFAULT 72",
      "ALTER TABLE ai_agent_settings ADD COLUMN reply_delay_enabled TINYINT(1) NOT NULL DEFAULT 0",
      "ALTER TABLE ai_agent_settings ADD COLUMN reply_delay_minutes INT NOT NULL DEFAULT 30",
      "ALTER TABLE ai_agent_settings ADD COLUMN last_reply_delay_scan DATETIME NULL",
    ] as $alter) { try { $pdo->exec($alter); } catch (Throwable $e) { /* column exists */ } }
    // Dedup ledger so a given un-answered inbound message alerts follow-up management once only.
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_reply_delay_alerts (
      phone VARCHAR(30) NOT NULL,
      last_inbound_at DATETIME NOT NULL,
      sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (phone, last_inbound_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_complaints (
      id CHAR(36) NOT NULL PRIMARY KEY,
      category VARCHAR(30) NOT NULL DEFAULT 'complaint',
      phone VARCHAR(30) NOT NULL DEFAULT '',
      customer_name VARCHAR(200) NOT NULL DEFAULT '',
      summary TEXT NULL,
      details TEXT NULL,
      ai_solution TEXT NULL,
      ai_reply TEXT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'new',
      reply_sent_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_phone (phone), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_order_reminders (
      order_id CHAR(36) NOT NULL PRIMARY KEY,
      sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) { /* best-effort */ }
}

function ai_cfg($tenantId = null) {
  ensure_ai_agent_schema();
  try {
    // SECURITY/CORRECTNESS (multi-tenant): without this, every tenant shared
    // whichever agency's AI settings (including their API key!) was saved most recently.
    if ($tenantId === null && !tenant_is_platform_admin()) { $tenantId = tenant_current_id(); }
    $sql = "SELECT * FROM ai_agent_settings";
    $params = [];
    if ($tenantId !== null) { $sql .= " WHERE tenant_id = :tid"; $params[':tid'] = $tenantId; }
    // Deterministic pick: updated_at can tie (or flip via ON UPDATE CURRENT_TIMESTAMP when
    // cron touches last_* columns), so break ties by created_at then id — otherwise a stale
    // duplicate row can win and freshly saved settings appear to "not save".
    $sql .= " ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1";
    $st = pdo()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) { return null; }
}

function ai_feature_on($cfg, $flag) {
  return $cfg && !empty($cfg['enabled']) && (string)$cfg['enabled'] !== '0'
    && !empty($cfg[$flag]) && (string)$cfg[$flag] !== '0'
    && trim((string)($cfg['api_key'] ?? '')) !== '';
}

// Generic LLM call: gemini native API, or OpenAI-compatible (openai/groq/deepseek).
// Returns ['ok'=>bool, 'text'=>string, 'error'=>string].
function ai_llm_call($cfg, $system, $user, $expectJson = false) {
  $provider = strtolower(trim((string)($cfg['provider'] ?? 'gemini')));
  $key = trim((string)($cfg['api_key'] ?? ''));
  $model = trim((string)($cfg['model'] ?? ''));
  if ($key === '') return ['ok'=>false, 'text'=>'', 'error'=>'no_api_key'];
  if (!function_exists('curl_init')) return ['ok'=>false, 'text'=>'', 'error'=>'no_curl'];
  $headers = ['Content-Type: application/json'];
  if ($provider === 'gemini') {
    if ($model === '') $model = 'gemini-2.0-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
    $body = [
      'contents' => [['role'=>'user', 'parts'=>[['text' => $system . "\n\n" . $user]]]],
    ];
    if ($expectJson) $body['generationConfig'] = ['response_mime_type' => 'application/json'];
  } else {
    $bases = ['openai'=>'https://api.openai.com/v1', 'groq'=>'https://api.groq.com/openai/v1', 'deepseek'=>'https://api.deepseek.com/v1'];
    $defModels = ['openai'=>'gpt-4o-mini', 'groq'=>'llama-3.3-70b-versatile', 'deepseek'=>'deepseek-chat'];
    $base = $bases[$provider] ?? $bases['openai'];
    if ($model === '') $model = $defModels[$provider] ?? 'gpt-4o-mini';
    $url = $base . '/chat/completions';
    $headers[] = 'Authorization: Bearer ' . $key;
    $body = [
      'model' => $model,
      'messages' => [['role'=>'system','content'=>$system], ['role'=>'user','content'=>$user]],
      'temperature' => 0.2,
    ];
    if ($expectJson) $body['response_format'] = ['type'=>'json_object'];
  }
  try {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      CURLOPT_TIMEOUT => 60,
      CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch); $errMsg = curl_error($ch);
    curl_close($ch);
    if ($errNo !== 0) return ['ok'=>false, 'text'=>'', 'error'=>'curl_error: ' . $errMsg];
    $j = json_decode((string)$resp, true);
    if ($code < 200 || $code >= 300) {
      $em = is_array($j) ? (string)($j['error']['message'] ?? substr((string)$resp, 0, 300)) : substr((string)$resp, 0, 300);
      return ['ok'=>false, 'text'=>'', 'error'=>'http_' . $code . ': ' . $em];
    }
    $text = '';
    if ($provider === 'gemini') $text = (string)($j['candidates'][0]['content']['parts'][0]['text'] ?? '');
    else $text = (string)($j['choices'][0]['message']['content'] ?? '');
    if ($text === '') return ['ok'=>false, 'text'=>'', 'error'=>'empty_response'];
    return ['ok'=>true, 'text'=>$text, 'error'=>''];
  } catch (Throwable $e) { return ['ok'=>false, 'text'=>'', 'error'=>'exception: ' . $e->getMessage()]; }
}

// Parse a JSON object out of an LLM response (tolerates ```json fences / extra text).
function ai_parse_json($text) {
  $t = trim((string)$text);
  $t = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $t);
  $j = json_decode($t, true);
  if (is_array($j)) return $j;
  if (preg_match('/\{.*\}/s', $t, $m)) { $j = json_decode($m[0], true); if (is_array($j)) return $j; }
  return null;
}

// Build a plain-text transcript of the WhatsApp thread with one contact.
function ai_thread_text($phone, $limit = 120, $sinceMinutes = 0) {
  $variants = wa_phone_variants($phone);
  if (!$variants) return '';
  $pdo = pdo();
  $cols = get_table_columns('whatsapp_messages');
  $hasDir = isset($cols['direction']);
  $inA = []; $inB = []; $bind = [];
  foreach ($variants as $i => $v) { $inA[] = ":va$i"; $bind[":va$i"] = $v; $inB[] = ":vb$i"; $bind[":vb$i"] = $v; }
  // Optional time bound: only messages newer than N minutes (keeps the AI away from old,
  // already-handled conversations/orders). Uses DB time — PHP TZ may differ from MySQL.
  $timeCond = '';
  if ((int)$sinceMinutes > 0) {
    $timeCond = " AND created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$sinceMinutes . " MINUTE)";
  }
  $sql = "SELECT from_number, to_number, " . ($hasDir ? "direction," : "'' AS direction,") . " message_type, message_content, created_at
          FROM whatsapp_messages
          WHERE (from_number IN (" . implode(',', $inA) . ") OR to_number IN (" . implode(',', $inB) . "))" . $timeCond . "
          ORDER BY created_at DESC LIMIT " . (int)$limit;
  $st = $pdo->prepare($sql);
  $st->execute($bind);
  $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
  $lines = [];
  foreach ($rows as $r) {
    $dir = (string)$r['direction'];
    if ($dir !== 'incoming' && $dir !== 'outgoing') $dir = ((string)$r['to_number'] === 'system') ? 'incoming' : 'outgoing';
    $who = $dir === 'incoming' ? 'العميل' : 'الموظف';
    $txt = trim((string)$r['message_content']);
    $type = (string)($r['message_type'] ?? 'text');
    if ($txt === '' && $type !== 'text') $txt = '[' . $type . ']';
    if ($txt === '') continue;
    $lines[] = $who . ' (' . (string)$r['created_at'] . '): ' . mb_substr($txt, 0, 500);
  }
  return implode("\n", $lines);
}

// Media attachments (images/audio/documents) stored for a conversation within an optional
// time window. Returns view_url (relative, for the frontend) and public_url (absolute, for
// copy/share/order notes). Newest first, capped at $limit.
function ai_thread_media($phone, $sinceMinutes = 0, $limit = 20) {
  $variants = wa_phone_variants($phone);
  if (!$variants) return [];
  $cols = get_table_columns('whatsapp_messages');
  if (!isset($cols['media_url'])) return [];
  $hasDir = isset($cols['direction']);
  $hasMime = isset($cols['media_mime']);
  $hasFn = isset($cols['media_filename']);
  $inA = []; $inB = []; $bind = [];
  foreach ($variants as $i => $v) { $inA[] = ":va$i"; $bind[":va$i"] = $v; $inB[] = ":vb$i"; $bind[":vb$i"] = $v; }
  $timeCond = '';
  if ((int)$sinceMinutes > 0) {
    $timeCond = " AND created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$sinceMinutes . " MINUTE)";
  }
  $sql = "SELECT media_url, " . ($hasMime ? "media_mime," : "'' AS media_mime,") . ($hasFn ? " media_filename," : " '' AS media_filename,") . " " . ($hasDir ? "direction," : "'' AS direction,") . " message_type, created_at
          FROM whatsapp_messages
          WHERE (from_number IN (" . implode(',', $inA) . ") OR to_number IN (" . implode(',', $inB) . "))
            AND media_url IS NOT NULL AND media_url <> ''" . $timeCond . "
          ORDER BY created_at DESC LIMIT " . (int)$limit;
  try {
    $st = pdo()->prepare($sql);
    $st->execute($bind);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { return []; }
  $out = [];
  foreach ($rows as $r) {
    $basename = basename((string)$r['media_url']);
    if ($basename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $basename)) continue;
    $mime = strtolower((string)($r['media_mime'] ?? ''));
    if ($mime === '') $mime = wa_mime_from_ext(strtolower(pathinfo($basename, PATHINFO_EXTENSION)));
    if (strpos($mime, 'image/') === 0) $kind = 'image';
    elseif (strpos($mime, 'audio/') === 0) $kind = 'audio';
    elseif (strpos($mime, 'video/') === 0) $kind = 'video';
    else $kind = 'document';
    $out[] = [
      'kind' => $kind,
      'mime' => $mime,
      'filename' => (string)(($r['media_filename'] ?? '') !== '' ? $r['media_filename'] : $basename),
      'direction' => (string)($r['direction'] ?? ''),
      'created_at' => (string)($r['created_at'] ?? ''),
      'view_url' => 'api/index.php?service=wa-media&f=' . rawurlencode($basename),
      'public_url' => wa_media_public_url($basename),
    ];
  }
  return $out;
}

// Find an existing customer by any variant of the phone (phone or whatsapp columns).
function ai_find_customer_by_phone($phone) {
  $variants = wa_phone_variants($phone);
  if (!$variants) return null;
  // Separate placeholder sets for the two IN() lists (MySQL native prepares reject reuse).
  $inA = []; $inB = []; $bind = [];
  foreach ($variants as $i => $v) { $inA[] = ":a$i"; $bind[":a$i"] = $v; $inB[] = ":b$i"; $bind[":b$i"] = $v; }
  try {
    $st = pdo()->prepare("SELECT * FROM customers WHERE phone IN (" . implode(',', $inA) . ") OR whatsapp IN (" . implode(',', $inB) . ") LIMIT 1");
    $st->execute($bind);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) { return null; }
}

// Send a WhatsApp text and record it in whatsapp_messages (best-effort).
// Resolve the requesting user: PHP session first, then the X-Auth-User header sent by the
// frontend (the app stores auth in localStorage, so a PHP session may not exist on prod —
// the header carries the logged-in user's id and is validated against the users table).
function ai_request_user() {
  if (!empty($_SESSION['user'])) return $_SESSION['user'];
  $uid = trim((string)($_SERVER['HTTP_X_AUTH_USER'] ?? ''));
  if ($uid === '' || strlen($uid) > 64) return null;
  try {
    $st = pdo()->prepare("SELECT id, email, role FROM users WHERE id = :id LIMIT 1");
    $st->execute([':id'=>$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
  } catch (Throwable $e) { return null; }
}

// True when the dedicated follow-up-management WhatsApp link is enabled and configured.
function ai_fu_gateway($aiCfg = null) {
  $cfg = $aiCfg ?: ai_cfg();
  if (!$cfg) return null;
  $en = !empty($cfg['fu_direct_enabled']) && (string)$cfg['fu_direct_enabled'] !== '0';
  $url = trim((string)($cfg['fu_api_url'] ?? ''));
  $key = trim((string)($cfg['fu_app_key'] ?? ''));
  if (!$en || $url === '' || $key === '') return null;
  return ['api_url'=>$url, 'app_key'=>$key, 'auth_key'=>trim((string)($cfg['fu_auth_key'] ?? ''))];
}

function ai_send_whatsapp($to, $message) {
  $to = wa_canon_phone($to);
  if ($to === '') return ['ok'=>false, 'error'=>'invalid_number'];
  // Prefer the dedicated follow-up-management WhatsApp link when configured —
  // complaint replies, reminders and alerts must come from that number only.
  $fu = ai_fu_gateway();
  $cfg = $fu ?: get_active_whatsapp_api_settings();
  $r = $cfg ? send_via_whatsapp_api($to, $message, $cfg) : null;
  $ok = is_array($r) ? !empty($r['ok']) : false;
  try {
    $cust = function_exists('wa_lookup_customer_by_phone') ? wa_lookup_customer_by_phone($to) : null;
    wa_insert_outgoing(generate_uuid_v4(), $to, 'text', $message, '', '', '', $ok ? 'sent' : 'failed', $ok ? '' : (string)($r['error'] ?? 'no_active_api'), ($cust['id'] ?? null), date('Y-m-d H:i:s'));
  } catch (Throwable $e) { /* ignore */ }
  return ['ok'=>$ok, 'error'=>$ok ? '' : (is_array($r) ? (string)($r['error'] ?? 'send_failed') : 'no_gateway')];
}

// Compose the full order details text used in reminders/alerts.
function ai_order_full_text($order) {
  $pdo = pdo();
  $cust = null;
  try { $st = $pdo->prepare("SELECT name, phone FROM customers WHERE id = :id"); $st->execute([':id'=>(string)$order['customer_id']]); $cust = $st->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
  $items = [];
  try { $st = $pdo->prepare("SELECT item_name, quantity, unit_price, total FROM order_items WHERE order_id = :id"); $st->execute([':id'=>(string)$order['id']]); $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
  $lines = [];
  $lines[] = 'طلب رقم: ' . (string)($order['order_number'] ?? $order['id']);
  if ($cust) $lines[] = 'العميل: ' . (string)$cust['name'] . ' — ' . (string)$cust['phone'];
  $lines[] = 'الحالة: ' . (string)($order['status'] ?? '');
  if (!empty($order['delivery_date'])) $lines[] = 'موعد التسليم: ' . (string)$order['delivery_date'];
  if ($items) {
    $lines[] = 'البنود:';
    foreach ($items as $it) $lines[] = '- ' . (string)$it['item_name'] . ' × ' . (string)$it['quantity'] . ' = ' . (string)$it['total'];
  }
  $total = (float)($order['total_amount'] ?? 0); $paid = (float)($order['paid_amount'] ?? 0);
  $lines[] = 'الإجمالي: ' . number_format($total, 2) . ' | المدفوع: ' . number_format($paid, 2) . ' | المتبقي: ' . number_format(max(0, $total - $paid), 2);
  if (!empty($order['notes'])) $lines[] = 'ملاحظات: ' . (string)$order['notes'];
  return implode("\n", $lines);
}

// Lazy cron: piggybacks on inbox polling. Time-gated per feature inside.
function ai_agent_cron() {
  $cfg = ai_cfg();
  if (!$cfg || empty($cfg['enabled']) || (string)$cfg['enabled'] === '0') return;
  $pdo = pdo();
  $now = time();
  // ---- Delivery reminders: 12h before delivery_date, once per order ----
  if (ai_feature_on($cfg, 'feat_delivery_reminder') && trim((string)$cfg['followup_whatsapp']) !== '') {
    // Atomic claim: only ONE concurrent request wins the UPDATE (rowCount 0 = another request already ran it)
    $claimed = false;
    try {
      $st = $pdo->prepare("UPDATE ai_agent_settings SET last_reminder_run = NOW() WHERE id = :id AND (last_reminder_run IS NULL OR last_reminder_run < DATE_SUB(NOW(), INTERVAL 900 SECOND))");
      $st->execute([':id'=>(string)$cfg['id']]);
      $claimed = $st->rowCount() > 0;
    } catch (Throwable $e) {}
    if ($claimed) { // every 15 min max
      try {
        $orders = $pdo->query("SELECT * FROM orders WHERE delivery_date IS NOT NULL AND delivery_date <> '' AND status NOT IN ('delivered','completed','cancelled','ملغي','مكتمل','تم التسليم')")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($orders as $o) {
          $dt = strtotime((string)$o['delivery_date']);
          if ($dt === false) continue;
          $diff = $dt - $now;
          if ($diff <= 0 || $diff > 12 * 3600) continue; // within the next 12 hours only
          $st = $pdo->prepare("SELECT order_id FROM ai_order_reminders WHERE order_id = :id");
          $st->execute([':id'=>(string)$o['id']]);
          if ($st->fetch()) continue;
          $msg = "⏰ تذكير: طلب يستحق التسليم خلال أقل من 12 ساعة\n\n" . ai_order_full_text($o);
          $r = ai_send_whatsapp((string)$cfg['followup_whatsapp'], $msg);
          if (!empty($r['ok'])) { try { $pdo->prepare("INSERT IGNORE INTO ai_order_reminders (order_id) VALUES (:id)")->execute([':id'=>(string)$o['id']]); } catch (Throwable $e) {} }
        }
      } catch (Throwable $e) { /* ignore */ }
    }
  }
  // ---- Hourly: detect confirmed-but-unregistered orders from recent chats ----
  if (ai_feature_on($cfg, 'feat_unregistered_alert')) {
    $claimed = false;
    try {
      $st = $pdo->prepare("UPDATE ai_agent_settings SET last_unreg_run = NOW() WHERE id = :id AND (last_unreg_run IS NULL OR last_unreg_run < DATE_SUB(NOW(), INTERVAL 3600 SECOND))");
      $st->execute([':id'=>(string)$cfg['id']]);
      $claimed = $st->rowCount() > 0;
    } catch (Throwable $e) {}
    if ($claimed) {
      // Hourly cadence → cover the hour gap (plus slack) even if the configured window is tiny.
      try { ai_scan_unregistered_orders($cfg, 90); } catch (Throwable $e) { /* ignore */ }
    }
  }
  // ---- Complaint scan: every 30 min over fresh inbound messages ----
  if (ai_feature_on($cfg, 'feat_complaints')) {
    $claimed = false;
    try {
      $st = $pdo->prepare("UPDATE ai_agent_settings SET last_complaint_scan = NOW() WHERE id = :id AND (last_complaint_scan IS NULL OR last_complaint_scan < DATE_SUB(NOW(), INTERVAL 1800 SECOND))");
      $st->execute([':id'=>(string)$cfg['id']]);
      $claimed = $st->rowCount() > 0;
    } catch (Throwable $e) {}
    if ($claimed) {
      // 30-min cadence → cover the gap (plus slack) even if the configured window is tiny.
      try { ai_scan_complaints($cfg, 45); } catch (Throwable $e) { /* ignore */ }
    }
  }
  // ---- Reply-delay alert: customer message unanswered longer than the configured limit ----
  if (!empty($cfg['reply_delay_enabled']) && (string)$cfg['reply_delay_enabled'] !== '0'
      && trim((string)$cfg['followup_whatsapp']) !== '') {
    $claimed = false;
    try {
      $st = $pdo->prepare("UPDATE ai_agent_settings SET last_reply_delay_scan = NOW() WHERE id = :id AND (last_reply_delay_scan IS NULL OR last_reply_delay_scan < DATE_SUB(NOW(), INTERVAL 600 SECOND))");
      $st->execute([':id'=>(string)$cfg['id']]);
      $claimed = $st->rowCount() > 0;
    } catch (Throwable $e) {}
    if ($claimed) {
      try { ai_scan_reply_delays($cfg); } catch (Throwable $e) { /* ignore */ }
    }
  }
}

// Alert follow-up management when a customer's last message has waited longer than
// reply_delay_minutes without any outgoing reply. One alert per unanswered message.
function ai_scan_reply_delays($cfg) {
  ensure_ai_agent_schema();
  $pdo = pdo();
  $delayMin = max(5, min(1440, (int)($cfg['reply_delay_minutes'] ?? 30)));
  $win = ai_scan_window($cfg);
  $sent = 0;
  // DB time reference — PHP TZ may differ from MySQL's, never mix time() with created_at.
  $dbNow = strtotime((string)$pdo->query("SELECT NOW()")->fetchColumn());
  if ($dbNow === false) return 0;
  foreach (ai_recent_inbound_phones($win, 30) as $phone) {
    $variants = wa_phone_variants($phone);
    if (!$variants) continue;
    $inA = []; $inB = []; $bind = [];
    foreach ($variants as $i => $v) { $inA[] = ":a$i"; $bind[":a$i"] = $v; $inB[] = ":b$i"; $bind[":b$i"] = $v; }
    $lastIn = null; $lastOut = null;
    try {
      $st = $pdo->prepare("SELECT MAX(created_at) FROM whatsapp_messages WHERE direction = 'incoming' AND from_number IN (" . implode(',', $inA) . ")");
      $st->execute(array_intersect_key($bind, array_flip(array_map(function($i){return ":a$i";}, array_keys($variants)))));
      $lastIn = $st->fetchColumn() ?: null;
      $st = $pdo->prepare("SELECT MAX(created_at) FROM whatsapp_messages WHERE direction = 'outgoing' AND to_number IN (" . implode(',', $inB) . ")");
      $st->execute(array_intersect_key($bind, array_flip(array_map(function($i){return ":b$i";}, array_keys($variants)))));
      $lastOut = $st->fetchColumn() ?: null;
    } catch (Throwable $e) { continue; }
    if (!$lastIn) continue;
    $inTs = strtotime((string)$lastIn);
    if ($inTs === false) continue;
    // Answered already, or not yet overdue
    if ($lastOut && strtotime((string)$lastOut) >= $inTs) continue;
    if ($dbNow - $inTs < $delayMin * 60) continue;
    // Dedup: one alert per unanswered inbound message
    try {
      $st = $pdo->prepare("SELECT phone FROM ai_reply_delay_alerts WHERE phone = :p AND last_inbound_at = :t LIMIT 1");
      $st->execute([':p'=>$phone, ':t'=>(string)$lastIn]);
      if ($st->fetch()) continue;
    } catch (Throwable $e) {}
    // Customer data + AI summary of what the customer wants
    $cust = ai_find_customer_by_phone($phone);
    $summary = '';
    $thread = ai_thread_text($phone, 40, $win);
    if ($thread !== '' && trim((string)($cfg['api_key'] ?? '')) !== '') {
      $r = ai_llm_call($cfg, "أنت مساعد لوكالة دعاية وإعلان. لخّص في 3 أسطر كحد أقصى ماذا يريد العميل من هذه المحادثة بالعربية.", "المحادثة:\n" . $thread, false);
      if (!empty($r['ok'])) $summary = trim((string)$r['text']);
    }
    if ($summary === '') {
      // Fallback: the customer's last message text
      try {
        $st = $pdo->prepare("SELECT message_content FROM whatsapp_messages WHERE direction = 'incoming' AND from_number IN (" . implode(',', $inA) . ") ORDER BY created_at DESC LIMIT 1");
        $st->execute(array_intersect_key($bind, array_flip(array_map(function($i){return ":a$i";}, array_keys($variants)))));
        $summary = mb_substr(trim((string)$st->fetchColumn()), 0, 300);
      } catch (Throwable $e) {}
    }
    $mins = (int)floor(($dbNow - $inTs) / 60);
    $msg = "⏳ تنبيه: عميل بانتظار الرد منذ " . $mins . " دقيقة\n\n"
         . "العميل: " . ($cust ? (string)$cust['name'] : 'غير مسجل بالنظام') . "\n"
         . "الجوال: " . $phone . "\n"
         . "آخر رسالة من العميل: " . (string)$lastIn . "\n\n"
         . "ملخص طلب العميل:\n" . ($summary !== '' ? $summary : '(لا يوجد نص)') . "\n\n"
         . "يرجى الرد على العميل بأسرع وقت.";
    $r = ai_send_whatsapp((string)$cfg['followup_whatsapp'], $msg);
    if (!empty($r['ok'])) {
      $sent++;
      try { $pdo->prepare("INSERT IGNORE INTO ai_reply_delay_alerts (phone, last_inbound_at) VALUES (:p, :t)")->execute([':p'=>$phone, ':t'=>(string)$lastIn]); } catch (Throwable $e) {}
    }
  }
  return $sent;
}

// Recent distinct inbound phones (canonical) within the last N minutes.
// Numbers the AI must NEVER treat as customers: the follow-up/status alert targets and the
// customer-service WhatsApp's own number (its outgoing/status traffic is not a customer chat).
function ai_excluded_phones() {
  $ex = [];
  try {
    $cfg = ai_cfg();
    if ($cfg) {
      foreach (['followup_whatsapp', 'status_whatsapp'] as $k) {
        $p = wa_canon_phone((string)($cfg[$k] ?? ''));
        if ($p !== '') $ex[$p] = true;
      }
    }
  } catch (Throwable $e) {}
  try {
    $wcfg = wa_pull_cfg();
    $p = wa_canon_phone((string)($wcfg['connected_number'] ?? ''));
    if ($p !== '') $ex[$p] = true;
  } catch (Throwable $e) {}
  return $ex;
}

function ai_recent_inbound_phones($minutes = 1440, $limit = 8) {
  $pdo = pdo();
  $phones = [];
  $excluded = ai_excluded_phones();
  try {
    $rows = $pdo->query("SELECT from_number, created_at FROM whatsapp_messages WHERE direction = 'incoming' ORDER BY created_at DESC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Use DB time, not PHP time: the web PHP timezone can differ from MySQL's
    // (observed +3h locally), which silently excluded valid recent messages.
    $cutoff = (string)$pdo->query("SELECT DATE_SUB(NOW(), INTERVAL " . (int)$minutes . " MINUTE)")->fetchColumn();
    foreach ($rows as $r) {
      if (strcmp((string)$r['created_at'], $cutoff) < 0) continue; // lexical compare is chronological here
      $p = wa_canon_phone((string)$r['from_number']);
      if ($p === '' || isset($phones[$p])) continue;
      // Skip WhatsApp LID pseudo-numbers (anonymized IDs, typically 14-15 digits): they are the
      // SAME person as their real number, so scanning both produces duplicate alerts — once from
      // the real phone and once from an "unknown" number.
      if (strlen($p) > 13) continue;
      if (isset($excluded[$p])) continue;
      $phones[$p] = true;
      if (count($phones) >= $limit) break;
    }
  } catch (Throwable $e) {}
  return array_keys($phones);
}

// User-configurable scan window (MINUTES) for complaint/unregistered-order scans.
// Prefers scan_window_minutes; falls back to legacy scan_window_hours * 60.
function ai_scan_window($cfg) {
  $m = (int)($cfg['scan_window_minutes'] ?? 0);
  if ($m <= 0) {
    $h = (int)($cfg['scan_window_hours'] ?? 24);
    $m = max(1, min(720, $h ?: 24)) * 60;
  }
  return max(5, min(43200, $m));
}

// User-defined phrases (one per line) that must always be treated as complaint/note.
function ai_complaint_phrases($cfg) {
  $raw = trim((string)($cfg['complaint_phrases'] ?? ''));
  if ($raw === '') return [];
  $out = [];
  foreach (preg_split('/[\r\n]+/u', $raw) as $ln) {
    // Unicode-safe trim: trim() with a multibyte charlist is byte-based and
    // strips lead bytes off Arabic characters, corrupting the phrase.
    $ln = preg_replace('/^[\s\-•,،]+|[\s\-•,،]+$/u', '', $ln);
    if ($ln !== '') $out[] = $ln;
  }
  return array_slice(array_values(array_unique($out)), 0, 100);
}

// $lookbackMin (optional) overrides the configured scan window: manual "فحص الآن" and
// gap-covering cron runs pass a wider lookback so short windows (e.g. 5 min) never cause
// silent misses of older conversations.
function ai_scan_complaints($cfg, $lookbackMin = 0) {
  ensure_ai_agent_schema();
  $pdo = pdo();
  $found = 0;
  $win = (int)$lookbackMin > 0 ? max((int)$lookbackMin, ai_scan_window($cfg)) : ai_scan_window($cfg);
  foreach (ai_recent_inbound_phones($win, 20) as $phone) {
    // Skip if we already flagged a complaint for this phone within the scan window
    try {
      $st = $pdo->prepare("SELECT id FROM ai_complaints WHERE phone = :p AND category = 'complaint' AND created_at >= DATE_SUB(NOW(), INTERVAL :h MINUTE) LIMIT 1");
      $st->bindValue(':p', $phone); $st->bindValue(':h', $win, PDO::PARAM_INT);
      $st->execute();
      if ($st->fetch()) continue;
    } catch (Throwable $e) {}
    // Only messages inside the window — old conversations must not resurface.
    $thread = ai_thread_text($phone, 60, $win);
    if ($thread === '') continue;
    // A complaint can only come from something the CUSTOMER wrote. Threads made purely of
    // outgoing/app messages (order-status templates) must never produce a complaint card.
    if (mb_strpos($thread, 'العميل (') === false) continue;
    $sys = "أنت مساعد لوكالة دعاية وإعلان. حلّل محادثة واتساب وحدد إن كان العميل لديه شكوى أو تذمر أو ملاحظة سلبية (تأخير تسليم، جودة، خدمة، سعر، تأخر الرد، انتظار، استعجال...). أي عتاب أو استعجال أو سؤال متكرر بلا رد يُعد ملاحظة. انتبه: رسائل تأكيد الطلب/التسجيل/الاستلام التلقائية المرسلة من الوكالة ليست شكوى، وإذا لم توجد رسالة من العميل نفسه تعبّر عن مشكلة أو استياء فأجب has_complaint=false ولا تخترع شكوى. أجب بصيغة JSON فقط: {\"has_complaint\": true/false, \"customer_name\": \"\", \"summary\": \"ملخص قصير للشكوى\", \"details\": \"التفاصيل\", \"solution\": \"حل مقترح للإدارة\", \"reply\": \"رد مهذب جاهز للإرسال للعميل بالعربية\"}";
    $phrases = ai_complaint_phrases($cfg);
    if ($phrases) {
      $sys .= "\n\nمهم جدًا: إذا وردت في رسائل العميل أي من العبارات التالية أو ما يشابهها في المعنى فاعتبرها شكوى/ملاحظة مؤكدة (has_complaint=true):\n- " . implode("\n- ", $phrases);
    }
    // Hard keyword match: if any configured phrase literally appears in an inbound
    // message, we treat it as a complaint even if the LLM says otherwise.
    $forced = false;
    if ($phrases) {
      foreach ($phrases as $ph) {
        if (mb_stripos($thread, $ph) !== false) { $forced = true; break; }
      }
    }
    $r = ai_llm_call($cfg, $sys, "المحادثة:\n" . $thread, true);
    $j = !empty($r['ok']) ? ai_parse_json($r['text']) : null;
    if ((!$j || empty($j['has_complaint'])) && !$forced) continue;
    if (!$j) $j = [];
    if (empty($j['has_complaint']) && $forced) {
      // Phrase matched but LLM missed/failed — build a minimal complaint record.
      $j['summary'] = (string)($j['summary'] ?? '') !== '' ? $j['summary'] : 'وردت عبارة شكوى/ملاحظة معرّفة من الإدارة في رسائل العميل';
      $j['details'] = (string)($j['details'] ?? '') !== '' ? $j['details'] : mb_substr($thread, -500);
    }
    $id = generate_uuid_v4();
    try {
      $pdo->prepare("INSERT INTO ai_complaints (id, category, phone, customer_name, summary, details, ai_solution, ai_reply, status) VALUES (:id, 'complaint', :p, :n, :s, :d, :sol, :rep, 'new')")
          ->execute([':id'=>$id, ':p'=>$phone, ':n'=>(string)($j['customer_name'] ?? ''), ':s'=>(string)($j['summary'] ?? ''), ':d'=>(string)($j['details'] ?? ''), ':sol'=>(string)($j['solution'] ?? ''), ':rep'=>(string)($j['reply'] ?? '')]);
      $found++;
      if (trim((string)$cfg['followup_whatsapp']) !== '') {
        $msg = "🔔 شكوى/ملاحظة عميل جديدة\n\nالعميل: " . (string)($j['customer_name'] ?? '') . "\nالجوال: " . $phone . "\n\nالملخص:\n" . (string)($j['summary'] ?? '') . "\n\nالتفاصيل:\n" . (string)($j['details'] ?? '') . "\n\nالحل المقترح:\n" . (string)($j['solution'] ?? '');
        ai_send_whatsapp((string)$cfg['followup_whatsapp'], $msg);
      }
    } catch (Throwable $e) {}
  }
  return $found;
}

// $lookbackMin (optional) overrides the configured scan window — see ai_scan_complaints().
function ai_scan_unregistered_orders($cfg, $lookbackMin = 0) {
  ensure_ai_agent_schema();
  $pdo = pdo();
  $found = 0;
  $win = (int)$lookbackMin > 0 ? max((int)$lookbackMin, ai_scan_window($cfg)) : ai_scan_window($cfg);
  foreach (ai_recent_inbound_phones($win, 20) as $phone) {
    // Skip if flagged already within the scan window
    try {
      $st = $pdo->prepare("SELECT id FROM ai_complaints WHERE phone = :p AND category = 'unregistered_order' AND created_at >= DATE_SUB(NOW(), INTERVAL :h MINUTE) LIMIT 1");
      $st->bindValue(':p', $phone); $st->bindValue(':h', $win, PDO::PARAM_INT);
      $st->execute();
      if ($st->fetch()) continue;
    } catch (Throwable $e) {}
    // Skip if this customer already has an order registered recently in the system
    // (lookback = scan window + 4 days so orders registered directly are always seen).
    $cust = ai_find_customer_by_phone($phone);
    if ($cust) {
      try {
        $lookbackM = $win + 96 * 60;
        $st = $pdo->prepare("SELECT id FROM orders WHERE customer_id = :c AND created_at >= DATE_SUB(NOW(), INTERVAL :h MINUTE) LIMIT 1");
        $st->bindValue(':c', (string)$cust['id']); $st->bindValue(':h', $lookbackM, PDO::PARAM_INT);
        $st->execute();
        if ($st->fetch()) continue;
      } catch (Throwable $e) {}
    }
    // Only messages inside the window — old confirmed-then-registered orders must not resurface.
    $thread = ai_thread_text($phone, 60, $win);
    if ($thread === '') continue;
    // A confirmed order requires an actual CUSTOMER message — app-template-only threads are noise.
    if (mb_strpos($thread, 'العميل (') === false) continue;
    $sys = "أنت مساعد لوكالة دعاية وإعلان. حلّل محادثة واتساب وحدد إن كان العميل أكّد طلبًا/اتفق على تنفيذ خدمة (موافقة صريحة على السعر أو التنفيذ). إذا دلّت المحادثة على أن الموظف سجّل الطلب فعلًا أو أرسل تأكيد تسجيل/فاتورة/رقم طلب، فأجب confirmed_order=false. لا تعتمد على رسائل الوكالة التلقائية وحدها — يجب أن يكون التأكيد واردًا في كلام العميل نفسه. أجب JSON فقط: {\"confirmed_order\": true/false, \"customer_name\": \"\", \"summary\": \"ملخص الطلب المؤكد\", \"details\": \"تفاصيل الطلب والسعر وموعد التسليم إن ذُكر، مع ذكر ما طلبه العميل نصًا\"}";
    $r = ai_llm_call($cfg, $sys, "المحادثة:\n" . $thread, true);
    if (empty($r['ok'])) continue;
    $j = ai_parse_json($r['text']);
    if (!$j || empty($j['confirmed_order'])) continue;
    $id = generate_uuid_v4();
    try {
      $pdo->prepare("INSERT INTO ai_complaints (id, category, phone, customer_name, summary, details, status) VALUES (:id, 'unregistered_order', :p, :n, :s, :d, 'new')")
          ->execute([':id'=>$id, ':p'=>$phone, ':n'=>(string)($j['customer_name'] ?? ''), ':s'=>(string)($j['summary'] ?? ''), ':d'=>(string)($j['details'] ?? '')]);
      $found++;
      if (trim((string)$cfg['followup_whatsapp']) !== '') {
        $msg = "⚠️ عميل أكّد طلبًا عبر واتساب ولم يُسجَّل له طلب في النظام\n\nالعميل: " . (string)($j['customer_name'] ?? '') . "\nالجوال: " . $phone . "\n\nملخص الطلب:\n" . (string)($j['summary'] ?? '') . "\n\nالتفاصيل:\n" . (string)($j['details'] ?? '') . "\n\nيرجى تسجيل الطلب في النظام.";
        ai_send_whatsapp((string)$cfg['followup_whatsapp'], $msg);
      }
    } catch (Throwable $e) {}
  }
  return $found;
}

function wa_pull_cfg() {
  ensure_whatsapp_schema();
  try {
    $st = pdo()->query("SELECT * FROM whatsapp_inbound_settings ORDER BY updated_at DESC, created_at DESC LIMIT 1");
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) { return null; }
}

function wa_pull_pdo($cfg) {
  $host = trim((string)($cfg['pull_db_host'] ?? '')) ?: 'localhost';
  $name = trim((string)($cfg['pull_db_name'] ?? ''));
  $user = trim((string)($cfg['pull_db_user'] ?? ''));
  $pass = (string)($cfg['pull_db_pass'] ?? '');
  if ($name === '' || $user === '') throw new RuntimeException('pull_not_configured');
  $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
  ]);
}

// Locate the messages table in the WaSender DB: configured name first, then best-guess scan.
function wa_pull_find_table($xpdo, $cfg) {
  $configured = trim((string)($cfg['pull_table'] ?? ''));
  $tables = [];
  foreach ($xpdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM) as $r) $tables[] = (string)$r[0];
  if ($configured !== '' && in_array($configured, $tables, true)) return $configured;
  // Prefer tables literally named like messages
  foreach ($tables as $t) { if (strtolower($t) === 'messages') return $t; }
  $best = ''; $bestScore = 0;
  foreach ($tables as $t) {
    if (stripos($t, 'message') === false && stripos($t, 'chat') === false) continue;
    try { $cols = array_map('strtolower', array_column($xpdo->query("SHOW COLUMNS FROM `" . wa_sql_ident($t) . "`")->fetchAll(PDO::FETCH_ASSOC), 'Field')); } catch (Throwable $e) { continue; }
    $score = 0;
    foreach (['fromme','from_me','isfromme','is_from_me'] as $c) if (in_array($c, $cols, true)) $score += 3;
    foreach (['remotejid','remote_jid','jid','chatid','chat_id','from'] as $c) if (in_array($c, $cols, true)) $score += 2;
    foreach (['text','body','content','message','raw','payload'] as $c) if (in_array($c, $cols, true)) $score += 1;
    if ($score > $bestScore) { $bestScore = $score; $best = $t; }
  }
  return $best;
}

// Map one WaSender DB row (unknown/foreign schema, defensively) to the message array shape
// understood by wa_normalize_one_message(). JSON columns (raw/payload/message/data) are decoded
// and used as the base, then flat columns overlay.
function wa_pull_row_to_msg($row) {
  $m = [];
  foreach (['raw','rawjson','raw_json','payload','data','message','msg','content_json'] as $jc) {
    foreach ($row as $k => $v) {
      if (strtolower((string)$k) === $jc && is_string($v) && $v !== '' && ($v[0] === '{' || $v[0] === '[')) {
        $dec = json_decode($v, true);
        if (is_array($dec)) { $m = array_replace($dec, $m); }
      }
    }
  }
  $lower = [];
  foreach ($row as $k => $v) $lower[strtolower((string)$k)] = $v;
  $ali = function($keys) use ($lower) { foreach ($keys as $k) { if (isset($lower[$k]) && $lower[$k] !== '' && $lower[$k] !== null) return $lower[$k]; } return null; };
  $set = function($key, $val) use (&$m) { if ($val !== null && !isset($m[$key])) $m[$key] = $val; };
  $set('fromMe', $ali(['fromme','from_me','isfromme','is_from_me']));
  $set('remoteJid', $ali(['remotejid','remote_jid','jid','chatid','chat_id','from','sender']));
  $set('id', $ali(['messageid','message_id','msgid','msg_id','waid','wa_id','wamessageid','wa_message_id','keyid','key_id']));
  $set('pushName', $ali(['pushname','push_name','sendername','sender_name','notifyname','contact_name','name']));
  $set('timestamp', $ali(['messagetimestamp','message_timestamp','timestamp','ts','createdat','created_at']));
  $set('text', $ali(['text','body','content','message_text','caption']));
  $set('type', $ali(['type','messagetype','message_type']));
  $set('mediaUrl', $ali(['mediaurl','media_url','fileurl','file_url']));
  $set('mimetype', $ali(['mimetype','mime','mime_type']));
  $set('fileName', $ali(['filename','file_name']));
  $set('isGroup', $ali(['isgroup','is_group']));
  // Self-hosted WaSender stores no fromMe flag; it uses a direction column instead
  // (incoming/outgoing). Without this mapping the agency's/staff's OUTGOING phone messages
  // were ingested as if customers sent them, creating fake inbox threads.
  if (!isset($m['fromMe'])) {
    $dir = strtolower(trim((string)($ali(['direction','dir','message_direction']) ?? '')));
    if ($dir !== '') { $m['fromMe'] = in_array($dir, ['outgoing','out','outbound','sent','send'], true); }
  }
  return $m;
}

// Pull new rows from the WaSender DB and store INBOUND ones. Returns a summary array.
function wa_pull_run($force = false) {
  $cfg = wa_pull_cfg();
  if (!$cfg) return ['ok'=>false, 'error'=>'no_settings_row'];
  $enabled = !empty($cfg['pull_enabled']) && (string)$cfg['pull_enabled'] !== '0';
  if (!$enabled && !$force) return ['ok'=>false, 'error'=>'pull_disabled'];
  // Throttle background-triggered pulls to one per 15s
  if (!$force && !empty($cfg['pull_last_run'])) {
    if (strtotime((string)$cfg['pull_last_run']) !== false && (time() - strtotime((string)$cfg['pull_last_run'])) < 15) {
      return ['ok'=>true, 'skipped'=>'throttled'];
    }
  }
  $pdo = pdo();
  $touch = function($result) use ($pdo, $cfg) {
    try {
      $pdo->prepare("UPDATE whatsapp_inbound_settings SET pull_last_run = NOW(), pull_last_result = :r WHERE id = :id")
        ->execute([':r'=>mb_substr(json_encode($result, JSON_UNESCAPED_UNICODE), 0, 60000), ':id'=>(string)$cfg['id']]);
    } catch (Throwable $e) { /* ignore */ }
  };
  try {
    $xpdo = wa_pull_pdo($cfg);
    $table = wa_pull_find_table($xpdo, $cfg);
    if ($table === '') { $r = ['ok'=>false, 'error'=>'messages_table_not_found']; $touch($r); return $r; }
    $tableQ = wa_sql_ident($table);
    $cols = array_column($xpdo->query("SHOW COLUMNS FROM `$tableQ`")->fetchAll(PDO::FETCH_ASSOC), 'Type', 'Field');
    $colsLower = array_change_key_case($cols, CASE_LOWER);
    // Order/checkpoint column: prefer numeric PK id, else a timestamp-ish column
    $orderCol = ''; $numeric = false;
    foreach (['id'] as $c) { if (isset($colsLower[$c]) && preg_match('/int/i', (string)$colsLower[$c])) { $orderCol = $c; $numeric = true; break; } }
    if ($orderCol === '') foreach (['createdat','created_at','messagetimestamp','timestamp','receivedat','received_at'] as $c) { if (isset($colsLower[$c])) { $orderCol = $c; break; } }
    if ($orderCol === '') { $orderCol = 'id'; }
    // Real column name (case-sensitive)
    foreach (array_keys($cols) as $real) { if (strtolower($real) === $orderCol) { $orderCol = $real; break; } }
    $checkpoint = (string)($cfg['pull_checkpoint'] ?? '');
    $conds = []; $bind = [];
    $orderColQ = wa_sql_ident($orderCol);
    if ($checkpoint !== '') { $conds[] = "`$orderColQ` > :cp"; $bind[':cp'] = $numeric ? (int)$checkpoint : $checkpoint; }
    // SESSION FILTER: the self-hosted WaSender DB holds messages of ALL connected WhatsApp
    // sessions (e.g. the follow-up team's personal number). Only ingest rows belonging to the
    // configured customer-service session, otherwise unrelated private chats leak into the inbox.
    $sessionId = trim((string)($cfg['pull_session_id'] ?? ''));
    $sessCol = '';
    foreach (array_keys($cols) as $real) { if (in_array(strtolower($real), ['session_id','sessionid','session'], true)) { $sessCol = $real; break; } }
    if ($sessionId !== '' && $sessCol !== '') { $conds[] = "`" . wa_sql_ident($sessCol) . "` = :sid"; $bind[':sid'] = $sessionId; }
    $where = empty($conds) ? '' : ('WHERE ' . implode(' AND ', $conds));
    $st = $xpdo->prepare("SELECT * FROM `$tableQ` $where ORDER BY `$orderColQ` ASC LIMIT 200");
    $st->execute($bind);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stored = 0; $seen = 0; $skipped = 0; $lastKey = $checkpoint;
    foreach ($rows as $row) {
      $seen++;
      $lastKey = (string)($row[$orderCol] ?? $lastKey);
      $m = wa_pull_row_to_msg($row);
      $norm = wa_normalize_one_message($m);
      if (!$norm) { $skipped++; continue; }
      if (!empty($norm['from_me'])) { $skipped++; continue; } // outgoing already recorded at send time
      $rowPk = (string)($row[$orderCol] ?? '');
      // ALWAYS also carry the synthesized row key: earlier pulls (before wa_message_id was
      // mapped) stored rows under this key, so dedup must match BOTH to avoid re-inserting.
      $norm['alt_provider_message_id'] = 'wspull:' . $table . ':' . $rowPk;
      if ($norm['provider_message_id'] === '') {
        $norm['provider_message_id'] = $norm['alt_provider_message_id'];
      }
      try { $r = wa_store_message($norm); if (!empty($r['stored'])) $stored++; } catch (Throwable $e) { $skipped++; }
    }
    if ($lastKey !== $checkpoint) {
      try { $pdo->prepare("UPDATE whatsapp_inbound_settings SET pull_checkpoint = :cp WHERE id = :id")->execute([':cp'=>$lastKey, ':id'=>(string)$cfg['id']]); } catch (Throwable $e) { /* ignore */ }
    }
    // Capture the session OWNER number (the customer-service WhatsApp itself): the most common
    // `to` of incoming rows is the owner. Stored in connected_number so AI scans can exclude it —
    // otherwise the owner shows up as a fake "customer" built from the app's own status messages.
    if (trim((string)($cfg['connected_number'] ?? '')) === '') {
      try {
        $toCol = ''; $dirCol = '';
        foreach (array_keys($cols) as $real) { if (in_array(strtolower($real), ['to','to_number','recipient'], true)) { $toCol = $real; break; } }
        foreach (array_keys($cols) as $real) { if (in_array(strtolower($real), ['direction', 'dir', 'message_direction', 'msg_direction'], true)) { $dirCol = $real; break; } }
        if ($toCol !== '' && $dirCol !== '') {
          $ownSql = "SELECT `" . wa_sql_ident($toCol) . "` t, COUNT(*) c FROM `$tableQ` WHERE LOWER(`" . wa_sql_ident($dirCol) . "`) IN ('incoming','in','inbound','received') AND `" . wa_sql_ident($toCol) . "` NOT LIKE '%broadcast%'";
          $ownBind = [];
          if ($sessionId !== '' && $sessCol !== '') { $ownSql .= " AND `" . wa_sql_ident($sessCol) . "` = :sid"; $ownBind[':sid'] = $sessionId; }
          $ownSql .= " GROUP BY t ORDER BY c DESC LIMIT 1";
          $stOwn = $xpdo->prepare($ownSql); $stOwn->execute($ownBind);
          $own = wa_canon_phone((string)($stOwn->fetchColumn() ?: ''));
          if ($own !== '') {
            $pdo->prepare("UPDATE whatsapp_inbound_settings SET connected_number = :n WHERE id = :id")->execute([':n'=>$own, ':id'=>(string)$cfg['id']]);
          }
        }
      } catch (Throwable $eOwn) { /* ignore */ }
    }
    $r = ['ok'=>true, 'table'=>$table, 'order_col'=>$orderCol, 'rows_seen'=>$seen, 'stored'=>$stored, 'skipped'=>$skipped, 'checkpoint'=>$lastKey];
    $touch($r);
    return $r;
  } catch (Throwable $e) {
    $r = ['ok'=>false, 'error'=>'pull_exception: ' . $e->getMessage()];
    $touch($r);
    return $r;
  }
}

// Public webhook receiver. ALWAYS answers 200 (so the gateway never disables the hook) and ALWAYS
// logs the raw payload (capped) so the exact WaSender shape can be inspected live and tuned.
function handle_wa_webhook() {
  $raw = file_get_contents('php://input');
  if (!is_string($raw)) $raw = '';
  $storedCount = 0; $secretOk = false; $eventType = '';
  try {
    $pdo = pdo();
    ensure_whatsapp_schema();
    // SECURITY (multi-tenant): each tenant's WaSender/Meta webhook URL must
    // include ?tenant=<tenant_id> so we validate the secret against THAT
    // tenant's own row (not "whichever tenant's settings were saved last").
    // Legacy single-tenant installs that haven't added ?tenant=... yet fall
    // back to the single most-recent row, same as before this change.
    $webhookTenantId = trim((string)($_GET['tenant'] ?? ''));
    $cfg = wa_get_or_create_inbound_secret($webhookTenantId !== '' ? $webhookTenantId : null);
    $expected = (string)($cfg['inbound_secret'] ?? '');
    $provided = (string)($_GET['secret'] ?? $_GET['token'] ?? ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? ''));
    $secretOk = ($expected !== '' && hash_equals($expected, $provided));
    $resolvedTenantId = $webhookTenantId !== '' ? $webhookTenantId : ($cfg['tenant_id'] ?? null);

    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = (is_array($_POST) && !empty($_POST)) ? $_POST : [];
    $eventType = (string)wa_first($payload, ['event','type','eventType'], '');

    // SESSION FILTER (same rule as the DB pull): if a customer-service session is configured and
    // the event carries a session id for a DIFFERENT session (e.g. the follow-up team's own
    // WhatsApp), log it but never store it in the inbox.
    $allowedSid = trim((string)($cfg['pull_session_id'] ?? ''));
    $eventSid = (string)wa_first($payload, ['sessionId','session_id','session'], '');
    if ($eventSid === '' && isset($payload['data']) && is_array($payload['data'])) {
      $eventSid = (string)wa_first($payload['data'], ['sessionId','session_id','session'], '');
    }
    $sessionAllowed = ($allowedSid === '' || $eventSid === '' || $eventSid === $allowedSid);

    $summary = [];
    if (!empty($payload)) {
      $msgs = wa_extract_incoming_messages($payload);
      foreach ($msgs as $norm) {
        $summary[] = ['dir'=> ($norm['from_me'] ? 'out' : 'in'), 'type'=>$norm['message_type'], 'has_media'=>($norm['media_type'] !== ''), 'text_len'=>mb_strlen((string)$norm['text'])];
        if ($secretOk && $sessionAllowed) {
          try { $r = wa_store_message($norm, $resolvedTenantId); if (!empty($r['stored'])) $storedCount++; } catch (Throwable $e) { /* skip one */ }
        }
      }
    }

    try {
      $pdo->prepare("INSERT INTO whatsapp_inbound_log (id, received_at, remote_ip, secret_ok, event_type, raw_body, parsed_summary, stored_count) VALUES (:id,:ra,:ip,:sok,:ev,:raw,:ps,:sc)")
        ->execute([
          ':id'=>generate_uuid_v4(), ':ra'=>date('Y-m-d H:i:s'),
          ':ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), ':sok'=>$secretOk ? 1 : 0,
          ':ev'=>substr($eventType, 0, 128), ':raw'=>mb_substr($raw, 0, 60000),
          ':ps'=>json_encode($summary, JSON_UNESCAPED_UNICODE), ':sc'=>$storedCount,
        ]);
      try { $pdo->exec("DELETE FROM whatsapp_inbound_log WHERE id NOT IN (SELECT id FROM (SELECT id FROM whatsapp_inbound_log ORDER BY received_at DESC LIMIT 200) t)"); } catch (Throwable $e) { /* ignore */ }
    } catch (Throwable $e) { /* ignore */ }
  } catch (Throwable $e) { /* swallow: fall through to the response below */ }
  if (!$secretOk) {
    // Reject unauthorized webhook calls (missing/invalid shared secret). The attempt is still
    // logged above for diagnostics; we simply do not acknowledge it as success and store nothing.
    respond(null, ['message'=>'invalid or missing webhook secret', 'received'=>true], 401);
  }
  respond(['ok'=>true, 'received'=>true, 'secret_ok'=>$secretOk, 'stored'=>$storedCount]);
}

// Streams a stored media file. Path-traversal safe (basename + whitelist).
function handle_wa_media() {
  $f = basename((string)($_GET['f'] ?? ''));
  if ($f === '' || strpos($f, '..') !== false || !preg_match('/^[A-Za-z0-9._-]+$/', $f)) {
    http_response_code(400); header('Content-Type: text/plain; charset=utf-8'); echo 'bad request'; exit;
  }
  $path = wa_uploads_dir() . '/' . $f;
  if (!is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'not found'; exit; }
  $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
  header('Content-Type: ' . wa_mime_from_ext($ext));
  header('Content-Length: ' . filesize($path));
  header('Cache-Control: private, max-age=86400');
  header('Content-Disposition: inline; filename="' . $f . '"');
  readfile($path);
  exit;
}

// Ensure evaluations table has detailed rating fields; auto-migrate if missing
function ensure_evaluations_schema() {
  try {
    $pdo = pdo(); global $CFG;
    $db = $CFG['name'];
    $hasCol = function($col) use ($pdo, $db) {
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'evaluations' AND COLUMN_NAME = :c LIMIT 1");
      $st->execute([':db' => $db, ':c' => $col]);
      return (bool)$st->fetchColumn();
    };
    // add columns if missing
    $alter = [];
    if (!$hasCol('overall_rating')) $alter[] = 'ADD COLUMN `overall_rating` TINYINT NULL';
    if (!$hasCol('service_quality_rating')) $alter[] = 'ADD COLUMN `service_quality_rating` TINYINT NULL';
    if (!$hasCol('delivery_time_rating')) $alter[] = 'ADD COLUMN `delivery_time_rating` TINYINT NULL';
    if (!$hasCol('communication_rating')) $alter[] = 'ADD COLUMN `communication_rating` TINYINT NULL';
    if (!$hasCol('price_value_rating')) $alter[] = 'ADD COLUMN `price_value_rating` TINYINT NULL';
    if (!$hasCol('would_recommend')) $alter[] = 'ADD COLUMN `would_recommend` TINYINT(1) NULL';
    if (!$hasCol('feedback_text')) $alter[] = 'ADD COLUMN `feedback_text` TEXT NULL';
    if (!$hasCol('suggestions')) $alter[] = 'ADD COLUMN `suggestions` TEXT NULL';
    if (!$hasCol('submitted_at')) $alter[] = 'ADD COLUMN `submitted_at` DATETIME NULL';
    if (!empty($alter)) {
      try { $pdo->exec('ALTER TABLE `evaluations` ' . implode(', ', $alter)); } catch (Throwable $e) { /* ignore */ }
    }
    // index on token
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_evaluations_token ON `evaluations`(`evaluation_token`)"); } catch (Throwable $e) { /* ignore */ }
    // backfill legacy rating/comment
    try { $pdo->exec("UPDATE `evaluations` SET `overall_rating` = NULLIF(CAST(`rating` AS UNSIGNED), 0) WHERE (`overall_rating` IS NULL OR `overall_rating` = 0) AND `rating` IS NOT NULL AND TRIM(`rating`) <> '' AND CAST(`rating` AS UNSIGNED) BETWEEN 1 AND 5"); } catch (Throwable $e) { /* ignore */ }
    try { $pdo->exec("UPDATE `evaluations` SET `feedback_text` = `comment` WHERE `feedback_text` IS NULL AND `comment` IS NOT NULL AND TRIM(`comment`) <> ''"); } catch (Throwable $e) { /* ignore */ }
  } catch (Throwable $e) { /* ignore */ }
}

// Ensure installment reminders flags exist on installment_payments
function ensure_installments_schema() {
  try {
    $pdo = pdo(); global $CFG;
    $db = $CFG['name'];
    $colExists = function($col) use ($pdo, $db) {
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'installment_payments' AND COLUMN_NAME = :c LIMIT 1");
      $st->execute([':db' => $db, ':c' => $col]);
      return (bool)$st->fetchColumn();
    };
    $alters = [];
    if (!$colExists('reminder_sent_2days')) $alters[] = "ADD COLUMN `reminder_sent_2days` TINYINT(1) NULL DEFAULT 0";
    if (!$colExists('reminder_sent_1day')) $alters[] = "ADD COLUMN `reminder_sent_1day` TINYINT(1) NULL DEFAULT 0";
    if (!empty($alters)) {
      try { $pdo->exec('ALTER TABLE `installment_payments` ' . implode(', ', $alters)); } catch (Throwable $e) { /* ignore */ }
    }
    // Indexes for due_date and flags to speed scans
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_installment_due_date ON `installment_payments`(`due_date`)"); } catch (Throwable $e2) { /* ignore */ }
  } catch (Throwable $e) { /* ignore */ }
}

// Template rendering helpers and webhook resolution
function get_template_content($key) {
  try {
    $pdo = pdo();
    // Detect existing columns to build a compatible WHERE clause
    $cols = get_table_columns('message_templates');
    $nameCols = [];
    foreach (['template_name','name','template_key','key'] as $c) {
      if (isset($cols[$c])) $nameCols[] = $c;
    }

    if (empty($nameCols)) {
      // Fallback: no recognizable name columns; just pick the most recently updated/created row
      $orderCols = [];
      foreach (['updated_at','created_at','id'] as $oc) { if (isset($cols[$oc])) $orderCols[] = $oc; }
      $orderBy = $orderCols ? (' ORDER BY ' . implode(', ', array_map(function($c){ return $c . ' DESC'; }, $orderCols))) : '';
      $sql = 'SELECT * FROM message_templates' . $orderBy . ' LIMIT 1';
      $st = $pdo->prepare($sql);
      $st->execute();
    } else {
      $conds = [];
      foreach ($nameCols as $c) { $conds[] = 'LOWER(TRIM(`' . $c . '`)) = LOWER(TRIM(:k))'; }
      $where = '(' . implode(' OR ', $conds) . ')';
      $sql = 'SELECT * FROM message_templates WHERE ' . $where;
      if (isset($cols['is_active'])) {
        $sql .= " AND (is_active = 1 OR is_active = '1' OR LOWER(TRIM(is_active)) IN ('true','t','yes','on') OR is_active IS NULL)";
      }
      $orderCols = [];
      foreach (['updated_at','created_at','id'] as $oc) { if (isset($cols[$oc])) $orderCols[] = $oc; }
      $orderBy = $orderCols ? (' ORDER BY ' . implode(', ', array_map(function($c){ return $c . ' DESC'; }, $orderCols))) : '';
      $sql .= $orderBy . ' LIMIT 1';
      $st = $pdo->prepare($sql);
      $st->execute([':k' => $key]);
    }

    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row && !empty($nameCols)) {
      // Fallback fuzzy search using LIKE if exact match failed
      $likeConds = [];
      foreach ($nameCols as $c) { $likeConds[] = '`' . $c . '` LIKE :k_like'; }
      $sql2 = 'SELECT * FROM message_templates WHERE (' . implode(' OR ', $likeConds) . ')';
      if (isset($cols['is_active'])) {
        $sql2 .= " AND (is_active = 1 OR is_active = '1' OR LOWER(TRIM(is_active)) IN ('true','t','yes','on') OR is_active IS NULL)";
      }
      $sql2 .= $orderBy . ' LIMIT 1';
      $st2 = $pdo->prepare($sql2);
      $st2->execute([':k_like' => '%' . $key . '%']);
      $row = $st2->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) {
      // Try per-column equality without is_active filter
      foreach (['name','template_name','template_key','key'] as $c) {
        try {
          $sql3 = 'SELECT * FROM message_templates WHERE LOWER(TRIM(`' . $c . '`)) = LOWER(TRIM(:k))' . $orderBy . ' LIMIT 1';
          $st3 = $pdo->prepare($sql3);
          $st3->execute([':k' => $key]);
          $row = $st3->fetch(PDO::FETCH_ASSOC);
          if ($row) break;
        } catch (Throwable $e3) { /* ignore and try next */ }
      }
    }
    if (!$row) {
      // Try per-column LIKE without is_active filter
      foreach (['name','template_name','template_key','key'] as $c) {
        try {
          $sql4 = 'SELECT * FROM message_templates WHERE `' . $c . '` LIKE :k_like' . $orderBy . ' LIMIT 1';
          $st4 = $pdo->prepare($sql4);
          $st4->execute([':k_like' => '%' . $key . '%']);
          $row = $st4->fetch(PDO::FETCH_ASSOC);
          if ($row) break;
        } catch (Throwable $e4) { /* ignore */ }
      }
    }
    if (!$row) return null;
    foreach (['template_content','content','text','body','message','message_content'] as $c) {
      if (isset($row[$c]) && trim((string)$row[$c]) !== '') return (string)$row[$c];
    }
    // Last resort: attempt to infer the content column by picking the longest TEXT-like field
    $best = '';
    foreach ($row as $k => $v) {
      if (is_string($v) && strlen($v) > strlen($best)) { $best = $v; }
    }
    return $best !== '' ? $best : null;
  } catch (Throwable $e) { return null; }
}

function render_template($tpl, $data) {
  // Ensure numeric amounts are formatted as 2-decimals strings
  foreach (['amount','total_amount','paid_amount','remaining_amount'] as $k) {
    if (isset($data[$k]) && $data[$k] !== '' && $data[$k] !== null) {
      $data[$k] = number_format((float)$data[$k], 2);
    }
  }
  // Normalize and enrich context to make template merging robust
  $ctx = is_array($data) ? $data : [];

  // Compute common derived fields
  $now = time();
  if (empty($ctx['time_short'])) {
    $ctx['time_short'] = ar_time_short($now);
  }
  if (empty($ctx['timestamp'])) {
    $ctx['timestamp'] = date('Y-m-d H:i:s');
  }
  if (!empty($ctx['delivery_date'])) {
    $ts = strtotime((string)$ctx['delivery_date']);
    if ($ts) { $ctx['delivery_date_ar'] = to_ar_digits(date('Y-m-d', $ts)); }
  }
  if (!empty($ctx['due_date'])) {
    $ts = strtotime((string)$ctx['due_date']);
    if ($ts) { $ctx['due_date_ar'] = to_ar_digits(date('Y-m-d', $ts)); }
  }
  // Delivery time formatted in Arabic digits HH:mm
  $ctx['delivery_time_ar'] = get_delivery_time_formatted($ctx);

  // Backfill aliases
  $aliases = [
    'orderNumber' => 'order_number',
    'orderId' => 'order_number',
    'service' => 'service_name',
    'serviceType' => 'service_name',
    'customer' => 'customer_name',
    'customer_whatsapp' => 'customer_phone',
    'phone' => 'customer_phone',
    'amount' => 'total_amount',
    'total' => 'total_amount',
    'paid' => 'paid_amount',
    'remaining' => 'remaining_amount',
  ];
  foreach ($aliases as $from => $to) {
    if (!isset($ctx[$from]) && isset($ctx[$to])) { $ctx[$from] = $ctx[$to]; }
  }

  // Build a case-insensitive lookup with multiple variants for each key
  $lookup = [];
  foreach ($ctx as $k => $v) {
    $lookup[$k] = $v;
    $lower = strtolower($k);
    $lookup[$lower] = $v;
    $lookup[str_replace(['_', ' '], '', $lower)] = $v; // snake stripped
  }

  $resolve = function($key) use ($lookup) {
    // Try exact
    if (array_key_exists($key, $lookup)) return $lookup[$key];
    // Try lowercase
    $k = strtolower($key);
    if (array_key_exists($k, $lookup)) return $lookup[$k];
    // Try snake-case to flat and without underscores
    $k2 = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
    $k2 = strtolower($k2);
    if (array_key_exists($k2, $lookup)) return $lookup[$k2];
    $k3 = str_replace(['_', ' '], '', $k2);
    if (array_key_exists($k3, $lookup)) return $lookup[$k3];
    return '';
  };

  $out = (string)$tpl;
  if ($out === '') return '';

  // Handle simple conditionals {{#if var}}...{{/if}}
  $out = preg_replace_callback('/\{\{#if\s+([A-Za-z0-9_]+)\}\}(.*?)\{\{\/if\}\}/s', function($m) use ($resolve) {
    $var = $m[1]; $body = $m[2];
    $val = $resolve($var);
    $truthy = !($val === null || $val === '' || $val === false || $val === 0 || $val === '0');
    return $truthy ? $body : '';
  }, $out);

  // Replace placeholders case-insensitively with alias support
  $out = preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function($m) use ($resolve) {
    $rawKey = $m[1];
    $v = $resolve($rawKey);
    if (is_array($v) || is_object($v)) return '';
    return (string)$v;
  }, $out);

  return $out;
}

// Helper functions for Arabic formatting and payment type mapping
function to_ar_digits($s) {
  $map = ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩'];
  return strtr((string)$s, $map);
}

function ar_time_short($ts) {
  $h = date('h', $ts);
  $m = date('i', $ts);
  $ap = date('A', $ts) === 'AM' ? 'ص' : 'م';
  return to_ar_digits($h . ':' . $m . ' ' . $ap);
}

function map_event_to_template_key($k) {
  $k = strtolower((string)$k);
  $map = [
    'payment_logged_notification' => 'new_payment_notification',
    'notify-new-payment' => 'new_payment_notification',
    'notify-new-order' => 'new_order_notification',
    'notify-payment-delay' => 'payment_delay_notification',
    'notify-delivery-delay' => 'delivery_delay_notification',
  ];
  return $map[$k] ?? $k;
}

function map_payment_type($type) {
  $t = trim(mb_strtolower((string)$type));
  if ($t === '') return '';
  if (strpos($t,'شب')!==false || strpos($t,'network')!==false || strpos($t,'card')!==false || strpos($t,'visa')!==false || strpos($t,'mada')!==false) return '💳 شبكة';
  if (strpos($t,'كاش')!==false || strpos($t,'cash')!==false || strpos($t,'نقد')!==false) return '💵 نقدي';
  if (strpos($t,'تحويل')!==false || strpos($t,'حوال')!==false || strpos($t,'bank')!==false || strpos($t,'transfer')!==false) return '🏦 تحويل بنكي';
  if (in_array($t, ['cash'])) return '💵 نقدي';
  if (in_array($t, ['card'])) return '💳 شبكة';
  if (in_array($t, ['bank_transfer','transfer'])) return '🏦 تحويل بنكي';
  return (string)$type;
}

// Convert a phone number into the international MSISDN format WhatsApp gateways require
// (country code + number, digits only, no leading 0 or +). ultramsg/WaSender silently accept
// a request for a locally-formatted number (e.g. Saudi "0512345678") and often even reply
// {"sent":"true"}, but the message is NEVER delivered because WhatsApp has no such contact.
// This app's context is Saudi Arabia (country code 966), so map the common local formats.
function to_international_msisdn($raw) {
  $d = preg_replace('/\D+/', '', (string)$raw);
  if ($d === '') return '';
  // "00966..." international dialing prefix -> strip the 00
  if (substr($d, 0, 2) === '00') $d = substr($d, 2);
  // Saudi local mobile "05XXXXXXXX" (10 digits) -> "9665XXXXXXXX"
  if (strlen($d) === 10 && substr($d, 0, 2) === '05') return '966' . substr($d, 1);
  // Saudi mobile without leading zero "5XXXXXXXX" (9 digits) -> "9665XXXXXXXX"
  if (strlen($d) === 9 && $d[0] === '5') return '966' . $d;
  // Already looks international (has a country code): leave as-is
  return $d;
}

function normalize_phone_candidate($s) {
  $s = trim((string)$s);
  if ($s === '') return '';
  // Keep leading + and digits only
  $s = preg_replace('/(?!^)[^0-9]/', '', $s); // remove non-digits except first char (handled below)
  // If original had + at start, re-add
  if (strlen($s) > 0 && $s[0] !== '+') {
    // no-op, already digits only
  }
  // Basic sanity: must contain at least 7 digits
  if (!preg_match('/\d{7,}/', $s)) return '';
  return $s;
}

function guess_phone_from_name($name) {
  $name = trim((string)$name);
  if ($name === '') return '';
  try {
    $pdo = pdo();
    // Try exact then LIKE
    $st = $pdo->prepare("SELECT COALESCE(whatsapp, phone) AS ph FROM customers WHERE name = :n ORDER BY id DESC LIMIT 1");
    $st->execute([':n' => $name]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['ph'])) return (string)$row['ph'];
    $st2 = $pdo->prepare("SELECT COALESCE(whatsapp, phone) AS ph FROM customers WHERE name LIKE :n ORDER BY LENGTH(name) ASC, id DESC LIMIT 1");
    $st2->execute([':n' => '%' . $name . '%']);
    $row2 = $st2->fetch(PDO::FETCH_ASSOC);
    if ($row2 && !empty($row2['ph'])) return (string)$row2['ph'];
  } catch (Throwable $e) { /* ignore */ }
  return '';
}

function extract_customer_name_from_message($msg) {
  $msg = (string)$msg;
  // Match lines like: "اسم العميل: ..." or "العميل: ..."
  if (preg_match('/اسم\s*العميل\s*[:：]_?\s*([^\r\n]+)/u', $msg, $m)) {
    return trim($m[1]);
  }
  if (preg_match('/\bالعميل\s*[:：]_?\s*([^\r\n]+)/u', $msg, $m2)) {
    return trim($m2[1]);
  }
  return '';
}

function resolve_phone_for_outstanding($raw, $templateVars, $message) {
  $rawStr = trim((string)$raw);
  // 1) Try raw as phone
  $norm = normalize_phone_candidate($rawStr);
  if ($norm !== '') return $rawStr; // keep original including + if present
  // 2) Try template vars
  $name = '';
  if (is_array($templateVars) && !empty($templateVars['customer_name'])) {
    $name = (string)$templateVars['customer_name'];
  }
  // 3) Try extract from message
  if ($name === '') { $name = extract_customer_name_from_message($message); }
  // 4) Lookup by name
  if ($name !== '') {
    $ph = guess_phone_from_name($name);
    $phNorm = normalize_phone_candidate($ph);
    if ($phNorm !== '') return $ph;
  }
  // 5) Fallback to follow-up default number
  $fallback = get_followup_number();
  $fbNorm = normalize_phone_candidate($fallback);
  return $fbNorm !== '' ? $fallback : '';
}

function ensure_evaluation_for_order($orderId) {
  try {
    $pdo = pdo();
    $st = $pdo->prepare("SELECT customer_id, order_number FROM orders WHERE id = :id LIMIT 1");
    $st->execute([':id' => $orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row || empty($row['customer_id'])) return null;
    $custId = (string)$row['customer_id'];

    $token = '';
    $st2 = $pdo->prepare("SELECT id, evaluation_token FROM evaluations WHERE order_id = :id LIMIT 1");
    $st2->execute([':id' => $orderId]);
    $ev = $st2->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($ev && !empty($ev['evaluation_token'])) {
      $token = (string)$ev['evaluation_token'];
    } else {
      $token = md5($orderId . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
      if ($ev && !empty($ev['id'])) {
        $pdo->prepare("UPDATE evaluations SET evaluation_token = :t, sent_at = NULL WHERE id = :id")->execute([':t' => $token, ':id' => $ev['id']]);
      } else {
        $id = generate_uuid_v4();
        $pdo->prepare("INSERT INTO evaluations (id, customer_id, order_id, evaluation_token, created_at) VALUES (:id, :c, :o, :t, NOW())")
            ->execute([':id' => $id, ':c' => $custId, ':o' => $orderId, ':t' => $token]);
      }
    }

    $base = 'https://fdert1.net/evaluation/';
    $url = $base . $token;
    $code = strtoupper(substr($token, -5));
    return [ 'evaluation_url' => $url, 'evaluation_code' => $code ];
  } catch (Throwable $e) { return null; }
}

function build_payments_section_text($orderId) {
  try {
    $pdo = pdo();
    $cols = get_table_columns('payments');
    $selects = ['amount'];
    if (isset($cols['payment_method'])) {
      $selects[] = 'payment_method';
    }
    if (isset($cols['payment_date'])) {
      $selects[] = 'payment_date';
    }
    
    $orderBy = [];
    if (isset($cols['created_at'])) {
      $orderBy[] = '`created_at` ASC';
    }
    if (isset($cols['id'])) {
      $orderBy[] = '`id` ASC';
    }

    $sql = "SELECT " . implode(', ', $selects) . " FROM `payments` WHERE `order_id` = :id";
    if (!empty($orderBy)) {
      $sql .= " ORDER BY " . implode(', ', $orderBy);
    }

    $st = $pdo->prepare($sql);
    $st->execute([':id' => $orderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return "لا توجد دفعات مسجلة";
    
    $lines = [];
    foreach ($rows as $p) {
      $amt = isset($p['amount']) ? number_format((float)$p['amount'], 2) : '0.00';
      $method = map_payment_type($p['payment_method'] ?? '');
      $date = trim((string)($p['payment_date'] ?? ''));
      $dateAr = $date !== '' ? to_ar_digits(date('Y-m-d', strtotime($date))) : '';
      $parts = ["• {$amt} ر.س"];
      if ($method !== '') $parts[] = $method;
      if ($dateAr !== '') $parts[] = $dateAr;
      $lines[] = implode(' - ', $parts);
    }
    return implode("\n", $lines);
  } catch (Throwable $e) { return "لا توجد دفعات مسجلة"; }
}

function get_delivery_time_formatted($ctx) {
  $t = trim((string)($ctx['delivery_time'] ?? ''));
  if ($t === '' || strtolower($t) === 'null') $t = trim((string)($ctx['estimated_delivery_time'] ?? ''));
  if ($t === '' || strtolower($t) === 'null') $t = '17:00';
  if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
    $h = (int)$m[1]; $min = $m[2];
    $hh = sprintf('%02d', $h);
    return to_ar_digits($hh . ':' . $min);
  }
  return to_ar_digits($t);
}

// Seed default templates to guarantee exact formats when templates are missing
function ensure_default_templates() {
  // Ensure defaults are present and do not trigger unrelated templates during tests
  try {
    $pdo = pdo();
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS message_templates (
        id VARCHAR(255) PRIMARY KEY,
        template_key VARCHAR(191) UNIQUE,
        template_content LONGTEXT NULL,
        is_active TINYINT(1) DEFAULT 1,
        updated_at DATETIME NULL,
        created_at DATETIME NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ignore */ }

    $defaults = [
      'payment_logged_notification' =>
        "💰 إشعار: تسجيل دفعة جديدة\n\n" .
        "📦 رقم الطلب: {{order_number}}\n\n" .
        "👤 العميل: {{customer_name}}\n\n" .
        "📱 واتساب العميل: {{customer_phone}}\n\n" .
        "━━━━━━━━━━━━━━━━━━━━\n\n" .
        "💵 تفاصيل الدفعة:\n\n" .
        "• المبلغ المدفوع: {{amount}} ر.س\n\n" .
        "• طريقة الدفع: {{payment_method}}\n\n" .
        "• تاريخ الدفع: {{payment_date}}\n\n" .
        "━━━━━━━━━━━━━━━━━━━━\n\n" .
        "📊 حالة الطلب:\n\n" .
        "• إجمالي الطلب: {{total_amount}} ر.س\n\n" .
        "• المبلغ المدفوع: {{paid_amount}} ر.س\n\n" .
        "• المتبقي: {{remaining_amount}} ر.س\n\n" .
        "• الحالة: {{status}}\n\n" .
        "⏰ {{time_short}}",

      'test_follow_up_system' =>
        "🧪 هذه رسالة اختبار\n\n" .
        "💰 تنبيه: تأخير في الدفعات\n\n" .
        "👤 اسم العميل: {{customer_name}}\n\n" .
        "📱 رقم الواتساب: {{customer_phone}}\n\n" .
        "💵 الرصيد المستحق: {{balance_due}} ريال\n\n" .
        "📦 أقدم طلب: {{oldest_order_number}}\n\n" .
        "📅 تاريخ الطلب: {{oldest_order_date_ar}}\n\n" .
        "⏱ مر على الطلب: {{oldest_order_age}}\n\n" .
        "يرجى المتابعة مع العميل لتحصيل المستحقات.\n\n" .
        "🎉 طلب جديد\n\n" .
        "📦 رقم الطلب: {{order_number}}\n\n" .
        "👤 معلومات العميل:\n\n" .
        "• الاسم: {{customer_name}}\n\n" .
        "• الجوال: {{customer_phone}}\n\n" .
        "🔧 تفاصيل الطلب:\n\n" .
        "• الخدمة: {{service_name}}\n\n" .
        "• الوصف: {{order_description}}\n\n" .
        "• الحالة: {{status}}\n\n" .
        "• تاريخ الاستحقاق: {{due_date_ar}}\n\n" .
        "💰 المبالغ المالية:\n\n" .
        "• المبلغ الإجمالي: {{total_amount}} ريال\n\n" .
        "• المبلغ المدفوع: {{paid_amount}} ريال\n\n" .
        "• المبلغ المتبقي: {{remaining_amount}} ريال\n\n" .
        "📋 بنود الطلب:\n\n" .
        "{{order_items}}\n\n" .
        "⏰ تاريخ الإنشاء: {{created_at_ar}} {{created_time_ar}}\n\n" .
        "🧪 هذه رسالة اختبار",

      // Seed missing follow-up templates to ensure all events work out of the box
      'new_payment_notification' =>
        "💵 دفعة جديدة\n\n" .
        "💰 المبلغ: {{amount}} ريال\n\n" .
        "📦 رقم الطلب: {{order_number}}\n\n" .
        "👤 اسم العميل: {{customer_name}}\n\n" .
        "💳 نوع الدفع: {{payment_method}}\n\n" .
        "📊 حالة الطلب:\n\n" .
        "• إجمالي الطلب: {{total_amount}} ريال\n\n" .
        "• المدفوع: {{paid_amount}} ريال\n\n" .
        "• المتبقي: {{remaining_amount}} ريال\n\n" .
        "⏰ وقت الدفع: {{timestamp}}",

      'new_expense_notification' =>
        "🧾 تم تسجيل مصروف جديد\n\n" .
        "القسم: {{category}}\n\n" .
        "الوصف: {{description}}\n\n" .
        "المبلغ: {{amount}} ر.س\n\n" .
        "⏰ {{timestamp}}",

      'delivery_delay_notification' =>
        "⚠ تنبيه: تجاوز فترة التسليم\n\n" .
        "📦 رقم الطلب: {{order_number}}\n\n" .
        "👤 اسم العميل: {{customer_name}}\n\n" .
        "📅 تاريخ التسليم المتوقع: {{delivery_date}}\n\n" .
        "⏱ تأخير: {{delay_days}}+ أيام\n\n" .
        "يرجى المتابعة الفورية مع العميل.",

      'payment_delay_notification' =>
        "💰 تنبيه تأخر دفعات\n\n" .
        "العميل: {{customer_name}}\n\n" .
        "واتساب: {{customer_whatsapp}}\n\n" .
        "أقدم طلب: {{order_number}}\n\n" .
        "تاريخ الطلب: {{order_date}}\n\n" .
        "عدد أيام التأخير: {{delay_days}}\n\n" .
        "⏰ {{timestamp}}",

      'outstanding_balance_report' =>
        "📊 تقرير مالي يومي\n\n" .
        "التاريخ: {{report_date}}\n\n" .
        "إجمالي المدفوعات: {{total_due}}\n\n" .
        "عدد الطلبات غير المسددة: {{unpaid_orders_count}}\n\n" .
        "أقدم تاريخ استحقاق: {{earliest_due_date}}\n\n" .
        "\nالدفعات:\n{{payments_section}}\n\n" .
        "المصروفات:\n{{payments_section}}"
    ];

    foreach ($defaults as $k => $content) {
      try {
        $st = $pdo->prepare("SELECT 1 FROM message_templates WHERE template_key = :k LIMIT 1");
        $st->execute([':k' => $k]);
        $exists = (bool)$st->fetchColumn();
        if (!$exists) {
          $stI = $pdo->prepare("INSERT INTO message_templates (id, template_key, template_content, is_active, created_at, updated_at) VALUES (:id, :k, :c, 1, NOW(), NOW())");
          $stI->execute([':id' => generate_uuid_v4(), ':k' => $k, ':c' => $content]);
        }
      } catch (Throwable $eIns) { /* ignore */ }
    }
  } catch (Throwable $e) { /* ignore */ }
}

function get_company_name() {
  ensure_default_templates();
  try {
    $st = pdo()->prepare("SELECT value FROM website_settings WHERE `key` = 'website_content' LIMIT 1");
    $st->execute(); $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['value'])) { $conf = json_decode($row['value'], true); if (!empty($conf['siteName'])) return (string)$conf['siteName']; }
  } catch (Throwable $e) { /* ignore */ }
  return '';
}

function build_order_items_section($orderId) {
  try {
    $pdo = pdo();
    // Build ORDER BY dynamically based on available columns to avoid failures on hosts missing created_at
    $cols = get_table_columns('order_items');
    $orderParts = [];
    if (isset($cols['created_at'])) { $orderParts[] = '`created_at` ASC'; }
    if (isset($cols['id'])) { $orderParts[] = '`id` ASC'; }
    $sql = "SELECT item_name, description, quantity, unit_price, total FROM `order_items` WHERE `order_id` = :id";
    if (!empty($orderParts)) { $sql .= " ORDER BY " . implode(', ', $orderParts); }
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $orderId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$items) return 'لا توجد بنود مسجلة';
    $i = 1; $lines = [];
    foreach ($items as $it) {
      $name = (string)($it['item_name'] ?? 'بند');
      $qty = (float)($it['quantity'] ?? 0);
      $price = (float)($it['unit_price'] ?? 0);
      $tot = (float)($it['total'] ?? ($qty * $price));
      $desc = trim((string)($it['description'] ?? ''));
      $lines[] = $i . '. ' . $name . "\n" .
                 'الكمية: ' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . "\n" .
                 'السعر: ' . number_format($price, 2) . ' ريال' . "\n" .
                 'الإجمالي: ' . number_format($tot, 2) . ' ريال' . ($desc !== '' ? ("\nالوصف: " . $desc) : '');
      $i++;
    }
    return implode("\n\n", $lines);
  } catch (Throwable $e) { return 'لا توجد بنود مسجلة'; }
}

function get_order_context($orderId) {
  $ctx = [];
  try {
    $pdo = pdo();
    // SECURITY (multi-tenant): verify this order actually belongs to the
    // caller's own tenant before returning any of its data — otherwise a
    // tenant could read (and even trigger a WhatsApp send of) another
    // tenant's order/customer details just by guessing/enumerating order_id.
    $__tid = tenant_is_platform_admin() ? null : tenant_current_id();
    $sql = "SELECT o.*, c.name AS customer_name, COALESCE(c.whatsapp, c.phone) AS customer_phone, s.name AS service_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id LEFT JOIN service_types s ON o.service_type_id = s.id WHERE o.id = :id" . ($__tid !== null ? " AND o.tenant_id = :tid" : "") . " LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($__tid !== null ? [':id' => $orderId, ':tid' => $__tid] : [':id' => $orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$o) return $ctx;
    $ctx['order_number'] = (string)($o['order_number'] ?? '');
    $ctx['customer_name'] = (string)($o['customer_name'] ?? '');
    $ctx['delivery_date'] = (string)($o['delivery_date'] ?? '');
    $ctx['estimated_delivery_time'] = (string)($o['estimated_delivery_time'] ?? '');
    $ctx['delivery_time'] = (string)($o['delivery_time'] ?? '');
    // تأكد من أن delivery_time موجود إذا كان estimated_delivery_time فارغاً
    if (($ctx['delivery_time'] === '' || strtolower($ctx['delivery_time']) === 'null') && $ctx['estimated_delivery_time'] !== '' && strtolower($ctx['estimated_delivery_time']) !== 'null') {
      $ctx['delivery_time'] = $ctx['estimated_delivery_time'];
    }
    $amount = isset($o['total_amount']) ? (float)$o['total_amount'] : (isset($o['amount']) ? (float)$o['amount'] : 0.0);
    $ctx['amount'] = number_format($amount, 2);
    $ctx['total_amount'] = number_format($amount, 2);
    // Sum paid amounts
    $paid = 0.0;
    try {
      $st2 = $pdo->prepare("SELECT SUM(amount) AS s FROM payments WHERE order_id = :id");
      $st2->execute([':id' => $orderId]); $row2 = $st2->fetch(PDO::FETCH_ASSOC); $paid = (float)($row2['s'] ?? 0);
    } catch (Throwable $e2) { $paid = 0.0; }
    $ctx['paid_amount'] = number_format($paid, 2);
    $ctx['remaining_amount'] = number_format(max(0, $amount - $paid), 2);
    $ctx['order_items'] = build_order_items_section($orderId);
    $ctx['company_name'] = get_company_name();
    $ctx['status'] = (string)($o['status'] ?? '');
    // Optional fields that may not exist
    $ctx['service_name'] = (string)($o['service_name'] ?? ($o['service_name'] ?? 'غير محدد'));
    $ctx['order_description'] = (string)($o['description'] ?? ($o['notes'] ?? 'غير محدد'));
    if ($ctx['delivery_date'] === '' || $ctx['delivery_date'] === null) {
      $ctx['delivery_date'] = 'سيتم تحديده';
    }
    if ($ctx['estimated_delivery_time'] === '' || strtolower((string)$ctx['estimated_delivery_time']) === 'null') {
      $ctx['estimated_delivery_time'] = '17:00';
    }
  } catch (Throwable $e) { /* ignore */ }
  return $ctx;
}

function map_status_to_template_key($status) {
  $s = trim((string)$status);
  // Try Arabic and English common statuses
  $map = [
    'قيد المراجعة' => 'order_under_review',
    'جاهز للتسليم' => 'order_ready_for_delivery',
    'قيد التنفيذ' => 'order_in_progress',
    'معلق' => 'order_on_hold',
    'مكتمل' => 'order_completed',
    'ملغي' => 'order_cancelled',
    'confirmed' => 'order_confirmed',
    'in_progress' => 'order_in_progress',
    'under_review' => 'order_under_review',
    'ready_for_delivery' => 'order_ready_for_delivery',
    'on_hold' => 'order_on_hold',
    'completed' => 'order_completed',
    'cancelled' => 'order_cancelled'
  ];
  if (isset($map[$s])) return $map[$s];
  return '';
}

function resolve_webhook_for_message_type($type) {
  $pdo = pdo();
  $t = trim((string)$type);
  // Order event types
  $orderTypes = ['order_created','order_confirmed','order_in_progress','order_under_review','order_ready_for_delivery','order_on_hold','order_status_updated','order_completed','order_cancelled'];
  try {
    if (in_array($t, $orderTypes, true)) {
      // Specific webhook with matching order_statuses
      try {
        $st = $pdo->prepare("SELECT webhook_url FROM webhook_settings WHERE (is_active = 1 OR is_active = '1' OR LOWER(is_active) IN ('true','t','yes','on')) AND order_statuses LIKE :pat AND webhook_url NOT LIKE '%/webhook-test/%' ORDER BY updated_at DESC, created_at DESC LIMIT 1");
        $st->execute([':pat' => '%' . $t . '%']);
        $row = $st->fetch(PDO::FETCH_ASSOC); if ($row && !empty($row['webhook_url'])) return (string)$row['webhook_url'];
      } catch (Throwable $e) { /* ignore */ }
      // Fallback to any active 'outgoing'
      try {
        $st = $pdo->query("SELECT webhook_url FROM webhook_settings WHERE webhook_type = 'outgoing' AND (is_active = 1 OR is_active = '1' OR LOWER(is_active) IN ('true','t','yes','on')) AND webhook_url NOT LIKE '%/webhook-test/%' ORDER BY updated_at DESC, created_at DESC LIMIT 1");
        $row = $st->fetch(PDO::FETCH_ASSOC); if ($row && !empty($row['webhook_url'])) return (string)$row['webhook_url'];
      } catch (Throwable $e) { /* ignore */ }
    }
    if ($t === 'outstanding_balance_report') {
      try {
        $st = $pdo->query("SELECT webhook_url FROM webhook_settings WHERE webhook_type = 'outstanding_balance_report' AND (is_active = 1 OR is_active = '1' OR LOWER(is_active) IN ('true','t','yes','on')) AND webhook_url NOT LIKE '%/webhook-test/%' ORDER BY updated_at DESC, created_at DESC LIMIT 1");
        $row = $st->fetch(PDO::FETCH_ASSOC); if ($row && !empty($row['webhook_url'])) return (string)$row['webhook_url'];
      } catch (Throwable $e) { /* ignore */ }
    }
    // Default: follow-up webhook (internal)
    try {
      $st = $pdo->query("SELECT follow_up_webhook_url FROM follow_up_settings WHERE follow_up_webhook_url IS NOT NULL AND follow_up_webhook_url <> '' ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1");
      $row = $st->fetch(PDO::FETCH_ASSOC); if ($row && !empty($row['follow_up_webhook_url'])) return (string)$row['follow_up_webhook_url'];
    } catch (Throwable $e) { /* ignore */ }
    // Last fallback: any active outgoing
    try {
      $st = $pdo->query("SELECT webhook_url FROM webhook_settings WHERE (is_active = 1 OR is_active = '1' OR LOWER(is_active) IN ('true','t','yes','on')) AND webhook_url NOT LIKE '%/webhook-test/%' ORDER BY updated_at DESC, created_at DESC LIMIT 1");
      $row = $st->fetch(PDO::FETCH_ASSOC); if ($row && !empty($row['webhook_url'])) return (string)$row['webhook_url'];
    } catch (Throwable $e) { /* ignore */ }
  } catch (Throwable $e) { /* ignore */ }
  return '';
}

function send_order_template_message($orderId, $eventKey, $options = []) {
  try {
    if (!$orderId || !$eventKey) return false;
    $force = !empty($options['force']);
    $toOverride = isset($options['to']) ? trim((string)$options['to']) : '';
    $ctxOverride = is_array($options['context'] ?? null) ? $options['context'] : [];
    $tpl = get_template_content($eventKey);
    // Dynamic only: no hardcoded fallback templates
    // Dynamic only: no hardcoded fallback templates
    if (!$tpl) return false;
    $ctx = get_order_context($orderId);
    if (!empty($ctxOverride)) { $ctx = array_merge($ctx, $ctxOverride); }
    // Ensure delivery_time_formatted exists for all events
    if (empty($ctx['delivery_time_formatted'])) {
      $ctx['delivery_time_formatted'] = get_delivery_time_formatted($ctx);
      if (empty($ctx['delivery_time_formatted'])) { $ctx['delivery_time_formatted'] = to_ar_digits('17:00'); }
    }
    // If items are not yet inserted (race condition), retry briefly to fetch them
    if (empty($ctx['order_items']) || $ctx['order_items'] === 'لا توجد بنود مسجلة') {
      // Retry logic for new orders where items might not be saved yet
      if (in_array($eventKey, ['order_created', 'order_confirmed'], true)) {
        for ($__i = 0; $__i < 3; $__i++) {
          usleep(300000); // 300ms
          $oi = build_order_items_section($orderId);
          if ($oi !== 'لا توجد بنود مسجلة') {
            $ctx['order_items'] = $oi;
            break;
          }
        }
      }
    }

    // If still not found, use a more user-friendly placeholder.
    if (empty($ctx['order_items']) || $ctx['order_items'] === 'لا توجد بنود مسجلة') {
        $ctx['order_items'] = 'سيتم تزويدكم ببنود الطلب قريباً.';
    }
    if (strtolower((string)$eventKey) === 'order_completed') {
      if (empty($ctx['total_amount'])) { $ctx['total_amount'] = $ctx['amount'] ?? ''; }
      // Ensure evaluation link/code
      $ev = ensure_evaluation_for_order($orderId);
      if (is_array($ev)) {
        $ctx['evaluation_url'] = $ev['evaluation_url'] ?? '';
        $ctx['evaluation_code'] = $ev['evaluation_code'] ?? '';
      }
      if (empty($ctx['evaluation_url'])) {
        try {
          $stTok = pdo()->prepare("SELECT evaluation_token FROM evaluations WHERE order_id = :id ORDER BY created_at DESC LIMIT 1");
          $stTok->execute([':id' => $orderId]);
          $tok = (string)($stTok->fetchColumn() ?: '');
          if ($tok === '') {
            // Force-generate token and upsert
            $tok = md5($orderId . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
            // Try update existing evaluation row or insert new
            try {
              $stEv = pdo()->prepare("SELECT id FROM evaluations WHERE order_id = :id ORDER BY created_at DESC LIMIT 1");
              $stEv->execute([':id' => $orderId]);
              $evRow = $stEv->fetch(PDO::FETCH_ASSOC) ?: [];
              if (!empty($evRow['id'])) {
                pdo()->prepare("UPDATE evaluations SET evaluation_token = :t WHERE id = :eid")->execute([':t' => $tok, ':eid' => $evRow['id']]);
              } else {
                // Fetch customer_id for order
                $stCust = pdo()->prepare("SELECT customer_id FROM orders WHERE id = :id LIMIT 1");
                $stCust->execute([':id' => $orderId]);
                $custId = (string)($stCust->fetchColumn() ?: '');
                pdo()->prepare("INSERT INTO evaluations (id, customer_id, order_id, evaluation_token, created_at) VALUES (:id, :c, :o, :t, NOW())")
                   ->execute([':id' => generate_uuid_v4(), ':c' => $custId, ':o' => $orderId, ':t' => $tok]);
              }
            } catch (Throwable $eUp) { /* ignore */ }
          }
          if ($tok !== '') {
            $ctx['evaluation_url'] = 'https://fdert1.net/evaluation/' . $tok;
            if (empty($ctx['evaluation_code'])) { $ctx['evaluation_code'] = strtoupper(substr($tok, -5)); }
          }
        } catch (Throwable $e) { /* ignore */ }
      }
      // Payments section (always non-empty)
      $ptext = build_payments_section_text($orderId);
      if (!is_string($ptext) || trim($ptext) === '') { $ptext = 'لا توجد دفعات مسجلة'; }
      $ctx['payments_section_text'] = $ptext;
      $ctx['has_payments'] = ($ptext !== 'لا توجد دفعات مسجلة');
      // Delivery time formatted (fallback to 17:00)
      $ctx['delivery_time_formatted'] = get_delivery_time_formatted($ctx);
      if (empty($ctx['delivery_time_formatted'])) { $ctx['delivery_time_formatted'] = to_ar_digits('17:00'); }
      if (empty($ctx['customer_name'])) $ctx['customer_name'] = 'العميل';
    }
    $message = render_template($tpl, $ctx);
    // Post-process message to enforce missing parts
    try {
      // 1) Ensure time appears after 'الساعة :'
      if (strpos($message, 'الساعة :') !== false) {
        $time = (string)($ctx['delivery_time_formatted'] ?? '');
        if ($time === '') { $time = to_ar_digits('17:00'); }
        $message = preg_replace_callback('/الساعة\s*:\s*(\r?\n)/u', function($m) use ($time){ return 'الساعة : ' . $time . "\n"; }, $message, 1);
      }
      // 2) Ensure payments section has content
      if (strpos($message, '💰 الدفعات:') !== false) {
        $pay = (string)($ctx['payments_section_text'] ?? '');
        if (trim($pay) === '') { $pay = 'لا توجد دفعات مسجلة'; }
        // If no text exists after header, inject it
        if (preg_match('/💰 الدفعات:\s*(\r?\n){1,2}(?=\S|$)/u', $message)) {
          $message = preg_replace('/💰 الدفعات:\s*(\r?\n){1,2}/u', "💰 الدفعات:\n" . $pay . "\n\n", $message, 1);
        }
      }
      // 3) Ensure evaluation URL line exists below the sentence
      if (strpos($message, 'نرجو تقييم تجربتك معنا عبر الرابط التالي:') !== false) {
        $evalUrl = (string)($ctx['evaluation_url'] ?? '');
        if ($evalUrl === '') {
          // Final fallback: generate token and upsert, then build URL
          try {
            $tok = md5($orderId . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
            $stEv = pdo()->prepare("SELECT id FROM evaluations WHERE order_id = :id ORDER BY created_at DESC LIMIT 1");
            $stEv->execute([':id' => $orderId]);
            $evRow = $stEv->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($evRow['id'])) {
              pdo()->prepare("UPDATE evaluations SET evaluation_token = :t WHERE id = :eid")->execute([':t' => $tok, ':eid' => $evRow['id']]);
            } else {
              $stCust = pdo()->prepare("SELECT customer_id FROM orders WHERE id = :id LIMIT 1");
              $stCust->execute([':id' => $orderId]);
              $custId = (string)($stCust->fetchColumn() ?: '');
              pdo()->prepare("INSERT INTO evaluations (id, customer_id, order_id, evaluation_token, created_at) VALUES (:id, :c, :o, :t, NOW())")
                 ->execute([':id' => generate_uuid_v4(), ':c' => $custId, ':o' => $orderId, ':t' => $tok]);
            }
            $evalUrl = 'https://fdert1.net/evaluation/' . $tok;
          } catch (Throwable $eGen) { $evalUrl = ''; }
        }
        if ($evalUrl !== '') {
          $message = preg_replace_callback('/(نرجو تقييم تجربتك معنا عبر الرابط التالي:)\s*(\r?\n)+/u', function($m) use ($evalUrl){ return $m[1] . "\n" . $evalUrl . "\n\n"; }, $message, 1);
        }
      }
    } catch (Throwable $eFix) { /* ignore */ }
    // Determine recipient
    $to = '';
    try {
      $st = pdo()->prepare("SELECT c.whatsapp, c.phone FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.id = :id LIMIT 1");
      $st->execute([':id' => $orderId]); $row = $st->fetch(PDO::FETCH_ASSOC);
      $to = trim((string)($row['whatsapp'] ?? '')); if ($to === '') $to = trim((string)($row['phone'] ?? ''));
    } catch (Throwable $e2) { /* ignore */ }
    if ($to === '' && $toOverride !== '') { $to = $toOverride; }
    if ($to === '' && !empty($ctx['customer_phone'])) { $to = (string)$ctx['customer_phone']; }
    if ($to === '') return false;
    // Enqueue for customer with stable dedupe to avoid duplicates from multiple sources
    ensure_whatsapp_schema();
    $pdo = pdo();
    // Build stable dedupe: cust|event|order_number
    $ordNo = '';
    try {
      $stOrd = $pdo->prepare("SELECT order_number FROM orders WHERE id = :id LIMIT 1");
      $stOrd->execute([':id' => $orderId]);
      $ordNo = (string)($stOrd->fetchColumn() ?: '');
    } catch (Throwable $eO) { $ordNo = ''; }
    // Build dedupe; for completed status allow multiple sends per day by appending date-hour to dedupe
    $baseDedupe = 'customer|' . strtolower((string)$eventKey) . '|' . $ordNo;
    $dedupe = $baseDedupe;
    if (strtolower((string)$eventKey) === 'order_completed') {
      // permit once per hour for completed status
      $dedupe = $baseDedupe . '|' . date('YmdH');
    }
    if ($force) {
      $dedupe = $baseDedupe . '|force-' . date('YmdHis');
    } else {
      // Duplicate handling: allow resend unless a recent 'sent' exists
      try {
        $mins = (strtolower((string)$eventKey) === 'order_completed') ? 60 : 1440;
        $cutoff = date('Y-m-d H:i:s', time() - ($mins * 60));
        $stChk = $pdo->prepare("SELECT id, status, created_at FROM whatsapp_messages WHERE dedupe_key = :dk AND to_number = :to ORDER BY created_at DESC LIMIT 1");
        $stChk->bindValue(':dk', $dedupe);
        $stChk->bindValue(':to', (string)$to);
        $stChk->execute();
        $ex = $stChk->fetch(PDO::FETCH_ASSOC);
        if ($ex && !empty($ex['id'])) {
          $stStr = strtolower((string)($ex['status'] ?? ''));
          $createdAtTs = strtotime((string)($ex['created_at'] ?? '')) ?: 0;
          $recent = ($createdAtTs > strtotime($cutoff));
          if ($stStr === 'sent' && $recent) {
            if (strtolower((string)$eventKey) === 'order_completed') {
              // Force resend for completed status with unique dedupe
              $dedupe = $dedupe . '|resend-' . date('YmdHis');
            } else {
              // Already sent recently -> skip
              return true;
            }
          } elseif ($stStr === 'pending') {
            // Try to process existing pending
            try { process_whatsapp_queue(20); } catch (Throwable $eProc) { /* ignore */ }
            return true;
          } else {
            // For failed or old messages -> retry with unique dedupe suffix
            $dedupe = $dedupe . '|retry-' . date('His');
          }
        }
      } catch (Throwable $eD) { /* ignore */ }
    }
    $id = generate_uuid_v4();
    $st2 = $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, created_at, dedupe_key) VALUES (:id, :from_number, :to_number, :message_type, :message_content, :status, NOW(), :dedupe)");
    $st2->execute([
      ':id' => $id,
      ':from_number' => 'system',
      ':to_number' => (string)$to,
      ':message_type' => (string)$eventKey,
      ':message_content' => (string)$message,
      ':status' => 'pending',
      ':dedupe' => $dedupe,
    ]);

    return true;
  } catch (Throwable $e) { return false; }
}

function followup_template_candidates($eventKey) {
  $map = [
    'new_order_notification' => ['new_order_notification'],
    'invoice_notification' => ['invoice_notification','new_invoice_notification'],
    'payment_logged_notification' => ['new_payment_notification','payment_logged_notification'],
    'new_payment_notification' => ['new_payment_notification','payment_logged_notification'],
    'new_expense_notification' => ['new_expense_notification','expense_logged_notification'],
    'order_status_changed' => ['order_status_changed'],
    'order_updated' => ['order_updated'],
    'delivery_delay_notification' => ['delivery_delay_notification'],
    'payment_delay_notification' => ['payment_delay_notification'],
    'test_follow_up_system' => ['test_follow_up_system'],
    'outstanding_balance_report' => ['outstanding_balance_report'],
    'task_transfer' => ['task_transfer'],
  ];
  return $map[$eventKey] ?? [$eventKey];
}

function send_followup_event($eventKey, $context = [], $to = null, $force = false) {
  try {
    $pdo = pdo();
    ensure_default_templates();
    $tplKey = map_event_to_template_key($eventKey);

    // Enrich payment notifications from DB when order_id/order_number available
    if (in_array(strtolower($tplKey), ['new_payment_notification','payment_logged_notification'], true)) {
      $orderId = (string)($context['order_id'] ?? '');
      $orderNumber = (string)($context['order_number'] ?? '');
      if ($orderId === '' && $orderNumber !== '') {
        try { $st = $pdo->prepare("SELECT id FROM orders WHERE order_number = :n LIMIT 1"); $st->execute([':n'=>$orderNumber]); $orderId = (string)($st->fetchColumn() ?: ''); } catch (Throwable $e) {}
      }
      if ($orderId !== '') {
        try {
          $st = $pdo->prepare("SELECT o.order_number, o.total_amount, o.paid_amount, o.customer_id, c.name AS customer_name, COALESCE(c.whatsapp,c.phone) AS customer_phone FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = :id LIMIT 1");
          $st->execute([':id'=>$orderId]);
          $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
          if (!empty($row)) {
            $context['order_number'] = $row['order_number'] ?? ($context['order_number'] ?? '');
            $context['customer_name'] = $row['customer_name'] ?? ($context['customer_name'] ?? '');
            $context['customer_phone'] = $row['customer_phone'] ?? ($context['customer_phone'] ?? '');
            // Pull totals; if not reliable, compute fallbacks
            $tot = (float)($row['total_amount'] ?? 0);
            $paid = (float)($row['paid_amount'] ?? 0);
            try {
              if ($paid <= 0.00001) {
                $sp = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = :id");
                $sp->execute([':id'=>$orderId]);
                $sumPaid = (float)($sp->fetchColumn() ?: 0);
                if ($sumPaid > 0) { $paid = $sumPaid; }
              }
            } catch (Throwable $eSum) { /* ignore */ }
            try {
              if ($tot <= 0.00001) {
                $stt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM order_items WHERE order_id = :id");
                $stt->execute([':id'=>$orderId]);
                $sumTot = (float)($stt->fetchColumn() ?: 0);
                if ($sumTot > 0) { $tot = $sumTot; }
              }
            } catch (Throwable $eTot) { /* ignore */ }
            $context['total_amount'] = number_format($tot, 2);
            $context['paid_amount'] = number_format($paid, 2);
            $context['remaining_amount'] = number_format(max(0, $tot - $paid), 2);
          }
          // If still missing amounts, fallback to amount field when provided
          if (empty($context['paid_amount']) && isset($context['amount'])) {
            $amt = (float)$context['amount'];
            if (empty($context['total_amount']) || (float)str_replace(',','',$context['total_amount']) <= 0) {
              $context['total_amount'] = number_format($amt, 2);
              $context['paid_amount'] = number_format($amt, 2);
              $context['remaining_amount'] = number_format(0, 2);
            } else {
              $totNum = (float)str_replace(',','',$context['total_amount']);
              $context['paid_amount'] = number_format($amt, 2);
              $context['remaining_amount'] = number_format(max(0, $totNum - $amt), 2);
            }
          }
        } catch (Throwable $e) { /* ignore enrich errors */ }
      }
      // Normalize payment_type alias to payment_method placeholder expected in some templates
      if (!empty($context['payment_type']) && empty($context['payment_method'])) {
        $context['payment_method'] = map_payment_type($context['payment_type']);
      } elseif (!empty($context['payment_method'])) {
        $context['payment_method'] = map_payment_type($context['payment_method']);
      }
    }

    // Fill defaults for delay tests when fields missing
    if (strtolower($tplKey) === 'payment_delay_notification') {
      if (empty($context['customer_name'])) $context['customer_name'] = 'عميل';
      if (empty($context['customer_whatsapp']) && !empty($context['customer_phone'])) $context['customer_whatsapp'] = $context['customer_phone'];
      if (empty($context['customer_whatsapp'])) $context['customer_whatsapp'] = get_followup_number();
      if (empty($context['order_number'])) $context['order_number'] = 'ORD-TEST-' . date('Ymd');
      if (empty($context['order_date'])) $context['order_date'] = date('Y-m-d');
      if (empty($context['delay_days'])) $context['delay_days'] = '7';
    }
    if (strtolower($tplKey) === 'delivery_delay_notification') {
      if (empty($context['order_number'])) $context['order_number'] = 'ORD-TEST-' . time();
      if (empty($context['customer_name'])) $context['customer_name'] = 'عميل';
      if (empty($context['delivery_date'])) $context['delivery_date'] = date('Y-m-d');
      if (empty($context['delay_days'])) $context['delay_days'] = '1';
    }

    // Render and enqueue
    $tpl = get_template_content($tplKey);
    if (!$tpl) { return ['error' => 'Template not found', 'template' => $tplKey]; }
    $message = render_template($tpl, $context);
    $dest = trim((string)($to ?: ($context['customer_phone'] ?? '')));
    if ($dest === '') { $dest = get_followup_number(); }
    if ($dest === '') { return ['processed' => 0, 'errors' => 1, 'message' => 'Missing destination phone']; }
    ensure_whatsapp_schema();
    $id = generate_uuid_v4();
    $dedupe = strtolower($tplKey) . '|' . md5($dest . '|' . ($context['order_number'] ?? '')) . '|' . date('YmdHis');
    $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, created_at, dedupe_key) VALUES (:id,'system',:to,:type,:msg,'pending',NOW(),:dk)")
        ->execute([':id'=>$id, ':to'=>$dest, ':type'=>$tplKey, ':msg'=>$message, ':dk'=>$dedupe]);
    return process_whatsapp_queue(20);
  } catch (Throwable $e) {
    return ['processed' => 0, 'errors' => 1, 'message' => $e->getMessage()];
  }

  try {
    ensure_default_templates();
    // Normalize context: flatten {result: x} payloads and scalarize arrays
    if (is_array($context)) {
      foreach ($context as $k => $v) {
        if (is_array($v) && array_key_exists('result', $v)) { $context[$k] = $v['result']; continue; }
        if (is_array($v)) { $context[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
      }
    }
    $cands = followup_template_candidates($eventKey);
    $tpl = null; $tplKey = null;
    foreach ($cands as $k) { $t = get_template_content($k); if ($t) { $tpl = $t; $tplKey = $k; break; } }
    if (!$tpl) return false; // No template configured -> do not send static messages

    // For new orders, retry fetching items to solve race condition
    if ($eventKey === 'new_order_notification' && isset($context['id'])) {
      if (empty($context['order_items']) || $context['order_items'] === 'لا توجد بنود مسجلة') {
        for ($i = 0; $i < 2; $i++) {
          usleep(250000); // 250ms
          $items = build_order_items_section($context['id']);
          if ($items !== 'لا توجد بنود مسجلة') {
            $context['order_items'] = $items;
            break;
          }
        }
      }
    }
    // enrich context
    if (!isset($context['company_name'])) { $context['company_name'] = get_company_name(); }
    if (!isset($context['timestamp'])) { $context['timestamp'] = date('Y-m-d H:i:s'); }
    if ($tplKey === 'test_follow_up_system') {
      $fn_to_ar_date = function($ts){ return to_ar_digits(date('Y-m-d', $ts)); };
      $now = time();
      if (empty($context['customer_name'])) $context['customer_name'] = 'اختبار';
      if (empty($context['customer_phone'])) $context['customer_phone'] = get_followup_number() ?: '+966500000000';
      if (empty($context['balance_due'])) $context['balance_due'] = number_format(100.00, 2);
      if (empty($context['oldest_order_number'])) $context['oldest_order_number'] = 'TEST-PAY-' . date('Ymd');
      if (empty($context['oldest_order_date_ar'])) $context['oldest_order_date_ar'] = $fn_to_ar_date($now);
      if (empty($context['oldest_order_age'])) $context['oldest_order_age'] = '2+ أيام';
      if (empty($context['order_number'])) $context['order_number'] = 'ORD-TEST-12345';
      if (empty($context['service_name'])) $context['service_name'] = 'خدمة تجريبية';
      if (empty($context['order_description'])) $context['order_description'] = 'طلب تجريبي لاختبار نظام الإشعارات';
      if (empty($context['status'])) $context['status'] = 'قيد الانتظار';
      if (empty($context['due_date_ar'])) $context['due_date_ar'] = $fn_to_ar_date(strtotime('+1 day', $now));
      if (empty($context['total_amount'])) $context['total_amount'] = number_format(1500, 2);
      if (empty($context['paid_amount'])) $context['paid_amount'] = number_format(500, 2);
      if (empty($context['remaining_amount'])) $context['remaining_amount'] = number_format(1000, 2);
      if (empty($context['order_items'])) {
        $context['order_items'] =
          "1. منتج تجريبي 1\n" .
          "الكمية: 2\n" .
          "السعر: 500 ريال\n" .
          "الإجمالي: 1000 ريال\n" .
          "الوصف: وصف المنتج التجريبي الأول\n\n" .
          "2. منتج تجريبي 2\n" .
          "الكمية: 1\n" .
          "السعر: 500 ريال\n" .
          "الإجمالي: 500 ريال\n" .
          "الوصف: وصف المنتج التجريبي الثاني";
      }
      if (empty($context['created_at_ar'])) $context['created_at_ar'] = $fn_to_ar_date($now);
      if (empty($context['created_time_ar'])) $context['created_time_ar'] = to_ar_digits(date('h:i:s A', $now));
    }

    // Fill defaults for common follow-up templates so test messages are complete
    switch ($tplKey) {
      case 'new_order_notification':
        if (empty($context['order_number'])) $context['order_number'] = 'ORD-TEST-12345';
        if (empty($context['customer_name'])) $context['customer_name'] = 'عميلنا الكريم';
        if (empty($context['service_name'])) $context['service_name'] = 'خدمة عامة';
        if (empty($context['order_items'])) $context['order_items'] = "1. بند تجريبي\nالكمية: 1\nالسعر: 0 ريال\nالإجمالي: 0 ريال";
        if (empty($context['total_amount'])) $context['total_amount'] = number_format(0, 2);
        if (empty($context['notes'])) $context['notes'] = '—';
        if (empty($context['timestamp'])) $context['timestamp'] = date('Y-m-d H:i:s');
        if (empty($context['delivery_date'])) $context['delivery_date'] = date('Y-m-d');
        break;
      case 'delivery_delay_notification':
        if (empty($context['order_number'])) $context['order_number'] = 'ORD-TEST-DEL-1';
        if (empty($context['customer_name'])) $context['customer_name'] = 'عميل';
        if (empty($context['delivery_date'])) $context['delivery_date'] = date('Y-m-d', strtotime('+1 day'));
        if (empty($context['delay_days'])) $context['delay_days'] = to_ar_digits('1');
        if (empty($context['timestamp'])) $context['timestamp'] = date('Y-m-d H:i:s');
        break;
      case 'payment_delay_notification':
        if (empty($context['customer_name'])) $context['customer_name'] = 'عميل';
        if (empty($context['customer_phone'])) $context['customer_phone'] = '+966500000000';
        if (empty($context['balance_due'])) $context['balance_due'] = number_format(100.00, 2);
        if (empty($context['order_number'])) $context['order_number'] = 'ORD-TEST-PAY-1';
        if (empty($context['order_date'])) $context['order_date'] = date('Y-m-d', strtotime('-7 days'));
        if (empty($context['delay_days'])) $context['delay_days'] = to_ar_digits('7');
        if (empty($context['timestamp'])) $context['timestamp'] = date('Y-m-d H:i:s');
        break;
      case 'new_expense_notification':
        if (empty($context['amount'])) $context['amount'] = number_format(0, 2);
        if (empty($context['category'])) $context['category'] = 'عام';
        if (empty($context['description'])) $context['description'] = '—';
        if (empty($context['timestamp'])) $context['timestamp'] = date('Y-m-d H:i:s');
        if (empty($context['receipt_number'])) $context['receipt_number'] = '—';
        break;
      case 'payment_logged_notification':
      case 'new_payment_notification':
        if (empty($context['amount'])) $context['amount'] = number_format(0, 2);
        if (empty($context['order_number'])) $context['order_number'] = 'ORD-TEST-1';
        if (empty($context['customer_name'])) $context['customer_name'] = 'عميل';
        if (empty($context['payment_method'])) $context['payment_method'] = 'نقدي';
        if (empty($context['total_amount'])) $context['total_amount'] = number_format(0, 2);
        if (empty($context['paid_amount'])) $context['paid_amount'] = number_format(0, 2);
        if (empty($context['remaining_amount'])) $context['remaining_amount'] = number_format(0, 2);
        if (empty($context['timestamp'])) $context['timestamp'] = date('Y-m-d H:i:s');
        break;
    }

    $message = render_template($tpl, $context);
    $dest = $to ?: get_followup_number();
    if (!$dest) return false;

    // Direct send for outstanding_balance_report with required payload shape
    if (strtolower((string)$tplKey) === 'outstanding_balance_report') {
      $webhookUrl = resolve_webhook_for_message_type('outstanding_balance_report');
      if ($webhookUrl) {
        $templateVars = [
          'customer_name' => (string)($context['customer_name'] ?? ''),
          'report_date' => (string)($context['report_date'] ?? date('d/m/Y - H:i')),
          'total_due' => (string)($context['total_due'] ?? ($context['outstanding_balance'] ?? '')),
          'unpaid_orders_count' => (string)($context['unpaid_orders_count'] ?? ($context['orders_count'] ?? '')),
          'earliest_due_date' => (string)($context['earliest_due_date'] ?? ($context['due_date'] ?? '')),
          'orders_section' => (string)($context['orders_section'] ?? ''),
          'payments_section' => (string)($context['payments_section'] ?? '')
        ];
        $resolvedPhone = resolve_phone_for_outstanding($dest, $templateVars, $message);
        $payloadArr = [
          'name' => 'send-whatsapp-simple',
          'phone' => (string)$resolvedPhone,
          'message' => (string)$message,
          'webhook_type' => 'outstanding_balance_report',
          'template_vars' => $templateVars
        ];
        $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ok = false; $resp=''; $code=0;
        if (function_exists('curl_init')) {
          try {
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 12]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ok = ($resp !== false && $code >= 200 && $code < 400);
            curl_close($ch);
          } catch (Throwable $e) { $ok = false; }
        }
        if (!$ok) {
          try {
            $opts = [
              'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 12,
              ]
            ];
            $ctx = stream_context_create($opts);
            $resp2 = @file_get_contents($webhookUrl, false, $ctx);
            $ok = ($resp2 !== false);
            $code = $ok ? 200 : ($code ?: 0);
          } catch (Throwable $e2) {
            $ok = false;
          }
        }
        return $ok;
      }
      // If no webhook configured, fall back to queueing below
    }

    $dedupe = null;
    $ek = strtolower((string)$tplKey);
    if (in_array($ek, ['payment_logged_notification','new_payment_notification'], true)) {
      $ord = isset($context['order_number']) ? (string)$context['order_number'] : '';
      $amtRaw = $context['amount'] ?? ($context['paid_amount'] ?? ($context['paid'] ?? null));
      $amt = is_string($amtRaw) ? floatval(preg_replace('/[^0-9.\-]/', '', $amtRaw)) : (is_numeric($amtRaw) ? (float)$amtRaw : 0.0);
      $dedupe = 'payment_event|' . $ord . '|' . number_format($amt, 2);
    }
    if ($dedupe === null && isset($context['order_number'])) {
      $dedupe = strtolower((string)$tplKey) . '|' . (string)$context['order_number'] . '|' . substr(md5($message), 0, 12);
    }
    if ($dedupe === null) {
      $dedupe = strtolower((string)$tplKey) . '|' . substr(md5($message), 0, 12);
    }
    if ($force) { $dedupe = $dedupe . '|force-' . date('YmdHis'); }
    enqueue_followup_message($dest, $message, $tplKey, $dedupe);
    return true;
  } catch (Throwable $e) { return false; }
}

function get_followup_number($tenantId = null) {
  try {
    // SECURITY/CORRECTNESS (multi-tenant): without this filter every tenant
    // shared whichever agency's settings row was updated most recently.
    if ($tenantId === null && !tenant_is_platform_admin()) { $tenantId = tenant_current_id(); }
    $sql = "SELECT whatsapp_number FROM follow_up_settings WHERE COALESCE(whatsapp_number, 0) <> 0";
    $params = [];
    if ($tenantId !== null) { $sql .= " AND tenant_id = :tid"; $params[':tid'] = $tenantId; }
    $sql .= " ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1";
    $st = pdo()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return '';
    $raw = $row['whatsapp_number'];
    // Ensure string
    $num = trim((string)$raw);
    // Some hosts may return numeric without plus sign; leave as-is
    return $num;
  } catch (Throwable $e) { return ''; }
}

function enqueue_followup_message($to, $message, $type = 'follow_up', $dedupeKey = null, $tenantId = null) {
  try {
    ensure_whatsapp_schema();
    $pdo = pdo();
    if ($tenantId === null && !tenant_is_platform_admin()) { $tenantId = tenant_current_id(); }
    $dedupe = $dedupeKey ?: ($type . '|' . substr(md5($to . '|' . substr($message,0,160)), 0, 12));
    if ($dedupeKey) {
      try {
        $chk = $pdo->prepare("SELECT id FROM whatsapp_messages WHERE dedupe_key = :dk AND created_at > (NOW() - INTERVAL 1 DAY) ORDER BY created_at DESC LIMIT 1");
        $chk->execute([':dk' => $dedupe]);
        $ex = $chk->fetch(PDO::FETCH_ASSOC);
        if ($ex && !empty($ex['id'])) {
          return [ 'id' => $ex['id'], 'deduped' => true ];
        }
      } catch (Throwable $e) { /* ignore */ }
    }
    $id = generate_uuid_v4();
    if ($tenantId !== null) {
      $st = $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, created_at, dedupe_key, tenant_id) VALUES (:id, :from_number, :to_number, :message_type, :message_content, :status, NOW(), :dedupe, :tid)");
      $st->execute([
        ':id' => $id, ':from_number' => 'system', ':to_number' => (string)$to, ':message_type' => (string)$type,
        ':message_content' => (string)$message, ':status' => 'pending', ':dedupe' => $dedupe, ':tid' => $tenantId,
      ]);
    } else {
      $st = $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, created_at, dedupe_key) VALUES (:id, :from_number, :to_number, :message_type, :message_content, :status, NOW(), :dedupe)");
      $st->execute([
        ':id' => $id, ':from_number' => 'system', ':to_number' => (string)$to, ':message_type' => (string)$type,
        ':message_content' => (string)$message, ':status' => 'pending', ':dedupe' => $dedupe,
      ]);
    }
    return [ 'id' => $id ];
  } catch (Throwable $e) { return [ 'error' => $e->getMessage() ]; }
}

function process_whatsapp_queue($limit = 50) {
  file_put_contents(__DIR__ . '/whatsapp_debug.log', "--- Running process_whatsapp_queue ---\n", FILE_APPEND);
  $pdo = pdo();
  ensure_whatsapp_schema();
  try {
    // Resolve webhook URL from settings with fallbacks
    $webhookUrl = '';
    try {
      $st = $pdo->query("SELECT follow_up_webhook_url FROM follow_up_settings WHERE follow_up_webhook_url IS NOT NULL AND follow_up_webhook_url <> '' ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1");
      $row = $st->fetch();
      $webhookUrl = (string)($row['follow_up_webhook_url'] ?? '');
    } catch (Throwable $e) { /* ignore */ }
    if (!$webhookUrl) {
      try {
        $st = $pdo->query("SELECT webhook_url FROM webhook_settings WHERE webhook_type IN ('outgoing','evaluation','invoice','proof','outstanding_balance_report','account_summary') AND (is_active = 1 OR is_active = '1' OR LOWER(is_active) IN ('true','t','yes','on')) ORDER BY updated_at DESC, created_at DESC LIMIT 1");
        $row = $st->fetch();
        $webhookUrl = (string)($row['webhook_url'] ?? '');
      } catch (Throwable $e) { /* ignore */ }
    }

    // Include 'pending' messages plus 'failed' messages that are due for automatic retry
    // (e.g. WaSender/fallback was down when first attempted). Without this, a message that
    // failed once stayed 'failed' forever even after the provider came back online, and the
    // customer never received their order-status update.
    // Priority ordering: process NEW/pending messages BEFORE the failed-retry backlog. Without this,
    // a large backlog of retry-due 'failed' rows (all created earlier) sorted oldest-first would
    // starve a freshly-enqueued customer order-status message, leaving it stuck 'pending' — the send
    // is never attempted, so it never "fails", so the follow-up failure alert never fires.
    $st = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE (status IS NULL OR status = '' OR status = 'pending') OR (status = 'failed' AND COALESCE(retry_count,0) < 5 AND (next_retry_at IS NULL OR next_retry_at <= NOW())) ORDER BY (CASE WHEN status IS NULL OR status = '' OR status = 'pending' THEN 0 ELSE 1 END), created_at ASC LIMIT :lim");
    $st->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
    $st->execute();
    $msgs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $processed = 0; $errors = 0; $details = [];
    $waApiCfg = get_active_whatsapp_api_settings();
    foreach ($msgs as $m) {
      $ok = false;
      $lastErr = '';
      $toNumber = $m['to_number'] ?? $m['recipient'] ?? $m['phone'] ?? '';
      $resp = '';
      $code = 0;
      $webhookUrl = '';

      // Prefer the direct WhatsApp API connection (e.g. WaSender) when configured and active — do not rely on webhooks in this case
      if ($waApiCfg) {
        $msgContent = $m['message_content'] ?? $m['content'] ?? $m['message'] ?? '';
        $apiResult = send_via_whatsapp_api($toNumber, $msgContent, $waApiCfg);
        $ok = (bool)($apiResult['ok'] ?? false);
        $code = (int)($apiResult['code'] ?? 0);
        $resp = (string)($apiResult['resp'] ?? '');
        $webhookUrl = (string)($apiResult['api_url'] ?? '');
        if (!$ok) { $lastErr = (string)($apiResult['error'] ?? 'wasender_api_failed'); }
        file_put_contents(__DIR__ . '/whatsapp_debug.log', "Processing msg {$m['id']} via direct API. ok=$ok, code=$code, err=$lastErr\n", FILE_APPEND);
      }

      if (!$waApiCfg) {
      $webhookUrl = resolve_webhook_for_message_type($m['message_type'] ?? '');
      file_put_contents(__DIR__ . '/whatsapp_debug.log', "Processing msg {$m['id']}. Type: {$m['message_type']}. URL: $webhookUrl\n", FILE_APPEND);
      if ($webhookUrl) {
        $toRaw = $m['to_number'] ?? $m['recipient'] ?? $m['phone'] ?? '';
        $msg = $m['message_content'] ?? $m['content'] ?? $m['message'] ?? '';
        $type = (string)($m['message_type'] ?? 'text');
        $toNoPlus = ltrim((string)$toRaw, '+');

        // Build payload format expected by n8n node "send-whatsapp-simple" when outstanding_balance_report
        if (strtolower($type) === 'outstanding_balance_report') {
        // Try to extract template variables if message was rendered from template
        $templateVars = $m['template_vars'] ?? null; // allow future extension
        if (!is_array($templateVars)) {
        $templateVars = [
        'customer_name' => '',
        'report_date' => date('d/m/Y - H:i'),
        'total_due' => '',
        'unpaid_orders_count' => '',
        'earliest_due_date' => '',
        'orders_section' => '',
        'payments_section' => ''
        ];
        }
        // Force render message from template, ignoring any prebuilt summary
        $tpl = get_template_content('outstanding_balance_report');
        if (is_string($tpl) && trim($tpl) !== '') {
        $msg = render_template($tpl, $templateVars);
        }
        $resolvedPhone = resolve_phone_for_outstanding($toRaw, $templateVars, $msg);
        $bodyArr = [
        'name' => 'send-whatsapp-simple',
        'phone' => (string)$resolvedPhone,
        'message' => (string)$msg,
        'webhook_type' => 'outstanding_balance_report',
        'template_vars' => $templateVars
        ];
        $payload = json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
          // Default legacy payload
          $payload = json_encode([
            'event' => 'whatsapp_message_send',
            'data' => [
              'to' => $toNoPlus ?: $toRaw,
              'phone' => $toRaw,
              'phoneNumber' => $toRaw,
              'message' => $msg,
              'messageText' => $msg,
              'text' => $msg,
              'type' => 'text',
              'message_type' => $type,
              'timestamp' => time(),
              'from_number' => $m['from_number'] ?? 'system'
            ],
            'meta' => $m,
            'to' => $toRaw,
            'message' => $msg,
            'body' => $msg,
            'type' => $type
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        file_put_contents(__DIR__ . '/whatsapp_debug.log', "Payload for {$m['id']}: $payload\n", FILE_APPEND);
        if (function_exists('curl_init')) {
          try {
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 12]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ok = ($resp !== false && $code >= 200 && $code < 400);
            if (!$ok) { $lastErr = 'http_' . ($code ?: 0); }
            curl_close($ch);
            file_put_contents(__DIR__ . '/whatsapp_debug.log', "cURL for {$m['id']}: ok=$ok, code=$code, err=$lastErr, resp=$resp\n", FILE_APPEND);
          } catch (Throwable $e) {
            $ok = false;
            $lastErr = 'curl_error: ' . $e->getMessage();
            file_put_contents(__DIR__ . '/whatsapp_error.log', "cURL exception for {$m['id']}: " . $e->getMessage() . "\n", FILE_APPEND);
          }
        }
        if (!$ok) {
          // Fallback without cURL
          try {
            $opts = [
              'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 12,
              ]
            ];
            $ctx = stream_context_create($opts);
            $resp2 = @file_get_contents($webhookUrl, false, $ctx);
            $ok = ($resp2 !== false);
            $resp = is_string($resp2) ? $resp2 : $resp;
            $code = $ok ? 200 : ($code ?: 0);
            if (!$ok && !$lastErr) { $lastErr = 'http_stream_failed'; }
          } catch (Throwable $e2) {
            if (!$lastErr) $lastErr = 'http_error';
          }
        }
      } else {
        $lastErr = 'no_active_webhook';
        file_put_contents(__DIR__ . '/whatsapp_error.log', "No active webhook for msg {$m['id']}, type {$m['message_type']}\n", FILE_APPEND);
      }
      }

      // Alert follow-up management via the fallback WhatsApp provider ONLY for:
      // (1) a WaSender connection failure, or (2) a failed order-status-update message.
      if (!$ok) {
        $msgTypeForAlert = (string)($m['message_type'] ?? '');
        $isOrderStatusMsg = (stripos($msgTypeForAlert, 'order_') === 0);
        $isConnFailure = $waApiCfg && (
          stripos($lastErr, 'curl_error') !== false ||
          stripos($lastErr, 'timed out') !== false ||
          stripos($lastErr, 'timeout') !== false ||
          stripos($lastErr, 'could not resolve') !== false ||
          stripos($lastErr, 'connection refused') !== false ||
          stripos($lastErr, 'wasender_api_failed') !== false ||
          stripos($lastErr, 'http_0') !== false
        );
        if ($isConnFailure || $isOrderStatusMsg) {
          try {
            $alertReason = $isConnFailure
              ? ('انقطع الاتصال مع واتساب (WaSender): ' . $lastErr)
              : ('فشل إرسال رسالة تحديث حالة الطلب: ' . $lastErr);
            $alertResult = maybe_alert_whatsapp_failure($alertReason, ['message_type' => $msgTypeForAlert, 'to' => $toNumber]);
            // Log the outcome of the alert attempt itself — previously this was swallowed silently,
            // so if the fallback (ultramsg) credentials were also broken, nobody could tell why no
            // alert ever arrived. Check whatsapp_debug.log on the server to see alert_sent=false/why.
            file_put_contents(__DIR__ . '/whatsapp_debug.log', "Alert attempt for msg {$m['id']}: alert_sent=" . var_export($alertResult, true) . "\n", FILE_APPEND);
          } catch (Throwable $eAlert) {
            file_put_contents(__DIR__ . '/whatsapp_error.log', "Alert attempt threw for msg {$m['id']}: " . $eAlert->getMessage() . "\n", FILE_APPEND);
          }
        }
      }

      try {
        $hasUpdatedAt = table_has_column('whatsapp_messages','updated_at');
        $hasErrMsg = table_has_column('whatsapp_messages','error_message');
        $hasRetryCount = table_has_column('whatsapp_messages','retry_count');
        $hasNextRetryAt = table_has_column('whatsapp_messages','next_retry_at');
        $setParts = ['status = :s'];
        $params = [':s' => $ok ? 'sent' : 'failed', ':id' => $m['id']];
        if ($hasUpdatedAt) { $setParts[] = 'updated_at = NOW()'; }
        if (!$ok && $hasErrMsg) { $setParts[] = 'error_message = :err'; $params[':err'] = $lastErr; }
        if (!$ok && $hasRetryCount) {
          $prevRetries = (int)($m['retry_count'] ?? 0);
          $newRetries = $prevRetries + 1;
          $setParts[] = 'retry_count = :rc';
          $params[':rc'] = $newRetries;
          if ($hasNextRetryAt) {
            // Exponential-ish backoff: 5, 15, 30, 60, 120 minutes, capped at 5 attempts total
            $backoffMinutes = [5, 15, 30, 60, 120];
            $delayMin = $backoffMinutes[min($newRetries - 1, count($backoffMinutes) - 1)];
            $setParts[] = 'next_retry_at = DATE_ADD(NOW(), INTERVAL :dmin MINUTE)';
            $params[':dmin'] = $delayMin;
          }
        } elseif ($ok && $hasRetryCount) {
          $setParts[] = 'retry_count = 0';
        }
        $sqlUp = 'UPDATE whatsapp_messages SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $st2 = $pdo->prepare($sqlUp);
        $st2->execute($params);

        if (table_exists('webhook_logs')) {
          try {
            $logCols = ['id']; $logVals = [':lid']; $binds = [':lid' => generate_uuid_v4()];
            if (table_has_column('webhook_logs','webhook_url')) { $logCols[]='webhook_url'; $logVals[]=':lu'; $binds[':lu']=$webhookUrl; }
            if (table_has_column('webhook_logs','request_body')) {
              $reqJson = json_encode([
                'type' => $m['message_type'] ?? 'text',
                'to' => $toNumber,
                'message' => $m['message_content'] ?? $m['content'] ?? $m['message'] ?? '',
                'meta' => ['id' => $m['id']]
              ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
              $logCols[]='request_body'; $logVals[]=':lrb'; $binds[':lrb']=$reqJson;
            }
            if (table_has_column('webhook_logs','response_status')) { $logCols[]='response_status'; $logVals[]=':lrs'; $binds[':lrs']=(int)$code; }
            elseif (table_has_column('webhook_logs','status_code')) { $logCols[]='status_code'; $logVals[]=':lrs'; $binds[':lrs']=(int)$code; }
            if (table_has_column('webhook_logs','response_body')) { $logCols[]='response_body'; $logVals[]=':lrb2'; $binds[':lrb2']= is_string($resp) ? substr($resp,0,1000) : ''; }
            elseif (table_has_column('webhook_logs','response')) { $logCols[]='response'; $logVals[]=':lrb2'; $binds[':lrb2']= is_string($resp) ? substr($resp,0,1000) : ''; }
            if (table_has_column('webhook_logs','success')) { $logCols[]='success'; $logVals[]=':ls'; $binds[':ls']=$ok ? 1 : 0; }
            if (table_has_column('webhook_logs','message_id')) { $logCols[]='message_id'; $logVals[]=':lmid'; $binds[':lmid']=$m['id']; }
            $createdInline = false;
            if (table_has_column('webhook_logs','created_at')) { $logCols[]='created_at'; $createdInline = true; }
            $sqlLog = 'INSERT INTO webhook_logs (' . implode(',', $logCols) . ') VALUES (' . implode(',', $createdInline ? array_merge($logVals, ['NOW()']) : $logVals) . ')';
            if ($createdInline) { array_pop($logVals); }
            $sqlLog = 'INSERT INTO webhook_logs (' . implode(',', $logCols) . ') VALUES (' . implode(',', $createdInline ? array_merge($logVals, ['NOW()']) : $logVals) . ')';
            $stLog = $pdo->prepare($sqlLog);
            foreach ($binds as $k => $v) { $stLog->bindValue($k, $v); }
            $stLog->execute();
          } catch (Throwable $eLog) { /* ignore */ }
        }

        $details[] = [
          'id' => $m['id'],
          'to' => $toNumber,
          'message_type' => $m['message_type'] ?? 'text',
          'ok' => $ok,
          'status_code' => (int)$code,
          'webhook_url' => $webhookUrl,
          'error' => $lastErr,
          'response_sample' => is_string($resp) ? substr($resp, 0, 512) : ''
        ];

        $processed++;
      } catch (Throwable $e) { $errors++; }
    }
    return ['processed' => $processed, 'errors' => $errors, 'webhook_url' => $webhookUrl, 'details' => $details];
  } catch (Throwable $e) { return ['processed' => 0, 'errors' => 1, 'message' => $e->getMessage()]; }
};

function get_table_columns($table) {
  static $cache = [];
  global $CFG;
  $t = sanitize_ident($table);
  if (isset($cache[$t])) return $cache[$t];
  try {
    // Primary attempt via INFORMATION_SCHEMA using configured DB name
    $st = pdo()->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t');
    $st->execute([':db' => $CFG['name'], ':t' => $t]);
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $cols[$r['COLUMN_NAME']] = true; }
    // Fallback: SHOW COLUMNS FROM `table` (uses current connection DB)
    if (empty($cols)) {
      try {
        $st2 = pdo()->prepare('SHOW COLUMNS FROM `' . $t . '`');
        $st2->execute();
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r2) { if (!empty($r2['Field'])) $cols[$r2['Field']] = true; }
      } catch (Throwable $e2) { /* ignore */ }
    }
    $cache[$t] = $cols;
    return $cols;
  } catch (Throwable $e) {
    // Last resort: try SHOW COLUMNS
    try {
      $cols = [];
      $st2 = pdo()->prepare('SHOW COLUMNS FROM `' . $t . '`');
      $st2->execute();
      foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r2) { if (!empty($r2['Field'])) $cols[$r2['Field']] = true; }
      $cache[$t] = $cols;
      return $cols;
    } catch (Throwable $e2) {
      return [];
    }
  }
}

function table_exists($table) { return !empty(get_table_columns($table)); }
function table_has_column($table, $col) { $cols = get_table_columns($table); return isset($cols[$col]); }

// WHERE builder for simple selects (base table only)
function build_where($filters, &$params, $baseTable = null) {
  if (!is_array($filters) || count($filters) === 0) return '';
  $clauses = [];
  $validCols = $baseTable ? get_table_columns($baseTable) : null;
  foreach ($filters as $i => $f) {
    $op = $f['op'] ?? 'eq';
    $colStr = trim((string)($f['column'] ?? ''));
    if ($colStr === '' || strpos($colStr, '.') !== false) continue; // ignore dotted in simple path
    $col = sanitize_ident($colStr);
    if ($validCols !== null && !isset($validCols[$col])) continue;
    $key = ":f{$i}";
    switch ($op) {
      case 'eq':
        if (is_bool($f['value'])) {
          if ($f['value'] === true) {
            $clauses[] = "(`$col` = 1 OR `$col` = '1' OR LOWER(TRIM(`$col`)) IN ('true','t','yes','on'))";
          } else {
            $clauses[] = "(`$col` = 0 OR `$col` = '0' OR LOWER(TRIM(`$col`)) IN ('false','f','no','off'))";
          }
        } else if ($f['value'] === null) {
          $clauses[] = "`$col` IS NULL";
        } else {
          $clauses[] = "`$col` = $key"; $params[$key] = $f['value'];
        }
        break;
      case 'neq':
        if (is_bool($f['value'])) {
          if ($f['value'] === true) {
            $clauses[] = "NOT (`$col` = 1 OR `$col` = '1' OR LOWER(TRIM(`$col`)) IN ('true','t','yes','on'))";
          } else {
            $clauses[] = "NOT (`$col` = 0 OR `$col` = '0' OR LOWER(TRIM(`$col`)) IN ('false','f','no','off'))";
          }
        } else if ($f['value'] === null) {
          $clauses[] = "`$col` IS NOT NULL";
        } else {
          $clauses[] = "`$col` <> $key"; $params[$key] = $f['value'];
        }
        break;
      case 'gt': $clauses[] = "`$col` > $key"; $params[$key] = is_bool($f['value']) ? ($f['value'] ? 1 : 0) : $f['value']; break;
      case 'gte': $clauses[] = "`$col` >= $key"; $params[$key] = is_bool($f['value']) ? ($f['value'] ? 1 : 0) : $f['value']; break;
      case 'lt': $clauses[] = "`$col` < $key"; $params[$key] = is_bool($f['value']) ? ($f['value'] ? 1 : 0) : $f['value']; break;
      case 'lte': $clauses[] = "`$col` <= $key"; $params[$key] = is_bool($f['value']) ? ($f['value'] ? 1 : 0) : $f['value']; break;
      case 'like': $clauses[] = "`$col` LIKE $key"; $params[$key] = $f['value']; break;
      case 'ilike': $clauses[] = "LOWER(`$col`) LIKE LOWER($key)"; $params[$key] = $f['value']; break;
      case 'is': if ($f['value'] === null) { $clauses[] = "`$col` IS NULL"; } else { $clauses[] = "`$col` = $key"; $params[$key] = is_bool($f['value']) ? ($f['value'] ? 1 : 0) : $f['value']; } break;
      case 'in':
        $vals = $f['value']; if (!is_array($vals) || count($vals) === 0) { $clauses[] = '1=0'; break; }
        $inKeys = [];
        $hasNull = false;
        foreach ($vals as $j => $v) {
          if ($v === null) { $hasNull = true; continue; }
          $k = ":f{$i}_{$j}";
          $inKeys[] = $k;
          $params[$k] = is_bool($v) ? ($v ? 1 : 0) : $v;
        }
        $condParts = [];
        if (!empty($inKeys)) { $condParts[] = "`$col` IN (" . implode(',', $inKeys) . ")"; }
        if ($hasNull) { $condParts[] = "`$col` IS NULL"; }
        $clauses[] = $condParts ? '(' . implode(' OR ', $condParts) . ')' : '1=0';
        break;
      default: break;
    }
  }
  return $clauses ? (' WHERE ' . implode(' AND ', $clauses)) : '';
}

// Column parsing for relational selects
function tokenize_columns($raw) {
  $tokens = [];
  $buf = '';
  $depth = 0; $len = strlen($raw);
  for ($i=0; $i<$len; $i++) {
    $ch = $raw[$i];
    if ($ch === '(') { $depth++; $buf .= $ch; continue; }
    if ($ch === ')') { $depth = max(0, $depth-1); $buf .= $ch; continue; }
    if ($ch === ',' && $depth === 0) { $tokens[] = trim($buf); $buf = ''; continue; }
    $buf .= $ch;
  }
  if (trim($buf) !== '') $tokens[] = trim($buf);
  return array_values(array_filter(array_map('trim', $tokens), function($t){ return $t !== ''; }));
}

function singularize($name) {
  if (preg_match('/ies$/', $name)) return preg_replace('/ies$/', 'y', $name);
  if (preg_match('/s$/', $name)) return preg_replace('/s$/', '', $name);
  return $name;
}

function guess_join_on($base, $rel, $aliasBase, $aliasRel) {
  $fk1 = singularize($rel) . '_id';
  if (table_has_column($base, $fk1)) return $aliasBase . '.`' . sanitize_ident($fk1) . '` = ' . $aliasRel . '.`id`';
  $fk2 = singularize($base) . '_id';
  if (table_has_column($rel, $fk2)) return $aliasRel . '.`' . sanitize_ident($fk2) . '` = ' . $aliasBase . '.`id`';
  return '';
}

function build_relational_where($filters, &$params, $base, $aliasBase, $relAliases, $baseColSet, $relColSets) {
  if (!is_array($filters) || count($filters) === 0) return '';
  $parts = [];
  foreach ($filters as $i => $f) {
    $op = $f['op'] ?? 'eq';
    $colStr = trim((string)($f['column'] ?? ''));
    if ($colStr === '') continue;
    $targetAlias = $aliasBase; $colName = '';
    if (strpos($colStr, '.') !== false) {
      list($t, $c) = array_pad(explode('.', $colStr, 2), 2, '');
      $t = trim($t); $c = trim($c);
      if ($t === $base) { $targetAlias = $aliasBase; $colName = $c; if (!isset($baseColSet[$colName])) continue; }
      elseif (isset($relAliases[$t])) { $targetAlias = $relAliases[$t]; $colName = $c; if (!isset($relColSets[$t][$colName])) continue; }
      else { continue; }
    } else {
      $colName = $colStr; if (!isset($baseColSet[$colName])) continue;
    }
    $colExpr = $targetAlias . '.`' . sanitize_ident($colName) . '`';
    $key = ":rf{$i}";
    switch ($op) {
      case 'eq':
        if (is_bool($f['value'])) {
          if ($f['value'] === true) {
            $parts[] = "($colExpr = 1 OR $colExpr = '1' OR LOWER(TRIM($colExpr)) IN ('true','t','yes','on'))";
          } else {
            $parts[] = "($colExpr = 0 OR $colExpr = '0' OR LOWER(TRIM($colExpr)) IN ('false','f','no','off'))";
          }
        } else if ($f['value'] === null) {
          $parts[] = "$colExpr IS NULL";
        } else {
          $parts[] = "$colExpr = $key"; $params[$key] = $f['value'];
        }
        break;
      case 'neq':
        if (is_bool($f['value'])) {
          if ($f['value'] === true) {
            $parts[] = "NOT ($colExpr = 1 OR $colExpr = '1' OR LOWER(TRIM($colExpr)) IN ('true','t','yes','on'))";
          } else {
            $parts[] = "NOT ($colExpr = 0 OR $colExpr = '0' OR LOWER(TRIM($colExpr)) IN ('false','f','no','off'))";
          }
        } else if ($f['value'] === null) {
          $parts[] = "$colExpr IS NOT NULL";
        } else {
          $parts[] = "$colExpr <> $key"; $params[$key] = $f['value'];
        }
        break;
      case 'gt': $parts[] = "$colExpr > $key"; $params[$key] = is_bool($f['value']) ? ($f['value'] ? 1 : 0) : $f['value']; break;
      case 'gte': $parts[] = "$colExpr >= $key"; $params[$key] = is_bool($f['value']) ? ($f['value'] ? 1 : 0) : $f['value']; break;
      case 'lt': $parts[] = "$colExpr < $key"; $params[$key] = is_bool($f['value']) ? ($f['value'] ? 1 : 0) : $f['value']; break;
      case 'lte': $parts[] = "$colExpr <= $key"; $params[$key] = is_bool($f['value']) ? ($f['value'] ? 1 : 0) : $f['value']; break;
      case 'like': $parts[] = "$colExpr LIKE $key"; $params[$key] = $f['value']; break;
      case 'ilike': $parts[] = "LOWER($colExpr) LIKE LOWER($key)"; $params[$key] = $f['value']; break;
      case 'is': if ($f['value'] === null) { $parts[] = "$colExpr IS NULL"; } else { $parts[] = "$colExpr = $key"; $params[$key] = is_bool($f['value']) ? ($f['value'] ? 1 : 0) : $f['value']; } break;
      case 'in':
        $vals = $f['value']; if (!is_array($vals) || count($vals) === 0) { $parts[] = '1=0'; break; }
        $inKeys = [];
        $hasNull = false;
        foreach ($vals as $j => $v) {
          if ($v === null) { $hasNull = true; continue; }
          $k = ":rf{$i}_{$j}";
          $inKeys[] = $k;
          $params[$k] = is_bool($v) ? ($v ? 1 : 0) : $v;
        }
        $condParts = [];
        if (!empty($inKeys)) { $condParts[] = "$colExpr IN (" . implode(',', $inKeys) . ")"; }
        if ($hasNull) { $condParts[] = "$colExpr IS NULL"; }
        $parts[] = $condParts ? '(' . implode(' OR ', $condParts) . ')' : '1=0';
        break;
      default: break;
    }
  }
  return $parts ? (' WHERE ' . implode(' AND ', $parts)) : '';
}

function build_relational_select_sql($baseTable, $columnsRaw, $orderSpec, $limit, $offset, $filters, &$paramsOut) {
  $base = sanitize_ident($baseTable);
  $aliasBase = 'b';
  $tokens = tokenize_columns($columnsRaw);
  $baseCols = [];
  $joins = [];
  $relAliases = [];
  $relColSets = [];
  $relIndex = 1;

  $baseColSet = get_table_columns($base);

  // Helper to build JSON_OBJECT for one-to-one
  $buildJsonObject = function($alias, $fields) {
    $parts = [];
    foreach ($fields as $f) {
      if (!preg_match('/^[A-Za-z0-9_]+$/', $f)) continue;
      $parts[] = "'" . $f . "', " . $alias . ".`" . $f . "`";
    }
    if (empty($parts)) return 'NULL';
    return 'JSON_OBJECT(' . implode(', ', $parts) . ')';
  };

  // Collect extra relations required only for dotted filters or order clauses
  $extraJoinRels = [];
  if (is_array($filters)) {
    foreach ($filters as $f) {
      $colStr = trim((string)($f['column'] ?? ''));
      if (strpos($colStr, '.') !== false) {
        list($t, $c) = array_pad(explode('.', $colStr, 2), 2, '');
        $t = trim($t); $c = trim($c);
        if ($t !== '' && $t !== $base && table_exists($t)) {
          $extraJoinRels[$t] = true;
        }
      }
    }
  }
  if (is_array($orderSpec)) {
    foreach ($orderSpec as $ord) {
      $rawCol = trim((string)($ord['column'] ?? ''));
      if (strpos($rawCol, '.') !== false) {
        list($t, $c) = array_pad(explode('.', $rawCol, 2), 2, '');
        $t = trim($t); $c = trim($c);
        if ($t !== '' && $t !== $base && table_exists($t)) {
          $extraJoinRels[$t] = true;
        }
      }
    }
  }

  foreach ($tokens as $tok) {
    $tok = trim($tok);
    if ($tok === '*') { $baseCols[] = "$aliasBase.*"; continue; }

    // relation(token)
    if (strpos($tok, '(') !== false && substr($tok, -1) === ')') {
      $pos = strpos($tok, '(');
      $relNamePart = trim(substr($tok, 0, $pos));
      $fieldsRaw = substr($tok, $pos + 1, -1);

      // join hint
      $joinType = 'LEFT';
      if (strpos($relNamePart, '!') !== false) { list($relNamePart,) = explode('!', $relNamePart, 2); $joinType = 'INNER'; }
      // foreign key override via colon syntax: relation:fk_name
      $fkOverride = null;
      if (strpos($relNamePart, ':') !== false) {
        list($relTable, $fkOverride) = explode(':', $relNamePart, 2);
        $relNamePart = trim($relTable);
        $fkOverride = sanitize_ident(trim($fkOverride));
      }
      $rel = sanitize_ident($relNamePart);
      $fields = array_values(array_filter(array_map('trim', explode(',', $fieldsRaw)), function($x){ return $x !== ''; }));

      $aliasRel = 'r' . $relIndex++;
      $relColSets[$rel] = get_table_columns($rel);
      if (in_array('*', $fields, true)) { $fields = array_keys($relColSets[$rel]); }

      // Determine ON clause and relation direction
      $on = '';
      $dir = 'base_to_rel'; // default if base has FK to rel
      $fkUsed = null;
      if ($fkOverride && table_has_column($base, $fkOverride)) {
        $on = $aliasBase . '.`' . $fkOverride . '` = ' . $aliasRel . '.`id`';
        $fkUsed = $fkOverride;
        $dir = 'base_to_rel';
      }
      if ($on === '') {
        $fk1 = singularize($rel) . '_id';
        if (table_has_column($base, $fk1)) { $on = $aliasBase . '.`' . $fk1 . '` = ' . $aliasRel . '.`id`'; $fkUsed = $fk1; $dir = 'base_to_rel'; }
      }
      if ($on === '') {
        $fk2 = singularize($base) . '_id';
        if (table_has_column($rel, $fk2)) { $on = $aliasRel . '.`' . $fk2 . '` = ' . $aliasBase . '.`id`'; $fkUsed = $fk2; $dir = 'rel_to_base'; }
      }

      if ($on === '') continue; // cannot determine relation

      if ($dir === 'base_to_rel') {
        // One-to-one: join and select as JSON object (supports one-level nested relations)
        $relAliases[$rel] = $aliasRel;
        $joins[] = $joinType . ' JOIN `' . $rel . '` ' . $aliasRel . ' ON ' . $on;

        $parts = [];
        foreach ($fields as $fraw) {
          $f = trim($fraw);
          // Nested relation inside this relation: e.g., customers(name)
          if (strpos($f, '(') !== false && substr($f, -1) === ')') {
            $pos2 = strpos($f, '(');
            $subNamePart = trim(substr($f, 0, $pos2));
            $subFieldsRaw = substr($f, $pos2 + 1, -1);
            // Parse join hint/override (inner/left, fk override)
            $subJoinType = 'LEFT';
            if (strpos($subNamePart, '!') !== false) { list($subNamePart,) = explode('!', $subNamePart, 2); $subJoinType = 'INNER'; }
            $subFkOverride = null;
            if (strpos($subNamePart, ':') !== false) { list($subTable, $subFkOverride) = explode(':', $subNamePart, 2); $subNamePart = trim($subTable); $subFkOverride = sanitize_ident(trim($subFkOverride)); }
            $subRel = sanitize_ident($subNamePart);
            $subFields = array_values(array_filter(array_map('trim', explode(',', $subFieldsRaw)), function($x){ return $x !== ''; }));
            $subAlias = 'r' . $relIndex++;
            $subColSet = get_table_columns($subRel);
            if (in_array('*', $subFields, true)) { $subFields = array_keys($subColSet); }

            // Determine ON between $rel and $subRel
            $onSub = '';
            $fkUsedSub = null; $dirSub = 'base_to_rel';
            if ($subFkOverride && table_has_column($rel, $subFkOverride)) { $onSub = $aliasRel . '.`' . $subFkOverride . '` = ' . $subAlias . '.`id`'; $fkUsedSub = $subFkOverride; $dirSub = 'base_to_rel'; }
            if ($onSub === '') {
              $fkR = singularize($subRel) . '_id';
              if (table_has_column($rel, $fkR)) { $onSub = $aliasRel . '.`' . $fkR . '` = ' . $subAlias . '.`id`'; $fkUsedSub = $fkR; $dirSub = 'base_to_rel'; }
            }
            if ($onSub === '') {
              $fkR2 = singularize($rel) . '_id';
              if (table_has_column($subRel, $fkR2)) { $onSub = $subAlias . '.`' . $fkR2 . '` = ' . $aliasRel . '.`id`'; $fkUsedSub = $fkR2; $dirSub = 'rel_to_base'; }
            }

            if ($onSub !== '') {
              if ($dirSub === 'base_to_rel') {
                // Join one-to-one nested
                $joins[] = $subJoinType . ' JOIN `' . $subRel . '` ' . $subAlias . ' ON ' . $onSub;
                $subParts = [];
                foreach ($subFields as $sf) {
                  if (!preg_match('/^[A-Za-z0-9_]+$/', $sf)) continue;
                  if (!isset($subColSet[$sf])) continue;
                  $subParts[] = "'" . $sf . "', " . $subAlias . ".`" . $sf . "`";
                }
                if (empty($subParts)) { $subParts = ["'id'", $subAlias . '.`id`' ]; }
                $subJson = 'JSON_OBJECT(' . implode(', ', $subParts) . ')';
                $parts[] = "'" . $subRel . "', " . $subJson;
              } else {
                // One-to-many nested: use subselect array
                $rr2 = 'rr' . $relIndex++;
                $subParts = [];
                foreach ($subFields as $sf) {
                  if (!preg_match('/^[A-Za-z0-9_]+$/', $sf)) continue;
                  if (!isset($subColSet[$sf])) continue;
                  $subParts[] = "'" . $sf . "', " . $rr2 . ".`" . $sf . "`";
                }
                if (empty($subParts)) { $subParts = ["'id'", $rr2 . '.`id`' ]; }
                $subJsonObj = 'JSON_OBJECT(' . implode(', ', $subParts) . ')';
                $subExpr = '(SELECT COALESCE(CONCAT("[", GROUP_CONCAT(' . $subJsonObj . '), "]"), "[]") FROM `' . $subRel . '` ' . $rr2 . ' WHERE ' . $rr2 . '.`' . $fkUsedSub . '` = ' . $aliasRel . '.`id`)';
                $parts[] = "'" . $subRel . "', " . $subExpr;
              }
            }
            continue;
          }

          // Scalar field from relation table
          if (preg_match('/^[A-Za-z0-9_]+$/', $f) && isset($relColSets[$rel][$f])) {
            $parts[] = "'" . $f . "', " . $aliasRel . ".`" . $f . "`";
          }
        }
        if (empty($parts)) { $parts = ["'id'", $aliasRel . '.`id`' ]; }
        $jsonObj = 'JSON_OBJECT(' . implode(', ', $parts) . ')';
        $baseCols[] = '(CASE WHEN ' . $aliasRel . '.`id` IS NULL THEN NULL ELSE ' . $jsonObj . ' END) AS `' . $rel . '`';
      } else {
        // One-to-many: subselect JSON array, no join
        $rr = 'rr' . $relIndex++;
        $jsonParts = [];
        foreach ($fields as $f) {
          if (!preg_match('/^[A-Za-z0-9_]+$/', $f)) continue;
          if (!isset($relColSets[$rel][$f])) continue;
          $jsonParts[] = "'" . $f . "', " . $rr . ".`" . $f . "`";
        }
        if (empty($jsonParts)) { $jsonParts = ["'id'", $rr . '.`id`' ]; }
        $jsonObj = 'JSON_OBJECT(' . implode(', ', $jsonParts) . ')';
        // Use GROUP_CONCAT(JSON_OBJECT(...)) to support older MySQL; wrap with [] and COALESCE to []
        $sub = '(SELECT COALESCE(CONCAT("[", GROUP_CONCAT(' . $jsonObj . '), "]"), "[]") FROM `' . $rel . '` ' . $rr . ' WHERE ' . $rr . '.`' . $fkUsed . '` = ' . $aliasBase . '.`id`) AS `' . $rel . '`';
        $baseCols[] = $sub;
      }
      continue;
    }

    // base table column
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tok)) continue;
    if (!isset($baseColSet[$tok])) continue;
    $baseCols[] = $aliasBase . '.`' . $tok . '`';
  }

  // Add extra joins needed only for dotted filters
  foreach (array_keys($extraJoinRels) as $rel) {
    if (isset($relAliases[$rel])) continue;
    $aliasRel = 'r' . $relIndex++;
    $on = guess_join_on($base, $rel, $aliasBase, $aliasRel);
    if ($on === '') continue;
    $joins[] = 'LEFT JOIN `' . $rel . '` ' . $aliasRel . ' ON ' . $on;
    $relAliases[$rel] = $aliasRel;
    $relColSets[$rel] = get_table_columns($rel);
  }

  if (empty($baseCols)) { $baseCols[] = "$aliasBase.*"; }

  $selectSql = implode(', ', $baseCols);
  $sql = 'SELECT ' . $selectSql . ' FROM `' . $base . '` ' . $aliasBase . ' ';
  if ($joins) $sql .= implode(' ', $joins) . ' ';

  // WHERE (relational)
  $where = build_relational_where($filters, $paramsOut, $base, $aliasBase, $relAliases, $baseColSet, $relColSets);
  if ($where) $sql .= $where . ' ';

  // ORDER BY
  if (is_array($orderSpec) && !empty($orderSpec)) {
    $orderParts = [];
    foreach ($orderSpec as $ord) {
      $rawCol = trim($ord['column'] ?? '');
      if ($rawCol === '') continue;
      $dir = (isset($ord['ascending']) && $ord['ascending'] === false) ? 'DESC' : 'ASC';
      if (strpos($rawCol, '.') !== false) {
        list($t, $c) = array_pad(explode('.', $rawCol, 2), 2, '');
        $t = trim($t); $c = trim($c);
        if ($t === $base && isset($baseColSet[$c])) { $orderParts[] = $aliasBase . '.`' . sanitize_ident($c) . '` ' . $dir; }
        elseif (isset($relAliases[$t]) && isset($relColSets[$t][$c])) { $orderParts[] = $relAliases[$t] . '.`' . sanitize_ident($c) . '` ' . $dir; }
      } else {
        if (isset($baseColSet[$rawCol])) $orderParts[] = $aliasBase . '.`' . sanitize_ident($rawCol) . '` ' . $dir;
      }
    }
    if ($orderParts) $sql .= 'ORDER BY ' . implode(', ', $orderParts) . ' ';
  }

  if (is_numeric($limit)) $sql .= 'LIMIT ' . intval($limit) . ' ';
  if (is_numeric($offset)) $sql .= 'OFFSET ' . intval($offset) . ' ';

  return trim($sql);
}

function handle_db() {
  $body = read_json_body();
  $action = $body['action'] ?? '';
  $table = isset($body['table']) ? sanitize_ident(trim($body['table'])) : '';
  $pdo = pdo();

  // SECURITY: ai_agent_settings stores the LLM API key — never expose it through the generic DB API.
  if ($table === 'ai_agent_settings') {
    return ['data' => null, 'error' => ['message' => 'هذا الجدول محمي ولا يمكن الوصول إليه مباشرة']];
  }

  // SECURITY: these tables hold secrets (inbound webhook secret, WaSender DB credentials,
  // WhatsApp API keys). They were previously readable WITHOUT login, which let anyone on the
  // internet fetch the shared secret and then call the secret-gated admin endpoints.
  // Only a logged-in user (validated against the users table) may touch them.
  if (in_array($table, ['whatsapp_inbound_settings', 'whatsapp_api_settings'], true)) {
    if (!ai_request_user()) {
      return ['data' => null, 'error' => ['message' => 'يتطلب تسجيل الدخول', 'code' => 401]];
    }
  }

  // Make sure WhatsApp-related tables (e.g. whatsapp_api_settings) exist before generic CRUD touches them
  if ($table === 'whatsapp_api_settings' || $table === 'whatsapp_messages' || $table === 'webhook_logs') {
    try { ensure_whatsapp_schema(); } catch (Throwable $eSchema) { /* ignore */ }
  }

  // Normalize delivery time for orders: accept estimated_delivery_time from top-level or nested and map to existing column
  try {
    if ($table === 'orders') {
      $incomingTime = null;
      if (isset($body['estimated_delivery_time']) && $body['estimated_delivery_time'] !== '') {
        $incomingTime = (string)$body['estimated_delivery_time'];
      }
      if ($incomingTime === null && isset($body['data']) && is_array($body['data']) && isset($body['data']['estimated_delivery_time'])) {
        $incomingTime = (string)$body['data']['estimated_delivery_time'];
      }
      if ($incomingTime === null && isset($body['values']) && is_array($body['values']) && isset($body['values']['estimated_delivery_time'])) {
        $incomingTime = (string)$body['values']['estimated_delivery_time'];
      }

      // Clean and normalize HH:MM
      if ($incomingTime !== null) {
        $s = trim((string)$incomingTime);
        if (preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
          $hh = str_pad((string)intval($m[1]), 2, '0', STR_PAD_LEFT);
          $mm = $m[2];
          $incomingTime = $hh . ':' . $mm;
        }
        // Decide target column based on actual schema
        $hasEstimated = table_has_column('orders','estimated_delivery_time');
        $hasDelivery = table_has_column('orders','delivery_time');
        $targetCol = $hasEstimated ? 'estimated_delivery_time' : ($hasDelivery ? 'delivery_time' : null);
        if ($targetCol !== null) {
          // Reflect into top-level
          $body[$targetCol] = $incomingTime;
          // Reflect into data/values
          if (isset($body['data']) && is_array($body['data'])) { $body['data'][$targetCol] = $incomingTime; }
          if (isset($body['values']) && is_array($body['values'])) { $body['values'][$targetCol] = $incomingTime; }
        }
      }
    }
  } catch (Throwable $eNorm) { /* ignore normalization errors */ }

  try {
    if ($action === 'select') {
      $columns = $body['columns'] ?? '*';
      $params = [];
      // Inject default is_active=true filter for accounts if none provided (to match Supabase semantics)
      try {
        if ($table === 'accounts') {
          $hasActiveFilter = false;
          if (!empty($body['filters']) && is_array($body['filters'])) {
            foreach ($body['filters'] as $fchk) {
              $col = isset($fchk['column']) ? strtolower(trim((string)$fchk['column'])) : '';
              if ($col === 'is_active') { $hasActiveFilter = true; break; }
            }
          }
          if (!$hasActiveFilter) {
            if (empty($body['filters']) || !is_array($body['filters'])) { $body['filters'] = []; }
            $body['filters'][] = [ 'column' => 'is_active', 'op' => 'eq', 'value' => true ];
          }
        }
      } catch (Throwable $e){ /* ignore */ }
      // Proceed even if schema cache cannot confirm existence; let query decide

      if (is_string($columns) && preg_match('/[()]/', $columns)) {
        $raw = trim($columns);
        $sql = build_relational_select_sql($table, $raw, $body['order'] ?? [], $body['limit'] ?? null, $body['offset'] ?? null, $body['filters'] ?? [], $params);
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        $rows = $st->fetchAll();

        // Decode JSON relation columns back to native arrays/objects
        $relNames = [];
        $tokens = tokenize_columns($raw);
        foreach ($tokens as $tok) {
          $tok = trim($tok);
          if (strpos($tok, '(') !== false && substr($tok, -1) === ')') {
            $pos = strpos($tok, '(');
            $relNamePart = trim(substr($tok, 0, $pos));
            if (strpos($relNamePart, '!') !== false) { $relNamePart = explode('!', $relNamePart, 2)[0]; }
            if (strpos($relNamePart, ':') !== false) { $relNamePart = explode(':', $relNamePart, 2)[0]; }
            $rel = sanitize_ident($relNamePart);
            $relNames[$rel] = true;
          }
        }
        if (!empty($relNames) && is_array($rows)) {
          foreach ($rows as &$r) {
            foreach (array_keys($relNames) as $rn) {
              if (array_key_exists($rn, $r) && is_string($r[$rn])) {
                $dec = json_decode($r[$rn], true);
                if (json_last_error() === JSON_ERROR_NONE) { $r[$rn] = $dec; }
                elseif ($r[$rn] === '' || $r[$rn] === null) { $r[$rn] = null; }
              }
            }
          }
          unset($r);
        }

        $result = !empty($body['single']) ? ($rows ? $rows[0] : null) : $rows;
        respond($result);
      }

      // Decide path: if filters/orders contain dotted columns, use relational path even without parentheses
      $hasDottedFilters = false; $hasDottedOrder = false;
      foreach (($body['filters'] ?? []) as $fchk) { if (isset($fchk['column']) && strpos((string)$fchk['column'], '.') !== false) { $hasDottedFilters = true; break; } }
      foreach (($body['order'] ?? []) as $ochk) { if (isset($ochk['column']) && strpos((string)$ochk['column'], '.') !== false) { $hasDottedOrder = true; break; } }
      if ($hasDottedFilters || $hasDottedOrder) {
        $sql = build_relational_select_sql($table, '*', $body['order'] ?? [], $body['limit'] ?? null, $body['offset'] ?? null, $body['filters'] ?? [], $params);
      } else {
        // Simple select
        $colsSql = '*';
        if (is_string($columns) && trim($columns) !== '*') {
          $baseCols = get_table_columns($table);
          $parts = array_filter(array_map('trim', explode(',', trim($columns))), function($c){ return $c !== ''; });
          $qparts = [];
          foreach ($parts as $p) { $p = preg_replace('/\s+AS\s+.*/i', '', $p); $c = sanitize_ident($p); if (isset($baseCols[$c])) $qparts[] = '`' . $c . '`'; }
          $colsSql = $qparts ? implode(', ', $qparts) : '*';
        }

        $where = build_where($body['filters'] ?? [], $params, $table);
        $sql = 'SELECT ' . $colsSql . ' FROM `' . $table . '`' . ($where ? ' ' . $where : '');

        if (!empty($body['order']) && is_array($body['order'])) {
          $baseCols = get_table_columns($table);
          $orderParts = [];
          foreach ($body['order'] as $ord) {
            $rawCol = trim($ord['column'] ?? '');
            if ($rawCol === '') continue;
            $dir = (isset($ord['ascending']) && $ord['ascending'] === false) ? 'DESC' : 'ASC';
            $colOnly = (strpos($rawCol, '.') !== false) ? trim(explode('.', $rawCol, 2)[1]) : $rawCol;
            $colOnly = sanitize_ident($colOnly);
            if (isset($baseCols[$colOnly])) $orderParts[] = '`' . $colOnly . '` ' . $dir;
          }
          if ($orderParts) $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        if (isset($body['limit']) && is_numeric($body['limit'])) $sql .= ' LIMIT ' . intval($body['limit']);
        if (isset($body['offset']) && is_numeric($body['offset'])) $sql .= ' OFFSET ' . intval($body['offset']);
      }

      $st = $pdo->prepare($sql);
      foreach ($params as $k => $v) $st->bindValue($k, $v);
      $st->execute();
      $rows = $st->fetchAll();

      // Fallback for accounts when no active rows are returned: fetch canonical active set
      if ($table === 'accounts' && is_array($rows) && count($rows) === 0) {
        try {
          $fallbackSql = "SELECT * FROM `accounts` WHERE `account_name` IN ('إيرادات المبيعات','الشبكة','بنك','ذمم مدينة','مصروفات','مصروفات عامة','نقدية') ORDER BY account_type ASC, account_name ASC";
          $stFb = $pdo->query($fallbackSql);
          $rows = $stFb->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $eFb) { /* ignore */ }
      }

      $result = !empty($body['single']) ? ($rows ? $rows[0] : null) : $rows;
      respond($result);
    }

    if ($action === 'insert') {
      $data = $body['data'] ?? null;
      if (!$data) respond(null, [ 'message' => 'No data to insert' ], 400);
      $rows = is_assoc($data) ? [$data] : $data;
      if (!is_array($rows) || count($rows) === 0) respond(null, [ 'message' => 'Invalid insert data' ], 400);

      // Auto-fill id/created_at if table contains these columns
      $colSet = get_table_columns($table);
      $hasId = isset($colSet['id']);
      $hasCreatedAt = isset($colSet['created_at']);
      foreach ($rows as &$r) {
        if ($hasId && (!array_key_exists('id', $r) || $r['id'] === null || $r['id'] === '')) {
          $r['id'] = generate_uuid_v4();
        }
        if ($hasCreatedAt && (!array_key_exists('created_at', $r) || $r['created_at'] === null || $r['created_at'] === '')) {
          $r['created_at'] = date('Y-m-d H:i:s');
        }
        // Normalize invoice_number for invoices table to prevent saving 'Array'
        if (strtolower($table) === 'invoices') {
          $inv = isset($r['invoice_number']) ? $r['invoice_number'] : null;
          // Extract from arrays/objects
          if (is_array($inv)) {
            if (isset($inv['invoice_number'])) { $inv = $inv['invoice_number']; }
            elseif (isset($inv['result'])) { $inv = $inv['result']; }
            else {
              $first = reset($inv);
              if (is_array($first)) {
                if (isset($first['invoice_number'])) { $inv = $first['invoice_number']; }
                elseif (isset($first['result'])) { $inv = $first['result']; }
                else { $inv = (string)reset($first); }
              } else {
                $inv = (string)$first;
              }
            }
          } elseif (is_object($inv)) {
            $arr = (array)$inv;
            if (isset($arr['invoice_number'])) { $inv = $arr['invoice_number']; }
            elseif (isset($arr['result'])) { $inv = $arr['result']; }
            else { $inv = ''; }
          }
          // Generate if empty
          if (!is_string($inv) || trim((string)$inv) === '') {
            try {
              $stG = pdo()->query('SELECT generate_invoice_number() AS num');
              $rowG = $stG->fetch(PDO::FETCH_ASSOC);
              $inv = (string)($rowG['num'] ?? '');
            } catch (Throwable $eGen) {
              $inv = 'INV-' . date('Ymd') . '-' . substr(md5(uniqid('', true)), 0, 5);
            }
          }
          $r['invoice_number'] = (string)$inv;
        }
      }
      unset($r);

      $cols = array_keys($rows[0]);
      $colIdents = array_map(function($c){ return '`' . sanitize_ident($c) . '`'; }, $cols);
      $placeholders = '(' . implode(',', array_map(function($c){ return ':' . $c; }, $cols)) . ')';
      // Insert rows one-by-one to avoid PDO named parameter conflicts in multi-row VALUES
      $sql = "INSERT INTO `$table` (" . implode(',', $colIdents) . ") VALUES " . $placeholders;
      $st = pdo()->prepare($sql);
      foreach ($rows as $i => $r) {
        if ($table === 'orders') {
            $r['estimated_delivery_time'] = $body['estimated_delivery_time'] ?? null;
        }
        foreach ($cols as $c) { $__v = $r[$c] ?? null; if (is_bool($__v)) $__v = $__v ? 1 : 0; $st->bindValue(':' . $c, $__v); }
        $st->execute();
      }

      // بعد الإدخال: إرسال إشعارات متابعة للطلبات/الفواتير/المدفوعات
      try {
        $t = strtolower($table);
        $num = get_followup_number();
        if ($num) {
          if ($t === 'orders') {
            foreach ($rows as $r) {
              $orderNo = (string)($r['order_number'] ?? '');
              $status = (string)($r['status'] ?? 'in_progress');
              $amount = isset($r['total_amount']) ? (float)$r['total_amount'] : null;
              $ctx = get_order_context($r['id'] ?? null);
              $ctx['timestamp'] = date('Y-m-d H:i:s');
              if (!isset($ctx['total_amount'])) { $ctx['total_amount'] = $ctx['amount'] ?? ''; }
              if (!isset($ctx['customer_phone'])) { $ctx['customer_phone'] = isset($r['customer_phone']) ? (string)$r['customer_phone'] : ''; }
              // Send follow-up using template (no static text)
              send_followup_event('new_order_notification', $ctx);
              // Customer-facing template
              // أرسل رسالة الطلب الجديد فقط إذا كان للطلب بنود مسجلة لتفادي رسالة بنود فارغة
              $oidTmp = $r['id'] ?? null;
              if ($oidTmp) {
                try {
                  $chk = pdo()->prepare("SELECT 1 FROM order_items WHERE order_id = :id LIMIT 1");
                  $chk->execute([':id' => $oidTmp]);
                  if ($chk->fetchColumn()) {
                    send_order_template_message($oidTmp, 'order_created');
                  }
                } catch (Throwable $eChk) { /* ignore and skip sending */ }
              }
            }
          } elseif ($t === 'invoices') {
            foreach ($rows as $r) {
              $invNo = (string)($r['invoice_number'] ?? '');
              $orderNo = (string)($r['order_number'] ?? '');
              if ($orderNo === '' && !empty($r['order_id'])) {
                try { $stO = pdo()->prepare('SELECT order_number FROM orders WHERE id = :id LIMIT 1'); $stO->execute([':id' => $r['order_id']]); $rowO = $stO->fetch(PDO::FETCH_ASSOC); if ($rowO && !empty($rowO['order_number'])) { $orderNo = (string)$rowO['order_number']; } } catch (Throwable $eO) { /* ignore */ }
              }
              $amount = isset($r['total_amount']) ? (float)$r['total_amount'] : (isset($r['amount']) ? (float)$r['amount'] : null);
              $ctx = [
                'invoice_number' => $invNo,
                'order_number' => $orderNo,
                'amount' => is_null($amount) ? '' : number_format($amount, 2),
                'timestamp' => date('Y-m-d H:i:s'),
              ];
              // Use templated follow-up message if a template exists
              send_followup_event('invoice_notification', $ctx);
            }
          } elseif ($t === 'payments') {
            foreach ($rows as $r) {
              $orderNo = (string)($r['order_number'] ?? '');
              $orderId = $r['order_id'] ?? null;
              if ($orderNo === '' && !empty($orderId)) {
                try { $stO = pdo()->prepare('SELECT order_number FROM orders WHERE id = :id LIMIT 1'); $stO->execute([':id' => $orderId]); $rowO = $stO->fetch(PDO::FETCH_ASSOC); if ($rowO && !empty($rowO['order_number'])) { $orderNo = (string)$rowO['order_number']; } } catch (Throwable $eO) { /* ignore */ }
              }
              $amount = isset($r['amount']) ? (float)$r['amount'] : (isset($r['paid_amount']) ? (float)$r['paid_amount'] : null);

              // Enrich payment context from DB
              $db = [ 'id' => null, 'order_number' => $orderNo, 'customer_name' => '', 'customer_phone' => '', 'total_amount' => null ];
              try {
                if ($orderNo !== '') {
                  $st = pdo()->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.order_number = :n LIMIT 1");
                  $st->execute([':n' => $orderNo]); $rr = $st->fetch(PDO::FETCH_ASSOC) ?: null; if ($rr) { $db = array_merge($db, $rr); }
                }
                if (!$db['id'] && !empty($orderId)) {
                  $st = pdo()->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.id = :id LIMIT 1");
                  $st->execute([':id' => $orderId]); $rr2 = $st->fetch(PDO::FETCH_ASSOC) ?: null; if ($rr2) { $db = array_merge($db, $rr2); }
                }
              } catch (Throwable $eDb) { /* ignore */ }

              $paidSum = null; $remaining = null; $totalAmt = isset($db['total_amount']) ? (float)$db['total_amount'] : null;
              if (!empty($db['id'])) {
                try { $st2 = pdo()->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :id'); $st2->execute([':id' => $db['id']]); $row2 = $st2->fetch(PDO::FETCH_ASSOC); $paidSum = (float)($row2['s'] ?? 0); } catch (Throwable $eP) { $paidSum = null; }
              }
              if ($totalAmt !== null && $paidSum !== null) { $remaining = max(0, $totalAmt - $paidSum); }

              $ctx = [
                'order_number' => $db['order_number'] ?: $orderNo,
                'customer_name' => (string)($db['customer_name'] ?? ''),
                'customer_phone' => (string)($db['customer_phone'] ?? ''),
                'amount' => is_null($amount) ? '' : number_format($amount, 2),
                'total_amount' => ($totalAmt !== null ? number_format($totalAmt, 2) : ''),
                'paid_amount' => ($paidSum !== null ? number_format($paidSum, 2) : ''),
                'remaining_amount' => ($remaining !== null ? number_format($remaining, 2) : ''),
                'status' => ($remaining !== null && $remaining <= 0 ? '✅ مدفوع بالكامل' : ''),
                'timestamp' => date('Y-m-d H:i:s'),
              ];
              // Backward-compatible aliases for template variables
              $ctx['total'] = $ctx['total_amount'];
              $ctx['paid'] = $ctx['paid_amount'];
              $ctx['remaining'] = $ctx['remaining_amount'];
              $ctx['customer_whatsapp'] = $ctx['customer_phone'];
              $ctx['phone'] = $ctx['customer_phone'];
              $ctx['whatsapp'] = $ctx['customer_phone'];
              $ctx['phoneNumber'] = $ctx['customer_phone'];
              $typeRaw = (string)($r['payment_method'] ?? ($r['payment_type'] ?? ''));
              $ctx['payment_method'] = map_payment_type($typeRaw);
              if (empty($ctx['payment_date'])) {
                $pd = isset($r['payment_date']) ? (string)$r['payment_date'] : (isset($r['paid_at']) ? (string)$r['paid_at'] : '');
                $ctx['payment_date'] = $pd !== '' ? to_ar_digits($pd) : to_ar_digits(date('Y-m-d'));
              }
              $ctx['time_short'] = ar_time_short(time());
              $ctx['order_total'] = $ctx['total_amount'];
              $ctx['amount_paid'] = $ctx['paid_amount'];
              $ctx['balance_due'] = $ctx['remaining_amount'];
              $ctx['order_status'] = $ctx['status'];
              $ctx['payment_time'] = $ctx['timestamp'];
              // Pre-check to prevent duplicate payment notifications from multiple sources
              try {
                $ordC = (string)$ctx['order_number'];
                $amtC = isset($r['amount']) ? (float)$r['amount'] : (isset($r['paid_amount']) ? (float)$r['paid_amount'] : (float)preg_replace('/[^0-9.\-]/', '', (string)($ctx['amount'] ?? '0')));
                $dk = 'payment_event|' . $ordC . '|' . number_format($amtC, 2);
                $stChk = pdo()->prepare("SELECT id FROM whatsapp_messages WHERE created_at > (NOW() - INTERVAL 5 MINUTE) AND (dedupe_key LIKE CONCAT(:dk, '%') OR (message_content LIKE :ord AND message_content LIKE :amt)) LIMIT 1");
                $stChk->execute([':dk' => $dk, ':ord' => '%' . $ordC . '%', ':amt' => '%' . number_format($amtC, 2) . '%']);
                $existsMsg = $stChk->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$existsMsg) {
                  // Try both historical keys: payment_logged_notification and new_payment_notification
                  // Disabled to avoid duplicates; use notify-new-payment endpoint instead
                  // send_followup_event('payment_logged_notification', $ctx);
                }
              } catch (Throwable $eDup) {
                // Disabled to avoid duplicates; use notify-new-payment endpoint instead
                // send_followup_event('payment_logged_notification', $ctx);
              }
            }
          }
          process_whatsapp_queue(min(count($rows) * 2, 50));
        }
        // Always send customer-facing template for new orders even if no follow-up number is configured
        if ($t === 'orders') {
          foreach ($rows as $r) {
            // أرسل رسالة الطلب الجديد فقط إذا كان للطلب بنود مسجلة لتفادي رسالة بنود فارغة
            $oidTmp2 = $r['id'] ?? null;
            if ($oidTmp2) {
              try {
                $chk2 = pdo()->prepare("SELECT 1 FROM order_items WHERE order_id = :id LIMIT 1");
                $chk2->execute([':id' => $oidTmp2]);
                if ($chk2->fetchColumn()) {
                  send_order_template_message($oidTmp2, 'order_created');
                }
              } catch (Throwable $eChk2) { /* ignore */ }
            }
          }
          process_whatsapp_queue(min(count($rows) * 2, 50));
        }
      } catch (Throwable $eNotif) { /* ignore notification errors */ }

      respond(($body['returning'] ?? 'representation') === 'representation' ? $rows : ['inserted' => count($rows)]);
    }

    if ($action === 'update') {
      $values = $body['values'] ?? null;
      if (!$values || !is_array($values)) respond(null, [ 'message' => 'No values to update' ], 400);

      // Normalize invoice_number on updates to avoid saving 'Array'
      if (strtolower($table) === 'invoices' && array_key_exists('invoice_number', $values)) {
        $inv = $values['invoice_number'];
        if (is_array($inv)) {
          if (isset($inv['invoice_number'])) { $inv = $inv['invoice_number']; }
          elseif (isset($inv['result'])) { $inv = $inv['result']; }
          else {
            $first = reset($inv);
            if (is_array($first)) {
              if (isset($first['invoice_number'])) { $inv = $first['invoice_number']; }
              elseif (isset($first['result'])) { $inv = $first['result']; }
              else { $inv = (string)reset($first); }
            } else { $inv = (string)$first; }
          }
        } elseif (is_object($inv)) {
          $arr = (array)$inv;
          if (isset($arr['invoice_number'])) { $inv = $arr['invoice_number']; }
          elseif (isset($arr['result'])) { $inv = $arr['result']; }
          else { $inv = ''; }
        }
        if (!is_string($inv) || trim((string)$inv) === '') {
          try { $stG = pdo()->query('SELECT generate_invoice_number() AS num'); $rowG = $stG->fetch(PDO::FETCH_ASSOC); $inv = (string)($rowG['num'] ?? ''); }
          catch (Throwable $eGen) { $inv = 'INV-' . date('Ymd') . '-' . substr(md5(uniqid('', true)), 0, 5); }
        }
        $values['invoice_number'] = (string)$inv;
      }

      // Auto-set updated_at when column exists and not provided
      $colSet = get_table_columns($table);
      if (isset($colSet['updated_at']) && !array_key_exists('updated_at', $values)) {
        $values['updated_at'] = date('Y-m-d H:i:s');
      }

      // Build SET only for existing columns
      $sets = []; $params = [];
      if ($table === 'orders' && isset($body['estimated_delivery_time'])) {
        $values['estimated_delivery_time'] = $body['estimated_delivery_time'];
      }
      foreach ($values as $k => $v) {
        $col = sanitize_ident($k);
        if (!isset($colSet[$col])) continue; // ignore unknown columns
        $p = ":set_$col";
        $sets[] = "`$col` = $p";
        $params[$p] = $v;
      }
      if (empty($sets)) {
        respond(['updated' => 0, 'skipped' => 'no_valid_columns']);
      }
      // Capture before-update rows for orders to detect status changes
      $__beforeRows = []; $__affectedIds = [];
      if (strtolower($table) === 'orders') {
        try {
          $paramsSel = [];
          $whereSel = build_where($body['filters'] ?? [], $paramsSel, $table);
          if ($whereSel !== '') {
            $stB = pdo()->prepare("SELECT id, order_number, status FROM `orders` " . $whereSel);
            foreach ($paramsSel as $k => $v) { $stB->bindValue($k, $v); }
            $stB->execute();
            $__beforeRows = $stB->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($__beforeRows as $br) { if (!empty($br['id'])) $__affectedIds[] = $br['id']; }
          }
        } catch (Throwable $eB) { /* ignore */ }
      }
      $where = build_where($body['filters'] ?? [], $params, $table);
      $sql = "UPDATE `$table` SET " . implode(', ', $sets) . $where;
      $st = pdo()->prepare($sql);
      foreach ($params as $k => $v) { if (is_bool($v)) $v = $v ? 1 : 0; $st->bindValue($k, $v); }
      $st->execute();

      // After update: send follow-up notifications for orders (status changes or generic update)
      try {
        if (strtolower($table) === 'orders' && !empty($__affectedIds)) {
          $place = implode(',', array_fill(0, count($__affectedIds), '?'));
          $stA = pdo()->prepare("SELECT id, order_number, status FROM `orders` WHERE id IN ($place)");
          foreach (array_values($__affectedIds) as $i => $idv) { $stA->bindValue($i+1, $idv); }
          $stA->execute();
          $afterRows = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
          $beforeMap = [];
          foreach ($__beforeRows as $br) { $beforeMap[(string)$br['id']] = $br; }
          $num = get_followup_number();
          if ($num) {
            foreach ($afterRows as $ar) {
              $id = (string)($ar['id'] ?? '');
              $ordNo = (string)($ar['order_number'] ?? '');
              $newSt = (string)($ar['status'] ?? '');
              $oldSt = isset($beforeMap[$id]) ? (string)($beforeMap[$id]['status'] ?? '') : '';
              $changed = ($oldSt !== '' && $newSt !== '' && $oldSt !== $newSt);
              if ($changed) {
                $ctxFU = [
                  'order_number' => $ordNo,
                  'old_status' => $oldSt,
                  'new_status' => $newSt,
                  'status' => $newSt,
                  'timestamp' => date('Y-m-d H:i:s'),
                ];
                send_followup_event('order_status_changed', $ctxFU);
                $tplKey = map_status_to_template_key($newSt);
                send_order_template_message($id, $tplKey !== '' ? $tplKey : 'order_status_updated');
              } else {
                $ctxFU2 = [
                  'order_number' => $ordNo,
                  'timestamp' => date('Y-m-d H:i:s'),
                ];
                send_followup_event('order_updated', $ctxFU2);
              }
            }
            // Fixed limit (not tied to the number of orders): each order can enqueue up to 2 messages
            // (follow-up + customer), and pending-first ordering ensures these fresh messages are the
            // ones actually attempted here.
            process_whatsapp_queue(25);
          } else {
            // No follow-up number; still send customer-facing template when status changes
            foreach ($afterRows as $ar) {
              $id = (string)($ar['id'] ?? '');
              $newSt = (string)($ar['status'] ?? '');
              $oldSt = isset($beforeMap[$id]) ? (string)($beforeMap[$id]['status'] ?? '') : '';
              $changed = ($oldSt !== '' && $newSt !== '' && $oldSt !== $newSt);
              if ($changed) {
                $tplKey = map_status_to_template_key($newSt);
                send_order_template_message($id, $tplKey !== '' ? $tplKey : 'order_status_updated');
              }
            }
            // Fixed limit (not tied to the number of orders): each order can enqueue up to 2 messages
            // (follow-up + customer), and pending-first ordering ensures these fresh messages are the
            // ones actually attempted here.
            process_whatsapp_queue(25);
          }
        }
      } catch (Throwable $eNotifUp) { /* ignore notification errors */ }

      respond(['updated' => $st->rowCount()]);
    }

    if ($action === 'delete') {
      $params = [];
      $where = build_where($body['filters'] ?? [], $params, $table);
      $sql = "DELETE FROM `$table`" . $where;
      $st = pdo()->prepare($sql);
      foreach ($params as $k => $v) $st->bindValue($k, $v);
      $st->execute();
      respond(['deleted' => $st->rowCount()]);
    }

    if ($action === 'rpc') {
      $fn = sanitize_ident($body['fn'] ?? '');
      $params = $body['params'] ?? [];
      if (!is_array($params)) $params = [];
      $placeholders = []; foreach ($params as $k => $v) { $placeholders[] = ':' . sanitize_ident($k); }
      $sql = 'CALL `' . $fn . '`(' . implode(',', $placeholders) . ')';
      try { $st = pdo()->prepare($sql); foreach ($params as $k => $v) $st->bindValue(':' . sanitize_ident($k), $v); $st->execute(); $rows = $st->fetchAll(); respond($rows);
      } catch (Throwable $e) {
        $sql2 = 'SELECT `' . $fn . '`(' . implode(',', $placeholders) . ') AS result';
        $st2 = pdo()->prepare($sql2); foreach ($params as $k => $v) $st2->bindValue(':' . sanitize_ident($k), $v); $st2->execute(); $row = $st2->fetch(); respond($row);
      }
    }

    respond(null, [ 'message' => 'Unsupported db action' ], 400);
  } catch (Throwable $e) {
    respond(null, [ 'message' => $e->getMessage(), 'code' => 'db_error' ], 500);
  }
}

function handle_auth() {
  $body = read_json_body(); $action = $body['action'] ?? '';
  if ($action === 'signin') {
    $email = trim((string)($body['email'] ?? '')); $password = (string)($body['password'] ?? '');
    if ($email === '' || $password === '') respond(null, [ 'message' => 'Email and password required' ], 400);
    login_rl_check($email); // SECURITY: throttle brute-force login attempts
    $pdo = pdo(); ensure_users_table($pdo);
    $st = $pdo->prepare('SELECT id, email, password_hash, full_name, role, tenant_id FROM users WHERE email = :e LIMIT 1');
    $st->execute([':e' => $email]); $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
      login_rl_record_failure($email);
      respond(null, [ 'message' => 'Invalid credentials' ], 401);
    }
    login_rl_clear($email);
    // SaaS: block login if this user's tenant/agency subscription is
    // suspended or cancelled. platform_admin has no tenant_id and is exempt.
    $role = (string)($u['role'] ?? '');
    $tenantId = $u['tenant_id'] ?? null;
    if (strtolower($role) !== 'platform_admin') {
      if (!$tenantId) {
        respond(null, [ 'message' => 'الحساب غير مرتبط بوكالة، تواصل مع الدعم', 'code' => 'no_tenant' ], 403);
      }
      try {
        ensure_tenant_tables($pdo);
        $tst = $pdo->prepare('SELECT status, trial_ends_at FROM tenants WHERE id = :id LIMIT 1');
        $tst->execute([':id' => $tenantId]); $tenant = $tst->fetch();
        if ($tenant) {
          if ($tenant['status'] === 'suspended' || $tenant['status'] === 'cancelled') {
            respond(null, [ 'message' => 'تم إيقاف اشتراك وكالتكم، يرجى تجديد الاشتراك للمتابعة', 'code' => 'tenant_suspended' ], 402);
          }
          if ($tenant['status'] === 'trial' && !empty($tenant['trial_ends_at']) && strtotime($tenant['trial_ends_at']) < time()) {
            respond(null, [ 'message' => 'انتهت الفترة التجريبية، يرجى الاشتراك للمتابعة', 'code' => 'trial_expired' ], 402);
          }
        }
      } catch (Throwable $eTenantCheck) { /* if tenant tables somehow missing, don't lock out legacy installs */ }
    }
    session_regenerate_id(true); // SECURITY: rotate session id on privilege change (login)
    $user = [ 'id' => (string)$u['id'], 'email' => $u['email'], 'full_name' => $u['full_name'], 'role' => $u['role'], 'tenant_id' => $tenantId ];
    $_SESSION['user'] = $user; respond([ 'user' => $user ]);
  }
  if ($action === 'signup') {
    $email = trim((string)($body['email'] ?? '')); $password = (string)($body['password'] ?? ''); $meta = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
    if ($email === '' || $password === '') respond(null, [ 'message' => 'Email and password required' ], 400);
    $pdo = pdo(); ensure_users_table($pdo); ensure_tenant_tables($pdo);
    $st = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1'); $st->execute([':e' => $email]); if ($st->fetch()) respond(null, [ 'message' => 'User already exists' ], 409);
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // SaaS: public signup always creates a brand-new tenant (agency) with its
    // own trial subscription — never trust a client-supplied role/tenant_id.
    // The signing-up user becomes that tenant's admin. Joining an EXISTING
    // tenant as a teammate is a separate, invite-based flow (not this one).
    $agencyName = trim((string)($meta['agency_name'] ?? $meta['full_name'] ?? 'وكالة جديدة'));
    $tenantId = generate_uuid_v4();
    $defaultPlanId = $pdo->query("SELECT id FROM subscription_plans ORDER BY price_monthly ASC LIMIT 1")->fetchColumn() ?: null;
    $trialEnds = date('Y-m-d H:i:s', strtotime('+14 days'));
    $pdo->prepare("INSERT INTO tenants (id, name, status, plan_id, trial_ends_at, created_at) VALUES (:id, :n, 'trial', :p, :t, NOW())")
        ->execute([':id' => $tenantId, ':n' => $agencyName, ':p' => $defaultPlanId, ':t' => $trialEnds]);

    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'id'")->fetch();
    $idIsAutoIncrement = $cols && stripos((string)($cols['Extra'] ?? ''), 'auto_increment') !== false;
    $roleForNewUser = 'admin'; // first user of a new tenant is always its admin
    if ($idIsAutoIncrement) {
      $st = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, tenant_id, created_at) VALUES (:e, :h, :n, :r, :tid, NOW())');
      $st->execute([':e' => $email, ':h' => $hash, ':n' => (string)($meta['full_name'] ?? ''), ':r' => $roleForNewUser, ':tid' => $tenantId]);
      $id = (string)$pdo->lastInsertId();
    } else {
      $id = generate_uuid_v4();
      $st = $pdo->prepare('INSERT INTO users (id, email, password_hash, full_name, role, tenant_id, created_at) VALUES (:i, :e, :h, :n, :r, :tid, NOW())');
      $st->execute([':i' => $id, ':e' => $email, ':h' => $hash, ':n' => (string)($meta['full_name'] ?? ''), ':r' => $roleForNewUser, ':tid' => $tenantId]);
    }
    $user = [ 'id' => $id, 'email' => $email, 'full_name' => (string)($meta['full_name'] ?? ''), 'role' => $roleForNewUser, 'tenant_id' => $tenantId ];
    // Keep legacy `user_roles` table (used by RoleProtectedRoute access checks) in sync with the role
    // assigned at signup, since this app's route guards check `user_roles`, not `users.role`.
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
        id VARCHAR(255) PRIMARY KEY,
        user_id VARCHAR(255) NULL,
        role VARCHAR(255) NULL,
        created_at VARCHAR(255) NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $rst = $pdo->prepare('INSERT INTO user_roles (id, user_id, role, created_at) VALUES (:i, :u, :r, :c)');
      $rst->execute([':i' => generate_uuid_v4(), ':u' => $id, ':r' => $roleForNewUser, ':c' => date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* ignore, non-critical sync */ }
    session_regenerate_id(true);
    $_SESSION['user'] = $user; respond([ 'user' => $user ]);
  }
  if ($action === 'signout') { unset($_SESSION['user']); respond([ 'ok' => true ]); }
  if ($action === 'whoami') {
    // Lets the frontend verify a locally-cached login against the REAL
    // server-side session, instead of blindly trusting a value that may be
    // stale (e.g. after the session expired or the server was restarted).
    // Deliberately never errors — "no session" is a normal, valid answer.
    respond([ 'user' => current_user() ]);
  }
  respond(null, [ 'message' => 'Unsupported auth action' ], 400);
}

function ensure_users_table($pdo) {
  // Create table if not exists (keep legacy INT id for compatibility with existing installs)
  $pdo->exec('CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NULL,
    role VARCHAR(64) NULL,
    whatsapp_number VARCHAR(32) NULL,
    tenant_id VARCHAR(36) NULL,
    created_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  // Ensure whatsapp_number/tenant_id columns exist for existing installations with older schema
  try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(32) NULL"); } catch (Throwable $e) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_number VARCHAR(32) NULL"); } catch (Throwable $e2) { /* ignore if exists */ }
  }
  try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS tenant_id VARCHAR(36) NULL"); } catch (Throwable $e) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tenant_id VARCHAR(36) NULL"); } catch (Throwable $e2) { /* ignore if exists */ }
  }
}

/**
 * Creates the SaaS platform tables (tenants, subscription_plans,
 * tenant_subscriptions) if they don't already exist, and seeds a starter set
 * of plans. Safe to call repeatedly (idempotent). This is also called by the
 * standalone migration script (api/migrate_tenant.php).
 */
function ensure_tenant_tables($pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS platform_content (
    content_key VARCHAR(191) PRIMARY KEY,
    content_value LONGTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

  // Seed starter plans once (placeholder pricing — adjust in phase 3 /
  // the subscription page before going live with real billing).
  $count = (int)$pdo->query("SELECT COUNT(*) FROM subscription_plans")->fetchColumn();
  if ($count === 0) {
    $seed = $pdo->prepare("INSERT INTO subscription_plans (id, name, price_monthly, currency, max_users, max_orders_per_month, features, is_active, created_at) VALUES (:id,:n,:p,'SAR',:mu,:mo,:f,1,NOW())");
    $seed->execute([':id'=>generate_uuid_v4(), ':n'=>'أساسية',  ':p'=>99,  ':mu'=>3,  ':mo'=>100,  ':f'=>json_encode(['whatsapp'=>true,'ai'=>false])]);
    $seed->execute([':id'=>generate_uuid_v4(), ':n'=>'احترافية', ':p'=>249, ':mu'=>10, ':mo'=>1000, ':f'=>json_encode(['whatsapp'=>true,'ai'=>true])]);
    $seed->execute([':id'=>generate_uuid_v4(), ':n'=>'أعمال',    ':p'=>599, ':mu'=>null,':mo'=>null, ':f'=>json_encode(['whatsapp'=>true,'ai'=>true,'priority_support'=>true])]);
  }
}

/**
 * Returns the fixed "legacy" tenant id used to own all data that existed
 * before this multi-tenant migration (i.e. your original single agency).
 * Creates that tenant row (status=active, unlimited/"أعمال" plan) if missing.
 */
function ensure_legacy_tenant($pdo) {
  ensure_tenant_tables($pdo);
  $legacyId = '00000000-0000-0000-0000-000000000001';
  $exists = $pdo->prepare("SELECT id FROM tenants WHERE id = :id LIMIT 1");
  $exists->execute([':id' => $legacyId]);
  if (!$exists->fetchColumn()) {
    $planId = $pdo->query("SELECT id FROM subscription_plans ORDER BY price_monthly DESC LIMIT 1")->fetchColumn() ?: null;
    $ins = $pdo->prepare("INSERT INTO tenants (id, name, slug, status, plan_id, created_at) VALUES (:id, :n, :s, 'active', :p, NOW())");
    $ins->execute([':id' => $legacyId, ':n' => 'الوكالة الأصلية', ':s' => 'legacy', ':p' => $planId]);
  }
  return $legacyId;
}

// ---------------------------------------------------------------------------
// Moyasar payment gateway (Saudi/Gulf card processor) — subscription billing.
// Docs: https://docs.moyasar.com/ . Uses HTTP Basic Auth with the SECRET key
// (never the publishable key) for all server-side calls.
// ---------------------------------------------------------------------------

function moyasar_secret_key() {
  return getenv('MOYASAR_SECRET_KEY') ?: (defined('MOYASAR_SECRET_KEY') ? MOYASAR_SECRET_KEY : '');
}

function moyasar_callback_url($subscriptionId) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  // The frontend route that finishes the checkout after Moyasar redirects back.
  return $scheme . '://' . $host . '/billing/checkout/complete?subscription_id=' . rawurlencode($subscriptionId);
}

/**
 * Fetches a payment's real status directly from Moyasar (never trusts the
 * client), and if paid, activates the tenant + subscription + sets a
 * 30-day billing period. Idempotent: safe to call more than once for the
 * same payment (e.g. once from the frontend redirect, once from the webhook).
 */
function moyasar_verify_and_activate($subscriptionId, $paymentId, $tenantId) {
  $secretKey = moyasar_secret_key();
  if ($secretKey === '') {
    return ['ok' => false, 'message' => 'بوابة الدفع غير مُهيأة بعد (MOYASAR_SECRET_KEY مفقود في config.php)'];
  }
  $ch = curl_init('https://api.moyasar.com/v1/payments/' . rawurlencode($paymentId));
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $secretKey . ':',
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = json_decode((string)$resp, true);
  if ($code !== 200 || !is_array($data)) {
    return ['ok' => false, 'message' => 'تعذر التحقق من حالة الدفع لدى مزوّد الدفع'];
  }
  $status = (string)($data['status'] ?? '');
  if ($status !== 'paid') {
    return ['ok' => false, 'message' => 'لم تكتمل عملية الدفع بعد', 'gateway_status' => $status];
  }

  $pdo = pdo();
  $st = $pdo->prepare("SELECT * FROM tenant_subscriptions WHERE id = :id AND tenant_id = :tid LIMIT 1");
  $st->execute([':id' => $subscriptionId, ':tid' => $tenantId]);
  $sub = $st->fetch(PDO::FETCH_ASSOC);
  if (!$sub) return ['ok' => false, 'message' => 'اشتراك غير معروف'];

  // Idempotency guard: if this subscription is already active with this exact
  // payment reference, don't re-extend the period on a duplicate call.
  if ($sub['status'] === 'active' && $sub['payment_gateway_ref'] === $paymentId) {
    return ['ok' => true, 'already_processed' => true];
  }

  $periodEnd = date('Y-m-d H:i:s', strtotime('+30 days'));
  $pdo->prepare("UPDATE tenant_subscriptions SET status = 'active', payment_gateway_ref = :ref, current_period_start = NOW(), current_period_end = :end WHERE id = :id")
      ->execute([':ref' => $paymentId, ':end' => $periodEnd, ':id' => $subscriptionId]);
  $pdo->prepare("UPDATE tenants SET status = 'active', plan_id = :pid, suspended_at = NULL WHERE id = :tid")
      ->execute([':pid' => $sub['plan_id'], ':tid' => $tenantId]);

  return ['ok' => true, 'activated' => true, 'current_period_end' => $periodEnd];
}

function handle_storage() {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(null, [ 'message' => 'POST required' ], 405);
  // SECURITY: this endpoint previously had NO login requirement, NO file
  // type/size validation, and accepted a client-supplied "path" that could
  // contain "../" segments — allowing an unauthenticated attacker to upload
  // arbitrary files, including path traversal outside the uploads directory.
  require_auth();
  $bucket = isset($_POST['bucket']) ? preg_replace('/[^A-Za-z0-9_-]/', '_', $_POST['bucket']) : '';
  $path = isset($_POST['path']) ? trim($_POST['path'], "/ ") : '';
  if ($bucket === '' || $path === '') respond(null, [ 'message' => 'bucket and path required' ], 400);
  if ($bucket === 'platform') { require_role(['platform_admin']); } // homepage logo/hero images — platform-wide, not per-tenant
  if (!isset($_FILES['file'])) respond(null, [ 'message' => 'file required' ], 400);
  $ext = validate_upload_or_die($_FILES['file']);

  // Strip any ".." path segments and re-derive a clean relative directory.
  $pathParts = array_values(array_filter(explode('/', $path), function ($seg) {
    return $seg !== '' && $seg !== '.' && $seg !== '..';
  }));
  $subDir = implode('/', array_slice($pathParts, 0, -1));
  $baseName = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)end($pathParts));
  if ($baseName === '' || $baseName === false) $baseName = bin2hex(random_bytes(8)) . '.' . $ext;

  // BUG FIX: `uploads/` is a symlink to shared/uploads/ in the deploy layout
  // (see scripts/activate_release.sh). realpath() must be called on the
  // FULL path (through the symlink) so it resolves to the same canonical
  // path that $resolvedDir below will resolve to. The previous version only
  // resolved the parent directory and string-appended "/uploads", which
  // never matched the symlink-resolved target — causing EVERY upload to
  // fail with "مسار غير صالح", regardless of permissions.
  $uploadsRoot = realpath(__DIR__ . '/../uploads');
  if ($uploadsRoot === false) respond(null, [ 'message' => 'مجلد uploads غير موجود على الخادم' ], 500);
  $targetDir = $uploadsRoot . DIRECTORY_SEPARATOR . $bucket . ($subDir !== '' ? DIRECTORY_SEPARATOR . $subDir : '');
  if (!is_dir($targetDir)) @mkdir($targetDir, 0775, true);

  // Defense in depth: confirm the resolved directory is still inside uploads/.
  $resolvedDir = realpath($targetDir);
  if ($resolvedDir === false || strpos($resolvedDir, $uploadsRoot) !== 0) {
    respond(null, [ 'message' => 'مسار غير صالح' ], 400);
  }

  $targetFile = $resolvedDir . DIRECTORY_SEPARATOR . $baseName;
  if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) respond(null, [ 'message' => 'Upload failed' ], 500);
  $public = '/uploads/' . $bucket . ($subDir !== '' ? '/' . $subDir : '') . '/' . $baseName;
  $public = preg_replace('#/+#', '/', $public);
  respond([ 'path' => $public ]);
}

function handle_functions() {
  $name = isset($_GET['name']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['name']) : '';
  // Ensure evaluations schema exists before handling related endpoints
  ensure_evaluations_schema();

  // New endpoint: submit-evaluation
  if ($name === 'submit-evaluation') {
    try {
      $b = read_json_body();
      $token = trim((string)($b['evaluation_token'] ?? $b['token'] ?? ''));
      if ($token === '') respond(null, [ 'message' => 'missing_token' ], 400);
      $pdo = pdo();
      $st = $pdo->prepare("SELECT id FROM evaluations WHERE evaluation_token = :t LIMIT 1");
      $st->execute([':t' => $token]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row || empty($row['id'])) respond(null, [ 'message' => 'not_found' ], 404);
      $id = (string)$row['id'];

      $fieldsIn = [
        'overall_rating' => $b['overall_rating'] ?? $b['rating'] ?? null,
        'service_quality_rating' => $b['service_quality_rating'] ?? $b['service_quality'] ?? null,
        'delivery_time_rating' => $b['delivery_time_rating'] ?? $b['delivery_time'] ?? null,
        'communication_rating' => $b['communication_rating'] ?? $b['communication'] ?? null,
        'price_value_rating' => $b['price_value_rating'] ?? $b['price_value'] ?? null,
        'would_recommend' => $b['would_recommend'] ?? null,
        'feedback_text' => $b['feedback_text'] ?? $b['comment'] ?? $b['feedback'] ?? null,
        'suggestions' => $b['suggestions'] ?? null,
      ];
      $cols = get_table_columns('evaluations');
      $setParts = [];
      $params = [ ':id' => $id ];
      foreach ($fieldsIn as $k => $v) {
        if (!isset($cols[$k]) || $v === null) continue;
        if ($k === 'would_recommend') {
          $v = ($v === true || $v === 'true' || $v === 1 || $v === '1') ? 1 : (($v === false || $v === 'false' || $v === 0 || $v === '0') ? 0 : null);
        }
        if ($k === 'overall_rating' || strpos($k, '_rating') !== false) {
          $n = (int)$v; if ($n < 0) $n = 0; if ($n > 5) $n = 5; $v = $n;
        }
        $setParts[] = "`$k` = :$k";
        $params[":".$k] = $v;
      }
      if (isset($cols['submitted_at'])) { $setParts[] = "submitted_at = NOW()"; }
      if (empty($setParts)) respond([ 'updated' => false, 'message' => 'no_fields' ]);
      $sql = "UPDATE evaluations SET " . implode(', ', $setParts) . " WHERE id = :id";
      $up = $pdo->prepare($sql);
      $up->execute($params);
      respond([ 'updated' => true ]);
    } catch (Throwable $e) {
      respond(null, [ 'message' => 'save_failed', 'error' => $e->getMessage() ], 500);
    }
  }
  // Early handlers for specific function names (compatible with old Supabase edge calls)
  if ($name === 'send-whatsapp-simple' || $name === 'send_whatsapp_simple') {
    $body = read_json_body();
    $phone = trim((string)($body['phone'] ?? $body['to'] ?? $body['phoneNumber'] ?? ''));
    $message = (string)($body['message'] ?? $body['text'] ?? $body['messageText'] ?? '');
    $type = (string)($body['webhook_type'] ?? 'whatsapp_direct');

    if ($phone === '' || $message === '') {
      respond(null, [ 'message' => 'Missing phone or message', 'code' => 'bad_request' ], 400);
    }

    // Normalize phone for downstream processors (digits only)
    $toDigits = preg_replace('/\D+/', '', $phone);
    $to = $toDigits !== '' ? $toDigits : ltrim($phone, '+');

    // Enqueue and attempt immediate processing of a single message
    $dedupe = $type . '|' . substr(md5($to . '|' . substr($message, 0, 160)), 0, 12);
    $queueRes = enqueue_followup_message($to, $message, $type, $dedupe);

    $msgId = is_array($queueRes) && isset($queueRes['id']) ? $queueRes['id'] : null;
    $procRes = process_whatsapp_queue(1);

    // Determine success/status compatible with legacy front-end checks
    $success = !(is_array($queueRes) && isset($queueRes['error']));
    $statusStr = $success ? 'pending' : 'error';

    if (is_array($procRes)) {
      $sentForThis = null;
      if (isset($procRes['details']) && is_array($procRes['details']) && $msgId) {
        foreach ($procRes['details'] as $d) {
          if (isset($d['id']) && $d['id'] === $msgId) {
            $sentForThis = $d;
            break;
          }
        }
      }
      if ($sentForThis !== null) {
        $success = !!($sentForThis['ok'] ?? false);
        $statusStr = $success ? 'sent' : 'failed';
      } else if (($procRes['processed'] ?? 0) > 0 && ($procRes['errors'] ?? 0) === 0) {
        // processed but not matched id (rare), assume sent
        $statusStr = 'sent';
        $success = true;
      }
    }

    respond([
      'ok' => $success,
      'success' => $success,
      'status' => $statusStr,
      'echo' => [
        'phone' => $phone,
        'phone_normalized' => $to,
        'message' => $message,
        'webhook_type' => $type,
      ],
      'function' => 'send-whatsapp-simple',
      'queue' => $queueRes,
      'processor' => $procRes
    ]);
  }
  if ($name === 'analyze-evaluations') {
    try {
      $pdo = pdo();
      $cols = get_table_columns('evaluations');
      $metrics = [];

      $mk = function($field) use ($pdo, $cols) {
        if (!isset($cols[$field])) return null;
        $sql = "SELECT AVG(CASE WHEN `" . $field . "` IS NOT NULL AND `" . $field . "` >= 0 THEN `" . $field . "` END) AS avg_val, COUNT(CASE WHEN `" . $field . "` IS NOT NULL THEN 1 END) AS cnt FROM evaluations";
        $st = $pdo->query($sql);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [ 'avg' => (float)($r['avg_val'] ?? 0), 'count' => (int)($r['cnt'] ?? 0) ];
      };

      $mkMulti = function($fields) use ($mk) {
        $best = null;
        foreach ($fields as $f) { $m = $mk($f); if ($m !== null) { $best = $m; break; } }
        return $best ?: [ 'avg' => 0.0, 'count' => 0 ];
      };

      // Map to MySQL columns used by the form (support multiple possible names)
      $metrics['service_quality'] = $mkMulti(['service_quality_rating','service_quality']);
      $metrics['communication']   = $mkMulti(['communication_rating','communication']);
      $metrics['delivery_time']   = $mkMulti(['delivery_time_rating','delivery_time']);
      $metrics['price_value']     = $mkMulti(['price_value_rating','price_value']);

      $overall = $mkMulti(['overall_rating','rating']);
      // Derive overall if not present
      if (($overall['count'] === 0 || $overall['avg'] === 0) &&
          ($metrics['service_quality']['count']>0 || $metrics['communication']['count']>0 || $metrics['delivery_time']['count']>0 || $metrics['price_value']['count']>0)) {
        $sum = 0; $cnt = 0; $maxCnt = 0;
        foreach (['service_quality','communication','delivery_time','price_value'] as $k) {
          if (!empty($metrics[$k]) && $metrics[$k]['count'] > 0 && $metrics[$k]['avg'] > 0) { $sum += $metrics[$k]['avg']; $cnt++; if ($metrics[$k]['count'] > $maxCnt) $maxCnt = $metrics[$k]['count']; }
        }
        $avg = $cnt > 0 ? ($sum / $cnt) : 0;
        $overall = [ 'avg' => $avg, 'count' => $maxCnt ];
      }
      $metrics['overall_rating'] = $overall;

      // Totals and recommendation
      $totalCount = 0;
      try { $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM evaluations")->fetchColumn(); } catch (Throwable $e) { $totalCount = 0; }

      $recommendPercent = 0.0;
      if (isset($cols['would_recommend'])) {
        try {
          $st = $pdo->query("SELECT COUNT(CASE WHEN (would_recommend = 1 OR would_recommend = '1' OR LOWER(TRIM(would_recommend)) IN ('true','t','yes','on')) THEN 1 END) AS rec, COUNT(*) AS total FROM evaluations");
          $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
          $rec = (int)($r['rec'] ?? 0); $tot = (int)($r['total'] ?? 0);
          $recommendPercent = $tot > 0 ? round(($rec * 100.0) / $tot, 2) : 0.0;
        } catch (Throwable $e) { $recommendPercent = 0.0; }
      }

      respond([ 'metrics' => $metrics, 'totals' => [ 'evaluations_count' => $totalCount, 'recommend_percent' => $recommendPercent ] ]);
    } catch (Throwable $e) {
      respond(null, [ 'message' => 'analyze_failed', 'error' => $e->getMessage() ], 500);
    }
  }

  // Detect content type and read payload accordingly
  $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? $_SERVER['HTTP_CONTENT_TYPE'] : '');
  $isMultipart = stripos($contentType, 'multipart/form-data') !== false;
  $payload = $isMultipart ? $_POST : read_json_body();

  // Test override for notify-new-order: allow test sends even if toggles are off (when follow-up number exists)
  if ($name === 'notify-new-order' && !empty(($payload['test'] ?? null))) {
    try {
      $num = get_followup_number();
      if (!$num) { respond([ 'message' => 'Notification disabled' ]); }
      $ctx = [
        'order_number' => (string)($payload['order_number'] ?? 'ORD-TEST-12345'),
        'customer_name' => (string)($payload['customer_name'] ?? 'عميل تجريبي'),
        'customer_phone' => (string)($payload['customer_phone'] ?? '+966501234567'),
        'service_name' => (string)($payload['service_name'] ?? 'خدمة تجريبية'),
        'order_description' => (string)($payload['order_description'] ?? 'طلب تجريبي لاختبار نظام الإشعارات'),
        'status' => (string)($payload['status'] ?? 'قيد الانتظار'),
        'due_date_ar' => (string)($payload['due_date_ar'] ?? to_ar_digits(date('Y-m-d'))),
        'total_amount' => isset($payload['total_amount']) ? number_format((float)$payload['total_amount'], 2) : '1500.00',
        'paid_amount' => isset($payload['paid_amount']) ? number_format((float)$payload['paid_amount'], 2) : '500.00',
        'remaining_amount' => isset($payload['remaining_amount']) ? number_format((float)$payload['remaining_amount'], 2) : '1000.00',
        'order_items' => (string)($payload['order_items'] ?? ''),
        'created_at_ar' => (string)($payload['created_at_ar'] ?? to_ar_digits(date('Y-m-d'))),
        'created_time_ar' => (string)($payload['created_time_ar'] ?? ar_time_short(time())),
        'timestamp' => date('Y-m-d H:i:s')
      ];
      send_followup_event('new_order_notification', $ctx);
      process_whatsapp_queue(5);
      respond(['sent' => true]);
    } catch (Throwable $e) { respond(['message' => 'failed', 'error' => $e->getMessage()]); }
  }

  // MySQL-native notify-new-payment implementation (mirrors Supabase formatting)
  if ($name === 'notify-new-payment') {
    try {
      $pdo = pdo();
      ensure_whatsapp_schema();
      $test = !empty($payload['test']);

      // Load follow-up settings (dest number + webhook)
      $stS = $pdo->query("SELECT * FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1");
      $settings = $stS->fetch(PDO::FETCH_ASSOC) ?: [];
      $toNumber = trim((string)($settings['whatsapp_number'] ?? ''));
      if ($toNumber === '') {
        respond(null, [ 'message' => 'No WhatsApp follow-up number configured' ], 400);
      }
      $followWebhook = (string)($settings['follow_up_webhook_url'] ?? '');

      // Emoji map for payment type
      $mapPaymentType = function($type) {
        $t = trim(mb_strtolower((string)$type));
        if ($t === '') return '';
        if (strpos($t,'شب')!==false || strpos($t,'network')!==false || strpos($t,'card')!==false || strpos($t,'visa')!==false || strpos($t,'mada')!==false) return '💳 شبكة';
        if (strpos($t,'كاش')!==false || strpos($t,'cash')!==false || strpos($t,'نقد')!==false) return '💵 نقدي';
        if (strpos($t,'تحويل')!==false || strpos($t,'حوال')!==false || strpos($t,'bank')!==false || strpos($t,'transfer')!==false) return '🏦 تحويل بنكي';
        if (in_array($t, ['cash'])) return '💵 نقدي';
        if (in_array($t, ['card'])) return '💳 شبكة';
        if (in_array($t, ['bank_transfer','transfer'])) return '🏦 تحويل بنكي';
        return (string)$type;
      };

      // Helper to convert ASCII digits to Arabic-Indic digits (for time aesthetics)
      $toArabicDigits = function($str) {
        $en = ['0','1','2','3','4','5','6','7','8','9','AM','PM','am','pm'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','ص','م','ص','م'];
        return str_replace($en, $ar, $str);
      };

      $orderNumber = '';
      $customerName = '';
      $customerWhatsapp = '';
      $totalAmount = 0.0; $paidAmount = 0.0; $remainingAmount = 0.0;
      $payment = [ 'amount' => 0.0, 'payment_type' => '', 'payment_date' => date('Y-m-d'), 'reference_number' => '', 'notes' => '' ];

      if ($test) {
        $payment['amount'] = isset($payload['amount']) && is_numeric($payload['amount']) ? (float)$payload['amount'] : 500.00;
        $payment['payment_type'] = isset($payload['payment_type']) ? (string)$payload['payment_type'] : 'bank_transfer';
        $payment['payment_date'] = date('Y-m-d');
        $payment['reference_number'] = isset($payload['reference_number']) ? (string)$payload['reference_number'] : 'TEST-001';
        $payment['notes'] = isset($payload['notes']) ? (string)$payload['notes'] : '';

        $orderNumber = 'ORD-TEST-12345';
        $customerName = 'عميل تجريبي';
        $customerWhatsapp = '+966501234567';
        $totalAmount = 1500.0;
        $paidAmount = 500.0 + (float)$payment['amount'];
        $remainingAmount = max(0.0, $totalAmount - $paidAmount);
      } else {
        $paymentId = $payload['payment_id'] ?? $payload['id'] ?? null;
        $orderId = $payload['order_id'] ?? null;
        $orderNumPayload = $payload['order_number'] ?? null;

        $row = null;
        if ($paymentId) {
          $st = $pdo->prepare("SELECT p.*, o.id AS oid, o.order_number, COALESCE(o.total_amount, 0) AS total_amount, c.name AS customer_name, COALESCE(c.whatsapp, c.phone) AS customer_whatsapp FROM payments p LEFT JOIN orders o ON p.order_id = o.id LEFT JOIN customers c ON o.customer_id = c.id WHERE p.id = :id LIMIT 1");
          $st->execute([':id' => $paymentId]);
          $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$row && $orderId) {
          $st = $pdo->prepare("SELECT o.id AS oid, o.order_number, COALESCE(o.total_amount, 0) AS total_amount, c.name AS customer_name, COALESCE(c.whatsapp, c.phone) AS customer_whatsapp FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.id = :id LIMIT 1");
          $st->execute([':id' => $orderId]);
          $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
          if ($row) { $row['amount'] = isset($payload['amount']) ? (float)$payload['amount'] : 0.0; $row['payment_type'] = (string)($payload['payment_type'] ?? ''); $row['payment_date'] = (string)($payload['payment_date'] ?? date('Y-m-d')); $row['reference_number'] = (string)($payload['reference_number'] ?? ''); $row['notes'] = (string)($payload['notes'] ?? ''); }
        }
        if (!$row && $orderNumPayload) {
          $st = $pdo->prepare("SELECT o.id AS oid, o.order_number, COALESCE(o.total_amount, 0) AS total_amount, c.name AS customer_name, COALESCE(c.whatsapp, c.phone) AS customer_whatsapp FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.order_number = :n LIMIT 1");
          $st->execute([':n' => (string)$orderNumPayload]);
          $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
          if ($row) { $row['amount'] = isset($payload['amount']) ? (float)$payload['amount'] : 0.0; $row['payment_type'] = (string)($payload['payment_type'] ?? ''); $row['payment_date'] = (string)($payload['payment_date'] ?? date('Y-m-d')); $row['reference_number'] = (string)($payload['reference_number'] ?? ''); $row['notes'] = (string)($payload['notes'] ?? ''); }
        }

        if (!$row) {
          respond(null, [ 'message' => 'Payment/order not found or insufficient parameters' ], 404);
        }

        $orderIdDb = $row['oid'] ?? $row['order_id'] ?? null;
        $payment['amount'] = (float)($row['amount'] ?? 0);
        $ptype = (string)($row['payment_type'] ?? '');
        if ($ptype === '' && isset($row['payment_method'])) { $ptype = (string)$row['payment_method']; }
        if ($ptype === '' && isset($payload['payment_type'])) { $ptype = (string)$payload['payment_type']; }
        $payment['payment_type'] = $ptype;
        $pdate = (string)($row['payment_date'] ?? '');
        if ($pdate === '' && isset($payload['payment_date'])) { $pdate = (string)$payload['payment_date']; }
        if ($pdate === '' && isset($row['created_at'])) { $pdate = substr((string)$row['created_at'], 0, 10); }
        if ($pdate === '') { $pdate = date('Y-m-d'); }
        $payment['payment_date'] = $pdate;
        $payment['reference_number'] = (string)($row['reference_number'] ?? '');
        $payment['notes'] = (string)($row['notes'] ?? '');
        $orderNumber = (string)($row['order_number'] ?? '');
        $customerName = (string)($row['customer_name'] ?? '');
        $customerWhatsapp = (string)($row['customer_whatsapp'] ?? '');
        $totalAmount = (float)($row['total_amount'] ?? 0);

        // Sum paid amount for the order
        $paidAmount = 0.0;
        if (!empty($orderIdDb)) {
          try {
            $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :id');
            $st2->execute([':id' => $orderIdDb]);
            $r2 = $st2->fetch(PDO::FETCH_ASSOC);
            $paidAmount = (float)($r2['s'] ?? 0);
          } catch (Throwable $e) { $paidAmount = 0.0; }
        }
        $remainingAmount = max(0.0, $totalAmount - $paidAmount);
      }

      // Format fields
      $payAmt = number_format((float)$payment['amount'], 2);
      $totalFmt = number_format((float)$totalAmount, 2);
      $paidFmt = number_format((float)$paidAmount, 2);
      $remainFmt = number_format((float)$remainingAmount, 2);
      $payType = $mapPaymentType($payment['payment_type']);
      $payDate = $payment['payment_date'] ? $payment['payment_date'] : date('Y-m-d');
      $timeAr = $toArabicDigits(date('h:i A'));

      $message = "💰 إشعار: تسجيل دفعة جديدة\n\n" .
                 "📦 رقم الطلب: " . ($orderNumber !== '' ? $orderNumber : 'غير محدد') . "\n\n" .
                 "👤 العميل: " . ($customerName !== '' ? $customerName : 'غير محدد') . "\n" .
                 "📱 واتساب العميل: " . ($customerWhatsapp !== '' ? $customerWhatsapp : 'غير متوفر') . "\n\n" .
                 "━━━━━━━━━━━━━━━━━━━━\n\n" .
                 "💵 تفاصيل الدفعة:\n" .
                 "• المبلغ المدفوع: $payAmt ر.س\n" .
                 "• طريقة الدفع: " . ($payType !== '' ? $payType : '') . "\n" .
                 "• تاريخ الدفع: $payDate\n" .
                 (($payment['reference_number'] ?? '') !== '' ? ("• رقم المرجع: " . $payment['reference_number'] . "\n") : '') .
                 (($payment['notes'] ?? '') !== '' ? ("• ملاحظات: " . $payment['notes'] . "\n") : '') .
                 "\n━━━━━━━━━━━━━━━━━━━━\n\n" .
                 "📊 حالة الطلب:\n" .
                 "• إجمالي الطلب: $totalFmt ر.س\n" .
                 "• المبلغ المدفوع: $paidFmt ر.س\n" .
                 "• المتبقي: $remainFmt ر.س\n" .
                 "• الحالة: " . ($remainingAmount <= 0.00001 ? '✅ مدفوع بالكامل' : '⏳ دفعة جزئية') . "\n\n" .
                 "⏰ " . $timeAr . ($test ? "\n\n🧪 هذه رسالة اختبار" : '');

      // // Enqueue WhatsApp message
      // $msgId = generate_uuid_v4();
      // $stI = $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, created_at, dedupe_key) VALUES (:id, :from_number, :to, :type, :content, :status, NOW(), :dk)");
      // $stI->execute([
      //   ':id' => $msgId,
      //   ':from_number' => 'system',
      //   ':to' => $toNumber,
      //   ':type' => 'payment_notification',
      //   ':content' => $message,
      //   ':status' => 'pending',
      //   ':dk' => 'payment_logged_' . ($test ? 'test' : ($payload['payment_id'] ?? $orderNumber ?? 'na')) . '_' . date('YmdHis')
      // ]);

      // Optionally attempt direct webhook
      if ($followWebhook !== '') {
        try {
          $payloadOut = [
            'event' => 'whatsapp_message_send',
            'data' => [
              'to' => ltrim($toNumber, '+'),
              'phone' => $toNumber,
              'phoneNumber' => $toNumber,
              'message' => $message,
              'messageText' => $message,
              'text' => $message,
              'type' => 'text',
              'message_type' => 'payment_notification',
              'timestamp' => time(),
              'from_number' => 'system'
            ],
            'meta' => [ 'id' => $msgId ]
          ];
          // Prefer curl if available
          $ok = false; $code = 0; $resp = '';
          if (function_exists('curl_init')) {
            $ch = curl_init($followWebhook);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payloadOut, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_TIMEOUT=>10]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ok = ($resp !== false && $code >= 200 && $code < 400);
            curl_close($ch);
          }
          if (!$ok) {
            $opts = [ 'http' => [ 'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($payloadOut, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 'timeout' => 10 ] ];
            $ctx = stream_context_create($opts);
            $r2 = @file_get_contents($followWebhook, false, $ctx);
            $ok = ($r2 !== false); $resp = is_string($r2)?$r2:$resp; $code = $ok?200:$code;
          }
          if ($ok) {
            // Mark sent
            try { $pdo->prepare("UPDATE whatsapp_messages SET status='sent', updated_at = NOW() WHERE id = :id")->execute([':id'=>$msgId]); } catch (Throwable $eUp) { /* ignore */ }
          }
        } catch (Throwable $eSend) { /* ignore webhook errors */ }
      }

      respond([ 'ok' => true, 'id' => $msgId ]);
    } catch (Throwable $e) {
      respond(null, [ 'message' => $e->getMessage() ], 500);
    }
  }

  // Early override: build notify-new-payment message with MySQL context and full formatting
  if ($name === 'notify-new-payment') {
    try {
      $pdo = pdo();
      $test = !empty($payload['test']);
      $to = get_followup_number();
      if (!$to) respond(null, [ 'message' => 'Missing follow-up WhatsApp number' ], 400);

      // Helper: map payment type to Arabic with emoji
      $mapPaymentType = function($type) {
        $t = trim(mb_strtolower((string)$type));
        if ($t === '') return '';
        if (strpos($t,'شب')!==false || strpos($t,'network')!==false || strpos($t,'card')!==false || strpos($t,'visa')!==false || strpos($t,'mada')!==false) return '💳 شبكة';
        if (strpos($t,'كاش')!==false || strpos($t,'cash')!==false || strpos($t,'نقد')!==false) return '💵 نقدي';
        if (strpos($t,'تحويل')!==false || strpos($t,'حوال')!==false || strpos($t,'bank')!==false || strpos($t,'transfer')!==false) return '🏦 تحويل بنكي';
        if (in_array($t, ['cash'])) return '💵 نقدي';
        if (in_array($t, ['card'])) return '💳 شبكة';
        if (in_array($t, ['bank_transfer','transfer'])) return '🏦 تحويل بنكي';
        return (string)$type;
      };

      $orderNumber = '';
      $customerName = '';
      $customerWhatsapp = '';
      $totalAmount = 0.0; $paidAmount = 0.0; $remainingAmount = 0.0;
      $payment = [ 'amount' => 0.0, 'payment_type' => '', 'payment_date' => date('Y-m-d'), 'reference_number' => '', 'notes' => '' ];

      if ($test) {
        $payment['amount'] = isset($payload['amount']) && is_numeric($payload['amount']) ? (float)$payload['amount'] : 500.00;
        $payment['payment_type'] = (string)($payload['payment_type'] ?? 'cash');
        $payment['payment_date'] = (string)($payload['payment_date'] ?? date('Y-m-d'));
        $payment['reference_number'] = (string)($payload['reference_number'] ?? 'TEST-001');
        $payment['notes'] = (string)($payload['notes'] ?? 'دفعة تجريبية لاختبار النظام');
        $orderNumber = (string)($payload['order_number'] ?? 'ORD-TEST-12345');
        $customerName = (string)($payload['customer_name'] ?? 'عميل تجريبي');
        $customerWhatsapp = (string)($payload['customer_whatsapp'] ?? '+966501234567');
        $totalAmount = isset($payload['total_amount']) && is_numeric($payload['total_amount']) ? (float)$payload['total_amount'] : 1500.0;
        $paidAmount = isset($payload['paid_amount']) && is_numeric($payload['paid_amount']) ? (float)$payload['paid_amount'] : 500.0;
        $remainingAmount = max(0.0, $totalAmount - $paidAmount);
      } else {
        $paymentId = $payload['payment_id'] ?? null;
        if ($paymentId) {
          $sql = "SELECT p.*, o.id AS oid, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_whatsapp FROM payments p LEFT JOIN orders o ON p.order_id = o.id LEFT JOIN customers c ON o.customer_id = c.id WHERE p.id = :id LIMIT 1";
          $st = $pdo->prepare($sql); $st->execute([':id' => $paymentId]);
          $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
          if ($row) {
            $payment['amount'] = (float)($row['amount'] ?? 0);
            $payment['payment_type'] = (string)($row['payment_type'] ?? '');
            $payment['payment_date'] = (string)($row['payment_date'] ?? ($row['created_at'] ?? date('Y-m-d')));
            $payment['reference_number'] = (string)($row['reference_number'] ?? '');
            $payment['notes'] = (string)($row['notes'] ?? '');
            $orderNumber = (string)($row['order_number'] ?? '');
            $customerName = (string)($row['customer_name'] ?? '');
            $customerWhatsapp = (string)($row['customer_whatsapp'] ?? '');
            $totalAmount = (float)($row['total_amount'] ?? 0);
            if (!empty($row['oid'])) {
              try { $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :oid'); $st2->execute([':oid' => $row['oid']]); $r2 = $st2->fetch(PDO::FETCH_ASSOC); $paidAmount = (float)($r2['s'] ?? 0); } catch (Throwable $e) { $paidAmount = 0.0; }
            }
            $remainingAmount = max(0.0, $totalAmount - $paidAmount);
          }
        }
        if ($orderNumber === '') {
          // order_id path
          $orderId = $payload['order_id'] ?? null;
          if ($orderId) {
            $st = $pdo->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_whatsapp FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.id = :id LIMIT 1");
            $st->execute([':id' => $orderId]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
              $orderNumber = (string)($row['order_number'] ?? '');
              $customerName = (string)($row['customer_name'] ?? '');
              $customerWhatsapp = (string)($row['customer_whatsapp'] ?? '');
              $totalAmount = (float)($row['total_amount'] ?? 0);
              try { $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :id'); $st2->execute([':id' => $row['id']]); $r2 = $st2->fetch(PDO::FETCH_ASSOC); $paidAmount = (float)($r2['s'] ?? 0); } catch (Throwable $e) { $paidAmount = 0.0; }
              $remainingAmount = max(0.0, $totalAmount - $paidAmount);
            }
          }
        }
        if ($orderNumber === '' && !empty($payload['order_number'])) {
          $orderNumber = (string)$payload['order_number'];
          // Lookup by order_number
          try {
            $st = $pdo->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_whatsapp FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.order_number = :n LIMIT 1");
            $st->execute([':n' => $orderNumber]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
              $customerName = (string)($row['customer_name'] ?? '');
              $customerWhatsapp = (string)($row['customer_whatsapp'] ?? '');
              $totalAmount = (float)($row['total_amount'] ?? 0);
              try { $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :id'); $st2->execute([':id' => $row['id']]); $r2 = $st2->fetch(PDO::FETCH_ASSOC); $paidAmount = (float)($r2['s'] ?? 0); } catch (Throwable $e) { $paidAmount = 0.0; }
              $remainingAmount = max(0.0, $totalAmount - $paidAmount);
            }
          } catch (Throwable $e) { /* ignore */ }
        }
        // Overlay explicit payload fields
        if (isset($payload['amount']) && is_numeric($payload['amount'])) $payment['amount'] = (float)$payload['amount'];
        if (isset($payload['payment_type'])) $payment['payment_type'] = (string)$payload['payment_type'];
        $payment['payment_date'] = (string)($payload['payment_date'] ?? $payment['payment_date']);
        $payment['reference_number'] = (string)($payload['reference_number'] ?? $payment['reference_number']);
        $payment['notes'] = (string)($payload['notes'] ?? $payment['notes']);
      }

      // Fallbacks from payload if DB lookup didn't resolve
      if ($customerName === '' && !empty($payload['customer_name'])) $customerName = (string)$payload['customer_name'];
      if ($customerWhatsapp === '' && !empty($payload['customer_whatsapp'])) $customerWhatsapp = (string)$payload['customer_whatsapp'];
      if ($customerWhatsapp === '' && !empty($payload['customer_phone'])) $customerWhatsapp = (string)$payload['customer_phone'];
      if (($totalAmount == 0.0) && isset($payload['total_amount']) && is_numeric($payload['total_amount'])) $totalAmount = (float)$payload['total_amount'];
      if (($paidAmount == 0.0) && isset($payload['paid_amount']) && is_numeric($payload['paid_amount'])) $paidAmount = (float)$payload['paid_amount'];
      $remainingAmount = max(0.0, $totalAmount - $paidAmount);
      if (empty($payment['payment_type']) && !empty($payload['payment_method'])) $payment['payment_type'] = (string)$payload['payment_method'];
      if (empty($payment['payment_date']) && !empty($payload['payment_time'])) $payment['payment_date'] = (string)$payload['payment_time'];

      $ptDecor = $mapPaymentType($payment['payment_type']);
      $paymentDateStr = $payment['payment_date'] ? date('Y-m-d', strtotime($payment['payment_date'])) : date('Y-m-d');
      $timeStr = date('h:i A');

      $message = "💰 إشعار: تسجيل دفعة جديدة\n\n" .
                 "📦 رقم الطلب: " . ($orderNumber !== '' ? $orderNumber : 'غير محدد') . "\n\n" .
                 "👤 العميل: " . ($customerName !== '' ? $customerName : 'غير محدد') . "\n\n" .
                 "📱 واتساب العميل: " . ($customerWhatsapp !== '' ? $customerWhatsapp : 'غير متوفر') . "\n\n" .
                 "━━━━━━━━━━━━━━━━━━━━\n\n" .
                 "💵 تفاصيل الدفعة:\n\n" .
                 "• المبلغ المدفوع: " . number_format((float)$payment['amount'], 2) . " ر.س\n\n" .
                 "• طريقة الدفع: " . ($ptDecor !== '' ? $ptDecor : 'غير محدد') . "\n\n" .
                 "• تاريخ الدفع: " . $paymentDateStr . "\n\n" .
                 "━━━━━━━━━━━━━━━━━━━━\n\n" .
                 "📊 حالة الطلب:\n\n" .
                 "• إجمالي الطلب: " . number_format((float)$totalAmount, 2) . " ر.س\n\n" .
                 "• المبلغ المدفوع: " . number_format((float)$paidAmount, 2) . " ر.س\n\n" .
                 "• المتبقي: " . number_format(max(0.0, (float)$remainingAmount), 2) . " ر.س\n\n" .
                 "• الحالة: " . ($remainingAmount <= 0.00001 ? '✅ مدفوع بالكامل' : '⏳ دفعة جزئية') . "\n\n" .
                 "⏰ " . $timeStr . ($test ? "\n\n🧪 هذه رسالة اختبار" : '');

      $dedupe = 'payment_logged_' . ($test ? 'test' : ($payload['payment_id'] ?? $orderNumber ?? '')) . '_' . time();
      enqueue_followup_message($to, $message, 'new_payment_notification', $dedupe);
      process_whatsapp_queue(10);
      respond([ 'success' => true, 'message' => 'Payment notification created' ]);
    } catch (Throwable $e) {
      respond(null, [ 'message' => $e->getMessage() ], 500);
    }
  }

  // Early template-driven notify handlers to ensure no static texts and clear failure reasons
  $handle_notify_event = function($eventKey, $ctxPayload = []) {
    try {
      // Special-case: build payment notification with full formatting, ignoring templates
      $ek = strtolower((string)$eventKey);
      if (in_array($ek, ['new_payment_notification','payment_logged_notification'], true)) {
        $ctx = is_array($ctxPayload) ? $ctxPayload : [];
        $pdo = pdo();
        $to = get_followup_number();
        if (!$to) return [ 'success' => false, 'error' => [ 'code' => 'missing_followup_number', 'message' => 'Missing follow-up WhatsApp number' ] ];

        $mapPaymentType = function($type) {
          $t = trim(mb_strtolower((string)$type));
          if ($t === '') return '';
          if (strpos($t,'شب')!==false || strpos($t,'network')!==false || strpos($t,'card')!==false || strpos($t,'visa')!==false || strpos($t,'mada')!==false) return '💳 شبكة';
          if (strpos($t,'كاش')!==false || strpos($t,'cash')!==false || strpos($t,'نقد')!==false) return '💵 نقدي';
          if (strpos($t,'تحويل')!==false || strpos($t,'حوال')!==false || strpos($t,'bank')!==false || strpos($t,'transfer')!==false) return '🏦 تحويل بنكي';
          if (in_array($t, ['cash'])) return '💵 نقدي';
          if (in_array($t, ['card'])) return '💳 شبكة';
          if (in_array($t, ['bank_transfer','transfer'])) return '🏦 تحويل بنكي';
          return (string)$type;
        };

        $orderNumber = (string)($ctx['order_number'] ?? '');
        $orderId = $ctx['order_id'] ?? null;
        $paymentId = $ctx['payment_id'] ?? null;
        $customerName = '';
        $customerWhatsapp = '';
        $totalAmount = 0.0; $paidAmount = 0.0; $remainingAmount = 0.0;

        $payment = [
          'amount' => isset($ctx['amount']) && is_numeric($ctx['amount']) ? (float)$ctx['amount'] : 0.0,
          'payment_type' => (string)($ctx['payment_type'] ?? ''),
          'payment_date' => (string)($ctx['payment_date'] ?? ($ctx['payment_time'] ?? date('Y-m-d'))),
          'reference_number' => (string)($ctx['reference_number'] ?? ''),
          'notes' => (string)($ctx['notes'] ?? ''),
        ];

        if ($paymentId) {
          try {
            $st = $pdo->prepare("SELECT p.*, o.id AS oid, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_whatsapp FROM payments p LEFT JOIN orders o ON p.order_id = o.id LEFT JOIN customers c ON o.customer_id = c.id WHERE p.id = :id LIMIT 1");
            $st->execute([':id'=>$paymentId]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
              $payment['amount'] = (float)($row['amount'] ?? $payment['amount']);
              $payment['payment_type'] = (string)($row['payment_type'] ?? $payment['payment_type']);
              $payment['payment_date'] = (string)($row['payment_date'] ?? ($row['created_at'] ?? $payment['payment_date']));
              $payment['reference_number'] = (string)($row['reference_number'] ?? $payment['reference_number']);
              $payment['notes'] = (string)($row['notes'] ?? $payment['notes']);
              $orderNumber = $orderNumber !== '' ? $orderNumber : (string)($row['order_number'] ?? '');
              $customerName = (string)($row['customer_name'] ?? '');
              $customerWhatsapp = (string)($row['customer_whatsapp'] ?? '');
              $totalAmount = (float)($row['total_amount'] ?? 0);
              if (!empty($row['oid'])) {
                try { $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :oid'); $st2->execute([':oid' => $row['oid']]); $r2 = $st2->fetch(PDO::FETCH_ASSOC); $paidAmount = (float)($r2['s'] ?? 0); } catch (Throwable $e) { $paidAmount = 0.0; }
              }
              $remainingAmount = max(0.0, $totalAmount - $paidAmount);
            }
          } catch (Throwable $e) { /* ignore */ }
        }

        if ($orderNumber === '' && !empty($orderId)) {
          try {
            $st = $pdo->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_whatsapp FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.id = :id LIMIT 1");
            $st->execute([':id'=>$orderId]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
              $orderNumber = (string)($row['order_number'] ?? '');
              $customerName = (string)($row['customer_name'] ?? '');
              $customerWhatsapp = (string)($row['customer_whatsapp'] ?? '');
              $totalAmount = (float)($row['total_amount'] ?? 0);
              try { $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :id'); $st2->execute([':id' => $row['id']]); $r2 = $st2->fetch(PDO::FETCH_ASSOC); $paidAmount = (float)($r2['s'] ?? 0); } catch (Throwable $e) { $paidAmount = 0.0; }
              $remainingAmount = max(0.0, $totalAmount - $paidAmount);
            }
          } catch (Throwable $e) { /* ignore */ }
        }

        if ($orderNumber !== '' && $customerName === '' && ($totalAmount == 0.0)) {
          try {
            $st = $pdo->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_whatsapp FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.order_number = :n LIMIT 1");
            $st->execute([':n'=>$orderNumber]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
              $customerName = (string)($row['customer_name'] ?? '');
              $customerWhatsapp = (string)($row['customer_whatsapp'] ?? '');
              $totalAmount = (float)($row['total_amount'] ?? 0);
              try { $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :id'); $st2->execute([':id' => $row['id']]); $r2 = $st2->fetch(PDO::FETCH_ASSOC); $paidAmount = (float)($r2['s'] ?? 0); } catch (Throwable $e) { $paidAmount = 0.0; }
              $remainingAmount = max(0.0, $totalAmount - $paidAmount);
            }
          } catch (Throwable $e) { /* ignore */ }
        }

        // Fallbacks from context if DB lookup didn't resolve
        if ($customerName === '' && !empty($ctx['customer_name'])) $customerName = (string)$ctx['customer_name'];
        if ($customerWhatsapp === '' && !empty($ctx['customer_whatsapp'])) $customerWhatsapp = (string)$ctx['customer_whatsapp'];
        if ($customerWhatsapp === '' && !empty($ctx['customer_phone'])) $customerWhatsapp = (string)$ctx['customer_phone'];
        if (($totalAmount == 0.0) && isset($ctx['total_amount']) && is_numeric($ctx['total_amount'])) $totalAmount = (float)$ctx['total_amount'];
        if (($paidAmount == 0.0) && isset($ctx['paid_amount']) && is_numeric($ctx['paid_amount'])) $paidAmount = (float)$ctx['paid_amount'];
        $remainingAmount = max(0.0, $totalAmount - $paidAmount);
        if (empty($payment['payment_type']) && !empty($ctx['payment_method'])) $payment['payment_type'] = (string)$ctx['payment_method'];
        if (empty($payment['payment_date']) && !empty($ctx['payment_time'])) $payment['payment_date'] = (string)$ctx['payment_time'];

        $ptDecor = $mapPaymentType($payment['payment_type']);
        $paymentDateStr = $payment['payment_date'] ? date('Y-m-d', strtotime($payment['payment_date'])) : date('Y-m-d');
        $timeStr = date('h:i A');
        $message = "💰 إشعار: تسجيل دفعة جديدة\n\n" .
                   "📦 رقم الطلب: " . ($orderNumber !== '' ? $orderNumber : 'غير محدد') . "\n\n" .
                   "👤 العميل: " . ($customerName !== '' ? $customerName : 'غير محدد') . "\n\n" .
                   "📱 واتساب العميل: " . ($customerWhatsapp !== '' ? $customerWhatsapp : 'غير متوفر') . "\n\n" .
                   "━━━━━━━━━━━━━━━━━━━━\n\n" .
                   "💵 تفاصيل الدفعة:\n\n" .
                   "• المبلغ المدفوع: " . number_format((float)$payment['amount'], 2) . " ر.س\n\n" .
                   "• طريقة الدفع: " . ($ptDecor !== '' ? $ptDecor : 'غير محدد') . "\n\n" .
                   "• تاريخ الدفع: " . $paymentDateStr . "\n\n" .
                   "━━━━━━━━━━━━━━━━━━━━\n\n" .
                   "📊 حالة الطلب:\n\n" .
                   "• إجمالي الطلب: " . number_format((float)$totalAmount, 2) . " ر.س\n\n" .
                   "• المبلغ المدفوع: " . number_format((float)$paidAmount, 2) . " ر.س\n\n" .
                   "• المتبقي: " . number_format(max(0.0, (float)$remainingAmount), 2) . " ر.س\n\n" .
                   "• الحالة: " . ($remainingAmount <= 0.00001 ? '✅ مدفوع بالكامل' : '⏳ دفعة جزئية') . "\n\n" .
                   "⏰ " . $timeStr;

        $dedupe = 'payment_logged_' . ($paymentId ?: ($orderId ?: $orderNumber ?: 'unknown')) . '_' . time();
        enqueue_followup_message($to, $message, 'new_payment_notification', $dedupe);
        process_whatsapp_queue(10);
        return [ 'success' => true, 'message' => 'Payment notification created' ];
      }

      $cands = followup_template_candidates($eventKey);
      $tpl = null; $tplKey = null;
      foreach ($cands as $k) { $t = get_template_content($k); if ($t) { $tpl = $t; $tplKey = $k; break; } }
      if (!$tpl) return [ 'success' => false, 'error' => [ 'code' => 'template_not_found', 'message' => 'Template not found for ' . $eventKey ] ];
      $dest = get_followup_number();
      if (!$dest) return [ 'success' => false, 'error' => [ 'code' => 'missing_followup_number', 'message' => 'Missing follow-up WhatsApp number' ] ];
      $ctx = is_array($ctxPayload) ? $ctxPayload : [];
      // Enrich payment context for payment-related events
      $ekeyLower = strtolower((string)$eventKey);
      if (strpos($ekeyLower, 'payment') !== false && (!empty($ctx['order_number']) || !empty($ctx['order_id']))) {
        try {
          $pdo2 = pdo();
          $orderNo2 = isset($ctx['order_number']) ? (string)$ctx['order_number'] : '';
          $orderId2 = $ctx['order_id'] ?? null;
          $db2 = ['id'=>null,'order_number'=>$orderNo2,'customer_name'=>'','customer_phone'=>'','total_amount'=>null];
          if ($orderNo2 !== '') {
            $q = $pdo2->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.order_number = :n LIMIT 1");
            $q->execute([':n'=>$orderNo2]); $rr = $q->fetch(PDO::FETCH_ASSOC) ?: null; if ($rr) $db2 = array_merge($db2,$rr);
          }
          if (!$db2['id'] && !empty($orderId2)) {
            $q2 = $pdo2->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.id = :id LIMIT 1");
            $q2->execute([':id'=>$orderId2]); $rr2 = $q2->fetch(PDO::FETCH_ASSOC) ?: null; if ($rr2) $db2 = array_merge($db2,$rr2);
          }
          $paidSum2 = null; if (!empty($db2['id'])) { $s = $pdo2->prepare("SELECT SUM(amount) AS s FROM payments WHERE order_id = :id"); $s->execute([':id'=>$db2['id']]); $paidSum2 = (float)($s->fetch(PDO::FETCH_ASSOC)['s'] ?? 0); }
          $totalAmt2 = isset($ctx['total_amount']) ? (float)$ctx['total_amount'] : (isset($db2['total_amount']) ? (float)$db2['total_amount'] : null);
          $remaining2 = ($totalAmt2 !== null && $paidSum2 !== null) ? max(0, $totalAmt2 - $paidSum2) : null;
          if (empty($ctx['customer_name'])) $ctx['customer_name'] = (string)($db2['customer_name'] ?? '');
          if (empty($ctx['customer_phone'])) $ctx['customer_phone'] = (string)($db2['customer_phone'] ?? '');
          if (empty($ctx['total_amount']) && $totalAmt2 !== null) $ctx['total_amount'] = number_format($totalAmt2, 2);
          if (empty($ctx['paid_amount']) && $paidSum2 !== null) $ctx['paid_amount'] = number_format($paidSum2, 2);
          if (empty($ctx['remaining_amount']) && $remaining2 !== null) $ctx['remaining_amount'] = number_format($remaining2, 2);
          if (empty($ctx['status']) && $remaining2 !== null && $remaining2 <= 0) $ctx['status'] = '✅ مدفوع بالكامل';
          // Decorate payment type
          if (!empty($ctx['payment_type'])) { $pt = trim(mb_strtolower((string)$ctx['payment_type'])); if (strpos($pt,'شب')!==false||strpos($pt,'network')!==false) $ctx['payment_type']='💳 شبكة'; elseif (strpos($pt,'كاش')!==false||strpos($pt,'cash')!==false) $ctx['payment_type']='💵 كاش'; elseif (strpos($pt,'تحويل')!==false||strpos($pt,'حوال')!==false||strpos($pt,'bank')!==false) $ctx['payment_type']='🏦 حوالة'; }
          // Aliases
          $ctx['total'] = $ctx['total_amount'] ?? ($totalAmt2!==null?number_format($totalAmt2,2):($ctx['total']??''));
          $ctx['paid'] = $ctx['paid_amount'] ?? ($paidSum2!==null?number_format($paidSum2,2):($ctx['paid']??''));
          $ctx['remaining'] = $ctx['remaining_amount'] ?? ($remaining2!==null?number_format($remaining2,2):($ctx['remaining']??''));
          $ctx['customer_whatsapp'] = $ctx['customer_phone'] ?? ($ctx['customer_whatsapp'] ?? '');
          $ctx['phone'] = $ctx['customer_phone'] ?? ($ctx['phone'] ?? '');
          $ctx['whatsapp'] = $ctx['customer_phone'] ?? ($ctx['whatsapp'] ?? '');
          $ctx['phoneNumber'] = $ctx['customer_phone'] ?? ($ctx['phoneNumber'] ?? '');
          $ctx['payment_method'] = $ctx['payment_type'] ?? ($ctx['payment_method'] ?? '');
          $ctx['order_total'] = $ctx['total_amount'] ?? ($ctx['order_total'] ?? '');
          $ctx['amount_paid'] = $ctx['paid_amount'] ?? ($ctx['amount_paid'] ?? '');
          $ctx['balance_due'] = $ctx['remaining_amount'] ?? ($ctx['balance_due'] ?? '');
          $ctx['order_status'] = $ctx['status'] ?? ($ctx['order_status'] ?? '');
          if (empty($ctx['payment_time']) && !empty($ctx['timestamp'])) $ctx['payment_time'] = $ctx['timestamp'];
        } catch (Throwable $eCtx) { /* ignore enrichment errors */ }
      }
      if (!isset($ctx['company_name'])) $ctx['company_name'] = get_company_name();
      if (!isset($ctx['timestamp'])) $ctx['timestamp'] = date('Y-m-d H:i:s');
      $message = render_template($tpl, $ctx);
      $dedupe2 = null;
      $ek2 = strtolower((string)$tplKey);
      if (in_array($ek2, ['payment_logged_notification','new_payment_notification'], true)) {
        $ord2 = isset($ctx['order_number']) ? (string)$ctx['order_number'] : '';
        $amtRaw2 = $ctx['amount'] ?? ($ctx['paid_amount'] ?? ($ctx['paid'] ?? null));
        $amt2 = is_string($amtRaw2) ? floatval(preg_replace('/[^0-9.\-]/', '', $amtRaw2)) : (is_numeric($amtRaw2) ? (float)$amtRaw2 : 0.0);
        $dedupe2 = 'payment_event|' . $ord2 . '|' . number_format($amt2, 2);
      }
      $res = enqueue_followup_message($dest, $message, $tplKey, $dedupe2);
      $processed = process_whatsapp_queue(10);
      return [
        'success' => empty($res['error']),
        'message_id' => $res['id'] ?? null,
        'processed' => $processed,
        'webhook_url' => $processed['webhook_url'] ?? resolve_webhook_for_message_type($tplKey),
        'detail' => null,
        'function' => $eventKey
      ];
    } catch (Throwable $e) {
      return [ 'success' => false, 'error' => [ 'code' => 'notify_error', 'message' => $e->getMessage() ] ];
    }
  };

  // Implement MySQL-based notify handlers mirroring Supabase functions (placed before legacy blocks)
  if ($name === 'notify-new-payment') {
    try {
      $pdo = pdo();
      $test = !empty($payload['test']);
      $to = get_followup_number();
      if (!$to) respond(null, [ 'message' => 'Missing follow-up WhatsApp number' ], 400);

      // Settings flags (best-effort)
      $notifyEnabled = true; $delayDays = 2;
      try {
        $st = $pdo->query("SELECT * FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1");
        $cfg = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cfg && array_key_exists('notify_payment_logged', $cfg)) {
          $notifyEnabled = (bool)($cfg['notify_payment_logged'] == 1 || strtolower((string)$cfg['notify_payment_logged']) === 'true');
        }
        if ($cfg && array_key_exists('payment_delay_days', $cfg) && is_numeric($cfg['payment_delay_days'])) {
          $delayDays = (int)$cfg['payment_delay_days'];
        }
      } catch (Throwable $e) { /* ignore */ }
      if (!$notifyEnabled && !$test) respond([ 'message' => 'Notification disabled' ]);

      $getPaymentTypeArabic = function($type) {
        $t = trim(mb_strtolower((string)$type));
        if ($t === '') return '';
        if (strpos($t,'شب')!==false || strpos($t,'network')!==false || strpos($t,'card')!==false) return '💳 شبكة';
        if (strpos($t,'كاش')!==false || strpos($t,'cash')!==false) return '💵 نقدي';
        if (strpos($t,'تحويل')!==false || strpos($t,'حوال')!==false || strpos($t,'bank')!==false) return '🏦 تحويل بنكي';
        if (in_array($t, ['cash'])) return '💵 نقدي';
        if (in_array($t, ['card'])) return '💳 شبكة';
        if (in_array($t, ['bank_transfer','transfer'])) return '🏦 تحويل بنكي';
        return (string)$type;
      };

      $orderNumber = '';
      $customerName = '';
      $customerWhatsapp = '';
      $totalAmount = 0.0; $paidAmount = 0.0; $remainingAmount = 0.0;
      $payment = [ 'amount' => 0.0, 'payment_type' => '', 'payment_date' => date('Y-m-d'), 'reference_number' => '', 'notes' => '' ];

      if ($test) {
        $now = date('c');
        $payment = [
          'amount' => isset($payload['amount']) && is_numeric($payload['amount']) ? (float)$payload['amount'] : 500.00,
          'payment_type' => $payload['payment_type'] ?? 'cash',
          'payment_date' => $now,
          'reference_number' => $payload['reference_number'] ?? 'REF-TEST-001',
          'notes' => $payload['notes'] ?? 'دفعة تجريبية لاختبار النظام'
        ];
        $orderNumber = $payload['order_number'] ?? 'ORD-TEST-12345';
        $customerName = $payload['customer_name'] ?? 'عميل تجريبي';
        $customerWhatsapp = $payload['customer_whatsapp'] ?? '+966501234567';
        $totalAmount = isset($payload['total_amount']) && is_numeric($payload['total_amount']) ? (float)$payload['total_amount'] : 1500.0;
        $paidAmount = isset($payload['paid_amount']) && is_numeric($payload['paid_amount']) ? (float)$payload['paid_amount'] : 1250.0;
        $remainingAmount = max(0.0, $totalAmount - $paidAmount);
      } else {
        $paymentId = $payload['payment_id'] ?? null;
        if ($paymentId) {
          // Try to fetch payment with order/customer context
          $sql = "SELECT p.*, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_whatsapp FROM payments p LEFT JOIN orders o ON p.order_id = o.id LEFT JOIN customers c ON o.customer_id = c.id WHERE p.id = :id LIMIT 1";
          $st = $pdo->prepare($sql); $st->execute([':id' => $paymentId]);
          $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
          if (!$row) respond(null, [ 'message' => 'Payment not found' ], 404);
          $payment['amount'] = (float)($row['amount'] ?? 0);
          $payment['payment_type'] = (string)($row['payment_type'] ?? '');
          $payment['payment_date'] = (string)($row['payment_date'] ?? ($row['created_at'] ?? date('Y-m-d')));
          $payment['reference_number'] = (string)($row['reference_number'] ?? '');
          $payment['notes'] = (string)($row['notes'] ?? '');
          $orderNumber = (string)($row['order_number'] ?? '');
          $customerName = (string)($row['customer_name'] ?? '');
          $customerWhatsapp = (string)($row['customer_whatsapp'] ?? '');
          $totalAmount = (float)($row['total_amount'] ?? 0);
          // Sum paid for that order
          if (!empty($row['order_id'])) {
            try { $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :oid'); $st2->execute([':oid' => $row['order_id']]); $r2 = $st2->fetch(PDO::FETCH_ASSOC); $paidAmount = (float)($r2['s'] ?? 0); } catch (Throwable $e) { $paidAmount = 0.0; }
          }
          $remainingAmount = max(0.0, $totalAmount - $paidAmount);
        } else {
          // Payload-only: order_id/order_number + amount/type
          $orderId = $payload['order_id'] ?? null;
          if ($orderId) {
            $st = $pdo->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_whatsapp FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.id = :id LIMIT 1");
            $st->execute([':id' => $orderId]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
              $orderNumber = (string)($row['order_number'] ?? '');
              $customerName = (string)($row['customer_name'] ?? '');
              $customerWhatsapp = (string)($row['customer_whatsapp'] ?? '');
              $totalAmount = (float)($row['total_amount'] ?? 0);
              try { $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :id'); $st2->execute([':id' => $row['id']]); $r2 = $st2->fetch(PDO::FETCH_ASSOC); $paidAmount = (float)($r2['s'] ?? 0); } catch (Throwable $e) { $paidAmount = 0.0; }
              $remainingAmount = max(0.0, $totalAmount - $paidAmount);
            }
          }
          if ($orderNumber === '' && !empty($payload['order_number'])) $orderNumber = (string)$payload['order_number'];
          // Fallback lookup by order_number if context is still empty
          if ($orderNumber !== '' && $customerName === '' && ($totalAmount == 0.0)) {
            try {
              $stOn = $pdo->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_whatsapp FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.order_number = :n LIMIT 1");
              $stOn->execute([':n' => $orderNumber]);
              $rowOn = $stOn->fetch(PDO::FETCH_ASSOC) ?: null;
              if ($rowOn) {
                $customerName = (string)($rowOn['customer_name'] ?? '');
                $customerWhatsapp = (string)($rowOn['customer_whatsapp'] ?? '');
                $totalAmount = (float)($rowOn['total_amount'] ?? 0);
                try { $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :id'); $st2->execute([':id' => $rowOn['id']]); $r2 = $st2->fetch(PDO::FETCH_ASSOC); $paidAmount = (float)($r2['s'] ?? 0); } catch (Throwable $e) { $paidAmount = 0.0; }
                $remainingAmount = max(0.0, $totalAmount - $paidAmount);
              }
            } catch (Throwable $e) { /* ignore */ }
          }
          $payment['amount'] = isset($payload['amount']) && is_numeric($payload['amount']) ? (float)$payload['amount'] : 0.0;
          $payment['payment_type'] = (string)($payload['payment_type'] ?? '');
          $payment['payment_date'] = (string)($payload['payment_date'] ?? date('Y-m-d'));
          $payment['reference_number'] = (string)($payload['reference_number'] ?? '');
          $payment['notes'] = (string)($payload['notes'] ?? '');
        }
      }

      $paymentDateStr = date('Y-m-d', strtotime($payment['payment_date'] ?: date('Y-m-d')));
      $timeStr = date('h:i A');
      $ptDecor = $getPaymentTypeArabic($payment['payment_type']);

      $message = "💰 إشعار: تسجيل دفعة جديدة\n\n" .
                 "📦 رقم الطلب: " . ($orderNumber !== '' ? $orderNumber : 'غير محدد') . "\n\n" .
                 "👤 العميل: " . ($customerName !== '' ? $customerName : 'غير محدد') . "\n\n" .
                 "📱 واتساب العميل: " . ($customerWhatsapp !== '' ? $customerWhatsapp : 'غير متوفر') . "\n\n" .
                 "━━━━━━━━━━━━━━━━━━━━\n\n" .
                 "💵 تفاصيل الدفعة:\n\n" .
                 "• المبلغ المدفوع: " . number_format((float)$payment['amount'], 2) . " ر.س\n\n" .
                 "• طريقة الدفع: " . ($ptDecor !== '' ? $ptDecor : 'غير محدد') . "\n\n" .
                 "• تاريخ الدفع: " . ($paymentDateStr ?: date('Y-m-d')) . "\n" .
                 ( $payment['reference_number'] !== '' ? ("\n• رقم المرجع: " . $payment['reference_number'] . "\n") : '' ) .
                 ( $payment['notes'] !== '' ? ("\n• ملاحظات: " . $payment['notes'] . "\n") : '' ) .
                 "\n━━━━━━━━━━━━━━━━━━━━\n\n" .
                 "📊 حالة الطلب:\n\n" .
                 "• إجمالي الطلب: " . number_format((float)$totalAmount, 2) . " ر.س\n\n" .
                 "• المبلغ المدفوع: " . number_format((float)$paidAmount, 2) . " ر.س\n\n" .
                 "• المتبقي: " . number_format(max(0.0, (float)$remainingAmount), 2) . " ر.س\n\n" .
                 "• الحالة: " . ($remainingAmount <= 0.00001 ? '✅ مدفوع بالكامل' : '⏳ دفعة جزئية') . "\n\n" .
                 "⏰ " . $timeStr . ($test ? "\n\n🧪 هذه رسالة اختبار" : '');

      $dedupe = 'payment_logged_' . ($test ? 'test' : ($payload['payment_id'] ?? $orderNumber ?? '')) . '_' . time();
      enqueue_followup_message($to, $message, 'new_payment_notification', $dedupe);
      process_whatsapp_queue(10);
      respond([ 'success' => true, 'message' => 'Payment notification created' ]);
    } catch (Throwable $e) {
      respond(null, [ 'message' => $e->getMessage() ], 500);
    }
  }

  // notify-new-order is handled via the structured switch-case below. Removing stray stub to prevent 500s.
  if ($name === 'notify-new-order-legacy-removed') {
    try {
      $pdo = pdo(); ensure_whatsapp_schema();
      $test = !empty($payload['test']);
      // Helpers
      $toArabicDigits = function($str){ $en=['0','1','2','3','4','5','6','7','8','9','AM','PM','am','pm']; $ar=['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','ص','م','ص','م']; return str_replace($en,$ar,$str); };
      $fmt2 = function($n){ return number_format((float)$n, 2); };
      $statusMap = ['pending'=>'قيد الانتظار','in_progress'=>'قيد التنفيذ','completed'=>'مكتمل','cancelled'=>'ملغي','ready_for_delivery'=>'جاهز للتسليم','on_hold'=>'معلق','under_review'=>'قيد المراجعة'];
      // Destination
      $stS = $pdo->query("SELECT * FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1");
      $settings = $stS->fetch(PDO::FETCH_ASSOC) ?: [];
      $to = trim((string)($settings['whatsapp_number'] ?? ''));
      if ($to === '') { respond(null, ['message'=>'No WhatsApp number configured'], 400); }
      // Data
      if ($test) {
        $order = [
          'id' => 'test-order-id',
          'order_number' => 'ORD-TEST-12345',
          'status' => 'pending',
          'total_amount' => 1500,
          'created_at' => date('Y-m-d H:i:s'),
          'delivery_date' => date('Y-m-d', time()+86400),
          'notes' => 'طلب تجريبي لاختبار نظام الإشعارات',
          'customer_name' => 'عميل تجريبي',
          'customer_phone' => '+966501234567',
          'service_name' => 'خدمة تجريبية'
        ];
        $paid = 500.0; $remain = 1000.0;
        $itemsText = "1. منتج تجريبي 1\nالكمية: 2\nالسعر: 500 ريال\nالإجمالي: 1000 ريال\nالوصف: وصف المنتج التجريبي الأول\n\n2. منتج تجريبي 2\nالكمية: 1\nالسعر: 500 ريال\nالإجمالي: 500 ريال\nالوصف: وصف المنتج التجريبي الثاني";
        $dateAr = $toArabicDigits(date('Y-m-d'));
        $timeAr = $toArabicDigits(date('h:i A'));
        $msg = "🎉 طلب جديد\n\n".
               "📦 رقم الطلب: " . $order['order_number'] . "\n\n".
               "👤 معلومات العميل:\n\n".
               "• الاسم: " . $order['customer_name'] . "\n\n".
               "• الجوال: " . $order['customer_phone'] . "\n\n".
               "🔧 تفاصيل الطلب:\n\n".
               "• الخدمة: " . $order['service_name'] . "\n".
               "• الوصف: " . $order['notes'] . "\n\n".
               "• الحالة: " . $statusMap[$order['status']] . "\n\n".
               "• تاريخ الاستحقاق: " . $dateAr . "\n\n".
               "💰 المبالغ المالية:\n\n".
               "• المبلغ الإجمالي: " . (int)$order['total_amount'] . " ريال\n\n".
               "• المبلغ المدفوع: " . (int)$paid . " ريال\n\n".
               "• المبلغ المتبقي: " . $fmt2($remain) . " ريال\n\n".
               "📋 بنود الطلب:\n\n".
               $itemsText . "\n\n".
               "⏰ تاريخ الإنشاء: " . $dateAr . " " . $timeAr . "\n\n".
               "🧪 هذه رسالة اختبار";
        $id = generate_uuid_v4();
        $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, created_at, dedupe_key) VALUES (:id,'system',:to,'new_order_notification',:msg,'pending',NOW(),:dk)")
            ->execute([':id'=>$id, ':to'=>$to, ':msg'=>$msg, ':dk'=>'new_order_test_'.date('YmdHis')]);
        respond(['ok'=>true,'id'=>$id]);
      }
      // Real order
      $orderId = $payload['orderId'] ?? $payload['order_id'] ?? null;
      $orderNo = $payload['order_number'] ?? null;
      $ord = null;
      if (!empty($orderId)) {
        $st = $pdo->prepare("SELECT o.*, c.name AS customer_name, COALESCE(c.whatsapp, c.phone) AS customer_phone, s.name AS service_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id LEFT JOIN service_types s ON o.service_type_id = s.id WHERE o.id = :id LIMIT 1");
        $st->execute([':id'=>$orderId]); $ord = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      } elseif (!empty($orderNo)) {
        $st = $pdo->prepare("SELECT o.*, c.name AS customer_name, COALESCE(c.whatsapp, c.phone) AS customer_phone, s.name AS service_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id LEFT JOIN service_types s ON o.service_type_id = s.id WHERE o.order_number = :n LIMIT 1");
        $st->execute([':n'=>$orderNo]); $ord = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      }
      if (!$ord) { respond(null, ['message'=>'Order not found'], 404); }
      $oid = $ord['id'];
      // sums
      $total = (float)($ord['total_amount'] ?? 0);
      $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS s FROM payments WHERE order_id = :id");
      $st->execute([':id'=>$oid]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $paid = (float)($row['s'] ?? 0); $remain = max(0, $total - $paid);
      // items
      $itemsText = build_order_items_section($oid);
      if ($itemsText === '') { $itemsText = 'لا توجد بنود'; }
      $statusDisp = $statusMap[$ord['status']] ?? $ord['status'];
      $dateAr = $toArabicDigits(substr((string)($ord['delivery_date'] ?? ''),0,10));
      $createdAt = $toArabicDigits(date('Y-m-d h:i A', strtotime($ord['created_at'] ?? date('Y-m-d H:i:s'))));
      $msg = "🎉 طلب جديد\n\n".
            "📦 رقم الطلب: " . ($ord['order_number'] ?? '') . "\n\n".
            "👤 معلومات العميل:\n\n".
            "• الاسم: " . ((string)($ord['customer_name'] ?? 'غير محدد')) . "\n\n".
            "• الجوال: " . ((string)($ord['customer_phone'] ?? 'غير محدد')) . "\n\n".
            "🔧 تفاصيل الطلب:\n\n".
            "• الخدمة: " . ((string)($ord['service_name'] ?? 'غير محدد')) . "\n".
            ((trim((string)($ord['notes'] ?? ''))!=='' ) ? ("• الوصف: ".trim((string)$ord['notes'])."\n\n") : "") .
            "• الحالة: " . $statusDisp . "\n\n".
            (trim((string)($ord['delivery_date'] ?? ''))!=='' ? ("• تاريخ الاستحقاق: ".$dateAr."\n\n") : '') .
            "💰 المبالغ المالية:\n\n".
            "• المبلغ الإجمالي: " . (strpos((string)$total,'.')!==false ? $fmt2($total) : (int)$total) . " ريال\n\n".
            "• المبلغ المدفوع: " . (strpos((string)$paid,'.')!==false ? $fmt2($paid) : (int)$paid) . " ريال\n\n".
            "• المبلغ المتبقي: " . $fmt2($remain) . " ريال\n\n".
            "📋 بنود الطلب:\n\n".
            $itemsText . "\n\n".
            "⏰ تاريخ الإنشاء: " . $createdAt . "\n\n".
            "يرجى متابعة الطلب والتواصل مع العميل.";
      $id = generate_uuid_v4();
      $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, created_at, dedupe_key) VALUES (:id,'system',:to,'new_order_notification',:msg,'pending',NOW(),:dk)")
          ->execute([':id'=>$id, ':to'=>$to, ':msg'=>$msg, ':dk'=>'new_order_'.($ord['order_number']??$oid).'_'.date('YmdHis')]);
          respond(['ok'=>true,'id'=>$id]);
        } catch (Throwable $e) { 
          respond(null, ['message'=>$e->getMessage()], 500); 
        }
      }
    
      // notify-new-order main implementation
  if ($name === 'daily-financial-report') {
    // Compose and send daily financial report using template 'outstanding_balance_report'
    try {
      $pdo = pdo(); ensure_default_templates();
      // Check toggle and destination
      $stS = $pdo->query("SELECT * FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1");
      $settings = $stS->fetch(PDO::FETCH_ASSOC) ?: [];
      $enabled = !empty($settings['daily_financial_report']) && (string)$settings['daily_financial_report'] !== '0' && strtolower((string)$settings['daily_financial_report']) !== 'false';
      $to = get_followup_number();
      $payload = read_json_body();
      $isTest = !empty($payload['test']);
      if ((!$enabled || !$to) && !$isTest) { respond(['message' => 'Notification disabled']); }

      // Attempt to use daily_financial_summary view if exists; otherwise compute minimal aggregates
      $report = [
        'report_date' => date('Y-m-d'),
        'total_due' => '0.00',
        'unpaid_orders_count' => '0',
        'earliest_due_date' => '',
        'orders_section' => '',
        'payments_section' => ''
      ];
      try {
        if (table_exists('daily_financial_summary')) {
          $r = $pdo->query("SELECT * FROM daily_financial_summary WHERE summary_date = CURDATE() LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
          if ($r) {
            $report['total_due'] = number_format((float)($r['total_payments'] ?? 0), 2);
            $report['unpaid_orders_count'] = (string)($r['unpaid_orders_count'] ?? 0);
            $report['earliest_due_date'] = (string)($r['earliest_due_date'] ?? '');
          }
        } else {
          // Fallback aggregates for today
          $r1 = $pdo->query("SELECT SUM(amount) s FROM payments WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC) ?: [];
          $report['total_due'] = number_format((float)($r1['s'] ?? 0), 2);
          $r2 = $pdo->query("SELECT COUNT(*) c FROM orders WHERE COALESCE(total_amount, amount, 0) > 0 AND (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.order_id = orders.id) < COALESCE(total_amount, amount, 0)")->fetch(PDO::FETCH_ASSOC) ?: [];
          $report['unpaid_orders_count'] = (string)($r2['c'] ?? 0);
          $r3 = $pdo->query("SELECT MIN(delivery_date) m FROM orders WHERE delivery_date IS NOT NULL AND delivery_date <> ''")->fetch(PDO::FETCH_ASSOC) ?: [];
          $report['earliest_due_date'] = (string)($r3['m'] ?? '');
        }
      } catch (Throwable $eAgg) { /* ignore */ }

      // Sections (optional best-effort)
      try {
        $rows = $pdo->query("SELECT order_id, amount, created_at FROM payments WHERE DATE(created_at) = CURDATE() ORDER BY created_at ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lines = [];
        foreach ($rows as $p) {
          $lines[] = '• ' . number_format((float)($p['amount'] ?? 0),2) . ' ر.س (طلب ' . (string)($p['order_id'] ?? '') . ')';
        }
        $report['payments_section'] = $lines ? implode("\n", $lines) : 'لا توجد دفعات اليوم';
      } catch (Throwable $eSec) { $report['payments_section'] = 'لا توجد دفعات اليوم'; }

      // Render and send
      $tpl = get_template_content('outstanding_balance_report');
      if (!$tpl) {
        // Fallback minimal message
        $msg = "📊 تقرير مالي يومي\n\nالتاريخ: " . date('Y-m-d') . "\n\nإجمالي المدفوعات: " . $report['total_due'] . "\n\nعدد الطلبات غير المسددة: " . $report['unpaid_orders_count'];
        enqueue_followup_message($to, $msg, 'outstanding_balance_report', 'daily_report_' . date('Ymd')); process_whatsapp_queue(5); respond(['sent'=>true]);
      }
      $message = render_template($tpl, $report);
      enqueue_followup_message($to, $message, 'outstanding_balance_report', 'daily_report_' . date('Ymd'));
      process_whatsapp_queue(5);
      respond(['sent'=>true, 'report'=>$report]);
    } catch (Throwable $e) { respond(null, ['message'=>$e->getMessage()], 500); }
  }

  if ($name === 'search-orders-for-installment') {
    try {
      $pdo = pdo();
      $payload = read_json_body();
      $q = isset($payload['q']) ? trim((string)$payload['q']) : '';
      if ($q === '' || mb_strlen($q) < 2) { respond([]); }
      $colsOrders = get_table_columns('orders');
      $createdCol = isset($colsOrders['created_at']) ? 'o.created_at' : (isset($colsOrders['id']) ? 'o.id' : '');
      $orderBy = $createdCol !== '' ? (" ORDER BY $createdCol DESC") : '';
      $sql = "SELECT 
        o.id,
        o.order_number,
        COALESCE(o.total_amount, o.amount, 0) AS total_amount,
        o.customer_id,
        COALESCE(c.name, o.customer_name) AS customer_name,
        COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone,
        (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.order_id = o.id) AS paid_amount
      FROM orders o
      LEFT JOIN customers c ON o.customer_id = c.id
      WHERE (
        o.order_number = :qExact
        OR o.order_number LIKE :qLike
        OR c.name LIKE :qLike
        OR c.phone LIKE :qLike
        OR c.whatsapp LIKE :qLike
      )
      AND NOT EXISTS (SELECT 1 FROM installment_plans ip WHERE ip.order_id = o.id)
      $orderBy
      LIMIT 10";
      $st = $pdo->prepare($sql);
      $st->bindValue(':qExact', $q);
      $st->bindValue(':qLike', '%' . $q . '%');
      $st->execute();
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as &$r) {
        $tot = (float)($r['total_amount'] ?? 0);
        $paid = (float)($r['paid_amount'] ?? 0);
        $r['remaining_amount'] = max(0, $tot - $paid);
      }
      respond($rows);
    } catch (Throwable $e) { respond([], ['message' => $e->getMessage()], 500); }
  }

  if ($name === 'prepare-installment-plan') {
    try {
      $pdo = pdo();
      $payload = read_json_body();
      $orderId = isset($payload['order_id']) ? trim((string)$payload['order_id']) : '';
      $orderNo = isset($payload['order_number']) ? trim((string)$payload['order_number']) : '';
      if ($orderId === '' && $orderNo === '') { respond(null, ['message' => 'order_id or order_number required'], 400); }
      $where = $orderId !== '' ? 'o.id = :oid' : 'o.order_number = :ono';
      $sql = "SELECT 
        o.id,
        o.order_number,
        o.customer_id,
        COALESCE(o.total_amount, o.amount, 0) AS total_amount,
        COALESCE(c.name, o.customer_name) AS customer_name,
        COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone,
        (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.order_id = o.id) AS paid_amount
      FROM orders o
      LEFT JOIN customers c ON o.customer_id = c.id
      WHERE $where
      LIMIT 1";
      $st = $pdo->prepare($sql);
      if ($orderId !== '') { $st->bindValue(':oid', $orderId); } else { $st->bindValue(':ono', $orderNo); }
      $st->execute();
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$row) { respond(null, ['message' => 'Order not found'], 404); }
      $st2 = $pdo->prepare('SELECT id FROM installment_plans WHERE order_id = :oid LIMIT 1');
      $st2->execute([':oid' => $row['id']]);
      $hasPlan = (bool)$st2->fetchColumn();
      $tot = (float)($row['total_amount'] ?? 0);
      $paid = (float)($row['paid_amount'] ?? 0);
      $remaining = max(0, $tot - $paid);
      $summary = [
        'order_id' => (string)$row['id'],
        'order_number' => (string)$row['order_number'],
        'customer_id' => (string)$row['customer_id'],
        'customer_name' => (string)$row['customer_name'],
        'customer_phone' => (string)$row['customer_phone'],
        'total_amount' => number_format($tot, 2, '.', ''),
        'paid_amount' => number_format($paid, 2, '.', ''),
        'remaining_amount' => number_format($remaining, 2, '.', ''),
        'eligible' => ($remaining > 0.01) && !$hasPlan,
        'has_existing_plan' => $hasPlan,
      ];
      respond($summary);
    } catch (Throwable $e) { respond(null, ['message' => $e->getMessage()], 500); }
  }

  if ($name === 'notify-new-order') {
    $pdo = pdo();
    // Load follow-up settings
    $settings = null;
     try { $stS = $pdo->query("SELECT * FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1"); $settings = $stS->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
    if ($settings && ((!empty($settings['notify_new_order']) && (string)$settings['notify_new_order'] !== '0' && strtolower((string)$settings['notify_new_order'])!=='false') || !isset($settings['notify_new_order']))) {
      // ok
    } else {
      respond([ 'message' => 'Notification disabled' ]);
    }
    // Determine test mode
    $isTest = !empty($payload['test']);
    if ($isTest) {
      $now = date('Y-m-d H:i:s');
      $tomorrow = date('Y-m-d', time()+86400);
      $itemsText = "1. منتج تجريبي 1\nالكمية: 2\nالسعر: 500 ريال\nالإجمالي: 1000 ريال\nالوصف: وصف المنتج التجريبي الأول\n\n2. منتج تجريبي 2\nالكمية: 1\nالسعر: 500 ريال\nالإجمالي: 500 ريال\nالوصف: وصف المنتج التجريبي الثاني";
      $ctx = [
        'order_number' => 'ORD-TEST-12345',
        'customer_name' => 'عميل تجريبي',
        'customer_whatsapp' => '+966501234567',
        'service_name' => 'خدمة تجريبية',
        'status' => 'قيد الانتظار',
        'delivery_date' => $tomorrow,
        'amount' => number_format(1500, 2),
        'paid_amount' => number_format(500, 2),
        'remaining_amount' => number_format(1000, 2),
        'order_items' => $itemsText,
        'timestamp' => $now,
        'notes' => 'طلب تجريبي لاختبار نظام الإشعارات'
      ];
      respond($handle_notify_event('new_order_notification', $ctx));
    }

    $orderId = $payload['orderId'] ?? ($payload['order_id'] ?? null);
    $orderNo = isset($payload['order_number']) ? (string)$payload['order_number'] : '';
    $ord = null;
    try {
      if (!empty($orderId)) {
        $st = $pdo->prepare("SELECT o.*, c.name AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone, s.name AS service_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id LEFT JOIN service_types s ON o.service_type_id = s.id WHERE o.id = :id LIMIT 1");
        $st->execute([':id'=>$orderId]); $ord = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      } elseif ($orderNo !== '') {
        $st = $pdo->prepare("SELECT o.*, c.name AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone, s.name AS service_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id LEFT JOIN service_types s ON o.service_type_id = s.id WHERE o.order_number = :n LIMIT 1");
        $st->execute([':n'=>$orderNo]); $ord = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      }
    } catch (Throwable $e) { $ord = null; }

    if (!$ord) { respond([ 'error' => 'order_not_found' ], null, 404); }

    $paidSum = 0.0; try { $st2 = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :id'); $st2->execute([':id'=>$ord['id']]); $row2 = $st2->fetch(PDO::FETCH_ASSOC); $paidSum = (float)($row2['s'] ?? 0); } catch (Throwable $e) { $paidSum = 0.0; }
    $totalAmt = isset($ord['total_amount']) ? (float)$ord['total_amount'] : (isset($ord['amount']) ? (float)$ord['amount'] : 0);
    $itemsText = build_order_items_section($ord['id']);

    $statusMap = [ 'pending'=>'قيد الانتظار','in_progress'=>'قيد التنفيذ','completed'=>'مكتمل','cancelled'=>'ملغي' ];
    $stTxt = $statusMap[$ord['status'] ?? ''] ?? ($ord['status'] ?? '');

    $ctx = [
      'order_number' => (string)$ord['order_number'],
      'customer_name' => (string)($ord['customer_name'] ?? ''),
      'customer_whatsapp' => (string)($ord['customer_phone'] ?? ''),
      'service_name' => (string)($ord['service_name'] ?? ''),
      'status' => $stTxt,
      'delivery_date' => (string)($ord['delivery_date'] ?? ''),
      'amount' => number_format($totalAmt, 2),
      'paid_amount' => number_format($paidSum, 2),
      'remaining_amount' => number_format(max(0,$totalAmt-$paidSum), 2),
      'order_items' => $itemsText,
      'timestamp' => date('Y-m-d H:i:s'),
      'notes' => (string)($ord['notes'] ?? '')
    ];
    respond($handle_notify_event('new_order_notification', $ctx));
  }
  if ($name === 'notify-new-payment') {
    $pdo = pdo();
    // Check for payment_id path (mimic supabase)
    $paymentId = $payload['payment_id'] ?? null;
    $isTest = !empty($payload['test']);
    if ($isTest) {
      $now = date('Y-m-d H:i:s');
      $ctx = [
        'order_number' => 'ORD-TEST-12345',
        'customer_name' => 'عميل تجريبي',
        'customer_whatsapp' => '+966501234567',
        'amount' => number_format(750, 2),
        'payment_type' => '💵 نقدي',
        'payment_time' => date('Y-m-d'),
        'total_amount' => number_format(1500, 2),
        'paid_amount' => number_format(1250, 2),
        'remaining_amount' => number_format(250, 2),
        'order_status' => '⏳ دفعة جزئية',
        'timestamp' => $now,
      ];
      respond($handle_notify_event('new_payment_notification', $ctx));
    }
    if (!empty($paymentId)) {
      // Fetch payment with order and customer
      $p = null;
      try {
        $st = $pdo->prepare("SELECT p.*, o.id AS o_id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, c.name AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone FROM payments p LEFT JOIN orders o ON p.order_id = o.id LEFT JOIN customers c ON o.customer_id = c.id WHERE p.id = :id LIMIT 1");
        $st->execute([':id'=>$paymentId]); $p = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      } catch (Throwable $e) { $p = null; }
      if (!$p) { respond([ 'error' => 'payment_not_found' ], null, 404); }
      $paidSum = 0.0; try { $s = $pdo->prepare('SELECT SUM(amount) AS s FROM payments WHERE order_id = :id'); $s->execute([':id'=>$p['order_id']]); $r = $s->fetch(PDO::FETCH_ASSOC); $paidSum = (float)($r['s'] ?? 0); } catch (Throwable $e) { $paidSum = 0.0; }
      $totalAmt = isset($p['total_amount']) ? (float)$p['total_amount'] : 0.0;
      $remaining = max(0, $totalAmt - $paidSum);
      // Map payment type
      $pt = trim(mb_strtolower((string)($p['payment_type'] ?? '')));
      $ptDecor = (string)($p['payment_type'] ?? '');
      if ($pt !== '') {
        if (strpos($pt,'شب')!==false || strpos($pt,'card')!==false || strpos($pt,'network')!==false) $ptDecor = '💳 شبكة';
        elseif (strpos($pt,'كاش')!==false || strpos($pt,'cash')!==false) $ptDecor = '💵 نقدي';
        elseif (strpos($pt,'تحويل')!==false || strpos($pt,'bank')!==false) $ptDecor = '🏦 تحويل بنكي';
      }
      $ctx = [
        'order_number' => (string)($p['order_number'] ?? ''),
        'customer_name' => (string)($p['customer_name'] ?? ''),
        'customer_whatsapp' => (string)($p['customer_phone'] ?? ''),
        'amount' => number_format((float)($p['amount'] ?? 0), 2),
        'payment_type' => $ptDecor,
        'payment_time' => date('Y-m-d'),
        'total_amount' => number_format($totalAmt, 2),
        'paid_amount' => number_format($paidSum, 2),
        'remaining_amount' => number_format($remaining, 2),
        'order_status' => ($remaining <= 0 ? '✅ مدفوع بالكامل' : '⏳ دفعة جزئية'),
        'timestamp' => date('Y-m-d H:i:s'),
      ];
      // Aliases
      $ctx['total'] = $ctx['total_amount']; $ctx['paid'] = $ctx['paid_amount']; $ctx['remaining'] = $ctx['remaining_amount'];
      respond($handle_notify_event('new_payment_notification', $ctx));
    }
    // Fallback: existing path with order_number/order_id
    $orderNo = trim((string)($payload['order_number'] ?? ''));
    $orderId = $payload['order_id'] ?? null;
    $amountVal = isset($payload['amount']) ? (float)$payload['amount'] : null;
    $paymentTypeRaw = (string)($payload['payment_type'] ?? ($payload['payment_method'] ?? ''));

    $pdo = pdo();
    $db = [ 'id' => null, 'order_number' => $orderNo, 'customer_name' => '', 'customer_phone' => '', 'total_amount' => null ];
    try {
      if ($orderNo !== '') {
        $st = $pdo->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.order_number = :n LIMIT 1");
        $st->execute([':n' => $orderNo]); $r = $st->fetch(PDO::FETCH_ASSOC) ?: null; if ($r) { $db = array_merge($db, $r); }
      }
      if (!$db['id'] && !empty($orderId)) {
        $st = $pdo->prepare("SELECT o.id, o.order_number, COALESCE(o.total_amount, o.amount) AS total_amount, COALESCE(c.name, o.customer_name) AS customer_name, COALESCE(c.whatsapp, c.phone, o.customer_phone) AS customer_phone FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.id = :id LIMIT 1");
        $st->execute([':id' => $orderId]); $r2 = $st->fetch(PDO::FETCH_ASSOC) ?: null; if ($r2) { $db = array_merge($db, $r2); }
      }
    } catch (Throwable $eDb) { /* ignore */ }

    // Sum paid amounts to date
    $paidSum = null; $remaining = null;
    if (!empty($db['id'])) {
      try {
        $st2 = $pdo->prepare("SELECT SUM(amount) AS s FROM payments WHERE order_id = :id");
        $st2->execute([':id' => $db['id']]); $row2 = $st2->fetch(PDO::FETCH_ASSOC);
        $paidSum = (float)($row2['s'] ?? 0);
      } catch (Throwable $eP) { $paidSum = null; }
    }

    $totalAmt = isset($payload['total_amount']) ? (float)$payload['total_amount'] : (isset($db['total_amount']) ? (float)$db['total_amount'] : null);
    if ($totalAmt !== null && $paidSum !== null) { $remaining = max(0, $totalAmt - $paidSum); }

    // Decorate payment type with emoji
    $pt = trim(mb_strtolower($paymentTypeRaw)); $ptDecor = $paymentTypeRaw;
    if ($pt !== '') {
      if (strpos($pt, 'شب') !== false || strpos($pt, 'network') !== false) $ptDecor = '💳 شبكة';
      elseif (strpos($pt, 'كاش') !== false || strpos($pt, 'cash') !== false) $ptDecor = '💵 كاش';
      elseif (strpos($pt, 'تحويل') !== false || strpos($pt, 'حوال') !== false || strpos($pt, 'bank') !== false) $ptDecor = '🏦 حوالة';
    }

    $ctx = [
      'order_number' => $db['order_number'] ?: $orderNo,
      'customer_name' => (string)($payload['customer_name'] ?? ($db['customer_name'] ?? '')),
      'customer_phone' => (string)($db['customer_phone'] ?? ''),
      'amount' => ($amountVal !== null ? number_format($amountVal, 2) : (isset($payload['amount']) ? number_format((float)$payload['amount'], 2) : '')),
      'payment_type' => $ptDecor,
      'total_amount' => ($totalAmt !== null ? number_format($totalAmt, 2) : (isset($payload['total_amount']) ? number_format((float)$payload['total_amount'], 2) : '')),
      'paid_amount' => ($paidSum !== null ? number_format($paidSum, 2) : (isset($payload['paid_amount']) ? number_format((float)$payload['paid_amount'], 2) : '')),
      'remaining_amount' => ($remaining !== null ? number_format($remaining, 2) : (isset($payload['remaining_amount']) ? number_format((float)$payload['remaining_amount'], 2) : '')),
      'status' => ($remaining !== null && $remaining <= 0 ? '✅ مدفوع بالكامل' : ''),
    ];

    // Backward-compatible aliases for template variables
    $ctx['total'] = $ctx['total_amount'];
    $ctx['paid'] = $ctx['paid_amount'];
    $ctx['remaining'] = $ctx['remaining_amount'];
    $ctx['customer_whatsapp'] = $ctx['customer_phone'];
    $ctx['phone'] = $ctx['customer_phone'];
    respond($handle_notify_event('new_payment_notification', $ctx));
  }
  if ($name === 'notify-delivery-delay') {
    $pdo = pdo();
    // Load settings
    $settings = null; try { $stS = $pdo->query("SELECT * FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1"); $settings = $stS->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
    $enabled = $settings && (!empty($settings['notify_delivery_delay']) && strtolower((string)$settings['notify_delivery_delay'])!=='false' && (string)$settings['notify_delivery_delay']!=='0');
    $number = get_followup_number();
    if ((!$enabled && empty($payload['test'])) || !$number) { respond([ 'message' => 'Notification disabled' ]); }
    // Test mode
    $isTest = !empty($payload['test']);
    if ($isTest) {
      $ctx = [
        'order_number' => 'TEST-DEL-' . date('Ymd'),
        'customer_name' => 'اختبار',
        'delivery_date' => date('Y-m-d'),
        'delay_days' => (string)($settings['delivery_delay_days'] ?? 1),
        'timestamp' => date('Y-m-d H:i:s'),
      ];
      respond($handle_notify_event('delivery_delay_notification', $ctx));
    }
    $days = (int)($settings['delivery_delay_days'] ?? 1);
    $th = date('Y-m-d', time() - $days*86400);
    $rows = [];
    try {
      $st = $pdo->prepare("SELECT o.id, o.order_number, o.delivery_date, c.name AS customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.status = 'in_progress' AND o.delivery_date < :th ORDER BY o.delivery_date ASC LIMIT 20");
      $st->execute([':th'=>$th]); $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $rows = []; }
    $count = 0;
    foreach ($rows as $r) {
      $ctx = [
        'order_number' => (string)($r['order_number'] ?? ''),
        'customer_name' => (string)($r['customer_name'] ?? ''),
        'delivery_date' => (string)($r['delivery_date'] ?? ''),
        'delay_days' => (string)$days,
        'timestamp' => date('Y-m-d H:i:s'),
      ];
      $res = $handle_notify_event('delivery_delay_notification', $ctx);
      if (!empty($res['success'])) $count++;
    }
    respond([ 'success' => true, 'count' => $count ]);
  }
  if ($name === 'notify-payment-delay') {
    $pdo = pdo();
    // Load settings
    $settings = null; try { $stS = $pdo->query("SELECT * FROM follow_up_settings ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 1"); $settings = $stS->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
    $enabled = $settings && (!empty($settings['notify_payment_delay']) && strtolower((string)$settings['notify_payment_delay'])!=='false' && (string)$settings['notify_payment_delay']!=='0');
    $number = get_followup_number();
    if ((!$enabled && empty($payload['test'])) || !$number) { respond([ 'message' => 'Notification disabled' ]); }

    // Test mode
    $isTest = !empty($payload['test']);
    if ($isTest) {
      $ctx = [
        'customer_name' => 'اختبار',
        'customer_whatsapp' => $number,
        'customer_phone' => $number,
        'outstanding_balance' => number_format(100, 2),
        'oldest_order' => 'TEST-PAY-' . date('Ymd'),
        'order_date' => date('Y-m-d'),
        'delay_days' => (string)($settings['payment_delay_days'] ?? 1),
        'timestamp' => date('Y-m-d H:i:s')
      ];
      respond($handle_notify_event('payment_delay_notification', $ctx));
    }

    $days = (int)($settings['payment_delay_days'] ?? 1);
    $count = 0; $rows = [];
    // Prefer precomputed view/table if exists
    if (table_exists('customer_outstanding_balances')) {
      try {
        $st = $pdo->query("SELECT * FROM customer_outstanding_balances WHERE outstanding_balance > 0 LIMIT 50");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (Throwable $e) { $rows = []; }
      foreach ($rows as $cust) {
        // Oldest order older than threshold
        $oldest = null;
        try {
          $st2 = $pdo->prepare("SELECT id, order_number, created_at FROM orders WHERE customer_id = :cid AND created_at < DATE_SUB(NOW(), INTERVAL :d DAY) ORDER BY created_at ASC LIMIT 1");
          $st2->bindValue(':cid', $cust['customer_id']); $st2->bindValue(':d', $days, PDO::PARAM_INT); $st2->execute(); $oldest = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e2) { $oldest = null; }
        if (!$oldest) continue;
        $ctx = [
          'customer_name' => (string)($cust['customer_name'] ?? ''),
          'customer_whatsapp' => (string)($cust['whatsapp'] ?? ''),
          'customer_phone' => (string)($cust['phone'] ?? ''),
          'outstanding_balance' => number_format((float)($cust['outstanding_balance'] ?? 0), 2),
          'oldest_order' => (string)($oldest['order_number'] ?? ''),
          'order_date' => (string)($oldest['created_at'] ?? ''),
          'delay_days' => (string)$days,
          'timestamp' => date('Y-m-d H:i:s')
        ];
        $res = $handle_notify_event('payment_delay_notification', $ctx);
        if (!empty($res['success'])) $count++;
      }
      respond([ 'success' => true, 'count' => $count ]);
    }

    respond([ 'success' => true, 'count' => 0, 'message' => 'customer_outstanding_balances not found' ]);
  }
  if ($name === 'notify-new-expense') {
    $ctx = [
      'amount' => isset($payload['amount']) ? number_format((float)$payload['amount'], 2) : '',
      'expense_type' => (string)($payload['expense_type'] ?? ''),
      'description' => (string)($payload['description'] ?? ''),
      'expense_date' => (string)($payload['expense_date'] ?? ''),
      'receipt_number' => (string)($payload['receipt_number'] ?? ''),
    ];
    respond($handle_notify_event('new_expense_notification', $ctx));
  }

  // Helpers
  $safe_email = function($email){ $email = trim((string)$email); return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ''; };

  $get_settings_email = function() use ($safe_email) {
    try {
      $st = pdo()->prepare("SELECT email FROM follow_up_settings WHERE email IS NOT NULL AND email <> '' ORDER BY updated_at DESC LIMIT 1");
      $st->execute(); $row = $st->fetch(); if ($row && !empty($row['email'])) return $safe_email($row['email']);
    } catch (Throwable $e) { /* ignore */ }
    // Fallback to website_settings JSON if present
    try {
      $st = pdo()->prepare("SELECT value FROM website_settings WHERE `key` = 'website_content' LIMIT 1");
      $st->execute(); $row = $st->fetch(); if ($row && !empty($row['value'])) { $conf = json_decode($row['value'], true); if (!empty($conf['contactInfo']['email'])) return $safe_email($conf['contactInfo']['email']); }
    } catch (Throwable $e) { /* ignore */ }
    return '';
  };

  $send_mail_with_attachment = function($to, $subject, $message, $attachments = []) {
    $boundary = "==Multipart_Boundary_x" . md5((string)microtime()) . "x";
    $headers = "MIME-Version: 1.0\r\n" .
               "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n" .
               "From: no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n";

    $body = "--$boundary\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n\r\n" .
            (string)$message . "\r\n";

    foreach ($attachments as $att) {
      $filename = $att['name'] ?? ('file_' . time());
      $data = $att['data'] ?? '';
      $type = $att['type'] ?? 'application/octet-stream';
      $body .= "--$boundary\r\n" .
               "Content-Type: $type; name=\"$filename\"\r\n" .
               "Content-Transfer-Encoding: base64\r\n" .
               "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n" .
               chunk_split(base64_encode($data)) . "\r\n";
    }
    $body .= "--$boundary--";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
  };

  $export_sql = function() {
    $pdo = pdo();
    $out = "-- Promo Sync Suite MySQL backup\n-- Host: " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
    // List tables
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) { $tables[] = $r[0]; }
    foreach ($tables as $t) {
      try {
        $row = $pdo->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['Create Table'])) {
          $out .= "DROP TABLE IF EXISTS `{$t}`;\n";
          $out .= $row['Create Table'] . ";\n\n";
        }
      } catch (Throwable $e) { /* ignore */ }
      try {
        $st = $pdo->query('SELECT * FROM `'.$t.'`');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
          $cols = array_map(function($c){ return '`'.str_replace('`','``',$c).'`'; }, array_keys($row));
          $vals = array_map(function($v) use ($pdo){
            if ($v === null) return 'NULL';
            return $pdo->quote((string)$v);
          }, array_values($row));
          $out .= "INSERT INTO `{$t}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
        }
        $out .= "\n";
      } catch (Throwable $e) { /* ignore */ }
    }
    return $out;
  };

  $generate_orders_csv = function($dateYmd = null) {
    $pdo = pdo(); $date = $dateYmd ?: date('Y-m-d');
    $sql = "SELECT o.id, o.order_number, o.status, o.total_amount, o.created_at, o.delivery_date, c.name AS customer_name, c.phone AS customer_phone FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE DATE(o.created_at) = :d ORDER BY o.created_at DESC";
    $st = $pdo->prepare($sql); $st->execute([':d' => $date]); $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cols = ['id','order_number','status','total_amount','created_at','delivery_date','customer_name','customer_phone'];
    $csv = implode(',', $cols) . "\r\n";
    foreach ($rows as $r) {
      $line = [];
      foreach ($cols as $c) { $v = isset($r[$c]) ? (string)$r[$c] : ''; $v = str_replace(['"',"\r","\n"], ['""','',' '], $v); $line[] = '"' . $v . '"'; }
      $csv .= implode(',', $line) . "\r\n";
    }
    return $csv;
  };

  $ensure_whatsapp_schema = function() {
    try {
      $pdo = pdo(); global $CFG;
      // create tables if missing
      try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_messages (
          id VARCHAR(255) PRIMARY KEY,
          from_number VARCHAR(64) NULL,
          to_number VARCHAR(64) NOT NULL,
          message_type VARCHAR(64) NULL,
          message_content TEXT NULL,
          status VARCHAR(32) NULL,
          error_message TEXT NULL,
          dedupe_key VARCHAR(255) NULL,
          created_at DATETIME NOT NULL,
          updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_whatsapp_status_created ON whatsapp_messages (status, created_at)");
      } catch (Throwable $e) { /* ignore */ }
      try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_logs (
          id VARCHAR(255) PRIMARY KEY,
          webhook_url TEXT NULL,
          request_body LONGTEXT NULL,
          response_status INT NULL,
          response_body LONGTEXT NULL,
          success TINYINT(1) NULL,
          message_id VARCHAR(255) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      } catch (Throwable $e) { /* ignore */ }
      // check to_number type
      $st = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'to_number' LIMIT 1");
      $st->execute([':db' => $CFG['name']]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      $type = strtolower((string)($row['DATA_TYPE'] ?? ''));
      if ($type !== '' && !in_array($type, ['varchar','char','text'])) {
        try { $pdo->exec("ALTER TABLE whatsapp_messages MODIFY to_number VARCHAR(32) NULL"); } catch (Throwable $e2) { /* ignore */ }
      }
      // check from_number type as well
      $st2 = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'from_number' LIMIT 1");
      $st2->execute([':db' => $CFG['name']]);
      $row2 = $st2->fetch(PDO::FETCH_ASSOC);
      $type2 = strtolower((string)($row2['DATA_TYPE'] ?? ''));
      if ($type2 !== '' && !in_array($type2, ['varchar','char','text'])) {
        try { $pdo->exec("ALTER TABLE whatsapp_messages MODIFY from_number VARCHAR(32) NULL"); } catch (Throwable $e3) { /* ignore */ }
      }
      // widen dedupe_key if too small
      $st3 = $pdo->prepare("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'dedupe_key' LIMIT 1");
      $st3->execute([':db' => $CFG['name']]);
      $row3 = $st3->fetch(PDO::FETCH_ASSOC);
      $type3 = strtolower((string)($row3['DATA_TYPE'] ?? ''));
      $len3 = (int)($row3['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
      if ($type3 !== '' && in_array($type3, ['varchar','char']) && $len3 > 0 && $len3 < 255) {
        try { $pdo->exec("ALTER TABLE whatsapp_messages MODIFY dedupe_key VARCHAR(255) NULL"); } catch (Throwable $e4) { /* ignore */ }
      }
    } catch (Throwable $e) { /* ignore */ }
  };

  $get_followup_number = function() {
    try {
      $st = pdo()->query("SELECT whatsapp_number FROM follow_up_settings ORDER BY updated_at DESC LIMIT 1");
      $row = $st->fetch(PDO::FETCH_ASSOC);
      $num = isset($row['whatsapp_number']) ? trim((string)$row['whatsapp_number']) : '';
      return $num;
    } catch (Throwable $e) { return ''; }
  };

  $enqueue_followup_message = function($to, $message, $type = 'follow_up') use (&$ensure_whatsapp_schema) {
    try {
      $ensure_whatsapp_schema();
      $pdo = pdo();
      $id = generate_uuid_v4();
      $st = $pdo->prepare("INSERT INTO whatsapp_messages (id, from_number, to_number, message_type, message_content, status, created_at, dedupe_key) VALUES (:id, :from_number, :to_number, :message_type, :message_content, :status, NOW(), :dedupe)");
      $st->execute([
        ':id' => $id,
        ':from_number' => 'system',
        ':to_number' => (string)$to,
        ':message_type' => (string)$type,
        ':message_content' => (string)$message,
        ':status' => 'pending',
        ':dedupe' => $type . '_' . time() . '_' . substr(md5($to . $message), 0, 8),
      ]);
      return [ 'id' => $id ];
    } catch (Throwable $e) { return [ 'error' => $e->getMessage() ]; }
  };

  $process_whatsapp_queue = function($limit = 50) use (&$ensure_whatsapp_schema) {
    $pdo = pdo();
    $ensure_whatsapp_schema();
    try {
      // Resolve webhook URL from settings with fallbacks
      $webhookUrl = '';
      try {
        $st = $pdo->query("SELECT follow_up_webhook_url FROM follow_up_settings ORDER BY updated_at DESC LIMIT 1");
        $row = $st->fetch();
        $webhookUrl = (string)($row['follow_up_webhook_url'] ?? '');
      } catch (Throwable $e) { /* ignore */ }
      if (!$webhookUrl) {
        try {
          $st = $pdo->query("SELECT webhook_url FROM webhook_settings WHERE webhook_type IN ('outgoing','evaluation','invoice','proof','outstanding_balance_report','account_summary') AND (is_active = 1 OR is_active = '1' OR LOWER(is_active) IN ('true','t','yes','on')) ORDER BY updated_at DESC LIMIT 1");
          $row = $st->fetch();
          $webhookUrl = (string)($row['webhook_url'] ?? '');
        } catch (Throwable $e) { /* ignore */ }
      }

      $st = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE (status IS NULL OR status = '' OR status = 'pending') ORDER BY created_at ASC LIMIT :lim");
      $st->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
      $st->execute();
      $msgs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $processed = 0; $errors = 0; $details = [];
      foreach ($msgs as $m) {
        $ok = false;
        $lastErr = '';
        $toNumber = $m['to_number'] ?? $m['recipient'] ?? $m['phone'] ?? '';
        $resp = '';
        $code = 0;
        // Resolve per message type webhook if available
        $webhookUrl = resolve_webhook_for_message_type($m['message_type'] ?? '');
        // Try webhook if available
        if ($webhookUrl) {
          try {
            $ch = curl_init($webhookUrl);
            $toRaw = $m['to_number'] ?? $m['recipient'] ?? $m['phone'] ?? '';
            $msg = $m['message_content'] ?? $m['content'] ?? $m['message'] ?? '';
            $toNoPlus = ltrim((string)$toRaw, '+');
            $payload = json_encode([
              'event' => 'whatsapp_message_send',
              'data' => [
                'to' => $toNoPlus ?: $toRaw,
                'phone' => $toRaw,
                'phoneNumber' => $toRaw,
                'message' => $msg,
                'messageText' => $msg,
                'text' => $msg,
                'type' => 'text',
                'message_type' => $m['message_type'] ?? 'text',
                'timestamp' => time(),
                'from_number' => $m['from_number'] ?? 'system'
              ],
              'meta' => $m,
              'to' => $toRaw,
              'message' => $msg,
              'body' => $msg,
              'type' => $m['message_type'] ?? 'text'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 10]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ok = ($resp !== false && $code >= 200 && $code < 400);
            if (!$ok) {
              $lastErr = 'http_' . ($code ?: 0);
            }
            curl_close($ch);
          } catch (Throwable $e) { $ok = false; $lastErr = 'curl_error'; }
        } else {
          $lastErr = 'no_active_webhook';
        }
        // Mark as sent (or failed)
        try {
          $hasUpdatedAt = table_has_column('whatsapp_messages','updated_at');
          $hasErrMsg = table_has_column('whatsapp_messages','error_message');
          $setParts = ['status = :s'];
          $params = [':s' => $ok ? 'sent' : 'failed', ':id' => $m['id']];
          if ($hasUpdatedAt) { $setParts[] = 'updated_at = NOW()'; }
          if (!$ok && $hasErrMsg) { $setParts[] = 'error_message = :err'; $params[':err'] = $lastErr; }
          $sqlUp = 'UPDATE whatsapp_messages SET ' . implode(', ', $setParts) . ' WHERE id = :id';
          $st2 = $pdo->prepare($sqlUp);
          $st2->execute($params);

          // Log webhook call if table exists
          if (table_exists('webhook_logs')) {
            try {
              $logCols = ['id']; $logVals = [':lid']; $binds = [':lid' => generate_uuid_v4()];
              if (table_has_column('webhook_logs','webhook_url')) { $logCols[]='webhook_url'; $logVals[]=':lu'; $binds[':lu']=$webhookUrl; }
              if (table_has_column('webhook_logs','request_body')) {
                $reqJson = json_encode([
                  'type' => $m['message_type'] ?? 'text',
                  'to' => $toNumber,
                  'message' => $m['message_content'] ?? $m['content'] ?? $m['message'] ?? '',
                  'meta' => ['id' => $m['id']]
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $logCols[]='request_body'; $logVals[]=':lrb'; $binds[':lrb']=$reqJson;
              }
              if (table_has_column('webhook_logs','response_status')) { $logCols[]='response_status'; $logVals[]=':lrs'; $binds[':lrs']=(int)$code; }
              elseif (table_has_column('webhook_logs','status_code')) { $logCols[]='status_code'; $logVals[]=':lrs'; $binds[':lrs']=(int)$code; }
              if (table_has_column('webhook_logs','response_body')) { $logCols[]='response_body'; $logVals[]=':lrb2'; $binds[':lrb2']= is_string($resp) ? substr($resp,0,1000) : ''; }
              elseif (table_has_column('webhook_logs','response')) { $logCols[]='response'; $logVals[]=':lrb2'; $binds[':lrb2']= is_string($resp) ? substr($resp,0,1000) : ''; }
              if (table_has_column('webhook_logs','success')) { $logCols[]='success'; $logVals[]=':ls'; $binds[':ls']=$ok ? 1 : 0; }
              if (table_has_column('webhook_logs','message_id')) { $logCols[]='message_id'; $logVals[]=':lmid'; $binds[':lmid']=$m['id']; }
              // created_at if exists
              $createdInline = false;
              if (table_has_column('webhook_logs','created_at')) { $logCols[]='created_at'; $createdInline = true; }
              $sqlLog = 'INSERT INTO webhook_logs (' . implode(',', $logCols) . ') VALUES (' . implode(',', array_merge($logVals, $createdInline ? ['NOW()'] : [])) . ')';
              if ($createdInline) { array_pop($logVals); } // already appended NOW()
              // Fix placeholders if created_at added
              $sqlLog = 'INSERT INTO webhook_logs (' . implode(',', $logCols) . ') VALUES (' . implode(',', $createdInline ? array_merge($logVals, ['NOW()']) : $logVals) . ')';
              $stLog = $pdo->prepare($sqlLog);
              foreach ($binds as $k => $v) { $stLog->bindValue($k, $v); }
              $stLog->execute();
            } catch (Throwable $eLog) { /* ignore log errors */ }
          }

          // Push details for response
          $details[] = [
            'id' => $m['id'],
            'to' => $toNumber,
            'message_type' => $m['message_type'] ?? 'text',
            'ok' => $ok,
            'status_code' => (int)$code,
            'webhook_url' => $webhookUrl,
            'error' => $lastErr,
            'response_sample' => is_string($resp) ? substr($resp, 0, 512) : ''
          ];

          $processed++;
        } catch (Throwable $e) { $errors++; }
      }
      return ['processed' => $processed, 'errors' => $errors, 'webhook_url' => $webhookUrl, 'details' => $details];
    } catch (Throwable $e) { return ['processed' => 0, 'errors' => 1, 'message' => $e->getMessage()]; }
  };

  // Router for function names
  try {
    switch ($name) {
      case 'send-daily-backup': {
        $to = $safe_email($payload['to'] ?? '') ?: $get_settings_email();
        if (!$to) respond(null, ['message' => 'No recipient email configured'], 400);
        $sqlDump = $export_sql();
        // Save a copy on disk
        $dir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $fname = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        @file_put_contents($dir . DIRECTORY_SEPARATOR . $fname, $sqlDump);
        $ok = $send_mail_with_attachment($to, 'النسخة الاحتياطية اليومية - ' . date('Y-m-d'), "مرفق نسخة احتياطية لقاعدة البيانات بتاريخ " . date('Y-m-d H:i:s'), [ ['name' => $fname, 'data' => $sqlDump, 'type' => 'application/sql'] ]);
        respond(['success' => $ok, 'message' => $ok ? 'تم إرسال النسخة الاحتياطية' : 'تعذر إرسال الإيميل']);
      }
      case 'send-daily-orders-report': {
        $to = $safe_email($payload['to'] ?? '') ?: $get_settings_email();
        if (!$to) respond(null, ['message' => 'No recipient email configured'], 400);
        
        // جلب بيانات الطلبات لليوم الحالي
        $pdo = pdo();
        $date = date('Y-m-d');
        $sql = "SELECT o.id, o.order_number, o.status, o.total_amount, o.created_at, o.delivery_date, c.name AS customer_name, c.phone AS customer_phone
                FROM orders o LEFT JOIN customers c ON o.customer_id = c.id
                WHERE DATE(o.created_at) = :d ORDER BY o.created_at DESC";
        $st = $pdo->prepare($sql);
        $st->execute([':d' => $date]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // توليد ملف Excel بصيغة SpreadsheetML (متوافق مع Excel) بامتداد .xls
        $headers = ['id','order_number','status','total_amount','created_at','delivery_date','customer_name','customer_phone'];
        $xml = "<?xml version=\"1.0\"?>\n" .
               "<?mso-application progid=\"Excel.Sheet\"?>\n" .
               "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" " .
               "xmlns:o=\"urn:schemas-microsoft-com:office:office\" " .
               "xmlns:x=\"urn:schemas-microsoft-com:office:excel\" " .
               "xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\" " .
               "xmlns:html=\"http://www.w3.org/TR/REC-html40\">\n" .
               " <Worksheet ss:Name=\"Orders\">\n" .
               "  <Table>\n";

        // صف العناوين
        $xml .= "   <Row>";
        foreach ($headers as $h) {
          $xml .= "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($h, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</Data></Cell>";
        }
        $xml .= "</Row>\n";

        // الصفوف
        foreach ($rows as $r) {
          $xml .= "   <Row>";
          foreach ($headers as $h) {
            $v = isset($r[$h]) ? (string)$r[$h] : '';
            // تحديد النوع
            if (in_array($h, ['total_amount'])) {
              $num = is_numeric($v) ? $v : '0';
              $xml .= "<Cell><Data ss:Type=\"Number\">" . $num . "</Data></Cell>";
            } else {
              $xml .= "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</Data></Cell>";
            }
          }
          $xml .= "</Row>\n";
        }

        $xml .= "  </Table>\n" .
                " </Worksheet>\n" .
                "</Workbook>";

        $fname = 'orders_report_' . $date . '.xls';
        $ok = $send_mail_with_attachment($to, 'تقرير الطلبات اليومي - ' . $date, "مرفق تقرير الطلبات لليوم (صيغة Excel)." , [ ['name' => $fname, 'data' => $xml, 'type' => 'application/vnd.ms-excel'] ]);
        respond(['success' => $ok, 'message' => $ok ? 'تم إرسال التقرير اليومي' : 'تعذر إرسال الإيميل']);
      }
      case 'preview-sql-backup': {
        if (!$isMultipart || empty($_FILES['file'])) respond(null, ['message' => 'لم يتم رفع الملف'], 400);
        $f = $_FILES['file']; $content = file_get_contents($f['tmp_name']);
        $lines = preg_split("/\r?\n/", (string)$content);
        $totalLines = count($lines);
        $totalInserts = 0; $tableStats = [];
        foreach ($lines as $ln) {
          if (preg_match('/^INSERT INTO `([^`]+)`/i', $ln, $m)) { $totalInserts++; $table = $m[1]; $tableStats[$table] = ($tableStats[$table] ?? 0) + 1; }
        }
        $firstLines = implode("\n", array_slice($lines, 0, 50));
        respond(['preview' => [ 'fileName' => $f['name'], 'fileSize' => (int)$f['size'], 'totalLines' => $totalLines, 'totalInserts' => $totalInserts, 'tableStats' => $tableStats, 'firstLines' => $firstLines ]]);
      }
      case 'restore-sql-backup': {
        if (!$isMultipart || empty($_FILES['file'])) respond(null, ['message' => 'لم يتم رفع الملف'], 400);
        $f = $_FILES['file']; $sql = file_get_contents($f['tmp_name']);
        $pdo = pdo(); $pdo->beginTransaction();
        try {
          // Split by ; while ignoring simple cases of ; inside strings (basic approach)
          $statements = preg_split('/;\s*\n/', $sql);
          $count = 0;
          foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '--') === 0 || strpos($stmt, '/*') === 0) continue;
            $pdo->exec($stmt);
            $count++;
          }
          $pdo->commit();
          respond(['success' => true, 'message' => 'تمت الاستعادة بنجاح', 'executed' => $count]);
        } catch (Throwable $e) {
          $pdo->rollBack();
          respond(['success' => false, 'message' => 'فشل الاستفادة: ' . $e->getMessage()], 500);
        }
      }
      case 'preview-excel-orders': {
        if (!$isMultipart || empty($_FILES['file'])) respond(null, ['message' => 'لم يتم رفع الملف'], 400);
        $f = $_FILES['file']; $name = strtolower($f['name']);
        $raw = file_get_contents($f['tmp_name']);
        // Minimal support: treat as CSV if extension is .csv; otherwise return guidance
        if (!preg_match('/\.csv$/', $name)) {
          respond(['preview' => [ 'fileName' => $f['name'], 'fileSize' => (int)$f['size'], 'totalRows' => 0, 'columns' => [], 'sampleData' => [] ], 'warning' => 'الرجاء رفع ملف CSV (UTF-8). سيتم دعم XLSX لاحقاً.'], 200);
        }
        $lines = preg_split("/\r?\n/", trim((string)$raw));
        $cols = [];
        $rows = [];
        foreach ($lines as $i => $ln) {
          if ($ln === '') continue;
          $parts = str_getcsv($ln);
          if ($i === 0) { $cols = $parts; continue; }
          $row = [];
          foreach ($cols as $j => $c) { $row[$c] = $parts[$j] ?? ''; }
          $rows[] = $row; if (count($rows) >= 10) break;
        }
        respond(['preview' => [ 'fileName' => $f['name'], 'fileSize' => (int)$f['size'], 'totalRows' => max(0, count($lines) - 1), 'columns' => $cols, 'sampleData' => $rows ]]);
      }
      case 'import-excel-orders': {
        if (!$isMultipart || empty($_FILES['file'])) respond(null, ['message' => 'لم يتم رفع الملف'], 400);
        $f = $_FILES['file']; $name = strtolower($f['name']);
        $raw = file_get_contents($f['tmp_name']);
        if (!preg_match('/\.csv$/', $name)) respond(null, ['message' => 'الرجاء رفع ملف CSV (UTF-8) للطلبات'], 400);
        $lines = preg_split("/\r?\n/", trim((string)$raw));
        if (count($lines) < 2) respond(['success' => false, 'message' => 'الملف فارغ'], 200);
        $cols = str_getcsv($lines[0]); $colIndex = [];
        foreach ($cols as $i => $c) { $colIndex[strtolower(trim($c))] = $i; }
        $required = ['customer_name','customer_phone','order_number','total_amount'];
        foreach ($required as $rc) { if (!array_key_exists($rc, $colIndex)) respond(null, ['message' => 'الأعمدة المطلوبة: customer_name, customer_phone, order_number, total_amount'], 400); }
        $pdo = pdo(); $inserted = 0; $skipped = 0;
        for ($i=1; $i<count($lines); $i++) {
          $ln = trim($lines[$i]); if ($ln === '') continue;
          $parts = str_getcsv($ln);
          $custName = $parts[$colIndex['customer_name']] ?? '';
          $custPhone = $parts[$colIndex['customer_phone']] ?? '';
          $orderNumber = $parts[$colIndex['order_number']] ?? '';
          $total = (float)($parts[$colIndex['total_amount']] ?? 0);
          if ($custName === '' || $orderNumber === '') { $skipped++; continue; }
          // Ensure customer exists
          $custId = null;
          try {
            $st = $pdo->prepare('SELECT id FROM customers WHERE phone = :p OR name = :n LIMIT 1');
            $st->execute([':p' => $custPhone, ':n' => $custName]); $row = $st->fetch();
            if ($row) { $custId = $row['id']; }
            else {
              $custId = generate_uuid_v4();
              $st2 = $pdo->prepare('INSERT INTO customers (id, name, phone, created_at) VALUES (:id,:n,:p,NOW())');
              $st2->execute([':id' => $custId, ':n' => $custName, ':p' => $custPhone]);
            }
          } catch (Throwable $e) { $skipped++; continue; }
          // Insert order if not exists
          try {
            $st = $pdo->prepare('SELECT id FROM orders WHERE order_number = :no LIMIT 1');
            $st->execute([':no' => $orderNumber]); $row = $st->fetch();
            if ($row) { $skipped++; continue; }
            $oid = generate_uuid_v4();
            $st2 = $pdo->prepare('INSERT INTO orders (id, order_number, customer_id, total_amount, status, created_at) VALUES (:id,:no,:cid,:tot,:st,NOW())');
            $st2->execute([':id' => $oid, ':no' => $orderNumber, ':cid' => $custId, ':tot' => $total, ':st' => 'in_progress']);
            $inserted++;
          } catch (Throwable $e) { $skipped++; continue; }
        }
        respond(['success' => true, 'message' => "تم استيراد $inserted طلب (تخطي $skipped)"]);
      }
      case 'process-whatsapp-queue': {
        $limit = isset($payload['limit']) ? intval($payload['limit']) : 50;
        $res = $process_whatsapp_queue($limit);
        respond($res);
      }
      case 'webhook-test': {
        $url = (string)($payload['webhook_url'] ?? '');
        if ($url === '') { respond(null, ['message' => 'webhook_url required'], 400); }
        $event = (string)($payload['event'] ?? 'webhook_test');
        $testData = is_array($payload['test_data'] ?? null) ? $payload['test_data'] : [];
        $body = [
          'webhook_url' => $url,
          'event' => $event,
          'test' => true,
          'timestamp' => date('c'),
          'message' => $testData['message'] ?? 'هذا اختبار للويب هوك',
          'customerPhone' => $testData['customerPhone'] ?? '+966535983261',
          'customerName' => $testData['customerName'] ?? 'عميل تجريبي',
          'notificationType' => $testData['notificationType'] ?? 'webhook_test',
          'echo' => $testData,
        ];
        try {
          $ch = curl_init($url);
          curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 15,
          ]);
          $resp = curl_exec($ch);
          $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
          $err = curl_error($ch);
          curl_close($ch);
          $success = ($resp !== false && $code >= 200 && $code < 400);
          respond([
            'success' => $success,
            'ok' => $success,
            'status' => $code ?: 0,
            'response' => is_string($resp) ? $resp : (string)$err,
            'echo' => $body,
            'function' => 'webhook-test'
          ]);
        } catch (Throwable $e) {
          respond([
            'success' => false,
            'ok' => false,
            'status' => 0,
            'response' => '',
            'error' => $e->getMessage(),
            'function' => 'webhook-test'
          ]);
        }
      }
      case 'notify-new-order': {
        $num = $get_followup_number();
        if (!$num) respond(null, ['message' => 'Missing follow-up WhatsApp number in settings'], 400);
        $isTest = !empty($payload['test']);
        $ctx = [
          'order_number' => (string)($payload['order_number'] ?? ''),
          'customer_name' => (string)($payload['customer_name'] ?? ''),
          'total_amount' => isset($payload['total_amount']) ? number_format((float)$payload['total_amount'], 2) : '',
          'delivery_date' => (string)($payload['delivery_date'] ?? ''),
          'notes' => (string)($payload['notes'] ?? ''),
          'timestamp' => date('Y-m-d H:i:s'),
        ];
        send_followup_event('new_order_notification', $ctx);
        if (!empty($res['error'])) respond(null, ['message' => $res['error']], 500);
        $proc = $process_whatsapp_queue(10);
        $detail = null;
        if (is_array($proc) && isset($proc['details']) && is_array($proc['details'])) {
          foreach ($proc['details'] as $d) { if (($d['id'] ?? '') === ($res['id'] ?? '')) { $detail = $d; break; } }
          if ($detail === null && count($proc['details']) > 0) { $detail = $proc['details'][0]; }
        }
        respond([
          'success' => true,
          'message_id' => $res['id'],
          'processed' => $proc,
          'webhook_url' => $proc['webhook_url'] ?? '',
          'detail' => $detail,
          'function' => 'notify-new-order'
        ]);
      }
      case 'notify-delivery-delay': {
        $num = $get_followup_number();
        if (!$num) respond(null, ['message' => 'Missing follow-up WhatsApp number in settings'], 400);
        $isTest = !empty($payload['test']);
        $ctx = [
          'order_number' => (string)($payload['order_number'] ?? ''),
          'customer_name' => (string)($payload['customer_name'] ?? ''),
          'delivery_date' => (string)($payload['delivery_date'] ?? ''),
          'delay_days' => isset($payload['delay_days']) ? (string)$payload['delay_days'] : '',
          'timestamp' => date('Y-m-d H:i:s'),
        ];
        send_followup_event('delivery_delay_notification', $ctx);
        if (!empty($res['error'])) respond(null, ['message' => $res['error']], 500);
        $proc = $process_whatsapp_queue(10);
        $detail = null;
        if (is_array($proc) && isset($proc['details']) && is_array($proc['details'])) {
          foreach ($proc['details'] as $d) { if (($d['id'] ?? '') === ($res['id'] ?? '')) { $detail = $d; break; } }
          if ($detail === null && count($proc['details']) > 0) { $detail = $proc['details'][0]; }
        }
        respond([
          'success' => true,
          'message_id' => $res['id'],
          'processed' => $proc,
          'webhook_url' => $proc['webhook_url'] ?? '',
          'detail' => $detail,
          'function' => 'notify-delivery-delay'
        ]);
      }
      case 'notify-payment-delay': {
        $num = $get_followup_number();
        if (!$num) respond(null, ['message' => 'Missing follow-up WhatsApp number in settings'], 400);
        $isTest = !empty($payload['test']);
        $ctx = [
          'customer_name' => (string)($payload['customer_name'] ?? ''),
          'customer_phone' => (string)($payload['customer_phone'] ?? ''),
          'outstanding_balance' => isset($payload['outstanding_balance']) ? number_format((float)$payload['outstanding_balance'], 2) : '',
          'oldest_order' => (string)($payload['oldest_order'] ?? ''),
          'order_date' => (string)($payload['order_date'] ?? ''),
          'delay_days' => isset($payload['delay_days']) ? (string)$payload['delay_days'] : '',
          'timestamp' => date('Y-m-d H:i:s'),
        ];
        send_followup_event('payment_delay_notification', $ctx);
        if (!empty($res['error'])) respond(null, ['message' => $res['error']], 500);
        $proc = $process_whatsapp_queue(10);
        $detail = null;
        if (is_array($proc) && isset($proc['details']) && is_array($proc['details'])) {
          foreach ($proc['details'] as $d) { if (($d['id'] ?? '') === ($res['id'] ?? '')) { $detail = $d; break; } }
          if ($detail === null && count($proc['details']) > 0) { $detail = $proc['details'][0]; }
        }
        respond([
          'success' => true,
          'message_id' => $res['id'],
          'processed' => $proc,
          'webhook_url' => $proc['webhook_url'] ?? '',
          'detail' => $detail,
          'function' => 'notify-payment-delay'
        ]);
      }
      case 'notify-new-expense': {
        $num = $get_followup_number();
        if (!$num) respond(null, ['message' => 'Missing follow-up WhatsApp number in settings'], 400);
        $isTest = !empty($payload['test']);
        $amount = isset($payload['amount']) ? (float)$payload['amount'] : null;
        $ctx = [
          'amount' => isset($payload['amount']) ? number_format((float)$payload['amount'], 2) : '',
          'expense_type' => (string)($payload['expense_type'] ?? ''),
          'description' => (string)($payload['description'] ?? ''),
          'expense_date' => (string)($payload['expense_date'] ?? ''),
          'receipt_number' => (string)($payload['receipt_number'] ?? ''),
          'timestamp' => date('Y-m-d H:i:s'),
        ];
        send_followup_event('new_expense_notification', $ctx);
        if (!empty($res['error'])) respond(null, ['message' => $res['error']], 500);
        $proc = $process_whatsapp_queue(10);
        $detail = null;
        if (is_array($proc) && isset($proc['details']) && is_array($proc['details'])) {
          foreach ($proc['details'] as $d) { if (($d['id'] ?? '') === ($res['id'] ?? '')) { $detail = $d; break; } }
          if ($detail === null && count($proc['details']) > 0) { $detail = $proc['details'][0]; }
        }
        respond([
          'success' => true,
          'message_id' => $res['id'],
          'processed' => $proc,
          'webhook_url' => $proc['webhook_url'] ?? '',
          'detail' => $detail,
          'function' => 'notify-new-expense'
        ]);
      }
      case 'notify-new-payment': {
        $num = $get_followup_number();
        if (!$num) respond(null, ['message' => 'Missing follow-up WhatsApp number in settings'], 400);
        $isTest = !empty($payload['test']);
        $amount = isset($payload['amount']) ? (float)$payload['amount'] : null;
        $ctx = [
          'amount' => isset($payload['amount']) ? number_format((float)$payload['amount'], 2) : '',
          'order_number' => (string)($payload['order_number'] ?? ''),
          'customer_name' => (string)($payload['customer_name'] ?? ''),
          'payment_type' => (string)($payload['payment_type'] ?? ($payload['payment_method'] ?? '')),
          'total_amount' => isset($payload['total_amount']) ? number_format((float)$payload['total_amount'], 2) : '',
          'paid_amount' => isset($payload['paid_amount']) ? number_format((float)$payload['paid_amount'], 2) : '',
          'remaining_amount' => isset($payload['remaining_amount']) ? number_format((float)$payload['remaining_amount'], 2) : '',
          'timestamp' => date('Y-m-d H:i:s'),
        ];
        send_followup_event('new_payment_notification', $ctx);
        if (!empty($res['error'])) respond(null, ['message' => $res['error']], 500);
        $proc = $process_whatsapp_queue(10);
        $detail = null;
        if (is_array($proc) && isset($proc['details']) && is_array($proc['details'])) {
          foreach ($proc['details'] as $d) { if (($d['id'] ?? '') === ($res['id'] ?? '')) { $detail = $d; break; } }
          if ($detail === null && count($proc['details']) > 0) { $detail = $proc['details'][0]; }
        }
        respond([
          'success' => true,
          'message_id' => $res['id'],
          'processed' => $proc,
          'webhook_url' => $proc['webhook_url'] ?? '',
          'detail' => $detail,
          'function' => 'notify-new-payment'
        ]);
      }
      case 'daily-financial-report': {
        $pdo = pdo();
        $url = '';
        // 1) Prefer follow_up_settings.follow_up_webhook_url
        try {
          $st = $pdo->query("SELECT follow_up_webhook_url FROM follow_up_settings ORDER BY updated_at DESC LIMIT 1");
          $row = $st->fetch();
          $url = (string)($row['follow_up_webhook_url'] ?? '');
        } catch (Throwable $e) { /* ignore */ }
        // 2) Fall back to webhook_settings of types financial
        if (!$url) {
          try {
            $st = $pdo->query("SELECT webhook_url FROM webhook_settings WHERE webhook_type IN ('outstanding_balance_report','account_summary') AND (is_active = 1 OR is_active = '1' OR LOWER(is_active) IN ('true','t','yes','on')) ORDER BY updated_at DESC LIMIT 1");
            $row = $st->fetch();
            $url = (string)($row['webhook_url'] ?? '');
          } catch (Throwable $e) { /* ignore */ }
        }
        // 3) Fall back to any active outgoing webhook
        if (!$url) {
          try {
            $st = $pdo->query("SELECT webhook_url FROM webhook_settings WHERE webhook_type = 'outgoing' AND (is_active = 1 OR is_active = '1' OR LOWER(is_active) IN ('true','t','yes','on')) ORDER BY updated_at DESC LIMIT 1");
            $row = $st->fetch();
            $url = (string)($row['webhook_url'] ?? '');
          } catch (Throwable $e) { /* ignore */ }
        }
        if (!$url) {
          respond(['success' => false, 'error' => 'لا يوجد Webhook نشط للتقارير المالية']);
        }
        $phone = (string)($payload['phone'] ?? '+966535983261');
        $customerName = (string)($payload['customer_name'] ?? 'عميل تقارير');
        $report = "🧪 اختبار تقرير مالي\nالعميل: {$customerName}\nالرقم: {$phone}\nالتاريخ: " . date('Y-m-d H:i:s') . "\nهذا اختبار لنظام ويبهوك التقارير المالية.";
        $body = [
          'type' => 'financial_report',
          'notificationType' => 'financial_report_test',
          'to' => $phone,
          'phone' => $phone,
          'customerName' => $customerName,
          'message' => $report,
          'timestamp' => date('c'),
          'test' => true,
        ];
        try {
          $ch = curl_init($url);
          curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 20,
          ]);
          $resp = curl_exec($ch);
          $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
          $err = curl_error($ch);
          curl_close($ch);
          $success = ($resp !== false && $code >= 200 && $code < 400);
          respond([
            'success' => $success,
            'status' => $code ?: 0,
            'response' => is_string($resp) ? $resp : (string)$err,
            'webhook_url' => $url,
          ]);
        } catch (Throwable $e) {
          respond([
            'success' => false,
            'status' => 0,
            'response' => '',
            'error' => $e->getMessage(),
            'webhook_url' => $url,
          ]);
        }
      }
      case 'send-order-notifications': {
        $payload = read_json_body();
        $type = (string)($payload['type'] ?? '');
        $orderId = (string)($payload['order_id'] ?? '');
        if ($orderId !== '' && $type === 'order_created') {
          send_order_template_message($orderId, 'order_created');
        }
        respond([ 'ok' => true, 'echo' => $payload, 'function' => $name ]);
        break;
      }
      default:
        respond([ 'ok' => true, 'echo' => $payload, 'function' => $name ]);
    }
  } catch (Throwable $e) {
    respond(null, ['message' => $e->getMessage(), 'code' => 'fn_error'], 500);
  }
}

// Router
$service = isset($_GET['service']) ? $_GET['service'] : null;
if (!$service) {
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  if (preg_match('#/api/([A-Za-z0-9_\-]+)\.php#', $uri, $m)) $service = $m[1];
}
// If accessed via /api/index.php directly without specifying service,
// auto-detect based on request payload: route DB actions to handle_db, otherwise functions
if (!$service || $service === 'index') {
  $body = read_json_body();
  if ((is_array($body) && isset($body['action'])) || (isset($_POST['action']))) {
    $service = 'db';
  } else {
    $service = 'functions';
  }
}

try {
  switch ($service) {
    case 'db': handle_db(); break;
    case 'auth': handle_auth(); break;
    case 'storage': handle_storage(); break;
    case 'functions': handle_functions(); break;
    case 'whatsapp': handle_whatsapp(); break;
    case 'wa-webhook': handle_wa_webhook(); break;
    case 'wa-media': handle_wa_media(); break;
    default: respond(null, [ 'message' => 'Unknown service' ], 404);
  }
} catch (Throwable $e) {
  respond(null, [ 'message' => $e->getMessage(), 'code' => 'unexpected' ], 500);
}
