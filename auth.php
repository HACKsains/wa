<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — ADMIN-ONLY MARGO ONE SSO AUTH MIDDLEWARE
 * ==============================================================================
 * Akses Admin Panel 100% WAJIB melalui Margo One — Ilmuna SSO dengan role `admin`.
 * Tidak ada form password lokal / bypass publik.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Cek apakah sesi saat ini adalah Admin terautentikasi sah melalui Margo One SSO
 */
function wa_is_admin_logged_in(): bool
{
    if (empty($_SESSION['wa_admin_logged_in']) || $_SESSION['wa_admin_logged_in'] !== true) {
        return false;
    }
    if (empty($_SESSION['margo_sso_authenticated']) || $_SESSION['margo_sso_authenticated'] !== true) {
        return false;
    }
    $role = strtolower(trim((string)($_SESSION['wa_admin_role'] ?? '')));
    return in_array($role, ['admin', 'super_admin'], true);
}

/**
 * Validasi klaim JWT Margo One SSO memiliki role `admin` / `super_admin`
 */
function wa_sso_claims_is_admin(array $claims): bool
{
    $collected = [];

    if (!empty($claims['active_role']) && is_string($claims['active_role'])) {
        $collected[] = strtolower(trim($claims['active_role']));
    }
    if (!empty($claims['role']) && is_string($claims['role'])) {
        $collected[] = strtolower(trim($claims['role']));
    }
    if (!empty($claims['roles'])) {
        if (is_array($claims['roles'])) {
            foreach ($claims['roles'] as $r) {
                if (is_string($r)) {
                    $collected[] = strtolower(trim($r));
                } elseif (is_array($r) && !empty($r['code'])) {
                    $collected[] = strtolower(trim((string)$r['code']));
                }
            }
        } elseif (is_string($claims['roles'])) {
            foreach (explode(',', $claims['roles']) as $r) {
                $collected[] = strtolower(trim($r));
            }
        }
    }

    foreach ($collected as $role) {
        if (in_array($role, ['admin', 'super_admin'], true)) {
            return true;
        }
    }
    return false;
}

/**
 * Ambil data admin SSO aktif
 */
function wa_current_admin(): array
{
    return [
        'id'       => (int)($_SESSION['wa_admin_id'] ?? 0),
        'name'     => (string)($_SESSION['wa_admin_name'] ?? 'Administrator'),
        'email'    => (string)($_SESSION['wa_admin_email'] ?? ''),
        'username' => (string)($_SESSION['wa_admin_username'] ?? 'admin'),
        'avatar'   => (string)($_SESSION['wa_admin_avatar'] ?? ''),
        'role'     => (string)($_SESSION['wa_admin_role'] ?? 'admin'),
        'sso_sid'  => (string)($_SESSION['margo_sso_sid'] ?? ''),
    ];
}

/**
 * Middleware wajib di setiap halaman & endpoint AJAX dashboard (khusus Admin SSO)
 */
function wa_require_admin(bool $isAjax = false): array
{
    if (!wa_is_admin_logged_in()) {
        if ($isAjax || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_GET['ajax']) && $_GET['ajax'] === '1')) {
            wa_json_response(false, 'Sesi Admin Margo One SSO belum aktif atau telah berakhir. Silakan login ulang.', [
                'redirect' => BASE_URL . '/login.php',
            ], 401);
        }
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    return wa_current_admin();
}

/**
 * Validasi CSRF token pada seluruh aksi POST AJAX di Dashboard Admin
 */
function wa_require_csrf_ajax(?array $jsonBody = null): void
{
    $headers = wa_get_request_headers();
    $token = (string)(
        $headers['x-csrf-token']
        ?? ($jsonBody['csrf_token'] ?? null)
        ?? ($_POST['csrf_token'] ?? '')
    );

    if (!wa_verify_csrf($token)) {
        wa_json_response(false, 'Token keamanan CSRF tidak valid. Muat ulang halaman dan coba lagi.', [], 403);
    }
}

/**
 * Bangun instance MargoOneSsoClient dengan deteksi otomatis lokal (/SSO) vs produksi (margo-one.ilmuna.my.id)
 */
function wa_sso_build_client(): MargoOneSsoClient
{
    require_once BASE_PATH . '/sdk/ilmuna-sso-client.php';

    $currHost = strtolower((string)(parse_url(BASE_URL, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'wa.ilmuna.my.id')));
    $isLocal  = in_array($currHost, ['localhost', '127.0.0.1', 'wa-bot.test'], true);

    $resolvedSsoUrl = !empty($_SESSION['wa_margo_sso_url'])
        ? (string)$_SESSION['wa_margo_sso_url']
        : (($isLocal && is_dir(dirname(BASE_PATH) . '/SSO'))
            ? ((str_starts_with(BASE_URL, 'https://') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/SSO')
            : ILMUNA_SSO_URL);

    $resolvedRedirect = !empty($_SESSION['wa_margo_sso_redirect'])
        ? (string)$_SESSION['wa_margo_sso_redirect']
        : ($isLocal ? (BASE_URL . '/sso-callback.php') : (BASE_URL . '/sso-callback'));

    $_SESSION['wa_margo_sso_url']      = $resolvedSsoUrl;
    $_SESSION['wa_margo_sso_redirect'] = $resolvedRedirect;

    return new MargoOneSsoClient([
        'sso_url'         => $resolvedSsoUrl,
        'public_key'      => ILMUNA_SSO_PUBLIC_KEY,
        'private_key'     => ILMUNA_SSO_PRIVATE_KEY,
        'sig_mode'        => ILMUNA_SSO_SIG_MODE,
        'rsa_private_pem' => ILMUNA_SSO_RSA_PRIVATE_PEM,
        'redirect_uri'    => $resolvedRedirect,
        'site_host'       => $isLocal ? 'wa.ilmuna.my.id' : $currHost,
        'ssl_verify'      => !$isLocal,
    ]);
}

/**
 * Bangun sesi Admin WA Bot yang sah setelah verifikasi JWT Margo One SSO
 */
function wa_establish_sso_admin_session(array $claims, array $tokenData = []): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['wa_admin_logged_in']      = true;
    $_SESSION['margo_sso_authenticated'] = true;
    $_SESSION['wa_admin_role']           = 'admin';
    $_SESSION['wa_admin_id']             = (int)($claims['sub'] ?? 0);
    $_SESSION['wa_admin_name']           = (string)($claims['name'] ?? $claims['full_name'] ?? $claims['preferred_username'] ?? 'Administrator');
    $_SESSION['wa_admin_email']          = (string)($claims['email'] ?? '');
    $_SESSION['wa_admin_username']       = (string)($claims['preferred_username'] ?? $claims['username'] ?? 'admin');
    $_SESSION['wa_admin_avatar']         = (string)($claims['picture'] ?? $claims['avatar'] ?? '');
    $_SESSION['margo_sso_sid']           = (string)($tokenData['sid'] ?? ($claims['sid'] ?? ''));
    $_SESSION['margo_sso_refresh_token'] = (string)($tokenData['refresh_token'] ?? '');
    $_SESSION['margo_sso_access_token']  = (string)($tokenData['access_token'] ?? '');
    $_SESSION['wa_login_at']             = date('Y-m-d H:i:s');
}
