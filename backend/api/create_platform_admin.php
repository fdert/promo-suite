<?php
/**
 * create_platform_admin.php — creates (or promotes) the SaaS operator account.
 *
 * A platform_admin is NOT tied to any single tenant/agency — they can see
 * and manage every tenant's data and subscription. There is no public signup
 * for this role on purpose; you create it once, by hand, from SSH.
 *
 * Usage:
 *   php create_platform_admin.php you@example.com "a-strong-password" "Your Name"
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;
$fullName = $argv[3] ?? '';
if (!$email || !$password) {
  echo "Usage: php create_platform_admin.php <email> <password> [full name]\n";
  exit(1);
}
if (strlen($password) < 10) {
  echo "Please use a password of at least 10 characters for this account.\n";
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

$pdo = new PDO("mysql:host={$CFG['host']};dbname={$CFG['name']};charset=utf8mb4", $CFG['user'], $CFG['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
$existing->execute([':e' => $email]);
$id = $existing->fetchColumn();

if ($id) {
  $pdo->prepare("UPDATE users SET role = 'platform_admin', tenant_id = NULL, password_hash = :h WHERE id = :id")
      ->execute([':h' => $hash, ':id' => $id]);
  echo "Existing user $email promoted to platform_admin.\n";
} else {
  $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'id'")->fetch();
  $auto = $cols && stripos((string)($cols['Extra'] ?? ''), 'auto_increment') !== false;
  if ($auto) {
    $pdo->prepare("INSERT INTO users (email, password_hash, full_name, role, tenant_id, created_at) VALUES (:e, :h, :n, 'platform_admin', NULL, NOW())")
        ->execute([':e' => $email, ':h' => $hash, ':n' => $fullName]);
  } else {
    $newId = bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO users (id, email, password_hash, full_name, role, tenant_id, created_at) VALUES (:i, :e, :h, :n, 'platform_admin', NULL, NOW())")
        ->execute([':i' => $newId, ':e' => $email, ':h' => $hash, ':n' => $fullName]);
  }
  echo "Created new platform_admin: $email\n";
}
