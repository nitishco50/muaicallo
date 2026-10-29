<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? 'settings');
    if ($action === 'settings') {
        $flags = trim((string) ($_POST['flags_json'] ?? '{}'));
        if ($flags === '' || json_decode($flags, true) === null && $flags !== 'null') {
            flash('Feature flags must be valid JSON. Nothing was saved.');
        } else {
            foreach ([
                'app_name' => 'allomusic',
                'maintenance' => '0',
                'maintenance_message' => '',
                'min_version_code' => '1',
                'latest_version_code' => '1',
                'update_url' => '',
                'force_update' => '0',
                'icon_set' => 'default',
                'announcement' => '',
            ] as $key => $default) {
                $value = (string) ($_POST[$key] ?? $default);
                if ($key === 'maintenance' || $key === 'force_update') {
                    $value = $value === '1' ? '1' : '0';
                }
                if ($key === 'min_version_code' || $key === 'latest_version_code') {
                    $value = (string) max(1, (int) $value);
                }
                setting_set($key, $value);
            }
            setting_set('flags_json', $flags);
            audit('settings.update');
            flash('App settings saved. Clients pick them up on next launch.');
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current'] ?? '');
        $next = (string) ($_POST['next'] ?? '');
        $admin = admin_current();
        if (!password_verify($current, $admin['password_hash'])) {
            flash('Current password is wrong.');
        } elseif (strlen($next) < 10) {
            flash('New password must be at least 10 characters.');
        } else {
            $upd = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
            $upd->execute([password_hash($next, PASSWORD_DEFAULT), $admin['id']]);
            audit('admin.password');
            flash('Admin password changed.');
        }
    }
    header('Location: settings.php');
    exit;
}

$icons = $pdo->query('SELECT icon_key, name FROM app_icons ORDER BY sort, id')->fetchAll();
$activeIcon = setting('icon_set', 'default');

admin_header('App Settings', 'settings');
?>
<form method="post" class="stack">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="settings">

    <section class="card">
        <h2>Identity</h2>
        <div class="row">
            <label>App name<input name="app_name" value="<?= e(setting('app_name', 'allomusic')) ?>" maxlength="60"></label>
            <label>Home-screen icon set
                <select name="icon_set">
                    <?php foreach ($icons as $icon): ?>
                        <option value="<?= e($icon['icon_key']) ?>" <?= $activeIcon === $icon['icon_key'] ? 'selected' : '' ?>>
                            <?= e($icon['name']) ?> (<?= e($icon['icon_key']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="muted small">The app can only switch to icon sets shipped inside the APK. Upload custom artwork on the <a href="icons.php">App Icon</a> page.</p>
    </section>

    <section class="card">
        <h2>Availability</h2>
        <div class="row">
            <label>Maintenance mode
                <select name="maintenance">
                    <option value="0" <?= setting('maintenance', '0') !== '1' ? 'selected' : '' ?>>Off</option>
                    <option value="1" <?= setting('maintenance', '0') === '1' ? 'selected' : '' ?>>On</option>
                </select>
            </label>
            <label>Minimum version code<input type="number" min="1" name="min_version_code" value="<?= e(setting('min_version_code', '1')) ?>"></label>
            <label>Latest version code<input type="number" min="1" name="latest_version_code" value="<?= e(setting('latest_version_code', '1')) ?>"></label>
        </div>
        <label>Maintenance message<textarea name="maintenance_message" style="min-height:80px;font-family:inherit;font-size:15px"><?= e(setting('maintenance_message', '')) ?></textarea></label>
    </section>

    <section class="card">
        <h2>Updates &amp; messaging</h2>
        <div class="row">
            <label>Update URL<input name="update_url" value="<?= e(setting('update_url', '')) ?>"></label>
            <label>Force update
                <select name="force_update">
                    <option value="0" <?= setting('force_update', '0') !== '1' ? 'selected' : '' ?>>Off</option>
                    <option value="1" <?= setting('force_update', '0') === '1' ? 'selected' : '' ?>>On</option>
                </select>
            </label>
        </div>
        <label>Announcement banner<textarea name="announcement" style="min-height:80px;font-family:inherit;font-size:15px"><?= e(setting('announcement', '')) ?></textarea></label>
        <label>Feature flags (JSON)<textarea name="flags_json"><?= e(setting('flags_json', '{}')) ?></textarea></label>
    </section>

    <div class="actions"><button class="btn primary" type="submit">Save settings</button></div>
</form>

<form method="post" class="card" style="margin-top:16px">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="password">
    <h2>Change admin password</h2>
    <div class="row">
        <label>Current password<input type="password" name="current" autocomplete="current-password" required></label>
        <label>New password<input type="password" name="next" minlength="10" autocomplete="new-password" required></label>
    </div>
    <div class="actions"><button class="btn" type="submit">Update password</button></div>
</form>
<?php admin_footer(); ?>
