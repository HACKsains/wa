<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — ACADEMIC H-1 & H-0 REMINDER SCHEDULER (CLI / CRON 15 MENIT)
 * ==============================================================================
 * Jalankan tiap 15 menit via Cron cPanel / Windows Task Scheduler:
 *   *\/15 * * * * /usr/local/bin/php /home/uofruaju/wa.ilmuna.my.id/workers/reminder-scheduler.php >> /dev/null 2>&1
 *
 * Tugas:
 * - Menarik Tugas, Proyek, Agenda/Kaldik, & Asesmen CBT yang mendekati tenggat
 *   (H-1 & H-0) dari Ilmuna Learn via HMAC-SHA256
 * - Menjadwalkan kampanye pengingat otomatis ke siswa, orang tua, & grup kelas
 *   secara 100% idempoten (anti-duplikat)
 * ==============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI scheduler only.');
}

require_once dirname(__DIR__) . '/config.php';

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ilmuna_wa_reminder_scheduler.lock';
$lockFp = @fopen($lockPath, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'skipped', 'reason' => 'scheduler_already_running']) . PHP_EOL;
    exit(0);
}

try {
    $pdo       = getDBConnection();
    $transport = TransportFactory::create($pdo);
    $engine    = new BroadcastEngine($pdo, $transport);
    $result    = $engine->runReminderScan();

    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'result'    => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Reminder scheduler error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
