<?php
declare(strict_types=1);

// 0. Timezone wajib Waktu Indonesia Barat (WIB) & Polyfill PHP 7.4 (ea-php74 cPanel)
date_default_timezone_set('Asia/Jakarta');

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        return strlen($haystack) >= $len && substr_compare($haystack, $needle, -$len) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// ==============================================================================
// GUARDIAN DOME (Patch-3.0) — LAYER PERLINDUNGAN TERDEPAN EKOSISTEM ILMUNA
// Target: wa.ilmuna.my.id (Admin Panel & Public Routes)
// Anti-Judol, Anti-DDoS, Auto-Ban Blacklist IP, & Verifikasi Pengunjung.
// Endpoint server-to-server (webhook.php, api/v1/*, sso-callback.php) dan CLI
// melewati interstitial HTML melalui konstanta ILMUNA_SKIP_GD_WAF_INTERSTITIAL.
// ==============================================================================
if (PHP_SAPI !== 'cli' && (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL') || !ILMUNA_SKIP_GD_WAF_INTERSTITIAL)) {
    $gd_waf_candidates = [
        '/home/uofruaju/guardian-dome/guardian-dome-waf.php',
        'C:/laragon/Guardian Dome/guardian-dome-waf.php',
        'C:/laragon/guardian-dome/guardian-dome-waf.php',
        dirname(dirname(__DIR__)) . '/Guardian Dome/guardian-dome-waf.php',
        dirname(dirname(__DIR__)) . '/guardian-dome/guardian-dome-waf.php',
        dirname(__DIR__) . '/Guardian Dome/guardian-dome-waf.php',
        dirname(__DIR__) . '/guardian-dome/guardian-dome-waf.php',
        __DIR__ . '/../Guardian Dome/guardian-dome-waf.php',
        __DIR__ . '/guardian-dome-waf.php',
    ];
    foreach ($gd_waf_candidates as $gd_waf) {
        if ($gd_waf && @file_exists($gd_waf)) {
            require_once $gd_waf;
            break;
        }
    }
}

// ==============================================================================
// GUARDIAN DOME — TELEGRAM SENTINEL REAL-TIME MONITORING
// Memantau ancaman siber, fatal error/crash, & trafik tidak wajar ke Telegram
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    $gd_tg_candidates = [
        '/home/uofruaju/guardian-dome/guardian-dome-telegram/bootstrap.php',
        'C:/laragon/Guardian Dome/guardian-dome-telegram/bootstrap.php',
        'C:/laragon/guardian-dome/guardian-dome-telegram/bootstrap.php',
        dirname(dirname(__DIR__)) . '/Guardian Dome/guardian-dome-telegram/bootstrap.php',
        dirname(dirname(__DIR__)) . '/guardian-dome/guardian-dome-telegram/bootstrap.php',
        dirname(__DIR__) . '/Guardian Dome/guardian-dome-telegram/bootstrap.php',
        dirname(__DIR__) . '/guardian-dome/guardian-dome-telegram/bootstrap.php',
        __DIR__ . '/../Guardian Dome/guardian-dome-telegram/bootstrap.php',
        __DIR__ . '/guardian-dome-telegram/bootstrap.php',
    ];
    foreach ($gd_tg_candidates as $gd_tg) {
        if ($gd_tg && @file_exists($gd_tg)) {
            require_once $gd_tg;
            break;
        }
    }
}

/**
 * ==============================================================================
 * ILMUNA WA BOT — CENTRALIZED CONFIGURATION (SINGLE SOURCE OF TRUTH)
 * ==============================================================================
 * Platform    : WhatsApp Bot PHP 7.4+ / 8.x Native (Interactive Buttons, Anti-Call,
 *               Group Guard, Auto-Broadcast Akademik & Bridge Ilmuna Learn)
 * Domain      : wa.ilmuna.my.id
 * UI Standard : Lumina Gold & Sentuhan Nusantara (learn/README.md)
 * ==============================================================================
 */

// 1. Deteksi HTTPS (termasuk di balik LiteSpeed / Reverse Proxy / Cloudflare)
$waIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

// 2. Session aman untuk mode non-CLI (Admin Panel & CSRF)
if (session_status() === PHP_SESSION_NONE && !headers_sent() && PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $waIsHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// 3. Muat override lokal/produksi opsional (tidak wajib ada di repo)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// 4. Identitas Aplikasi & Jalur Sistem
if (!defined('APP_NAME'))    define('APP_NAME', 'Ilmuna WA Bot');
if (!defined('APP_TAGLINE')) define('APP_TAGLINE', 'Asisten WhatsApp Interaktif, Moderasi Grup & Broadcast Akademik Ilmuna Learn');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.0.0');
if (!defined('APP_DOMAIN'))  define('APP_DOMAIN', 'wa.ilmuna.my.id');
if (!defined('BASE_PATH'))   define('BASE_PATH', __DIR__);

if (!defined('BASE_URL')) {
    if (PHP_SAPI === 'cli') {
        $isWinCli = DIRECTORY_SEPARATOR === '\\';
        define('BASE_URL', $isWinCli ? 'http://localhost/wa%20bot' : ('https://' . APP_DOMAIN));
    } else {
        $protocol = $waIsHttps ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = (string)preg_replace(
            '#/(dashboard(/.*)?|api(/.*)?|workers(/.*)?|core(/.*)?|includes(/.*)?)$#',
            '',
            $scriptDir
        );
        $basePath = rtrim($basePath, '/');
        if ($basePath === '.' || $basePath === './') {
            $basePath = '';
        }
        define('BASE_URL', $protocol . $host . $basePath);
    }
}

// 5. Konfigurasi Database MySQL InnoDB (Produksi cPanel: uofruaju_wa)
if (!defined('DB_HOST'))    define('DB_HOST', (string)(getenv('WA_DB_HOST') ?: 'localhost'));
if (!defined('DB_PORT'))    define('DB_PORT', (string)(getenv('WA_DB_PORT') ?: '3306'));
if (!defined('DB_NAME'))    define('DB_NAME', (string)(getenv('WA_DB_NAME') ?: 'uofruaju_wa'));
if (!defined('DB_USER'))    define('DB_USER', (string)(getenv('WA_DB_USER') ?: 'uofruaju_wa'));
if (!defined('DB_PASS'))    define('DB_PASS', (string)(getenv('WA_DB_PASS') !== false ? getenv('WA_DB_PASS') : 'Ilmuna28@'));
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// 6. Konfigurasi Dual Transport Adapter (hybrid | cloud_api | md_bridge)
if (!defined('WA_DRIVER'))                define('WA_DRIVER', (string)(getenv('WA_DRIVER') ?: 'hybrid'));
if (!defined('WA_DRY_RUN'))               define('WA_DRY_RUN', false);

// Meta WhatsApp Cloud API (Graph API v21.0)
if (!defined('WA_CLOUD_API_VERSION'))     define('WA_CLOUD_API_VERSION', 'v21.0');
if (!defined('WA_CLOUD_GRAPH_URL'))       define('WA_CLOUD_GRAPH_URL', 'https://graph.facebook.com/' . WA_CLOUD_API_VERSION);
if (!defined('WA_CLOUD_PHONE_NUMBER_ID')) define('WA_CLOUD_PHONE_NUMBER_ID', (string)(getenv('WA_CLOUD_PHONE_NUMBER_ID') ?: ''));
if (!defined('WA_CLOUD_ACCESS_TOKEN'))    define('WA_CLOUD_ACCESS_TOKEN', (string)(getenv('WA_CLOUD_ACCESS_TOKEN') ?: ''));
if (!defined('WA_CLOUD_VERIFY_TOKEN'))    define('WA_CLOUD_VERIFY_TOKEN', (string)(getenv('WA_CLOUD_VERIFY_TOKEN') ?: 'ilmuna_wa_verify_2026'));
if (!defined('WA_CLOUD_APP_SECRET'))      define('WA_CLOUD_APP_SECRET', (string)(getenv('WA_CLOUD_APP_SECRET') ?: ''));

// Self-Hosted Multi-Device Bridge (WAHA / Evolution / Go-WA / Generic JSON Bridge)
// Port default 38421 (menghindari bentrok port 3000 dengan akun lain di shared cPanel)
if (!defined('WA_BRIDGE_BASE_URL'))       define('WA_BRIDGE_BASE_URL', (string)(getenv('WA_BRIDGE_BASE_URL') ?: 'http://127.0.0.1:38421'));
if (!defined('WA_BRIDGE_API_KEY'))        define('WA_BRIDGE_API_KEY', (string)(getenv('WA_BRIDGE_API_KEY') ?: ''));
if (!defined('WA_BRIDGE_SESSION'))        define('WA_BRIDGE_SESSION', (string)(getenv('WA_BRIDGE_SESSION') ?: 'ilmuna_main'));
if (!defined('WA_BRIDGE_WEBHOOK_SECRET')) define('WA_BRIDGE_WEBHOOK_SECRET', (string)(getenv('WA_BRIDGE_WEBHOOK_SECRET') ?: 'ilmuna_md_webhook_secret_2026'));
if (!defined('WA_BRIDGE_FLAVOR'))         define('WA_BRIDGE_FLAVOR', (string)(getenv('WA_BRIDGE_FLAVOR') ?: 'generic'));

// 7. Konfigurasi Integrasi Dua Arah HMAC-SHA256 dengan Ilmuna Learn
if (!defined('LEARN_API_BASE_URL')) {
    $envLearnApi = getenv('LEARN_API_BASE_URL');
    if ($envLearnApi !== false && $envLearnApi !== '') {
        define('LEARN_API_BASE_URL', (string)$envLearnApi);
    } else {
        $reqHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $isLocalReq = (PHP_SAPI === 'cli' && DIRECTORY_SEPARATOR === '\\')
            || in_array($reqHost, ['localhost', '127.0.0.1', 'wa-bot.test'], true)
            || strpos($reqHost, 'localhost:') === 0
            || strpos($reqHost, '127.0.0.1:') === 0;
        define('LEARN_API_BASE_URL', $isLocalReq ? 'http://localhost/learn' : 'https://learn.ilmuna.my.id');
    }
}
if (!defined('LEARN_PUBLIC_URL'))         define('LEARN_PUBLIC_URL', (string)(getenv('LEARN_PUBLIC_URL') ?: 'https://learn.ilmuna.my.id'));
if (!defined('WA_BRIDGE_PUBLIC_KEY'))     define('WA_BRIDGE_PUBLIC_KEY', (string)(getenv('WA_BRIDGE_PUBLIC_KEY') ?: 'pk_wa_ilmuna_2026_bridge'));
if (!defined('LEARN_HMAC_SECRET'))        define('LEARN_HMAC_SECRET', (string)(getenv('LEARN_HMAC_SECRET') ?: 'sk_wa_ilmuna_2026_hmac_shared_secret'));
if (!defined('LEARN_HMAC_MAX_SKEW'))      define('LEARN_HMAC_MAX_SKEW', 300);

// 7B. Konfigurasi Margo One — Ilmuna SSO (WAJIB LOGIN SSO KHUSUS ADMIN)
if (!defined('ILMUNA_SSO_ENABLED'))         define('ILMUNA_SSO_ENABLED', true);
if (!defined('ILMUNA_SSO_ADMIN_ONLY'))      define('ILMUNA_SSO_ADMIN_ONLY', true);
if (!defined('ILMUNA_SSO_URL'))             define('ILMUNA_SSO_URL', (string)(getenv('ILMUNA_SSO_URL') ?: 'https://margo-one.ilmuna.my.id'));
if (!defined('ILMUNA_SSO_PUBLIC_KEY'))      define('ILMUNA_SSO_PUBLIC_KEY', (string)(getenv('ILMUNA_SSO_PUBLIC_KEY') ?: 'pk_sso_f22569b9d238417c7701ea921f85efc1'));
if (!defined('ILMUNA_SSO_PRIVATE_KEY'))     define('ILMUNA_SSO_PRIVATE_KEY', (string)(getenv('ILMUNA_SSO_PRIVATE_KEY') ?: 'sk_sso_c52d67237b915ae4c233125c30722d47ed199641ae7cbdfe051054f687e74fec'));
if (!defined('ILMUNA_SSO_SIG_MODE'))        define('ILMUNA_SSO_SIG_MODE', 'HMAC-SHA256');
if (!defined('ILMUNA_SSO_RSA_PRIVATE_PEM')) define('ILMUNA_SSO_RSA_PRIVATE_PEM', '');

// 8. Identitas Sekolah & Kontak Admin Default
if (!defined('SCHOOL_NAME'))              define('SCHOOL_NAME', 'Ilmuna Learn School');
if (!defined('SCHOOL_ADMIN_PHONE'))       define('SCHOOL_ADMIN_PHONE', '6281200000000');

// 9. Sentralisasi UI Templates, Favicon & Iconify Solar Icons (Standar learn/README.md)
if (!defined('ICONIFY_CDN_URL'))          define('ICONIFY_CDN_URL', 'https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js');
if (!defined('ICONIFY_OFFICIAL_SET'))     define('ICONIFY_OFFICIAL_SET', 'solar');
if (!defined('INCLUDES_PATH'))            define('INCLUDES_PATH', BASE_PATH . '/includes');
if (!defined('TEMPLATE_MODAL_PATH'))      define('TEMPLATE_MODAL_PATH', BASE_PATH . '/includes/modal-template.php');
if (!defined('TEMPLATE_CALENDAR_PATH'))   define('TEMPLATE_CALENDAR_PATH', BASE_PATH . '/includes/calendar-template.php');
if (!defined('TEMPLATE_TABLE_PATH'))      define('TEMPLATE_TABLE_PATH', BASE_PATH . '/includes/table-template.php');
if (!defined('ALERT_JS_PATH'))            define('ALERT_JS_PATH', BASE_PATH . '/assets/js/alert.js');
if (!defined('APP_LOGO_FILE'))            define('APP_LOGO_FILE', '/assets/img/logo-wa.png');
if (!defined('APP_LOGO'))                 define('APP_LOGO', BASE_URL . APP_LOGO_FILE);
if (!defined('APP_FAVICON_FILE'))         define('APP_FAVICON_FILE', '/favicon.ico');
if (!defined('APP_FAVICON_PNG_FILE'))     define('APP_FAVICON_PNG_FILE', '/assets/img/logo-wa-icon.png');
if (!defined('APP_FAVICON'))              define('APP_FAVICON', BASE_URL . APP_FAVICON_FILE);

// 10. Autoloader PHP Native untuk Direktori core/
spl_autoload_register(static function (string $className): void {
    $clean = ltrim($className, '\\');
    $parts = explode('\\', $clean);
    $shortName = end($parts);
    $file = BASE_PATH . '/core/' . $shortName . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// 11. Muat Singleton PDO Database
require_once BASE_PATH . '/database.php';

// ==============================================================================
// HELPER SENTRAL — UI, NORMALISASI NOMOR, HMAC, CSRF, SETTING & AUDIT LOG
// ==============================================================================

/**
 * URL logo resmi dengan cache-busting filemtime
 */
function getLogoUrl(): string
{
    $absolutePath = BASE_PATH . APP_LOGO_FILE;
    $version = is_file($absolutePath) ? (string)filemtime($absolutePath) : APP_VERSION;
    return APP_LOGO . '?v=' . $version;
}

/**
 * URL favicon dengan cache-busting filemtime (sinkron dengan logo resmi)
 */
function getFaviconUrl(): string
{
    $absolutePath = BASE_PATH . APP_FAVICON_FILE;
    $version = is_file($absolutePath) ? (string)filemtime($absolutePath) : APP_VERSION;
    return APP_FAVICON . '?v=' . $version;
}

/**
 * Render tag <link> favicon terpusat (ICO + PNG 180x180 + Apple Touch Icon dari logo resmi)
 */
function renderFavicon(): string
{
    $icoUrl  = htmlspecialchars(getFaviconUrl(), ENT_QUOTES, 'UTF-8');
    $pngPath = BASE_PATH . APP_FAVICON_PNG_FILE;
    $pngVer  = is_file($pngPath) ? (string)filemtime($pngPath) : APP_VERSION;
    $pngUrl  = htmlspecialchars(BASE_URL . APP_FAVICON_PNG_FILE . '?v=' . $pngVer, ENT_QUOTES, 'UTF-8');

    return '    <link rel="icon" type="image/x-icon" href="' . $icoUrl . '">' . "\n"
         . '    <link rel="shortcut icon" type="image/x-icon" href="' . $icoUrl . '">' . "\n"
         . '    <link rel="icon" type="image/png" sizes="180x180" href="' . $pngUrl . '">' . "\n"
         . '    <link rel="apple-touch-icon" sizes="180x180" href="' . $pngUrl . '">' . "\n";
}

/**
 * Render script Iconify Solar Icons (deferred)
 */
function renderIconifyScript(): string
{
    return '    <script src="' . htmlspecialchars(ICONIFY_CDN_URL, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
}

/**
 * Pemuat template UI sentral (Modal, Kalender, Tabel Data)
 */
function loadCentralTemplates(array $templates = ['modal', 'calendar', 'table']): void
{
    foreach ($templates as $tpl) {
        switch (strtolower(trim((string)$tpl))) {
            case 'modal':
                if (is_file(TEMPLATE_MODAL_PATH)) {
                    require_once TEMPLATE_MODAL_PATH;
                }
                break;
            case 'calendar':
            case 'kalender':
                if (is_file(TEMPLATE_CALENDAR_PATH)) {
                    require_once TEMPLATE_CALENDAR_PATH;
                }
                break;
            case 'table':
            case 'tabel':
                if (is_file(TEMPLATE_TABLE_PATH)) {
                    require_once TEMPLATE_TABLE_PATH;
                }
                break;
        }
    }
}

/**
 * Normalisasi nomor WhatsApp ke format E.164 tanpa '+' (628xxxxxxxxxx)
 * Sesuai spesifikasi API_INTEGRATION.md §4:
 * 1. Hapus semua karakter non-digit
 * 2. Jika diawali 0 -> ganti dengan 62
 * 3. Jika diawali 8 -> tambahkan prefix 62
 * 4. Validasi panjang 10-15 digit
 */
function wa_normalize_phone(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    // Jika berupa JID personal seperti 6281234567890:12@s.whatsapp.net atau @c.us, ambil bagian sebelum @ dan :
    if (str_contains($raw, '@')) {
        if (wa_is_group_jid($raw)) {
            return '';
        }
        $raw = explode('@', $raw, 2)[0];
        $raw = explode(':', $raw, 2)[0];
    }

    $digits = (string)preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    } elseif (str_starts_with($digits, '8')) {
        $digits = '62' . $digits;
    }

    $len = strlen($digits);
    if ($len < 10 || $len > 15) {
        return '';
    }

    return $digits;
}

/**
 * Deteksi apakah JID adalah grup WhatsApp (@g.us)
 */
function wa_is_group_jid(string $jid): bool
{
    return str_ends_with(strtolower(trim($jid)), '@g.us');
}

/**
 * Ekstrak nomor E.164 dari JID personal (atau kembalikan string kosong jika grup/tidak valid)
 */
function wa_jid_to_phone(string $jid): string
{
    if (wa_is_group_jid($jid)) {
        return '';
    }
    return wa_normalize_phone($jid);
}

/**
 * Konversi nomor atau JID menjadi JID WhatsApp standar
 */
function wa_phone_to_jid(string $phoneOrJid, string $suffix = 's.whatsapp.net'): string
{
    $trimmed = trim($phoneOrJid);
    if ($trimmed === '') {
        return '';
    }
    if (wa_is_group_jid($trimmed)) {
        return $trimmed;
    }
    $phone = wa_normalize_phone($trimmed);
    if ($phone === '') {
        return '';
    }
    $suffix = ltrim($suffix, '@');
    return $phone . '@' . $suffix;
}

/**
 * Pemotongan teks aman UTF-8 (untuk batas karakter tombol/list Meta WhatsApp)
 */
function wa_truncate(string $text, int $maxLength): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($maxLength <= 0) {
        return '';
    }
    if (mb_strlen($text, 'UTF-8') <= $maxLength) {
        return $text;
    }
    if ($maxLength <= 1) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return rtrim(mb_substr($text, 0, $maxLength - 1, 'UTF-8')) . '…';
}

/**
 * Ambil seluruh header HTTP request dalam format lowercase key
 */
function wa_get_request_headers(): array
{
    $headers = [];
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) {
            $headers[strtolower((string)$k)] = is_array($v) ? implode(', ', $v) : (string)$v;
        }
    }
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            if (!isset($headers[$name])) {
                $headers[$name] = (string)$value;
            }
        } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $name = strtolower(str_replace('_', '-', $key));
            if (!isset($headers[$name])) {
                $headers[$name] = (string)$value;
            }
        }
    }
    return $headers;
}

/**
 * Buat header autentikasi HMAC-SHA256 untuk komunikasi WA Bot <-> Ilmuna Learn
 * Kontrak API_INTEGRATION.md §1
 */
function wa_sign_hmac(string $rawBody, ?string $secret = null, ?int $timestamp = null): array
{
    $ts = $timestamp ?? time();
    $key = $secret ?? LEARN_HMAC_SECRET;
    $sig = hash_hmac('sha256', $ts . '.' . $rawBody, $key);

    return [
        'X-Ilmuna-Key'       => WA_BRIDGE_PUBLIC_KEY,
        'X-Ilmuna-Timestamp' => (string)$ts,
        'X-Ilmuna-Signature' => $sig,
    ];
}

/**
 * Verifikasi autentikasi HMAC-SHA256 + Anti-Replay (±300 detik)
 */
function wa_verify_hmac(
    string $timestamp,
    string $rawBody,
    string $signature,
    ?string $secret = null,
    ?string $publicKey = null
): bool {
    if ($publicKey !== null && $publicKey !== '' && !hash_equals(WA_BRIDGE_PUBLIC_KEY, $publicKey)) {
        return false;
    }

    if ($timestamp === '' || !ctype_digit($timestamp)) {
        return false;
    }

    $ts = (int)$timestamp;
    if (abs(time() - $ts) > LEARN_HMAC_MAX_SKEW) {
        return false;
    }

    $sharedSecret = $secret ?? LEARN_HMAC_SECRET;
    if ($sharedSecret === '' || $signature === '') {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $sharedSecret);
    return hash_equals($expected, strtolower(trim($signature)));
}

/**
 * CSRF Token Generator & Verifier untuk Admin Panel
 */
function wa_csrf_token(): string
{
    if (empty($_SESSION['wa_csrf_token'])) {
        $_SESSION['wa_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['wa_csrf_token'];
}

function wa_verify_csrf(?string $token): bool
{
    if ($token === null || $token === '' || empty($_SESSION['wa_csrf_token'])) {
        return false;
    }
    return hash_equals((string)$_SESSION['wa_csrf_token'], $token);
}

/**
 * Lepas exclusive file lock PHP session agar request AJAX & navigasi paralel tidak saling antre
 */
function wa_release_session_lock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/**
 * Standar Respon JSON Seragam (Hukum 6 learn/README.md)
 *
 * @param mixed $data
 */
function wa_json_response(bool $success, string $message, $data = null, int $statusCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    $payload = [
        'success' => $success,
        'message' => $message,
        'data'    => $data ?? new stdClass(),
    ];

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Baca pengaturan global dari tabel wa_settings dengan in-memory cache
 *
 * @param mixed $default
 * @return mixed
 */
function wa_get_setting(string $key, $default = null, ?PDO $pdo = null)
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            $db = $pdo ?? getDBConnection();
            $stmt = $db->query('SELECT setting_key, setting_value FROM wa_settings');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[(string)$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            return $default;
        }
    }

    return array_key_exists($key, $cache) && $cache[$key] !== null
        ? $cache[$key]
        : $default;
}

/**
 * Simpan/perbarui pengaturan ke tabel wa_settings
 */
function wa_set_setting(string $key, string $value, ?PDO $pdo = null): bool
{
    $db = $pdo ?? getDBConnection();
    $stmt = $db->prepare(
        'INSERT INTO wa_settings (setting_key, setting_value)
         VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    return $stmt->execute([':k' => $key, ':v' => $value]);
}

/**
 * Audit Trail pesan masuk & keluar ke tabel wa_message_logs
 */
function wa_log_message(
    PDO $pdo,
    string $direction,
    string $remoteJid,
    string $type = 'text',
    ?string $buttonId = null,
    ?string $preview = null,
    ?string $providerMsgId = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO wa_message_logs
                (direction, remote_jid, message_type, button_id, content_preview, provider_msg_id)
             VALUES
                (:direction, :remote_jid, :message_type, :button_id, :content_preview, :provider_msg_id)'
        );
        $stmt->execute([
            ':direction'       => $direction === 'out' ? 'out' : 'in',
            ':remote_jid'      => mb_substr($remoteJid, 0, 100, 'UTF-8'),
            ':message_type'    => mb_substr($type, 0, 40, 'UTF-8'),
            ':button_id'       => $buttonId !== null ? mb_substr($buttonId, 0, 100, 'UTF-8') : null,
            ':content_preview' => $preview !== null ? mb_substr($preview, 0, 1000, 'UTF-8') : null,
            ':provider_msg_id' => $providerMsgId !== null ? mb_substr($providerMsgId, 0, 120, 'UTF-8') : null,
        ]);
    } catch (Throwable $e) {
        // Jangan gagalkan alur utama bot jika audit log mengalami kendala sementara
    }
}

/**
 * Log sinkronisasi API dua arah Ilmuna Learn <-> WA Bot
 */
function wa_log_sync(
    PDO $pdo,
    string $direction,
    string $eventName,
    ?int $httpStatus = null,
    ?string $reqSummary = null,
    ?string $resSummary = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO wa_learn_sync_logs
                (direction, event_name, http_status, request_summary, response_summary)
             VALUES
                (:direction, :event_name, :http_status, :request_summary, :response_summary)'
        );
        $stmt->execute([
            ':direction'        => $direction === 'wa_to_learn' ? 'wa_to_learn' : 'learn_to_wa',
            ':event_name'       => mb_substr($eventName, 0, 80, 'UTF-8'),
            ':http_status'      => $httpStatus,
            ':request_summary'  => $reqSummary !== null ? mb_substr($reqSummary, 0, 2000, 'UTF-8') : null,
            ':response_summary' => $resSummary !== null ? mb_substr($resSummary, 0, 2000, 'UTF-8') : null,
        ]);
    } catch (Throwable $e) {
        // Non-blocking audit log
    }
}
