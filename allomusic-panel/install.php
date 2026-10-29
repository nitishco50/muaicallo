<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$schemaFile = __DIR__ . '/sql/schema.sql';
$error = '';
$done = false;

try {
    $hasAdmins = (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
} catch (Throwable $e) {
    $hasAdmins = -1;
    $error = 'Database not reachable: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasAdmins === 0) {
    admin_session_start();
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $name = trim((string) ($_POST['name'] ?? 'Owner')) ?: 'Owner';
    $password = (string) ($_POST['password'] ?? '');

    if (!valid_email($email)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 10) {
        $error = 'Use a password of at least 10 characters.';
    } else {
        try {
            $sql = (string) file_get_contents($schemaFile);
            foreach (preg_split('/;\s*\n/', $sql) as $statement) {
                $statement = trim($statement);
                if ($statement !== '' && !str_starts_with($statement, '--')) {
                    db()->exec($statement);
                }
            }
            $stmt = db()->prepare(
                'INSERT INTO admins (email, password_hash, name, role, created_at)
                 VALUES (?, ?, ?, "owner", ?)'
            );
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, now()]);
            @mkdir(__DIR__ . '/uploads/icons', 0775, true);
            @file_put_contents(__DIR__ . '/uploads/icons/.gitkeep', '');
            $done = true;
            $hasAdmins = 1;
        } catch (Throwable $e) {
            $error = 'Install failed: ' . $e->getMessage();
        }
    }
}

function page_head(string $title): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title><link rel="stylesheet" href="assets/admin.css"></head><body class="auth-body">';
}
?>
<?php page_head('allomusic installer'); ?>
<main class="auth-card">
    <h1><span class="logo-dot"></span> allomusic</h1>
    <p class="muted">Admin panel installer</p>

    <?php if ($hasAdmins > 0): ?>
        <div class="alert ok">
            <strong>Already installed.</strong>
            An administrator account exists. Delete <code>install.php</code> from the server.
        </div>
        <a class="btn" href="admin/login.php">Open admin panel</a>
    <?php elseif ($done): ?>
        <div class="alert ok">
            <strong>Install complete.</strong> Delete <code>install.php</code> now, then sign in.
        </div>
        <a class="btn" href="admin/login.php">Open admin panel</a>
    <?php else: ?>
        <div class="alert warn">
            Create your owner account. The database tables will be created on submit.
        </div>
        <?php if ($error !== ''): ?>
            <div class="alert err"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" class="stack">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label>Name<input name="name" value="Owner" autocomplete="name" required></label>
            <label>Email<input type="email" name="email" autocomplete="username" required></label>
            <label>Password<input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
            <button class="btn primary" type="submit">Install</button>
        </form>
        <p class="muted small">Use PHP 8.1+ and edit <code>config.php</code> first. Never keep this file after setup.</p>
    <?php endif; ?>
</main>
<?php if ($error !== '' && $hasAdmins === -1): ?>
    <main class="auth-card"><div class="alert err"><?= e($error) ?></div></main>
<?php endif; ?>
</body></html>
