<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — BRIDGE CONTROL & QR / PAIRING CODE API (AJAX / HMAC)
 * ==============================================================================
 * Endpoint: /api/v1/bridge.php?action=status|env|start|stop|restart|qr|pairing|logs
 * Dipakai oleh Admin Panel (dashboard/devices.php) maupun integrasi HMAC Learn.
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once dirname(__DIR__, 2) . '/config.php';

// Autentikasi: boleh dari sesi Admin yang sudah login ATAU header HMAC-SHA256 valid
$isAdminSession = !empty($_SESSION['wa_admin_logged_in']);
$headers        = wa_get_request_headers();
$rawBody        = (string)file_get_contents('php://input');
$isHmacValid    = wa_verify_hmac(
    (string)($headers['x-ilmuna-timestamp'] ?? ''),
    $rawBody,
    (string)($headers['x-ilmuna-signature'] ?? ''),
    null,
    (string)($headers['x-ilmuna-key'] ?? '')
);

if (!$isAdminSession && !$isHmacValid) {
    wa_json_response(false, 'Akses ditolak: autentikasi Admin atau HMAC-SHA256 diperlukan.', [], 401);
}

$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'status')));

try {
    $pdo     = getDBConnection();
    $manager = new BridgeManager($pdo);

    switch ($action) {
        case 'env':
            wa_json_response(true, 'Diagnosa lingkungan server berhasil.', $manager->detectEnvironment());

        case 'status':
            wa_json_response(true, 'Status koneksi WA Bridge berhasil dimuat.', $manager->getStatus());

        case 'start':
            $res = $manager->ensureRunning();
            wa_json_response((bool)$res['success'], (string)$res['message'], $res, $res['success'] ? 200 : 422);

        case 'stop':
            $res = $manager->stop();
            wa_json_response((bool)$res['success'], (string)$res['message'], $res);

        case 'restart':
            $manager->stop();
            usleep(400_000);
            $res = $manager->start();
            wa_json_response((bool)$res['success'], (string)$res['message'], $res, $res['success'] ? 200 : 422);

        case 'qr':
            $res = $manager->getQrCode(true);
            wa_json_response((bool)($res['success'] ?? false), (string)($res['error'] ?? 'QR siap dipindai.'), $res);

        case 'pairing':
            $bodyData = json_decode($rawBody, true);
            $phone = (string)($_POST['phone'] ?? $_GET['phone'] ?? (is_array($bodyData) ? ($bodyData['phone'] ?? '') : ''));
            $res = $manager->requestPairingCode($phone, true);
            wa_json_response(
                (bool)($res['success'] ?? false),
                !empty($res['pairing_code'])
                    ? 'Pairing Code berhasil dibuat. Masukkan kode di WhatsApp → Perangkat Tertaut → Tautkan dengan nomor telepon.'
                    : (string)($res['error'] ?? 'Gagal membuat Pairing Code.'),
                $res,
                !empty($res['success']) ? 200 : 422
            );

        case 'logs':
            wa_json_response(true, 'Log bridge berhasil diambil.', ['logs' => $manager->tailLog(60)]);

        default:
            wa_json_response(false, "Aksi '{$action}' tidak dikenali.", [], 400);
    }
} catch (Throwable $e) {
    wa_json_response(false, 'Galat Bridge API: ' . $e->getMessage(), [], 500);
}
