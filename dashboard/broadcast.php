<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — DASHBOARD 4: BROADCAST MASSAL & AUTO-BROADCAST AKADEMIK
 * ==============================================================================
 */

require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/auth.php';

wa_require_admin(isset($_GET['ajax']) && $_GET['ajax'] === '1');

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $pdo = getDBConnection();

    try {
        $transport = TransportFactory::create($pdo);
        $engine    = new BroadcastEngine($pdo, $transport);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            wa_require_csrf_ajax($body);
            $act = strtolower(trim((string)($body['action'] ?? '')));

            if ($act === 'create_campaign') {
                $admin = wa_current_admin();
                $customRaw = trim((string)($body['custom_recipients'] ?? ''));
                $filter = [];
                if (($body['target_scope'] ?? '') === 'custom' && $customRaw !== '') {
                    $lines = preg_split('/[\r\n,;]+/', $customRaw) ?: [];
                    $filter['recipients'] = array_values(array_filter(array_map('trim', $lines)));
                } elseif (!empty($body['kelas_ids'])) {
                    $filter['kelas_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)$body['kelas_ids']))));
                }

                $buttons = [];
                if (!empty($body['cta_url'])) {
                    $buttons[] = ['type' => 'cta_url', 'id' => 'CTA_BC', 'title' => (string)($body['cta_label'] ?: '🌐 Buka Tautan'), 'url' => (string)$body['cta_url']];
                }
                if (!empty($body['include_ack'])) {
                    $buttons[] = ['type' => 'reply', 'id' => 'ACK_READ_MANUAL_' . time(), 'title' => '✅ Tandai Dibaca'];
                }

                $res = $engine->createCampaign([
                    'title'         => (string)($body['title'] ?? ''),
                    'source_module' => (string)($body['source_module'] ?? 'manual'),
                    'target_scope'  => (string)($body['target_scope'] ?? 'all_ortu'),
                    'target_filter' => $filter,
                    'message_body'  => (string)($body['message_body'] ?? ''),
                    'media_url'     => (string)($body['media_url'] ?? ''),
                    'buttons'       => $buttons,
                    'scheduled_at'  => !empty($body['scheduled_at']) ? (string)$body['scheduled_at'] : date('Y-m-d H:i:s'),
                    'created_by'    => $admin['name'],
                ]);
                if (!$res['success']) {
                    wa_json_response(false, (string)($res['message'] ?? 'Gagal membuat kampanye.'), [], 422);
                }
                $row = $pdo->prepare('SELECT * FROM wa_broadcast_campaigns WHERE id = :id');
                $row->execute([':id' => $res['campaign_id']]);
                wa_json_response(true, "Kampanye dijadwalkan untuk {$res['total_targets']} target unik.", $row->fetch(PDO::FETCH_ASSOC));
            }

            if ($act === 'scan_academic_reminders') {
                $res = $engine->runReminderScan();
                wa_json_response((bool)$res['success'], $res['success']
                    ? "Pemindaian selesai: {$res['scheduled']} pengingat baru dijadwalkan ({$res['skipped']} sudah ada)."
                    : (string)($res['message'] ?? 'Gagal memindai Ilmuna Learn.'), $res);
            }

            if ($act === 'process_batch') {
                $res = $engine->processQueueBatch(10, 1, 2);
                wa_json_response(true, "Batch diproses: {$res['sent']} terkirim, {$res['retried']} diulang, {$res['failed']} gagal.", $res);
            }

            if (in_array($act, ['pause', 'resume', 'cancel'], true)) {
                $id = (int)($body['id'] ?? 0);
                $stMap = ['pause' => 'paused', 'resume' => 'scheduled', 'cancel' => 'cancelled'];
                $st = $stMap[$act];
                $pdo->prepare('UPDATE wa_broadcast_campaigns SET status = :st WHERE id = :id')->execute([':st' => $st, ':id' => $id]);
                wa_json_response(true, "Status kampanye #{$id} diubah menjadi {$st}.", ['id' => $id, 'status' => $st]);
            }

            wa_json_response(false, 'Aksi tidak dikenali.', [], 400);
        }

        $campaigns = $pdo->query('SELECT * FROM wa_broadcast_campaigns ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        $qStats    = $pdo->query('SELECT status, COUNT(*) FROM wa_broadcast_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

        wa_json_response(true, 'Daftar broadcast dimuat.', [
            'campaigns' => $campaigns,
            'queue'     => [
                'pending' => (int)($qStats['pending'] ?? 0),
                'sent'    => (int)($qStats['sent'] ?? 0),
                'failed'  => (int)($qStats['failed'] ?? 0),
            ],
        ]);
    } catch (Throwable $e) {
        wa_json_response(false, 'Galat Broadcast: ' . $e->getMessage(), [], 500);
    }
}

$pageTitle = 'Broadcast & Auto-Broadcast Akademik';
$pageIcon  = 'solar:plain-3-bold';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="ilmuna-filter-box">
    <div class="ilmuna-filter-topline">
        <div class="ilmuna-pill-group" id="bcStatusPills">
            <button type="button" class="ilmuna-pill-btn is-active" data-status="all">Semua</button>
            <button type="button" class="ilmuna-pill-btn" data-status="scheduled" style="color:#D97706;">Terjadwal</button>
            <button type="button" class="ilmuna-pill-btn" data-status="processing" style="color:#0284C7;">Proses</button>
            <button type="button" class="ilmuna-pill-btn" data-status="completed" style="color:#10B981;">Selesai</button>
        </div>

        <div class="ilmuna-search-wrap">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="searchBc" class="ilmuna-search-input" placeholder="Cari judul kampanye atau modul akademik..." autocomplete="off">
        </div>

        <div class="ilmuna-filter-actions">
            <div class="ilmuna-nav-stat-inline">
                <iconify-icon icon="solar:clock-circle-bold" width="15"></iconify-icon>
                <strong id="cntQueuePending">0</strong>
                <span>Antrean</span>
            </div>
            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" id="btnNewCampaign">
                <iconify-icon icon="solar:add-circle-bold" width="17"></iconify-icon>
                <span>Buat Broadcast</span>
            </button>
            <div class="ilmuna-nav-menu-wrap">
                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" data-dropdown-toggle="bcActionDrop">
                    <iconify-icon icon="solar:widget-5-bold" width="15"></iconify-icon>
                    <span>Aksi PHP</span>
                    <iconify-icon icon="solar:alt-arrow-down-bold" width="12"></iconify-icon>
                </button>
                <div class="ilmuna-nav-dropdown" id="bcActionDrop">
                    <button type="button" class="ilmuna-nav-drop-item" id="btnScanAcademic">
                        <iconify-icon icon="solar:calendar-date-bold" width="18" style="color:#0D7A87;"></iconify-icon>
                        <span>Tarik Pengingat H-1 Learn</span>
                    </button>
                    <button type="button" class="ilmuna-nav-drop-item" id="btnRunBatchNow">
                        <iconify-icon icon="solar:play-circle-bold" width="18" style="color:#10B981;"></iconify-icon>
                        <span>Kirim 1 Batch Antrean</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="ilmuna-card">
    <div class="table-responsive">
        <table class="ilmuna-table ilmuna-table-stack">
            <thead>
                <tr>
                    <th>Kampanye &amp; Sumber</th>
                    <th>Target Audiens</th>
                    <th>Progres Kirim</th>
                    <th>Jadwal</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody id="bcTbody">
                <tr><td colspan="6" class="text-center text-muted" style="padding:28px;">
                    <iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin" width="20"></iconify-icon> Memuat kampanye broadcast...
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- UNIVERSAL MODAL BUAT KAMPANYE BROADCAST -->
<div class="ilm-modal-backdrop" id="modalBroadcast">
    <div class="ilm-modal-dialog" style="max-width:680px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:plain-3-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Buat Kampanye Broadcast</h3>
                    <p class="ilm-modal-header__subtitle">Dikirim bertahap via antrean anti-banned (jeda acak 3–7 detik)</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalBroadcast')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formBroadcast">
            <div class="ilm-modal-body" style="max-height:calc(88vh - 160px);">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Judul Kampanye</label>
                        <input type="text" id="bcTitle" class="form-control" required placeholder="Misal: Pengumuman Penilaian Sumatif">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sumber Modul</label>
                        <select id="bcModule" class="form-control">
                            <option value="manual">Manual / Umum</option>
                            <option value="tugas">Tugas</option>
                            <option value="proyek">Proyek P5/PPRA</option>
                            <option value="agenda">Agenda / Kaldik</option>
                            <option value="asesmen">Asesmen / CBT</option>
                            <option value="lkpd">LKPD</option>
                            <option value="materi">Materi Baru</option>
                            <option value="mutabaah">Mutaba'ah Yaumiyah</option>
                            <option value="public_form">Link Form Publik Ter-Hash</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Target Audiens</label>
                        <select id="bcScope" class="form-control">
                            <option value="all_ortu">Semua Orang Tua (Ilmuna Learn)</option>
                            <option value="ortu_kelas">Orang Tua per Kelas</option>
                            <option value="all_siswa">Semua Siswa (Ilmuna Learn)</option>
                            <option value="siswa_kelas">Siswa per Kelas</option>
                            <option value="all_guru">Semua Guru</option>
                            <option value="groups">Grup WhatsApp Terdaftar</option>
                            <option value="custom">Nomor Kustom Manual</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jadwal Kirim (Universal Calendar)</label>
                        <div style="position:relative;">
                            <input type="text" id="bcSchedule" class="form-control" data-ilm-calendar="datetime" readonly placeholder="Sekarang (kosongkan untuk langsung)" style="cursor:pointer;padding-right:38px;">
                            <iconify-icon icon="solar:calendar-date-bold" onclick="document.getElementById('bcSchedule').click()" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--lumina-teal);cursor:pointer;"></iconify-icon>
                        </div>
                    </div>
                </div>
                <div class="form-group" id="wrapKelasIds" style="display:none;">
                    <label class="form-label">ID Kelas Ilmuna Learn (pisahkan koma, contoh: 1,2)</label>
                    <input type="text" id="bcKelasIds" class="form-control" placeholder="1,2">
                </div>
                <div class="form-group" id="wrapCustomPhones" style="display:none;">
                    <label class="form-label">Daftar Nomor Tujuan (1 nomor per baris atau pisahkan koma)</label>
                    <textarea id="bcCustomPhones" class="form-control font-mono" placeholder="081234567890&#10;6285678901234"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Isi Pesan (Variabel: <code>{nama}</code>, <code>{kelas}</code>, <code>{nama_anak}</code>, <code>{tanggal}</code>, <code>{link_learn}</code>)</label>
                    <textarea id="bcBody" class="form-control" required placeholder="Assalamu'alaikum Bapak/Ibu {nama}..."></textarea>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Tombol CTA URL (Opsional)</label>
                        <input type="url" id="bcCtaUrl" class="form-control" placeholder="https://learn.ilmuna.my.id/...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Lampiran Media URL (Gambar/PDF · Opsional)</label>
                        <input type="url" id="bcMediaUrl" class="form-control" placeholder="https://learn.ilmuna.my.id/uploads/...">
                    </div>
                </div>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;">
                    <input type="checkbox" id="bcIncludeAck" checked> Sertakan tombol cepat <code>[✅ Tandai Dibaca]</code>
                </label>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalBroadcast')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon><span>Batal</span>
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:plain-3-bold"></iconify-icon><span>Jadwalkan Broadcast</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let campaigns = [];
    let statusFilter = 'all';
    const tbody = document.getElementById('bcTbody');
    const searchInput = document.getElementById('searchBc');

    function rowHtml(c) {
        const cls = c.status === 'completed' ? 'success' : (c.status === 'cancelled' ? 'danger' : (c.status === 'processing' ? 'info' : 'warning'));
        const toggleBtn = c.status === 'paused'
            ? `<button type="button" class="btn btn-sm btn-outline btn-bc-act" data-act="resume" data-id="${c.id}" title="Lanjutkan"><iconify-icon icon="solar:play-bold"></iconify-icon></button>`
            : (c.status === 'scheduled' || c.status === 'processing'
                ? `<button type="button" class="btn btn-sm btn-outline btn-bc-act" data-act="pause" data-id="${c.id}" title="Jeda"><iconify-icon icon="solar:pause-bold"></iconify-icon></button>`
                : '');
        return `<tr id="bc-row-${c.id}">
            <td data-label="Kampanye"><strong>${WaPanel.escape(c.title)}</strong><br><span class="ilmuna-badge ilmuna-badge--navy">${WaPanel.escape(c.source_module)}</span></td>
            <td data-label="Target"><code>${WaPanel.escape(c.target_scope)}</code></td>
            <td data-label="Progres"><strong>${c.sent_count}/${c.total_targets}</strong> ${c.failed_count > 0 ? `<span class="ilmuna-badge ilmuna-badge--danger">${c.failed_count} gagal</span>` : ''}</td>
            <td data-label="Jadwal">${WaPanel.escape(c.scheduled_at || '-')}</td>
            <td data-label="Status"><span class="ilmuna-badge ilmuna-badge--${cls}">${WaPanel.escape(c.status)}</span></td>
            <td data-label="Aksi" class="text-center" style="white-space:nowrap;">
                ${toggleBtn}
                ${c.status !== 'completed' && c.status !== 'cancelled' ? `<button type="button" class="btn btn-sm btn-danger btn-bc-act" data-act="cancel" data-id="${c.id}" title="Batalkan"><iconify-icon icon="solar:close-circle-bold"></iconify-icon></button>` : ''}
            </td>
        </tr>`;
    }

    function renderTable() {
        const q = (searchInput.value || '').trim().toLowerCase();
        const list = campaigns.filter(c => {
            if (statusFilter !== 'all' && c.status !== statusFilter) return false;
            return q === '' || JSON.stringify(c).toLowerCase().includes(q);
        });
        tbody.innerHTML = list.length ? list.map(rowHtml).join('') : '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Belum ada kampanye broadcast.</td></tr>';
        tbody.querySelectorAll('.btn-bc-act').forEach(btn => {
            btn.onclick = async function () {
                const id = parseInt(this.dataset.id, 10);
                const act = this.dataset.act;
                const res = await WaPanel.post('?ajax=1', { action: act, id });
                if (res.success) {
                    campaigns = campaigns.map(x => x.id == id ? Object.assign({}, x, { status: res.data.status }) : x);
                    renderTable();
                    WaPanel.flashRow(document.getElementById('bc-row-' + id));
                    Ilmuna.success('Diperbarui', res.message);
                }
            };
        });
    }

    async function loadCampaigns() {
        const res = await WaPanel.get('?ajax=1');
        if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
        campaigns = res.data.campaigns || [];
        document.getElementById('cntQueuePending').textContent = res.data.queue.pending || 0;
        renderTable();
    }

    document.querySelectorAll('#bcStatusPills .ilmuna-pill-btn').forEach(p => {
        p.addEventListener('click', function () {
            document.querySelectorAll('#bcStatusPills .ilmuna-pill-btn').forEach(x => x.classList.remove('is-active'));
            this.classList.add('is-active');
            statusFilter = this.dataset.status;
            renderTable();
        });
    });

    document.getElementById('bcScope').addEventListener('change', function () {
        document.getElementById('wrapKelasIds').style.display = (this.value === 'ortu_kelas' || this.value === 'siswa_kelas') ? 'block' : 'none';
        document.getElementById('wrapCustomPhones').style.display = (this.value === 'custom') ? 'block' : 'none';
    });

    searchInput.addEventListener('input', renderTable);
    document.getElementById('btnNewCampaign').addEventListener('click', () => {
        document.getElementById('formBroadcast').reset();
        document.getElementById('wrapKelasIds').style.display = 'none';
        document.getElementById('wrapCustomPhones').style.display = 'none';
        openIlmunaModal('modalBroadcast');
    });

    document.getElementById('formBroadcast').addEventListener('submit', async function (e) {
        e.preventDefault();
        const res = await WaPanel.post('?ajax=1', {
            action: 'create_campaign',
            title: document.getElementById('bcTitle').value,
            source_module: document.getElementById('bcModule').value,
            target_scope: document.getElementById('bcScope').value,
            kelas_ids: document.getElementById('bcKelasIds').value,
            custom_recipients: document.getElementById('bcCustomPhones').value,
            scheduled_at: document.getElementById('bcSchedule').value,
            message_body: document.getElementById('bcBody').value,
            cta_url: document.getElementById('bcCtaUrl').value,
            media_url: document.getElementById('bcMediaUrl').value,
            include_ack: document.getElementById('bcIncludeAck').checked ? 1 : 0
        });
        if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
        closeIlmunaModal('modalBroadcast');
        campaigns.unshift(res.data);
        renderTable();
        const tr = document.getElementById('bc-row-' + res.data.id);
        if (tr) tr.classList.add('row-smooth-in');
        Ilmuna.success('Kampanye Dibuat', res.message);
    });

    document.getElementById('btnScanAcademic').addEventListener('click', async () => {
        const res = await WaPanel.post('?ajax=1', { action: 'scan_academic_reminders' });
        res.success ? Ilmuna.success('Sinkron Pengingat', res.message) : Ilmuna.warning('Info', res.message);
        loadCampaigns();
    });

    document.getElementById('btnRunBatchNow').addEventListener('click', async () => {
        const res = await WaPanel.post('?ajax=1', { action: 'process_batch' });
        res.success ? Ilmuna.success('Batch Diproses', res.message) : Ilmuna.error('Gagal', res.message);
        loadCampaigns();
    });

    loadCampaigns();
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
