<?php
// public/api/_bootstrap.php
// Shared helpers: load .env, CORS, JSON output, error trapping

ini_set('display_errors', '0');
error_reporting(E_ALL);

// --- Load .env from project root ---
$root = dirname(__DIR__, 2);
$envFile = $root . '/.env';
if (is_readable($envFile)) {
  foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $eq = strpos($line, '=');
    if ($eq === false) continue;
    $k = trim(substr($line, 0, $eq));
    $v = trim(substr($line, $eq + 1));
    $_ENV[$k] = $v;
  }
}

function envv(string $key, ?string $default = null): ?string {
  return $_ENV[$key] ?? $default;
}

function allow_cors(): void {
  $origin = envv('ALLOWED_ORIGIN', '*');
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
}

function json_out($data, int $status = 200): void {
  allow_cors();
  header('Content-Type: application/json; charset=utf-8');
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

set_error_handler(function ($severity, $message, $file, $line) {
  json_out(['error' => 'PHP Error', 'detail' => compact('severity','message','file','line')], 500);
});
set_exception_handler(function ($ex) {
  json_out(['error' => 'PHP Exception', 'detail' => $ex->getMessage()], 500);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  allow_cors();
  http_response_code(204);
  exit;
}

function json_body(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function storage_path(string $path = ''): string {
  $root = dirname(__DIR__, 2);
  $full = $root . '/storage' . ($path ? '/' . $path : '');
  if (!is_dir(dirname($full))) @mkdir(dirname($full), 0777, true);
  return $full;
}

function log_line(string $msg): void {
  $log = storage_path('logs/requests.log');
  if (!is_dir(dirname($log))) @mkdir(dirname($log), 0777, true);
  @file_put_contents($log, '['.date('c')."] ".$msg."\n", FILE_APPEND);
}
