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
        'SELECT id, title, body, created_at, read_at FROM notifications
          WHERE user_id = ? OR user_id IS NULL
          ORDER BY id DESC LIMIT 50'
    );
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
    $unread = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL'
    );
    $unread->execute([$user['id']]);
    api_ok([
        'notifications' => $rows,
        'unread' => (int) $unread->fetchColumn(),
    ]);
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
            (user_id, type, track_id, title, artist, album, art_url, genre, source,
             duration_ms, listen_ms, extra, client_ts, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                substr((string) ($event['art_url'] ?? $event['thumbnail_url'] ?? ''), 0, 512),
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


/* ------------------------------------------------- history and profile */

if ($action === 'history') {
    $user = require_api_user();
    $days = community_clamp_int($_GET['days'] ?? 30, 1, 180, 30);
    $limit = community_clamp_int($_GET['limit'] ?? 200, 1, 500, 200);
    api_ok(community_history((int) $user['id'], $days, $limit));
}

if ($action === 'history_clear') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $days = community_clamp_int(body_json()['days'] ?? 30, 1, 180, 30);
    $window = "created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    $stmt = $pdo->prepare(
        "DELETE FROM events WHERE user_id = ? AND type IN ('play', 'complete', 'skip', 'like', 'dislike', 'unlike', 'search') AND $window"
    );
    $stmt->execute([$user['id']]);
    $deleted = $stmt->rowCount();
    try {
        signals_recompute((int) $user['id']);
    } catch (Throwable $e) {
        error_log('allomusic history_clear signals: ' . $e->getMessage());
    }
    api_ok(['deleted' => $deleted, 'days' => $days]);
}

if ($action === 'profile_stats') {
    $user = require_api_user();
    $history = community_history((int) $user['id'], 30, 1);
    unset($history['plays']);
    $pdo = db();
    $count = static function (string $sql, array $args) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    };
    $history['stats']['likes_given'] = $count(
        'SELECT COUNT(*) FROM song_reactions WHERE user_id = ? AND value = 1',
        [(int) $user['id']]
    );
    $history['stats']['dislikes_given'] = $count(
        'SELECT COUNT(*) FROM song_reactions WHERE user_id = ? AND value = -1',
        [(int) $user['id']]
    );
    $history['stats']['bookmarks'] = $count(
        'SELECT COUNT(*) FROM song_bookmarks WHERE user_id = ?',
        [(int) $user['id']]
    );
    $history['stats']['comments'] = $count(
        "SELECT COUNT(*) FROM song_comments WHERE user_id = ? AND status = 'visible'",
        [(int) $user['id']]
    );
    $history['stats']['comment_likes_received'] = $count(
        'SELECT COUNT(*) FROM comment_likes cl JOIN song_comments c ON c.id = cl.comment_id WHERE c.user_id = ?',
        [(int) $user['id']]
    );
    $history['stats']['followers'] = $count(
        'SELECT COUNT(*) FROM user_follows WHERE following_id = ?',
        [(int) $user['id']]
    );
    $history['stats']['following'] = $count(
        'SELECT COUNT(*) FROM user_follows WHERE follower_id = ?',
        [(int) $user['id']]
    );
    $history['stats']['artists_followed'] = $count(
        'SELECT COUNT(*) FROM artist_follows WHERE user_id = ?',
        [(int) $user['id']]
    );
    $history['user'] = [
        'id' => (int) $user['id'],
        'uuid' => $user['uuid'],
        'name' => $user['name'],
        'plan' => $user['plan'],
        'created_at' => $user['created_at'],
    ];
    api_ok($history);
}

/* --------------------------------------------------------- reactions */

if ($action === 'reaction') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $body = body_json();
    $songKey = community_key($body['song_key'] ?? '');
    if ($songKey === '') {
        api_err('song_key', 'A song_key is required.');
    }
    $actionName = body_str($body, 'action', 'like');
    if (!in_array($actionName, ['like', 'dislike', 'clear'], true)) {
        api_err('action', 'action must be like, dislike or clear.');
    }
    if ($actionName === 'clear') {
        $stmt = $pdo->prepare('DELETE FROM song_reactions WHERE user_id = ? AND song_key = ?');
        $stmt->execute([$user['id'], $songKey]);
    } else {
        $value = $actionName === 'like' ? 1 : -1;
        $meta = community_song_meta($songKey, $body);
        $stmt = $pdo->prepare(
            'INSERT INTO song_reactions
                (user_id, song_key, value, title, artist, art_url, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), title = VALUES(title),
                artist = VALUES(artist), art_url = VALUES(art_url), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            $user['id'], $songKey, $value, $meta['title'], $meta['artist'], $meta['art_url'], now(), now(),
        ]);
        try {
            signals_recompute((int) $user['id']);
        } catch (Throwable $e) {
            error_log('allomusic reaction signals: ' . $e->getMessage());
        }
    }
    api_ok(community_song((int) $user['id'], $songKey, $body));
}

if ($action === 'reactions') {
    $user = require_api_user();
    $value = (string) ($_GET['value'] ?? 'like');
    if (!in_array($value, ['like', 'dislike', 'all'], true)) {
        $value = 'like';
    }
    $limit = community_clamp_int($_GET['limit'] ?? 100, 1, 300, 100);
    $where = $value === 'all' ? '' : ' AND value = ' . ($value === 'like' ? 1 : -1);
    $stmt = $pdo->prepare(
        "SELECT song_key, value, title, artist, art_url, created_at, updated_at
           FROM song_reactions WHERE user_id = ?$where ORDER BY updated_at DESC LIMIT $limit"
    );
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['my_reaction'] = (int) $row['value'] === 1 ? 'like' : 'dislike';
        $row['value'] = (int) $row['value'];
        $row += community_counts((string) $row['song_key']);
    }
    unset($row);
    api_ok(['reactions' => $rows]);
}

/* ---------------------------------------------------------- bookmarks */

if ($action === 'bookmark') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $body = body_json();
    $songKey = community_key($body['song_key'] ?? '');
    if ($songKey === '') {
        api_err('song_key', 'A song_key is required.');
    }
    $actionName = body_str($body, 'action', 'toggle');
    if (!in_array($actionName, ['add', 'remove', 'toggle'], true)) {
        api_err('action', 'action must be add, remove or toggle.');
    }
    $stmt = $pdo->prepare('SELECT id FROM song_bookmarks WHERE user_id = ? AND song_key = ? LIMIT 1');
    $stmt->execute([$user['id'], $songKey]);
    $exists = (bool) $stmt->fetchColumn();
    $want = $actionName === 'add' ? true : ($actionName === 'remove' ? false : !$exists);
    if ($want && !$exists) {
        $meta = community_song_meta($songKey, $body);
        $pdo->prepare(
            'INSERT INTO song_bookmarks (user_id, song_key, title, artist, art_url, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$user['id'], $songKey, $meta['title'], $meta['artist'], $meta['art_url'], now()]);
    } elseif (!$want && $exists) {
        $pdo->prepare('DELETE FROM song_bookmarks WHERE user_id = ? AND song_key = ?')
            ->execute([$user['id'], $songKey]);
    }
    api_ok(community_song((int) $user['id'], $songKey, $body));
}

if ($action === 'bookmarks') {
    $user = require_api_user();
    $limit = community_clamp_int($_GET['limit'] ?? 100, 1, 300, 100);
    $stmt = $pdo->prepare(
        "SELECT song_key, title, artist, art_url, created_at
           FROM song_bookmarks WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit"
    );
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['bookmarked'] = true;
        $row += community_counts((string) $row['song_key']);
    }
    unset($row);
    api_ok(['bookmarks' => $rows]);
}

/* ----------------------------------------------------------- comments */

if ($action === 'song') {
    $user = require_api_user();
    $songKey = community_key($_GET['song_key'] ?? '');
    if ($songKey === '') {
        api_err('song_key', 'A song_key is required.');
    }
    api_ok(['song' => community_song((int) $user['id'], $songKey)]);
}

if ($action === 'comments' && $method === 'GET') {
    $user = require_api_user();
    $songKey = community_key($_GET['song_key'] ?? '');
    if ($songKey === '') {
        api_err('song_key', 'A song_key is required.');
    }
    $limit = community_clamp_int($_GET['limit'] ?? 50, 1, 100, 50);
    $offset = community_clamp_int($_GET['offset'] ?? 0, 0, 10000, 0);
    $stmt = $pdo->prepare(
        "SELECT c.id, c.song_key, c.parent_id, c.body, c.created_at, c.user_id, u.name AS user_name
           FROM song_comments c JOIN users u ON u.id = c.user_id
          WHERE c.song_key = ? AND c.status = 'visible'
          ORDER BY c.id DESC LIMIT $limit OFFSET $offset"
    );
    $stmt->execute([$songKey]);
    $rows = community_comment_rows((int) $user['id'], $stmt->fetchAll());
    $count = $pdo->prepare(
        "SELECT COUNT(*) FROM song_comments WHERE song_key = ? AND status = 'visible'"
    );
    $count->execute([$songKey]);
    api_ok([
        'comments' => $rows,
        'count' => (int) $count->fetchColumn(),
        'song' => community_song((int) $user['id'], $songKey),
    ]);
}

if ($action === 'comments' && $method === 'POST') {
    $user = require_api_user();
    $body = body_json();
    $songKey = community_key($body['song_key'] ?? '');
    $text = trim((string) ($body['body'] ?? $body['comment'] ?? ''));
    if ($songKey === '') {
        api_err('song_key', 'A song_key is required.');
    }
    if ($text === '' || mb_strlen($text) > 1000) {
        api_err('body', 'A comment must be 1 to 1000 characters.');
    }
    $recent = $pdo->prepare(
        'SELECT COUNT(*) FROM song_comments WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
    );
    $recent->execute([$user['id']]);
    if ((int) $recent->fetchColumn() >= 10) {
        api_err('rate_limited', 'You are commenting too quickly. Wait a minute.', 429);
    }
    $parentId = isset($body['parent_id']) && is_numeric($body['parent_id'])
        ? (int) $body['parent_id'] : null;
    if ($parentId !== null) {
        $parent = $pdo->prepare(
            "SELECT id, user_id FROM song_comments WHERE id = ? AND song_key = ? AND status = 'visible' LIMIT 1"
        );
        $parent->execute([$parentId, $songKey]);
        $parentRow = $parent->fetch();
        if (!$parentRow) {
            api_err('parent', 'That comment no longer exists.');
        }
    }
    $meta = community_song_meta($songKey, $body);
    $stmt = $pdo->prepare(
        'INSERT INTO song_comments (user_id, song_key, parent_id, body, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, "visible", ?, ?)'
    );
    $stmt->execute([$user['id'], $songKey, $parentId, $text, now(), now()]);
    $commentId = (int) $pdo->lastInsertId();

    $title = $meta['title'] !== '' ? $meta['title'] : 'a song';
    if ($parentId !== null && isset($parentRow) && (int) $parentRow['user_id'] !== (int) $user['id']) {
        community_notify((int) $parentRow['user_id'], 'New reply to your comment', $text);
    }
    $others = $pdo->prepare(
        "SELECT DISTINCT user_id FROM song_comments
          WHERE song_key = ? AND user_id <> ? AND status = 'visible' ORDER BY id DESC LIMIT 20"
    );
    $others->execute([$songKey, (int) $user['id']]);
    foreach ($others->fetchAll() as $other) {
        if ($parentId !== null && (int) $other['user_id'] === (int) $parentRow['user_id']) {
            continue;
        }
        community_notify((int) $other['user_id'], 'New comment on ' . $title, $text);
    }

    $stmt = $pdo->prepare(
        "SELECT c.id, c.song_key, c.parent_id, c.body, c.created_at, c.user_id, u.name AS user_name
           FROM song_comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?"
    );
    $stmt->execute([$commentId]);
    api_ok([
        'comment' => community_comment_rows((int) $user['id'], $stmt->fetchAll())[0] ?? null,
        'song' => community_song((int) $user['id'], $songKey, $body),
    ]);
}

if ($action === 'comment_delete') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $commentId = community_clamp_int(body_json()['id'] ?? 0, 0, PHP_INT_MAX, 0);
    $stmt = $pdo->prepare('DELETE FROM song_comments WHERE id = ? AND user_id = ?');
    $stmt->execute([$commentId, $user['id']]);
    if ($stmt->rowCount() === 0) {
        api_err('not_found', 'Comment not found.', 404);
    }
    api_ok(['deleted' => true]);
}

if ($action === 'comment_like') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $body = body_json();
    $commentId = community_clamp_int($body['id'] ?? 0, 0, PHP_INT_MAX, 0);
    $like = body_str($body, 'action', 'like') !== 'unlike';
    $stmt = $pdo->prepare(
        "SELECT id, user_id FROM song_comments WHERE id = ? AND status = 'visible' LIMIT 1"
    );
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch();
    if (!$comment) {
        api_err('not_found', 'Comment not found.', 404);
    }
    if ($like) {
        $pdo->prepare('INSERT IGNORE INTO comment_likes (comment_id, user_id, created_at) VALUES (?, ?, ?)')
            ->execute([$commentId, $user['id'], now()]);
        if ((int) $comment['user_id'] !== (int) $user['id']) {
            community_notify((int) $comment['user_id'], 'Someone liked your comment', '');
        }
    } else {
        $pdo->prepare('DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?')
            ->execute([$commentId, $user['id']]);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM comment_likes WHERE comment_id = ?');
    $stmt->execute([$commentId]);
    api_ok(['likes' => (int) $stmt->fetchColumn(), 'liked' => $like]);
}

if ($action === 'comment_report') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $body = body_json();
    $commentId = community_clamp_int($body['id'] ?? 0, 0, PHP_INT_MAX, 0);
    $reason = substr(trim(body_str($body, 'reason', '')), 0, 255);
    $pdo->prepare(
        'INSERT IGNORE INTO comment_reports (comment_id, user_id, reason, created_at) VALUES (?, ?, ?, ?)'
    )->execute([$commentId, $user['id'], $reason, now()]);
    api_ok(['reported' => true]);
}

if ($action === 'my_comments') {
    $user = require_api_user();
    $limit = community_clamp_int($_GET['limit'] ?? 100, 1, 300, 100);
    $stmt = $pdo->prepare(
        "SELECT c.id, c.song_key, c.parent_id, c.body, c.created_at, c.user_id, u.name AS user_name
           FROM song_comments c JOIN users u ON u.id = c.user_id
          WHERE c.user_id = ? AND c.status = 'visible'
          ORDER BY c.id DESC LIMIT $limit"
    );
    $stmt->execute([$user['id']]);
    api_ok(['comments' => community_comment_rows((int) $user['id'], $stmt->fetchAll())]);
}

/* ------------------------------------------------- community discovery */

if ($action === 'trending') {
    $user = require_api_user();
    $days = community_clamp_int($_GET['days'] ?? 7, 1, 90, 7);
    $limit = community_clamp_int($_GET['limit'] ?? 20, 1, 50, 20);
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    $sql = "SELECT song_key, MAX(NULLIF(title, '')) AS title, MAX(NULLIF(artist, '')) AS artist,
                   MAX(NULLIF(art_url, '')) AS art_url, SUM(weight) AS score
              FROM (
                SELECT song_key, title, artist, art_url, 3 AS weight FROM song_reactions WHERE updated_at >= ?
                UNION ALL
                SELECT song_key, title, artist, art_url, 2 AS weight FROM song_bookmarks WHERE created_at >= ?
                UNION ALL
                SELECT song_key, '', '', '' AS art_url, 4 AS weight FROM song_comments
                  WHERE status = 'visible' AND created_at >= ?
                UNION ALL
                SELECT track_id AS song_key, title, artist, art_url, 1 AS weight FROM events
                  WHERE type = 'play' AND track_id <> '' AND created_at >= ?
              ) AS activity
             GROUP BY song_key ORDER BY score DESC LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$since, $since, $since, $since]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = (string) $row['song_key'];
        $summary = community_song((int) $user['id'], $key, $row);
        $summary['score'] = (int) $row['score'];
        $rows[] = $summary;
    }
    api_ok(['days' => $days, 'songs' => $rows]);
}

if ($action === 'search_songs') {
    $user = require_api_user();
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        api_ok(['songs' => []]);
    }
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $limit = community_clamp_int($_GET['limit'] ?? 30, 1, 50, 30);
    $stmt = $pdo->prepare(
        "SELECT song_key, MAX(NULLIF(title, '')) AS title, MAX(NULLIF(artist, '')) AS artist,
                MAX(NULLIF(art_url, '')) AS art_url
           FROM (
             SELECT song_key, title, artist, art_url FROM song_reactions
             UNION ALL SELECT song_key, title, artist, art_url FROM song_bookmarks
             UNION ALL SELECT track_id AS song_key, title, artist, art_url FROM events
               WHERE type = 'play' AND track_id <> ''
           ) AS songs
          WHERE title LIKE ? OR artist LIKE ?
          GROUP BY song_key LIMIT $limit"
    );
    $stmt->execute([$like, $like]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = community_song((int) $user['id'], (string) $row['song_key'], $row);
    }
    api_ok(['query' => $q, 'songs' => $rows]);
}

/* -------------------------------------------------------------- follows */

if ($action === 'public_profile') {
    $user = require_api_user();
    $targetId = community_clamp_int($_GET['id'] ?? 0, 0, PHP_INT_MAX, 0);
    if ($targetId === 0) {
        $uuid = substr(trim((string) ($_GET['uuid'] ?? '')), 0, 64);
        if ($uuid === '') {
            api_err('id', 'A user id or uuid is required.');
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        $targetId = (int) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT id, uuid, name, plan, status, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) {
        api_err('not_found', 'User not found.', 404);
    }
    $history = community_history((int) $target['id'], 30, 1);
    $topArtists = $history['top_artists'];
    unset($history['plays'], $history['top_artists'], $history['daily']);
    $pdo = db();
    $count = static function (string $sql, array $args) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    };
    $stmt = $pdo->prepare(
        "SELECT c.id, c.song_key, c.parent_id, c.body, c.created_at, c.user_id, u.name AS user_name
           FROM song_comments c JOIN users u ON u.id = c.user_id
          WHERE c.user_id = ? AND c.status = 'visible' ORDER BY c.id DESC LIMIT 10"
    );
    $stmt->execute([(int) $target['id']]);
    $comments = community_comment_rows((int) $user['id'], $stmt->fetchAll());
    $follow = $pdo->prepare('SELECT 1 FROM user_follows WHERE follower_id = ? AND following_id = ?');
    $follow->execute([$user['id'], (int) $target['id']]);
    api_ok([
        'user' => [
            'id' => (int) $target['id'],
            'uuid' => $target['uuid'],
            'name' => $target['name'],
            'plan' => $target['plan'],
            'created_at' => $target['created_at'],
        ],
        'stats' => $history['stats'] + [
            'likes_given' => $count('SELECT COUNT(*) FROM song_reactions WHERE user_id = ? AND value = 1', [(int) $target['id']]),
            'comments' => $count("SELECT COUNT(*) FROM song_comments WHERE user_id = ? AND status = 'visible'", [(int) $target['id']]),
            'followers' => $count('SELECT COUNT(*) FROM user_follows WHERE following_id = ?', [(int) $target['id']]),
            'following' => $count('SELECT COUNT(*) FROM user_follows WHERE follower_id = ?', [(int) $target['id']]),
        ],
        'top_artists' => $topArtists,
        'comments' => $comments,
        'following' => (bool) $follow->fetchColumn(),
        'me' => (int) $target['id'] === (int) $user['id'],
    ]);
}

if ($action === 'follow') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $body = body_json();
    $targetId = community_clamp_int($body['user_id'] ?? 0, 0, PHP_INT_MAX, 0);
    if ($targetId === (int) $user['id']) {
        api_err('self', 'You cannot follow yourself.');
    }
    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $targetName = $stmt->fetchColumn();
    if ($targetName === false) {
        api_err('not_found', 'User not found.', 404);
    }
    if (body_str($body, 'action', 'follow') === 'unfollow') {
        $pdo->prepare('DELETE FROM user_follows WHERE follower_id = ? AND following_id = ?')
            ->execute([$user['id'], $targetId]);
        api_ok(['following' => false]);
    }
    $pdo->prepare(
        'INSERT IGNORE INTO user_follows (follower_id, following_id, created_at) VALUES (?, ?, ?)'
    )->execute([$user['id'], $targetId, now()]);
    community_notify($targetId, 'New follower', (string) ($user['name'] ?? 'A listener') . ' started following you.');
    api_ok(['following' => true]);
}

if ($action === 'following' || $action === 'followers') {
    $user = require_api_user();
    $mine = $action === 'following';
    if ($mine) {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.uuid, u.name, f.created_at FROM user_follows f
               JOIN users u ON u.id = f.following_id WHERE f.follower_id = ? ORDER BY f.created_at DESC LIMIT 200'
        );
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.uuid, u.name, f.created_at FROM user_follows f
               JOIN users u ON u.id = f.follower_id WHERE f.following_id = ? ORDER BY f.created_at DESC LIMIT 200'
        );
        $stmt->execute([$user['id']]);
    }
    api_ok(['users' => $stmt->fetchAll()]);
}

if ($action === 'artist_follow') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $body = body_json();
    $artist = substr(trim(body_str($body, 'artist')), 0, 191);
    if ($artist === '') {
        api_err('artist', 'An artist name is required.');
    }
    if (body_str($body, 'action', 'follow') === 'unfollow') {
        $pdo->prepare('DELETE FROM artist_follows WHERE user_id = ? AND artist = ?')
            ->execute([$user['id'], $artist]);
        api_ok(['following' => false, 'artist' => $artist]);
    }
    $pdo->prepare(
        'INSERT IGNORE INTO artist_follows (user_id, artist, created_at) VALUES (?, ?, ?)'
    )->execute([$user['id'], $artist, now()]);
    api_ok(['following' => true, 'artist' => $artist]);
}

if ($action === 'artist_follows') {
    $user = require_api_user();
    $stmt = $pdo->prepare('SELECT artist, created_at FROM artist_follows WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$user['id']]);
    api_ok(['artists' => $stmt->fetchAll()]);
}

/* ------------------------------------------------------ notifications */

if ($action === 'notifications_read') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $body = body_json();
    if (!empty($body['all'])) {
        $pdo->prepare('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL')
            ->execute([now(), $user['id']]);
    } else {
        $id = community_clamp_int($body['id'] ?? 0, 0, PHP_INT_MAX, 0);
        $pdo->prepare('UPDATE notifications SET read_at = ? WHERE id = ? AND user_id = ?')
            ->execute([now(), $id, $user['id']]);
    }
    api_ok(['read' => true]);
}

if ($action === 'notifications_clear') {
    if ($method !== 'POST') {
        api_err('method', 'Use POST.', 405);
    }
    $user = require_api_user();
    $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$user['id']]);
    api_ok(['cleared' => true]);
}

api_err('unknown_action', 'Unknown action: ' . $action, 404);
