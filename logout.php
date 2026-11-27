<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — LOGOUT ADMIN SSO
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/config.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: ' . BASE_URL . '/login.php?msg=logged_out');
exit;
