<?php
$sessionLifetime = 24 * 60 * 60; // 24 horas
$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$cookiePath = '/';
$cookieSettings = [
    'lifetime' => $sessionLifetime,
    'path' => $cookiePath,
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax',
];

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    session_set_cookie_params($cookieSettings);
    session_start();
}

if (session_status() === PHP_SESSION_ACTIVE) {
    setcookie(session_name(), session_id(), [
        'expires' => time() + $sessionLifetime,
        'path' => $cookiePath,
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
