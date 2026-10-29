<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

admin_session_start();
if (admin_current() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $ip = client_ip();

    if (too_many_attempts($email, $ip)) {
        $error = 'Too many failed attempts. Wait a few minutes and try again.';
    } else {
        $stmt = db()->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($password, $admin['password_hash'])) {
            record_attempt($email, $ip, true);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $upd = db()->prepare('UPDATE admins SET last_login_at = ?, last_ip = ? WHERE id = ?');
            $upd->execute([now(), $ip, $admin['id']]);
            audit('admin.login', $admin['email']);
            header('Location: index.php');
            exit;
        }
        record_attempt($email, $ip, false);
        $error = 'Wrong email or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · allomusic admin</title>
<link rel="stylesheet" href="../assets/admin.css">
</head>
<body class="auth-body">
<main class="auth-card">
    <h1><span class="logo-dot"></span> allomusic</h1>
    <p class="muted">Admin control panel</p>
    <?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label>Email<input type="email" name="email" autocomplete="username" required autofocus></label>
        <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="btn primary" type="submit">Sign in</button>
    </form>
    <p class="muted small">Protected by rate limiting and CSRF tokens.</p>
</main>
</body>
</html>
