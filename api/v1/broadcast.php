<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — API V1 BROADCAST (TRIGGER & MANAJEMEN KAMPANYE BROADCAST)
 * ==============================================================================
 * Endpoint : GET/POST /api/v1/broadcast.php
 * Keamanan : Sesi Admin Panel ATAU HMAC-SHA256 Ilmuna Learn
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once dirname(__DIR__, 2) . '/config.php';

$isAdminSession = !empty($_SESSION['wa_admin_logged_in']);
$rawBody        = (string)file_get_contents('php://input');
$headers        = wa_get_request_headers();
$isHmacValid    = wa_verify_hmac(
    (string)($headers['x-ilmuna-timestamp'] ?? ''),
    $rawBody,
    (string)($headers['x-ilmuna-signature'] ?? ''),
    null,
    (string)($headers['x-ilmuna-key'] ?? '')
);

if (!$isAdminSession && !$isHmacValid) {
    wa_json_response(false, 'Autentikasi Admin atau HMAC-SHA256 diperlukan.', [], 401);
}

try {
    $pdo       = getDBConnection();
    $transport = TransportFactory::create($pdo);
    $engine    = new BroadcastEngine($pdo, $transport);
    $method    = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $statusFilter = trim((string)($_GET['status'] ?? 'all'));
        $sql = 'SELECT * FROM wa_broadcast_campaigns';
        $params = [];
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $sql .= ' WHERE status = :st';
            $params[':st'] = $statusFilter;
        }
        $sql .= ' ORDER BY id DESC LIMIT 100';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $qStats = $pdo->query('SELECT status, COUNT(*) FROM wa_broadcast_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

        wa_json_response(true, 'Daftar kampanye broadcast berhasil dimuat.', [
            'campaigns' => $campaigns,
            'queue'     => [
                'pending' => (int)($qStats['pending'] ?? 0),
                'sending' => (int)($qStats['sending'] ?? 0),
                'sent'    => (int)($qStats['sent'] ?? 0),
                'failed'  => (int)($qStats['failed'] ?? 0),
            ],
        ]);
    }

    if ($method === 'POST') {
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        // Validasi CSRF jika dipanggil via sesi browser admin
        if ($isAdminSession && !$isHmacValid) {
            $csrf = (string)($headers['x-csrf-token'] ?? $input['csrf_token'] ?? '');
            if (!wa_verify_csrf($csrf)) {
                wa_json_response(false, 'Token CSRF tidak valid.', [], 403);
            }
        }

        $action = strtolower(trim((string)($input['action'] ?? 'create')));

        if ($action === 'create') {
            $input['created_by'] = $isAdminSession ? (string)($_SESSION['wa_admin_name'] ?? 'admin') : 'api_hmac';
            $res = $engine->createCampaign($input);
            wa_json_response((bool)$res['success'], $res['message'] ?? 'Kampanye broadcast dibuat.', $res, $res['success'] ? 200 : 422);
        }

        if ($action === 'academic') {
            $res = $engine->createAcademicBroadcast($input);
            wa_json_response((bool)$res['success'], 'Auto-broadcast akademik diproses.', $res, $res['success'] ? 200 : 422);
        }

        if ($action === 'process_now') {
            $batch = max(1, min(25, (int)($input['batch_size'] ?? 10)));
            $res = $engine->processQueueBatch($batch, 1, 2);
            wa_json_response(true, "Batch diproses: {$res['sent']} terkirim, {$res['retried']} diulang, {$res['failed']} gagal.", $res);
        }

        if (in_array($action, ['pause', 'resume', 'cancel'], true)) {
            $cid = (int)($input['campaign_id'] ?? 0);
            if ($cid <= 0) {
                wa_json_response(false, 'campaign_id tidak valid.', [], 422);
            }
            $newStatus = match ($action) {
                'pause'  => 'paused',
                'resume' => 'scheduled',
                'cancel' => 'cancelled',
            };
            $stmt = $pdo->prepare('UPDATE wa_broadcast_campaigns SET status = :st WHERE id = :id');
            $stmt->execute([':st' => $newStatus, ':id' => $cid]);
            wa_json_response(true, "Status kampanye #{$cid} diubah menjadi {$newStatus}.", ['campaign_id' => $cid, 'status' => $newStatus]);
        }

        wa_json_response(false, "Aksi '{$action}' tidak dikenali.", [], 400);
    }

    wa_json_response(false, "Method {$method} tidak didukung.", [], 405);
} catch (Throwable $e) {
    wa_json_response(false, 'Galat Broadcast API: ' . $e->getMessage(), [], 500);
}
