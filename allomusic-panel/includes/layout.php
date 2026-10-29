<?php
declare(strict_types=1);

function admin_header(string $title, string $active = ''): void
{
    $admin = admin_current();
    $items = [
        'index.php' => ['Dashboard', 'dash'],
        'users.php' => ['Users', 'users'],
        'settings.php' => ['App Settings', 'settings'],
        'icons.php' => ['App Icon', 'icons'],
        'notices.php' => ['Notices', 'notices'],
        'audit.php' => ['Audit Log', 'audit'],
    ];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . ' · allomusic admin</title>'
        . '<link rel="stylesheet" href="../assets/admin.css"></head><body>';
    echo '<div class="shell"><aside class="sidebar">'
        . '<div class="brand"><span class="logo-dot"></span><span>allomusic</span></div>'
        . '<nav>';
    foreach ($items as $href => [$label, $key]) {
        $class = $key === $active ? 'nav-item active' : 'nav-item';
        echo '<a class="' . $class . '" href="' . $href . '">' . e($label) . '</a>';
    }
    echo '</nav><div class="side-foot">'
        . '<div class="who">' . e($admin['name'] ?? 'Admin') . '<small>' . e($admin['email'] ?? '') . '</small></div>'
        . '<a class="nav-item" href="logout.php">Sign out</a>'
        . '</div></aside><main class="content">';
    echo '<header class="topbar"><h1>' . e($title) . '</h1>';
    if ($admin) {
        echo '<span class="badge">' . e($admin['role'] ?? 'admin') . '</span>';
    }
    echo '</header>';
    $flash = flash();
    if ($flash !== null) {
        echo '<div class="alert ok">' . e($flash) . '</div>';
    }
}

function admin_footer(): void
{
    echo '</main></div></body></html>';
}

function stat_card(string $label, string $value, string $hint = ''): void
{
    echo '<div class="stat"><div class="stat-value">' . e($value) . '</div>'
        . '<div class="stat-label">' . e($label) . '</div>'
        . ($hint !== '' ? '<div class="stat-hint">' . e($hint) . '</div>' : '')
        . '</div>';
}

function pager(int $page, int $pages, string $base): void
{
    if ($pages <= 1) {
        return;
    }
    echo '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $class = $i === $page ? 'page active' : 'page';
        echo '<a class="' . $class . '" href="' . e($base . $i) . '">' . $i . '</a>';
    }
    echo '</div>';
}
