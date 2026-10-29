<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $target = (string) ($_POST['target'] ?? 'all');
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $userId = null;

    if ($target === 'user') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();
        if (!$userId) {
            flash('No user with that email.');
            header('Location: notices.php');
            exit;
        }
    }
    if ($title === '' || $body === '') {
        flash('Title and message are required.');
    } else {
        $ins = $pdo->prepare('INSERT INTO notifications (user_id, title, body, created_at) VALUES (?, ?, ?, ?)');
        $ins->execute([$userId, $title, $body, now()]);
        audit('notice.send', $userId ? (string) $userId : 'all', $title);
        flash('Notice sent.');
    }
    header('Location: notices.php');
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;
$offset = ($page - 1) * $per;
$total = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$rows = $pdo->query(
    'SELECT n.*, u.email FROM notifications n LEFT JOIN users u ON u.id = n.user_id
      ORDER BY n.id DESC LIMIT ' . $per . ' OFFSET ' . $offset
)->fetchAll();
$pages = max(1, (int) ceil($total / $per));

admin_header('Notices', 'notices');
?>
<div class="grid two">
    <section class="card">
        <h2>Send a notice</h2>
        <form method="post" class="stack">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label>Audience
                <select name="target">
                    <option value="all">Everyone</option>
                    <option value="user">One user</option>
                </select>
            </label>
            <label>User email (only for “One user”)<input type="email" name="email" placeholder="user@example.com"></label>
            <label>Title<input name="title" maxlength="160" required></label>
            <label>Message<textarea name="body" required style="min-height:100px;font-family:inherit;font-size:15px"></textarea></label>
            <div class="actions"><button class="btn primary" type="submit">Send notice</button></div>
        </form>
    </section>

    <section class="card">
        <h2>Delivery</h2>
        <div class="grid">
            <?php stat_card('Sent', (string) $total); ?>
        </div>
        <p class="muted small" style="margin-top:14px">Notices are pulled by the app from <code>/api/index.php?action=notifications</code> on launch.</p>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <h2>Recent notices</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Audience</th><th>Title</th><th>Message</th><th>Sent</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $n): ?>
            <tr>
                <td><?= (int) $n['id'] ?></td>
                <td><?= $n['user_id'] ? e($n['email'] ?? ('#' . $n['user_id'])) : '<span class="badge">all</span>' ?></td>
                <td><?= e($n['title']) ?></td>
                <td class="muted small"><?= e($n['body']) ?></td>
                <td class="nowrap small"><?= e($n['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">No notices yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php pager($page, $pages, 'notices.php?page='); ?>
</section>
<?php admin_footer(); ?>
