<?php
declare(strict_types=1);

// Daily housekeeping. Run from cron, never from the web:
//   php /path/to/backend/cron/cleanup.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = db();

$tokens = $pdo->exec('DELETE FROM auth_tokens WHERE expires_at < (NOW() - INTERVAL 30 DAY) OR revoked = 1');
$attempts = $pdo->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 30 DAY)');
$audit = $pdo->exec('DELETE FROM audit_log WHERE created_at < (NOW() - INTERVAL 365 DAY)');
$notices = $pdo->exec('DELETE FROM notifications WHERE created_at < (NOW() - INTERVAL 180 DAY)');

printf(
    "removed tokens=%d attempts=%d audit=%d notices=%d\n",
    (int) $tokens,
    (int) $attempts,
    (int) $audit,
    (int) $notices,
);
