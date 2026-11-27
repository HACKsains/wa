<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — DASHBOARD 7: LOG PESAN MASUK/KELUAR & SYNC ILMUNA LEARN
 * ==============================================================================
 */

require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/auth.php';

wa_require_admin(isset($_GET['ajax']) && $_GET['ajax'] === '1');

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $pdo = getDBConnection();
    $tab = strtolower(trim((string)($_GET['tab'] ?? 'messages')));

    try {
        $counts = [
            'messages' => (int)$pdo->query('SELECT COUNT(*) FROM wa_message_logs')->fetchColumn(),
            'sync'     => (int)$pdo->query('SELECT COUNT(*) FROM wa_learn_sync_logs')->fetchColumn(),
        ];

        $rows = $tab === 'sync'
            ? $pdo->query('SELECT * FROM wa_learn_sync_logs ORDER BY id DESC LIMIT 120')->fetchAll(PDO::FETCH_ASSOC)
            : $pdo->query('SELECT * FROM wa_message_logs ORDER BY id DESC LIMIT 120')->fetchAll(PDO::FETCH_ASSOC);

        wa_json_response(true, 'Audit log berhasil dimuat.', [
            'tab'    => $tab,
            'counts' => $counts,
            'rows'   => $rows,
        ]);
    } catch (Throwable $e) {
        wa_json_response(false, 'Galat memuat log: ' . $e->getMessage(), [], 500);
    }
}

$pageTitle = 'Log Pesan & Sinkronisasi Learn';
$pageIcon  = 'solar:document-text-bold';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="curriculum-main-tabs" id="logTabs" role="tablist">
    <button type="button" class="curriculum-tab-btn is-active" data-tab="messages">
        <iconify-icon icon="solar:chat-round-dots-bold"></iconify-icon>
        <span class="curriculum-tab-label">Log Pesan WA (In / Out)</span>
        <span class="curriculum-tab-counter" id="cntMsgLogs">0</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="sync">
        <iconify-icon icon="solar:transfer-horizontal-bold"></iconify-icon>
        <span class="curriculum-tab-label">Sinkronisasi API Ilmuna Learn</span>
        <span class="curriculum-tab-counter" id="cntSyncLogs">0</span>
    </button>
</div>

<div class="ilmuna-filter-box">
    <div class="ilmuna-filter-topline">
        <div class="ilmuna-pill-group" id="dirPills">
            <button type="button" class="ilmuna-pill-btn is-active" data-dir="all">Semua Arah</button>
            <button type="button" class="ilmuna-pill-btn" data-dir="first">Masuk / Learn → Bot</button>
            <button type="button" class="ilmuna-pill-btn" data-dir="second">Keluar / Bot → Learn</button>
        </div>
        <div class="ilmuna-search-wrap">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="searchLogs" class="ilmuna-search-input" placeholder="Cari nomor, button_id, event, atau isi pesan..." autocomplete="off">
        </div>
        <div class="ilmuna-filter-actions">
            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" id="btnReloadLogs">
                <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                <span>Segarkan</span>
            </button>
        </div>
    </div>
</div>

<div class="ilmuna-card">
    <div class="table-responsive">
        <table class="ilmuna-table ilmuna-table-stack">
            <thead id="logsThead"><tr><th>Memuat...</th></tr></thead>
            <tbody id="logsTbody">
                <tr><td class="text-center text-muted" style="padding:28px;">
                    <iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin" width="20"></iconify-icon> Memuat audit log...
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let activeTab = 'messages';
    let dirFilter = 'all';
    let rows = [];
    const thead = document.getElementById('logsThead');
    const tbody = document.getElementById('logsTbody');
    const searchInput = document.getElementById('searchLogs');

    function render() {
        const q = (searchInput.value || '').trim().toLowerCase();
        const filtered = rows.filter(r => {
            if (dirFilter === 'first' && !(r.direction === 'in' || r.direction === 'learn_to_wa')) return false;
            if (dirFilter === 'second' && !(r.direction === 'out' || r.direction === 'wa_to_learn')) return false;
            return q === '' || JSON.stringify(r).toLowerCase().includes(q);
        });

        if (activeTab === 'messages') {
            thead.innerHTML = '<tr><th>Waktu</th><th>Arah</th><th>JID / Nomor</th><th>Tipe &amp; Button ID</th><th>Pratinjau Pesan</th></tr>';
            tbody.innerHTML = filtered.length ? filtered.map(m => `<tr>
                <td data-label="Waktu">${WaPanel.escape(m.created_at)}</td>
                <td data-label="Arah"><span class="ilmuna-badge ilmuna-badge--${m.direction === 'in' ? 'info' : 'success'}">${m.direction === 'in' ? '↓ Masuk' : '↑ Keluar'}</span></td>
                <td data-label="JID"><code style="font-size:12px;">${WaPanel.escape(m.remote_jid)}</code></td>
                <td data-label="Tipe"><span class="ilmuna-badge ilmuna-badge--navy">${WaPanel.escape(m.message_type)}</span> ${m.button_id ? '<br><code style="font-size:11.5px;">' + WaPanel.escape(m.button_id) + '</code>' : ''}</td>
                <td data-label="Isi"><small>${WaPanel.escape((m.content_preview || '').slice(0, 140))}</small></td>
            </tr>`).join('') : '<tr><td colspan="5" class="text-center text-muted" style="padding:28px;">Belum ada log pesan.</td></tr>';
        } else {
            thead.innerHTML = '<tr><th>Waktu</th><th>Arah</th><th>Event / Action</th><th>HTTP</th><th>Ringkasan Respon</th></tr>';
            tbody.innerHTML = filtered.length ? filtered.map(s => {
                const ok = s.http_status >= 200 && s.http_status < 300;
                return `<tr>
                    <td data-label="Waktu">${WaPanel.escape(s.created_at)}</td>
                    <td data-label="Arah"><span class="ilmuna-badge ilmuna-badge--${s.direction === 'learn_to_wa' ? 'info' : 'navy'}">${WaPanel.escape(s.direction)}</span></td>
                    <td data-label="Event"><code>${WaPanel.escape(s.event_name)}</code></td>
                    <td data-label="HTTP"><span class="ilmuna-badge ilmuna-badge--${ok ? 'success' : 'danger'}">${WaPanel.escape(s.http_status || '-')}</span></td>
                    <td data-label="Respon"><small>${WaPanel.escape((s.response_summary || '').slice(0, 140))}</small></td>
                </tr>`;
            }).join('') : '<tr><td colspan="5" class="text-center text-muted" style="padding:28px;">Belum ada log sinkronisasi.</td></tr>';
        }
    }

    async function loadTab(tab) {
        activeTab = tab;
        const res = await WaPanel.get('?ajax=1&tab=' + encodeURIComponent(tab));
        if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
        document.getElementById('cntMsgLogs').textContent = res.data.counts.messages;
        document.getElementById('cntSyncLogs').textContent = res.data.counts.sync;
        rows = res.data.rows || [];
        render();
    }

    WaPanel.initTabs('#logTabs', loadTab);
    document.querySelectorAll('#dirPills .ilmuna-pill-btn').forEach(p => {
        p.addEventListener('click', function () {
            document.querySelectorAll('#dirPills .ilmuna-pill-btn').forEach(x => x.classList.remove('is-active'));
            this.classList.add('is-active');
            dirFilter = this.dataset.dir;
            render();
        });
    });
    searchInput.addEventListener('input', render);
    document.getElementById('btnReloadLogs').addEventListener('click', () => loadTab(activeTab));

    loadTab('messages');
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
