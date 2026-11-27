<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — BROADCAST QUEUE WORKER (CLI / CRON ANTI-BANNED)
 * ==============================================================================
 * Jalankan tiap 1 menit via Cron cPanel / Windows Task Scheduler:
 *   * * * * * /usr/local/bin/php /home/uofruaju/wa.ilmuna.my.id/workers/broadcast-worker.php >> /dev/null 2>&1
 *
 * Opsi CLI:
 *   --batch=25      Override jumlah pesan per batch
 *   --no-delay      Jalankan tanpa jeda tidur (khusus simulasi uji)
 *   --status        Tampilkan statistik antrean saat ini tanpa mengirim
 * ==============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI worker only.');
}

require_once dirname(__DIR__) . '/config.php';

$args = array_slice($argv, 1);
$batchOverride = null;
$noDelay       = in_array('--no-delay', $args, true);
$statusOnly    = in_array('--status', $args, true);

foreach ($args as $a) {
    if (str_starts_with($a, '--batch=')) {
        $batchOverride = max(1, (int)substr($a, 8));
    }
}

$pdo = getDBConnection();

if ($statusOnly) {
    $rows = $pdo->query(
        'SELECT status, COUNT(*) AS total FROM wa_broadcast_queue GROUP BY status'
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'queue'     => [
            'pending' => (int)($rows['pending'] ?? 0),
            'sending' => (int)($rows['sending'] ?? 0),
            'sent'    => (int)($rows['sent'] ?? 0),
            'failed'  => (int)($rows['failed'] ?? 0),
        ],
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

// File lock anti-tumpuk (mencegah 2 cron berjalan bersamaan)
$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ilmuna_wa_broadcast_worker.lock';
$lockFp = @fopen($lockPath, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'skipped', 'reason' => 'worker_already_running']) . PHP_EOL;
    exit(0);
}

try {
    $transport = TransportFactory::create($pdo);
    $engine    = new BroadcastEngine($pdo, $transport);
    $result    = $engine->processQueueBatch(
        $batchOverride,
        $noDelay ? 0 : null,
        $noDelay ? 0 : null
    );

    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'status'    => 'ok',
        'result'    => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Broadcast worker error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
