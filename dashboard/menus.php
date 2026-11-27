<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — DASHBOARD 3: EDITOR VISUAL MENU TOMBOL INTERAKTIF PER ROLE
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

            if ($act === 'save_menu') {
                $id     = (int)($body['id'] ?? 0);
                $code   = strtoupper(trim((string)($body['menu_code'] ?? '')));
                $role   = in_array($body['target_role'] ?? '', ['all','admin','guru','guru_bk','orang_tua','siswa','guest'], true) ? (string)$body['target_role'] : 'all';
                $uiType = in_array($body['ui_type'] ?? '', ['buttons','list','cta_url'], true) ? (string)$body['ui_type'] : 'buttons';
                $header = trim((string)($body['header_text'] ?? ''));
                $bodyTx = trim((string)($body['body_text'] ?? ''));
                $footer = trim((string)($body['footer_text'] ?? 'Ilmuna Learn · Asisten Resmi Sekolah'));
                $btns   = is_array($body['buttons'] ?? null) ? $body['buttons'] : [];
                $active = !empty($body['is_active']) ? 1 : 0;

                if ($code === '' || $bodyTx === '' || $btns === []) {
                    wa_json_response(false, 'Kode menu, teks body, dan minimal 1 tombol wajib diisi.', [], 422);
                }

                $btnJson = json_encode(array_values($btns), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($id > 0) {
                    $st = $pdo->prepare(
                        'UPDATE wa_button_menus
                         SET menu_code = :code, target_role = :role, ui_type = :ui, header_text = :hdr,
                             body_text = :body, footer_text = :ftr, buttons_json = :btns, is_active = :act
                         WHERE id = :id'
                    );
                    $st->execute([
                        ':code' => $code, ':role' => $role, ':ui' => $uiType, ':hdr' => $header,
                        ':body' => $bodyTx, ':ftr' => $footer, ':btns' => $btnJson, ':act' => $active, ':id' => $id,
                    ]);
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO wa_button_menus (menu_code, target_role, ui_type, header_text, body_text, footer_text, buttons_json, is_active)
                         VALUES (:code, :role, :ui, :hdr, :body, :ftr, :btns, :act)'
                    );
                    $st->execute([
                        ':code' => $code, ':role' => $role, ':ui' => $uiType, ':hdr' => $header,
                        ':body' => $bodyTx, ':ftr' => $footer, ':btns' => $btnJson, ':act' => $active,
                    ]);
                    $id = (int)$pdo->lastInsertId();
                }

                $r = $pdo->prepare('SELECT * FROM wa_button_menus WHERE id = :id');
                $r->execute([':id' => $id]);
                wa_json_response(true, "Menu tombol {$code} berhasil disimpan.", $r->fetch(PDO::FETCH_ASSOC));
            }

            if ($act === 'toggle_menu') {
                $id = (int)($body['id'] ?? 0);
                $pdo->prepare('UPDATE wa_button_menus SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')->execute([':id' => $id]);
                $r = $pdo->prepare('SELECT * FROM wa_button_menus WHERE id = :id');
                $r->execute([':id' => $id]);
                wa_json_response(true, 'Status menu diperbarui.', $r->fetch(PDO::FETCH_ASSOC));
            }

            if ($act === 'delete_menu') {
                $id = (int)($body['id'] ?? 0);
                $pdo->prepare('DELETE FROM wa_button_menus WHERE id = :id')->execute([':id' => $id]);
                wa_json_response(true, 'Menu berhasil dihapus.', ['id' => $id]);
            }

            wa_json_response(false, 'Aksi tidak valid.', [], 400);
        }

        $menus = $pdo->query('SELECT * FROM wa_button_menus ORDER BY target_role ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        wa_json_response(true, 'Daftar menu interaktif dimuat.', $menus);
    } catch (Throwable $e) {
        wa_json_response(false, 'Galat Menu Editor: ' . $e->getMessage(), [], 500);
    }
}

$pageTitle = 'Editor Menu Tombol Interaktif';
$pageIcon  = 'solar:widget-5-bold';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="ilmuna-filter-box">
    <div class="ilmuna-filter-topline">
        <div class="ilmuna-pill-group" id="roleFilterPills">
            <button type="button" class="ilmuna-pill-btn is-active" data-role="all_roles">Semua Role</button>
            <button type="button" class="ilmuna-pill-btn" data-role="orang_tua">Orang Tua</button>
            <button type="button" class="ilmuna-pill-btn" data-role="siswa">Siswa</button>
            <button type="button" class="ilmuna-pill-btn" data-role="guru">Guru</button>
            <button type="button" class="ilmuna-pill-btn" data-role="guru_bk">Guru BK</button>
            <button type="button" class="ilmuna-pill-btn" data-role="guest">Tamu</button>
        </div>

        <div class="ilmuna-search-wrap">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="searchMenus" class="ilmuna-search-input" placeholder="Cari kode menu atau isi tombol..." autocomplete="off">
        </div>

        <div class="ilmuna-filter-actions">
            <div class="ilmuna-nav-stat-inline">
                <iconify-icon icon="solar:widget-5-bold" width="15"></iconify-icon>
                <strong id="cntMenus">0</strong>
                <span>Menu</span>
            </div>
            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" id="btnAddMenu">
                <iconify-icon icon="solar:add-circle-bold" width="17"></iconify-icon>
                <span>Tambah Menu</span>
            </button>
        </div>
    </div>
</div>

<div class="ilmuna-card">
    <div class="table-responsive">
        <table class="ilmuna-table ilmuna-table-stack">
            <thead>
                <tr>
                    <th>Kode Menu</th>
                    <th>Role</th>
                    <th>Tipe UI</th>
                    <th>Pratinjau Tombol</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody id="menusTbody">
                <tr><td colspan="6" class="text-center text-muted" style="padding:28px;">
                    <iconify-icon icon="solar:refresh-circle-bold" class="ilmuna-spin" width="20"></iconify-icon> Memuat daftar menu tombol...
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- UNIVERSAL MODAL EDITOR MENU TOMBOL -->
<div class="ilm-modal-backdrop" id="modalMenu">
    <div class="ilm-modal-dialog" style="max-width:720px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:widget-5-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="modalMenuTitle">Edit Menu Tombol</h3>
                    <p class="ilm-modal-header__subtitle">Perubahan langsung aktif di WhatsApp tanpa restart server</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalMenu')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formMenu">
            <input type="hidden" id="menuId" value="0">
            <div class="ilm-modal-body" style="max-height:calc(88vh - 160px);">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Kode Menu Unik</label>
                        <input type="text" id="menuCode" class="form-control font-mono" required placeholder="MENU_SISWA_MAIN">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Target Role &amp; Tipe UI</label>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <select id="menuRole" class="form-control">
                                <option value="all">all</option>
                                <option value="orang_tua">orang_tua</option>
                                <option value="siswa">siswa</option>
                                <option value="guru">guru</option>
                                <option value="guru_bk">guru_bk</option>
                                <option value="admin">admin</option>
                                <option value="guest">guest</option>
                            </select>
                            <select id="menuUi" class="form-control">
                                <option value="buttons">buttons (≤3)</option>
                                <option value="list">list (≤10)</option>
                                <option value="cta_url">cta_url</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Header Text</label>
                        <input type="text" id="menuHeader" class="form-control" maxlength="120">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Footer Text</label>
                        <input type="text" id="menuFooter" class="form-control" maxlength="120" value="Ilmuna Learn · Asisten Resmi Sekolah">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Body Text (variabel: <code>{nama}</code>, <code>{nama_anak}</code>, <code>{kelas}</code>)</label>
                    <textarea id="menuBody" class="form-control" required></textarea>
                </div>
                <div class="form-group">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <label class="form-label">Daftar Tombol / Baris List</label>
                        <button type="button" class="btn btn-sm btn-outline" id="btnAddBtnRow">
                            <iconify-icon icon="solar:add-circle-bold"></iconify-icon> Tambah Tombol
                        </button>
                    </div>
                    <div id="btnBuilderRows" style="display:grid;gap:8px;margin-top:6px;"></div>
                </div>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;">
                    <input type="checkbox" id="menuActive" checked> Menu aktif digunakan bot
                </label>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalMenu')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon><span>Batal</span>
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:disk-bold"></iconify-icon><span>Simpan Menu</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let menus = [];
    let roleFilter = 'all_roles';
    const tbody = document.getElementById('menusTbody');
    const searchInput = document.getElementById('searchMenus');
    const builderWrap = document.getElementById('btnBuilderRows');

    function addBuilderRow(item) {
        const b = item || { id: '', title: '', description: '', url: '' };
        const div = document.createElement('div');
        div.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 1.2fr auto;gap:6px;align-items:center;';
        div.innerHTML = `
            <input type="text" class="form-control font-mono btn-f-id" placeholder="ID (MENU_...)" value="${WaPanel.escape(b.id || '')}" required>
            <input type="text" class="form-control btn-f-title" placeholder="Label Tombol (≤20)" value="${WaPanel.escape(b.title || '')}" required>
            <input type="text" class="form-control btn-f-extra" placeholder="Deskripsi / URL (opsional)" value="${WaPanel.escape(b.url || b.description || '')}">
            <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()" title="Hapus"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>
        `;
        builderWrap.appendChild(div);
    }

    function renderRow(m) {
        let btns = [];
        try { btns = JSON.parse(m.buttons_json || '[]'); } catch (e) {}
        const chips = btns.map(b => `<span class="ilmuna-badge ilmuna-badge--navy" style="margin:2px;">${WaPanel.escape(b.title || b.id)}</span>`).join('');
        return `<tr id="menu-row-${m.id}">
            <td data-label="Kode"><code>${WaPanel.escape(m.menu_code)}</code><br><small class="text-muted">${WaPanel.escape(m.header_text || '')}</small></td>
            <td data-label="Role"><span class="ilmuna-badge ilmuna-badge--info">${WaPanel.escape(m.target_role)}</span></td>
            <td data-label="Tipe"><code>${WaPanel.escape(m.ui_type)}</code></td>
            <td data-label="Tombol">${chips}</td>
            <td data-label="Status">
                <button type="button" class="ilmuna-badge ilmuna-badge--${m.is_active == 1 ? 'success' : 'danger'} btn-toggle-menu" data-id="${m.id}" style="cursor:pointer;">
                    ${m.is_active == 1 ? 'Aktif' : 'Nonaktif'}
                </button>
            </td>
            <td data-label="Aksi" class="text-center" style="white-space:nowrap;">
                <button type="button" class="btn btn-sm btn-outline btn-edit-menu" data-id="${m.id}" title="Edit"><iconify-icon icon="solar:pen-new-square-bold"></iconify-icon></button>
                <button type="button" class="btn btn-sm btn-danger btn-del-menu" data-id="${m.id}" title="Hapus"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>
            </td>
        </tr>`;
    }

    function renderTable() {
        const q = (searchInput.value || '').trim().toLowerCase();
        const filtered = menus.filter(m => {
            if (roleFilter !== 'all_roles' && m.target_role !== roleFilter) return false;
            return q === '' || JSON.stringify(m).toLowerCase().includes(q);
        });
        document.getElementById('cntMenus').textContent = filtered.length;
        tbody.innerHTML = filtered.length ? filtered.map(renderRow).join('') : '<tr><td colspan="6" class="text-center text-muted" style="padding:28px;">Tidak ada menu yang cocok.</td></tr>';
        bindEvents();
    }

    function bindEvents() {
        tbody.querySelectorAll('.btn-toggle-menu').forEach(btn => {
            btn.onclick = async function () {
                const id = parseInt(this.dataset.id, 10);
                const res = await WaPanel.post('?ajax=1', { action: 'toggle_menu', id });
                if (res.success && res.data) {
                    menus = menus.map(x => x.id == id ? res.data : x);
                    const tr = document.getElementById('menu-row-' + id);
                    if (tr) { tr.outerHTML = renderRow(res.data); WaPanel.flashRow(document.getElementById('menu-row-' + id)); bindEvents(); }
                    Ilmuna.success('Status Diperbarui', res.message);
                }
            };
        });

        tbody.querySelectorAll('.btn-edit-menu').forEach(btn => {
            btn.onclick = function () {
                const m = menus.find(x => x.id == this.dataset.id);
                if (!m) return;
                document.getElementById('modalMenuTitle').textContent = 'Edit Menu: ' + m.menu_code;
                document.getElementById('menuId').value = m.id;
                document.getElementById('menuCode').value = m.menu_code;
                document.getElementById('menuRole').value = m.target_role;
                document.getElementById('menuUi').value = m.ui_type;
                document.getElementById('menuHeader').value = m.header_text || '';
                document.getElementById('menuFooter').value = m.footer_text || '';
                document.getElementById('menuBody').value = m.body_text || '';
                document.getElementById('menuActive').checked = m.is_active == 1;
                builderWrap.innerHTML = '';
                let btns = [];
                try { btns = JSON.parse(m.buttons_json || '[]'); } catch (e) {}
                btns.forEach(addBuilderRow);
                openIlmunaModal('modalMenu');
            };
        });

        tbody.querySelectorAll('.btn-del-menu').forEach(btn => {
            btn.onclick = async function () {
                const id = parseInt(this.dataset.id, 10);
                const ok = await Ilmuna.confirm('error', 'Hapus Menu Tombol?', 'Menu ini akan dihapus permanen.');
                if (!ok) return;
                const res = await WaPanel.post('?ajax=1', { action: 'delete_menu', id });
                if (res.success) {
                    menus = menus.filter(x => x.id != id);
                    WaPanel.removeRow(document.getElementById('menu-row-' + id), () => {
                        document.getElementById('cntMenus').textContent = menus.length;
                    });
                    Ilmuna.success('Dihapus', res.message);
                }
            };
        });
    }

    document.querySelectorAll('#roleFilterPills .ilmuna-pill-btn').forEach(p => {
        p.addEventListener('click', function () {
            document.querySelectorAll('#roleFilterPills .ilmuna-pill-btn').forEach(x => x.classList.remove('is-active'));
            this.classList.add('is-active');
            roleFilter = this.dataset.role;
            renderTable();
        });
    });

    searchInput.addEventListener('input', renderTable);
    document.getElementById('btnAddBtnRow').addEventListener('click', () => addBuilderRow());
    document.getElementById('btnAddMenu').addEventListener('click', () => {
        document.getElementById('modalMenuTitle').textContent = 'Buat Menu Tombol Baru';
        document.getElementById('formMenu').reset();
        document.getElementById('menuId').value = '0';
        builderWrap.innerHTML = '';
        addBuilderRow({ id: 'MENU_MAIN', title: '📋 Menu Utama' });
        openIlmunaModal('modalMenu');
    });

    document.getElementById('formMenu').addEventListener('submit', async function (e) {
        e.preventDefault();
        const ui = document.getElementById('menuUi').value;
        const btns = [];
        builderWrap.querySelectorAll(':scope > div').forEach(row => {
            const id = row.querySelector('.btn-f-id').value.trim();
            const title = row.querySelector('.btn-f-title').value.trim();
            const extra = row.querySelector('.btn-f-extra').value.trim();
            if (id && title) {
                const obj = { id, title };
                if (extra) {
                    if (ui === 'cta_url' || extra.startsWith('http')) obj.url = extra;
                    else obj.description = extra;
                }
                btns.push(obj);
            }
        });
        const res = await WaPanel.post('?ajax=1', {
            action: 'save_menu',
            id: parseInt(document.getElementById('menuId').value, 10),
            menu_code: document.getElementById('menuCode').value,
            target_role: document.getElementById('menuRole').value,
            ui_type: ui,
            header_text: document.getElementById('menuHeader').value,
            footer_text: document.getElementById('menuFooter').value,
            body_text: document.getElementById('menuBody').value,
            buttons: btns,
            is_active: document.getElementById('menuActive').checked ? 1 : 0
        });
        if (!res.success) { Ilmuna.error('Gagal', res.message); return; }
        closeIlmunaModal('modalMenu');
        const saved = res.data;
        const idx = menus.findIndex(x => x.id == saved.id);
        if (idx >= 0) menus[idx] = saved; else menus.unshift(saved);
        renderTable();
        const tr = document.getElementById('menu-row-' + saved.id);
        if (tr) tr.classList.add(idx >= 0 ? 'row-flash' : 'row-smooth-in');
        Ilmuna.success('Tersimpan', res.message);
    });

    (async () => {
        const res = await WaPanel.get('?ajax=1');
        if (res.success) { menus = res.data || []; renderTable(); }
    })();
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
