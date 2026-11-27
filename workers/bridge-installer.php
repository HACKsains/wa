<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — CLI / CRON AUTO-INSTALLER NODE.JS PORTABLE + BAILEYS
 * ==============================================================================
 * Dijalankan otomatis di background oleh BridgeManager::installAll() ATAU
 * cukup ditempel sekali di cPanel → Cron Jobs (tanpa SSH / Terminal!):
 *
 *   /usr/local/bin/php /home/uofruaju/wa.ilmuna.my.id/workers/bridge-installer.php --auto-start
 *
 * Tugas idempoten (aman dijalankan berulang kali):
 * 1. Unduh & ekstrak Node.js Portable resmi (+ auto-fallback glibc-217 utk CloudLinux 7)
 * 2. Pasang @whiskeysockets/baileys, pino, dan qrcode di bridge/node_modules
 * 3. Jalankan daemon bridge/server.js agar langsung siap mengeluarkan QR / Pairing 8-Digit
 * ==============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI/Cron only. Gunakan tombol Auto-Install di Dashboard Admin Devices.');
}

require_once dirname(__DIR__) . '/config.php';

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ilmuna_wa_bridge_installer.lock';
$lockFp   = @fopen($lockPath, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'skipped', 'reason' => 'installer_already_running']) . PHP_EOL;
    exit(0);
}

$args      = array_slice($argv ?? [], 1);
$autoStart = !in_array('--no-start', $args, true);

try {
    $pdo     = getDBConnection();
    $manager = new BridgeManager($pdo);
    $result  = $manager->runFullInstallSync($autoStart);

    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'result'    => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

    exit(!empty($result['success']) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, '[Installer Fatal] ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
