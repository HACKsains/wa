<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — DASHBOARD 2: DEVICES & KONEKSI PURE PHP APACHE (NO NODE.JS)
 * ==============================================================================
 * Mengelola koneksi Cloud API Meta Resmi & Multi-Device HTTP Gateway
 * (Whapi / Fonnte / GoWA / WAHA / Generic), Scan QR & Pairing Code 8-Digit,
 * serta Diagnosa Hosting Apache + Cron PHP siap salin.
 * ==============================================================================
 */

require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/auth.php';

wa_require_admin(isset($_GET['ajax']) && $_GET['ajax'] === '1');

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $pdo = getDBConnection();

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
                $id      = (int)($body['id'] ?? 0);
                $name    = trim((string)($body['name'] ?? ''));
                $driver  = ($body['driver'] ?? '') === 'cloud_api' ? 'cloud_api' : 'md_bridge';
                $phone   = trim((string)($body['phone_number'] ?? ''));
                $url     = trim((string)($body['endpoint_url'] ?? ''));
                $token   = trim((string)($body['api_token'] ?? ''));
                $secret  = trim((string)($body['webhook_secret'] ?? ''));
                $isDef   = !empty($body['is_default']) ? 1 : 0;

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
                $id = (int)($body['id'] ?? 0);
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
                    "Assalamu'alaikum! Ini adalah pesan uji tombol interaktif dari *Ilmuna WA Bot* (Pure PHP Native).",
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

        // GET: lazy-load devices + environment info
        $devices = $pdo->query(
            'SELECT id, name, driver, phone_number, endpoint_url, status, is_default, last_seen_at
             FROM wa_devices ORDER BY is_default DESC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $mgr = new BridgeManager($pdo);
        $env = $mgr->detectEnvironment();

        wa_json_response(true, 'Data device berhasil dimuat.', [
            'devices'          => $devices,
            'wa_driver'        => (string)wa_get_setting('wa_driver', WA_DRIVER, $pdo),
            'wa_bridge_flavor' => (string)wa_get_setting('wa_bridge_flavor', WA_BRIDGE_FLAVOR, $pdo),
            'webhook_url'      => rtrim(BASE_URL, '/') . '/webhook',
            'verify_token'     => WA_CLOUD_VERIFY_TOKEN,
            'environment'      => $env,
            'cron_commands'    => [
                'broadcast' => '* * * * * /usr/local/bin/php ' . BASE_PATH . '/workers/broadcast-worker.php >/dev/null 2>&1',
                'reminder'  => '*/15 * * * * /usr/local/bin/php ' . BASE_PATH . '/workers/reminder-scheduler.php >/dev/null 2>&1',
                'watchdog'  => '* * * * * /usr/local/bin/php ' . BASE_PATH . '/workers/bridge-watchdog.php >/dev/null 2>&1',
            ],
        ]);
    } catch (Throwable $e) {
        wa_json_response(false, 'Galat Devices: ' . $e->getMessage(), [], 500);
    }
}

$pageTitle = 'Devices & Koneksi Pure PHP';
$pageIcon  = 'solar:smartphone-2-bold';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- TABS SEGMENTED CONTROL -->
<div class="curriculum-main-tabs" id="deviceTabs" role="tablist">
    <button type="button" class="curriculum-tab-btn is-active" data-tab="devices">
        <iconify-icon icon="solar:smartphone-2-bold"></iconify-icon>
        <span class="curriculum-tab-label">Daftar Device &amp; Mode</span>
        <span class="curriculum-tab-counter" id="cntDevTab">0</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="qr">
        <iconify-icon icon="solar:qr-code-bold"></iconify-icon>
        <span class="curriculum-tab-label">Scan QR / Pairing &amp; Uji Kirim</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="apache">
        <iconify-icon icon="solar:server-square-cloud-bold"></iconify-icon>
        <span class="curriculum-tab-label">Mode Apache Tanpa Node.js &amp; Cron</span>
    </button>
</div>

<!-- TAB 1: DAFTAR DEVICE & MODE TRANSPORT -->
<div class="tab-panel is-active" data-tab-panel="devices">
    <div class="ilmuna-filter-box" style="margin-bottom:14px;">
        <div class="ilmuna-filter-topline">
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <select id="selDriverMode" class="ilmuna-select" aria-label="Mode Transport WA">
                    <option value="hybrid">Mode Hybrid (Cloud API + MD Gateway)</option>
                    <option value="cloud_api">Pure PHP: Meta Cloud API Resmi</option>
                    <option value="md_bridge">Multi-Device Gateway / Bridge</option>
                </select>
                <select id="selBridgeFlavor" class="ilmuna-select" aria-label="Flavor MD Gateway">
                    <option value="whapi">Whapi.cloud (100% PHP cPanel · Tanpa Node)</option>
                    <option value="fonnte">Fonnte API (100% PHP cPanel · Tanpa Node)</option>
                    <option value="gowa">Go-WhatsApp Binary (Tanpa Node.js)</option>
                    <option value="waha">WAHA HTTP API</option>
                    <option value="generic">Ilmuna Bridge (Local / VPS / Tunnel)</option>
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

<!-- TAB 2: SCAN QR / PAIRING CODE & UJI KIRIM -->
<div class="tab-panel" data-tab-panel="qr">
    <div class="wa-grid-2">
        <div class="ilmuna-card">
            <div class="ilmuna-card-body">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                    <strong style="color:var(--lumina-navy);font-family:'Outfit',sans-serif;font-size:15px;">Tautkan WhatsApp via PHP Bridge API</strong>
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="btn btn-sm btn-primary" id="btnFetchQr">
                            <iconify-icon icon="solar:qr-code-bold"></iconify-icon> Ambil QR
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" id="btnStartBridge">
                            <iconify-icon icon="solar:play-bold"></iconify-icon> Auto-Start PHP
                        </button>
                    </div>
                </div>
                <div class="wa-qr-box" id="qrPreviewBox">
                    <iconify-icon icon="solar:qr-code-bold-duotone" width="54" style="color:var(--lumina-teal);"></iconify-icon>
                    <div class="text-muted" style="font-size:13px;">Klik <strong>Ambil QR</strong> atau masukkan nomor WA di bawah untuk meminta <strong>Pairing Code 8-Digit</strong>.</div>
                </div>
                <div style="display:flex;gap:8px;margin-top:12px;">
                    <input type="text" id="inputPairingPhone" class="form-control" placeholder="Nomor WA Bot (misal: 081234567890)">
                    <button type="button" class="btn btn-gold" id="btnReqPairing">
                        <iconify-icon icon="solar:key-bold"></iconify-icon> Pairing 8-Digit
                    </button>
                </div>
            </div>
        </div>

        <div class="ilmuna-card">
            <div class="ilmuna-card-body">
                <strong style="color:var(--lumina-navy);font-family:'Outfit',sans-serif;font-size:15px;display:block;margin-bottom:12px;">Uji Kirim Pesan Tombol Interaktif</strong>
                <div class="form-group">
                    <label class="form-label" for="testTargetPhone">Nomor WhatsApp Tujuan Uji</label>
                    <input type="text" id="testTargetPhone" class="form-control" placeholder="0812xxxxxxxx / 62812xxxxxxxx">
                </div>
                <button type="button" class="btn btn-primary" id="btnSendTestWa" style="width:100%;">
                    <iconify-icon icon="solar:plain-3-bold" width="18"></iconify-icon>
                    <span>Kirim Pesan Tombol Uji Sekarang</span>
                </button>
                <div style="margin-top:16px;padding:12px 14px;border-radius:12px;background:#F8FAFC;border:1px solid #E2E8F0;font-size:12.5px;color:#475569;">
                    <strong>Callback URL Webhook (Meta &amp; MD Gateway):</strong>
                    <div style="display:flex;gap:8px;align-items:center;margin-top:6px;">
                        <code id="webhookUrlBox" style="flex:1;word-break:break-all;">-</code>
                        <button type="button" class="btn btn-sm btn-outline" id="btnCopyWebhook"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>
                    </div>
                    <div style="margin-top:8px;">Meta Verify Token: <code id="verifyTokenBox">-</code></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TAB 3: MODE HOSTING APACHE TANPA NODE.JS & CRON PHP -->
<div class="tab-panel" data-tab-panel="apache">
    <div class="wa-grid-2">
        <div class="ilmuna-card">
            <div class="ilmuna-card-body" id="envDiagBox">
                <iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin"></iconify-icon> Mendiagnosa lingkungan server PHP/Apache...
            </div>
        </div>
        <div class="ilmuna-card">
            <div class="ilmuna-card-body">
                <strong style="color:var(--lumina-navy);font-family:'Outfit',sans-serif;font-size:15px;display:block;margin-bottom:10px;">Perintah Cron Job cPanel (100% Pure PHP)</strong>
                <div class="form-group">
                    <label class="form-label">1. Antrean Broadcast Anti-Banned (tiap 1 menit)</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="cronBroadcast" class="form-control font-mono" readonly style="font-size:12px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="WaPanel.copyText(document.getElementById('cronBroadcast').value, 'Cron Broadcast')"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">2. Pengingat Otomatis H-1 Tugas/Proyek/CBT (tiap 15 menit)</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="cronReminder" class="form-control font-mono" readonly style="font-size:12px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="WaPanel.copyText(document.getElementById('cronReminder').value, 'Cron Reminder')"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">3. Watchdog Status Bridge (tiap 1 menit)</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="cronWatchdog" class="form-control font-mono" readonly style="font-size:12px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="WaPanel.copyText(document.getElementById('cronWatchdog').value, 'Cron Watchdog')"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>
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
                    <p class="ilm-modal-header__subtitle">Mendukung Meta Cloud API Resmi &amp; HTTP Gateway tanpa Node.js</p>
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
                        <input type="text" id="devName" class="form-control" required placeholder="Misal: Whapi Sekolah / Meta Cloud">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tipe Driver</label>
                        <select id="devDriver" class="form-control">
                            <option value="cloud_api">cloud_api (Meta Resmi · Pure PHP)</option>
                            <option value="md_bridge">md_bridge (Whapi / Fonnte / GoWA / Bridge)</option>
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
                        <input type="url" id="devUrl" class="form-control" required placeholder="https://graph.facebook.com/v21.0 atau https://gate.whapi.cloud">
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
    const tbody = document.getElementById('devicesTbody');

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
        document.getElementById('cronBroadcast').value = d.cron_commands.broadcast;
        document.getElementById('cronReminder').value = d.cron_commands.reminder;
        document.getElementById('cronWatchdog').value = d.cron_commands.watchdog;

        tbody.innerHTML = devicesList.length ? devicesList.map(rowHtml).join('') : '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Belum ada device.</td></tr>';
        bindRowButtons();

        const env = d.environment || {};
        document.getElementById('envDiagBox').innerHTML = `
            <strong style="color:var(--lumina-navy);font-family:'Outfit',sans-serif;font-size:15px;display:block;margin-bottom:10px;">Diagnosa Server Hosting (Auto-Detect PHP)</strong>
            <div style="display:grid;gap:8px;font-size:13px;">
                <div>• Sistem Operasi &amp; PHP : <code>${WaPanel.escape(env.os)} · PHP ${WaPanel.escape(env.php_version)}</code></div>
                <div>• Ekstensi cURL HTTPS : ${env.curl_loaded ? '<span class="ilmuna-badge ilmuna-badge--success">Aktif (Siap Pure PHP)</span>' : '<span class="ilmuna-badge ilmuna-badge--danger">Nonaktif</span>'}</div>
                <div>• Spawn Proses Lokal : ${env.can_spawn_process ? '<span class="ilmuna-badge ilmuna-badge--success">Diizinkan</span>' : '<span class="ilmuna-badge ilmuna-badge--warning">Dibatasi Hosting Shared</span>'}</div>
                <div>• Mode Rekomendasi : <span class="ilmuna-badge ilmuna-badge--info">${WaPanel.escape(env.recommended_mode)}</span></div>
                <div style="padding:10px 12px;border-radius:10px;background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;margin-top:4px;">
                    <strong>Panduan Otomatis:</strong> ${WaPanel.escape(env.recommendation_note || '')}
                </div>
            </div>`;
    }

    WaPanel.initTabs('#deviceTabs');

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

    document.getElementById('btnFetchQr').addEventListener('click', async () => {
        const box = document.getElementById('qrPreviewBox');
        box.innerHTML = '<iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin" width="32"></iconify-icon><span>Meminta QR Code melalui PHP...</span>';
        const res = await WaPanel.get(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=qr');
        if (res.success && res.data && res.data.qr_data_url) {
            box.innerHTML = `<img src="${WaPanel.escape(res.data.qr_data_url)}" alt="QR WhatsApp" loading="lazy" draggable="false"><div style="font-size:12.5px;color:#047857;font-weight:700;">Pindai QR ini dari WhatsApp → Perangkat Tertaut</div>`;
        } else if (res.data && res.data.status === 'connected') {
            box.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="48" style="color:#10B981;"></iconify-icon><strong style="color:#047857;">WhatsApp Sudah Terhubung!</strong>';
        } else {
            box.innerHTML = `<iconify-icon icon="solar:info-circle-bold" width="40" style="color:#D97706;"></iconify-icon><div style="font-size:13px;color:#92400E;">${WaPanel.escape(res.message || 'Bridge belum aktif atau menggunakan mode Cloud API Meta (tanpa QR).')}</div>`;
        }
    });

    document.getElementById('btnStartBridge').addEventListener('click', async () => {
        const res = await WaPanel.get(window.ILMUNA_BASE_URL + '/api/v1/bridge.php?action=start');
        res.success ? Ilmuna.success('Bridge Aktif', res.message) : Ilmuna.warning('Info Server', res.message);
    });

    document.getElementById('btnReqPairing').addEventListener('click', async () => {
        const phone = document.getElementById('inputPairingPhone').value.trim();
        if (!phone) { Ilmuna.warning('Nomor Kosong', 'Masukkan nomor WA Bot terlebih dahulu.'); return; }
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

    loadDevices();
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
