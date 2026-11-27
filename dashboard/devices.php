<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — DASHBOARD 2: DEVICES & AUTO-INSTALLER NODE.JS BAILEYS VIA PHP
 * ==============================================================================
 * - Hubungkan WhatsApp pakai Node.js Portable + Baileys 100% melalui PHP Web
 *   (tanpa SSH, tanpa CLI manual, tanpa Node.js bawaan hosting!)
 * - Auto-QR polling & Pairing Code 8-Digit + Live Log Installer/Bridge
 * - Fallback otomatis Cron Job cPanel jika web PHP membatasi exec/proc_open
 * - Juga mendukung Meta Cloud API Resmi & HTTP Gateway (Whapi / Fonnte / GoWA)
 * ==============================================================================
 */

require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/auth.php';

wa_require_admin(isset($_GET['ajax']) && $_GET['ajax'] === '1');

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $pdo = getDBConnection();

    // Simpan BASE_URL publik aktual agar Cron Job CLI memakai webhook HTTPS yang tepat
    if (!empty($_SERVER['HTTP_HOST'])) {
        $currPublic = rtrim(BASE_URL, '/');
        if (wa_get_setting('app_public_url', '', $pdo) !== $currPublic) {
            wa_set_setting('app_public_url', $currPublic, $pdo);
        }
    }

    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            wa_require_csrf_ajax($body);
            $act = strtolower(trim((string)($body['action'] ?? '')));

            if ($act === 'save_driver_mode') {
                $mode   = in_array($body['wa_driver'] ?? '', ['hybrid', 'cloud_api', 'md_bridge'], true) ? (string)$body['wa_driver'] : 'hybrid';
                $flavor = in_array($body['wa_bridge_flavor'] ?? '', ['generic', 'gowa', 'waha', 'whapi', 'fonnte'], true) ? (string)$body['wa_bridge_flavor'] : 'generic';
                wa_set_setting('wa_driver', $mode, $pdo);
                wa_set_setting('wa_bridge_flavor', $flavor, $pdo);
                wa_json_response(true, "Mode transport diperbarui ke {$mode} (flavor: {$flavor}).", ['wa_driver' => $mode, 'wa_bridge_flavor' => $flavor]);
            }

            if ($act === 'save_device') {
                $id     = (int)($body['id'] ?? 0);
                $name   = trim((string)($body['name'] ?? ''));
                $driver = ($body['driver'] ?? '') === 'cloud_api' ? 'cloud_api' : 'md_bridge';
                $phone  = trim((string)($body['phone_number'] ?? ''));
                $url    = trim((string)($body['endpoint_url'] ?? ''));
                $token  = trim((string)($body['api_token'] ?? ''));
                $secret = trim((string)($body['webhook_secret'] ?? ''));
                $isDef  = !empty($body['is_default']) ? 1 : 0;

                if ($name === '' || $url === '') {
                    wa_json_response(false, 'Nama device dan Endpoint URL wajib diisi.', [], 422);
                }

                if ($isDef === 1) {
                    $pdo->prepare('UPDATE wa_devices SET is_default = 0 WHERE driver = :drv')->execute([':drv' => $driver]);
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE wa_devices
                         SET name = :name, driver = :drv, phone_number = :phone, endpoint_url = :url,
                             api_token = CASE WHEN :tok <> "" THEN :tok2 ELSE api_token END,
                             webhook_secret = CASE WHEN :sec <> "" THEN :sec2 ELSE webhook_secret END,
                             is_default = :def
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':name' => $name, ':drv' => $driver, ':phone' => $phone !== '' ? $phone : null,
                        ':url' => $url, ':tok' => $token, ':tok2' => $token, ':sec' => $secret, ':sec2' => $secret,
                        ':def' => $isDef, ':id' => $id,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO wa_devices (name, driver, phone_number, endpoint_url, api_token, webhook_secret, status, is_default)
                         VALUES (:name, :drv, :phone, :url, :tok, :sec, "disconnected", :def)'
                    );
                    $stmt->execute([
                        ':name' => $name, ':drv' => $driver, ':phone' => $phone !== '' ? $phone : null,
                        ':url' => $url, ':tok' => $token, ':sec' => $secret, ':def' => $isDef,
                    ]);
                    $id = (int)$pdo->lastInsertId();
                }

                $row = $pdo->prepare('SELECT id, name, driver, phone_number, endpoint_url, status, is_default, last_seen_at FROM wa_devices WHERE id = :id');
                $row->execute([':id' => $id]);
                wa_json_response(true, 'Konfigurasi device berhasil disimpan.', $row->fetch(PDO::FETCH_ASSOC));
            }

            if ($act === 'test_device') {
                $id   = (int)($body['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM wa_devices WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $id]);
                $dev = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$dev) {
                    wa_json_response(false, 'Device tidak ditemukan.', [], 404);
                }

                if ($dev['driver'] === 'cloud_api') {
                    $drv = new CloudApiDriver([
                        'phone_number_id' => (string)($dev['phone_number'] ?? ''),
                        'graph_url'       => (string)($dev['endpoint_url'] ?: WA_CLOUD_GRAPH_URL),
                        'access_token'    => (string)($dev['api_token'] ?? ''),
                        'app_secret'      => (string)($dev['webhook_secret'] ?? ''),
                    ]);
                } else {
                    $drv = new BridgeMdDriver([
                        'base_url'       => (string)($dev['endpoint_url'] ?: WA_BRIDGE_BASE_URL),
                        'api_key'        => (string)($dev['api_token'] ?? ''),
                        'webhook_secret' => (string)($dev['webhook_secret'] ?? ''),
                        'flavor'         => (string)wa_get_setting('wa_bridge_flavor', WA_BRIDGE_FLAVOR, $pdo),
                    ]);
                }

                $hc = $drv->healthCheck();
                $newStatus = in_array($hc['status'] ?? '', ['connected', 'qr_pending', 'disconnected'], true) ? $hc['status'] : 'disconnected';
                $pdo->prepare('UPDATE wa_devices SET status = :st, last_seen_at = NOW() WHERE id = :id')
                    ->execute([':st' => $newStatus, ':id' => $id]);

                wa_json_response((bool)$hc['success'], $hc['success'] ? "Device aktif (status: {$newStatus})." : ('Koneksi gagal: ' . ($hc['error'] ?? 'Tidak merespon')), [
                    'id'     => $id,
                    'status' => $newStatus,
                    'health' => $hc,
                ]);
            }

            if ($act === 'test_send_interactive') {
                $to = wa_normalize_phone((string)($body['phone'] ?? ''));
                if ($to === '') {
                    wa_json_response(false, 'Masukkan nomor WA tujuan yang valid (contoh: 081234567890).', [], 422);
                }
                $transport = TransportFactory::create($pdo);
                $res = $transport->sendButtons(
                    $to,
                    "Assalamu'alaikum! Ini adalah pesan uji tombol interaktif dari *Ilmuna WA Bot* (Baileys PHP Bridge).",
                    [
                        ['id' => 'MENU_MAIN', 'title' => '📋 Buka Menu Utama'],
                        ['id' => 'MENU_CONTACT_ADMIN', 'title' => '📞 Hubungi Admin'],
                    ],
                    ['header' => 'Uji Koneksi WA Bot', 'footer' => 'Ilmuna WA Bot · Verified']
                );
                wa_json_response((bool)$res['success'], $res['success'] ? "Pesan uji terkirim via {$res['driver']}!" : ('Gagal kirim: ' . ($res['error'] ?? 'Error')), $res);
            }

            if ($act === 'delete_device') {
                $id = (int)($body['id'] ?? 0);
                $pdo->prepare('DELETE FROM wa_devices WHERE id = :id')->execute([':id' => $id]);
                wa_json_response(true, 'Device berhasil dihapus.', ['id' => $id]);
            }

            wa_json_response(false, 'Aksi tidak dikenali.', [], 400);
        }

        // GET: lazy-load devices + environment + bridge & installer status
        $devices = $pdo->query(
            'SELECT id, name, driver, phone_number, endpoint_url, status, is_default, last_seen_at
             FROM wa_devices ORDER BY is_default DESC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $mgr       = new BridgeManager($pdo);
        $bridgeSt  = $mgr->getStatus();
        $env       = $bridgeSt['environment'];
        // Reuse $env & $bridgeSt['running'] agar 0 exec tambahan & 0 cURL ganda!
        $installSt = $mgr->getInstallStatus($env, (bool)$bridgeSt['running']);
        $phpBin    = $env['php_cli_binary'] ?: '/usr/local/bin/php';

        wa_json_response(true, 'Data device berhasil dimuat.', [
            'devices'          => $devices,
            'wa_driver'        => (string)wa_get_setting('wa_driver', WA_DRIVER, $pdo),
            'wa_bridge_flavor' => (string)wa_get_setting('wa_bridge_flavor', WA_BRIDGE_FLAVOR, $pdo),
            'webhook_url'      => rtrim(BASE_URL, '/') . '/webhook',
            'verify_token'     => WA_CLOUD_VERIFY_TOKEN,
            'environment'      => $env,
            'bridge_status'    => $bridgeSt,
            'install_status'   => $installSt,
            'cron_commands'    => [
                'installer' => $phpBin . ' ' . BASE_PATH . '/workers/bridge-installer.php --auto-start >/dev/null 2>&1',
                'watchdog'  => '* * * * * ' . $phpBin . ' ' . BASE_PATH . '/workers/bridge-watchdog.php >/dev/null 2>&1',
                'broadcast' => '* * * * * ' . $phpBin . ' ' . BASE_PATH . '/workers/broadcast-worker.php >/dev/null 2>&1',
                'reminder'  => '*/15 * * * * ' . $phpBin . ' ' . BASE_PATH . '/workers/reminder-scheduler.php >/dev/null 2>&1',
            ],
        ]);
    } catch (Throwable $e) {
        wa_json_response(false, 'Galat Devices: ' . $e->getMessage(), [], 500);
    }
}

$pageTitle = 'Devices & Koneksi Baileys PHP';
$pageIcon  = 'solar:smartphone-2-bold';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
.wa-ready-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}
@media (max-width: 960px) { .wa-ready-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .wa-ready-grid { grid-template-columns: 1fr; } }
.wa-ready-chip {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 14px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 11px;
    box-shadow: var(--shadow-sm);
}
.wa-ready-ico {
    width: 38px;
    height: 38px;
    border-radius: 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    background: rgba(13, 122, 135, 0.10);
    color: var(--lumina-teal);
}
.wa-ready-meta { min-width: 0; }
.wa-ready-lbl { font-size: 11.5px; color: var(--lumina-text-muted); font-weight: 600; }
.wa-ready-val { font-size: 13.5px; font-weight: 700; color: var(--lumina-navy); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.wa-progress-track {
    width: 100%;
    height: 8px;
    border-radius: 999px;
    background: #E2E8F0;
    overflow: hidden;
    margin-top: 10px;
}
.wa-progress-bar {
    height: 100%;
    width: 0%;
    border-radius: 999px;
    background: linear-gradient(90deg, #1D3B5E 0%, #0D7A87 60%, #F59E0B 100%);
    transition: width .35s ease;
}
.wa-console-box {
    background: #0F172A;
    color: #E2E8F0;
    border-radius: 12px;
    padding: 12px 14px;
    font-family: 'JetBrains Mono', Consolas, monospace;
    font-size: 11.5px;
    line-height: 1.55;
    max-height: 220px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-word;
    border: 1px solid #1E293B;
}
.wa-cron-alert {
    margin-top: 12px;
    padding: 12px 14px;
    border-radius: 12px;
    background: #FFFBEB;
    border: 1px solid #FCD34D;
    color: #92400E;
    font-size: 12.5px;
}
</style>

<!-- TABS SEGMENTED CONTROL -->
<div class="curriculum-main-tabs" id="deviceTabs" role="tablist">
    <button type="button" class="curriculum-tab-btn is-active" data-tab="qr">
        <iconify-icon icon="solar:qr-code-bold"></iconify-icon>
        <span class="curriculum-tab-label">Hubungkan WA (Baileys PHP)</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="devices">
        <iconify-icon icon="solar:smartphone-2-bold"></iconify-icon>
        <span class="curriculum-tab-label">Daftar Device &amp; Mode</span>
        <span class="curriculum-tab-counter" id="cntDevTab">0</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="apache">
        <iconify-icon icon="solar:server-square-cloud-bold"></iconify-icon>
        <span class="curriculum-tab-label">Diagnosa Server &amp; Cron cPanel</span>
    </button>
</div>

<!-- TAB 1 (DEFAULT): HUBUNGKAN WHATSAPP VIA NODE.JS PORTABLE + BAILEYS (100% LEWAT PHP) -->
<div class="tab-panel is-active" data-tab-panel="qr">
    <!-- 4 INDIKATOR KESIAPAN BAILEYS -->
    <div class="wa-ready-grid">
        <div class="wa-ready-chip">
            <span class="wa-ready-ico" id="icoNode"><iconify-icon icon="solar:cpu-bolt-bold"></iconify-icon></span>
            <div class="wa-ready-meta">
                <div class="wa-ready-lbl">1. Runtime Node.js</div>
                <div class="wa-ready-val" id="stNodeVal">Memeriksa...</div>
            </div>
        </div>
        <div class="wa-ready-chip">
            <span class="wa-ready-ico" id="icoBaileys"><iconify-icon icon="solar:box-bold"></iconify-icon></span>
            <div class="wa-ready-meta">
                <div class="wa-ready-lbl">2. Paket Baileys</div>
                <div class="wa-ready-val" id="stBaileysVal">Memeriksa...</div>
            </div>
        </div>
        <div class="wa-ready-chip">
            <span class="wa-ready-ico" id="icoDaemon"><iconify-icon icon="solar:server-bold"></iconify-icon></span>
            <div class="wa-ready-meta">
                <div class="wa-ready-lbl">3. Layanan Bridge</div>
                <div class="wa-ready-val" id="stDaemonVal">Memeriksa...</div>
            </div>
        </div>
        <div class="wa-ready-chip">
            <span class="wa-ready-ico" id="icoWa"><iconify-icon icon="solar:chat-round-check-bold"></iconify-icon></span>
            <div class="wa-ready-meta">
                <div class="wa-ready-lbl">4. Status WhatsApp</div>
                <div class="wa-ready-val" id="stWaVal">Memeriksa...</div>
            </div>
        </div>
    </div>

    <!-- WIZARD 1-KLIK AUTO-INSTALLER NODE.JS PORTABLE + BAILEYS VIA PHP -->
    <div class="ilmuna-card" style="margin-bottom:14px;">
        <div class="ilmuna-card-body">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <div>
                    <strong style="color:var(--lumina-navy);font-family:'Outfit',sans-serif;font-size:15px;">
                        Auto-Installer Node.js Portable + Baileys (Tanpa SSH / Terminal)
                    </strong>
                    <div id="installHintText" style="font-size:12.5px;color:var(--lumina-text-muted);margin-top:2px;">
                        PHP otomatis mengunduh Node.js Portable, memasang <code>@whiskeysockets/baileys</code>, dan menyalakan bridge di latar belakang.
                    </div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--gold" id="btnInstallAll">
                        <iconify-icon icon="solar:cloud-download-bold" width="17"></iconify-icon>
                        <span>1. Install Node.js + Baileys</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" id="btnUpgradeNode" style="display:none;" title="Unduh Node.js v26.13.1 LTS Portable via PHP">
                        <iconify-icon icon="solar:arrow-up-bold"></iconify-icon> Upgrade Node v26.13.1
                    </button>
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" id="btnStartBridge">
                        <iconify-icon icon="solar:play-bold" width="16"></iconify-icon>
                        <span>2. Jalankan Baileys</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" id="btnRestartBridge" title="Restart Layanan Baileys">
                        <iconify-icon icon="solar:restart-bold"></iconify-icon> Restart
                    </button>
                    <button type="button" class="btn btn-sm btn-danger" id="btnResetSession" title="Putuskan & Hapus Sesi WA">
                        <iconify-icon icon="solar:logout-3-bold"></iconify-icon> Reset Sesi
                    </button>
                </div>
            </div>

            <div id="installProgressWrap" style="display:none;margin-top:10px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;color:var(--lumina-navy);">
                    <span id="installPhaseLabel">Memproses...</span>
                    <span id="installPercentLabel">0%</span>
                </div>
                <div class="wa-progress-track">
                    <div class="wa-progress-bar" id="installProgressBar"></div>
                </div>
            </div>

            <!-- FALLBACK OTOMATIS JIKA WEB PHP MEMBLOKIR EXEC/PROC_OPEN -->
            <div id="cronFallbackBox" class="wa-cron-alert" style="display:none;">
                <div style="font-weight:700;margin-bottom:6px;">
                    <iconify-icon icon="solar:shield-warning-bold" style="vertical-align:-2px;"></iconify-icon>
                    Web PHP Hosting Membatasi <code>exec()</code> — Gunakan 1 Baris Cron Job cPanel (Tetap Tanpa SSH!):
                </div>
                <div style="margin-bottom:8px;">
                    Buka <strong>cPanel → Cron Jobs</strong>, tempel perintah berikut sekali (setel tiap 1 menit sampai status di atas menjadi <strong>Siap</strong>, lalu ganti ke Cron Watchdog):
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="text" id="cronInstallerInline" class="form-control font-mono" readonly style="font-size:11.5px;background:#FFFFFF;">
                    <button type="button" class="btn btn-sm btn-gold" id="btnCopyCronInstaller">
                        <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" id="btnCheckInstallNow">
                        <iconify-icon icon="solar:refresh-bold"></iconify-icon> Cek
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- GRID 2 KOLOM: QR / PAIRING + UJI KIRIM & LIVE LOG -->
    <div class="wa-grid-2">
        <div class="ilmuna-card">
            <div class="ilmuna-card-body">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                    <strong style="color:var(--lumina-navy);font-family:'Outfit',sans-serif;font-size:15px;">3. Tautkan Nomor WhatsApp Bot</strong>
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="btn btn-sm btn-primary" id="btnFetchQr">
                            <iconify-icon icon="solar:qr-code-bold"></iconify-icon> Ambil QR
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" id="btnRefreshStatus">
                            <iconify-icon icon="solar:refresh-bold"></iconify-icon> Status
                        </button>
                    </div>
                </div>
                <div class="wa-qr-box" id="qrPreviewBox">
                    <iconify-icon icon="solar:qr-code-bold-duotone" width="54" style="color:var(--lumina-teal);"></iconify-icon>
                    <div class="text-muted" style="font-size:13px;">
                        Setelah <strong>Node.js + Baileys</strong> aktif, klik <strong>Ambil QR</strong> atau masukkan nomor WA di bawah untuk meminta <strong>Pairing Code 8-Digit</strong>.
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:12px;">
                    <input type="text" id="inputPairingPhone" class="form-control" placeholder="Nomor WA Bot (misal: 081234567890)">
                    <button type="button" class="btn btn-gold" id="btnReqPairing">
                        <iconify-icon icon="solar:key-bold"></iconify-icon> Pairing 8-Digit
                    </button>
                </div>
                <div style="margin-top:10px;font-size:12px;color:var(--lumina-text-muted);">
                    <iconify-icon icon="solar:info-circle-bold" style="vertical-align:-2px;color:var(--lumina-teal);"></iconify-icon>
                    Di HP WhatsApp: buka <strong>Perangkat Tertaut → Tautkan Perangkat</strong> (scan QR atau pilih <em>Tautkan dengan nomor telepon</em>).
                </div>
            </div>
        </div>

        <div class="ilmuna-card">
            <div class="ilmuna-card-body">
                <strong style="color:var(--lumina-navy);font-family:'Outfit',sans-serif;font-size:15px;display:block;margin-bottom:10px;">4. Uji Kirim Pesan &amp; Log Live Baileys</strong>
                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <input type="text" id="testTargetPhone" class="form-control" placeholder="Nomor WA tujuan uji (0812xxxxxxxx)">
                    <button type="button" class="btn btn-primary" id="btnSendTestWa" style="white-space:nowrap;">
                        <iconify-icon icon="solar:plain-3-bold" width="17"></iconify-icon> Kirim Uji
                    </button>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:6px;flex-wrap:wrap;">
                    <div class="ilmuna-pill-group" id="logSwitchPills">
                        <button type="button" class="ilmuna-pill-btn is-active" data-log="installer">Log Installer</button>
                        <button type="button" class="ilmuna-pill-btn" data-log="bridge">Log Baileys</button>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline" id="btnLoadLogs">
                        <iconify-icon icon="solar:refresh-bold"></iconify-icon> Muat Log
                    </button>
                </div>
                <div class="wa-console-box" id="liveLogConsole">Menunggu log dari server PHP...</div>

                <div style="margin-top:12px;padding:10px 12px;border-radius:10px;background:#F8FAFC;border:1px solid #E2E8F0;font-size:12px;color:#475569;">
                    <strong>Webhook URL:</strong>
                    <div style="display:flex;gap:6px;align-items:center;margin-top:4px;">
                        <code id="webhookUrlBox" style="flex:1;word-break:break-all;">-</code>
                        <button type="button" class="btn btn-sm btn-outline" id="btnCopyWebhook"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>
                    </div>
                    <div style="margin-top:4px;">Meta Verify Token: <code id="verifyTokenBox">-</code></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TAB 2: DAFTAR DEVICE & MODE TRANSPORT -->
<div class="tab-panel" data-tab-panel="devices">
    <div class="ilmuna-filter-box" style="margin-bottom:14px;">
        <div class="ilmuna-filter-topline">
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <select id="selDriverMode" class="ilmuna-select" aria-label="Mode Transport WA">
                    <option value="md_bridge">Baileys Multi-Device (Node.js via PHP)</option>
                    <option value="hybrid">Mode Hybrid (Cloud API + Baileys MD)</option>
                    <option value="cloud_api">Pure PHP: Meta Cloud API Resmi</option>
                </select>
                <select id="selBridgeFlavor" class="ilmuna-select" aria-label="Flavor MD Gateway">
                    <option value="generic">Ilmuna Baileys Bridge (Node.js Portable via PHP)</option>
                    <option value="gowa">Go-WhatsApp Binary (Tanpa Node.js)</option>
                    <option value="whapi">Whapi.cloud (HTTP Gateway cPanel)</option>
                    <option value="fonnte">Fonnte API (HTTP Gateway cPanel)</option>
                    <option value="waha">WAHA HTTP API</option>
                </select>
                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--gold" id="btnSaveDriverMode">
                    <iconify-icon icon="solar:disk-bold" width="16"></iconify-icon>
                    <span>Simpan Mode</span>
                </button>
            </div>
            <div class="ilmuna-filter-actions">
                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" id="btnAddDevice">
                    <iconify-icon icon="solar:add-circle-bold" width="17"></iconify-icon>
                    <span>Tambah Device</span>
                </button>
            </div>
        </div>
    </div>

    <div class="ilmuna-card">
        <div class="table-responsive">
            <table class="ilmuna-table ilmuna-table-stack">
                <thead>
                    <tr>
                        <th>Nama Device</th>
                        <th>Driver</th>
                        <th>Nomor / Phone ID</th>
                        <th>Endpoint URL</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody id="devicesTbody">
                    <tr><td colspan="6" class="text-center text-muted" style="padding:28px;">
                        <iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin" width="20"></iconify-icon> Memuat daftar device...
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 3: DIAGNOSA SERVER & CRON JOB CPANEL -->
<div class="tab-panel" data-tab-panel="apache">
    <div class="wa-grid-2">
        <div class="ilmuna-card">
            <div class="ilmuna-card-body" id="envDiagBox">
                <iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin"></iconify-icon> Mendiagnosa lingkungan server PHP/Apache...
            </div>
        </div>
        <div class="ilmuna-card">
            <div class="ilmuna-card-body">
                <strong style="color:var(--lumina-navy);font-family:'Outfit',sans-serif;font-size:15px;display:block;margin-bottom:10px;">Perintah Cron Job cPanel (Tanpa SSH / Terminal)</strong>
                <div class="form-group">
                    <label class="form-label">1. Auto-Installer Node.js Portable + Baileys (jalankan sekali jika web <code>exec</code> diblokir)</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="cronInstaller" class="form-control font-mono" readonly style="font-size:11.5px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="WaPanel.copyText(document.getElementById('cronInstaller').value, 'Cron Auto-Installer')"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">2. Watchdog Baileys (tiap 1 menit — jaga Baileys selalu online di cPanel)</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="cronWatchdog" class="form-control font-mono" readonly style="font-size:11.5px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="WaPanel.copyText(document.getElementById('cronWatchdog').value, 'Cron Watchdog')"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">3. Antrean Broadcast Anti-Banned (tiap 1 menit)</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="cronBroadcast" class="form-control font-mono" readonly style="font-size:11.5px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="WaPanel.copyText(document.getElementById('cronBroadcast').value, 'Cron Broadcast')"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">4. Pengingat Otomatis H-1 Tugas/Proyek/CBT (tiap 15 menit)</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="cronReminder" class="form-control font-mono" readonly style="font-size:11.5px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="WaPanel.copyText(document.getElementById('cronReminder').value, 'Cron Reminder')"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- UNIVERSAL MODAL TAMBAH / EDIT DEVICE -->
<div class="ilm-modal-backdrop" id="modalDevice">
    <div class="ilm-modal-dialog" style="max-width:620px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:smartphone-2-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="modalDeviceTitle">Konfigurasi Device WA</h3>
                    <p class="ilm-modal-header__subtitle">Baileys Node.js via PHP, Meta Cloud API Resmi, atau HTTP Gateway</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalDevice')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formDevice">
            <input type="hidden" id="devId" value="0">
            <div class="ilm-modal-body" style="max-height:calc(86vh - 160px);">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Nama Device</label>
                        <input type="text" id="devName" class="form-control" required placeholder="Misal: Ilmuna Baileys Utama">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tipe Driver</label>
                        <select id="devDriver" class="form-control">
                            <option value="md_bridge">md_bridge (Baileys PHP / GoWA / Whapi / Fonnte)</option>
                            <option value="cloud_api">cloud_api (Meta Cloud API Resmi)</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Nomor WA / Meta Phone Number ID</label>
                        <input type="text" id="devPhone" class="form-control" placeholder="62812... atau Phone Number ID">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Endpoint URL</label>
                        <input type="url" id="devUrl" class="form-control" required placeholder="http://127.0.0.1:3000 atau https://graph.facebook.com/v21.0">
                    </div>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">API Token / Access Token</label>
                        <input type="password" id="devToken" class="form-control" placeholder="Biarkan kosong jika tidak mengubah token">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Webhook Secret / Meta App Secret</label>
                        <input type="password" id="devSecret" class="form-control" placeholder="Biarkan kosong jika tidak mengubah secret">
                    </div>
                </div>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;">
                    <input type="checkbox" id="devDefault" value="1"> Jadikan device default untuk driver ini
                </label>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalDevice')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon><span>Batal</span>
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:disk-bold"></iconify-icon><span>Simpan Device</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let devicesList = [];
    let currentLogMode = 'installer';
    let cachedLogs = { installer: '', bridge: '' };
    let installPollTimer = null;
    let qrPollTimer = null;
    const tbody = document.getElementById('devicesTbody');

    function setChipStyle(icoEl, ok, warn) {
        if (!icoEl) return;
        if (ok) {
            icoEl.style.background = 'rgba(16, 185, 129, 0.14)';
            icoEl.style.color = '#059669';
        } else if (warn) {
            icoEl.style.background = 'rgba(245, 158, 11, 0.16)';
            icoEl.style.color = '#D97706';
        } else {
            icoEl.style.background = 'rgba(239, 68, 68, 0.12)';
            icoEl.style.color = '#DC2626';
        }
    }

    function renderReadiness(env, bridgeSt, instSt) {
        env = env || {};
        bridgeSt = bridgeSt || {};
        instSt = instSt || {};

        const hasNode = Boolean(env.node_binary);
        const hasBaileys = Boolean(env.node_modules_ready);
        const isRunning = Boolean(bridgeSt.running || instSt.port_open);
        const waStatus = bridgeSt.wa_status || 'disconnected';

        document.getElementById('stNodeVal').textContent = hasNode
            ? ((env.node_version || 'Terpasang') + (env.node_is_portable ? ' (Portable)' : ''))
            : 'Belum Terpasang';
        setChipStyle(document.getElementById('icoNode'), hasNode, false);

        const upgBtn = document.getElementById('btnUpgradeNode');
        if (upgBtn) {
            const targetVer = env.node_target_version || 'v26.13.1';
            const isLatest = env.node_version && String(env.node_version).indexOf(targetVer) === 0;
            upgBtn.style.display = (hasNode && !isLatest && env.can_auto_install_node) ? 'inline-flex' : 'none';
        }

        document.getElementById('stBaileysVal').textContent = hasBaileys ? 'Siap (@whiskeysockets)' : 'Belum Diinstall';
        setChipStyle(document.getElementById('icoBaileys'), hasBaileys, hasNode);

        document.getElementById('stDaemonVal').textContent = isRunning
            ? ('Aktif' + (bridgeSt.pid ? ' (PID ' + bridgeSt.pid + ')' : ''))
            : 'Belum Menyala';
        setChipStyle(document.getElementById('icoDaemon'), isRunning, hasBaileys);

        const waLabel = waStatus === 'connected'
            ? ('Terhubung' + (bridgeSt.me && bridgeSt.me.id ? ' · ' + String(bridgeSt.me.id).split(':')[0] : ''))
            : (waStatus === 'qr_pending' ? 'Menunggu Scan QR' : 'Belum Terhubung');
        document.getElementById('stWaVal').textContent = waLabel;
        setChipStyle(document.getElementById('icoWa'), waStatus === 'connected', waStatus === 'qr_pending');

        // Tampilkan box Cron fallback otomatis bila web PHP membatasi exec/proc_open
        const cronBox = document.getElementById('cronFallbackBox');
        if (!env.can_spawn_process && env.is_local_endpoint) {
            cronBox.style.display = 'block';
            document.getElementById('cronInstallerInline').value = env.cron_installer_cmd || '';
        } else {
            cronBox.style.display = 'none';
        }

        // Progres installer
        const progWrap = document.getElementById('installProgressWrap');
        if (instSt.status === 'running' || instSt.installer_alive || (instSt.percent > 0 && instSt.status !== 'idle')) {
            progWrap.style.display = 'block';
            document.getElementById('installPhaseLabel').textContent = instSt.message || instSt.phase || 'Memproses...';
            document.getElementById('installPercentLabel').textContent = (instSt.percent || 0) + '%';
            document.getElementById('installProgressBar').style.width = Math.min(100, Math.max(0, instSt.percent || 0)) + '%';
        } else {
            progWrap.style.display = 'none';
        }

        if (instSt.installer_log !== undefined) cachedLogs.installer = instSt.installer_log || '';
        if (instSt.bridge_log !== undefined) cachedLogs.bridge = instSt.bridge_log || '';
        renderLogConsole();

        document.getElementById('installHintText').textContent = env.recommendation_note || '';
    }

    function renderLogConsole() {
        const el = document.getElementById('liveLogConsole');
        const txt = currentLogMode === 'installer' ? cachedLogs.installer : cachedLogs.bridge;
        el.textContent = txt && txt.trim() !== ''
            ? txt
            : (currentLogMode === 'installer'
                ? 'Belum ada log installer. Klik "1. Install Node.js + Baileys" untuk memulai.'
                : 'Belum ada log Baileys. Klik "2. Jalankan Baileys" setelah instalasi selesai.');
        el.scrollTop = el.scrollHeight;
    }

    function stopInstallPolling() {
        if (installPollTimer) { clearInterval(installPollTimer); installPollTimer = null; }
    }
    function stopQrPolling() {
        if (qrPollTimer) { clearInterval(qrPollTimer); qrPollTimer = null; }
    }

    function startInstallPolling() {
        stopInstallPolling();
        installPollTimer = setInterval(async () => {
            const res = await WaPanel.get(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=install_status');
            if (!res.success || !res.data) return;
            const st = res.data;
            renderReadiness(st.environment, { running: st.port_open, wa_status: st.port_open ? 'qr_pending' : 'disconnected' }, st);

            if (st.status === 'done' && !st.installer_alive) {
                stopInstallPolling();
                Ilmuna.success('Instalasi Selesai', st.message || 'Node.js Portable & Baileys siap dipakai!');
                await loadDevices();
                if (st.port_open) {
                    fetchQr(true);
                }
            } else if ((st.status === 'failed' || st.status === 'needs_cron') && !st.installer_alive) {
                stopInstallPolling();
                Ilmuna.warning('Info Instalasi', st.message || 'Periksa Log Installer di bawah.');
            }
        }, 2500);
    }

    async function fetchQr(enableAutoPoll) {
        const box = document.getElementById('qrPreviewBox');
        if (!qrPollTimer) {
            box.innerHTML = '<iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin" width="32"></iconify-icon><span>Menghubungi Baileys via PHP untuk mengambil QR...</span>';
        }
        const res = await WaPanel.get(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=qr');
        const d = res.data || {};
        if (res.success && d.qr_data_url) {
            box.innerHTML = `<img src="${WaPanel.escape(d.qr_data_url)}" alt="QR WhatsApp" loading="lazy" draggable="false">
                <div style="font-size:12.5px;color:#047857;font-weight:700;">Pindai QR ini dari WhatsApp → Perangkat Tertaut (Auto-refresh aktif)</div>`;
            if (enableAutoPoll && !qrPollTimer) {
                qrPollTimer = setInterval(() => fetchQr(false), 5000);
            }
        } else if (d.status === 'connected') {
            stopQrPolling();
            box.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="52" style="color:#10B981;"></iconify-icon><strong style="color:#047857;font-size:15px;">WhatsApp Baileys Sudah Terhubung!</strong>';
            loadDevices();
        } else {
            box.innerHTML = `<iconify-icon icon="solar:info-circle-bold" width="40" style="color:#D97706;"></iconify-icon><div style="font-size:13px;color:#92400E;">${WaPanel.escape(res.message || 'Baileys belum siap. Pastikan Step 1 & Step 2 sudah selesai.')}</div>`;
            if (enableAutoPoll && res.success && !qrPollTimer) {
                // Bridge sedang startup handshake -> coba lagi otomatis dalam 4 detik
                qrPollTimer = setInterval(() => fetchQr(false), 4500);
            }
        }
    }

    function rowHtml(d) {
        const stCls = d.status === 'connected' ? 'success' : (d.status === 'qr_pending' ? 'warning' : 'danger');
        return `<tr id="dev-row-${d.id}" data-id="${d.id}">
            <td data-label="Device"><strong>${WaPanel.escape(d.name)}</strong> ${d.is_default == 1 ? '<span class="ilmuna-badge ilmuna-badge--info">Default</span>' : ''}</td>
            <td data-label="Driver"><code>${WaPanel.escape(d.driver)}</code></td>
            <td data-label="Nomor"><code>${WaPanel.escape(d.phone_number || '-')}</code></td>
            <td data-label="Endpoint"><code style="font-size:12px;">${WaPanel.escape(d.endpoint_url || '-')}</code></td>
            <td data-label="Status"><span class="ilmuna-badge ilmuna-badge--${stCls}">${WaPanel.escape(d.status)}</span></td>
            <td data-label="Aksi" class="text-center" style="white-space:nowrap;">
                <button type="button" class="btn btn-sm btn-outline btn-test-dev" data-id="${d.id}" title="Uji Koneksi"><iconify-icon icon="solar:wifi-router-bold"></iconify-icon></button>
                <button type="button" class="btn btn-sm btn-outline btn-edit-dev" data-id="${d.id}" title="Edit"><iconify-icon icon="solar:pen-new-square-bold"></iconify-icon></button>
                <button type="button" class="btn btn-sm btn-danger btn-del-dev" data-id="${d.id}" title="Hapus"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>
            </td>
        </tr>`;
    }

    function bindRowButtons() {
        tbody.querySelectorAll('.btn-test-dev').forEach(btn => {
            btn.onclick = async function () {
                const id = parseInt(this.dataset.id, 10);
                this.disabled = true;
                try {
                    const res = await WaPanel.post('?ajax=1', { action: 'test_device', id });
                    const row = document.getElementById('dev-row-' + id);
                    if (res.data && res.data.status && row) {
                        const st = res.data.status;
                        const cls = st === 'connected' ? 'success' : (st === 'qr_pending' ? 'warning' : 'danger');
                        row.children[4].innerHTML = `<span class="ilmuna-badge ilmuna-badge--${cls}">${WaPanel.escape(st)}</span>`;
                        WaPanel.flashRow(row);
                    }
                    res.success ? Ilmuna.success('Koneksi Aktif', res.message) : Ilmuna.warning('Belum Terhubung', res.message);
                } finally {
                    this.disabled = false;
                }
            };
        });

        tbody.querySelectorAll('.btn-edit-dev').forEach(btn => {
            btn.onclick = function () {
                const d = devicesList.find(x => x.id == this.dataset.id);
                if (!d) return;
                document.getElementById('modalDeviceTitle').textContent = 'Edit Device: ' + d.name;
                document.getElementById('devId').value = d.id;
                document.getElementById('devName').value = d.name;
                document.getElementById('devDriver').value = d.driver;
                document.getElementById('devPhone').value = d.phone_number || '';
                document.getElementById('devUrl').value = d.endpoint_url || '';
                document.getElementById('devToken').value = '';
                document.getElementById('devSecret').value = '';
                document.getElementById('devDefault').checked = d.is_default == 1;
                openIlmunaModal('modalDevice');
            };
        });

        tbody.querySelectorAll('.btn-del-dev').forEach(btn => {
            btn.onclick = async function () {
                const id = parseInt(this.dataset.id, 10);
                const ok = await Ilmuna.confirm('error', 'Hapus Device?', 'Konfigurasi device ini akan dihapus permanen.');
                if (!ok) return;
                const res = await WaPanel.post('?ajax=1', { action: 'delete_device', id });
                if (res.success) {
                    devicesList = devicesList.filter(x => x.id != id);
                    WaPanel.removeRow(document.getElementById('dev-row-' + id), () => {
                        document.getElementById('cntDevTab').textContent = devicesList.length;
                    });
                    Ilmuna.success('Dihapus', res.message);
                } else {
                    Ilmuna.error('Gagal', res.message);
                }
            };
        });
    }

    async function loadDevices() {
        const res = await WaPanel.get('?ajax=1');
        if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
        const d = res.data;
        devicesList = d.devices || [];
        document.getElementById('cntDevTab').textContent = devicesList.length;
        document.getElementById('selDriverMode').value = d.wa_driver || 'hybrid';
        document.getElementById('selBridgeFlavor').value = d.wa_bridge_flavor || 'generic';
        document.getElementById('webhookUrlBox').textContent = d.webhook_url;
        document.getElementById('verifyTokenBox').textContent = d.verify_token;
        document.getElementById('cronInstaller').value = d.cron_commands.installer;
        document.getElementById('cronWatchdog').value = d.cron_commands.watchdog;
        document.getElementById('cronBroadcast').value = d.cron_commands.broadcast;
        document.getElementById('cronReminder').value = d.cron_commands.reminder;

        tbody.innerHTML = devicesList.length ? devicesList.map(rowHtml).join('') : '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Belum ada device.</td></tr>';
        bindRowButtons();

        const env = d.environment || {};
        renderReadiness(env, d.bridge_status, d.install_status);
        if (d.install_status && (d.install_status.status === 'running' || d.install_status.installer_alive)) {
            startInstallPolling();
        }
        // Jika Baileys sudah aktif & menunggu scan QR, tampilkan QR langsung tanpa perlu klik ulang!
        if (d.bridge_status && d.bridge_status.running && d.bridge_status.wa_status === 'qr_pending' && !qrPollTimer) {
            fetchQr(true);
        } else if (d.bridge_status && d.bridge_status.running && d.bridge_status.wa_status === 'connected') {
            document.getElementById('qrPreviewBox').innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="52" style="color:#10B981;"></iconify-icon><strong style="color:#047857;font-size:15px;">WhatsApp Baileys Terhubung!</strong>';
        }

        document.getElementById('envDiagBox').innerHTML = `
            <strong style="color:var(--lumina-navy);font-family:'Outfit',sans-serif;font-size:15px;display:block;margin-bottom:10px;">Diagnosa Server Hosting (Auto-Detect PHP)</strong>
            <div style="display:grid;gap:8px;font-size:13px;">
                <div>• Sistem Operasi &amp; Arch : <code>${WaPanel.escape(env.os)} (${WaPanel.escape(env.arch || '-')}) · PHP ${WaPanel.escape(env.php_version)}</code></div>
                <div>• Ekstensi cURL &amp; PharData : ${env.curl_loaded ? '<span class="ilmuna-badge ilmuna-badge--success">cURL Aktif</span>' : '<span class="ilmuna-badge ilmuna-badge--danger">cURL Nonaktif</span>'} ${env.phar_loaded ? '<span class="ilmuna-badge ilmuna-badge--success">PharData Aktif</span>' : ''}</div>
                <div>• Eksekusi Proses PHP (exec/proc_open) : ${env.can_spawn_process ? '<span class="ilmuna-badge ilmuna-badge--success">Diizinkan (1-Klik Web Siap!)</span>' : '<span class="ilmuna-badge ilmuna-badge--warning">Dibatasi Web PHP (Gunakan Cron cPanel)</span>'}</div>
                <div>• Binary PHP CLI : <code>${WaPanel.escape(env.php_cli_binary || 'Tidak terdeteksi')}</code></div>
                <div>• Binary Node.js : <code>${WaPanel.escape(env.node_binary || 'Belum terpasang — siap unduh Portable')}</code> ${env.node_version ? '<span class="ilmuna-badge ilmuna-badge--info">' + WaPanel.escape(env.node_version) + '</span>' : ''}</div>
                <div>• Paket Baileys : ${env.node_modules_ready ? '<span class="ilmuna-badge ilmuna-badge--success">Terpasang Lengkap</span>' : '<span class="ilmuna-badge ilmuna-badge--warning">Belum Terpasang</span>'}</div>
                <div style="padding:10px 12px;border-radius:10px;background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;margin-top:4px;">
                    <strong>Panduan Otomatis:</strong> ${WaPanel.escape(env.recommendation_note || '')}
                </div>
            </div>`;
    }

    WaPanel.initTabs('#deviceTabs');

    document.querySelectorAll('#logSwitchPills .ilmuna-pill-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#logSwitchPills .ilmuna-pill-btn').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            currentLogMode = this.dataset.log;
            renderLogConsole();
        });
    });

    document.getElementById('btnLoadLogs').addEventListener('click', async () => {
        const res = await WaPanel.get(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=logs');
        if (res.success && res.data) {
            cachedLogs.installer = res.data.installer_log || '';
            cachedLogs.bridge = res.data.bridge_log || '';
            renderLogConsole();
        }
    });

    document.getElementById('btnInstallAll').addEventListener('click', async function () {
        const ok = await Ilmuna.confirm(
            'question',
            'Install Node.js Portable + Baileys via PHP?',
            'PHP akan mengunduh Node.js Portable resmi (~40 MB), memasang paket @whiskeysockets/baileys di folder bridge/, lalu langsung menyalakan layanan Baileys tanpa perlu SSH.'
        );
        if (!ok) return;
        this.disabled = true;
        try {
            const res = await WaPanel.get(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=install_all');
            if (res.data && res.data.install) {
                renderReadiness(res.data.install.environment, { running: res.data.install.port_open, wa_status: 'disconnected' }, res.data.install);
            }
            if (res.success) {
                Ilmuna.info('Instalasi Dimulai', res.message);
                startInstallPolling();
            } else {
                Ilmuna.warning('Perlu Cron cPanel', res.message);
            }
        } finally {
            this.disabled = false;
        }
    });

    document.getElementById('btnUpgradeNode').addEventListener('click', async function () {
        const ok = await Ilmuna.confirm(
            'question',
            'Upgrade ke Node.js v26.13.1 LTS Portable?',
            'PHP akan mengunduh Node.js v26.13.1 LTS resmi ke bridge/runtime/node/ dan merestart Baileys di port bebas (38421+). Jika glibc OS hosting lebih lama, otomatis kembali ke versi stabil.'
        );
        if (!ok) return;
        this.disabled = true;
        try {
            const res = await WaPanel.get(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=upgrade_node');
            if (res.success) {
                Ilmuna.info('Upgrade Node.js Dimulai', res.message);
                startInstallPolling();
            } else {
                Ilmuna.warning('Info Upgrade', res.message);
            }
        } finally {
            this.disabled = false;
        }
    });

    document.getElementById('btnStartBridge').addEventListener('click', async function () {
        this.disabled = true;
        try {
            const res = await WaPanel.get(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=start');
            res.success ? Ilmuna.success('Baileys Aktif', res.message) : Ilmuna.warning('Info Server', res.message);
            await loadDevices();
            if (res.success) {
                setTimeout(() => fetchQr(true), 2000);
            }
        } finally {
            this.disabled = false;
        }
    });

    document.getElementById('btnRestartBridge').addEventListener('click', async function () {
        this.disabled = true;
        try {
            const res = await WaPanel.get(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=restart');
            res.success ? Ilmuna.success('Baileys Direstart', res.message) : Ilmuna.warning('Info Server', res.message);
            await loadDevices();
        } finally {
            this.disabled = false;
        }
    });

    document.getElementById('btnResetSession').addEventListener('click', async () => {
        const ok = await Ilmuna.confirm(
            'warning',
            'Reset Sesi WhatsApp?',
            'Sesi auth_info Baileys saat ini akan dihapus dan bot akan mengeluarkan QR baru agar Anda bisa menautkan nomor WA baru.'
        );
        if (!ok) return;
        stopQrPolling();
        const res = await WaPanel.get(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=reset_session');
        res.success ? Ilmuna.success('Sesi Direset', res.message) : Ilmuna.warning('Info', res.message);
        await loadDevices();
        if (res.success) {
            setTimeout(() => fetchQr(true), 2500);
        }
    });

    document.getElementById('btnFetchQr').addEventListener('click', () => fetchQr(true));
    document.getElementById('btnRefreshStatus').addEventListener('click', () => loadDevices());
    document.getElementById('btnCheckInstallNow').addEventListener('click', () => loadDevices());
    document.getElementById('btnCopyCronInstaller').addEventListener('click', () => {
        WaPanel.copyText(document.getElementById('cronInstallerInline').value, 'Cron Auto-Installer Baileys');
    });

    document.getElementById('btnReqPairing').addEventListener('click', async () => {
        const phone = document.getElementById('inputPairingPhone').value.trim();
        if (!phone) { Ilmuna.warning('Nomor Kosong', 'Masukkan nomor WA Bot terlebih dahulu (contoh: 081234567890).'); return; }
        stopQrPolling();
        const res = await WaPanel.post(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=pairing', { phone });
        if (res.success && res.data && res.data.pairing_code) {
            document.getElementById('qrPreviewBox').innerHTML = `
                <div style="font-size:12px;color:#64748B;">Kode Tautan 8-Digit Anda:</div>
                <div class="font-mono" style="font-size:30px;font-weight:800;letter-spacing:4px;color:var(--lumina-navy);">${WaPanel.escape(res.data.pairing_code)}</div>
                <div style="font-size:12px;color:#047857;">Buka WhatsApp → Perangkat Tertaut → Tautkan dengan nomor telepon</div>`;
            Ilmuna.success('Pairing Code Siap', res.message);
        } else {
            Ilmuna.error('Gagal', res.message);
        }
    });

    document.getElementById('btnSendTestWa').addEventListener('click', async () => {
        const phone = document.getElementById('testTargetPhone').value.trim();
        const res = await WaPanel.post('?ajax=1', { action: 'test_send_interactive', phone });
        res.success ? Ilmuna.success('Pesan Uji Terkirim', res.message) : Ilmuna.error('Gagal Kirim', res.message);
    });

    document.getElementById('btnSaveDriverMode').addEventListener('click', async () => {
        const res = await WaPanel.post('?ajax=1', {
            action: 'save_driver_mode',
            wa_driver: document.getElementById('selDriverMode').value,
            wa_bridge_flavor: document.getElementById('selBridgeFlavor').value
        });
        res.success ? Ilmuna.success('Mode Tersimpan', res.message) : Ilmuna.error('Gagal', res.message);
    });

    document.getElementById('btnAddDevice').addEventListener('click', () => {
        document.getElementById('modalDeviceTitle').textContent = 'Tambah Device WA Baru';
        document.getElementById('formDevice').reset();
        document.getElementById('devId').value = '0';
        openIlmunaModal('modalDevice');
    });

    document.getElementById('formDevice').addEventListener('submit', async function (e) {
        e.preventDefault();
        const res = await WaPanel.post('?ajax=1', {
            action: 'save_device',
            id: parseInt(document.getElementById('devId').value, 10),
            name: document.getElementById('devName').value,
            driver: document.getElementById('devDriver').value,
            phone_number: document.getElementById('devPhone').value,
            endpoint_url: document.getElementById('devUrl').value,
            api_token: document.getElementById('devToken').value,
            webhook_secret: document.getElementById('devSecret').value,
            is_default: document.getElementById('devDefault').checked ? 1 : 0
        });
        if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
        closeIlmunaModal('modalDevice');
        const saved = res.data;
        const existingRow = document.getElementById('dev-row-' + saved.id);
        if (existingRow) {
            devicesList = devicesList.map(x => x.id == saved.id ? saved : x);
            existingRow.outerHTML = rowHtml(saved);
            WaPanel.flashRow(document.getElementById('dev-row-' + saved.id));
        } else {
            devicesList.unshift(saved);
            tbody.insertAdjacentHTML('afterbegin', rowHtml(saved));
            document.getElementById('dev-row-' + saved.id).classList.add('row-smooth-in');
            document.getElementById('cntDevTab').textContent = devicesList.length;
        }
        bindRowButtons();
        Ilmuna.success('Tersimpan', res.message);
    });

    document.getElementById('btnCopyWebhook').addEventListener('click', () => {
        WaPanel.copyText(document.getElementById('webhookUrlBox').textContent, 'Webhook URL');
    });

    loadDevices();
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
