<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — PUSH NOTIFICATION HANDLER (LEARN -> WA BOT)
 * ==============================================================================
 * Memproses payload event real-time dari Ilmuna Learn (`POST /api/v1/notify.php`)
 * sesuai kontrak API_INTEGRATION.md §2:
 * - absensi_alert      (Alpa / Terlambat / Sakit / Izin -> Orang Tua)
 * - pelanggaran_bk     (Catatan Pelanggaran BK -> Orang Tua)
 * - perizinan_baru     (GPS + Bukti Foto + tombol Setujui/Tolak -> Guru)
 * - perizinan_status   (Hasil Approval Guru -> Siswa & Orang Tua)
 * - academic_broadcast (Tugas, Proyek, Agenda, Asesmen, LKPD, Materi, Mutaba'ah)
 * ==============================================================================
 */

class NotifyHandler
{
    private PDO $pdo;
    private BroadcastEngine $broadcastEngine;

    public function __construct(PDO $pdo, BroadcastEngine $broadcastEngine)
    {
        $this->pdo             = $pdo;
        $this->broadcastEngine = $broadcastEngine;
    }

    /**
     * Proses payload event push dari Ilmuna Learn
     */
    public function handleEvent(array $data, bool $sendImmediately = true): array
    {
        $event = strtolower(trim((string)($data['event'] ?? '')));
        if ($event === '') {
            return ['success' => false, 'http_status' => 400, 'message' => 'Field "event" wajib diisi.', 'data' => []];
        }

        $result = match ($event) {
            'absensi_alert'      => $this->handleAbsensiAlert($data, $sendImmediately),
            'pelanggaran_bk'     => $this->handlePelanggaranBk($data, $sendImmediately),
            'perizinan_baru'     => $this->handlePerizinanBaru($data, $sendImmediately),
            'perizinan_status'   => $this->handlePerizinanStatus($data, $sendImmediately),
            'academic_broadcast' => $this->handleAcademicBroadcast($data),
            default              => [
                'success'     => false,
                'http_status' => 400,
                'message'     => "Event '{$event}' tidak dikenali.",
                'data'        => [],
            ],
        };

        wa_log_sync(
            $this->pdo,
            'learn_to_wa',
            $event,
            (int)($result['http_status'] ?? 200),
            mb_substr((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 500, 'UTF-8'),
            mb_substr((string)($result['message'] ?? 'OK'), 0, 500, 'UTF-8')
        );

        return $result;
    }

    private function handleAbsensiAlert(array $data, bool $sendNow): array
    {
        $recipients  = (array)($data['recipients'] ?? []);
        $studentName = trim((string)($data['student_name'] ?? 'Siswa'));
        $kelas       = trim((string)($data['kelas'] ?? '-'));
        $status      = trim((string)($data['status'] ?? 'Alpa'));
        $tanggal     = trim((string)($data['tanggal'] ?? date('Y-m-d')));
        $portalUrl   = trim((string)($data['portal_url'] ?? (LEARN_PUBLIC_URL . '/dashboard/orang-tua')));

        if ($recipients === []) {
            return ['success' => false, 'http_status' => 422, 'message' => 'Daftar recipients kosong.', 'data' => []];
        }

        $body = "Assalamu'alaikum Bapak/Ibu Wali Murid.\n\n"
            . "Menginformasikan status kehadiran ananda hari ini:\n"
            . "• Nama Siswa : *{$studentName}*\n"
            . "• Kelas      : *{$kelas}*\n"
            . "• Tanggal    : *{$tanggal}*\n"
            . "• Status     : *⚠️ {$status}*\n\n"
            . "🌐 Portal Ortu: {$portalUrl}\n"
            . 'Silakan konfirmasi alasan melalui tombol di bawah ini.';

        $buttons = [
            ['id' => 'ORTU_CONFIRM_ABSEN_' . preg_replace('/\D+/', '', $tanggal), 'title' => '📩 Konfirmasi Alasan'],
            ['id' => 'MENU_ORTU_PORTAL', 'title' => '🌐 Buka Portal Ortu'],
        ];

        $this->primeRecipientsButtonMap($recipients, $buttons, 'NOTIF_ABSENSI');

        $res = $this->broadcastEngine->enqueuePriorityNotification(
            $recipients,
            'buttons',
            [
                'header'  => '📅 Notifikasi Kehadiran Siswa',
                'body'    => $body,
                'footer'  => 'Ilmuna Learn · Presensi Real-Time',
                'buttons' => $buttons,
            ],
            $sendNow
        );

        return [
            'success'     => true,
            'http_status' => 200,
            'message'     => "Notifikasi absensi ({$status}) diproses untuk {$res['queued']} penerima.",
            'data'        => $res,
        ];
    }

    private function handlePelanggaranBk(array $data, bool $sendNow): array
    {
        $recipients   = (array)($data['recipients'] ?? []);
        $studentName  = trim((string)($data['student_name'] ?? 'Siswa'));
        $kelas        = trim((string)($data['kelas'] ?? '-'));
        $jenis        = trim((string)($data['jenis_pelanggaran'] ?? 'Pelanggaran Tata Tertib'));
        $poin         = (int)($data['poin'] ?? 0);
        $indeksSisa   = (int)($data['indeks_disiplin_sisa'] ?? max(0, 100 - $poin));

        if ($recipients === []) {
            return ['success' => false, 'http_status' => 422, 'message' => 'Daftar recipients kosong.', 'data' => []];
        }

        $body = "Assalamu'alaikum Bapak/Ibu Wali Murid.\n\n"
            . "Berikut pemberitahuan *Catatan Bimbingan Konseling (BK)* ananda:\n"
            . "• Nama Siswa        : *{$studentName}* ({$kelas})\n"
            . "• Catatan           : *{$jenis}*\n"
            . "• Poin Pelanggaran  : *-{$poin} poin*\n"
            . "• Sisa Indeks BK    : *{$indeksSisa}/100*\n\n"
            . 'Mohon sinergi pembinaan karakter bersama di rumah.';

        $buttons = [
            ['id' => 'MENU_ORTU_BK',     'title' => '⚠️ Detail Catatan BK'],
            ['id' => 'MENU_ORTU_PORTAL', 'title' => '🌐 Buka Portal Ortu'],
        ];

        $this->primeRecipientsButtonMap($recipients, $buttons, 'NOTIF_BK');

        $res = $this->broadcastEngine->enqueuePriorityNotification(
            $recipients,
            'buttons',
            [
                'header'  => '⚠️ Pemberitahuan Catatan BK',
                'body'    => $body,
                'footer'  => 'Ilmuna Learn · Bimbingan Konseling',
                'buttons' => $buttons,
            ],
            $sendNow
        );

        return [
            'success'     => true,
            'http_status' => 200,
            'message'     => "Notifikasi pelanggaran BK diproses untuk {$res['queued']} penerima.",
            'data'        => $res,
        ];
    }

    private function handlePerizinanBaru(array $data, bool $sendNow): array
    {
        $recipients  = (array)($data['recipients'] ?? []);
        $izinId      = (int)($data['izin_id'] ?? 0);
        $studentName = trim((string)($data['student_name'] ?? 'Siswa'));
        $kelas       = trim((string)($data['kelas'] ?? '-'));
        $jenisIzin   = trim((string)($data['jenis_izin'] ?? 'Izin'));
        $alasan      = trim((string)($data['alasan'] ?? '-'));
        $gpsLink     = trim((string)($data['gps_link'] ?? ''));
        $buktiUrl    = trim((string)($data['bukti_url'] ?? ''));

        if ($recipients === [] || $izinId <= 0) {
            return ['success' => false, 'http_status' => 422, 'message' => 'izin_id atau recipients tidak valid.', 'data' => []];
        }

        $body = "*📩 PENGAJUAN PERIZINAN SISWA (#{$izinId})*\n\n"
            . "• Siswa  : *{$studentName}* ({$kelas})\n"
            . "• Jenis  : *{$jenisIzin}*\n"
            . "• Alasan : {$alasan}\n"
            . ($gpsLink !== '' ? "• Lokasi : {$gpsLink}\n" : '')
            . "\nSilakan setujui atau tolak langsung melalui tombol di bawah ini:";

        $buttons = [
            ['id' => 'GURU_APPROVE_IZIN_' . $izinId, 'title' => '✅ Setujui Izin'],
            ['id' => 'GURU_REJECT_IZIN_' . $izinId,  'title' => '❌ Tolak Izin'],
        ];

        $this->primeRecipientsButtonMap($recipients, $buttons, 'GURU_IZIN_NOTIF');

        $payloadType = $buktiUrl !== '' ? 'media' : 'buttons';
        $payload = $buktiUrl !== ''
            ? [
                'media_type'   => 'image',
                'media_url'    => $buktiUrl,
                'caption'      => $body,
                'buttons_body' => "Aksi cepat untuk pengajuan izin *{$studentName}* (#{$izinId}):",
                'buttons'      => $buttons,
                'header'       => 'Approval Perizinan Siswa',
                'footer'       => 'Ilmuna Learn · Approval 1-Klik',
            ]
            : [
                'header'  => 'Approval Perizinan Siswa',
                'body'    => $body,
                'footer'  => 'Ilmuna Learn · Approval 1-Klik',
                'buttons' => $buttons,
            ];

        $res = $this->broadcastEngine->enqueuePriorityNotification($recipients, $payloadType, $payload, $sendNow);

        return [
            'success'     => true,
            'http_status' => 200,
            'message'     => "Notifikasi pengajuan izin #{$izinId} diproses untuk {$res['queued']} guru.",
            'data'        => $res,
        ];
    }

    private function handlePerizinanStatus(array $data, bool $sendNow): array
    {
        $recipients  = (array)($data['recipients'] ?? []);
        $studentName = trim((string)($data['student_name'] ?? 'Siswa'));
        $status      = trim((string)($data['status'] ?? 'Disetujui'));
        $guruName    = trim((string)($data['guru_name'] ?? 'Guru / Wali Kelas'));
        $tanggal     = trim((string)($data['tanggal'] ?? date('Y-m-d')));

        if ($recipients === []) {
            return ['success' => false, 'http_status' => 422, 'message' => 'Daftar recipients kosong.', 'data' => []];
        }

        $icon = stripos($status, 'setuju') !== false ? '✅' : '❌';
        $body = "*{$icon} PEMBARUAN STATUS PERIZINAN*\n\n"
            . "Pengajuan izin ananda *{$studentName}* tanggal *{$tanggal}* telah *{$status}* oleh *{$guruName}*.";

        $buttons = [['id' => 'MENU_MAIN', 'title' => '📋 Buka Menu Utama']];
        $this->primeRecipientsButtonMap($recipients, $buttons, 'NOTIF_IZIN_STATUS');

        $res = $this->broadcastEngine->enqueuePriorityNotification(
            $recipients,
            'buttons',
            ['header' => 'Status Perizinan', 'body' => $body, 'buttons' => $buttons],
            $sendNow
        );

        return [
            'success'     => true,
            'http_status' => 200,
            'message'     => "Konfirmasi status izin ({$status}) dikirim ke {$res['queued']} penerima.",
            'data'        => $res,
        ];
    }

    private function handleAcademicBroadcast(array $data): array
    {
        $res = $this->broadcastEngine->createAcademicBroadcast($data);
        return [
            'success'     => (bool)($res['success'] ?? false),
            'http_status' => !empty($res['success']) ? 200 : 422,
            'message'     => !empty($res['duplicate_skipped'])
                ? 'Auto-broadcast akademik sudah terjadwal sebelumnya (idempotent skip).'
                : ('Auto-broadcast akademik dijadwalkan untuk ' . (int)($res['total_targets'] ?? 0) . ' penerima.'),
            'data'        => $res,
        ];
    }

    /**
     * Simpan peta tombol ke wa_sessions penerima notifikasi agar klik tombol/Poll MD Bridge
     * langsung terpetakan ke action ID yang tepat tanpa ketik manual
     */
    private function primeRecipientsButtonMap(array $recipients, array $buttons, string $state): void
    {
        $map = [];
        foreach ($buttons as $b) {
            if (!empty($b['id']) && !empty($b['title'])) {
                $map[] = ['id' => (string)$b['id'], 'title' => (string)$b['title']];
            }
        }
        $ctx = json_encode(['last_button_map' => $map], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $exp = date('Y-m-d H:i:s', time() + 86400);

        $stmt = $this->pdo->prepare(
            'INSERT INTO wa_sessions (phone_e164, current_state, context_json, expires_at)
             VALUES (:phone, :st, :ctx, :exp)
             ON DUPLICATE KEY UPDATE current_state = VALUES(current_state), context_json = VALUES(context_json), expires_at = VALUES(expires_at)'
        );
        foreach ($recipients as $r) {
            $phone = wa_normalize_phone(is_array($r) ? (string)($r['phone'] ?? $r['jid'] ?? '') : (string)$r);
            if ($phone !== '') {
                $stmt->execute([':phone' => $phone, ':st' => $state, ':ctx' => $ctx, ':exp' => $exp]);
            }
        }
    }
}
