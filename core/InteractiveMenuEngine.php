<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — INTERACTIVE BUTTON & LIST MENU ENGINE (ZERO MANUAL TYPING)
 * ==============================================================================
 * Mesin state interaktif berbasis tabel `wa_sessions`, `wa_contacts_cache`,
 * dan `wa_button_menus`:
 *
 * - Deteksi role otomatis dari nomor HP di Ilmuna Learn (dengan cache 30 menit):
 *   `orang_tua` (Multi-Anak), `siswa`, `guru`, `guru_bk`, `admin`, `guest`
 * - 100% interaksi via klik tombol (`button_reply`, `list_reply`, `poll_vote`)
 * - Pemetaan otomatis opsi Poll/Teks ke `button_id` (kompatibel Cloud API & MD Bridge)
 * - Aksi langsung dari WA:
 *   • Orang Tua multi-anak: pilih anak → Nilai & Tugas, Absensi & Izin, Catatan BK, Ganti Anak
 *   • Siswa: Tugas & Proyek Aktif, Rekap Presensi, Status Perizinan, CTA Portal Learn
 *   • Guru & Guru BK: Rekap Absensi Hari Ini, Approval Perizinan 1-klik (`GURU_APPROVE_IZIN_*`), Catatan BK
 *   • Admin: Status Sistem live, Ringkasan Broadcast, Log Moderasi Grup & Anti-Call
 *   • Tamu / Belum Terdaftar: Link Hash Form Siswa & Form Guru (`public_form_links`)
 * - Fallback ramah: teks bebas apa pun tidak pernah error, langsung tampil menu tombol role
 * ==============================================================================
 */

class InteractiveMenuEngine
{
    private PDO $pdo;
    private TransportInterface $transport;
    private LearnBridgeClient $learnClient;
    private int $contactCacheTtlSec;
    private int $sessionTtlMinutes;

    public function __construct(
        PDO $pdo,
        TransportInterface $transport,
        ?LearnBridgeClient $learnClient = null
    ) {
        $this->pdo                = $pdo;
        $this->transport          = $transport;
        $this->learnClient        = $learnClient ?? new LearnBridgeClient($pdo);
        $this->contactCacheTtlSec = 1800; // 30 menit refresh berkala ke Ilmuna Learn
        $this->sessionTtlMinutes  = max(10, (int)wa_get_setting('session_ttl_minutes', '60', $pdo));
    }

    /**
     * Entrypoint utama dari WebhookProcessor untuk seluruh pesan personal (private_message)
     */
    public function handleIncomingMessage(array $event): array
    {
        $phone = wa_normalize_phone((string)($event['sender_phone'] ?? $event['chat_jid'] ?? ''));
        if ($phone === '') {
            return ['handled' => false, 'reason' => 'invalid_phone'];
        }

        $senderName = trim((string)($event['sender_name'] ?? ''));
        $contact    = $this->resolveContact($phone, $senderName);
        $session    = $this->loadSession($phone);
        $role       = (string)$contact['role'];

        // Tentukan button_id kanonis dari native button_reply, list_reply, poll_vote, atau angka cepat
        $actionId = $this->resolveActionId($event, $session);

        // 1. Aksi Universal (berlaku untuk semua role)
        if ($actionId === 'MENU_CONTACT_ADMIN') {
            return $this->handleContactAdmin($phone, $session);
        }
        if ($actionId !== null && str_starts_with($actionId, 'ACK_READ_')) {
            return $this->handleAckRead($phone, $actionId, $contact, $session);
        }
        if ($actionId === 'MENU_MAIN' || $actionId === 'MENU_HOME') {
            return $this->sendRoleMainMenu($phone, $contact, $session, false);
        }

        // 2. Aksi Konfirmasi Absensi dari Notifikasi Orang Tua (Zero Typing)
        if ($actionId !== null && str_starts_with($actionId, 'ORTU_CONFIRM_ABSEN')) {
            return $this->handleOrtuConfirmAbsen($phone, $actionId, $contact, $session);
        }
        if ($actionId !== null && str_starts_with($actionId, 'ORTU_ABSEN_REASON_')) {
            return $this->handleOrtuAbsenReasonPicked($phone, $actionId, $contact, $session);
        }

        // 3. Aksi Approval Perizinan Langsung oleh Guru / Guru BK / Admin (dari tombol notifikasi WA)
        if ($actionId !== null && preg_match('/^GURU_(APPROVE|REJECT)_IZIN_(\d+)$/', $actionId, $m)) {
            $decision = $m[1] === 'APPROVE' ? 'disetujui' : 'ditolak';
            return $this->handleGuruPerizinanAction($phone, $contact, $session, (int)$m[2], $decision);
        }

        // 4. Routing aksi spesifik per Role
        if ($actionId !== null) {
            switch ($role) {
                case 'orang_tua':
                    $handled = $this->handleOrangTuaAction($phone, $actionId, $contact, $session);
                    break;
                case 'siswa':
                    $handled = $this->handleSiswaAction($phone, $actionId, $contact, $session);
                    break;
                case 'guru':
                case 'guru_bk':
                    $handled = $this->handleGuruAction($phone, $actionId, $contact, $session);
                    break;
                case 'admin':
                    $handled = $this->handleAdminAction($phone, $actionId, $contact, $session);
                    break;
                default:
                    $handled = $this->handleGuestAction($phone, $actionId, $contact, $session);
                    break;
            }
            if ($handled !== null) {
                return $handled;
            }
        }

        // 5. Fallback Ramah (Teks bebas apa pun -> langsung kirim menu tombol interaktif sesuai Role)
        return $this->sendRoleMainMenu($phone, $contact, $session, true);
    }

    /**
     * Resolusi kontak & role otomatis (cache di `wa_contacts_cache` + refresh berkala ke Ilmuna Learn)
     */
    public function resolveContact(string $phoneE164, string $fallbackName = '', bool $forceRefresh = false): array
    {
        $cached = null;
        $stmt = $this->pdo->prepare('SELECT * FROM wa_contacts_cache WHERE phone_e164 = :phone LIMIT 1');
        $stmt->execute([':phone' => $phoneE164]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $cached = [
                'phone_e164'   => (string)$row['phone_e164'],
                'role'         => (string)$row['role'],
                'learn_ref_id' => $row['learn_ref_id'] !== null ? (int)$row['learn_ref_id'] : null,
                'display_name' => (string)($row['display_name'] ?: $fallbackName ?: 'Pengguna'),
                'extra'        => json_decode((string)($row['extra_json'] ?? '{}'), true) ?: [],
                'is_blocked'   => (int)$row['is_blocked'] === 1,
                'synced_at'    => (string)$row['synced_at'],
            ];

            $age = time() - (strtotime((string)$row['synced_at']) ?: 0);
            if (!$forceRefresh && $age < $this->contactCacheTtlSec) {
                return $cached;
            }
        }

        // Refresh ke Ilmuna Learn via HMAC-SHA256
        $resp = $this->learnClient->lookupContact($phoneE164);
        if ($resp['success'] && !empty($resp['data'])) {
            $data = $resp['data'];
            $role = (string)($data['role'] ?? 'guest');
            if (!in_array($role, ['admin', 'guru', 'guru_bk', 'orang_tua', 'siswa', 'guest'], true)) {
                $role = 'guest';
            }

            $refId = isset($data['learn_ref_id']) && is_numeric($data['learn_ref_id']) ? (int)$data['learn_ref_id'] : null;
            $name  = trim((string)($data['display_name'] ?? $data['nama'] ?? $fallbackName));
            if ($name === '') {
                $name = 'Pengguna';
            }
            $extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];
            if (isset($data['children']) && is_array($data['children'])) {
                $extra['children'] = $data['children'];
            }
            if (isset($data['kelas'])) {
                $extra['kelas'] = $data['kelas'];
            }
            if (isset($data['kelas_id'])) {
                $extra['kelas_id'] = (int)$data['kelas_id'];
            }

            $upsert = $this->pdo->prepare(
                'INSERT INTO wa_contacts_cache (phone_e164, role, learn_ref_id, display_name, extra_json, synced_at)
                 VALUES (:phone, :role, :ref_id, :name, :extra, NOW())
                 ON DUPLICATE KEY UPDATE
                    role = VALUES(role),
                    learn_ref_id = VALUES(learn_ref_id),
                    display_name = VALUES(display_name),
                    extra_json = VALUES(extra_json),
                    synced_at = NOW()'
            );
            $upsert->execute([
                ':phone'  => $phoneE164,
                ':role'   => $role,
                ':ref_id' => $refId,
                ':name'   => mb_substr($name, 0, 150, 'UTF-8'),
                ':extra'  => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return [
                'phone_e164'   => $phoneE164,
                'role'         => $role,
                'learn_ref_id' => $refId,
                'display_name' => $name,
                'extra'        => $extra,
                'is_blocked'   => $cached['is_blocked'] ?? false,
                'synced_at'    => date('Y-m-d H:i:s'),
            ];
        }

        // Jika Learn sementara tidak terjangkau, gunakan cache terakhir jika ada
        if ($cached !== null) {
            return $cached;
        }

        // Fallback default guest bila belum ada di cache dan Learn belum merespon
        return [
            'phone_e164'   => $phoneE164,
            'role'         => 'guest',
            'learn_ref_id' => null,
            'display_name' => $fallbackName !== '' ? $fallbackName : 'Tamu',
            'extra'        => [],
            'is_blocked'   => false,
            'synced_at'    => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Kirim menu utama dinamis sesuai role (dengan dukungan otomatis Multi-Anak untuk orang_tua)
     */
    private function sendRoleMainMenu(string $phone, array $contact, array $session, bool $isFallbackGreeting): array
    {
        $role = (string)$contact['role'];

        // Khusus Orang Tua: tangani Multi-Anak sebelum masuk menu aksi anak
        if ($role === 'orang_tua') {
            $children = $this->extractChildren($contact);
            $selectedChild = $this->findSelectedChild($children, $session['selected_child_id']);

            if (count($children) >= 2 && $selectedChild === null) {
                return $this->sendChildPickerList($phone, $contact, $children, $session);
            }

            if (count($children) === 1 && $selectedChild === null) {
                $selectedChild = $children[0];
                $session['selected_child_id'] = (int)$selectedChild['id'];
            }

            return $this->sendOrangTuaMainButtons($phone, $contact, $selectedChild, $children, $session);
        }

        $menuMap = [
            'siswa'   => 'MENU_SISWA_MAIN',
            'guru'    => 'MENU_GURU_MAIN',
            'guru_bk' => 'MENU_GURU_BK_MAIN',
            'admin'   => 'MENU_ADMIN_MAIN',
        ];
        $menuCode = $menuMap[$role] ?? 'MENU_GUEST_MAIN';

        $tpl = $this->getMenuTemplate($menuCode);
        $vars = [
            '{nama}'  => (string)$contact['display_name'],
            '{kelas}' => (string)($contact['extra']['kelas'] ?? 'Ilmuna Learn'),
        ];

        $body = $this->interpolate((string)$tpl['body_text'], $vars);
        if ($isFallbackGreeting && $role !== 'guest') {
            $body = "Halo *{$contact['display_name']}*! Cukup tekan tombol di bawah tanpa perlu mengetik perintah manual.\n\n" . $body;
        }

        $buttons = (array)$tpl['buttons'];
        return $this->replyWithButtons($phone, $body, $buttons, [
            'header' => $tpl['header_text'],
            'footer' => $tpl['footer_text'],
        ], $session, 'MAIN_MENU_' . strtoupper($role), null);
    }

    // ==========================================================================
    // HANDLER ROLE: ORANG TUA (MULTI-ANAK)
    // ==========================================================================

    private function handleOrangTuaAction(string $phone, string $actionId, array $contact, array $session): ?array
    {
        $children = $this->extractChildren($contact);

        // 1. Pilih anak dari List Multi-Anak
        if (preg_match('/^ORTU_PICK_CHILD_(\d+)$/', $actionId, $m)) {
            $pickedId = (int)$m[1];
            $picked = $this->findSelectedChild($children, $pickedId);
            if ($picked === null) {
                return $this->sendChildPickerList($phone, $contact, $children, $session);
            }
            $session['selected_child_id'] = $pickedId;
            return $this->sendOrangTuaMainButtons($phone, $contact, $picked, $children, $session);
        }

        // 2. Ganti anak
        if ($actionId === 'MENU_ORTU_GANTI_ANAK') {
            if (count($children) >= 2) {
                return $this->sendChildPickerList($phone, $contact, $children, $session);
            }
            $child = $children[0] ?? null;
            return $this->sendOrangTuaMainButtons($phone, $contact, $child, $children, $session);
        }

        // 3. Buka Portal Ortu (CTA URL)
        if ($actionId === 'MENU_ORTU_PORTAL') {
            $portalUrl = rtrim((string)wa_get_setting('learn_public_url', LEARN_PUBLIC_URL, $this->pdo), '/') . '/dashboard/orang-tua';
            $res = $this->transport->sendCtaUrl(
                $phone,
                "Bapak/Ibu *{$contact['display_name']}*, tekan tombol di bawah untuk membuka *Portal Orang Tua Ilmuna Learn* (grafik nilai, rapor, absensi & BK lengkap).",
                '🌐 Buka Portal Ortu',
                $portalUrl,
                ['header' => 'Portal Orang Tua', 'footer' => 'Ilmuna Learn · SIAKAD']
            );
            $this->saveSession($phone, 'ORTU_PORTAL', $session['selected_child_id'], $session['context']);
            return ['handled' => true, 'role' => 'orang_tua', 'action' => $actionId, 'transport' => $res];
        }

        // Pastikan anak sudah terpilih sebelum menarik data akademik/absensi/BK
        $selectedChild = $this->findSelectedChild($children, $session['selected_child_id']);
        if ($selectedChild === null) {
            if (count($children) >= 2) {
                return $this->sendChildPickerList($phone, $contact, $children, $session);
            }
            $selectedChild = $children[0] ?? null;
            if ($selectedChild !== null) {
                $session['selected_child_id'] = (int)$selectedChild['id'];
            }
        }

        if ($selectedChild === null) {
            return $this->replyWithButtons(
                $phone,
                'Akun Orang Tua Anda belum terhubung dengan data peserta didik di Ilmuna Learn. Silakan hubungi Admin Sekolah untuk penautan data anak.',
                [['id' => 'MENU_CONTACT_ADMIN', 'title' => '📞 Hubungi Admin']],
                ['header' => 'Data Anak Belum Tertaut'],
                $session,
                'ORTU_NO_CHILD',
                null
            );
        }

        $childId    = (int)$selectedChild['id'];
        $childName  = (string)$selectedChild['nama'];
        $childKelas = (string)($selectedChild['kelas'] ?? '-');
        $hasMulti   = count($children) >= 2;

        if ($actionId === 'MENU_ORTU_NILAI') {
            $sum = $this->learnClient->getStudentSummary($childId, 'nilai_tugas');
            $text = $this->formatNilaiTugasSummary($childName, $childKelas, $sum);
            $btns = [
                ['id' => 'MENU_ORTU_ABSENSI', 'title' => '📅 Absensi & Izin'],
                ['id' => 'MENU_ORTU_BK',      'title' => '⚠️ Catatan BK'],
                $hasMulti
                    ? ['id' => 'MENU_ORTU_GANTI_ANAK', 'title' => '🔄 Ganti Anak']
                    : ['id' => 'MENU_ORTU_PORTAL',     'title' => '🌐 Portal Ortu'],
            ];
            return $this->replyWithButtons($phone, $text, $btns, ['header' => '📊 Nilai & Tugas Ananda'], $session, 'ORTU_NILAI', $childId);
        }

        if ($actionId === 'MENU_ORTU_ABSENSI') {
            $sum = $this->learnClient->getStudentSummary($childId, 'absensi_izin');
            $text = $this->formatAbsensiSummary($childName, $childKelas, $sum);
            $btns = [
                ['id' => 'MENU_ORTU_NILAI', 'title' => '📊 Nilai & Tugas'],
                ['id' => 'MENU_ORTU_BK',    'title' => '⚠️ Catatan BK'],
                $hasMulti
                    ? ['id' => 'MENU_ORTU_GANTI_ANAK', 'title' => '🔄 Ganti Anak']
                    : ['id' => 'MENU_MAIN',            'title' => '📋 Menu Utama'],
            ];
            return $this->replyWithButtons($phone, $text, $btns, ['header' => '📅 Absensi & Perizinan'], $session, 'ORTU_ABSENSI', $childId);
        }

        if ($actionId === 'MENU_ORTU_BK') {
            $sum = $this->learnClient->getStudentSummary($childId, 'bk');
            $text = $this->formatBkSummary($childName, $childKelas, $sum);
            $btns = [
                ['id' => 'MENU_ORTU_NILAI',   'title' => '📊 Nilai & Tugas'],
                ['id' => 'MENU_ORTU_ABSENSI', 'title' => '📅 Absensi & Izin'],
                $hasMulti
                    ? ['id' => 'MENU_ORTU_GANTI_ANAK', 'title' => '🔄 Ganti Anak']
                    : ['id' => 'MENU_MAIN',            'title' => '📋 Menu Utama'],
            ];
            return $this->replyWithButtons($phone, $text, $btns, ['header' => '⚠️ Catatan Disiplin BK'], $session, 'ORTU_BK', $childId);
        }

        if ($actionId === 'MENU_ORTU_AGENDA') {
            $kelasId = isset($selectedChild['kelas_id']) ? (int)$selectedChild['kelas_id'] : null;
            $up = $this->learnClient->getUpcomingAcademic($kelasId, 'orang_tua', 7);
            $text = $this->formatUpcomingAcademic("Agenda & Tenggat Ananda {$childName} ({$childKelas})", $up);
            $btns = [
                ['id' => 'MENU_ORTU_NILAI', 'title' => '📊 Nilai & Tugas'],
                $hasMulti
                    ? ['id' => 'MENU_ORTU_GANTI_ANAK', 'title' => '🔄 Ganti Anak']
                    : ['id' => 'MENU_ORTU_PORTAL',     'title' => '🌐 Portal Ortu'],
                ['id' => 'MENU_MAIN', 'title' => '📋 Menu Utama'],
            ];
            return $this->replyWithButtons($phone, $text, $btns, ['header' => '🗓️ Agenda & Tenggat'], $session, 'ORTU_AGENDA', $childId);
        }

        if ($actionId === 'MENU_ORTU_LAINNYA') {
            $tpl = $this->getMenuTemplate('MENU_ORANG_TUA_LIST');
            $body = $this->interpolate((string)$tpl['body_text'], [
                '{nama}'      => (string)$contact['display_name'],
                '{nama_anak}' => $childName,
                '{kelas}'     => $childKelas,
            ]);
            return $this->replyWithList(
                $phone,
                $body,
                '📋 Pilih Menu',
                [['title' => 'Layanan Orang Tua', 'rows' => (array)$tpl['buttons']]],
                ['header' => $tpl['header_text'], 'footer' => $tpl['footer_text']],
                $session,
                'ORTU_LIST',
                $childId
            );
        }

        return null;
    }

    private function sendChildPickerList(string $phone, array $contact, array $children, array $session): array
    {
        $rows = [];
        foreach ($children as $child) {
            $genderIcon = strtoupper((string)($child['jk'] ?? $child['jenis_kelamin'] ?? 'L')) === 'P' ? '👧' : '👦';
            $rows[] = [
                'id'          => 'ORTU_PICK_CHILD_' . (int)$child['id'],
                'title'       => wa_truncate($genderIcon . ' ' . (string)$child['nama'], 24),
                'description' => wa_truncate('Kelas ' . (string)($child['kelas'] ?? '-') . (!empty($child['nisn']) ? ' · NISN ' . $child['nisn'] : ''), 72),
            ];
        }

        $body = "Assalamu'alaikum Bapak/Ibu *{$contact['display_name']}*.\nAnda terhubung dengan *" . count($children) . " putra/putri* di Ilmuna Learn.\n\nSilakan pilih ananda yang ingin dipantau terlebih dahulu:";

        return $this->replyWithList(
            $phone,
            $body,
            '👨‍👧‍👦 Pilih Anak',
            [['title' => 'Daftar Putra/Putri', 'rows' => $rows]],
            ['header' => 'Portal Orang Tua · Multi-Anak', 'footer' => 'Ilmuna Learn · Asisten Resmi Sekolah'],
            $session,
            'ORTU_PICK_CHILD',
            null
        );
    }

    private function sendOrangTuaMainButtons(string $phone, array $contact, ?array $selectedChild, array $children, array $session): array
    {
        $childName  = $selectedChild ? (string)$selectedChild['nama'] : '-';
        $childKelas = $selectedChild ? (string)($selectedChild['kelas'] ?? '-') : '-';
        $childId    = $selectedChild ? (int)$selectedChild['id'] : null;

        // Jika multi-anak, tampilkan List Menu agar semua 6 opsi (termasuk Ganti Anak & Portal Ortu) langsung 1-klik!
        if (count($children) >= 2) {
            $tpl = $this->getMenuTemplate('MENU_ORANG_TUA_LIST');
            $body = $this->interpolate((string)$tpl['body_text'], [
                '{nama}'      => (string)$contact['display_name'],
                '{nama_anak}' => $childName,
                '{kelas}'     => $childKelas,
            ]);
            return $this->replyWithList(
                $phone,
                $body,
                '📋 Pilih Layanan',
                [['title' => wa_truncate($childName, 24), 'rows' => (array)$tpl['buttons']]],
                ['header' => "Pantau: {$childName}", 'footer' => $tpl['footer_text']],
                $session,
                'ORTU_CHILD_MENU',
                $childId
            );
        }

        $tpl = $this->getMenuTemplate('MENU_ORANG_TUA_MAIN');
        $body = $this->interpolate((string)$tpl['body_text'], [
            '{nama}'      => (string)$contact['display_name'],
            '{nama_anak}' => $childName,
            '{kelas}'     => $childKelas,
        ]);

        return $this->replyWithButtons(
            $phone,
            $body,
            (array)$tpl['buttons'],
            ['header' => $tpl['header_text'], 'footer' => $tpl['footer_text']],
            $session,
            'ORTU_CHILD_MENU',
            $childId
        );
    }

    // ==========================================================================
    // HANDLER ROLE: SISWA
    // ==========================================================================

    private function handleSiswaAction(string $phone, string $actionId, array $contact, array $session): ?array
    {
        $siswaId = (int)($contact['learn_ref_id'] ?? 0);
        $nama    = (string)$contact['display_name'];
        $kelas   = (string)($contact['extra']['kelas'] ?? '-');
        $kelasId = isset($contact['extra']['kelas_id']) ? (int)$contact['extra']['kelas_id'] : null;

        if ($actionId === 'MENU_SISWA_TUGAS') {
            $sum = $this->learnClient->getStudentSummary($siswaId, 'nilai_tugas');
            $up  = $this->learnClient->getUpcomingAcademic($kelasId, 'siswa', 7);
            $text = $this->formatNilaiTugasSummary($nama, $kelas, $sum) . "\n\n" . $this->formatUpcomingAcademic('Tenggat & Agenda 7 Hari Ke Depan', $up);
            return $this->replyWithButtons($phone, $text, [
                ['id' => 'MENU_SISWA_PRESENSI', 'title' => '✅ Rekap Presensi'],
                ['id' => 'MENU_SISWA_IZIN',     'title' => '📩 Status Perizinan'],
                ['id' => 'CTA_OPEN_LEARN',      'title' => '🌐 Buka Learn'],
            ], ['header' => '📚 Tugas & Proyek Aktif'], $session, 'SISWA_TUGAS', null);
        }

        if ($actionId === 'MENU_SISWA_PRESENSI') {
            $sum  = $this->learnClient->getStudentSummary($siswaId, 'absensi_izin');
            $text = $this->formatAbsensiSummary($nama, $kelas, $sum);
            return $this->replyWithButtons($phone, $text, [
                ['id' => 'MENU_SISWA_TUGAS', 'title' => '📚 Tugas & Proyek'],
                ['id' => 'MENU_SISWA_IZIN',  'title' => '📩 Status Perizinan'],
                ['id' => 'MENU_MAIN',        'title' => '📋 Menu Utama'],
            ], ['header' => '✅ Rekap Presensi Siswa'], $session, 'SISWA_PRESENSI', null);
        }

        if ($actionId === 'MENU_SISWA_IZIN') {
            $sum  = $this->learnClient->getStudentSummary($siswaId, 'absensi_izin');
            $izinList = (array)($sum['data']['perizinan_terbaru'] ?? []);
            $lines = ["*📩 Status Perizinan — {$nama} ({$kelas})*"];
            if ($izinList === []) {
                $lines[] = 'Belum ada pengajuan perizinan terbaru.';
            } else {
                foreach (array_slice($izinList, 0, 5) as $iz) {
                    $tgl = (string)($iz['tanggal_izin'] ?? '-');
                    $jns = (string)($iz['jenis_izin'] ?? 'Izin');
                    $st  = (string)($iz['status_perizinan'] ?? 'Diproses');
                    $lines[] = "• {$tgl} — *{$jns}* ({$st})";
                }
            }
            return $this->replyWithButtons($phone, implode("\n", $lines), [
                ['id' => 'MENU_SISWA_TUGAS', 'title' => '📚 Tugas & Proyek'],
                ['id' => 'CTA_OPEN_LEARN',   'title' => '🌐 Ajukan di Learn'],
                ['id' => 'MENU_MAIN',        'title' => '📋 Menu Utama'],
            ], ['header' => '📩 Status Perizinan'], $session, 'SISWA_IZIN', null);
        }

        if ($actionId === 'CTA_OPEN_LEARN' || $actionId === 'MENU_SISWA_PORTAL') {
            $tpl = $this->getMenuTemplate('MENU_SISWA_CTA');
            $url = rtrim((string)wa_get_setting('learn_public_url', LEARN_PUBLIC_URL, $this->pdo), '/') . '/dashboard/siswa';
            $res = $this->transport->sendCtaUrl($phone, (string)$tpl['body_text'], '🌐 Buka Ilmuna Learn', $url, [
                'header' => $tpl['header_text'],
                'footer' => $tpl['footer_text'],
            ]);
            $this->saveSession($phone, 'SISWA_CTA', null, $session['context']);
            return ['handled' => true, 'role' => 'siswa', 'action' => $actionId, 'transport' => $res];
        }

        return null;
    }

    // ==========================================================================
    // HANDLER ROLE: GURU & GURU BK (TERMASUK APPROVAL IZIN 1-KLIK)
    // ==========================================================================

    private function handleGuruAction(string $phone, string $actionId, array $contact, array $session): ?array
    {
        if ($actionId === 'MENU_GURU_ABSENSI') {
            $ov = $this->learnClient->getTeacherOverview($phone, 'absensi_hari_ini');
            $d  = (array)($ov['data'] ?? []);
            $lines = ["*📋 Rekap Absensi Hari Ini (" . date('d/m/Y') . ")*"];
            if (!$ov['success']) {
                $lines[] = '_Gagal memuat data dari Ilmuna Learn: ' . $ov['message'] . '_';
            } else {
                $lines[] = '• Hadir : *' . (int)($d['hadir'] ?? 0) . '*';
                $lines[] = '• Sakit : *' . (int)($d['sakit'] ?? 0) . '*';
                $lines[] = '• Izin  : *' . (int)($d['izin'] ?? 0) . '*';
                $lines[] = '• Alpa  : *' . (int)($d['alpa'] ?? 0) . '*';
                foreach (array_slice((array)($d['alpa_list'] ?? []), 0, 5) as $alpa) {
                    $lines[] = "  - {$alpa['nama']} ({$alpa['kelas']})";
                }
            }
            return $this->replyWithButtons($phone, implode("\n", $lines), [
                ['id' => 'MENU_GURU_IZIN',   'title' => '📩 Approval Izin'],
                ['id' => 'MENU_GURU_AGENDA', 'title' => '📅 Agenda & Tenggat'],
                ['id' => 'MENU_MAIN',        'title' => '📋 Menu Utama'],
            ], ['header' => '📋 Rekap Presensi Guru'], $session, 'GURU_ABSENSI', null);
        }

        if ($actionId === 'MENU_GURU_IZIN') {
            $ov = $this->learnClient->getTeacherOverview($phone, 'pending_izin');
            $pending = (array)($ov['data']['pending_izin'] ?? []);

            if ($pending === []) {
                return $this->replyWithButtons(
                    $phone,
                    '✅ Tidak ada pengajuan perizinan siswa yang menunggu persetujuan Anda saat ini.',
                    [
                        ['id' => 'MENU_GURU_ABSENSI', 'title' => '📋 Rekap Absensi'],
                        ['id' => 'MENU_MAIN',         'title' => '📋 Menu Utama'],
                    ],
                    ['header' => '📩 Approval Perizinan'],
                    $session,
                    'GURU_IZIN_EMPTY',
                    null
                );
            }

            // Tampilkan izin teratas dengan 2 tombol aksi langsung Setujui / Tolak (Zero Typing!)
            $first = $pending[0];
            $izinId = (int)($first['id'] ?? 0);
            $text = "*📩 Pengajuan Perizinan #{$izinId}*\n"
                . "Siswa  : *{$first['student_name']}* ({$first['kelas']})\n"
                . "Jenis  : *{$first['jenis_izin']}* · {$first['tanggal_izin']}\n"
                . "Alasan : {$first['alasan']}\n"
                . (!empty($first['gps_link']) ? "Lokasi : {$first['gps_link']}\n" : '')
                . (count($pending) > 1 ? "\n_(Masih ada " . (count($pending) - 1) . ' pengajuan lainnya)_' : '');

            return $this->replyWithButtons($phone, $text, [
                ['id' => 'GURU_APPROVE_IZIN_' . $izinId, 'title' => '✅ Setujui Izin'],
                ['id' => 'GURU_REJECT_IZIN_' . $izinId,  'title' => '❌ Tolak Izin'],
                ['id' => 'MENU_MAIN',                    'title' => '📋 Menu Utama'],
            ], ['header' => 'Approval Perizinan Langsung'], $session, 'GURU_IZIN_REVIEW', null);
        }

        if ($actionId === 'MENU_GURU_AGENDA') {
            $up = $this->learnClient->getUpcomingAcademic(null, 'guru', 7);
            $text = $this->formatUpcomingAcademic('Agenda, Tugas, Proyek & CBT (7 Hari)', $up);
            return $this->replyWithButtons($phone, $text, [
                ['id' => 'MENU_GURU_ABSENSI', 'title' => '📋 Rekap Absensi'],
                ['id' => 'MENU_GURU_IZIN',    'title' => '📩 Approval Izin'],
                ['id' => 'MENU_MAIN',         'title' => '📋 Menu Utama'],
            ], ['header' => '📅 Agenda Akademik'], $session, 'GURU_AGENDA', null);
        }

        if ($actionId === 'MENU_BK_PELANGGARAN') {
            $recentViol = $this->pdo->query(
                'SELECT sender_name, sender_phone, violation_type, strike_count, synced_to_bk, created_at
                 FROM wa_violation_logs ORDER BY id DESC LIMIT 5'
            )->fetchAll(PDO::FETCH_ASSOC);

            $lines = ['*⚠️ Monitoring Pelanggaran BK & Group Guard*'];
            if ($recentViol === []) {
                $lines[] = 'Belum ada pelanggaran grup WA yang tercatat.';
            } else {
                foreach ($recentViol as $v) {
                    $who = $v['sender_name'] ?: $v['sender_phone'];
                    $syncBadge = (int)$v['synced_to_bk'] === 1 ? '✅ BK' : 'ℹ️ Log';
                    $lines[] = "• {$who} — *{$v['violation_type']}* (Strike #{$v['strike_count']}, {$syncBadge})";
                }
            }
            $portalUrl = rtrim((string)wa_get_setting('learn_public_url', LEARN_PUBLIC_URL, $this->pdo), '/') . '/dashboard/guru';
            $res = $this->transport->sendCtaUrl($phone, implode("\n", $lines), '🌐 Buka Modul BK', $portalUrl, [
                'header' => 'Panel Bimbingan Konseling',
                'footer' => 'Ilmuna Learn · BK 70/30 Afektif',
            ]);
            $this->saveSession($phone, 'BK_OVERVIEW', null, $session['context']);
            return ['handled' => true, 'role' => $contact['role'], 'action' => $actionId, 'transport' => $res];
        }

        return null;
    }

    private function handleGuruPerizinanAction(
        string $phone,
        array $contact,
        array $session,
        int $izinId,
        string $decision
    ): array {
        if (!in_array($contact['role'], ['guru', 'guru_bk', 'admin'], true)) {
            return $this->replyWithButtons(
                $phone,
                '⚠️ Aksi persetujuan perizinan hanya dapat dilakukan oleh nomor WhatsApp Guru / Wali Kelas terdaftar.',
                [['id' => 'MENU_MAIN', 'title' => '📋 Menu Utama']],
                ['header' => 'Akses Ditolak'],
                $session,
                'ACCESS_DENIED',
                null
            );
        }

        $res = $this->learnClient->approvePerizinan($izinId, $decision, $phone, 'Diproses langsung melalui tombol WA Bot');
        $label = $decision === 'disetujui' ? '✅ DISETUJUI' : '❌ DITOLAK';

        if ($res['success']) {
            $student = (string)($res['data']['student_name'] ?? ('Izin #' . $izinId));
            $body = "*Status Perizinan Diperbarui*\n\n"
                . "Pengajuan izin *{$student}* (#{$izinId}) telah *{$label}* oleh *{$contact['display_name']}*.\n"
                . 'Status di database Ilmuna Learn telah diperbarui otomatis & notifikasi dikirim ke siswa/orang tua.';
        } else {
            $body = "⚠️ Gagal memproses izin #{$izinId}: {$res['message']}";
        }

        return $this->replyWithButtons($phone, $body, [
            ['id' => 'MENU_GURU_IZIN',    'title' => '📩 Izin Berikutnya'],
            ['id' => 'MENU_GURU_ABSENSI', 'title' => '📋 Rekap Absensi'],
            ['id' => 'MENU_MAIN',         'title' => '📋 Menu Utama'],
        ], ['header' => 'Konfirmasi Approval Izin'], $session, 'GURU_IZIN_DONE', null);
    }

    // ==========================================================================
    // HANDLER ROLE: ADMIN
    // ==========================================================================

    private function handleAdminAction(string $phone, string $actionId, array $contact, array $session): ?array
    {
        if ($actionId === 'MENU_ADMIN_STATUS') {
            $devices = $this->pdo->query('SELECT name, driver, status FROM wa_devices')->fetchAll(PDO::FETCH_ASSOC);
            $qPending = (int)$this->pdo->query("SELECT COUNT(*) FROM wa_broadcast_queue WHERE status = 'pending'")->fetchColumn();
            $callsToday = (int)$this->pdo->query('SELECT COUNT(*) FROM wa_call_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn();
            $violToday  = (int)$this->pdo->query('SELECT COUNT(*) FROM wa_violation_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn();

            $lines = ['*📊 Status Live Ilmuna WA Bot*'];
            foreach ($devices as $dev) {
                $icon = $dev['status'] === 'connected' ? '🟢' : ($dev['status'] === 'qr_pending' ? '🟡' : '🔴');
                $lines[] = "{$icon} {$dev['name']} (`{$dev['driver']}`): *{$dev['status']}*";
            }
            $lines[] = '';
            $lines[] = "• Antrean Broadcast Pending : *{$qPending}*";
            $lines[] = "• Panggilan Ditolak Hari Ini : *{$callsToday}*";
            $lines[] = "• Pelanggaran Grup Hari Ini  : *{$violToday}*";

            return $this->replyWithButtons($phone, implode("\n", $lines), [
                ['id' => 'MENU_ADMIN_BROADCAST', 'title' => '📢 Status Broadcast'],
                ['id' => 'MENU_ADMIN_GUARD',     'title' => '🛡️ Log Moderasi'],
                ['id' => 'MENU_MAIN',            'title' => '📋 Menu Utama'],
            ], ['header' => '📊 Monitoring Sistem'], $session, 'ADMIN_STATUS', null);
        }

        if ($actionId === 'MENU_ADMIN_BROADCAST') {
            $rows = $this->pdo->query(
                'SELECT title, source_module, status, sent_count, total_targets
                 FROM wa_broadcast_campaigns ORDER BY id DESC LIMIT 5'
            )->fetchAll(PDO::FETCH_ASSOC);

            $lines = ['*📢 5 Kampanye Broadcast Terbaru*'];
            if ($rows === []) {
                $lines[] = 'Belum ada kampanye broadcast.';
            } else {
                foreach ($rows as $r) {
                    $lines[] = "• *{$r['title']}* [{$r['source_module']}] — {$r['status']} ({$r['sent_count']}/{$r['total_targets']})";
                }
            }
            return $this->replyWithButtons($phone, implode("\n", $lines), [
                ['id' => 'MENU_ADMIN_STATUS', 'title' => '📊 Status Sistem'],
                ['id' => 'MENU_ADMIN_GUARD',  'title' => '🛡️ Log Moderasi'],
                ['id' => 'MENU_MAIN',         'title' => '📋 Menu Utama'],
            ], ['header' => '📢 Status Broadcast'], $session, 'ADMIN_BROADCAST', null);
        }

        if ($actionId === 'MENU_ADMIN_GUARD') {
            $rows = $this->pdo->query(
                'SELECT sender_phone, violation_type, strike_count, action_taken, created_at
                 FROM wa_violation_logs ORDER BY id DESC LIMIT 5'
            )->fetchAll(PDO::FETCH_ASSOC);

            $lines = ['*🛡️ 5 Log Moderasi Grup Terbaru*'];
            if ($rows === []) {
                $lines[] = 'Aman — belum ada pelanggaran grup tercatat.';
            } else {
                foreach ($rows as $r) {
                    $lines[] = "• {$r['sender_phone']} — *{$r['violation_type']}* (Strike #{$r['strike_count']}, {$r['action_taken']})";
                }
            }
            return $this->replyWithButtons($phone, implode("\n", $lines), [
                ['id' => 'MENU_ADMIN_STATUS',    'title' => '📊 Status Sistem'],
                ['id' => 'MENU_ADMIN_BROADCAST', 'title' => '📢 Status Broadcast'],
                ['id' => 'MENU_MAIN',            'title' => '📋 Menu Utama'],
            ], ['header' => '🛡️ Log Group Guard'], $session, 'ADMIN_GUARD', null);
        }

        return null;
    }

    // ==========================================================================
    // HANDLER ROLE: GUEST (TAMU / CALON SISWA & GURU BARU)
    // ==========================================================================

    private function handleGuestAction(string $phone, string $actionId, array $contact, array $session): ?array
    {
        if ($actionId === 'MENU_GUEST_FORM_SISWA' || $actionId === 'MENU_GUEST_FORM_GURU') {
            $forms = $this->learnClient->getPublicForms();
            $basePublic = rtrim((string)wa_get_setting('learn_public_url', LEARN_PUBLIC_URL, $this->pdo), '/');

            $isSiswa = $actionId === 'MENU_GUEST_FORM_SISWA';
            $url = $isSiswa
                ? (string)($forms['data']['form_siswa_url'] ?? ($basePublic . '/form-siswa'))
                : (string)($forms['data']['form_guru_url'] ?? ($basePublic . '/form-guru'));

            $title = $isSiswa ? 'Formulir Pendataan Siswa & Orang Tua' : 'Formulir Pendataan Guru';
            $btn   = $isSiswa ? '📝 Buka Form Siswa' : '👨‍🏫 Buka Form Guru';
            $body  = "Silakan tekan tombol di bawah ini untuk mengisi *{$title}* resmi Ilmuna Learn (tautan hash aktif terverifikasi).\n\nSetelah data diverifikasi sekolah, nomor WhatsApp Anda akan otomatis terhubung ke menu interaktif sesuai peran.";

            $res = $this->transport->sendCtaUrl($phone, $body, $btn, $url, [
                'header' => $title,
                'footer' => 'Ilmuna Learn · Pendaftaran Resmi',
            ]);
            $this->saveSession($phone, $actionId, null, $session['context']);
            return ['handled' => true, 'role' => 'guest', 'action' => $actionId, 'transport' => $res];
        }

        return null;
    }

    // ==========================================================================
    // HANDLER UNIVERSAL & KONFIRMASI NOTIFIKASI
    // ==========================================================================

    private function handleContactAdmin(string $phone, array $session): array
    {
        $adminPhone = wa_normalize_phone((string)wa_get_setting('school_admin_phone', SCHOOL_ADMIN_PHONE, $this->pdo));
        $schoolName = (string)wa_get_setting('school_name', SCHOOL_NAME, $this->pdo);
        $waUrl = 'https://wa.me/' . $adminPhone;

        $body = "*📞 Kontak Admin {$schoolName}*\n\n"
            . "Jika membutuhkan bantuan penautan nomor WhatsApp, akun Ilmuna Learn, atau informasi sekolah, silakan hubungi Admin resmi kami melalui tombol di bawah.\n"
            . "Nomor Admin: +{$adminPhone}";

        $res = $this->transport->sendCtaUrl($phone, $body, '💬 Chat Admin Sekolah', $waUrl, [
            'header' => 'Layanan Bantuan Sekolah',
            'footer' => 'Ilmuna Learn · Layanan Terpadu',
        ]);
        $this->saveSession($phone, 'CONTACT_ADMIN', $session['selected_child_id'], $session['context']);
        return ['handled' => true, 'action' => 'MENU_CONTACT_ADMIN', 'transport' => $res];
    }

    private function handleAckRead(string $phone, string $actionId, array $contact, array $session): array
    {
        return $this->replyWithButtons(
            $phone,
            "✅ Terima kasih *{$contact['display_name']}*, informasi akademik telah ditandai sudah dibaca.",
            [['id' => 'MENU_MAIN', 'title' => '📋 Buka Menu Utama']],
            ['header' => 'Konfirmasi Dibaca'],
            $session,
            'ACK_DONE',
            $session['selected_child_id']
        );
    }

    private function handleOrtuConfirmAbsen(string $phone, string $actionId, array $contact, array $session): array
    {
        return $this->replyWithButtons(
            $phone,
            "Terima kasih Bapak/Ibu *{$contact['display_name']}*. Silakan pilih keterangan ketidakhadiran ananda melalui tombol di bawah ini:",
            [
                ['id' => 'ORTU_ABSEN_REASON_SAKIT', 'title' => '🤒 Sakit'],
                ['id' => 'ORTU_ABSEN_REASON_IZIN',  'title' => '📝 Izin Keluarga'],
                ['id' => 'MENU_ORTU_PORTAL',        'title' => '🌐 Unggah Bukti'],
            ],
            ['header' => '📩 Konfirmasi Alasan Absensi'],
            $session,
            'ORTU_ABSEN_CONFIRM',
            $session['selected_child_id']
        );
    }

    private function handleOrtuAbsenReasonPicked(string $phone, string $actionId, array $contact, array $session): array
    {
        $reason = str_ends_with($actionId, 'SAKIT') ? 'Sakit' : 'Izin Keluarga';
        return $this->replyWithButtons(
            $phone,
            "✅ Konfirmasi *{$reason}* telah kami catat.\nUntuk kelengkapan administrasi (surat dokter / bukti foto & lokasi GPS), silakan tekan *🌐 Portal Ortu* bila diperlukan.",
            [
                ['id' => 'MENU_ORTU_PORTAL', 'title' => '🌐 Portal Ortu'],
                ['id' => 'MENU_MAIN',        'title' => '📋 Menu Utama'],
            ],
            ['header' => 'Konfirmasi Diterima'],
            $session,
            'ORTU_ABSEN_CONFIRMED',
            $session['selected_child_id']
        );
    }

    // ==========================================================================
    // HELPER FORMATTER, SESSION STATE & POLL/BUTTON MAP RESOLVER
    // ==========================================================================

    private function resolveActionId(array $event, array $session): ?string
    {
        $rawButtonId = trim((string)($event['button_id'] ?? ''));
        $text        = trim((string)($event['text'] ?? ''));
        $msgType     = (string)($event['message_type'] ?? 'text');

        // 1. Native button_reply / list_reply (Cloud API & MD Bridge)
        if ($rawButtonId !== '' && in_array($msgType, ['button_reply', 'list_reply'], true)) {
            return $rawButtonId;
        }

        // 2. Poll Vote atau pilihan angka cepat -> cocokkan ke last_button_map di wa_sessions
        $lastMap = is_array($session['context']['last_button_map'] ?? null)
            ? $session['context']['last_button_map']
            : [];

        if ($lastMap !== []) {
            $lookupText = $rawButtonId !== '' ? $rawButtonId : $text;
            // Cocokkan judul persis (case-insensitive)
            foreach ($lastMap as $idx => $entry) {
                $title = trim((string)($entry['title'] ?? ''));
                $id    = trim((string)($entry['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                if ($lookupText !== '' && mb_strtolower($lookupText, 'UTF-8') === mb_strtolower($title, 'UTF-8')) {
                    return $id;
                }
                if ($lookupText !== '' && $lookupText === $id) {
                    return $id;
                }
                // Dukungan angka urut ("1", "2", "3") untuk fallback teks di perangkat WA sangat lama
                if (ctype_digit($text) && (int)$text === ((int)$idx + 1)) {
                    return $id;
                }
            }
        }

        return null;
    }

    private function replyWithButtons(
        string $phone,
        string $body,
        array $buttons,
        array $options,
        array $session,
        string $nextState,
        ?int $selectedChildId
    ): array {
        $map = [];
        foreach (array_values($buttons) as $b) {
            if (!empty($b['id']) && !empty($b['title'])) {
                $map[] = ['id' => (string)$b['id'], 'title' => (string)$b['title']];
            }
        }

        $context = $session['context'];
        $context['last_button_map'] = $map;
        $this->saveSession($phone, $nextState, $selectedChildId, $context);

        $res = $this->transport->sendButtons($phone, $body, $buttons, $options);
        return [
            'handled'   => true,
            'state'     => $nextState,
            'ui_type'   => 'buttons',
            'transport' => $res,
        ];
    }

    private function replyWithList(
        string $phone,
        string $body,
        string $buttonLabel,
        array $sections,
        array $options,
        array $session,
        string $nextState,
        ?int $selectedChildId
    ): array {
        $map = [];
        foreach ($sections as $sec) {
            foreach ((array)($sec['rows'] ?? []) as $row) {
                if (!empty($row['id']) && !empty($row['title'])) {
                    $map[] = ['id' => (string)$row['id'], 'title' => (string)$row['title']];
                }
            }
        }

        $context = $session['context'];
        $context['last_button_map'] = $map;
        $this->saveSession($phone, $nextState, $selectedChildId, $context);

        $res = $this->transport->sendListMenu($phone, $body, $buttonLabel, $sections, $options);
        return [
            'handled'   => true,
            'state'     => $nextState,
            'ui_type'   => 'list',
            'transport' => $res,
        ];
    }

    private function loadSession(string $phoneE164): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT current_state, selected_child_id, context_json, expires_at
             FROM wa_sessions WHERE phone_e164 = :phone LIMIT 1'
        );
        $stmt->execute([':phone' => $phoneE164]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (strtotime((string)$row['expires_at']) ?: 0) < time()) {
            return [
                'current_state'     => 'MAIN_MENU',
                'selected_child_id' => null,
                'context'           => [],
            ];
        }

        return [
            'current_state'     => (string)$row['current_state'],
            'selected_child_id' => $row['selected_child_id'] !== null ? (int)$row['selected_child_id'] : null,
            'context'           => json_decode((string)($row['context_json'] ?? '{}'), true) ?: [],
        ];
    }

    private function saveSession(string $phoneE164, string $state, ?int $selectedChildId, array $context): void
    {
        $expiresAt = date('Y-m-d H:i:s', time() + ($this->sessionTtlMinutes * 60));
        $stmt = $this->pdo->prepare(
            'INSERT INTO wa_sessions (phone_e164, current_state, selected_child_id, context_json, expires_at)
             VALUES (:phone, :state, :child_id, :ctx, :exp)
             ON DUPLICATE KEY UPDATE
                current_state = VALUES(current_state),
                selected_child_id = VALUES(selected_child_id),
                context_json = VALUES(context_json),
                expires_at = VALUES(expires_at)'
        );
        $stmt->execute([
            ':phone'    => $phoneE164,
            ':state'    => mb_substr($state, 0, 80, 'UTF-8'),
            ':child_id' => $selectedChildId,
            ':ctx'      => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':exp'      => $expiresAt,
        ]);
    }

    private function getMenuTemplate(string $menuCode): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM wa_button_menus WHERE menu_code = :code AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':code' => $menuCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'header_text' => $row['header_text'],
                'body_text'   => (string)$row['body_text'],
                'footer_text' => (string)($row['footer_text'] ?? 'Ilmuna Learn · Asisten Resmi Sekolah'),
                'buttons'     => json_decode((string)$row['buttons_json'], true) ?: [],
            ];
        }

        return [
            'header_text' => 'Ilmuna Learn',
            'body_text'   => 'Silakan pilih layanan melalui tombol di bawah ini:',
            'footer_text' => 'Ilmuna Learn · Asisten Resmi Sekolah',
            'buttons'     => [['id' => 'MENU_CONTACT_ADMIN', 'title' => '📞 Hubungi Admin']],
        ];
    }

    private function extractChildren(array $contact): array
    {
        $raw = (array)($contact['extra']['children'] ?? []);
        $children = [];
        foreach ($raw as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $children[] = [
                'id'       => (int)$item['id'],
                'nama'     => (string)($item['nama'] ?? $item['nama_lengkap'] ?? 'Siswa'),
                'kelas'    => (string)($item['kelas'] ?? $item['nama_kelas'] ?? '-'),
                'kelas_id' => isset($item['kelas_id']) ? (int)$item['kelas_id'] : null,
                'nisn'     => (string)($item['nisn'] ?? ''),
                'jk'       => (string)($item['jk'] ?? $item['jenis_kelamin'] ?? 'L'),
            ];
        }
        return $children;
    }

    private function findSelectedChild(array $children, ?int $childId): ?array
    {
        if ($childId === null || $childId <= 0) {
            return null;
        }
        foreach ($children as $c) {
            if ((int)$c['id'] === $childId) {
                return $c;
            }
        }
        return null;
    }

    private function interpolate(string $template, array $vars): string
    {
        return str_replace(array_keys($vars), array_values($vars), $template);
    }

    private function formatNilaiTugasSummary(string $nama, string $kelas, array $sum): string
    {
        $lines = ["*📊 Ringkasan Nilai & Tugas — {$nama} ({$kelas})*"];
        if (!$sum['success']) {
            $lines[] = '_Data belum tersedia sementara: ' . $sum['message'] . '_';
            return implode("\n", $lines);
        }

        $d = (array)$sum['data'];
        $avg = $d['rata_rata_nilai'] ?? '-';
        $lines[] = "• Rata-rata Nilai : *{$avg}*";
        $lines[] = '• Tugas Aktif     : *' . count((array)($d['tugas_aktif'] ?? [])) . '*';
        $lines[] = '• Proyek P5/PPRA  : *' . count((array)($d['proyek_aktif'] ?? [])) . '*';

        $tugas = array_slice((array)($d['tugas_aktif'] ?? []), 0, 4);
        if ($tugas !== []) {
            $lines[] = '';
            $lines[] = '*Daftar Tugas Aktif:*';
            foreach ($tugas as $t) {
                $status = !empty($t['sudah_kumpul']) ? '✅' : '⏳';
                $lines[] = "{$status} {$t['judul']} (Tenggat: " . ($t['tenggat'] ?? '-') . ')';
            }
        }
        return implode("\n", $lines);
    }

    private function formatAbsensiSummary(string $nama, string $kelas, array $sum): string
    {
        $lines = ["*📅 Rekap Absensi — {$nama} ({$kelas})*"];
        if (!$sum['success']) {
            $lines[] = '_Data belum tersedia sementara: ' . $sum['message'] . '_';
            return implode("\n", $lines);
        }
        $a = (array)($sum['data']['absensi'] ?? $sum['data']);
        $lines[] = '• Hadir : *' . (int)($a['hadir'] ?? 0) . '*';
        $lines[] = '• Sakit : *' . (int)($a['sakit'] ?? 0) . '*';
        $lines[] = '• Izin  : *' . (int)($a['izin'] ?? 0) . '*';
        $lines[] = '• Alpa  : *' . (int)($a['alpa'] ?? 0) . '*';
        if (isset($sum['data']['mutabaah_persen'])) {
            $lines[] = "• Mutaba'ah Yaumiyah : *{$sum['data']['mutabaah_persen']}%*";
        }
        return implode("\n", $lines);
    }

    private function formatBkSummary(string $nama, string $kelas, array $sum): string
    {
        $lines = ["*⚠️ Catatan Bimbingan Konseling — {$nama} ({$kelas})*"];
        if (!$sum['success']) {
            $lines[] = '_Data belum tersedia sementara: ' . $sum['message'] . '_';
            return implode("\n", $lines);
        }
        $bk = (array)($sum['data']['bk'] ?? $sum['data']);
        $poin = (int)($bk['total_poin'] ?? 0);
        $indeks = (int)($bk['indeks_disiplin'] ?? max(0, 100 - $poin));
        $lines[] = "• Total Poin Pelanggaran : *{$poin} poin*";
        $lines[] = "• Indeks Disiplin (BK)   : *{$indeks}/100*";

        $riwayat = array_slice((array)($bk['riwayat'] ?? []), 0, 4);
        if ($riwayat === []) {
            $lines[] = '✅ Alhamdulillah, tidak ada catatan pelanggaran.';
        } else {
            $lines[] = '';
            $lines[] = '*Catatan Terbaru:*';
            foreach ($riwayat as $r) {
                $lines[] = "• {$r['tanggal']} — {$r['pelanggaran']} (-{$r['poin']} poin)";
            }
        }
        return implode("\n", $lines);
    }

    private function formatUpcomingAcademic(string $title, array $up): string
    {
        $lines = ["*🗓️ {$title}*"];
        if (!$up['success']) {
            $lines[] = '_Belum dapat memuat jadwal dari Ilmuna Learn._';
            return implode("\n", $lines);
        }
        $items = array_slice((array)($up['data']['items'] ?? []), 0, 6);
        if ($items === []) {
            $lines[] = 'Tidak ada agenda/tenggat mendesak dalam 7 hari ke depan.';
        } else {
            foreach ($items as $it) {
                $mod = strtoupper((string)($it['module'] ?? 'AGENDA'));
                $lines[] = "• [{$mod}] *{$it['title']}* — {$it['date_label']}";
            }
        }
        return implode("\n", $lines);
    }
}
