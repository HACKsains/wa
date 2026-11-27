<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — CLI / CRON WATCHDOG OTOMATIS WA BRIDGE
 * ==============================================================================
 * Jalankan tiap 1–5 menit melalui Cron cPanel / Windows Task Scheduler:
 *   * * * * * php /path/to/wa-bot/workers/bridge-watchdog.php >> /dev/null 2>&1
 *
 * Fungsi:
 * - Mengecek kesehatan koneksi WA Bridge (lokal maupun remote)
 * - Jika mode lokal (Go-WhatsApp binary / Baileys) mati, PHP otomatis menyalakan ulang
 * - Menyinkronkan status terkini ke tabel wa_devices
 * - Menggunakan file lock anti-tumpuk (flock)
 * ==============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI only.');
}

require_once dirname(__DIR__) . '/config.php';

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ilmuna_wa_bridge_watchdog.lock';
$lockFp = @fopen($lockPath, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'skipped', 'reason' => 'watchdog_already_running']) . PHP_EOL;
    exit(0);
}

try {
    $pdo     = getDBConnection();
    $manager = new BridgeManager($pdo);
    $result  = $manager->ensureRunning();

    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'result'    => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Watchdog error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
