<?php

declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Krasnoyarsk');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/subscription.php';
require_once __DIR__ . '/movies.php';
require_once __DIR__ . '/playback.php';
require_once __DIR__ . '/admin.php';
