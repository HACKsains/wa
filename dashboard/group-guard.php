<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — DASHBOARD 5: GROUP GUARD (GRUP, ATURAN & LOG PELANGGARAN)
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

            if ($act === 'save_group') {
                $id    = (int)($body['id'] ?? 0);
                $jid   = trim((string)($body['group_jid'] ?? ''));
                $name  = trim((string)($body['group_name'] ?? ''));
                $type  = in_array($body['group_type'] ?? '', ['kelas','orang_tua','guru','umum'], true) ? (string)$body['group_type'] : 'kelas';
                $kid   = !empty($body['learn_kelas_id']) ? (int)$body['learn_kelas_id'] : null;
                $guard = !empty($body['guard_enabled']) ? 1 : 0;
                $bk    = !empty($body['sync_bk_enabled']) ? 1 : 0;
                $ortu  = !empty($body['notify_parent_enabled']) ? 1 : 0;
                $qs    = trim((string)($body['quiet_start'] ?? '')) ?: null;
                $qe    = trim((string)($body['quiet_end'] ?? '')) ?: null;

                if ($jid === '' || !wa_is_group_jid($jid) || $name === '') {
                    wa_json_response(false, 'Group JID (akhiran @g.us) dan Nama Grup wajib diisi.', [], 422);
                }

                if ($id > 0) {
                    $st = $pdo->prepare(
                        'UPDATE wa_groups
                         SET group_jid = :jid, group_name = :name, group_type = :type, learn_kelas_id = :kid,
                             guard_enabled = :g, sync_bk_enabled = :bk, notify_parent_enabled = :o, quiet_start = :qs, quiet_end = :qe
                         WHERE id = :id'
                    );
                    $st->execute([':jid'=>$jid, ':name'=>$name, ':type'=>$type, ':kid'=>$kid, ':g'=>$guard, ':bk'=>$bk, ':o'=>$ortu, ':qs'=>$qs, ':qe'=>$qe, ':id'=>$id]);
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO wa_groups (group_jid, group_name, group_type, learn_kelas_id, guard_enabled, sync_bk_enabled, notify_parent_enabled, quiet_start, quiet_end)
                         VALUES (:jid, :name, :type, :kid, :g, :bk, :o, :qs, :qe)'
                    );
                    $st->execute([':jid'=>$jid, ':name'=>$name, ':type'=>$type, ':kid'=>$kid, ':g'=>$guard, ':bk'=>$bk, ':o'=>$ortu, ':qs'=>$qs, ':qe'=>$qe]);
                    $id = (int)$pdo->lastInsertId();
                }

                $r = $pdo->prepare('SELECT * FROM wa_groups WHERE id = :id');
                $r->execute([':id' => $id]);
                wa_json_response(true, 'Konfigurasi grup berhasil disimpan.', $r->fetch(PDO::FETCH_ASSOC));
            }

            if ($act === 'toggle_group_flag') {
                $id  = (int)($body['id'] ?? 0);
                $col = in_array($body['field'] ?? '', ['guard_enabled', 'sync_bk_enabled', 'notify_parent_enabled'], true) ? (string)$body['field'] : '';
                if ($col === '') {
                    wa_json_response(false, 'Kolom tidak valid.', [], 422);
                }
                $pdo->prepare("UPDATE wa_groups SET `{$col}` = IF(`{$col}` = 1, 0, 1) WHERE id = :id")->execute([':id' => $id]);
                $r = $pdo->prepare('SELECT * FROM wa_groups WHERE id = :id');
                $r->execute([':id' => $id]);
                wa_json_response(true, 'Pengaturan grup diperbarui.', $r->fetch(PDO::FETCH_ASSOC));
            }

            if ($act === 'save_rule') {
                $id   = (int)($body['id'] ?? 0);
                $type = in_array($body['rule_type'] ?? '', ['badword','link_blacklist','link_whitelist','flood','virtex'], true) ? (string)$body['rule_type'] : 'badword';
                $pat  = trim((string)($body['pattern'] ?? ''));
                $sev  = in_array($body['severity'] ?? '', ['ringan','sedang','berat'], true) ? (string)$body['severity'] : 'sedang';
                $mode = in_array($body['action_mode'] ?? '', ['warn','delete_warn','delete_kick'], true) ? (string)$body['action_mode'] : 'delete_warn';
                $poin = max(0, (int)($body['bk_poin'] ?? 10));

                if ($pat === '') {
                    wa_json_response(false, 'Kata / pola aturan tidak boleh kosong.', [], 422);
                }

                if ($id > 0) {
                    $st = $pdo->prepare('UPDATE wa_group_rules SET rule_type=:t, pattern=:p, severity=:s, action_mode=:m, bk_poin=:bp WHERE id=:id');
                    $st->execute([':t'=>$type, ':p'=>$pat, ':s'=>$sev, ':m'=>$mode, ':bp'=>$poin, ':id'=>$id]);
                } else {
                    $st = $pdo->prepare('INSERT INTO wa_group_rules (rule_type, pattern, severity, action_mode, bk_poin, is_active) VALUES (:t, :p, :s, :m, :bp, 1)');
                    $st->execute([':t'=>$type, ':p'=>$pat, ':s'=>$sev, ':m'=>$mode, ':bp'=>$poin]);
                    $id = (int)$pdo->lastInsertId();
                }

                $r = $pdo->prepare('SELECT * FROM wa_group_rules WHERE id = :id');
                $r->execute([':id' => $id]);
                wa_json_response(true, 'Aturan Group Guard berhasil disimpan.', $r->fetch(PDO::FETCH_ASSOC));
            }

            if ($act === 'delete_rule') {
                $id = (int)($body['id'] ?? 0);
                $pdo->prepare('DELETE FROM wa_group_rules WHERE id = :id')->execute([':id' => $id]);
                wa_json_response(true, 'Aturan berhasil dihapus.', ['id' => $id]);
            }

            wa_json_response(false, 'Aksi tidak dikenali.', [], 400);
        }

        $tab = strtolower(trim((string)($_GET['tab'] ?? 'rules')));
        $counts = [
            'groups'     => (int)$pdo->query('SELECT COUNT(*) FROM wa_groups')->fetchColumn(),
            'rules'      => (int)$pdo->query('SELECT COUNT(*) FROM wa_group_rules WHERE is_active = 1')->fetchColumn(),
            'violations' => (int)$pdo->query('SELECT COUNT(*) FROM wa_violation_logs')->fetchColumn(),
        ];

        if ($tab === 'groups') {
            $rows = $pdo->query('SELECT * FROM wa_groups ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($tab === 'violations') {
            $rows = $pdo->query('SELECT * FROM wa_violation_logs ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $pdo->query('SELECT * FROM wa_group_rules ORDER BY rule_type ASC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
        }

        wa_json_response(true, 'Data Group Guard dimuat.', ['tab' => $tab, 'counts' => $counts, 'rows' => $rows]);
    } catch (Throwable $e) {
        wa_json_response(false, 'Galat Group Guard: ' . $e->getMessage(), [], 500);
    }
}

$pageTitle = 'Group Guard & Moderasi Grup';
$pageIcon  = 'solar:shield-warning-bold';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="curriculum-main-tabs" id="guardTabs" role="tablist">
    <button type="button" class="curriculum-tab-btn is-active" data-tab="rules">
        <iconify-icon icon="solar:forbidden-circle-bold"></iconify-icon>
        <span class="curriculum-tab-label">Kamus Kata &amp; Aturan Link</span>
        <span class="curriculum-tab-counter" id="cntRules">0</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="groups">
        <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon>
        <span class="curriculum-tab-label">Grup Dipantau &amp; Jam Malam</span>
        <span class="curriculum-tab-counter" id="cntGroups">0</span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-tab="violations">
        <iconify-icon icon="solar:shield-warning-bold"></iconify-icon>
        <span class="curriculum-tab-label">Log Pelanggaran &amp; Strike</span>
        <span class="curriculum-tab-counter" id="cntViol">0</span>
    </button>
</div>

<div class="ilmuna-filter-box">
    <div class="ilmuna-filter-topline">
        <div class="ilmuna-search-wrap">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="searchGuard" class="ilmuna-search-input" placeholder="Cari kata terlarang, domain, grup, atau nomor pelaku..." autocomplete="off">
        </div>
        <div class="ilmuna-filter-actions">
            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" id="btnAddRule">
                <iconify-icon icon="solar:add-circle-bold" width="17"></iconify-icon>
                <span>Tambah Aturan</span>
            </button>
            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--gold" id="btnAddGroup">
                <iconify-icon icon="solar:users-group-rounded-bold" width="17"></iconify-icon>
                <span>Tambah Grup</span>
            </button>
        </div>
    </div>
</div>

<div class="ilmuna-card">
    <div class="table-responsive">
        <table class="ilmuna-table ilmuna-table-stack">
            <thead id="guardThead"><tr><th>Memuat...</th></tr></thead>
            <tbody id="guardTbody">
                <tr><td class="text-center text-muted" style="padding:28px;">
                    <iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin" width="20"></iconify-icon> Memuat aturan Group Guard...
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL ATURAN GROUP GUARD -->
<div class="ilm-modal-backdrop" id="modalRule">
    <div class="ilm-modal-dialog" style="max-width:560px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:forbidden-circle-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Aturan Deteksi Pelanggaran</h3>
                    <p class="ilm-modal-header__subtitle">Badword mendukung normalisasi leetspeak otomatis (4nj1ng, g0bl0k, dll)</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalRule')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formRule">
            <input type="hidden" id="ruleId" value="0">
            <div class="ilm-modal-body">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Jenis Aturan</label>
                        <select id="ruleType" class="form-control">
                            <option value="badword">Kata Kasar / Toxic (badword)</option>
                            <option value="link_blacklist">Blacklist Judol / Link (regex)</option>
                            <option value="link_whitelist">Whitelist Domain Resmi</option>
                            <option value="flood">Anti-Flood (N_msg_in_S_sec)</option>
                            <option value="virtex">Anti-Virtex (len_gt_N)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tingkat &amp; Poin BK</label>
                        <div style="display:grid;grid-template-columns:1fr 90px;gap:8px;">
                            <select id="ruleSeverity" class="form-control">
                                <option value="ringan">Ringan</option>
                                <option value="sedang" selected>Sedang</option>
                                <option value="berat">Berat</option>
                            </select>
                            <input type="number" id="rulePoin" class="form-control" min="0" max="100" value="15">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Kata / Pola Regex / Domain</label>
                    <input type="text" id="rulePattern" class="form-control font-mono" required placeholder="Contoh: slot|gacor atau ilmuna.my.id">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Tindakan Otomatis Bot</label>
                    <select id="ruleMode" class="form-control">
                        <option value="warn">Peringatan Saja (warn)</option>
                        <option value="delete_warn" selected>Hapus Pesan + Peringatan (delete_warn)</option>
                        <option value="delete_kick">Hapus Pesan + Kick Langsung (delete_kick)</option>
                    </select>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalRule')"><span>Batal</span></button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary"><iconify-icon icon="solar:disk-bold"></iconify-icon><span>Simpan Aturan</span></button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL TAMBAH / EDIT GRUP -->
<div class="ilm-modal-backdrop" id="modalGroup">
    <div class="ilm-modal-dialog" style="max-width:600px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Konfigurasi Grup WhatsApp</h3>
                    <p class="ilm-modal-header__subtitle">Atur proteksi moderasi, jam malam, sinkron BK &amp; notifikasi Orang Tua</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalGroup')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formGroup">
            <input type="hidden" id="grpId" value="0">
            <div class="ilm-modal-body">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Group JID (@g.us)</label>
                        <input type="text" id="grpJid" class="form-control font-mono" required placeholder="1203630xxxx@g.us">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nama Grup</label>
                        <input type="text" id="grpName" class="form-control" required placeholder="Kelas X PAI-1">
                    </div>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Tipe Grup &amp; ID Kelas Learn</label>
                        <div style="display:grid;grid-template-columns:1fr 90px;gap:8px;">
                            <select id="grpType" class="form-control">
                                <option value="kelas">Kelas</option>
                                <option value="orang_tua">Orang Tua</option>
                                <option value="guru">Guru</option>
                                <option value="umum">Umum</option>
                            </select>
                            <input type="number" id="grpKelasId" class="form-control" placeholder="ID">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jam Malam Grup (Mulai – Selesai)</label>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <input type="time" id="grpQuietStart" class="form-control">
                            <input type="time" id="grpQuietEnd" class="form-control">
                        </div>
                    </div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:13px;font-weight:600;">
                    <label><input type="checkbox" id="grpGuard" checked> Guard Aktif</label>
                    <label><input type="checkbox" id="grpSyncBk" checked> Sinkron BK Learn</label>
                    <label><input type="checkbox" id="grpNotifyOrtu" checked> Notif WA Orang Tua</label>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalGroup')"><span>Batal</span></button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary"><iconify-icon icon="solar:disk-bold"></iconify-icon><span>Simpan Grup</span></button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let activeTab = 'rules';
    let rows = [];
    const thead = document.getElementById('guardThead');
    const tbody = document.getElementById('guardTbody');
    const searchInput = document.getElementById('searchGuard');

    function render() {
        const q = (searchInput.value || '').trim().toLowerCase();
        const list = q === '' ? rows : rows.filter(r => JSON.stringify(r).toLowerCase().includes(q));

        if (activeTab === 'rules') {
            thead.innerHTML = '<tr><th>Jenis</th><th>Kata / Pola</th><th>Tingkat</th><th>Aksi</th><th>Poin BK</th><th class="text-center">Hapus</th></tr>';
            tbody.innerHTML = list.length ? list.map(r => `<tr id="rule-row-${r.id}">
                <td data-label="Jenis"><span class="ilmuna-badge ilmuna-badge--${r.rule_type === 'link_whitelist' ? 'success' : 'info'}">${WaPanel.escape(r.rule_type)}</span></td>
                <td data-label="Pola"><code>${WaPanel.escape(r.pattern)}</code></td>
                <td data-label="Tingkat"><span class="ilmuna-badge ilmuna-badge--${r.severity === 'berat' ? 'danger' : (r.severity === 'sedang' ? 'warning' : 'navy')}">${WaPanel.escape(r.severity)}</span></td>
                <td data-label="Aksi"><code>${WaPanel.escape(r.action_mode)}</code></td>
                <td data-label="Poin BK"><strong>${r.bk_poin}</strong></td>
                <td data-label="Hapus" class="text-center"><button type="button" class="btn btn-sm btn-danger btn-del-rule" data-id="${r.id}"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button></td>
            </tr>`).join('') : '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Belum ada aturan.</td></tr>';
            tbody.querySelectorAll('.btn-del-rule').forEach(btn => {
                btn.onclick = async function () {
                    const id = parseInt(this.dataset.id, 10);
                    const ok = await Ilmuna.confirm('error', 'Hapus Aturan?', 'Aturan deteksi ini akan dihapus permanen.');
                    if (!ok) return;
                    const res = await WaPanel.post('?ajax=1', { action: 'delete_rule', id });
                    if (res.success) {
                        rows = rows.filter(x => x.id != id);
                        WaPanel.removeRow(document.getElementById('rule-row-' + id), () => {
                            document.getElementById('cntRules').textContent = rows.length;
                        });
                        Ilmuna.success('Dihapus', res.message);
                    }
                };
            });
        } else if (activeTab === 'groups') {
            thead.innerHTML = '<tr><th>Grup WA</th><th>Tipe / Kelas</th><th>Jam Malam</th><th>Guard</th><th>Sync BK</th><th>Notif Ortu</th></tr>';
            tbody.innerHTML = list.length ? list.map(g => `<tr id="grp-row-${g.id}">
                <td data-label="Grup"><strong>${WaPanel.escape(g.group_name)}</strong><br><code style="font-size:11.5px;">${WaPanel.escape(g.group_jid)}</code></td>
                <td data-label="Tipe"><span class="ilmuna-badge ilmuna-badge--navy">${WaPanel.escape(g.group_type)}</span> ${g.learn_kelas_id ? 'Kelas #' + g.learn_kelas_id : ''}</td>
                <td data-label="Jam Malam">${g.quiet_start && g.quiet_end ? WaPanel.escape(g.quiet_start.slice(0,5) + ' – ' + g.quiet_end.slice(0,5)) : '<span class="text-muted">Nonaktif</span>'}</td>
                <td data-label="Guard"><button type="button" class="ilmuna-badge ilmuna-badge--${g.guard_enabled == 1 ? 'success' : 'danger'} btn-tog-grp" data-id="${g.id}" data-field="guard_enabled" style="cursor:pointer;">${g.guard_enabled == 1 ? 'Aktif' : 'Mati'}</button></td>
                <td data-label="BK"><button type="button" class="ilmuna-badge ilmuna-badge--${g.sync_bk_enabled == 1 ? 'success' : 'navy'} btn-tog-grp" data-id="${g.id}" data-field="sync_bk_enabled" style="cursor:pointer;">${g.sync_bk_enabled == 1 ? 'Sync' : 'Off'}</button></td>
                <td data-label="Ortu"><button type="button" class="ilmuna-badge ilmuna-badge--${g.notify_parent_enabled == 1 ? 'info' : 'navy'} btn-tog-grp" data-id="${g.id}" data-field="notify_parent_enabled" style="cursor:pointer;">${g.notify_parent_enabled == 1 ? 'Aktif' : 'Off'}</button></td>
            </tr>`).join('') : '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Belum ada grup terdaftar.</td></tr>';
            tbody.querySelectorAll('.btn-tog-grp').forEach(btn => {
                btn.onclick = async function () {
                    const id = parseInt(this.dataset.id, 10);
                    const field = this.dataset.field;
                    const res = await WaPanel.post('?ajax=1', { action: 'toggle_group_flag', id, field });
                    if (res.success && res.data) {
                        rows = rows.map(x => x.id == id ? res.data : x);
                        render();
                        WaPanel.flashRow(document.getElementById('grp-row-' + id));
                    }
                };
            });
        } else {
            thead.innerHTML = '<tr><th>Waktu</th><th>Pelaku</th><th>Jenis &amp; Pola</th><th>Cuplikan</th><th>Strike &amp; Aksi</th><th>BK &amp; Ortu</th></tr>';
            tbody.innerHTML = list.length ? list.map(v => `<tr>
                <td data-label="Waktu">${WaPanel.escape(v.created_at)}</td>
                <td data-label="Pelaku"><strong>${WaPanel.escape(v.sender_name || '-')}</strong><br><code>${WaPanel.escape(v.sender_phone)}</code></td>
                <td data-label="Jenis"><span class="ilmuna-badge ilmuna-badge--danger">${WaPanel.escape(v.violation_type)}</span><br><small><code>${WaPanel.escape(v.matched_pattern || '')}</code></small></td>
                <td data-label="Pesan"><small>${WaPanel.escape((v.message_excerpt || '').slice(0, 100))}</small></td>
                <td data-label="Aksi"><strong>Strike #${v.strike_count}</strong><br><small>${WaPanel.escape(v.action_taken)}</small></td>
                <td data-label="Sync">${v.synced_to_bk == 1 ? '<span class="ilmuna-badge ilmuna-badge--success">BK ✓</span>' : '-'} ${v.parent_notified == 1 ? '<span class="ilmuna-badge ilmuna-badge--info">Ortu ✓</span>' : ''}</td>
            </tr>`).join('') : '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Belum ada pelanggaran tercatat.</td></tr>';
        }
    }

    async function loadTab(tab) {
        activeTab = tab;
        const res = await WaPanel.get('?ajax=1&tab=' + encodeURIComponent(tab));
        if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
        document.getElementById('cntRules').textContent = res.data.counts.rules;
        document.getElementById('cntGroups').textContent = res.data.counts.groups;
        document.getElementById('cntViol').textContent = res.data.counts.violations;
        rows = res.data.rows || [];
        render();
    }

    WaPanel.initTabs('#guardTabs', loadTab);
    searchInput.addEventListener('input', render);

    document.getElementById('btnAddRule').addEventListener('click', () => {
        document.getElementById('formRule').reset();
        document.getElementById('ruleId').value = '0';
        openIlmunaModal('modalRule');
    });
    document.getElementById('btnAddGroup').addEventListener('click', () => {
        document.getElementById('formGroup').reset();
        document.getElementById('grpId').value = '0';
        openIlmunaModal('modalGroup');
    });

    document.getElementById('formRule').addEventListener('submit', async function (e) {
        e.preventDefault();
        const res = await WaPanel.post('?ajax=1', {
            action: 'save_rule',
            id: parseInt(document.getElementById('ruleId').value, 10),
            rule_type: document.getElementById('ruleType').value,
            pattern: document.getElementById('rulePattern').value,
            severity: document.getElementById('ruleSeverity').value,
            action_mode: document.getElementById('ruleMode').value,
            bk_poin: parseInt(document.getElementById('rulePoin').value, 10)
        });
        if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
        closeIlmunaModal('modalRule');
        Ilmuna.success('Tersimpan', res.message);
        if (activeTab === 'rules') {
            rows.unshift(res.data);
            document.getElementById('cntRules').textContent = rows.length;
            render();
            const tr = document.getElementById('rule-row-' + res.data.id);
            if (tr) tr.classList.add('row-smooth-in');
        } else {
            loadTab(activeTab);
        }
    });

    document.getElementById('formGroup').addEventListener('submit', async function (e) {
        e.preventDefault();
        const res = await WaPanel.post('?ajax=1', {
            action: 'save_group',
            id: parseInt(document.getElementById('grpId').value, 10),
            group_jid: document.getElementById('grpJid').value,
            group_name: document.getElementById('grpName').value,
            group_type: document.getElementById('grpType').value,
            learn_kelas_id: document.getElementById('grpKelasId').value,
            quiet_start: document.getElementById('grpQuietStart').value,
            quiet_end: document.getElementById('grpQuietEnd').value,
            guard_enabled: document.getElementById('grpGuard').checked ? 1 : 0,
            sync_bk_enabled: document.getElementById('grpSyncBk').checked ? 1 : 0,
            notify_parent_enabled: document.getElementById('grpNotifyOrtu').checked ? 1 : 0
        });
        if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
        closeIlmunaModal('modalGroup');
        Ilmuna.success('Tersimpan', res.message);
        loadTab('groups');
    });

    loadTab('rules');
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
