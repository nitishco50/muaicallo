<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

$admin = require_admin();
$pdo = db();

$totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$onlineDay = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE last_seen_at > (NOW() - INTERVAL 1 DAY)")->fetchColumn();
$newWeek = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE created_at > (NOW() - INTERVAL 7 DAY)")->fetchColumn();
$banned = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'banned'")->fetchColumn();
$dataRows = (int) $pdo->query('SELECT COUNT(*) FROM user_data')->fetchColumn();
$dataBytes = (int) $pdo->query('SELECT COALESCE(SUM(LENGTH(json)), 0) FROM user_data')->fetchColumn();
$tokens = (int) $pdo->query('SELECT COUNT(*) FROM auth_tokens WHERE revoked = 0 AND expires_at > NOW()')->fetchColumn();

$recentUsers = $pdo->query('SELECT id, email, name, status, created_at, last_seen_at FROM users ORDER BY id DESC LIMIT 8')->fetchAll();
$recentAudit = $pdo->query('SELECT action, target, ip, created_at FROM audit_log ORDER BY id DESC LIMIT 8')->fetchAll();

admin_header('Dashboard', 'dash');
?>
<div class="grid">
    <?php stat_card('Total users', (string) $totalUsers, $activeUsers . ' active · ' . $banned . ' banned'); ?>
    <?php stat_card('Online (24h)', (string) $onlineDay); ?>
    <?php stat_card('New (7 days)', (string) $newWeek); ?>
    <?php stat_card('Active sessions', (string) $tokens); ?>
    <?php stat_card('Data documents', (string) $dataRows, round($dataBytes / 1024, 1) . ' KB stored'); ?>
    <?php stat_card('App version', (string) setting('latest_version_code', '1'), 'min ' . setting('min_version_code', '1')); ?>
</div>

<div class="grid two" style="margin-top:16px">
    <section class="card">
        <h2>System status</h2>
        <div class="kv">
            <div>App name</div><div><?= e(setting('app_name', 'allomusic')) ?></div>
            <div>Maintenance mode</div>
            <div><span class="badge <?= setting('maintenance', '0') === '1' ? 'warn' : 'ok' ?>"><?= setting('maintenance', '0') === '1' ? 'ON' : 'OFF' ?></span></div>
            <div>Active icon set</div><div><?= e(setting('icon_set', 'default')) ?></div>
            <div>Update URL</div><div class="small"><?= e(setting('update_url', '')) ?></div>
            <div>Base URL</div><div class="small"><?= e(base_url()) ?></div>
            <div>Server time</div><div><?= e(now()) ?></div>
        </div>
        <p style="margin-top:16px"><a class="btn" href="settings.php">Edit app settings</a></p>
    </section>

    <section class="card">
        <h2>Recent audit</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Action</th><th>Target</th><th>When</th></tr></thead>
                <tbody>
                <?php foreach ($recentAudit as $row): ?>
                    <tr>
                        <td><?= e($row['action']) ?></td>
                        <td class="muted small"><?= e($row['target']) ?></td>
                        <td class="nowrap small"><?= e($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentAudit): ?><tr><td colspan="3" class="muted">No activity yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <h2>Newest users</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Email</th><th>Name</th><th>Status</th><th>Joined</th><th>Last seen</th></tr></thead>
            <tbody>
            <?php foreach ($recentUsers as $u): ?>
                <tr>
                    <td><?= (int) $u['id'] ?></td>
                    <td><a href="user.php?id=<?= (int) $u['id'] ?>"><?= e($u['email']) ?></a></td>
                    <td><?= e($u['name']) ?></td>
                    <td><span class="badge <?= $u['status'] === 'active' ? 'ok' : 'err' ?>"><?= e($u['status']) ?></span></td>
                    <td class="nowrap small"><?= e($u['created_at']) ?></td>
                    <td class="nowrap small"><?= e($u['last_seen_at'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentUsers): ?><tr><td colspan="6" class="muted">No users yet. They appear after the first app sign-up.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php admin_footer(); ?>
