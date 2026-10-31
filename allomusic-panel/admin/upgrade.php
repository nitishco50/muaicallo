<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

require_admin();
$pdo = db();
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    audit('database.upgrade');
    try {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $ran = 0;
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
                $ran++;
            }
        }
        $messages[] = "Schema statements executed: $ran.";

        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "events" AND COLUMN_NAME = "art_url"'
        );
        $check->execute();
        if ((int) $check->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE events ADD COLUMN art_url VARCHAR(512) NOT NULL DEFAULT "" AFTER album');
            $messages[] = 'Added events.art_url for history artwork.';
        }

        if ((int) setting('latest_version_code', '1') < 6) {
            setting_set('latest_version_code', '6');
            $messages[] = 'Latest app version code set to 6.';
        }
        $messages[] = 'Database is up to date.';
        flash('Upgrade finished.');
    } catch (Throwable $e) {
        $messages[] = 'Upgrade failed: ' . $e->getMessage();
    }
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
admin_header('Database upgrade', 'upgrade');
?>
<p class="muted">Run this once after uploading a new backend. Every statement is idempotent: tables use <code>CREATE TABLE IF NOT EXISTS</code>, seeds use <code>INSERT IGNORE</code>, and the one new column is checked before it is added.</p>
<?php foreach ($messages as $message): ?>
    <div class="alert <?= str_contains($message, 'failed') ? 'err' : 'ok' ?>"><?= e($message) ?></div>
<?php endforeach; ?>
<form method="post" class="stack">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <button class="btn primary" type="submit">Apply schema upgrade</button>
</form>
<h2>Current tables</h2>
<div class="chips">
    <?php foreach ($tables as $table): ?>
        <span class="chip"><?= e($table) ?></span>
    <?php endforeach; ?>
</div>
<?php admin_footer(); ?>
