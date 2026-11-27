<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — DASHBOARD 6: PROTEKSI ANTI-CALL & DAFTAR NOMOR DIBLOKIR
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

            if ($act === 'save_settings') {
                $isEnabled = !empty($body['anti_call_enabled']);
                wa_set_setting('anti_call_enabled', $isEnabled ? '1' : '0', $pdo);
                wa_set_setting('anti_call_reply_text', trim((string)($body['anti_call_reply_text'] ?? '')), $pdo);
                wa_set_setting('anti_call_spam_limit', (string)max(1, (int)($body['anti_call_spam_limit'] ?? 3)), $pdo);
                wa_set_setting('anti_call_spam_window_minutes', (string)max(1, (int)($body['anti_call_spam_window_minutes'] ?? 5)), $pdo);

                // Sinkron live ke Baileys bridge agar Instant Auto-Reject langsung mengikuti toggle tanpa restart
                try {
                    $bridgeDrv = TransportFactory::create($pdo)->getBridgeDriver();
                    if ($bridgeDrv !== null && method_exists($bridgeDrv, 'syncAntiCallConfig')) {
                        $bridgeDrv->syncAntiCallConfig($isEnabled);
                    }
                } catch (Throwable $e) {
                    // Non-blocking jika bridge sedang offline
                }

                wa_json_response(true, 'Pengaturan Anti-Call berhasil diperbarui.');
            }

            if ($act === 'unblock_phone') {
                $phone = wa_normalize_phone((string)($body['phone'] ?? ''));
                $transport = TransportFactory::create($pdo);
                $engine = new AntiCallEngine($pdo, $transport);
                $engine->unblockContact($phone);
                wa_json_response(true, "Blokir nomor {$phone} berhasil dibuka.", ['phone' => $phone]);
            }

            wa_json_response(false, 'Aksi tidak valid.', [], 400);
        }

        $settings = [
            'anti_call_enabled'             => (string)wa_get_setting('anti_call_enabled', '1', $pdo),
            'anti_call_reply_text'          => (string)wa_get_setting('anti_call_reply_text', '', $pdo),
            'anti_call_spam_limit'          => (string)wa_get_setting('anti_call_spam_limit', '3', $pdo),
            'anti_call_spam_window_minutes' => (string)wa_get_setting('anti_call_spam_window_minutes', '5', $pdo),
        ];
        $logs = $pdo->query('SELECT * FROM wa_call_logs ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        $blocked = $pdo->query('SELECT phone_e164, display_name, role, synced_at FROM wa_contacts_cache WHERE is_blocked = 1 ORDER BY synced_at DESC')->fetchAll(PDO::FETCH_ASSOC);

        wa_json_response(true, 'Data Anti-Call dimuat.', [
            'settings' => $settings,
            'logs'     => $logs,
            'blocked'  => $blocked,
        ]);
    } catch (Throwable $e) {
        wa_json_response(false, 'Galat Anti-Call: ' . $e->getMessage(), [], 500);
    }
}

$pageTitle = 'Proteksi Anti-Call & Auto-Block';
$pageIcon  = 'solar:phone-calling-rounded-bold';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="curriculum-main-tabs" id="callTabs" role="tablist">
    <button type="button" class="curriculum-tab-btn is-active" data-tab="settings">
        <iconify-icon icon="solar:settings-bold"></iconify-icon>
        <span class="curriculum-tab-label">Pengaturan Auto-Reject</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="logs">
        <iconify-icon icon="solar:phone-calling-rounded-bold"></iconify-icon>
        <span class="curriculum-tab-label">Riwayat Panggilan Masuk</span>
        <span class="curriculum-tab-counter" id="cntCallLogs">0</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="blocked">
        <iconify-icon icon="solar:user-block-bold"></iconify-icon>
        <span class="curriculum-tab-label">Nomor Diblokir</span>
        <span class="curriculum-tab-counter" id="cntBlocked">0</span>
    </button>
</div>

<!-- TAB 1: PENGATURAN -->
<div class="tab-panel is-active" data-tab-panel="settings">
    <div class="ilmuna-card">
        <div class="ilmuna-card-body">
            <form id="formAntiCall">
                <div class="form-group">
                    <label style="display:inline-flex;align-items:center;gap:10px;font-weight:700;color:var(--lumina-navy);cursor:pointer;">
                        <input type="checkbox" id="acEnabled" checked> Aktifkan Auto-Reject seluruh panggilan suara &amp; video masuk
                    </label>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Batas Spam Call sebelum Auto-Block (kali)</label>
                        <input type="number" id="acLimit" class="form-control" min="1" max="20" value="3">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jendela Waktu Deteksi Spam (menit)</label>
                        <input type="number" id="acWindow" class="form-control" min="1" max="60" value="5">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Pesan Balasan Sopan Otomatis (disertai tombol Menu Utama &amp; Kontak Admin)</label>
                    <textarea id="acReplyText" class="form-control" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                    <iconify-icon icon="solar:disk-bold" width="18"></iconify-icon>
                    <span>Simpan Pengaturan Anti-Call</span>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- TAB 2: LOG PANGGILAN -->
<div class="tab-panel" data-tab-panel="logs">
    <div class="ilmuna-card">
        <div class="table-responsive">
            <table class="ilmuna-table ilmuna-table-stack">
                <thead>
                    <tr><th>Waktu</th><th>Penelepon</th><th>Jenis</th><th>Ditolak</th><th>Balasan</th><th>Auto-Block</th></tr>
                </thead>
                <tbody id="callLogsTbody">
                    <tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Memuat riwayat panggilan...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 3: NOMOR DIBLOKIR -->
<div class="tab-panel" data-tab-panel="blocked">
    <div class="ilmuna-card">
        <div class="table-responsive">
            <table class="ilmuna-table ilmuna-table-stack">
                <thead>
                    <tr><th>Nomor WhatsApp</th><th>Nama</th><th>Role</th><th>Waktu</th><th class="text-center">Aksi</th></tr>
                </thead>
                <tbody id="blockedTbody">
                    <tr><td colspan="5" class="text-center text-muted" style="padding:28px;">Memuat daftar blokir...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let blockedList = [];
    WaPanel.initTabs('#callTabs');

    function renderBlocked() {
        const tbody = document.getElementById('blockedTbody');
        document.getElementById('cntBlocked').textContent = blockedList.length;
        tbody.innerHTML = blockedList.length ? blockedList.map(b => `<tr id="blk-${b.phone_e164}">
            <td data-label="Nomor"><code>${WaPanel.escape(b.phone_e164)}</code></td>
            <td data-label="Nama"><strong>${WaPanel.escape(b.display_name || '-')}</strong></td>
            <td data-label="Role"><span class="ilmuna-badge ilmuna-badge--navy">${WaPanel.escape(b.role)}</span></td>
            <td data-label="Waktu">${WaPanel.escape(b.synced_at)}</td>
            <td data-label="Aksi" class="text-center">
                <button type="button" class="btn btn-sm btn-outline btn-unblock" data-phone="${WaPanel.escape(b.phone_e164)}">
                    <iconify-icon icon="solar:lock-keyhole-unlocked-bold"></iconify-icon> Buka Blokir
                </button>
            </td>
        </tr>`).join('') : '<tr><td colspan="5" class="text-center text-muted" style="padding:28px;">Tidak ada nomor yang diblokir.</td></tr>';

        tbody.querySelectorAll('.btn-unblock').forEach(btn => {
            btn.onclick = async function () {
                const phone = this.dataset.phone;
                const ok = await Ilmuna.confirm('warning', 'Buka Blokir Nomor?', 'Nomor ' + phone + ' akan diizinkan kembali berinteraksi dengan bot.');
                if (!ok) return;
                const res = await WaPanel.post('?ajax=1', { action: 'unblock_phone', phone });
                if (res.success) {
                    blockedList = blockedList.filter(x => x.phone_e164 !== phone);
                    WaPanel.removeRow(document.getElementById('blk-' + phone), () => {
                        document.getElementById('cntBlocked').textContent = blockedList.length;
                    });
                    Ilmuna.success('Blokir Dibuka', res.message);
                }
            };
        });
    }

    document.getElementById('formAntiCall').addEventListener('submit', async function (e) {
        e.preventDefault();
        const res = await WaPanel.post('?ajax=1', {
            action: 'save_settings',
            anti_call_enabled: document.getElementById('acEnabled').checked ? 1 : 0,
            anti_call_spam_limit: parseInt(document.getElementById('acLimit').value, 10),
            anti_call_spam_window_minutes: parseInt(document.getElementById('acWindow').value, 10),
            anti_call_reply_text: document.getElementById('acReplyText').value
        });
        res.success ? Ilmuna.success('Tersimpan', res.message) : Ilmuna.error('Gagal', res.message);
    });

    (async () => {
        const res = await WaPanel.get('?ajax=1');
        if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
        const s = res.data.settings;
        document.getElementById('acEnabled').checked = s.anti_call_enabled === '1';
        document.getElementById('acLimit').value = s.anti_call_spam_limit;
        document.getElementById('acWindow').value = s.anti_call_spam_window_minutes;
        document.getElementById('acReplyText').value = s.anti_call_reply_text;

        const logs = res.data.logs || [];
        document.getElementById('cntCallLogs').textContent = logs.length;
        document.getElementById('callLogsTbody').innerHTML = logs.length ? logs.map(c => `<tr>
            <td data-label="Waktu">${WaPanel.escape(c.created_at)}</td>
            <td data-label="Penelepon"><code>${WaPanel.escape(c.caller_phone)}</code></td>
            <td data-label="Jenis"><span class="ilmuna-badge ilmuna-badge--info">${WaPanel.escape(c.call_type)}</span></td>
            <td data-label="Ditolak">${c.was_rejected == 1 ? '<span class="ilmuna-badge ilmuna-badge--success">Auto-Reject</span>' : '-'}</td>
            <td data-label="Balasan">${c.auto_replied == 1 ? '<span class="ilmuna-badge ilmuna-badge--success">Terkirim</span>' : '-'}</td>
            <td data-label="Block">${c.triggered_block == 1 ? '<span class="ilmuna-badge ilmuna-badge--danger">Auto-Block</span>' : '<span class="ilmuna-badge ilmuna-badge--navy">Normal</span>'}</td>
        </tr>`).join('') : '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Belum ada log panggilan masuk.</td></tr>';

        blockedList = res.data.blocked || [];
        renderBlocked();
    })();
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
