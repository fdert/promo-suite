<?php
/**
 * security.php — Central security guard for the API router.
 *
 * This file is intentionally kept separate from index.php (rather than patched
 * inline all over a 10k-line file) so it is easy to audit, test, and extend.
 *
 * What it does:
 *  1. Hardens the PHP session cookie (HttpOnly, SameSite, Secure-on-HTTPS).
 *  2. Provides current_user() / require_auth() / require_role() helpers used
 *     by index.php to gate every sensitive action.
 *  3. Provides a simple, file-based login rate limiter (no extra DB table
 *     needed) to slow down brute-force attempts.
 *  4. Provides a shared-secret check for server-to-server calls (cron jobs
 *     hitting cron-* / process-* endpoints) that must work WITHOUT a human
 *     login, without opening those endpoints to the public internet.
 *  5. Provides a small helper to validate uploaded file extensions/size.
 *
 * IMPORTANT: include this file as early as possible in api/index.php, right
 * after $CFG is defined and before any service branching.
 */

// ---------------------------------------------------------------------------
// 1) Session hardening
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
  $isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
    (($_SERVER['SERVER_PORT'] ?? '') === '443')
  );
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  ini_set('session.use_strict_mode', '1');
}

// ---------------------------------------------------------------------------
// 2) Auth helpers
// ---------------------------------------------------------------------------

/** Returns the logged-in user array from the session, or null. */
function current_user() {
  return (!empty($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
}

/** Stops the request with 401 unless a user is logged in. Returns the user array. */
function require_auth() {
  $u = current_user();
  if (!$u) {
    respond(null, ['message' => 'يتطلب تسجيل الدخول', 'code' => 'unauthenticated'], 401);
  }
  return $u;
}

/**
 * Stops the request with 403 unless the logged-in user's role is in $roles.
 * Always requires login first (calls require_auth()).
 * @param string|string[] $roles
 */
function require_role($roles) {
  $u = require_auth();
  $roles = is_array($roles) ? $roles : [$roles];
  $role = strtolower((string)($u['role'] ?? ''));
  if (!in_array($role, array_map('strtolower', $roles), true)) {
    respond(null, ['message' => 'لا تملك صلاحية تنفيذ هذا الإجراء', 'code' => 'forbidden'], 403);
  }
  return $u;
}

/**
 * Allows a request through WITHOUT a human login only if it presents the
 * correct cron/server secret (via header X-Cron-Secret or ?cron_secret=).
 * Configure the real secret via the CRON_SECRET environment variable (or
 * define CRON_SECRET in api/config.php). Falls back to require_auth() if the
 * secret does not match, so a logged-in staff member can still trigger the
 * same action manually from the app.
 */
function allow_cron_or_auth() {
  $configured = getenv('CRON_SECRET') ?: (defined('CRON_SECRET') ? CRON_SECRET : '');
  if ($configured !== '') {
    $provided = $_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['cron_secret'] ?? $_POST['cron_secret'] ?? '');
    if (is_string($provided) && $provided !== '' && hash_equals($configured, $provided)) {
      return null; // authenticated as a trusted cron caller
    }
  }
  return require_auth();
}

// ---------------------------------------------------------------------------
// 3) Simple file-based login rate limiter
// ---------------------------------------------------------------------------
/**
 * Throttles login attempts per IP+email combo. Call BEFORE checking the
 * password. Blocks with 429 if too many recent failed attempts.
 * Call login_rl_record_failure() on failed attempts and
 * login_rl_clear() on success.
 */
function login_rl_dir() {
  $dir = sys_get_temp_dir() . '/promo_suite_rl';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  return $dir;
}
function login_rl_key($email) {
  $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  return md5(strtolower(trim($email)) . '|' . $ip);
}
function login_rl_check($email, $maxAttempts = 8, $windowSeconds = 300) {
  $file = login_rl_dir() . '/' . login_rl_key($email) . '.json';
  if (!is_file($file)) return;
  $data = json_decode((string)@file_get_contents($file), true);
  if (!is_array($data)) return;
  $recent = array_filter($data['attempts'] ?? [], function($ts) use ($windowSeconds) {
    return $ts > (time() - $windowSeconds);
  });
  if (count($recent) >= $maxAttempts) {
    respond(null, ['message' => 'محاولات دخول كثيرة جدًا، حاول لاحقًا', 'code' => 'rate_limited'], 429);
  }
}
function login_rl_record_failure($email) {
  $file = login_rl_dir() . '/' . login_rl_key($email) . '.json';
  $data = ['attempts' => []];
  if (is_file($file)) {
    $decoded = json_decode((string)@file_get_contents($file), true);
    if (is_array($decoded)) $data = $decoded;
  }
  $data['attempts'][] = time();
  // Keep only the last 50 timestamps to bound file size
  $data['attempts'] = array_slice($data['attempts'], -50);
  @file_put_contents($file, json_encode($data));
}
function login_rl_clear($email) {
  $file = login_rl_dir() . '/' . login_rl_key($email) . '.json';
  @unlink($file);
}

// ---------------------------------------------------------------------------
// 5) Multi-tenant (SaaS) isolation helpers
// ---------------------------------------------------------------------------
/**
 * Platform-level tables belong to the SaaS operator, not to any single
 * tenant/agency. Regular tenant users must never read or write these via the
 * generic CRUD endpoint — only a platform_admin may.
 */
function tenant_platform_only_tables() {
  return ['tenants', 'subscription_plans', 'tenant_subscriptions', 'platform_admin_audit_log'];
}

/** The tenant_id of the logged-in user, or null (e.g. for a platform_admin). */
function tenant_current_id() {
  $u = current_user();
  return $u['tenant_id'] ?? null;
}

/** True if the logged-in user is the SaaS operator (sees across all tenants). */
function tenant_is_platform_admin() {
  $u = current_user();
  return $u && strtolower((string)($u['role'] ?? '')) === 'platform_admin';
}

/**
 * Call right after $cols (the table's column list) is known, for every
 * generic CRUD action. Stops the request if a regular tenant user tries to
 * touch a platform-only table, or if a tenant user's session has no
 * tenant_id (a misconfigured account — fail closed rather than leak data).
 * Returns the tenant_id to scope this request by, or null if the caller is
 * a platform_admin (no automatic scoping — full visibility by design).
 */
function tenant_guard($table, array $cols) {
  if (tenant_is_platform_admin()) return null;
  if (in_array($table, tenant_platform_only_tables(), true)) {
    respond(null, ['message' => 'هذا الجدول مخصص لإدارة المنصة فقط', 'code' => 'platform_only'], 403);
  }
  if (!isset($cols['tenant_id'])) {
    // Table hasn't been migrated to multi-tenant yet (or is a shared lookup
    // table by design). Nothing to scope by; let it through unchanged.
    return null;
  }
  $tid = tenant_current_id();
  if (!$tid) {
    respond(null, ['message' => 'حساب غير مرتبط بوكالة — تواصل مع الدعم', 'code' => 'no_tenant'], 403);
  }
  return $tid;
}

/**
 * Appends a tenant_id condition to a hand-written SQL string, inserting it
 * before GROUP BY/ORDER BY/LIMIT if present. Returns [$sql, $params] with the
 * tenant id appended as the last bound parameter — pass $params by value in
 * the same order you already bind (positional `?` placeholders only).
 * If $tenantId is null (platform_admin, or table not tenant-scoped), the
 * SQL/params are returned unchanged.
 * $qualifier lets you scope by a joined alias, e.g. tenant_scope_sql($sql,$p,$tid,'o.tenant_id').
 */
function tenant_scope_sql($sql, array $params, $tenantId, $qualifier = 'tenant_id') {
  if ($tenantId === null) return [$sql, $params];
  $cond = (preg_match('/\bwhere\b/i', $sql)) ? " AND $qualifier = ?" : " WHERE $qualifier = ?";
  if (preg_match('/\b(GROUP BY|ORDER BY|LIMIT)\b/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
    $pos = $m[0][1];
    $sql = substr($sql, 0, $pos) . $cond . ' ' . substr($sql, $pos);
  } else {
    $sql .= $cond;
  }
  $params[] = $tenantId;
  return [$sql, $params];
}

// ---------------------------------------------------------------------------
// 6) Upload validation helper
// ---------------------------------------------------------------------------
/**
 * Validates an uploaded file's extension and size before it's saved.
 * Stops the request with 400 if invalid. Returns the sanitized, lowercase
 * extension (without the dot) on success.
 */
function validate_upload_or_die(array $file, array $allowedExt = null, $maxBytes = 15728640) {
  if ($allowedExt === null) {
    $allowedExt = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','csv','txt','mp3','ogg','opus','mp4','webm'];
  }
  if (($file['error'] ?? 1) !== 0) {
    respond(null, ['message' => 'فشل رفع الملف', 'code' => 'upload_error'], 400);
  }
  $size = (int)($file['size'] ?? 0);
  if ($size <= 0 || $size > $maxBytes) {
    respond(null, ['message' => 'حجم الملف غير مسموح', 'code' => 'upload_too_large'], 400);
  }
  $origName = (string)($file['name'] ?? '');
  $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
  $ext = preg_replace('/[^a-z0-9]/', '', $ext);
  if ($ext === '' || !in_array($ext, $allowedExt, true)) {
    respond(null, ['message' => 'نوع الملف غير مسموح', 'code' => 'upload_bad_type'], 400);
  }
  // Also block double-extension tricks like "x.php.jpg" by only trusting the
  // final extension (already done above) and never trusting $file['name'] for
  // the stored path — callers must generate their own safe filename.
  return $ext;
}
