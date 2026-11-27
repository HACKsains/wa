<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — API V1 NOTIFY (PENERIMA PUSH EVENT DARI ILMUNA LEARN)
 * ==============================================================================
 * Endpoint : POST /api/v1/notify.php
 * Keamanan : Wajib HMAC-SHA256 + Anti-Replay (±300 detik) sesuai API_INTEGRATION.md
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once dirname(__DIR__, 2) . '/config.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wa_json_response(false, 'Gunakan metode POST.', [], 405);
}

$rawBody = (string)file_get_contents('php://input');
$headers = wa_get_request_headers();

$valid = wa_verify_hmac(
    (string)($headers['x-ilmuna-timestamp'] ?? ''),
    $rawBody,
    (string)($headers['x-ilmuna-signature'] ?? ''),
    null,
    (string)($headers['x-ilmuna-key'] ?? '')
);

if (!$valid) {
    wa_json_response(false, 'Autentikasi HMAC-SHA256 tidak valid atau timestamp kedaluwarsa.', [], 401);
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    wa_json_response(false, 'Payload JSON tidak valid.', [], 400);
}

try {
    $pdo       = getDBConnection();
    $transport = TransportFactory::create($pdo);
    $broadcast = new BroadcastEngine($pdo, $transport);
    $handler   = new NotifyHandler($pdo, $broadcast);

    $res = $handler->handleEvent($data, true);
    wa_json_response((bool)$res['success'], (string)$res['message'], $res['data'] ?? [], (int)($res['http_status'] ?? 200));
} catch (Throwable $e) {
    wa_json_response(false, 'Galat internal notify API: ' . $e->getMessage(), [], 500);
}
