<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — MAIN ENTRY ROUTER (IDENTIK POLA learn/index.php)
 * ==============================================================================
 */

if (isset($_GET['sso_login']) && !defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (wa_is_admin_logged_in()) {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

// Teruskan alur auto-login Margo One SSO (/?sso_login=1) langsung ke /authorize tanpa putus
if (isset($_GET['sso_login']) && defined('ILMUNA_SSO_ENABLED') && ILMUNA_SSO_ENABLED) {
    try {
        unset($_SESSION['wa_margo_sso_url'], $_SESSION['wa_margo_sso_redirect']);
        $client = wa_sso_build_client();
        header('Location: ' . $client->buildAuthorizeUrl(['scope' => 'openid profile email roles']));
        exit;
    } catch (Throwable $e) {
        header('Location: ' . BASE_URL . '/login.php?sso_error=' . urlencode('Gagal memulai Margo One SSO: ' . $e->getMessage()));
        exit;
    }
}

header('Location: ' . BASE_URL . '/login.php');
exit;
