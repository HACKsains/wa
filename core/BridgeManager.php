<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — PHP AUTOMATIC NODE.JS PORTABLE + BAILEYS INSTALLER & MANAGER
 * ==============================================================================
 * Mengendalikan instalasi Node.js Portable, `npm install` Baileys, dan siklus
 * hidup daemon `bridge/server.js` 100% melalui PHP (tanpa SSH / Terminal):
 *
 * 1. Auto-Install Node.js Portable via PHP cURL + tar/PharData:
 *    - Linux x64  : Node.js v20.18.1 LTS resmi (+ fallback otomatis v18.20.4
 *                   glibc-217 untuk CloudLinux 7 / CentOS 7!)
 *    - Linux arm64: Node.js v20.18.1 LTS arm64
 *    - Windows x64: Node.js v20.18.1 LTS win-x64 zip
 * 2. Auto-Install Dependensi Baileys (`@whiskeysockets/baileys`, `pino`, `qrcode`):
 *    - Menjalankan `npm-cli.js` langsung via binary Node portable (kebal error PATH)
 *    - Mendukung background non-blocking (anti-504 LiteSpeed) + live log viewer
 * 3. Jalur Tanpa `exec` di Web PHP (Shared cPanel super-ketat):
 *    - Tetap dapat dieksekusi 1x lewat cPanel Cron Job (`workers/bridge-installer.php`)
 *      tanpa butuh SSH maupun terminal!
 * 4. Kompatibel 100% PHP 7.4 (`ea-php74`) s/d PHP 8.3+.
 * ==============================================================================
 */

class BridgeManager
{
    private const NODE_LTS_VERSION     = 'v26.13.1';
    private const NODE_FALLBACK_V22    = 'v22.22.0';
    private const NODE_GLIBC217_VER    = 'v18.20.4';

    private PDO $pdo;
    private string $bridgeDir;
    private string $runtimeDir;
    private string $portableNodeDir;
    private string $pidFile;
    private string $logFile;
    private string $installerLogFile;
    private string $installerStateFile;
    private string $installerPidFile;
    private string $baseUrl;
    private int $port;
    private string $session;
    private string $webhookSecret;
    private string $apiKey;
    private ?array $cachedEnv = null;

    public function __construct(?PDO $pdo = null, array $config = [])
    {
        $this->pdo                = $pdo ?? getDBConnection();
        $this->bridgeDir          = rtrim((string)($config['bridge_dir'] ?? (BASE_PATH . '/bridge')), '/\\');
        $this->runtimeDir         = $this->bridgeDir . DIRECTORY_SEPARATOR . 'runtime';
        $this->portableNodeDir    = $this->runtimeDir . DIRECTORY_SEPARATOR . 'node';
        $this->pidFile            = $this->runtimeDir . DIRECTORY_SEPARATOR . 'bridge.pid';
        $this->logFile            = $this->runtimeDir . DIRECTORY_SEPARATOR . 'bridge.log';
        $this->installerLogFile   = $this->runtimeDir . DIRECTORY_SEPARATOR . 'installer.log';
        $this->installerStateFile = $this->runtimeDir . DIRECTORY_SEPARATOR . 'install_state.json';
        $this->installerPidFile   = $this->runtimeDir . DIRECTORY_SEPARATOR . 'installer.pid';

        // Muat konfigurasi default dari tabel wa_devices jika tidak di-override manual
        $dbDevice = null;
        if (!isset($config['base_url'])) {
            try {
                $stmt = $this->pdo->query(
                    "SELECT endpoint_url, api_token, webhook_secret
                     FROM wa_devices
                     WHERE driver = 'md_bridge'
                     ORDER BY is_default DESC, id ASC
                     LIMIT 1"
                );
                $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
                if (is_array($row)) {
                    $dbDevice = $row;
                }
            } catch (Throwable $e) {
                $dbDevice = null;
            }
        }

        $rawUrl = (string)($config['base_url']
            ?? $config['endpoint_url']
            ?? ($dbDevice && !empty($dbDevice['endpoint_url']) ? $dbDevice['endpoint_url'] : WA_BRIDGE_BASE_URL));
        $this->baseUrl = rtrim($rawUrl, '/');
        $this->port    = (int)(parse_url($this->baseUrl, PHP_URL_PORT) ?: 38421);
        $this->session = (string)($config['session'] ?? WA_BRIDGE_SESSION);

        $this->webhookSecret = (string)($config['webhook_secret']
            ?? ($dbDevice && !empty($dbDevice['webhook_secret']) ? $dbDevice['webhook_secret'] : WA_BRIDGE_WEBHOOK_SECRET));
        $this->apiKey = (string)($config['api_key']
            ?? ($dbDevice && !empty($dbDevice['api_token']) ? $dbDevice['api_token'] : WA_BRIDGE_API_KEY));
    }

    /**
     * Diagnosa lengkap kemampuan server (Laragon / VPS / Shared Hosting cPanel)
     */
    public function detectEnvironment(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && $this->cachedEnv !== null) {
            return $this->cachedEnv;
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $disabled  = array_map(
            'trim',
            explode(',', strtolower((string)ini_get('disable_functions')))
        );

        $procOpenAllowed  = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
        $execAllowed      = function_exists('exec') && !in_array('exec', $disabled, true);
        $shellExecAllowed = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
        $canSpawn         = $procOpenAllowed || $execAllowed;

        $goBinary         = $this->findGoWhatsAppBinary($isWindows);
        $nodeBinary       = $this->findNodeBinary($isWindows);
        $nodeIsPortable   = $nodeBinary !== null && strpos(str_replace('\\', '/', $nodeBinary), '/bridge/runtime/node') !== false;
        $npmCli           = $nodeBinary !== null ? $this->findNpmCli($nodeBinary, $isWindows) : null;
        $phpCliBinary     = $this->findPhpCliBinary($isWindows);
        $nodeServerExists = is_file($this->bridgeDir . DIRECTORY_SEPARATOR . 'server.js');
        $baileysReady     = $this->isBaileysReady();
        $isLocalTarget    = $this->isLocalhostEndpoint($this->baseUrl);
        $curlLoaded       = extension_loaded('curl');
        $pharLoaded       = class_exists('PharData');
        $zipLoaded        = class_exists('ZipArchive');
        $bridgeWritable   = is_dir($this->bridgeDir) && is_writable($this->bridgeDir);
        $arch             = strtolower((string)php_uname('m'));

        // Cache versi Node.js per path+mtime di disk agar tidak perlu spawn proses V8 di setiap load!
        $nodeVersion = $nodeBinary !== null ? $this->getCachedNodeVersion($nodeBinary, $execAllowed, $forceRefresh) : null;

        $canAutoInstallNode = $curlLoaded && $bridgeWritable && (!$isWindows || $zipLoaded);
        $installState       = $this->readInstallState();

        // Tentukan mode rekomendasi & strategi instalasi Baileys via PHP
        if (!$isLocalTarget) {
            $recommended     = 'remote_http_bridge';
            $installStrategy = 'remote';
            $reason          = 'Endpoint bridge mengarah ke server eksternal / Cloud Gateway (100% PHP cURL).';
        } elseif ($canSpawn && $nodeBinary !== null && $nodeServerExists) {
            $recommended     = 'node_baileys';
            $installStrategy = $baileysReady ? 'ready' : 'web_one_click';
            $reason          = $baileysReady
                ? 'Node.js (' . ($nodeVersion ?: 'terdeteksi') . ') & Baileys siap dijalankan otomatis 1-klik oleh PHP!'
                : 'Node.js ditemukan (' . ($nodeVersion ?: 'terdeteksi') . '). Klik "Install Node.js + Baileys" untuk memasang dependensi Baileys otomatis via PHP.';
        } elseif ($canSpawn && $goBinary !== null) {
            $recommended     = 'go_binary';
            $installStrategy = 'ready';
            $reason          = 'Binary tunggal Go-WhatsApp ditemukan dan siap dijalankan otomatis oleh PHP.';
        } elseif ($canSpawn && $nodeServerExists && $canAutoInstallNode) {
            $recommended     = 'node_baileys';
            $installStrategy = 'web_one_click';
            $reason          = 'Node.js belum terpasang, tetapi PHP di server ini BISA mengunduh Node.js Portable + install Baileys + menyalakan bot 1-klik langsung dari Panel Web (tanpa SSH/Terminal)!';
        } elseif (!$canSpawn && $nodeServerExists) {
            $recommended     = 'cloud_api_or_external_gateway';
            $installStrategy = 'cron_installer';
            $reason          = 'Web PHP memblokir fungsi exec/proc_open. Anda tetap bisa memasang Node.js Portable + Baileys TANPA SSH dengan menempel 1 baris perintah Cron Job Auto-Installer di cPanel → Cron Jobs, atau gunakan Meta Cloud API / Whapi / Fonnte.';
        } else {
            $recommended     = 'cloud_api_or_external_gateway';
            $installStrategy = 'external_only';
            $reason          = 'Hosting shared membatasi eksekusi proses lokal. Gunakan Meta WhatsApp Cloud API Resmi (100% PHP) atau Gateway HTTP (Whapi/Fonnte).';
        }

        $cronPhp = $phpCliBinary ?: ($isWindows ? 'php' : '/usr/local/bin/php');

        $this->cachedEnv = [
            'os'                   => $isWindows ? 'windows' : 'linux',
            'arch'                 => $arch,
            'php_version'          => PHP_VERSION,
            'php_cli_binary'       => $phpCliBinary,
            'curl_loaded'          => $curlLoaded,
            'phar_loaded'          => $pharLoaded,
            'zip_loaded'           => $zipLoaded,
            'bridge_writable'      => $bridgeWritable,
            'exec_allowed'         => $execAllowed,
            'proc_open_allowed'    => $procOpenAllowed,
            'shell_exec_allowed'   => $shellExecAllowed,
            'can_spawn_process'    => $canSpawn,
            'is_local_endpoint'    => $isLocalTarget,
            'go_binary'            => $goBinary,
            'node_binary'          => $nodeBinary,
            'node_version'         => $nodeVersion,
            'node_target_version'  => self::NODE_LTS_VERSION,
            'node_is_portable'     => $nodeIsPortable,
            'npm_cli'              => $npmCli,
            'node_server_exists'   => $nodeServerExists,
            'node_modules_ready'   => $baileysReady,
            'can_auto_install_node'=> $canAutoInstallNode,
            'install_strategy'     => $installStrategy,
            'install_state'        => $installState,
            'cron_installer_cmd'   => $cronPhp . ' ' . BASE_PATH . '/workers/bridge-installer.php --auto-start >> ' . $this->installerLogFile . ' 2>&1',
            'recommended_mode'     => $recommended,
            'recommendation_note'  => $reason,
            'target_base_url'      => $this->baseUrl,
        ];
        return $this->cachedEnv;
    }

    /**
     * Cek apakah service bridge merespon HTTP / status sesi terkini (1 panggilan cURL saja!)
     */
    public function getStatus(): array
    {
        $env    = $this->detectEnvironment();
        $driver = $this->buildBridgeDriver($env);
        $health = $driver->healthCheck();

        $pid      = $this->readPid();
        $pidAlive = $pid !== null && $this->isPidRunning($pid);

        $raw       = is_array($health['raw'] ?? null) ? $health['raw'] : [];
        $flavor    = $driver->getFlavor();
        $isRunning = (bool)($health['success'] ?? false);
        $errorMsg  = $health['error'] ?? null;

        // Verifikasi identitas bridge langsung dari payload $health['raw'] (0 cURL tambahan!)
        if ($env['is_local_endpoint']) {
            $verifiedOurs = $isRunning && (
                $flavor === 'gowa'
                    ? (array_key_exists('results', $raw) || array_key_exists('code', $raw))
                    : (($raw['app'] ?? '') === 'ilmuna-wa-bridge' || (($raw['session'] ?? '') === $this->session && isset($raw['status'])))
            );
            if (!$verifiedOurs) {
                $isRunning = false;
                if ((int)($health['http_status'] ?? 0) > 0) {
                    $errorMsg = "Port {$this->port} sedang dipakai aplikasi lain di server hosting. Klik '2. Jalankan Baileys' atau 'Ambil QR' untuk otomatis berpindah ke port bebas (38421+).";
                }
            }
        }

        $waStatus = $isRunning ? (string)($health['status'] ?? 'disconnected') : 'disconnected';
        $this->syncDeviceStatus($waStatus);

        return [
            'running'     => $isRunning,
            'wa_status'   => $waStatus,
            'driver'      => $flavor,
            'base_url'    => $this->baseUrl,
            'port'        => $this->port,
            'pid'         => $pidAlive ? $pid : null,
            'pid_alive'   => $pidAlive,
            'http_status' => (int)($health['http_status'] ?? 0),
            'me'          => $isRunning ? ($raw['me'] ?? null) : null,
            'error'       => $errorMsg,
            'environment' => $env,
        ];
    }

    /**
     * 1-Klik Installer All-in-One: Unduh Node.js Portable + npm install Baileys + Auto-Start
     * Pada Web request dengan `exec` aktif, pekerjaan berat dijalankan terpisah di background
     * agar tidak terkena batas timeout LiteSpeed/Cloudflare (60 detik).
     */
    public function installAll(bool $background = true, bool $autoStart = true, bool $forceUpgradeNode = false): array
    {
        $this->ensureRuntimeDir();
        $env = $this->detectEnvironment();

        // Cek apakah installer sedang berjalan
        $instPid = $this->readInstallerPid();
        if ($instPid !== null && $this->isPidRunning($instPid)) {
            return [
                'success' => true,
                'status'  => 'running',
                'pid'     => $instPid,
                'message' => 'Proses instalasi Node.js & Baileys sedang berjalan di latar belakang. Pantau log progres di bawah.',
                'install' => $this->getInstallStatus(),
            ];
        }

        // Jika sudah siap 100% dan tidak diminta paksa upgrade Node.js, langsung start saja
        if (!$forceUpgradeNode && $env['node_binary'] !== null && $env['node_modules_ready']) {
            $this->writeInstallState('done', 'ready', 100, 'Node.js & Baileys sudah terpasang lengkap.');
            if ($autoStart) {
                $startRes = $this->ensureRunning();
                return [
                    'success' => (bool)$startRes['success'],
                    'status'  => 'done',
                    'message' => 'Node.js & Baileys sudah siap! ' . ($startRes['message'] ?? ''),
                    'start'   => $startRes,
                    'install' => $this->getInstallStatus(),
                ];
            }
            return [
                'success' => true,
                'status'  => 'done',
                'message' => 'Node.js & Baileys sudah terpasang lengkap.',
                'install' => $this->getInstallStatus(),
            ];
        }

        // Jalur 1: Web background via PHP CLI (`workers/bridge-installer.php`) -> anti-timeout!
        if ($background && $env['can_spawn_process'] && $env['php_cli_binary'] !== null) {
            $installerScript = BASE_PATH . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'bridge-installer.php';
            if (is_file($installerScript)) {
                @file_put_contents($this->installerLogFile, '', LOCK_EX);
                $label = $forceUpgradeNode
                    ? ('Memulai Upgrade Node.js Portable ' . self::NODE_LTS_VERSION . ' & Restart Baileys via PHP (background)...')
                    : 'Memulai Auto-Installer Node.js Portable & Baileys via PHP (background)...';
                $this->appendInstallerLog($label);
                $this->writeInstallState('running', 'starting', 5, $label);

                $args = ($autoStart ? ' --auto-start' : '') . ($forceUpgradeNode ? ' --upgrade-node' : '');
                $cmd  = '"' . $env['php_cli_binary'] . '" "' . $installerScript . '"' . $args;
                $pid  = $this->spawnDetachedProcess($cmd, BASE_PATH, $env['os'] === 'windows', [], $this->installerLogFile);

                if ($pid !== null) {
                    @file_put_contents($this->installerPidFile, (string)$pid, LOCK_EX);
                }

                return [
                    'success' => true,
                    'status'  => 'running',
                    'pid'     => $pid,
                    'message' => $label . ' (PID: ' . ($pid ?? 'aktif') . ')',
                    'install' => $this->getInstallStatus(),
                ];
            }
        }

        // Jalur 2: Jika Web PHP memblokir `exec`/`proc_open` (shared hosting super-ketat)
        if (!$env['can_spawn_process']) {
            $nodeNote = '';
            if (($forceUpgradeNode || $env['node_binary'] === null) && $env['can_auto_install_node']) {
                $dlRes = $this->installPortableNode(false);
                if (!empty($dlRes['success'])) {
                    $nodeNote = 'Node.js Portable ' . self::NODE_LTS_VERSION . ' berhasil diunduh & diekstrak murni oleh PHP! ';
                }
            }

            $msg = $nodeNote
                . 'Web PHP di hosting ini menonaktifkan exec/proc_open, sehingga langkah `npm install` & menyalakan Baileys dijalankan lewat 1 baris Cron Job cPanel (tanpa SSH). Salin perintah Cron Auto-Installer di bawah lalu tempel sekali di cPanel → Cron Jobs.';
            $this->writeInstallState('needs_cron', 'cron_required', 40, $msg);

            return [
                'success'      => false,
                'status'       => 'needs_cron',
                'message'      => $msg,
                'cron_command' => $env['cron_installer_cmd'],
                'install'      => $this->getInstallStatus(),
            ];
        }

        // Jalur 3: Eksekusi langsung sinkron
        return $this->runFullInstallSync($autoStart, $forceUpgradeNode);
    }

    /**
     * Eksekusi instalasi penuh secara sinkron (dipakai oleh workers/bridge-installer.php & fallback)
     */
    public function runFullInstallSync(bool $autoStart = true, bool $forceUpgradeNode = false): array
    {
        $this->ensureRuntimeDir();
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $this->writeInstallState('running', 'checking_node', 10, 'Memeriksa ketersediaan runtime Node.js...');
        $this->appendInstallerLog('=== ILMUNA AUTO-INSTALLER NODE.JS PORTABLE (' . self::NODE_LTS_VERSION . ') + BAILEYS ===');
        $this->appendInstallerLog('OS: ' . PHP_OS . ' | Arch: ' . php_uname('m') . ' | PHP: ' . PHP_VERSION);

        if ($forceUpgradeNode) {
            $this->stop();
        }

        // 1. Pastikan Node.js tersedia (atau unduh v26.13.1 Portable jika diminta upgrade)
        $nodeBin = $forceUpgradeNode ? null : $this->findNodeBinary($isWindows);
        if ($nodeBin === null) {
            $nodeRes = $this->installPortableNode(false);
            if (empty($nodeRes['success'])) {
                // Jika upgrade v26 gagal (misal karena glibc server terlalu lama), periksa apakah Node sistem masih bisa dipakai
                $fallbackSys = $this->findNodeBinary($isWindows);
                if ($forceUpgradeNode && $fallbackSys !== null) {
                    $this->appendInstallerLog('Upgrade ke ' . self::NODE_LTS_VERSION . ' tidak kompatibel dengan glibc OS ini; tetap memakai Node.js stabil: ' . $fallbackSys);
                    $nodeBin = $fallbackSys;
                } else {
                    $err = (string)($nodeRes['message'] ?? 'Gagal memasang Node.js Portable.');
                    $this->writeInstallState('failed', 'node_failed', 20, $err);
                    return ['success' => false, 'status' => 'failed', 'message' => $err];
                }
            } else {
                $nodeBin = (string)$nodeRes['node_binary'];
            }
        } else {
            $this->appendInstallerLog('Node.js ditemukan: ' . $nodeBin);
            $this->writeInstallState('running', 'node_ready', 45, 'Node.js siap: ' . $nodeBin);
        }

        // 2. Pastikan dependensi Baileys (`@whiskeysockets/baileys`, `pino`, `qrcode`) terpasang
        if (!$this->isBaileysReady()) {
            $npmRes = $this->installBaileysDependencies(false);
            if (empty($npmRes['success'])) {
                $err = (string)($npmRes['message'] ?? 'Gagal menjalankan npm install Baileys.');
                $this->writeInstallState('failed', 'npm_failed', 65, $err);
                return ['success' => false, 'status' => 'failed', 'message' => $err];
            }
        } else {
            $this->appendInstallerLog('Dependensi Baileys sudah terpasang lengkap di bridge/node_modules.');
            $this->writeInstallState('running', 'baileys_ready', 85, 'Dependensi Baileys sudah terpasang.');
        }

        // 3. Auto-start daemon `bridge/server.js` bila diminta
        $startRes = null;
        if ($autoStart) {
            $this->writeInstallState('running', 'starting_bridge', 92, 'Menyalakan layanan Baileys WhatsApp Bridge...');
            $this->appendInstallerLog('Menyalakan daemon bridge/server.js...');
            $startRes = $this->ensureRunning();
            if (!empty($startRes['success'])) {
                $this->appendInstallerLog('BERHASIL: ' . ($startRes['message'] ?? 'Baileys Bridge aktif.'));
            } else {
                $this->appendInstallerLog('PERINGATAN START: ' . ($startRes['message'] ?? 'Bridge belum merespon.'));
            }
        }

        $finalMsg = ($startRes && !empty($startRes['success']))
            ? 'Instalasi selesai 100%! Node.js Portable + Baileys sudah AKTIF. Silakan klik "Ambil QR" atau "Pairing 8-Digit".'
            : 'Node.js Portable & Baileys berhasil dipasang. Klik "Jalankan Baileys" lalu "Ambil QR".';

        $this->writeInstallState('done', 'completed', 100, $finalMsg);
        if (is_file($this->installerPidFile)) {
            @unlink($this->installerPidFile);
        }

        return [
            'success'     => true,
            'status'      => 'done',
            'node_binary' => $nodeBin,
            'start'       => $startRes,
            'message'     => $finalMsg,
        ];
    }

    /**
     * Unduh & ekstrak Node.js Portable resmi langsung via PHP cURL (tanpa SSH/CLI!)
     * Mendukung fallback otomatis ke Node.js v18.20.4 glibc-217 untuk CloudLinux 7 / CentOS 7.
     */
    public function installPortableNode(bool $forceGlibc217 = false): array
    {
        $this->ensureRuntimeDir();
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        if (!extension_loaded('curl')) {
            return ['success' => false, 'message' => 'Ekstensi PHP cURL tidak aktif untuk mengunduh Node.js.'];
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $arch      = strtolower((string)php_uname('m'));
        $isArm64   = strpos($arch, 'aarch64') !== false || strpos($arch, 'arm64') !== false;

        if ($isWindows) {
            $ver     = self::NODE_LTS_VERSION;
            $url     = "https://nodejs.org/dist/{$ver}/node-{$ver}-win-x64.zip";
            $archive = $this->runtimeDir . DIRECTORY_SEPARATOR . 'node_dist.zip';
        } elseif ($forceGlibc217 && !$isArm64) {
            $ver     = self::NODE_GLIBC217_VER;
            $url     = "https://unofficial-builds.nodejs.org/download/release/{$ver}/node-{$ver}-linux-x64-glibc-217.tar.gz";
            $archive = $this->runtimeDir . DIRECTORY_SEPARATOR . 'node_dist.tar.gz';
        } elseif ($isArm64) {
            $ver     = self::NODE_LTS_VERSION;
            $url     = "https://nodejs.org/dist/{$ver}/node-{$ver}-linux-arm64.tar.gz";
            $archive = $this->runtimeDir . DIRECTORY_SEPARATOR . 'node_dist.tar.gz';
        } else {
            $ver     = self::NODE_LTS_VERSION;
            $url     = "https://nodejs.org/dist/{$ver}/node-{$ver}-linux-x64.tar.gz";
            $archive = $this->runtimeDir . DIRECTORY_SEPARATOR . 'node_dist.tar.gz';
        }

        $this->writeInstallState('running', 'downloading_node', 18, "Mengunduh Node.js Portable {$ver}...");
        $this->appendInstallerLog("Mengunduh Node.js Portable dari: {$url}");

        // 1. Streaming download ke file (hemat RAM!)
        $fp = @fopen($archive, 'wb');
        if (!$fp) {
            return ['success' => false, 'message' => 'Tidak dapat membuat berkas sementara di ' . $this->runtimeDir];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 240,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Ilmuna-WA-Bot-Installer/1.0',
        ]);
        $ok      = curl_exec($ch);
        $curlErr = curl_error($ch);
        $http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $http < 200 || $http >= 300 || !is_file($archive) || filesize($archive) < 1000000) {
            @unlink($archive);
            $err = "Gagal mengunduh Node.js Portable (HTTP {$http}): {$curlErr}";
            $this->appendInstallerLog('ERROR: ' . $err);
            return ['success' => false, 'message' => $err];
        }

        $sizeMb = round(filesize($archive) / 1048576, 1);
        $this->appendInstallerLog("Unduhan selesai ({$sizeMb} MB). Mengekstrak ke bridge/runtime/node/...");
        $this->writeInstallState('running', 'extracting_node', 32, "Mengekstrak Node.js Portable ({$sizeMb} MB)...");

        // Bersihkan direktori target lama bila ada
        $this->recursiveDelete($this->portableNodeDir);
        @mkdir($this->portableNodeDir, 0755, true);

        // 2. Ekstrak arsip
        $extracted = false;
        if ($isWindows) {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($archive) === true) {
                    $tmpDir = $this->runtimeDir . DIRECTORY_SEPARATOR . 'node_extract_tmp';
                    $this->recursiveDelete($tmpDir);
                    @mkdir($tmpDir, 0755, true);
                    $zip->extractTo($tmpDir);
                    $zip->close();
                    $inner = glob($tmpDir . DIRECTORY_SEPARATOR . 'node-*', GLOB_ONLYDIR) ?: [];
                    if (!empty($inner[0])) {
                        $this->recursiveDelete($this->portableNodeDir);
                        @rename($inner[0], $this->portableNodeDir);
                        $extracted = is_file($this->portableNodeDir . DIRECTORY_SEPARATOR . 'node.exe');
                    }
                    $this->recursiveDelete($tmpDir);
                }
            }
        } else {
            // Linux: prioritaskan `tar -xzf --strip-components=1` (cepat & jaga symlink/chmod)
            $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
            $execOk   = function_exists('exec') && !in_array('exec', $disabled, true);
            if ($execOk) {
                $tarOut  = [];
                $tarCode = 1;
                $tarCmd  = sprintf(
                    'tar -xzf %s --strip-components=1 -C %s 2>&1',
                    escapeshellarg($archive),
                    escapeshellarg($this->portableNodeDir)
                );
                @exec($tarCmd, $tarOut, $tarCode);
                if ($tarCode === 0 && is_file($this->portableNodeDir . '/bin/node')) {
                    $extracted = true;
                } else {
                    $this->appendInstallerLog('tar CLI gagal/tidak tersedia, beralih ke Pure PHP PharData: ' . implode(' ', array_slice($tarOut, 0, 3)));
                }
            }

            // Fallback Pure PHP PharData (berjalan TANPA exec!)
            if (!$extracted && class_exists('PharData')) {
                try {
                    $tmpDir = $this->runtimeDir . DIRECTORY_SEPARATOR . 'node_extract_tmp';
                    $this->recursiveDelete($tmpDir);
                    @mkdir($tmpDir, 0755, true);
                    $tarFile = $this->runtimeDir . DIRECTORY_SEPARATOR . 'node_dist.tar';
                    @unlink($tarFile);

                    $pharGz = new PharData($archive);
                    $pharGz->decompress(); // menghasilkan node_dist.tar
                    $pharTar = new PharData($tarFile);
                    $pharTar->extractTo($tmpDir, null, true);
                    @unlink($tarFile);

                    $inner = glob($tmpDir . '/node-*', GLOB_ONLYDIR) ?: [];
                    if (!empty($inner[0])) {
                        $this->recursiveDelete($this->portableNodeDir);
                        @rename($inner[0], $this->portableNodeDir);
                        $extracted = is_file($this->portableNodeDir . '/bin/node');
                    }
                    $this->recursiveDelete($tmpDir);
                } catch (Throwable $ePhar) {
                    $this->appendInstallerLog('PharData error: ' . $ePhar->getMessage());
                }
            }

            if ($extracted) {
                @chmod($this->portableNodeDir . '/bin/node', 0755);
                if (is_file($this->portableNodeDir . '/bin/npm')) {
                    @chmod($this->portableNodeDir . '/bin/npm', 0755);
                }
            }
        }

        @unlink($archive);

        $nodeBin = $isWindows
            ? ($this->portableNodeDir . DIRECTORY_SEPARATOR . 'node.exe')
            : ($this->portableNodeDir . '/bin/node');

        if (!$extracted || !is_file($nodeBin)) {
            $err = 'Gagal mengekstrak arsip Node.js Portable ke bridge/runtime/node/.';
            $this->appendInstallerLog('ERROR: ' . $err);
            return ['success' => false, 'message' => $err];
        }

        // 3. Verifikasi eksekusi binary + deteksi otomatis GLIBC lama (CloudLinux 7 / CentOS 7)
        $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
        $execOk   = function_exists('exec') && !in_array('exec', $disabled, true);
        $verOut   = [];
        $verCode  = 0;

        if ($execOk) {
            @exec('"' . $nodeBin . '" -v 2>&1', $verOut, $verCode);
            $joinedOut = implode("\n", $verOut);
            if ($verCode !== 0 && !$isWindows && !$isArm64 && !$forceGlibc217 && stripos($joinedOut, 'GLIBC_') !== false) {
                $this->recursiveDelete($this->portableNodeDir);
                $existingSys = $this->findNodeBinary($isWindows);
                if ($existingSys !== null) {
                    $msg = "GLIBC OS hosting belum mendukung {$ver} ({$joinedOut}). Tetap mempertahankan Node.js sistem yang kompatibel: {$existingSys}";
                    $this->appendInstallerLog($msg);
                    return ['success' => false, 'glibc_incompatible' => true, 'message' => $msg];
                }
                $this->appendInstallerLog("Deteksi GLIBC OS belum mendukung {$ver} ({$joinedOut}). Otomatis beralih ke fallback glibc-217 (" . self::NODE_GLIBC217_VER . ')...');
                return $this->installPortableNode(true);
            }
            if ($verCode !== 0) {
                $this->recursiveDelete($this->portableNodeDir);
                $err = "Binary Node.js {$ver} gagal dijalankan (exit {$verCode}): {$joinedOut}";
                $this->appendInstallerLog('ERROR: ' . $err);
                return ['success' => false, 'message' => $err];
            }
            $this->appendInstallerLog('Verifikasi Node.js Portable OK: ' . trim($joinedOut));
        } else {
            $this->appendInstallerLog('Node.js Portable diekstrak ke: ' . $nodeBin . ' (verifikasi exec dilewati karena web exec dibatasi).');
        }

        $this->writeInstallState('running', 'node_ready', 45, 'Node.js Portable siap dipakai (' . trim(implode(' ', $verOut) ?: $ver) . ').');

        return [
            'success'      => true,
            'node_binary'  => $nodeBin,
            'node_version' => trim(implode(' ', $verOut)) ?: $ver,
            'message'      => 'Node.js Portable berhasil dipasang di ' . $nodeBin,
        ];
    }

    /**
     * Pasang dependensi Baileys (`@whiskeysockets/baileys`, `pino`, `qrcode`) via npm-cli.js
     */
    public function installBaileysDependencies(bool $background = false): array
    {
        $this->ensureRuntimeDir();
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $disabled  = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
        $execOk    = function_exists('exec') && !in_array('exec', $disabled, true);

        if (!$execOk) {
            return [
                'success' => false,
                'message' => 'Fungsi PHP exec() dinonaktifkan pada Web PHP. Jalankan `workers/bridge-installer.php` lewat cPanel Cron Jobs.',
            ];
        }

        $nodeBin = $this->findNodeBinary($isWindows);
        if ($nodeBin === null) {
            return [
                'success' => false,
                'message' => 'Runtime Node.js belum ditemukan. Jalankan "Install Node.js Portable" terlebih dahulu.',
            ];
        }

        $npmCli = $this->findNpmCli($nodeBin, $isWindows);
        if ($npmCli === null) {
            return [
                'success' => false,
                'message' => 'Berkas npm-cli.js tidak ditemukan di dekat binary Node.js (' . $nodeBin . ').',
            ];
        }

        $homeDir  = $this->runtimeDir . DIRECTORY_SEPARATOR . 'home';
        $cacheDir = $this->runtimeDir . DIRECTORY_SEPARATOR . 'npm-cache';
        if (!is_dir($homeDir)) {
            @mkdir($homeDir, 0755, true);
        }
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $nodeDir = dirname($nodeBin);
        $this->writeInstallState('running', 'installing_baileys', 58, 'Memasang @whiskeysockets/baileys, pino, & qrcode via npm...');
        $this->appendInstallerLog('Menjalankan npm install --omit=dev di ' . $this->bridgeDir . '...');

        if (str_ends_with(strtolower($npmCli), '.js')) {
            $npmCommand = sprintf(
                '"%s" --max-old-space-size=384 "%s" install --omit=dev --no-audit --no-fund',
                $nodeBin,
                $npmCli
            );
        } else {
            $npmCommand = sprintf('"%s" install --omit=dev --no-audit --no-fund', $npmCli);
        }

        $envVars = [
            'HOME'             => $homeDir,
            'npm_config_cache' => $cacheDir,
            'npm_config_update_notifier' => 'false',
            'NODE_ENV'         => 'production',
            'PATH'             => $nodeDir . PATH_SEPARATOR . ((string)getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ];

        if ($background) {
            $pid = $this->spawnDetachedProcess($npmCommand, $this->bridgeDir, $isWindows, $envVars, $this->installerLogFile);
            if ($pid !== null) {
                @file_put_contents($this->installerPidFile, (string)$pid, LOCK_EX);
            }
            return [
                'success' => true,
                'status'  => 'running',
                'pid'     => $pid,
                'message' => 'Pemasangan paket Baileys berjalan di latar belakang.',
            ];
        }

        // Eksekusi sinkron (dipakai oleh workers/bridge-installer.php)
        if ($isWindows) {
            $prefix = '';
            foreach ($envVars as $k => $v) {
                $prefix .= 'set "' . $k . '=' . str_replace('"', '', $v) . '" && ';
            }
            $fullCmd = 'cd /d "' . $this->bridgeDir . '" && ' . $prefix . $npmCommand . ' 2>&1';
        } else {
            $prefix = '';
            foreach ($envVars as $k => $v) {
                $prefix .= $k . '=' . escapeshellarg($v) . ' ';
            }
            $fullCmd = 'cd ' . escapeshellarg($this->bridgeDir) . ' && ' . $prefix . $npmCommand . ' 2>&1';
        }

        $out  = [];
        $code = 1;
        @exec($fullCmd, $out, $code);
        foreach ($out as $line) {
            $this->appendInstallerLog($line);
        }

        if ($code === 0 && $this->isBaileysReady()) {
            $this->appendInstallerLog('BERHASIL: Paket @whiskeysockets/baileys, pino, dan qrcode terpasang!');
            $this->writeInstallState('running', 'baileys_ready', 85, 'Paket Baileys berhasil dipasang!');
            return [
                'success' => true,
                'message' => 'Dependensi Baileys berhasil dipasang.',
            ];
        }

        $tailErr = implode(' | ', array_slice($out, -5));
        $err     = "npm install gagal (exit {$code}): " . ($tailErr ?: 'Periksa bridge/runtime/installer.log');
        $this->appendInstallerLog('ERROR: ' . $err);
        return ['success' => false, 'message' => $err];
    }

    /**
     * Status progres instalasi & log live untuk polling Dashboard Admin
     */
    public function getInstallStatus(?array $knownEnv = null, ?bool $knownRunning = null): array
    {
        $state    = $this->readInstallState();
        $instPid  = $this->readInstallerPid();
        $instLive = $instPid !== null && $this->isPidRunning($instPid);
        $env      = $knownEnv ?? $this->detectEnvironment();

        // Jika status di JSON masih 'running' tapi prosesnya sudah selesai, perbarui otomatis
        if (($state['status'] ?? '') === 'running' && !$instLive) {
            if ($env['node_binary'] !== null && $env['node_modules_ready']) {
                $state['status']  = 'done';
                $state['percent'] = 100;
                $state['phase']   = 'completed';
                $state['message'] = 'Node.js Portable & Baileys sudah terpasang! Silakan klik "Jalankan Baileys" atau "Ambil QR".';
                $this->writeInstallState('done', 'completed', 100, $state['message']);
            }
        }

        $portOpen = $knownRunning !== null
            ? $knownRunning
            : ($this->isLocalhostEndpoint($this->baseUrl) && $this->isOurBridgeResponding());

        return [
            'status'          => (string)($state['status'] ?? 'idle'),
            'phase'           => (string)($state['phase'] ?? 'idle'),
            'percent'         => (int)($state['percent'] ?? 0),
            'message'         => (string)($state['message'] ?? ''),
            'updated_at'      => (string)($state['updated_at'] ?? ''),
            'installer_alive' => $instLive,
            'installer_pid'   => $instLive ? $instPid : null,
            'installer_log'   => $this->tailFile($this->installerLogFile, 80),
            'bridge_log'      => $this->tailLog(40),
            'port_open'       => $portOpen,
            'environment'     => $env,
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
     * Jalankan WA Bridge secara otomatis dari PHP (mendukung Baileys Node.js Portable maupun Go-WhatsApp)
     */
    public function start(): array
    {
        $this->ensureRuntimeDir();
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
                'success'      => false,
                'action'       => 'hosting_restricted',
                'message'      => 'Web PHP menonaktifkan proc_open/exec. Tempel perintah Cron Job Auto-Installer/Watchdog di cPanel → Cron Jobs agar Baileys menyala otomatis tanpa SSH.',
                'cron_command' => $env['cron_installer_cmd'],
                'env'          => $env,
            ];
        }

        // Jika Ilmuna WA Bridge KITA sendiri sudah merespon sehat di port saat ini, langsung pakai
        if ($this->isOurBridgeResponding()) {
            return [
                'success'  => true,
                'action'   => 'already_running',
                'runtime'  => 'node_baileys',
                'pid'      => $this->readPid(),
                'ready'    => true,
                'port'     => $this->port,
                'base_url' => $this->baseUrl,
                'message'  => "WA Bridge Ilmuna sudah aktif di port {$this->port}.",
            ];
        }

        // Anti-Bentrok Shared cPanel: jika port saat ini (mis. 3000) sudah dipakai aplikasi lain,
        // otomatis alokasikan port bebas di rentang 38421–38520 dan simpan ke wa_devices!
        $portSwitchedFrom = null;
        if ($this->isPortOpen('127.0.0.1', $this->port) || $this->port === 3000) {
            $freePort = $this->findFreeLocalPort(38421, 38520);
            if ($freePort === null) {
                return [
                    'success' => false,
                    'action'  => 'no_free_port',
                    'message' => "Port {$this->port} sedang terpakai dan tidak ditemukan port lokal kosong pada rentang 38421–38520.",
                ];
            }
            if ($freePort !== $this->port) {
                $portSwitchedFrom = $this->port;
                $this->port       = $freePort;
                $this->baseUrl    = 'http://127.0.0.1:' . $freePort;
                $this->persistBridgeBaseUrl($this->baseUrl);
                @file_put_contents(
                    $this->logFile,
                    '[' . date('H:i:s') . "] [BridgeManager] Port {$portSwitchedFrom} bentrok/dipakai aplikasi lain — otomatis pindah ke {$this->baseUrl}\n",
                    FILE_APPEND | LOCK_EX
                );
            }
        }

        $webhookUrl = $this->resolveWebhookUrl();
        $isWindows  = $env['os'] === 'windows';
        $modeUsed   = '';
        $pid        = null;

        // Prioritas 1: Node.js + Baileys (`bridge/server.js`) jika Node & Baileys tersedia
        if ($env['node_binary'] !== null && $env['node_server_exists'] && $env['node_modules_ready']) {
            $modeUsed = 'node_baileys';
            $homeDir  = $this->runtimeDir . DIRECTORY_SEPARATOR . 'home';
            if (!is_dir($homeDir)) {
                @mkdir($homeDir, 0755, true);
            }
            $envVars = [
                'HOME'                  => $homeDir,
                'NODE_ENV'              => 'production',
                'UV_THREADPOOL_SIZE'    => '2',
                'BRIDGE_PORT'           => (string)$this->port,
                'BRIDGE_SESSION'        => $this->session,
                'BRIDGE_API_KEY'        => $this->apiKey,
                'BRIDGE_WEBHOOK_URL'    => $webhookUrl,
                'BRIDGE_WEBHOOK_SECRET' => $this->webhookSecret,
            ];
            $serverJs = $this->bridgeDir . DIRECTORY_SEPARATOR . 'server.js';
            // Hemat RAM & thread (192MB heap, 1 V8 pool) agar ramah CloudLinux LVE shared hosting cPanel
            $cmd = sprintf('"%s" --max-old-space-size=192 "%s"', $env['node_binary'], $serverJs);
            $pid = $this->spawnDetachedProcess($cmd, $this->bridgeDir, $isWindows, $envVars, $this->logFile);
            $this->updateBridgeFlavor('generic');
        }
        // Prioritas 2: Binary tunggal Go-WhatsApp (bila dipasang manual di bridge/bin/)
        elseif ($env['go_binary'] !== null) {
            $modeUsed = 'go_binary';
            $cmd = sprintf(
                '"%s" rest --port=%d --webhook="%s" --webhook-secret="%s"',
                $env['go_binary'],
                $this->port,
                $webhookUrl,
                $this->webhookSecret
            );
            $pid = $this->spawnDetachedProcess($cmd, $this->bridgeDir, $isWindows, [], $this->logFile);
            $this->updateBridgeFlavor('gowa');
        }
        // Jika Node.js sudah ada tapi Baileys belum di-install
        elseif ($env['node_binary'] !== null && $env['node_server_exists'] && !$env['node_modules_ready']) {
            return [
                'success' => false,
                'action'  => 'deps_missing',
                'message' => 'Dependensi Baileys belum terpasang. Klik tombol "Install Node.js + Baileys (1-Klik PHP)" terlebih dahulu.',
                'env'     => $env,
            ];
        } else {
            return [
                'success' => false,
                'action'  => 'no_runtime_found',
                'message' => 'Node.js belum terpasang di server ini. Klik tombol "Install Node.js + Baileys (1-Klik PHP)" untuk mengunduh Node.js Portable otomatis tanpa SSH.',
                'env'     => $env,
            ];
        }

        if ($pid !== null) {
            @file_put_contents($this->pidFile, (string)$pid, LOCK_EX);
        }

        // Tunggu hingga maks 6 detik sampai Ilmuna WA Bridge KITA merespon HTTP 200 valid
        $ready = false;
        for ($i = 0; $i < 12; $i++) {
            usleep(500000);
            if ($this->isOurBridgeResponding()) {
                $ready = true;
                break;
            }
        }

        $switchNote = $portSwitchedFrom !== null ? " (otomatis dipindah dari port {$portSwitchedFrom} yang bentrok)" : '';

        return [
            'success'  => $ready,
            'action'   => 'started',
            'runtime'  => $modeUsed,
            'pid'      => $pid,
            'port'     => $this->port,
            'base_url' => $this->baseUrl,
            'ready'    => $ready,
            'message'  => $ready
                ? "WA Bridge ({$modeUsed}) aktif di port {$this->port}{$switchNote}! Siap mengeluarkan QR / Pairing 8-Digit."
                : "Proses WA Bridge ({$modeUsed}) dipicu di port {$this->port} (PID: " . ($pid ?? 'n/a') . '), namun belum merespon dalam 6 detik. Periksa Log Baileys.',
            'log_file' => $this->logFile,
        ];
    }

    /**
     * Hentikan proses WA Bridge lokal yang dijalankan oleh PHP
     */
    public function stop(): array
    {
        $pid       = $this->readPid();
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        // 1. Minta daemon berhenti bersih lewat HTTP (<50ms, aman tanpa sentuh proses lain)
        if ($this->isLocalhostEndpoint($this->baseUrl) && function_exists('curl_init')) {
            $headers = ['Content-Type: application/json'];
            if ($this->apiKey !== '') {
                $headers[] = 'X-Api-Key: ' . $this->apiKey;
                $headers[] = 'Authorization: Bearer ' . $this->apiKey;
            }
            $ch = curl_init($this->baseUrl . '/api/sessions/' . rawurlencode($this->session) . '/shutdown');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '{}',
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT        => 2,
            ]);
            @curl_exec($ch);
            curl_close($ch);
            usleep(250000);
        }

        // 2. Pastikan PID tercatat benar-benar mati
        if ($pid !== null) {
            if ($isWindows) {
                @exec("taskkill /F /T /PID {$pid} >NUL 2>&1");
            } else {
                @exec("kill -TERM {$pid} >/dev/null 2>&1");
                usleep(200000);
                if ($this->isPidRunning($pid)) {
                    @exec("kill -KILL {$pid} >/dev/null 2>&1");
                }
            }
        }

        // 3. Di Linux cPanel, bersihkan juga proses yatim dengan pola `[s]erver.js`
        //    (tanda kurung siku `[s]` mencegah pkill membunuh subshell dirinya sendiri!)
        if (!$isWindows) {
            $pattern = $this->bridgeDir . '/[s]erver.js';
            @exec('pkill -f ' . escapeshellarg($pattern) . ' >/dev/null 2>&1');
        }

        if (is_file($this->pidFile)) {
            @unlink($this->pidFile);
        }
        $this->syncDeviceStatus('disconnected');

        return [
            'success' => true,
            'message' => $pid !== null
                ? "Proses WA Bridge (PID {$pid}) berhasil dihentikan."
                : 'Seluruh proses WA Bridge lokal telah dihentikan.',
        ];
    }

    /**
     * Putuskan sesi WhatsApp saat ini, bersihkan auth_info, dan mulai ulang agar QR baru muncul
     */
    public function resetSession(): array
    {
        $this->stop();
        usleep(400000);

        $authDir = $this->bridgeDir . DIRECTORY_SEPARATOR . 'auth_info' . DIRECTORY_SEPARATOR . $this->session;
        if (is_dir($authDir)) {
            $this->recursiveDelete($authDir);
        }

        $this->syncDeviceStatus('disconnected');
        $startRes = $this->start();

        return [
            'success' => (bool)($startRes['success'] ?? true),
            'message' => !empty($startRes['success'])
                ? 'Sesi WhatsApp berhasil direset & Baileys dinyalakan ulang. Silakan pindai QR baru.'
                : 'Sesi auth_info berhasil dibersihkan. ' . (string)($startRes['message'] ?? ''),
            'start'   => $startRes,
        ];
    }

    /**
     * Ambil QR Code (auto-start + auto-resolve bentrok port bila bridge lokal belum menyala)
     */
    public function getQrCode(bool $autoStart = true): array
    {
        $justStarted = false;
        if ($autoStart && $this->isLocalhostEndpoint($this->baseUrl) && !$this->isOurBridgeResponding()) {
            $startRes = $this->start();
            if (!$startRes['success']) {
                return $startRes;
            }
            $justStarted = true;
        }

        $env    = $this->detectEnvironment();
        $driver = $this->buildBridgeDriver($env);

        // Jika baru saja dinyalakan, tunggu singkat maks 2,5 detik; jika sudah menyala, respon instan <30ms (auto-poll JS yang me-refresh)
        $qr = $driver->getQrCode();
        if ($justStarted && empty($qr['qr_data_url']) && ($qr['status'] ?? '') !== 'connected') {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                usleep(1200000);
                $qr = $driver->getQrCode();
                if (!empty($qr['qr_data_url']) || ($qr['status'] ?? '') === 'connected') {
                    break;
                }
            }
        }

        return $qr;
    }

    /**
     * Minta Pairing Code 8 digit (auto-start + auto-resolve bentrok port bila bridge lokal belum menyala)
     */
    public function requestPairingCode(string $phoneNumber, bool $autoStart = true): array
    {
        if ($autoStart && $this->isLocalhostEndpoint($this->baseUrl) && !$this->isOurBridgeResponding()) {
            $startRes = $this->start();
            if (!$startRes['success']) {
                return $startRes;
            }
            sleep(2);
        }

        $env = $this->detectEnvironment();
        return $this->buildBridgeDriver($env)->requestPairingCode($phoneNumber);
    }

    /**
     * Baca N baris terakhir log bridge
     */
    public function tailLog(int $lines = 40): string
    {
        return $this->tailFile($this->logFile, $lines);
    }

    /**
     * Baca N baris terakhir log installer
     */
    public function tailInstallerLog(int $lines = 80): string
    {
        return $this->tailFile($this->installerLogFile, $lines);
    }

    private function tailFile(string $path, int $lines): string
    {
        if (!is_file($path)) {
            return '';
        }
        $all = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
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

    private function isBaileysReady(): bool
    {
        $nm = $this->bridgeDir . DIRECTORY_SEPARATOR . 'node_modules';
        return is_dir($nm . DIRECTORY_SEPARATOR . '@whiskeysockets' . DIRECTORY_SEPARATOR . 'baileys')
            && is_dir($nm . DIRECTORY_SEPARATOR . 'qrcode')
            && is_dir($nm . DIRECTORY_SEPARATOR . 'pino');
    }

    /**
     * Cache hasil `node -v` di disk (berdasarkan path + mtime) agar tidak spawn V8 di setiap page load
     */
    private function getCachedNodeVersion(string $nodeBinary, bool $execAllowed, bool $forceRefresh = false): ?string
    {
        $cacheFile = $this->runtimeDir . DIRECTORY_SEPARATOR . 'node_ver.cache';
        $sig       = $nodeBinary . '|' . (string)(@filemtime($nodeBinary) ?: 0);

        if (!$forceRefresh && is_file($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['sig'] ?? '') === $sig && !empty($cached['version'])) {
                return (string)$cached['version'];
            }
        }

        if (!$execAllowed) {
            return null;
        }

        $vOut  = [];
        $vCode = 1;
        @exec('"' . $nodeBinary . '" -v 2>&1', $vOut, $vCode);
        if ($vCode === 0 && !empty($vOut[0])) {
            $ver = trim((string)$vOut[0]);
            $this->ensureRuntimeDir();
            @file_put_contents($cacheFile, json_encode(['sig' => $sig, 'version' => $ver]), LOCK_EX);
            return $ver;
        }
        return null;
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
        // 1. Prioritaskan Node.js Portable yang dipasang oleh PHP
        $portable = $isWindows
            ? ($this->portableNodeDir . '\node.exe')
            : ($this->portableNodeDir . '/bin/node');
        if (is_file($portable) && ($isWindows || is_executable($portable))) {
            return $portable;
        }

        // 2. Lokasi standar sistem, CloudLinux alt-nodejs (v26 -> v18), EasyApache ea-nodejs, & Laragon
        $candidates = $isWindows
            ? [
                'C:\laragon\bin\nodejs\node-v26\node.exe',
                'C:\laragon\bin\nodejs\node-v24\node.exe',
                'C:\laragon\bin\nodejs\node-v22\node.exe',
                'C:\Program Files\nodejs\node.exe',
            ]
            : [
                '/opt/alt/alt-nodejs26/root/usr/bin/node',
                '/opt/alt/alt-nodejs24/root/usr/bin/node',
                '/opt/alt/alt-nodejs22/root/usr/bin/node',
                '/opt/alt/alt-nodejs20/root/usr/bin/node',
                '/opt/alt/alt-nodejs18/root/usr/bin/node',
                '/opt/cpanel/ea-nodejs26/bin/node',
                '/opt/cpanel/ea-nodejs24/bin/node',
                '/opt/cpanel/ea-nodejs22/bin/node',
                '/opt/cpanel/ea-nodejs20/bin/node',
                '/opt/cpanel/ea-nodejs18/bin/node',
                '/usr/local/bin/node',
                '/usr/bin/node',
            ];

        foreach ($candidates as $path) {
            if (is_file($path) && ($isWindows || is_executable($path))) {
                return $path;
            }
        }

        if ($isWindows) {
            $glob = glob('C:\laragon\bin\nodejs\*\node.exe') ?: [];
            if ($glob !== []) {
                return $glob[0];
            }
        } else {
            // Cek juga virtualenv Node cPanel jika pernah ada
            $home = (string)(getenv('HOME') ?: dirname(BASE_PATH));
            $venvGlob = glob($home . '/nodevenv/*/*/bin/node') ?: [];
            if ($venvGlob !== []) {
                return $venvGlob[0];
            }
        }

        return null;
    }

    private function findNpmCli(string $nodeBin, bool $isWindows): ?string
    {
        $nodeDir = dirname($nodeBin);
        if ($isWindows) {
            $candidates = [
                $nodeDir . '\node_modules\npm\bin\npm-cli.js',
                $nodeDir . '\npm.cmd',
            ];
        } else {
            $prefixDir  = dirname($nodeDir);
            $candidates = [
                $prefixDir . '/lib/node_modules/npm/bin/npm-cli.js',
                $nodeDir . '/npm',
                '/usr/local/bin/npm',
                '/usr/bin/npm',
            ];
        }

        foreach ($candidates as $cand) {
            if (is_file($cand)) {
                return $cand;
            }
        }
        return null;
    }

    private function findPhpCliBinary(bool $isWindows): ?string
    {
        if ($isWindows) {
            if (defined('PHP_BINARY') && is_file(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0) {
                return PHP_BINARY;
            }
            $glob = glob('C:\laragon\bin\php\*\php.exe') ?: [];
            return $glob[0] ?? null;
        }

        // Hindari php-fpm / lsphp — cari binary PHP CLI sejati
        if (defined('PHP_BINARY') && is_file(PHP_BINARY)) {
            $base = strtolower(basename(PHP_BINARY));
            if ($base === 'php' || $base === 'php-cli' || strpos($base, 'lsphp') === false && strpos($base, 'fpm') === false) {
                return PHP_BINARY;
            }
        }

        $candidates = [
            '/usr/local/bin/php',
            '/opt/cpanel/ea-php74/root/usr/bin/php',
            '/opt/cpanel/ea-php81/root/usr/bin/php',
            '/opt/cpanel/ea-php82/root/usr/bin/php',
            '/opt/cpanel/ea-php83/root/usr/bin/php',
            '/usr/bin/php',
        ];
        foreach ($candidates as $cand) {
            if (is_file($cand) && is_executable($cand)) {
                return $cand;
            }
        }
        return null;
    }

    private function resolveWebhookUrl(): string
    {
        $savedBase = (string)wa_get_setting('app_public_url', '', $this->pdo);
        if ($savedBase !== '' && (str_starts_with($savedBase, 'http://') || str_starts_with($savedBase, 'https://'))) {
            return rtrim($savedBase, '/') . '/webhook.php';
        }
        return rtrim(BASE_URL, '/') . '/webhook.php';
    }

    private function ensureRuntimeDir(): void
    {
        if (!is_dir($this->runtimeDir)) {
            @mkdir($this->runtimeDir, 0755, true);
        }
    }

    private function appendInstallerLog(string $line): void
    {
        $this->ensureRuntimeDir();
        $entry = '[' . date('H:i:s') . '] ' . $line . "\n";
        @file_put_contents($this->installerLogFile, $entry, FILE_APPEND | LOCK_EX);
    }

    private function writeInstallState(string $status, string $phase, int $percent, string $message): void
    {
        $this->ensureRuntimeDir();
        $payload = [
            'status'     => $status,
            'phase'      => $phase,
            'percent'    => max(0, min(100, $percent)),
            'message'    => $message,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        @file_put_contents(
            $this->installerStateFile,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private function readInstallState(): array
    {
        if (!is_file($this->installerStateFile)) {
            return [
                'status'     => 'idle',
                'phase'      => 'idle',
                'percent'    => 0,
                'message'    => '',
                'updated_at' => '',
            ];
        }
        $decoded = json_decode((string)@file_get_contents($this->installerStateFile), true);
        return is_array($decoded) ? $decoded : ['status' => 'idle', 'phase' => 'idle', 'percent' => 0, 'message' => '', 'updated_at' => ''];
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

    /**
     * Verifikasi bahwa endpoint lokal benar-benar merespon sebagai Ilmuna WA Bridge
     * (bukan aplikasi lain milik akun cPanel lain yang kebetulan memakai port sama!)
     */
    private function isOurBridgeResponding(?string $overrideUrl = null): bool
    {
        $targetBase = rtrim($overrideUrl ?: $this->baseUrl, '/');
        if ($targetBase === '' || !function_exists('curl_init')) {
            return false;
        }

        $flavor = (string)wa_get_setting('wa_bridge_flavor', WA_BRIDGE_FLAVOR, $this->pdo);
        $path   = $flavor === 'gowa'
            ? '/app/devices'
            : ('/api/sessions/' . rawurlencode($this->session) . '/status');

        $headers = ['Accept: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'X-Api-Key: ' . $this->apiKey;
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init($targetBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            return false;
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return false;
        }

        if ($flavor === 'gowa') {
            return array_key_exists('results', $decoded) || array_key_exists('code', $decoded);
        }

        return ($decoded['app'] ?? '') === 'ilmuna-wa-bridge'
            || (($decoded['session'] ?? '') === $this->session && isset($decoded['status']));
    }

    /**
     * Cari port lokal 127.0.0.1 yang benar-benar bebas dan dapat di-bind oleh kernel OS
     */
    private function findFreeLocalPort(int $start = 38421, int $end = 38520): ?int
    {
        for ($p = $start; $p <= $end; $p++) {
            if ($this->isPortOpen('127.0.0.1', $p)) {
                continue;
            }
            $sock = @stream_socket_server("tcp://127.0.0.1:{$p}", $errno, $errstr);
            if (is_resource($sock)) {
                fclose($sock);
                return $p;
            }
        }
        return null;
    }

    /**
     * Simpan URL bridge terbaru ke wa_devices & wa_settings saat otomatis pindah port bebas
     */
    private function persistBridgeBaseUrl(string $newUrl): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE wa_devices
                 SET endpoint_url = :url
                 WHERE driver = 'md_bridge'
                 ORDER BY is_default DESC, id ASC
                 LIMIT 1"
            );
            $stmt->execute([':url' => $newUrl]);
            wa_set_setting('wa_bridge_base_url', $newUrl, $this->pdo);
        } catch (Throwable $e) {
            // Non-blocking
        }
    }

    private function readPid(): ?int
    {
        return $this->readPidFromFile($this->pidFile);
    }

    private function readInstallerPid(): ?int
    {
        return $this->readPidFromFile($this->installerPidFile);
    }

    private function readPidFromFile(string $file): ?int
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = trim((string)@file_get_contents($file));
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

    private function spawnDetachedProcess(string $command, string $cwd, bool $isWindows, array $envVars = [], ?string $logTarget = null): ?int
    {
        $logDest = $logTarget ?: $this->logFile;

        if ($isWindows) {
            $envPrefix = '';
            foreach ($envVars as $k => $v) {
                $safeVal = str_replace('"', '', $v);
                $envPrefix .= "set \"{$k}={$safeVal}\" && ";
            }
            $logEsc  = str_replace('"', '', $logDest);
            $batName = 'run_' . substr(md5($command . $logDest), 0, 8) . '.bat';
            $batFile = $this->runtimeDir . DIRECTORY_SEPARATOR . $batName;
            $batContent = "@echo off\r\ncd /d \"{$cwd}\"\r\n{$envPrefix}{$command} >> \"{$logEsc}\" 2>&1\r\n";
            @file_put_contents($batFile, $batContent, LOCK_EX);

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

        // Linux / cPanel background detached process:
        // Tutup semua file descriptor warisan (>2, termasuk socket LiteSpeed lsphp & MySQL!)
        // sebelum men-spawn proses latar belakang agar request PHP tidak pernah menggantung / ERR_CONNECTION_CLOSED!
        $envPrefix = '';
        foreach ($envVars as $k => $v) {
            $envPrefix .= $k . '=' . escapeshellarg($v) . ' ';
        }
        $closeFds = 'for fd in /proc/$$/fd/*; do f=${fd##*/}; case "$f" in ""|*[!0-9]*) ;; *) [ "$f" -gt 2 ] && eval "exec ${f}>&-" 2>/dev/null ;; esac; done;';
        $linuxCmd = sprintf(
            'cd %s && ( %s %snohup %s >> %s 2>&1 </dev/null & echo $! )',
            escapeshellarg($cwd),
            $closeFds,
            $envPrefix,
            $command,
            escapeshellarg($logDest)
        );
        $out = [];
        @exec($linuxCmd, $out);
        $pidStr = trim((string)($out[0] ?? ''));
        return ctype_digit($pidStr) ? (int)$pidStr : null;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!file_exists($dir) && !is_link($dir)) {
            return;
        }
        if (is_file($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->recursiveDelete($dir . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($dir);
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
