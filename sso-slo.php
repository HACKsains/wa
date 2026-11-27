<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — MARGO ONE BACK-CHANNEL SINGLE LOGOUT (SLO) WEBHOOK
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wa_json_response(false, 'Gunakan metode POST.', [], 405);
}

try {
    $rawBody = (string)file_get_contents('php://input');
    $headers = wa_get_request_headers();
    $ts      = (string)($headers['x-ilmuna-timestamp'] ?? '');
    $sig     = (string)($headers['x-ilmuna-signature'] ?? '');
    $key     = (string)($headers['x-ilmuna-key'] ?? '');

    $valid = ($key !== '' && hash_equals(ILMUNA_SSO_PUBLIC_KEY, $key))
        && $ts !== '' && ctype_digit($ts) && abs(time() - (int)$ts) <= 300
        && hash_equals(hash_hmac('sha256', $ts . '.' . $rawBody, ILMUNA_SSO_PRIVATE_KEY), strtolower($sig));

    if (!$valid) {
        wa_json_response(false, 'Signature SLO Margo One tidak valid.', [], 401);
    }

    $pdo = getDBConnection();
    wa_log_sync($pdo, 'learn_to_wa', 'sso_slo_received', 200, mb_substr($rawBody, 0, 300, 'UTF-8'), 'SLO acknowledged');
    wa_json_response(true, 'Single Logout diterima.', ['acknowledged' => true]);
} catch (Throwable $e) {
    wa_json_response(false, 'Galat SLO: ' . $e->getMessage(), [], 500);
}
