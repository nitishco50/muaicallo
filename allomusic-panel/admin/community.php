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
        if ($action === 'hide') {
            $pdo->prepare('UPDATE song_comments SET status = "hidden" WHERE id = ?')->execute([$id]);
            audit('comment.hide', (string) $id);
            flash('Comment hidden.');
        } elseif ($action === 'show') {
            $pdo->prepare('UPDATE song_comments SET status = "visible" WHERE id = ?')->execute([$id]);
            audit('comment.show', (string) $id);
            flash('Comment visible again.');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM song_comments WHERE id = ?')->execute([$id]);
            audit('comment.delete', (string) $id);
            flash('Comment deleted.');
        }
        header('Location: community.php');
        exit;
    }
}

$comments = $pdo->query(
    "SELECT c.id, c.song_key, c.body, c.status, c.created_at, u.id AS user_id, u.name AS user_name,
            (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id) AS likes,
            (SELECT COUNT(*) FROM comment_reports cr WHERE cr.comment_id = c.id) AS reports
       FROM song_comments c JOIN users u ON u.id = c.user_id
      ORDER BY c.id DESC LIMIT 100"
)->fetchAll();
$stats = [
    'comments' => (int) $pdo->query("SELECT COUNT(*) FROM song_comments WHERE status = 'visible'")->fetchColumn(),
    'reactions' => (int) $pdo->query('SELECT COUNT(*) FROM song_reactions')->fetchColumn(),
    'bookmarks' => (int) $pdo->query('SELECT COUNT(*) FROM song_bookmarks')->fetchColumn(),
    'reports' => (int) $pdo->query('SELECT COUNT(*) FROM comment_reports')->fetchColumn(),
];

admin_header('Community', 'community');
?>
<div class="stats">
    <?php stat_card('Visible comments', (string) $stats['comments']); ?>
    <?php stat_card('Likes / dislikes', (string) $stats['reactions']); ?>
    <?php stat_card('Bookmarks', (string) $stats['bookmarks']); ?>
    <?php stat_card('Open reports', (string) $stats['reports']); ?>
</div>

<section class="card" style="margin-top:16px">
    <h2>Recent comments</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>User</th><th>Song key</th><th>Comment</th><th>Likes</th><th>Reports</th><th>Status</th><th>When</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($comments as $c): ?>
                <tr>
                    <td><?= (int) $c['id'] ?></td>
                    <td><a href="user.php?id=<?= (int) $c['user_id'] ?>"><?= e($c['user_name']) ?></a></td>
                    <td class="small nowrap"><?= e($c['song_key']) ?></td>
                    <td class="small"><?= e($c['body']) ?></td>
                    <td><?= (int) $c['likes'] ?></td>
                    <td><?= (int) $c['reports'] > 0 ? '<span class="badge err">' . (int) $c['reports'] . '</span>' : '0' ?></td>
                    <td><span class="badge <?= $c['status'] === 'visible' ? 'ok' : 'err' ?>"><?= e($c['status']) ?></span></td>
                    <td class="nowrap small"><?= e($c['created_at']) ?></td>
                    <td class="actions">
                        <form method="post" class="inline">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <input type="hidden" name="action" value="<?= $c['status'] === 'visible' ? 'hide' : 'show' ?>">
                            <button class="btn small" type="submit"><?= $c['status'] === 'visible' ? 'Hide' : 'Show' ?></button>
                        </form>
                        <form method="post" class="inline" onsubmit="return confirm('Delete this comment?');">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button class="btn small danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$comments): ?><tr><td colspan="9" class="muted">No community comments yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php admin_footer(); ?>
