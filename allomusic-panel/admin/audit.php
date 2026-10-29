<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

require_admin();
$pdo = db();
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;
$offset = ($page - 1) * $per;
$total = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$rows = $pdo->query(
    'SELECT a.*, ad.email AS admin_email FROM audit_log a
       LEFT JOIN admins ad ON ad.id = a.admin_id
      ORDER BY a.id DESC LIMIT ' . $per . ' OFFSET ' . $offset
)->fetchAll();
$pages = max(1, (int) ceil($total / $per));

admin_header('Audit Log', 'audit');
?>
<section class="card">
    <p class="muted small"><?= $total ?> entries. Every admin action is recorded with IP and time.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= (int) $r['id'] ?></td>
                <td class="nowrap small"><?= e($r['created_at']) ?></td>
                <td class="small"><?= e($r['admin_email'] ?? '—') ?></td>
                <td><?= e($r['action']) ?></td>
                <td class="muted small"><?= e($r['target']) ?></td>
                <td class="small"><?= e($r['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="muted">No entries.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php pager($page, $pages, 'audit.php?page='); ?>
</section>
<?php admin_footer(); ?>
