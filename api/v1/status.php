<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — API V1 STATUS (KESEHATAN DEVICE, ANTREAN & INTEGRASI)
 * ==============================================================================
 * Endpoint : GET /api/v1/status.php
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once dirname(__DIR__, 2) . '/config.php';

if (isset($_GET['ping']) && $_GET['ping'] === '1') {
    wa_json_response(true, 'Ilmuna WA Bot online.', [
        'app'       => APP_NAME,
        'version'   => APP_VERSION,
        'driver'    => WA_DRIVER,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
}

$isAdminSession = !empty($_SESSION['wa_admin_logged_in']);
wa_release_session_lock();
$headers        = wa_get_request_headers();
$isHmacValid    = wa_verify_hmac(
    (string)($headers['x-ilmuna-timestamp'] ?? ''),
    '',
    (string)($headers['x-ilmuna-signature'] ?? ''),
    null,
    (string)($headers['x-ilmuna-key'] ?? '')
);

if (!$isAdminSession && !$isHmacValid) {
    wa_json_response(false, 'Autentikasi Admin atau HMAC-SHA256 diperlukan.', [], 401);
}

try {
    $pdo = getDBConnection();

    $devices = $pdo->query(
        'SELECT id, name, driver, phone_number, endpoint_url, status, is_default, last_seen_at FROM wa_devices ORDER BY is_default DESC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $qCounts = $pdo->query('SELECT status, COUNT(*) FROM wa_broadcast_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

    $stats = [
        'calls_today'      => (int)$pdo->query('SELECT COUNT(*) FROM wa_call_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
        'calls_blocked'    => (int)$pdo->query('SELECT COUNT(*) FROM wa_contacts_cache WHERE is_blocked = 1')->fetchColumn(),
        'violations_today' => (int)$pdo->query('SELECT COUNT(*) FROM wa_violation_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
        'messages_today'   => (int)$pdo->query('SELECT COUNT(*) FROM wa_message_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
        'groups_monitored' => (int)$pdo->query('SELECT COUNT(*) FROM wa_groups WHERE guard_enabled = 1')->fetchColumn(),
        'contacts_cached'  => (int)$pdo->query('SELECT COUNT(*) FROM wa_contacts_cache')->fetchColumn(),
        'queue_pending'    => (int)($qCounts['pending'] ?? 0),
        'queue_sending'    => (int)($qCounts['sending'] ?? 0),
        'queue_sent'       => (int)($qCounts['sent'] ?? 0),
        'queue_failed'     => (int)($qCounts['failed'] ?? 0),
    ];

    $bridgeMgr = new BridgeManager($pdo);

    wa_json_response(true, 'Status sistem Ilmuna WA Bot berhasil dimuat.', [
        'driver_mode' => (string)wa_get_setting('wa_driver', WA_DRIVER, $pdo),
        'devices'     => $devices,
        'stats'       => $stats,
        'environment' => $bridgeMgr->detectEnvironment(),
        'server_time' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    wa_json_response(false, 'Galat status API: ' . $e->getMessage(), [], 500);
}
