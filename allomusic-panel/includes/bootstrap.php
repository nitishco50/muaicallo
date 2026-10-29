<?php
declare(strict_types=1);

/**
 * Shared bootstrap for the allomusic admin panel and mobile API.
 * Requires PHP 8.1+ with pdo_mysql.
 */

function allo_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = dirname(__DIR__) . '/config.php';
    if (!is_file($file)) {
        http_response_code(500);
        exit('Backend is not configured. Copy config.sample.php to config.php.');
    }
    $cfg = require $file;
    date_default_timezone_set($cfg['app']['timezone'] ?? 'UTC');
    if (empty($cfg['security']['debug'])) {
        ini_set('display_errors', '0');
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = allo_config()['db'];
    $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}";
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function base_url(): string
{
    return rtrim(allo_config()['app']['base_url'] ?? '', '/');
}

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = $_SERVER[$key] ?? '';
        if ($value !== '') {
            $ip = trim(explode(',', $value)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function uuid4(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/* ---------------------------------------------------------------- settings */

function setting(string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = db()->prepare('SELECT v FROM settings WHERE k = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    $cache[$key] = ($value === false || $value === null) ? $default : (string) $value;
    return $cache[$key];
}

function setting_set(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
    );
    $stmt->execute([$key, $value]);
}

function app_flags(): array
{
    $raw = setting('flags_json', '{}');
    $flags = json_decode((string) $raw, true);
    return is_array($flags) ? $flags : [];
}

function public_config(bool $includeFlags = true): array
{
    $config = [
        'app_name' => setting('app_name', 'allomusic'),
        'maintenance' => setting('maintenance', '0') === '1',
        'maintenance_message' => setting('maintenance_message', ''),
        'min_version_code' => (int) setting('min_version_code', '1'),
        'latest_version_code' => (int) setting('latest_version_code', '1'),
        'update_url' => setting('update_url', ''),
        'force_update' => setting('force_update', '0') === '1',
        'icon_set' => setting('icon_set', 'default'),
        'announcement' => setting('announcement', ''),
        'brand_logo' => setting('brand_logo', '') !== ''
            ? base_url() . '/' . ltrim((string) setting('brand_logo', ''), '/')
            : '',
    ];
    if ($includeFlags) {
        $config['flags'] = app_flags();
    }
    return $config;
}

/* ------------------------------------------------------------------- admin */

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $s = allo_config()['security'];
    session_name($s['session_name'] ?? 'allomusic_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function admin_current(): ?array
{
    admin_session_start();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $admin = false;
    if ($admin === false) {
        $stmt = db()->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch() ?: null;
    }
    return $admin ?: null;
}

function require_admin(): array
{
    $admin = admin_current();
    if ($admin === null) {
        header('Location: login.php');
        exit;
    }
    return $admin;
}

function csrf_token(): string
{
    admin_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token(24);
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(419);
        exit('Invalid CSRF token. Reload the page and try again.');
    }
}

function flash(?string $message = null): ?string
{
    admin_session_start();
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $value;
}

function audit(string $action, string $target = '', string $meta = ''): void
{
    $admin = admin_current();
    $stmt = db()->prepare(
        'INSERT INTO audit_log (admin_id, action, target, meta, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$admin['id'] ?? null, $action, $target, $meta, client_ip(), now()]);
}

/* --------------------------------------------------------------------- api */

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_ok(mixed $data = null): never
{
    json_out(['ok' => true, 'data' => $data]);
}

function api_err(string $code, string $message, int $status = 400): never
{
    json_out(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function body_str(array $body, string $key, string $default = ''): string
{
    $value = $body[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header !== '' && preg_match('/Bearer\s+([A-Za-z0-9._-]+)/i', $header, $m)) {
        return $m[1];
    }
    $alt = $_SERVER['HTTP_X_ALLO_TOKEN'] ?? '';
    if ($alt !== '') {
        return $alt;
    }
    $query = $_GET['token'] ?? '';
    return is_string($query) && $query !== '' ? $query : null;
}

function api_user(): ?array
{
    $token = bearer_token();
    if ($token === null || $token === '') {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT t.id AS token_id, t.expires_at, u.*
           FROM auth_tokens t
           JOIN users u ON u.id = t.user_id
          WHERE t.token_hash = ? AND t.revoked = 0
          LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return null;
    }
    if (($row['status'] ?? '') === 'banned') {
        return null;
    }
    $upd = db()->prepare('UPDATE auth_tokens SET last_used_at = ? WHERE id = ?');
    $upd->execute([now(), $row['token_id']]);
    $seen = db()->prepare('UPDATE users SET last_seen_at = ?, last_ip = ? WHERE id = ?');
    $seen->execute([now(), client_ip(), $row['id']]);
    unset($row['token_id'], $row['expires_at']);
    return $row;
}

function require_api_user(): array
{
    $user = api_user();
    if ($user === null) {
        api_err('unauthorized', 'Invalid or expired token. Please sign in again.', 401);
    }
    return $user;
}

function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 190;
}

function too_many_attempts(string $email, string $ip): bool
{
    $s = allo_config()['security'];
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ok = 0 AND created_at > ? AND (email = ? OR ip = ?)'
    );
    $since = date('Y-m-d H:i:s', time() - ((int) ($s['login_window_minutes'] ?? 15) * 60));
    $stmt->execute([$since, $email, $ip]);
    return (int) $stmt->fetchColumn() >= (int) ($s['max_login_attempts'] ?? 5);
}

function record_attempt(string $email, string $ip, bool $ok): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (email, ip, ok, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$email, $ip, $ok ? 1 : 0, now()]);
}
