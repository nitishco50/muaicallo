<?php
// Copy this file to config.php and fill in the values from Hostinger.
// config.php is git-ignored and must never be committed.
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'u000000000_allo',
        'user'    => 'u000000000_allo',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        // Public origin of the panel/API. No trailing slash.
        'base_url' => 'https://allo.instadl.site',
        'timezone' => 'Asia/Kolkata',
    ],
    'security' => [
        'session_name'        => 'allomusic_admin',
        'token_ttl_days'      => 90,
        'max_login_attempts'  => 5,
        'login_window_minutes' => 15,
        'debug'               => false,
    ],
];
