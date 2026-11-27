<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — PHP AUTOMATIC BRIDGE MANAGER & HOSTING ENVIRONMENT DETECTOR
 * ==============================================================================
 * Memungkinkan PHP mengendalikan siklus hidup WA Bridge secara otomatis tanpa
 * admin perlu membuka terminal manual:
 *
 * 1. Mode Binary Tunggal Go-WhatsApp (`bridge/bin/whatsapp` / `whatsapp.exe`):
 *    → ZERO Node.js! Cocok untuk server Linux/cPanel yang tidak punya Node.js
 *      namun mengizinkan exec/proc_open + Cron Watchdog.
 * 2. Mode Local Baileys Bridge (`bridge/server.js`):
 *    → Jika `node` tersedia (Laragon / VPS / PC Sekolah), PHP otomatis menjalankan
 *      dan menjaga daemon tetap hidup di background.
 * 3. Mode Pure PHP Hosting Terbatas (cPanel tanpa Node.js & tanpa exec/daemon):
 *    → Mendeteksi otomatis keterbatasan hosting dan mengarahkan koneksi lewat
 *      HTTP REST (Meta WhatsApp Cloud API Resmi / Fonnte / Whapi / Remote Bridge
 *      via Cloudflare Tunnel) tanpa error fatal!
 * ==============================================================================
 */

class BridgeManager
{
    private PDO $pdo;
    private string $bridgeDir;
    private string $runtimeDir;
    private string $pidFile;
    private string $logFile;
    private string $baseUrl;
    private int $port;
    private string $session;
    private string $webhookSecret;
    private string $apiKey;

    public function __construct(?PDO $pdo = null, array $config = [])
    {
        $this->pdo           = $pdo ?? getDBConnection();
        $this->bridgeDir     = rtrim((string)($config['bridge_dir'] ?? (BASE_PATH . '/bridge')), '/\\');
        $this->runtimeDir    = $this->bridgeDir . DIRECTORY_SEPARATOR . 'runtime';
        $this->pidFile       = $this->runtimeDir . DIRECTORY_SEPARATOR . 'bridge.pid';
        $this->logFile       = $this->runtimeDir . DIRECTORY_SEPARATOR . 'bridge.log';
        $this->baseUrl       = rtrim((string)($config['base_url'] ?? WA_BRIDGE_BASE_URL), '/');
        $this->port          = (int)(parse_url($this->baseUrl, PHP_URL_PORT) ?: 3000);
        $this->session       = (string)($config['session'] ?? WA_BRIDGE_SESSION);
        $this->webhookSecret = (string)($config['webhook_secret'] ?? WA_BRIDGE_WEBHOOK_SECRET);
        $this->apiKey        = (string)($config['api_key'] ?? WA_BRIDGE_API_KEY);
    }

    /**
     * Diagnosa kemampuan server (Windows Laragon / Linux VPS / Shared Hosting cPanel)
     */
    public function detectEnvironment(): array
    {
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $disabled  = array_map(
            'trim',
            explode(',', strtolower((string)ini_get('disable_functions')))
        );

        $execAllowed = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
        $shellExecAllowed = function_exists('exec') && !in_array('exec', $disabled, true);
        $canSpawn = $execAllowed || $shellExecAllowed;

        $goBinary = $this->findGoWhatsAppBinary($isWindows);
        $nodeBinary = $this->findNodeBinary($isWindows);
        $nodeServerExists = is_file($this->bridgeDir . DIRECTORY_SEPARATOR . 'server.js');
        $nodeModulesReady = is_dir($this->bridgeDir . DIRECTORY_SEPARATOR . 'node_modules');

        $isLocalTarget = $this->isLocalhostEndpoint($this->baseUrl);

        // Tentukan strategi terbaik otomatis berdasarkan kapabilitas server
        if (!$isLocalTarget) {
            $recommended = 'remote_http_bridge';
            $reason = 'Endpoint bridge mengarah ke server eksternal / Cloud Gateway (100% PHP cURL, tidak butuh Node.js di hosting).';
        } elseif ($canSpawn && $goBinary !== null) {
            $recommended = 'go_binary';
            $reason = 'Binary tunggal Go-WhatsApp ditemukan (ZERO Node.js) dan PHP dapat menjalankannya otomatis.';
        } elseif ($canSpawn && $nodeBinary !== null && $nodeServerExists) {
            $recommended = 'node_baileys';
            $reason = $nodeModulesReady
                ? 'Node.js & Baileys Bridge lokal siap dijalankan otomatis oleh PHP.'
                : 'Node.js ditemukan; jalankan `npm install` di folder bridge/ agar siap auto-start oleh PHP.';
        } else {
            $recommended = 'cloud_api_or_external_gateway';
            $reason = 'Hosting shared tidak memiliki Node.js / binary lokal atau menonaktifkan proc_open. Gunakan Meta WhatsApp Cloud API Resmi (100% PHP) atau Gateway HTTP (Whapi/Fonnte/Bridge di PC Sekolah via Cloudflare Tunnel).';
        }

        return [
            'os'                  => $isWindows ? 'windows' : 'linux',
            'php_version'         => PHP_VERSION,
            'curl_loaded'         => extension_loaded('curl'),
            'can_spawn_process'   => $canSpawn,
            'is_local_endpoint'   => $isLocalTarget,
            'go_binary'           => $goBinary,
            'node_binary'         => $nodeBinary,
            'node_server_exists'  => $nodeServerExists,
            'node_modules_ready'  => $nodeModulesReady,
            'recommended_mode'    => $recommended,
            'recommendation_note' => $reason,
            'target_base_url'     => $this->baseUrl,
        ];
    }

    /**
     * Cek apakah service bridge merespon HTTP / status sesi terkini
     */
    public function getStatus(): array
    {
        $env = $this->detectEnvironment();
        $driver = $this->buildBridgeDriver($env);
        $health = $driver->healthCheck();

        $pid = $this->readPid();
        $pidAlive = $pid !== null && $this->isPidRunning($pid);

        // Sinkronisasi status terbaru ke tabel wa_devices
        $this->syncDeviceStatus($health['status'] ?? 'disconnected');

        return [
            'running'     => (bool)($health['success'] ?? false),
            'wa_status'   => (string)($health['status'] ?? 'disconnected'),
            'driver'      => $driver->getFlavor(),
            'base_url'    => $this->baseUrl,
            'pid'         => $pidAlive ? $pid : null,
            'pid_alive'   => $pidAlive,
            'http_status' => (int)($health['http_status'] ?? 0),
            'error'       => $health['error'] ?? null,
            'environment' => $env,
        ];
    }

    /**
     * Pastikan bridge berjalan otomatis: jika belum aktif dan server mendukung spawn, nyalakan!
     */
    public function ensureRunning(): array
    {
        $status = $this->getStatus();
        if ($status['running']) {
            return [
                'success' => true,
                'action'  => 'already_running',
                'message' => 'WA Bridge sudah aktif dan merespon.',
                'status'  => $status,
            ];
        }

        return $this->start();
    }

    /**
     * Jalankan WA Bridge secara otomatis dari PHP (mendukung Go single-binary TANPA Node.js, maupun Baileys)
     */
    public function start(): array
    {
        $env = $this->detectEnvironment();

        if (!$env['is_local_endpoint']) {
            return [
                'success' => false,
                'action'  => 'remote_managed',
                'message' => 'Endpoint bridge mengarah ke host remote (' . $this->baseUrl . '). Start proses dilakukan pada server remote/gateway.',
                'env'     => $env,
            ];
        }

        if (!$env['can_spawn_process']) {
            return [
                'success' => false,
                'action'  => 'hosting_restricted',
                'message' => 'Hosting menonaktifkan proc_open/exec. Gunakan Meta WhatsApp Cloud API atau Gateway HTTP eksternal di halaman Devices (100% PHP tanpa daemon).',
                'env'     => $env,
            ];
        }

        if (!is_dir($this->runtimeDir)) {
            @mkdir($this->runtimeDir, 0755, true);
        }

        $webhookUrl = rtrim(BASE_URL, '/') . '/webhook.php';
        $isWindows  = $env['os'] === 'windows';
        $modeUsed   = '';
        $pid        = null;

        // Prioritas 1: Go-WhatsApp Single Binary (ZERO Node.js — sangat hemat RAM!)
        if ($env['go_binary'] !== null) {
            $modeUsed = 'go_binary';
            $cmd = sprintf(
                '"%s" rest --port=%d --webhook="%s" --webhook-secret="%s"',
                $env['go_binary'],
                $this->port,
                $webhookUrl,
                $this->webhookSecret
            );
            $pid = $this->spawnDetachedProcess($cmd, $this->bridgeDir, $isWindows);
            $this->updateBridgeFlavor('gowa');
        }
        // Prioritas 2: Baileys Bridge lokal jika Node.js tersedia
        elseif ($env['node_binary'] !== null && $env['node_server_exists']) {
            if (!$env['node_modules_ready']) {
                return [
                    'success' => false,
                    'action'  => 'deps_missing',
                    'message' => 'Dependensi bridge belum terpasang. Jalankan sekali: `cd "C:\laragon\www\wa bot\bridge" && npm install` lalu klik Start lagi.',
                    'env'     => $env,
                ];
            }
            $modeUsed = 'node_baileys';
            $envVars = [
                'BRIDGE_PORT'           => (string)$this->port,
                'BRIDGE_SESSION'        => $this->session,
                'BRIDGE_API_KEY'        => $this->apiKey,
                'BRIDGE_WEBHOOK_URL'    => $webhookUrl,
                'BRIDGE_WEBHOOK_SECRET' => $this->webhookSecret,
            ];
            $serverJs = $this->bridgeDir . DIRECTORY_SEPARATOR . 'server.js';
            $cmd = sprintf('"%s" "%s"', $env['node_binary'], $serverJs);
            $pid = $this->spawnDetachedProcess($cmd, $this->bridgeDir, $isWindows, $envVars);
            $this->updateBridgeFlavor('generic');
        } else {
            return [
                'success' => false,
                'action'  => 'no_runtime_found',
                'message' => 'Tidak ditemukan binary Go-WhatsApp (bridge/bin/whatsapp) maupun Node.js di server ini. ' . $env['recommendation_note'],
                'env'     => $env,
            ];
        }

        if ($pid !== null) {
            @file_put_contents($this->pidFile, (string)$pid, LOCK_EX);
        }

        // Tunggu sebentar (maks 3 detik) hingga port HTTP merespon
        $ready = false;
        for ($i = 0; $i < 6; $i++) {
            usleep(500_000);
            if ($this->isPortOpen('127.0.0.1', $this->port)) {
                $ready = true;
                break;
            }
        }

        return [
            'success' => $ready || $pid !== null,
            'action'  => 'started',
            'runtime' => $modeUsed,
            'pid'     => $pid,
            'ready'   => $ready,
            'message' => $ready
                ? "WA Bridge ({$modeUsed}) berhasil dijalankan otomatis oleh PHP pada port {$this->port}."
                : "Proses WA Bridge ({$modeUsed}) telah dipicu oleh PHP (PID: " . ($pid ?? 'n/a') . '). Periksa log jika belum merespon dalam beberapa detik.',
            'log_file' => $this->logFile,
        ];
    }

    /**
     * Hentikan proses WA Bridge lokal yang dijalankan oleh PHP
     */
    public function stop(): array
    {
        $pid = $this->readPid();
        if ($pid === null) {
            return ['success' => true, 'message' => 'Tidak ada PID WA Bridge lokal yang tercatat aktif.'];
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        if ($isWindows) {
            @exec("taskkill /F /T /PID {$pid} >NUL 2>&1");
        } else {
            @exec("kill -TERM {$pid} >/dev/null 2>&1");
        }

        if (is_file($this->pidFile)) {
            @unlink($this->pidFile);
        }
        $this->syncDeviceStatus('disconnected');

        return [
            'success' => true,
            'message' => "Proses WA Bridge (PID {$pid}) berhasil dihentikan.",
        ];
    }

    /**
     * Ambil QR Code (auto-start bila bridge lokal belum menyala)
     */
    public function getQrCode(bool $autoStart = true): array
    {
        if ($autoStart && $this->isLocalhostEndpoint($this->baseUrl) && !$this->isPortOpen('127.0.0.1', $this->port)) {
            $startRes = $this->start();
            if (!$startRes['success']) {
                return $startRes;
            }
        }

        $env = $this->detectEnvironment();
        return $this->buildBridgeDriver($env)->getQrCode();
    }

    /**
     * Minta Pairing Code 8 digit (auto-start bila bridge lokal belum menyala)
     */
    public function requestPairingCode(string $phoneNumber, bool $autoStart = true): array
    {
        if ($autoStart && $this->isLocalhostEndpoint($this->baseUrl) && !$this->isPortOpen('127.0.0.1', $this->port)) {
            $startRes = $this->start();
            if (!$startRes['success']) {
                return $startRes;
            }
        }

        $env = $this->detectEnvironment();
        return $this->buildBridgeDriver($env)->requestPairingCode($phoneNumber);
    }

    /**
     * Baca 40 baris terakhir log bridge untuk debugging cepat dari Dashboard Admin
     */
    public function tailLog(int $lines = 40): string
    {
        if (!is_file($this->logFile)) {
            return '';
        }
        $all = @file($this->logFile, FILE_IGNORE_NEW_LINES) ?: [];
        return implode("\n", array_slice($all, -max(1, $lines)));
    }

    private function buildBridgeDriver(array $env): BridgeMdDriver
    {
        $flavor = (string)wa_get_setting('wa_bridge_flavor', WA_BRIDGE_FLAVOR, $this->pdo);
        if ($flavor === 'generic' && $env['recommended_mode'] === 'go_binary') {
            $flavor = 'gowa';
        }

        return new BridgeMdDriver([
            'base_url'       => $this->baseUrl,
            'api_key'        => $this->apiKey,
            'session'        => $this->session,
            'webhook_secret' => $this->webhookSecret,
            'flavor'         => $flavor,
        ]);
    }

    private function findGoWhatsAppBinary(bool $isWindows): ?string
    {
        $binDir = $this->bridgeDir . DIRECTORY_SEPARATOR . 'bin';
        $candidates = $isWindows
            ? [$binDir . '\whatsapp.exe', $binDir . '\gowa.exe']
            : [$binDir . '/whatsapp', $binDir . '/gowa', '/usr/local/bin/whatsapp'];

        foreach ($candidates as $path) {
            if (is_file($path) && ($isWindows || is_executable($path))) {
                return $path;
            }
        }
        return null;
    }

    private function findNodeBinary(bool $isWindows): ?string
    {
        $candidates = $isWindows
            ? [
                'C:\laragon\bin\nodejs\node-v22\node.exe',
                'C:\Program Files\nodejs\node.exe',
            ]
            : [
                '/usr/bin/node',
                '/usr/local/bin/node',
                '/opt/alt/alt-nodejs20/root/usr/bin/node',
                '/opt/alt/alt-nodejs18/root/usr/bin/node',
            ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        if ($isWindows) {
            $glob = glob('C:\laragon\bin\nodejs\*\node.exe') ?: [];
            if ($glob !== []) {
                return $glob[0];
            }
        }

        return null;
    }

    private function isLocalhostEndpoint(string $url): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    private function isPortOpen(string $host, int $port): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private function readPid(): ?int
    {
        if (!is_file($this->pidFile)) {
            return null;
        }
        $raw = trim((string)@file_get_contents($this->pidFile));
        return ctype_digit($raw) && (int)$raw > 0 ? (int)$raw : null;
    }

    private function isPidRunning(int $pid): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $out = [];
            @exec("tasklist /FI \"PID eq {$pid}\" /NH 2>NUL", $out);
            $joined = implode("\n", $out);
            return str_contains($joined, (string)$pid);
        }
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir("/proc/{$pid}");
    }

    private function spawnDetachedProcess(string $command, string $cwd, bool $isWindows, array $envVars = []): ?int
    {
        if ($isWindows) {
            $envPrefix = '';
            foreach ($envVars as $k => $v) {
                $safeVal = str_replace('"', '', $v);
                $envPrefix .= "set \"{$k}={$safeVal}\" && ";
            }
            $logEsc = str_replace('"', '', $this->logFile);
            $batFile = $this->runtimeDir . DIRECTORY_SEPARATOR . 'start_bridge.bat';
            $batContent = "@echo off\r\ncd /d \"{$cwd}\"\r\n{$envPrefix}{$command} >> \"{$logEsc}\" 2>&1\r\n";
            @file_put_contents($batFile, $batContent, LOCK_EX);

            // Jalankan terpisah (background tanpa memblokir request PHP) melalui PowerShell Start-Process -PassThru
            $psCmd = sprintf(
                'powershell -NoProfile -NonInteractive -Command "(Start-Process -FilePath \'cmd.exe\' -ArgumentList \'/c\",\"%s\' -WorkingDirectory \'%s\' -WindowStyle Hidden -PassThru).Id"',
                str_replace("'", "''", $batFile),
                str_replace("'", "''", $cwd)
            );
            $out = [];
            @exec($psCmd, $out);
            $pidStr = trim(implode('', $out));
            return ctype_digit($pidStr) ? (int)$pidStr : null;
        }

        // Linux / cPanel background detached process (nohup ... & echo $!)
        $envPrefix = '';
        foreach ($envVars as $k => $v) {
            $envPrefix .= $k . '=' . escapeshellarg($v) . ' ';
        }
        $linuxCmd = sprintf(
            'cd %s && %snohup %s >> %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($cwd),
            $envPrefix,
            $command,
            escapeshellarg($this->logFile)
        );
        $out = [];
        @exec($linuxCmd, $out);
        $pidStr = trim((string)($out[0] ?? ''));
        return ctype_digit($pidStr) ? (int)$pidStr : null;
    }

    private function syncDeviceStatus(string $status): void
    {
        if (!in_array($status, ['connected', 'disconnected', 'qr_pending'], true)) {
            $status = 'disconnected';
        }
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE wa_devices
                 SET status = :st, last_seen_at = CASE WHEN :st2 = 'connected' THEN NOW() ELSE last_seen_at END
                 WHERE driver = 'md_bridge'
                 ORDER BY is_default DESC, id ASC
                 LIMIT 1"
            );
            $stmt->execute([':st' => $status, ':st2' => $status]);
        } catch (Throwable $e) {
            // Non-blocking sync
        }
    }

    private function updateBridgeFlavor(string $flavor): void
    {
        try {
            wa_set_setting('wa_bridge_flavor', $flavor, $this->pdo);
        } catch (Throwable $e) {
            // Non-blocking
        }
    }
}
