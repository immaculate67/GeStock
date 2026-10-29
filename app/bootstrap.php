<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ProductRepository.php';
require_once __DIR__ . '/ProductValidator.php';
require_once __DIR__ . '/BusinessRepository.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'use_strict_mode' => true,
        'cookie_lifetime' => 0,
        'use_only_cookies' => true,
        'use_trans_sid' => false,
    ]);
}
