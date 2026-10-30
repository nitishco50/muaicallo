<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

require_admin();
$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(404);
    admin_header('User not found', 'users');
    echo '<div class="alert err">No such user.</div><a class="btn" href="users.php">Back to users</a>';
    admin_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $status = ($_POST['status'] ?? 'active') === 'banned' ? 'banned' : 'active';
        $plan = trim((string) ($_POST['plan'] ?? 'free')) ?: 'free';
        if (!valid_email($email)) {
            flash('Invalid email address.');
        } else {
            try {
                $upd = $pdo->prepare('UPDATE users SET name = ?, email = ?, status = ?, plan = ? WHERE id = ?');
                $upd->execute([$name, $email, $status, $plan, $id]);
                if ($status === 'banned') {
                    $pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE user_id = ?')->execute([$id]);
                }
                audit('user.update', (string) $id);
                flash('User updated.');
            } catch (Throwable $e) {
                flash('Could not update: email may already be in use.');
            }
        }
    } elseif ($action === 'password') {
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            flash('Password must be at least 8 characters.');
        } else {
            $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upd->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            $pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE user_id = ?')->execute([$id]);
            audit('user.password_reset', (string) $id);
            flash('Password changed and sessions revoked.');
        }
    } elseif ($action === 'revoke') {
        $pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE user_id = ?')->execute([$id]);
        audit('user.revoke_sessions', (string) $id);
        flash('All sessions revoked.');
    } elseif ($action === 'delete_data') {
        $ns = (string) ($_POST['ns'] ?? '');
        if ($ns !== '') {
            $del = $pdo->prepare('DELETE FROM user_data WHERE user_id = ? AND ns = ?');
            $del->execute([$id, $ns]);
            audit('user.data_delete', $id . ':' . $ns);
            flash('Data namespace deleted.');
        }
    } elseif ($action === 'delete_user') {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        audit('user.delete', (string) $id);
        flash('User deleted.');
        header('Location: users.php');
        exit;
    } elseif ($action === 'notify') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title !== '' && $body !== '') {
            $ins = $pdo->prepare('INSERT INTO notifications (user_id, title, body, created_at) VALUES (?, ?, ?, ?)');
            $ins->execute([$id, $title, $body, now()]);
            audit('notice.send', (string) $id, $title);
            flash('Notice sent to this user.');
        }
    }
    header('Location: user.php?id=' . $id);
    exit;
}

$devices = $pdo->prepare('SELECT * FROM devices WHERE user_id = ? ORDER BY last_seen DESC');
$devices->execute([$id]);
$devices = $devices->fetchAll();

$tokens = $pdo->prepare('SELECT * FROM auth_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 50');
$tokens->execute([$id]);
$tokens = $tokens->fetchAll();

$data = $pdo->prepare('SELECT ns, LENGTH(json) AS bytes, updated_at FROM user_data WHERE user_id = ? ORDER BY ns');
$data->execute([$id]);
$data = $data->fetchAll();

$openNs = (string) ($_GET['ns'] ?? '');
$openJson = null;
if ($openNs !== '') {
    $q = $pdo->prepare('SELECT json FROM user_data WHERE user_id = ? AND ns = ?');
    $q->execute([$id, $openNs]);
    $openJson = $q->fetchColumn();
}

$notices = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 20');
$notices->execute([$id]);
$notices = $notices->fetchAll();

$signals = signals_fetch($id);

$recentEvents = $pdo->prepare(
    'SELECT type, title, artist, listen_ms, duration_ms, created_at
       FROM events WHERE user_id = ? ORDER BY id DESC LIMIT 30'
);
$recentEvents->execute([$id]);
$recentEvents = $recentEvents->fetchAll();

admin_header('User #' . $id, 'users');
?>
<p><a href="users.php">← Back to users</a></p>
<div class="grid two">
    <section class="card">
        <h2>Profile</h2>
        <form method="post" class="stack">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update">
            <label>Name<input name="name" value="<?= e($user['name']) ?>"></label>
            <label>Email<input type="email" name="email" value="<?= e($user['email']) ?>" required></label>
            <div class="row">
                <label>Status
                    <select name="status">
                        <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>active</option>
                        <option value="banned" <?= $user['status'] === 'banned' ? 'selected' : '' ?>>banned</option>
                    </select>
                </label>
                <label>Plan<input name="plan" value="<?= e($user['plan']) ?>"></label>
            </div>
            <div class="actions"><button class="btn primary" type="submit">Save profile</button></div>
        </form>
        <div class="kv" style="margin-top:18px">
            <div>UUID</div><div class="small"><?= e($user['uuid']) ?></div>
            <div>Joined</div><div><?= e($user['created_at']) ?></div>
            <div>Last seen</div><div><?= e($user['last_seen_at'] ?? '—') ?></div>
            <div>Last IP</div><div><?= e($user['last_ip'] ?? '—') ?></div>
        </div>
    </section>

    <section class="card">
        <h2>Security</h2>
        <form method="post" class="stack">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="password">
            <label>Set new password<input type="text" name="password" minlength="8" placeholder="At least 8 characters"></label>
            <div class="actions"><button class="btn" type="submit">Change password</button></div>
        </form>
        <form method="post" style="margin-top:12px">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="revoke">
            <button class="btn" type="submit">Revoke all sessions</button>
        </form>
        <form method="post" style="margin-top:12px" onsubmit="return confirm('Delete this user and every record?');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_user">
            <button class="btn danger" type="submit">Delete user</button>
        </form>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <h2>Devices</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Device</th><th>Model</th><th>SDK</th><th>App</th><th>Last seen</th></tr></thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
            <tr>
                <td class="small"><?= e($d['device_id']) ?></td>
                <td><?= e($d['model']) ?></td>
                <td><?= e($d['sdk']) ?></td>
                <td><?= e($d['app_version']) ?></td>
                <td class="nowrap small"><?= e($d['last_seen']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$devices): ?><tr><td colspan="5" class="muted">No devices reported.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>


<section class="card" style="margin-top:16px">
    <h2>Listening signals <span class="muted small">(<?= count($signals) ?>)</span></h2>
    <?php if ($signals): ?>
        <div class="table-wrap" style="max-height:420px;overflow:auto">
            <table>
                <thead><tr><th>Signal</th><th>Value</th><th>Updated</th></tr></thead>
                <tbody>
                <?php foreach ($signals as $sig): ?>
                    <?php
                    $value = $sig['txt'] !== null && $sig['txt'] !== ''
                        ? $sig['txt']
                        : ($sig['num'] !== null
                            ? rtrim(rtrim(number_format((float) $sig['num'], 2, '.', ''), '0'), '.')
                            : '—');
                    ?>
                    <tr>
                        <td class="small"><?= e($sig['signal_key']) ?></td>
                        <td><?= e((string) $value) ?></td>
                        <td class="nowrap small"><?= e($sig['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="muted">No listening events yet. Signals appear after the app uploads its first batch.</p>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:16px">
    <h2>Recent events</h2>
    <?php if ($recentEvents): ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Type</th><th>Track</th><th>Artist</th><th>Listened</th><th>Duration</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($recentEvents as $ev): ?>
                <tr>
                    <td><span class="badge"><?= e($ev['type']) ?></span></td>
                    <td><?= e($ev['title'] ?: '—') ?></td>
                    <td class="muted"><?= e($ev['artist'] ?: '—') ?></td>
                    <td class="nowrap small"><?= $ev['listen_ms'] ? e(gmdate('i:s', (int) $ev['listen_ms'])) : '—' ?></td>
                    <td class="nowrap small"><?= $ev['duration_ms'] ? e(gmdate('i:s', (int) $ev['duration_ms'])) : '—' ?></td>
                    <td class="nowrap small"><?= e($ev['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <p class="muted">No events recorded.</p>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:16px">
    <h2>Stored data</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Namespace</th><th>Size</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($data as $d): ?>
            <tr>
                <td><a href="user.php?id=<?= $id ?>&ns=<?= urlencode($d['ns']) ?>#data"><?= e($d['ns']) ?></a></td>
                <td><?= (int) $d['bytes'] ?> B</td>
                <td class="nowrap small"><?= e($d['updated_at']) ?></td>
                <td>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this namespace?');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_data">
                        <input type="hidden" name="ns" value="<?= e($d['ns']) ?>">
                        <button class="btn small danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$data): ?><tr><td colspan="4" class="muted">Nothing synced yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php if ($openJson !== null && $openJson !== false): ?>
        <h3 id="data" style="margin-top:18px">Namespace: <?= e($openNs) ?></h3>
        <textarea readonly style="min-height:240px"><?= e((string) $openJson) ?></textarea>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:16px">
    <h2>Sessions</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Device</th><th>IP</th><th>Created</th><th>Last used</th><th>Expires</th><th>State</th></tr></thead>
        <tbody>
        <?php foreach ($tokens as $t): ?>
            <tr>
                <td><?= e($t['device_name'] ?: $t['device_id']) ?></td>
                <td class="small"><?= e($t['ip']) ?></td>
                <td class="nowrap small"><?= e($t['created_at']) ?></td>
                <td class="nowrap small"><?= e($t['last_used_at'] ?? '—') ?></td>
                <td class="nowrap small"><?= e($t['expires_at']) ?></td>
                <td><span class="badge <?= ((int) $t['revoked'] === 0 && strtotime($t['expires_at']) > time()) ? 'ok' : 'err' ?>"><?= ((int) $t['revoked'] === 0 && strtotime($t['expires_at']) > time()) ? 'live' : 'dead' ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$tokens): ?><tr><td colspan="6" class="muted">No sessions.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>

<section class="card" style="margin-top:16px">
    <h2>Send notice</h2>
    <form method="post" class="stack">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="notify">
        <label>Title<input name="title" maxlength="160" required></label>
        <label>Message<textarea name="body" required style="min-height:80px;font-family:inherit;font-size:15px"></textarea></label>
        <div class="actions"><button class="btn primary" type="submit">Send to user</button></div>
    </form>
    <?php if ($notices): ?>
        <div class="table-wrap" style="margin-top:16px"><table>
            <thead><tr><th>Title</th><th>Message</th><th>Sent</th></tr></thead>
            <tbody>
            <?php foreach ($notices as $n): ?>
                <tr><td><?= e($n['title']) ?></td><td class="muted small"><?= e($n['body']) ?></td><td class="nowrap small"><?= e($n['created_at']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
