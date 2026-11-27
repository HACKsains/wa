<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — DASHBOARD 1: OVERVIEW LIVE (DATA-FIRST, LAZY LOAD, ZERO RELOAD)
 * ==============================================================================
 */

require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/auth.php';

wa_require_admin(isset($_GET['ajax']) && $_GET['ajax'] === '1');

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $pdo = getDBConnection();
    $tab = strtolower(trim((string)($_GET['tab'] ?? 'devices')));

    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            wa_require_csrf_ajax($body);
            $act = (string)($body['action'] ?? '');

            if ($act === 'process_queue_now') {
                $transport = TransportFactory::create($pdo);
                $engine    = new BroadcastEngine($pdo, $transport);
                $res       = $engine->processQueueBatch(10, 1, 2);
                wa_json_response(true, "Antrean diproses: {$res['sent']} terkirim, {$res['retried']} diulang, {$res['failed']} gagal.", $res);
            }
            wa_json_response(false, 'Aksi tidak dikenali.', [], 400);
        }

        // Counter tab ringkas (1 query ringan)
        $counts = [
            'devices'    => (int)$pdo->query('SELECT COUNT(*) FROM wa_devices')->fetchColumn(),
            'queue'      => (int)$pdo->query("SELECT COUNT(*) FROM wa_broadcast_queue WHERE status = 'pending'")->fetchColumn(),
            'violations' => (int)$pdo->query('SELECT COUNT(*) FROM wa_violation_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
            'calls'      => (int)$pdo->query('SELECT COUNT(*) FROM wa_call_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
        ];

        $rows = [];
        if ($tab === 'devices') {
            $rows = $pdo->query(
                'SELECT id, name, driver, phone_number, endpoint_url, status, is_default, last_seen_at
                 FROM wa_devices ORDER BY is_default DESC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($tab === 'queue') {
            $rows = $pdo->query(
                'SELECT q.id, q.priority, q.recipient_jid, q.recipient_name, q.payload_type, q.status, q.attempts, q.scheduled_at, q.last_error, c.title AS campaign_title
                 FROM wa_broadcast_queue q
                 LEFT JOIN wa_broadcast_campaigns c ON c.id = q.campaign_id
                 ORDER BY q.id DESC LIMIT 50'
            )->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($tab === 'violations') {
            $rows = $pdo->query(
                'SELECT id, group_jid, sender_phone, sender_name, violation_type, strike_count, action_taken, synced_to_bk, parent_notified, created_at
                 FROM wa_violation_logs ORDER BY id DESC LIMIT 50'
            )->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($tab === 'calls') {
            $rows = $pdo->query(
                'SELECT id, caller_phone, call_type, was_rejected, auto_replied, triggered_block, created_at
                 FROM wa_call_logs ORDER BY id DESC LIMIT 50'
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        wa_json_response(true, 'Data overview live dimuat.', [
            'tab'         => $tab,
            'driver_mode' => (string)wa_get_setting('wa_driver', WA_DRIVER, $pdo),
            'counts'      => $counts,
            'rows'        => $rows,
        ]);
    } catch (Throwable $e) {
        wa_json_response(false, 'Galat memuat overview: ' . $e->getMessage(), [], 500);
    }
}

$pageTitle = 'Overview Live';
$pageIcon  = 'solar:home-2-bold';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- 1. TABS SEGMENTED CONTROL (ALA CURRICULUM.PHP — ZERO TITLE) -->
<div class="curriculum-main-tabs" id="overviewTabs" role="tablist">
    <button type="button" class="curriculum-tab-btn is-active" data-tab="devices">
        <iconify-icon icon="solar:smartphone-2-bold"></iconify-icon>
        <span class="curriculum-tab-label">Status Device</span>
        <span class="curriculum-tab-counter" id="cntDevices">0</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="queue">
        <iconify-icon icon="solar:plain-3-bold"></iconify-icon>
        <span class="curriculum-tab-label">Antrean Broadcast</span>
        <span class="curriculum-tab-counter" id="cntQueue">0</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="violations">
        <iconify-icon icon="solar:shield-warning-bold"></iconify-icon>
        <span class="curriculum-tab-label">Pelanggaran Grup</span>
        <span class="curriculum-tab-counter" id="cntViolations">0</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="calls">
        <iconify-icon icon="solar:phone-calling-rounded-bold"></iconify-icon>
        <span class="curriculum-tab-label">Log Anti-Call</span>
        <span class="curriculum-tab-counter" id="cntCalls">0</span>
    </button>
</div>

<!-- 2. TOOLBAR NAVIGASI & AKSI (ALA ASSESSMENT-ACCESS.PHP) -->
<div class="ilmuna-filter-box">
    <div class="ilmuna-filter-topline">
        <div class="ilmuna-search-wrap">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="liveSearchInput" class="ilmuna-search-input" placeholder="Filter cepat pada tab aktif..." autocomplete="off">
        </div>

        <div class="ilmuna-filter-actions">
            <div class="ilmuna-nav-stat-inline" title="Mode Transport WA aktif">
                <iconify-icon icon="solar:server-minimalistic-bold" width="15"></iconify-icon>
                <strong id="driverModeBadge">-</strong>
                <span>Mode</span>
            </div>

            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" id="btnProcessQueue">
                <iconify-icon icon="solar:play-circle-bold" width="17"></iconify-icon>
                <span>Proses Antrean PHP</span>
            </button>

            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" id="btnRefreshTab">
                <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                <span>Muat Ulang</span>
            </button>
        </div>
    </div>
</div>

<!-- 3. DATA TABLE UTAMA (LAZY LOADED) -->
<div class="ilmuna-card">
    <div class="table-responsive">
        <table class="ilmuna-table ilmuna-table-stack">
            <thead id="overviewThead"><tr><th>Memuat...</th></tr></thead>
            <tbody id="overviewTbody">
                <tr><td class="text-center" style="padding:32px;color:#64748B;">
                    <iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin" width="22"></iconify-icon> Memuat data live secara lazy...
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let activeTab = 'devices';
    let currentRows = [];

    const thead = document.getElementById('overviewThead');
    const tbody = document.getElementById('overviewTbody');
    const searchInput = document.getElementById('liveSearchInput');

    function renderRows(rows) {
        const q = (searchInput.value || '').trim().toLowerCase();
        const filtered = q === '' ? rows : rows.filter(r => JSON.stringify(r).toLowerCase().includes(q));

        if (activeTab === 'devices') {
            thead.innerHTML = '<tr><th>Nama Device</th><th>Driver</th><th>Nomor / ID</th><th>Endpoint</th><th>Status</th><th>Terakhir Aktif</th></tr>';
            if (!filtered.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Belum ada device terdaftar.</td></tr>';
                return;
            }
            tbody.innerHTML = filtered.map(d => {
                const stClass = d.status === 'connected' ? 'success' : (d.status === 'qr_pending' ? 'warning' : 'danger');
                return `<tr>
                    <td data-label="Device"><strong>${WaPanel.escape(d.name)}</strong> ${d.is_default == 1 ? '<span class="ilmuna-badge ilmuna-badge--info">Default</span>' : ''}</td>
                    <td data-label="Driver"><code>${WaPanel.escape(d.driver)}</code></td>
                    <td data-label="Nomor"><code>${WaPanel.escape(d.phone_number || '-')}</code></td>
                    <td data-label="Endpoint"><code style="font-size:12px;">${WaPanel.escape(d.endpoint_url || '-')}</code></td>
                    <td data-label="Status"><span class="ilmuna-badge ilmuna-badge--${stClass}">${WaPanel.escape(d.status)}</span></td>
                    <td data-label="Aktif">${WaPanel.escape(d.last_seen_at || '-')}</td>
                </tr>`;
            }).join('');
        } else if (activeTab === 'queue') {
            thead.innerHTML = '<tr><th>ID</th><th>Kampanye / Sumber</th><th>Penerima</th><th>Tipe</th><th>Prioritas</th><th>Status</th><th>Jadwal</th></tr>';
            if (!filtered.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:28px;">Antrean broadcast kosong.</td></tr>';
                return;
            }
            tbody.innerHTML = filtered.map(q => {
                const stClass = q.status === 'sent' ? 'success' : (q.status === 'failed' ? 'danger' : 'warning');
                return `<tr>
                    <td data-label="ID">#${q.id}</td>
                    <td data-label="Sumber">${WaPanel.escape(q.campaign_title || 'Notifikasi Real-Time Learn')}</td>
                    <td data-label="Penerima"><strong>${WaPanel.escape(q.recipient_name || '-')}</strong><br><code style="font-size:11.5px;">${WaPanel.escape(q.recipient_jid)}</code></td>
                    <td data-label="Tipe"><span class="ilmuna-badge ilmuna-badge--navy">${WaPanel.escape(q.payload_type)}</span></td>
                    <td data-label="Prioritas">P${q.priority} (${q.attempts}/3)</td>
                    <td data-label="Status"><span class="ilmuna-badge ilmuna-badge--${stClass}">${WaPanel.escape(q.status)}</span></td>
                    <td data-label="Jadwal">${WaPanel.escape(q.scheduled_at)}</td>
                </tr>`;
            }).join('');
        } else if (activeTab === 'violations') {
            thead.innerHTML = '<tr><th>Waktu</th><th>Pengirim</th><th>Pelanggaran</th><th>Strike</th><th>Tindakan</th><th>Sync BK &amp; Ortu</th></tr>';
            if (!filtered.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Aman — belum ada pelanggaran grup tercatat.</td></tr>';
                return;
            }
            tbody.innerHTML = filtered.map(v => `<tr>
                <td data-label="Waktu">${WaPanel.escape(v.created_at)}</td>
                <td data-label="Pengirim"><strong>${WaPanel.escape(v.sender_name || '-')}</strong><br><code>${WaPanel.escape(v.sender_phone)}</code></td>
                <td data-label="Jenis"><span class="ilmuna-badge ilmuna-badge--danger">${WaPanel.escape(v.violation_type)}</span></td>
                <td data-label="Strike"><strong>#${v.strike_count}</strong></td>
                <td data-label="Tindakan">${WaPanel.escape(v.action_taken)}</td>
                <td data-label="Sync">${v.synced_to_bk == 1 ? '<span class="ilmuna-badge ilmuna-badge--success">BK ✓</span>' : '<span class="ilmuna-badge ilmuna-badge--navy">Log</span>'} ${v.parent_notified == 1 ? '<span class="ilmuna-badge ilmuna-badge--info">Ortu ✓</span>' : ''}</td>
            </tr>`).join('');
        } else {
            thead.innerHTML = '<tr><th>Waktu</th><th>Nomor Penelepon</th><th>Jenis</th><th>Auto-Reject</th><th>Balasan</th><th>Auto-Block</th></tr>';
            if (!filtered.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Belum ada panggilan masuk tercatat.</td></tr>';
                return;
            }
            tbody.innerHTML = filtered.map(c => `<tr>
                <td data-label="Waktu">${WaPanel.escape(c.created_at)}</td>
                <td data-label="Nomor"><code>${WaPanel.escape(c.caller_phone)}</code></td>
                <td data-label="Jenis"><span class="ilmuna-badge ilmuna-badge--info">${WaPanel.escape(c.call_type)}</span></td>
                <td data-label="Reject">${c.was_rejected == 1 ? '<span class="ilmuna-badge ilmuna-badge--success">Ditolak</span>' : '-'}</td>
                <td data-label="Balasan">${c.auto_replied == 1 ? '<span class="ilmuna-badge ilmuna-badge--success">Terkirim</span>' : '-'}</td>
                <td data-label="Block">${c.triggered_block == 1 ? '<span class="ilmuna-badge ilmuna-badge--danger">Diblokir</span>' : '<span class="ilmuna-badge ilmuna-badge--navy">Normal</span>'}</td>
            </tr>`).join('');
        }
    }

    async function loadTab(tab) {
        activeTab = tab;
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:28px;"><iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin" width="20"></iconify-icon> Memuat...</td></tr>';
        try {
            const res = await WaPanel.get('?ajax=1&tab=' + encodeURIComponent(tab));
            if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
            const d = res.data;
            document.getElementById('cntDevices').textContent = d.counts.devices;
            document.getElementById('cntQueue').textContent = d.counts.queue;
            document.getElementById('cntViolations').textContent = d.counts.violations;
            document.getElementById('cntCalls').textContent = d.counts.calls;
            document.getElementById('driverModeBadge').textContent = (d.driver_mode || 'hybrid').toUpperCase();
            currentRows = d.rows || [];
            renderRows(currentRows);
        } catch (err) {
            Ilmuna.error('Kesalahan Jaringan', err.message);
        }
    }

    WaPanel.initTabs('#overviewTabs', loadTab);
    searchInput.addEventListener('input', () => renderRows(currentRows));
    document.getElementById('btnRefreshTab').addEventListener('click', () => loadTab(activeTab));
    document.getElementById('btnProcessQueue').addEventListener('click', async function () {
        this.disabled = true;
        try {
            const res = await WaPanel.post('?ajax=1', { action: 'process_queue_now' });
            if (res.success) {
                Ilmuna.success('Antrean Diproses', res.message);
                loadTab(activeTab);
            } else {
                Ilmuna.error('Gagal', res.message);
            }
        } catch (err) {
            Ilmuna.error('Galat', err.message);
        } finally {
            this.disabled = false;
        }
    });

    loadTab('devices');
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
