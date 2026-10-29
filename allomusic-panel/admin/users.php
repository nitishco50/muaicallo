<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($id > 0) {
        if ($action === 'ban' || $action === 'unban') {
            $status = $action === 'ban' ? 'banned' : 'active';
            $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
            $stmt->execute([$status, $id]);
            if ($action === 'ban') {
                $pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE user_id = ?')->execute([$id]);
            }
            audit('user.' . $action, (string) $id);
            flash('User ' . ($action === 'ban' ? 'banned' : 'unbanned') . '.');
        } elseif ($action === 'revoke') {
            $pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE user_id = ?')->execute([$id]);
            audit('user.revoke_sessions', (string) $id);
            flash('All sessions revoked.');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            audit('user.delete', (string) $id);
            flash('User deleted with all their data.');
        }
        header('Location: users.php');
        exit;
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;
$offset = ($page - 1) * $per;

if ($q !== '') {
    $like = '%' . $q . '%';
    $count = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email LIKE ? OR name LIKE ?');
    $count->execute([$like, $like]);
    $total = (int) $count->fetchColumn();
    $stmt = $pdo->prepare(
        'SELECT *, (SELECT COUNT(*) FROM devices d WHERE d.user_id = users.id) AS device_count FROM users WHERE email LIKE ? OR name LIKE ? ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . $offset
    );
    $stmt->execute([$like, $like]);
} else {
    $total = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stmt = $pdo->query('SELECT *, (SELECT COUNT(*) FROM devices d WHERE d.user_id = users.id) AS device_count FROM users ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . $offset);
}
$users = $stmt->fetchAll();
$pages = max(1, (int) ceil($total / $per));

admin_header('Users', 'users');
?>
<section class="card">
    <form method="get" class="row">
        <label>Search by email or name
            <input name="q" value="<?= e($q) ?>" placeholder="someone@example.com">
        </label>
        <div style="flex:0 0 auto"><button class="btn primary" type="submit">Search</button></div>
        <?php if ($q !== ''): ?><div style="flex:0 0 auto"><a class="btn" href="users.php">Clear</a></div><?php endif; ?>
    </form>
    <p class="muted small" style="margin:14px 0 0"><?= $total ?> user<?= $total === 1 ? '' : 's' ?> found.</p>
</section>

<section class="card" style="margin-top:16px">
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Email</th><th>Name</th><th>Status</th><th>Devices</th><th>Joined</th><th>Last seen</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= (int) $u['id'] ?></td>
                    <td><a href="user.php?id=<?= (int) $u['id'] ?>"><?= e($u['email']) ?></a></td>
                    <td><?= e($u['name']) ?></td>
                    <td><span class="badge <?= $u['status'] === 'active' ? 'ok' : 'err' ?>"><?= e($u['status']) ?></span></td>
                    <td><?= (int) $u['device_count'] ?? 0 ?></td>
                    <td class="nowrap small"><?= e($u['created_at']) ?></td>
                    <td class="nowrap small"><?= e($u['last_seen_at'] ?? '—') ?></td>
                    <td class="actions">
                        <form method="post" class="inline">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <input type="hidden" name="action" value="<?= $u['status'] === 'active' ? 'ban' : 'unban' ?>">
                            <button class="btn small" type="submit"><?= $u['status'] === 'active' ? 'Ban' : 'Unban' ?></button>
                        </form>
                        <form method="post" class="inline" onsubmit="return confirm('Delete this user and all their data?');">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button class="btn small danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?><tr><td colspan="8" class="muted">No users match.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php pager($page, $pages, 'users.php?q=' . urlencode($q) . '&page='); ?>
</section>
<?php admin_footer(); ?>
