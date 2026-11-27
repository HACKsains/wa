<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — BRIDGE CONTROL, AUTO-INSTALLER NODE/BAILEYS & QR API
 * ==============================================================================
 * Endpoint:
 *   /api/v1/bridge.php?action=status|env|install_all|install_node|install_baileys|
 *                             install_status|start|stop|restart|reset_session|
 *                             qr|pairing|logs
 * Dipakai oleh Admin Panel (dashboard/devices.php) maupun integrasi HMAC Learn.
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once dirname(__DIR__, 2) . '/config.php';

// Autentikasi: boleh dari sesi Admin yang sudah login ATAU header HMAC-SHA256 valid
$isAdminSession = !empty($_SESSION['wa_admin_logged_in']);
// Lepas session file lock segera agar polling QR / status / navigasi tidak saling memblokir!
wa_release_session_lock();
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

// Simpan BASE_URL publik aktual agar Cron Job CLI tahu URL webhook HTTPS produksi
try {
    $pdo = getDBConnection();
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $currPublic = rtrim(BASE_URL, '/');
        if (wa_get_setting('app_public_url', '', $pdo) !== $currPublic) {
            wa_set_setting('app_public_url', $currPublic, $pdo);
        }
    }

    $manager = new BridgeManager($pdo);

    switch ($action) {
        case 'env':
            wa_json_response(true, 'Diagnosa lingkungan server berhasil.', $manager->detectEnvironment());

        case 'status':
            wa_json_response(true, 'Status koneksi WA Bridge berhasil dimuat.', $manager->getStatus());

        case 'install_all':
            $res = $manager->installAll(true, true, false);
            wa_json_response((bool)$res['success'], (string)$res['message'], $res, $res['success'] ? 200 : 422);

        case 'upgrade_node':
            $res = $manager->installAll(true, true, true);
            wa_json_response((bool)$res['success'], (string)$res['message'], $res, $res['success'] ? 200 : 422);

        case 'install_node':
            $res = $manager->installPortableNode(false);
            wa_json_response((bool)$res['success'], (string)$res['message'], $res, $res['success'] ? 200 : 422);

        case 'install_baileys':
            $res = $manager->installBaileysDependencies(true);
            wa_json_response((bool)$res['success'], (string)$res['message'], $res, $res['success'] ? 200 : 422);

        case 'install_status':
            wa_json_response(true, 'Status progres instalasi dimuat.', $manager->getInstallStatus());

        case 'start':
            $res = $manager->ensureRunning();
            wa_json_response((bool)$res['success'], (string)$res['message'], $res, $res['success'] ? 200 : 422);

        case 'stop':
            $res = $manager->stop();
            wa_json_response((bool)$res['success'], (string)$res['message'], $res);

        case 'restart':
            $manager->stop();
            usleep(400000);
            $res = $manager->start();
            wa_json_response((bool)$res['success'], (string)$res['message'], $res, $res['success'] ? 200 : 422);

        case 'reset_session':
            $res = $manager->resetSession();
            wa_json_response((bool)$res['success'], (string)$res['message'], $res, $res['success'] ? 200 : 422);

        case 'qr':
            $res = $manager->getQrCode(true);
            $msg = !empty($res['qr_data_url'])
                ? 'QR WhatsApp siap dipindai.'
                : (($res['status'] ?? '') === 'connected'
                    ? 'WhatsApp sudah terhubung.'
                    : (string)($res['error'] ?? $res['message'] ?? 'Menunggu Baileys menghasilkan QR Code (tunggu 3–5 detik lalu coba lagi)...'));
            wa_json_response((bool)($res['success'] ?? false), $msg, $res);

        case 'pairing':
            $bodyData = json_decode($rawBody, true);
            $phone = (string)($_POST['phone'] ?? $_GET['phone'] ?? (is_array($bodyData) ? ($bodyData['phone'] ?? '') : ''));
            $res = $manager->requestPairingCode($phone, true);
            wa_json_response(
                (bool)($res['success'] ?? false),
                !empty($res['pairing_code'])
                    ? 'Pairing Code berhasil dibuat. Masukkan kode di WhatsApp → Perangkat Tertaut → Tautkan dengan nomor telepon.'
                    : (string)($res['error'] ?? $res['message'] ?? 'Gagal membuat Pairing Code.'),
                $res,
                !empty($res['success']) ? 200 : 422
            );

        case 'logs':
            wa_json_response(true, 'Log bridge & installer berhasil diambil.', [
                'bridge_log'    => $manager->tailLog(60),
                'installer_log' => $manager->tailInstallerLog(80),
            ]);

        default:
            wa_json_response(false, "Aksi '{$action}' tidak dikenali.", [], 400);
    }
} catch (Throwable $e) {
    wa_json_response(false, 'Galat Bridge API: ' . $e->getMessage(), [], 500);
}
