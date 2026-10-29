<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

require_admin();
$pdo = db();
$uploadDir = dirname(__DIR__) . '/uploads/icons';
@mkdir($uploadDir, 0775, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'select') {
        $key = (string) ($_POST['icon_key'] ?? '');
        $stmt = $pdo->prepare('SELECT icon_key FROM app_icons WHERE icon_key = ?');
        $stmt->execute([$key]);
        if ($stmt->fetchColumn()) {
            setting_set('icon_set', $key);
            audit('icon.select', $key);
            flash('Active icon set changed to ' . $key . '.');
        } else {
            flash('Unknown icon set.');
        }
    } elseif ($action === 'upload') {
        $file = $_FILES['artwork'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('Upload failed. Choose a PNG, JPG or WebP under 4 MB.');
        } elseif (($file['size'] ?? 0) > 4 * 1024 * 1024) {
            flash('Image is larger than 4 MB.');
        } else {
            $info = @getimagesize($file['tmp_name']);
            $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
            $type = $info[2] ?? 0;
            if (!$info || !isset($allowed[$type]) || ($info[0] ?? 0) < 48 || ($info[1] ?? 0) < 48) {
                flash('That is not a valid image (minimum 48×48).');
            } else {
                $name = 'brand_' . bin2hex(random_bytes(8)) . '.' . $allowed[$type];
                if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $name)) {
                    @chmod($uploadDir . '/' . $name, 0644);
                    $old = setting('brand_logo', '');
                    if ($old && is_file(dirname(__DIR__) . '/' . $old)) {
                        @unlink(dirname(__DIR__) . '/' . $old);
                    }
                    setting_set('brand_logo', 'uploads/icons/' . $name);
                    audit('icon.upload', $name);
                    flash('Custom artwork uploaded. The app picks it up on next launch.');
                } else {
                    flash('Could not save the upload. Check folder permissions.');
                }
            }
        }
    } elseif ($action === 'clear_logo') {
        $old = setting('brand_logo', '');
        if ($old && is_file(dirname(__DIR__) . '/' . $old)) {
            @unlink(dirname(__DIR__) . '/' . $old);
        }
        setting_set('brand_logo', '');
        audit('icon.clear');
        flash('Custom artwork removed.');
    }
    header('Location: icons.php');
    exit;
}

$icons = $pdo->query('SELECT * FROM app_icons ORDER BY sort, id')->fetchAll();
$active = setting('icon_set', 'default');
$brand = setting('brand_logo', '');
$brandUrl = $brand !== '' ? base_url() . '/' . ltrim($brand, '/') : '';

admin_header('App Icon', 'icons');
?>
<div class="alert warn">
    <strong>Android limitation:</strong> the home-screen icon can only be one of the icon sets compiled into the APK
    (each set is an <code>activity-alias</code>). The admin panel switches between those sets instantly — it cannot
    turn an arbitrary new upload into a launcher icon without shipping a new APK. Uploaded artwork is used as the
    in-app logo/brand image and as the custom set's preview.
</div>

<section class="card">
    <h2>Active home-screen icon</h2>
    <p class="muted">Currently: <span class="badge ok"><?= e($active) ?></span></p>
    <div class="icon-grid">
        <?php foreach ($icons as $icon): ?>
            <?php $isCustom = $icon['icon_key'] === 'user_custom'; ?>
            <form method="post" class="icon-card <?= $active === $icon['icon_key'] ? 'active' : '' ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="select">
                <input type="hidden" name="icon_key" value="<?= e($icon['icon_key']) ?>">
                <?php if ($isCustom && $brandUrl !== ''): ?>
                    <img src="<?= e($brandUrl) ?>" alt="">
                <?php else: ?>
                    <div style="width:72px;height:72px;margin:0 auto;border-radius:16px;background:linear-gradient(135deg,#8b5cf6,#4f46e5);display:grid;place-items:center;font-size:26px">♪</div>
                <?php endif; ?>
                <div class="name"><?= e($icon['name']) ?></div>
                <button class="btn small" type="submit" style="margin-top:8px"><?= $active === $icon['icon_key'] ? 'Active' : 'Use this' ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</section>

<section class="card" style="margin-top:16px">
    <h2>Custom artwork</h2>
    <?php if ($brandUrl !== ''): ?>
        <p><img src="<?= e($brandUrl) ?>" alt="Custom artwork" style="width:120px;height:120px;border-radius:22px;object-fit:cover;border:1px solid var(--line)"></p>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="clear_logo">
            <button class="btn danger small" type="submit">Remove artwork</button>
        </form>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="stack" style="margin-top:12px">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload">
        <label>Upload PNG / JPG / WebP (square, 512×512 or larger)
            <input type="file" name="artwork" accept="image/png,image/jpeg,image/webp" required>
        </label>
        <div class="actions"><button class="btn primary" type="submit">Upload artwork</button></div>
    </form>
</section>
<?php admin_footer(); ?>
