<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

/*
 * allomusic mobile API.
 *
 * Base: https://allo.instadl.site/api/index.php
 * Every call uses ?action=<name>. JSON in, JSON out.
 * Auth: Authorization: Bearer <token>  (X-Allo-Token also accepted).
 */

$action = (string) ($_GET['action'] ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$pdo = db();

date_default_timezone_set(allo_config()['app']['timezone'] ?? 'UTC');

if ($action === '' || $action === 'health') {
    api_ok([
        'status' => 'ok',
        'time' => now(),
        'version' => '1.0.0',
    ]);
}

if ($action === 'config') {
    $config = public_config();
    $config['icons'] = $pdo->query('SELECT icon_key, name FROM app_icons ORDER BY sort, id')->fetchAll();
    api_ok($config);
}

$config = public_config(false);
if (!empty($config['maintenance'])) {
    api_err('maintenance', $config['maintenance_message'] ?: 'allomusic is under maintenance.', 503);
}

/* ------------------------------------------------------------------ helpers */

/** Keeps the first-run profile bounded and shaped; the app sends free-form JSON. */
function sanitize_preferences(mixed $raw): array
{
    if (!is_object($raw) && !is_array($raw)) {
        return [];
    }
    $raw = (array) $raw;
    $clean = [];

    foreach (['regions', 'artists'] as $key) {
        $values = $raw[$key] ?? [];
        if (!is_array($values)) {
            $values = [];
        }
        $out = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = substr(trim($value), 0, 80);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
            if (count($out) >= 50) {
                break;
            }
        }
        $clean[$key] = $out;
    }

    $songs = [];
    $rawSongs = $raw['songs'] ?? [];
    if (!is_array($rawSongs)) {
        $rawSongs = [];
    }
    foreach ($rawSongs as $song) {
        if (!is_array($song)) {
            continue;
        }
        $title = substr(trim((string) ($song['title'] ?? '')), 0, 160);
        $artist = substr(trim((string) ($song['artist'] ?? '')), 0, 120);
        $region = substr(trim((string) ($song['region'] ?? '')), 0, 40);
        if ($title !== '') {
            $songs[] = ['title' => $title, 'artist' => $artist, 'region' => $region];
        }
        if (count($songs) >= 50) {
            break;
        }
    }
    $clean['songs'] = $songs;

    $gender = (string) ($raw['gender'] ?? '');
    $clean['gender'] = in_array($gender, ['male', 'female', 'other', 'private'], true) ? $gender : '';
    $time = (string) ($raw['listening_time'] ?? '');
    $clean['listening_time'] = in_array($time, ['morning', 'afternoon', 'evening', 'night'], true) ? $time : '';
    $age = (int) ($raw['age'] ?? 0);
    $clean['age'] = ($age >= 13 && $age <= 100) ? $age : 0;
    $clean['notifications'] = !empty($raw['notifications']);

    return $clean;
}

function store_preferences(int $userId, array $prefs): void
{
    $json = json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = db()->prepare(
        'INSERT INTO user_data (user_id, ns, json, updated_at) VALUES (?, "preferences", ?, ?)
         ON DUPLICATE KEY UPDATE json = VALUES(json), updated_at = VALUES(updated_at)'
    );
    $stmt->execute([$userId, $json, now()]);
}

function issue_token(int $userId, string $deviceId, string $deviceName, string $appVersion): array
{
    $token = random_token(32);
    $days = (int) (allo_config()['security']['token_ttl_days'] ?? 90);
    $expires = date('Y-m-d H:i:s', time() + $days * 86400);
    $stmt = db()->prepare(
        'INSERT INTO auth_tokens
            (user_id, token_hash, device_id, device_name, app_version, user_agent, ip, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        hash('sha256', $token),
        substr($deviceId, 0, 128),
        substr($deviceName, 0, 128),
        substr($appVersion, 0, 32),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        client_ip(),
        now(),
        $expires,
    ]);
    return ['token' => $token, 'expires_at' => $expires];
}

function track_device(int $userId, array $body): void
{
    $deviceId = body_str($body, 'device_id');
    if ($deviceId === '') {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO devices (user_id, device_id, model, sdk, app_version, last_seen, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE model = VALUES(model), sdk = VALUES(sdk),
             app_version = VALUES(app_version), last_seen = VALUES(last_seen)'
    );
    $stmt->execute([
        $userId,
        substr($deviceId, 0, 128),
        substr(body_str($body, 'device_name'), 0, 128),
        substr(body_str($body, 'sdk'), 0, 16),
        substr(body_str($body, 'app_version'), 0, 32),
        now(),
        now(),
    ]);
}

function public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'uuid' => $user['uuid'],
        'email' => $user['email'],
        'name' => $user['name'],
        'status' => $user['status'],
        'plan' => $user['plan'],
        'email_verified' => (int) $user['email_verified'] === 1,
        'created_at' => $user['created_at'],
    ];
}

/* ------------------------------------------------------------------- auth */

if ($action === 'register') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $body = body_json();
    $email = strtolower(body_str($body, 'email'));
    $password = body_str($body, 'password');
    $name = body_str($body, 'name');

    if (!valid_email($email)) {
        api_err('email', 'Enter a valid email address.');
    }
    if (strlen($password) < 8) {
        api_err('password', 'Password must be at least 8 characters.');
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        api_err('exists', 'An account with this email already exists.', 409);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (uuid, email, password_hash, name, status, plan, created_at, last_seen_at, last_ip)
             VALUES (?, ?, ?, ?, "active", "free", ?, ?, ?)'
        );
        $stmt->execute([
            uuid4(),
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $name !== '' ? $name : explode('@', $email)[0],
            now(),
            now(),
            client_ip(),
        ]);
    } catch (PDOException $e) {
        api_err('exists', 'An account with this email already exists.', 409);
    }

    $userId = (int) $pdo->lastInsertId();
    track_device($userId, $body);
    if (isset($body['preferences']) && (is_array($body['preferences']) || is_object($body['preferences']))) {
        store_preferences($userId, sanitize_preferences($body['preferences']));
        try {
            signals_recompute($userId);
        } catch (Throwable $e) {
            error_log('allomusic register signals: ' . $e->getMessage());
        }
    }
    $token = issue_token($userId, body_str($body, 'device_id'), body_str($body, 'device_name'), body_str($body, 'app_version'));

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    audit('api.register', (string) $userId);
    api_ok(['token' => $token['token'], 'expires_at' => $token['expires_at'], 'user' => public_user($user), 'config' => public_config()]);
}

if ($action === 'login') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $body = body_json();
    $email = strtolower(body_str($body, 'email'));
    $password = body_str($body, 'password');
    $ip = client_ip();

    if ($email === '' || $password === '') {
        api_err('fields', 'Email and password are required.');
    }
    if (too_many_attempts($email, $ip)) {
        api_err('rate_limited', 'Too many attempts. Try again in a few minutes.', 429);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_attempt($email, $ip, false);
        api_err('credentials', 'Wrong email or password.', 401);
    }
    if ($user['status'] === 'banned') {
        api_err('banned', 'This account has been suspended.', 403);
    }

    record_attempt($email, $ip, true);
    track_device((int) $user['id'], $body);
    $token = issue_token((int) $user['id'], body_str($body, 'device_id'), body_str($body, 'device_name'), body_str($body, 'app_version'));

    $seen = $pdo->prepare('UPDATE users SET last_seen_at = ?, last_ip = ? WHERE id = ?');
    $seen->execute([now(), $ip, $user['id']]);

    api_ok(['token' => $token['token'], 'expires_at' => $token['expires_at'], 'user' => public_user($user), 'config' => public_config()]);
}

if ($action === 'logout') {
    $user = api_user();
    if ($user === null) {
        api_ok(['logged_out' => true]);
    }
    $token = bearer_token();
    if ($token !== null) {
        $pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);
    }
    api_ok(['logged_out' => true]);
}

if ($action === 'me') {
    $user = require_api_user();
    $stmt = $pdo->prepare(
        'SELECT ns, LENGTH(json) AS bytes, updated_at FROM user_data WHERE user_id = ? ORDER BY ns'
    );
    $stmt->execute([$user['id']]);
    api_ok([
        'user' => public_user($user),
        'data' => $stmt->fetchAll(),
    ]);
}

if ($action === 'password') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $body = body_json();
    $current = body_str($body, 'current');
    $next = body_str($body, 'next');
    if (!password_verify($current, $user['password_hash'])) {
        api_err('current', 'Current password is wrong.');
    }
    if (strlen($next) < 8) {
        api_err('next', 'New password must be at least 8 characters.');
    }
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($next, PASSWORD_DEFAULT), $user['id']]);
    $pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE user_id = ? AND token_hash <> ?')
        ->execute([$user['id'], hash('sha256', (string) bearer_token())]);
    audit('api.password', (string) $user['id']);
    api_ok(['changed' => true]);
}

if ($action === 'delete_account') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
    audit('api.delete_account', (string) $user['id']);
    api_ok(['deleted' => true]);
}

if ($action === 'device') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    track_device((int) $user['id'], body_json());
    api_ok(['tracked' => true]);
}

/* ------------------------------------------------------------------- data */

if ($action === 'data' && $method === 'GET') {
    $user = require_api_user();
    $ns = (string) ($_GET['ns'] ?? '');
    if ($ns === '') {
        $stmt = $pdo->prepare('SELECT ns, json, updated_at FROM user_data WHERE user_id = ? ORDER BY ns');
        $stmt->execute([$user['id']]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['ns' => $row['ns'], 'json' => $row['json'], 'updated_at' => $row['updated_at']];
        }
        api_ok(['documents' => $out]);
    }
    $stmt = $pdo->prepare('SELECT json, updated_at FROM user_data WHERE user_id = ? AND ns = ? LIMIT 1');
    $stmt->execute([$user['id'], $ns]);
    $row = $stmt->fetch();
    api_ok([
        'ns' => $ns,
        'json' => $row ? $row['json'] : null,
        'updated_at' => $row ? $row['updated_at'] : null,
    ]);
}

if ($action === 'data' && $method === 'POST') {
    $user = require_api_user();
    $body = body_json();
    $ns = body_str($body, 'ns');
    $json = $body['json'] ?? null;
    if (is_array($json) || is_object($json)) {
        $json = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!is_string($json)) {
        api_err('json', 'Send a JSON string or object in "json".');
    }
    if ($ns === '' || !preg_match('/^[a-z0-9_.-]{1,64}$/i', $ns)) {
        api_err('ns', 'Namespace must be 1-64 letters, numbers, dot, dash or underscore.');
    }
    if (strlen($json) > 1024 * 1024) {
        api_err('size', 'Document is larger than 1 MB.', 413);
    }
    if (json_decode($json, true) === null && trim($json) !== 'null') {
        api_err('json', 'That is not valid JSON.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO user_data (user_id, ns, json, updated_at) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE json = VALUES(json), updated_at = VALUES(updated_at)'
    );
    $stmt->execute([$user['id'], $ns, $json, now()]);
    api_ok(['saved' => true, 'ns' => $ns, 'updated_at' => now()]);
}

if ($action === 'data_delete') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $body = body_json();
    $ns = body_str($body, 'ns');
    $pdo->prepare('DELETE FROM user_data WHERE user_id = ? AND ns = ?')->execute([$user['id'], $ns]);
    api_ok(['deleted' => true]);
}

/* ---------------------------------------------------------- notifications */

if ($action === 'notifications') {
    $user = require_api_user();
    $stmt = $pdo->prepare(
        'SELECT id, title, body, created_at FROM notifications
          WHERE user_id = ? OR user_id IS NULL
          ORDER BY id DESC LIMIT 50'
    );
    $stmt->execute([$user['id']]);
    api_ok(['notifications' => $stmt->fetchAll()]);
}



/* ------------------------------------------------------------ preferences */

if ($action === 'preferences' && $method === 'GET') {
    $user = require_api_user();
    $stmt = $pdo->prepare('SELECT json, updated_at FROM user_data WHERE user_id = ? AND ns = "preferences" LIMIT 1');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    api_ok([
        'preferences' => $row ? json_decode((string) $row['json'], true) : null,
        'updated_at' => $row ? $row['updated_at'] : null,
    ]);
}

if ($action === 'preferences' && $method === 'POST') {
    $user = require_api_user();
    $body = body_json();
    $prefs = sanitize_preferences($body['preferences'] ?? []);
    store_preferences((int) $user['id'], $prefs);
    try {
        signals_recompute((int) $user['id']);
    } catch (Throwable $e) {
        error_log('allomusic preferences signals: ' . $e->getMessage());
    }
    api_ok(['saved' => true, 'preferences' => $prefs]);
}

/* ----------------------------------------------------------------- events */

if ($action === 'event') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $body = body_json();
    $events = $body['events'] ?? null;
    if (!is_array($events) || $events === []) {
        api_err('events', 'Send a non-empty "events" array.');
    }
    if (count($events) > 100) {
        $events = array_slice($events, 0, 100);
    }
    $allowed = [
        'play', 'complete', 'skip', 'like', 'dislike', 'unlike',
        'search', 'download', 'share', 'playlist_add', 'open', 'session',
    ];
    $insert = $pdo->prepare(
        'INSERT INTO events
            (user_id, type, track_id, title, artist, album, genre, source,
             duration_ms, listen_ms, extra, client_ts, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $accepted = 0;
    $pdo->beginTransaction();
    try {
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $type = substr((string) ($event['type'] ?? ''), 0, 32);
            if (!in_array($type, $allowed, true)) {
                continue;
            }
            $extra = $event['extra'] ?? null;
            if (is_array($extra) || is_object($extra)) {
                $extra = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $insert->execute([
                $user['id'],
                $type,
                substr((string) ($event['track_id'] ?? ''), 0, 64),
                substr((string) ($event['title'] ?? ''), 0, 255),
                substr((string) ($event['artist'] ?? ''), 0, 255),
                substr((string) ($event['album'] ?? ''), 0, 255),
                substr((string) ($event['genre'] ?? ''), 0, 128),
                substr((string) ($event['source'] ?? ''), 0, 32),
                max(0, min(86400000, (int) ($event['duration_ms'] ?? 0))),
                max(0, min(86400000, (int) ($event['listen_ms'] ?? 0))),
                is_string($extra) ? substr($extra, 0, 4000) : null,
                max(0, (int) ($event['ts'] ?? 0)),
                now(),
            ]);
            $accepted++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        api_err('event_write', 'Could not store events.', 500);
    }
    $signals = 0;
    if ($accepted > 0) {
        try {
            $signals = signals_recompute((int) $user['id']);
        } catch (Throwable $e) {
            error_log('allomusic signals: ' . $e->getMessage());
        }
    }
    api_ok(['accepted' => $accepted, 'signals' => $signals]);
}

if ($action === 'signals') {
    $user = require_api_user();
    $rows = signals_fetch((int) $user['id']);
    api_ok(['signals' => $rows, 'count' => count($rows)]);
}

api_err('unknown_action', 'Unknown action: ' . $action, 404);
