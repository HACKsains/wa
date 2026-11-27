<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — MARGO ONE SSO CALLBACK HANDLER (STRICT ADMIN-ONLY GATE)
 * ==============================================================================
 * 1. Terima `code` + `state` dari Margo One SSO (/authorize)
 * 2. Tukar token bertanda tangan PKCE S256 + HMAC-SHA256 + verifikasi JWT RS256
 * 3. VALIDASI KERAS ROLE ADMIN (`admin` / `super_admin`) — tolak seluruh role lain!
 * 4. Bangun sesi Admin WA Bot & arahkan ke /dashboard/index.php
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (!ILMUNA_SSO_ENABLED) {
    header('Location: ' . BASE_URL . '/login.php?sso_error=' . urlencode('Integrasi Margo One SSO sedang nonaktif.'));
    exit;
}

if (!empty($_GET['error'])) {
    $desc = (string)($_GET['error_description'] ?? $_GET['error']);
    header('Location: ' . BASE_URL . '/login.php?sso_error=' . urlencode($desc));
    exit;
}

$code  = trim((string)($_GET['code'] ?? ''));
$state = trim((string)($_GET['state'] ?? ''));

if ($code === '' || $state === '') {
    header('Location: ' . BASE_URL . '/login.php?sso_error=' . urlencode('Parameter otorisasi Margo One SSO tidak lengkap.'));
    exit;
}

try {
    $client    = wa_sso_build_client();
    $tokenData = $client->exchangeCode($code, $state);
    $claims    = (array)($tokenData['id_token_claims'] ?? []);

    // Tambahan fallback verifikasi role dari endpoint userinfo jika claims ringkas
    if (!wa_sso_claims_is_admin($claims) && !empty($tokenData['access_token'])) {
        try {
            $uInfo = $client->fetchUserInfo((string)$tokenData['access_token']);
            if (is_array($uInfo)) {
                $claims = array_merge($claims, $uInfo);
            }
        } catch (Throwable $eUi) {
            // Lanjutkan validasi dari id_token_claims
        }
    }

    $pdo = getDBConnection();

    // GERBANG KETAT KHUSUS ADMIN: Tolak seluruh akun non-admin!
    if (!wa_sso_claims_is_admin($claims)) {
        $who = (string)($claims['email'] ?? $claims['preferred_username'] ?? 'unknown');
        wa_log_sync($pdo, 'learn_to_wa', 'sso_admin_denied', 403, "User={$who}", 'Non-admin role ditolak');
        header('Location: ' . BASE_URL . '/login.php?sso_error=' . urlencode(
            'Akses ditolak: Panel Ilmuna WA Bot hanya dapat diakses oleh akun dengan peran Administrator.'
        ));
        exit;
    }

    wa_establish_sso_admin_session($claims, $tokenData);
    wa_log_sync($pdo, 'learn_to_wa', 'sso_admin_login', 200, 'Admin=' . $_SESSION['wa_admin_email'], 'Login SSO Admin berhasil');

    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
} catch (Throwable $e) {
    header('Location: ' . BASE_URL . '/login.php?sso_error=' . urlencode('Verifikasi Margo One SSO gagal: ' . $e->getMessage()));
    exit;
}
