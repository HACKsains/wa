<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — PUBLIC WEBHOOK RECEIVER ENDPOINT
 * ==============================================================================
 * Menerima:
 * - GET  : Verifikasi challenge Meta WhatsApp Cloud API (hub.mode & hub.verify_token)
 * - POST : Event pesan masuk, klik tombol/list, pesan grup, panggilan masuk (Anti-Call),
 *          dan status session dari Meta Cloud API maupun Self-Hosted MD Bridge
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/config.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    $pdo       = getDBConnection();
    $transport = TransportFactory::create($pdo);
    $processor = new WebhookProcessor($pdo, $transport);

    // 1. Meta WhatsApp Cloud API Webhook Verification (GET)
    if ($method === 'GET') {
        $check = $processor->verifyMetaChallenge($_GET);
        if ($check['valid']) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo $check['challenge'];
            exit;
        }
        wa_json_response(false, $check['message'], [], 403);
    }

    // 2. Event Webhook Masuk (POST)
    if ($method === 'POST') {
        $rawBody = (string)file_get_contents('php://input');
        $headers = wa_get_request_headers();
        $result  = $processor->processRawWebhook($rawBody, $headers);

        wa_json_response(
            (bool)$result['success'],
            (string)$result['message'],
            $result['data'] ?? [],
            (int)($result['http_status'] ?? 200)
        );
    }

    wa_json_response(false, "HTTP method {$method} tidak diizinkan.", [], 405);
} catch (Throwable $e) {
    wa_json_response(false, 'Kesalahan internal webhook: ' . $e->getMessage(), [], 500);
}
