<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — BROADCAST ENGINE, AUTO-BROADCAST AKADEMIK & QUEUE PROCESSOR
 * ==============================================================================
 * Mendukung:
 * - Broadcast manual & terjadwal (all_ortu, ortu_kelas, all_siswa, siswa_kelas,
 *   all_guru, groups, custom)
 * - Auto-Broadcast sumber akademik Ilmuna Learn:
 *   Tugas, Proyek P5/PPRA, Agenda/Kaldik, Asesmen CBT, LKPD, Materi, Mutaba'ah,
 *   & Link Formulir Publik Ter-Hash (`public_form_links`)
 * - Penjadwalan pengingat tenggat otomatis H-1 & H-0
 * - Personalisasi variabel ({nama}, {kelas}, {nama_anak}, {judul}, {tenggat},
 *   {tanggal}, {link_learn}) + lampiran media + tombol CTA / Quick Reply
 * - Antrean anti-banned (jeda acak 3–7 detik, batch size, atomic claim, retry 3x)
 * ==============================================================================
 */

class BroadcastEngine
{
    private PDO $pdo;
    private TransportInterface $transport;
    private LearnBridgeClient $learnClient;

    public function __construct(
        PDO $pdo,
        TransportInterface $transport,
        ?LearnBridgeClient $learnClient = null
    ) {
        $this->pdo         = $pdo;
        $this->transport   = $transport;
        $this->learnClient = $learnClient ?? new LearnBridgeClient($pdo);
    }

    /**
     * Resolusi daftar audiens unik berdasarkan scope & filter
     *
     * @return array<int, array{jid:string, nama:string, kelas:string, nama_anak:string}>
     */
    public function resolveAudience(string $scope, array $filter = []): array
    {
        $recipients = [];

        if ($scope === 'groups') {
            $sql = 'SELECT group_jid, group_name, learn_kelas_id FROM wa_groups WHERE 1=1';
            $params = [];
            if (!empty($filter['kelas_ids']) && is_array($filter['kelas_ids'])) {
                $ids = array_values(array_filter(array_map('intval', $filter['kelas_ids'])));
                if ($ids !== []) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $sql .= " AND learn_kelas_id IN ({$placeholders})";
                    $params = array_merge($params, $ids);
                }
            }
            if (!empty($filter['group_jids']) && is_array($filter['group_jids'])) {
                $jids = array_values(array_filter(array_map('strval', $filter['group_jids'])));
                if ($jids !== []) {
                    $placeholders = implode(',', array_fill(0, count($jids), '?'));
                    $sql .= " AND group_jid IN ({$placeholders})";
                    $params = array_merge($params, $jids);
                }
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $jid = trim((string)$g['group_jid']);
                if (wa_is_group_jid($jid)) {
                    $recipients[$jid] = [
                        'jid'       => $jid,
                        'nama'      => (string)$g['group_name'],
                        'kelas'     => (string)$g['group_name'],
                        'nama_anak' => '',
                    ];
                }
            }
            return array_values($recipients);
        }

        if ($scope === 'custom') {
            $list = (array)($filter['recipients'] ?? []);
            foreach ($list as $item) {
                if (is_string($item)) {
                    $jid = wa_phone_to_jid($item);
                    if ($jid !== '' && !isset($recipients[$jid])) {
                        $recipients[$jid] = [
                            'jid'       => $jid,
                            'nama'      => 'Bapak/Ibu',
                            'kelas'     => '-',
                            'nama_anak' => '',
                        ];
                    }
                } elseif (is_array($item)) {
                    $raw = (string)($item['jid'] ?? $item['phone'] ?? $item['phone_e164'] ?? '');
                    $jid = wa_phone_to_jid($raw);
                    if ($jid !== '' && !isset($recipients[$jid])) {
                        $recipients[$jid] = [
                            'jid'       => $jid,
                            'nama'      => (string)($item['nama'] ?? $item['name'] ?? 'Pengguna'),
                            'kelas'     => (string)($item['kelas'] ?? '-'),
                            'nama_anak' => (string)($item['nama_anak'] ?? ''),
                        ];
                    }
                }
            }
            return array_values($recipients);
        }

        // Scope berbasis data Ilmuna Learn (all_ortu, ortu_kelas, all_siswa, siswa_kelas, all_guru)
        $resp = $this->learnClient->getAudienceList($scope, $filter);
        if ($resp['success']) {
            $rows = (array)($resp['data']['recipients'] ?? $resp['data'] ?? []);
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $jid = wa_phone_to_jid((string)($r['phone'] ?? $r['phone_e164'] ?? $r['jid'] ?? ''));
                if ($jid === '') {
                    continue;
                }
                $recipients[$jid] = [
                    'jid'       => $jid,
                    'nama'      => (string)($r['nama'] ?? $r['display_name'] ?? 'Pengguna'),
                    'kelas'     => (string)($r['kelas'] ?? '-'),
                    'nama_anak' => (string)($r['nama_anak'] ?? ''),
                ];
            }
            return array_values($recipients);
        }

        // Fallback lokal dari wa_contacts_cache jika API Learn sementara tidak terjangkau
        $roleMap = [
            'all_ortu'    => 'orang_tua',
            'ortu_kelas'  => 'orang_tua',
            'all_siswa'   => 'siswa',
            'siswa_kelas' => 'siswa',
            'all_guru'    => 'guru',
        ];
        if (isset($roleMap[$scope])) {
            $stmt = $this->pdo->prepare(
                'SELECT phone_e164, display_name, extra_json FROM wa_contacts_cache WHERE role = :role AND is_blocked = 0'
            );
            $stmt->execute([':role' => $roleMap[$scope]]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $jid = wa_phone_to_jid((string)$c['phone_e164']);
                $extra = json_decode((string)($c['extra_json'] ?? '{}'), true) ?: [];
                if ($jid !== '') {
                    $recipients[$jid] = [
                        'jid'       => $jid,
                        'nama'      => (string)($c['display_name'] ?: 'Pengguna'),
                        'kelas'     => (string)($extra['kelas'] ?? '-'),
                        'nama_anak' => (string)($extra['children'][0]['nama'] ?? ''),
                    ];
                }
            }
        }

        return array_values($recipients);
    }

    /**
     * Personalisasi variabel template broadcast
     */
    public static function renderTemplate(string $template, array $vars): string
    {
        $replacements = [
            '{nama}'       => (string)($vars['nama'] ?? 'Bapak/Ibu'),
            '{kelas}'      => (string)($vars['kelas'] ?? '-'),
            '{nama_anak}'  => (string)($vars['nama_anak'] ?? ''),
            '{judul}'      => (string)($vars['judul'] ?? ''),
            '{tenggat}'    => (string)($vars['tenggat'] ?? '-'),
            '{tanggal}'    => (string)($vars['tanggal'] ?? date('d/m/Y')),
            '{link_learn}' => (string)($vars['link_learn'] ?? LEARN_PUBLIC_URL),
            '{mapel}'      => (string)($vars['mapel'] ?? ''),
            '{ringkasan}'  => (string)($vars['ringkasan'] ?? ''),
            '{sekolah}'    => (string)($vars['sekolah'] ?? SCHOOL_NAME),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Buat kampanye broadcast & langsung masukkan audiens ke antrean `wa_broadcast_queue`
     */
    public function createCampaign(array $input): array
    {
        $allowedModules = ['manual', 'tugas', 'proyek', 'agenda', 'asesmen', 'lkpd', 'materi', 'mutabaah', 'public_form'];
        $allowedScopes  = ['all_ortu', 'ortu_kelas', 'all_siswa', 'siswa_kelas', 'all_guru', 'groups', 'custom'];

        $title        = wa_truncate((string)($input['title'] ?? 'Broadcast Ilmuna'), 160);
        $sourceModule = in_array($input['source_module'] ?? 'manual', $allowedModules, true)
            ? (string)$input['source_module'] : 'manual';
        $sourceRefId  = isset($input['source_ref_id']) && is_numeric($input['source_ref_id'])
            ? (int)$input['source_ref_id'] : null;
        $targetScope  = in_array($input['target_scope'] ?? 'custom', $allowedScopes, true)
            ? (string)$input['target_scope'] : 'custom';
        $targetFilter = is_array($input['target_filter'] ?? null) ? $input['target_filter'] : [];
        $messageBody  = trim((string)($input['message_body'] ?? ''));
        $mediaUrl     = !empty($input['media_url']) ? trim((string)$input['media_url']) : null;
        $mediaType    = strtolower((string)($input['media_type'] ?? 'image'));
        $buttons      = is_array($input['buttons'] ?? null) ? $input['buttons'] : [];
        $scheduledAt  = !empty($input['scheduled_at']) ? (string)$input['scheduled_at'] : date('Y-m-d H:i:s');
        $createdBy    = (string)($input['created_by'] ?? 'system');
        $status       = ($input['status'] ?? 'scheduled') === 'draft' ? 'draft' : 'scheduled';
        $priority     = max(1, min(9, (int)($input['priority'] ?? 5)));
        $extraVars    = is_array($input['vars'] ?? null) ? $input['vars'] : [];

        if ($messageBody === '') {
            return ['success' => false, 'message' => 'Isi pesan broadcast tidak boleh kosong.'];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO wa_broadcast_campaigns
                (title, source_module, source_ref_id, target_scope, target_filter_json,
                 message_body, media_url, buttons_json, status, scheduled_at, created_by)
             VALUES
                (:title, :mod, :ref_id, :scope, :filter, :body, :media, :btns, :status, :sched, :by)'
        );
        $stmt->execute([
            ':title'  => $title,
            ':mod'    => $sourceModule,
            ':ref_id' => $sourceRefId,
            ':scope'  => $targetScope,
            ':filter' => json_encode($targetFilter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':body'   => $messageBody,
            ':media'  => $mediaUrl,
            ':btns'   => $buttons !== [] ? json_encode($buttons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':status' => $status,
            ':sched'  => $scheduledAt,
            ':by'     => mb_substr($createdBy, 0, 100, 'UTF-8'),
        ]);
        $campaignId = (int)$this->pdo->lastInsertId();

        $enqueued = 0;
        if ($status !== 'draft' && ($input['enqueue_now'] ?? true)) {
            $resolved = isset($input['pre_resolved_recipients']) && is_array($input['pre_resolved_recipients'])
                ? $input['pre_resolved_recipients']
                : $this->resolveAudience($targetScope, $targetFilter);

            $enqueued = $this->enqueueRecipientsForCampaign(
                $campaignId,
                $resolved,
                $messageBody,
                $mediaUrl,
                $mediaType,
                $buttons,
                $extraVars,
                $priority,
                $scheduledAt
            );
        }

        return [
            'success'       => true,
            'campaign_id'   => $campaignId,
            'status'        => $status,
            'total_targets' => $enqueued,
            'scheduled_at'  => $scheduledAt,
        ];
    }

    /**
     * Auto-Broadcast Sumber Akademik dari Ilmuna Learn (Tugas, Proyek, Agenda, Asesmen, LKPD, Materi, Mutaba'ah)
     * Sekaligus menjadwalkan Pengingat H-1 otomatis jika tenggat > 24 jam ke depan.
     */
    public function createAcademicBroadcast(array $event): array
    {
        $module      = strtolower(trim((string)($event['source_module'] ?? 'agenda')));
        $refId       = isset($event['source_ref_id']) && is_numeric($event['source_ref_id']) ? (int)$event['source_ref_id'] : null;
        $title       = trim((string)($event['title'] ?? 'Informasi Akademik Ilmuna Learn'));
        $kelasIds    = array_values(array_filter(array_map('intval', (array)($event['kelas_ids'] ?? []))));
        $targetRoles = array_values(array_filter(array_map('strval', (array)($event['target_roles'] ?? ['siswa', 'orang_tua', 'group_kelas']))));
        $deadlineAt  = !empty($event['deadline_at']) ? (string)$event['deadline_at'] : null;
        $summary     = trim((string)($event['summary'] ?? ''));
        $actionUrl   = trim((string)($event['action_url'] ?? LEARN_PUBLIC_URL));
        $isReminder  = (bool)($event['is_reminder'] ?? false);
        $reminderTag = (string)($event['reminder_tag'] ?? '');

        $campaignTitle = ($isReminder && $reminderTag !== '' ? "[{$reminderTag}] " : '') . $title;

        // Idempotency anti-dobel kampanye untuk entitas sumber yang sama dalam 12 jam terakhir
        if ($refId !== null) {
            $dupStmt = $this->pdo->prepare(
                'SELECT id FROM wa_broadcast_campaigns
                 WHERE source_module = :mod AND source_ref_id = :ref AND title = :title
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
                 LIMIT 1'
            );
            $dupStmt->execute([':mod' => $module, ':ref' => $refId, ':title' => wa_truncate($campaignTitle, 160)]);
            $existingId = $dupStmt->fetchColumn();
            if ($existingId) {
                return [
                    'success'            => true,
                    'duplicate_skipped'  => true,
                    'campaign_id'        => (int)$existingId,
                    'total_targets'      => 0,
                ];
            }
        }

        // Gabungkan seluruh audiens lintas role tanpa duplikasi JID
        $combined = [];
        foreach ($targetRoles as $role) {
            $scope = match ($role) {
                'siswa'       => $kelasIds !== [] ? 'siswa_kelas' : 'all_siswa',
                'orang_tua'   => $kelasIds !== [] ? 'ortu_kelas' : 'all_ortu',
                'guru'        => 'all_guru',
                'group_kelas' => 'groups',
                default       => null,
            };
            if ($scope === null) {
                continue;
            }
            $batch = $this->resolveAudience($scope, $kelasIds !== [] ? ['kelas_ids' => $kelasIds] : []);
            foreach ($batch as $rec) {
                $combined[$rec['jid']] = $rec;
            }
        }

        $badge = match ($module) {
            'tugas'       => '📚 TUGAS BARU',
            'proyek'      => '🚀 PROYEK P5 / PPRA',
            'agenda'      => '🗓️ AGENDA & KALDIK SEKOLAH',
            'asesmen'     => '📝 ASESMEN / UJIAN CBT',
            'lkpd'        => '📄 LEMBAR KERJA (LKPD)',
            'materi'      => '📖 MATERI PEMBELAJARAN BARU',
            'mutabaah'    => "🕌 PENGINGAT MUTABA'AH YAUMIYAH",
            'public_form' => '📋 FORMULIR RESMI SEKOLAH',
            default       => '📢 INFORMASI AKADEMIK',
        };
        if ($isReminder) {
            $badge = '⏰ PENGINGAT TENGGAT — ' . strtoupper($module);
        }

        $deadlineFormatted = $deadlineAt !== null && strtotime($deadlineAt) !== false
            ? date('d M Y, H:i', strtotime($deadlineAt)) . ' WIB'
            : '-';

        $template = "*{$badge}*\n"
            . "Assalamu'alaikum *{nama}* ({kelas})\n\n"
            . "📌 *Judul:* {judul}\n"
            . ($deadlineAt !== null ? "⏳ *Tenggat:* {tenggat}\n" : '')
            . ($summary !== '' ? "\n{ringkasan}\n" : '')
            . "\nSilakan buka portal Ilmuna Learn melalui tombol di bawah ini.";

        $buttons = [
            ['type' => 'cta_url', 'id' => 'CTA_ACADEMIC_' . ($refId ?? 0), 'title' => '📚 Buka Ilmuna Learn', 'url' => $actionUrl],
            ['type' => 'reply',   'id' => 'ACK_READ_' . strtoupper($module) . '_' . ($refId ?? 0), 'title' => '✅ Tandai Dibaca'],
        ];

        $vars = [
            'judul'      => $title,
            'tenggat'    => $deadlineFormatted,
            'tanggal'    => date('d M Y'),
            'link_learn' => $actionUrl,
            'ringkasan'  => $summary,
        ];

        $mainRes = $this->createCampaign([
            'title'                   => $campaignTitle,
            'source_module'           => $module,
            'source_ref_id'           => $refId,
            'target_scope'            => 'custom',
            'target_filter'           => ['kelas_ids' => $kelasIds, 'target_roles' => $targetRoles],
            'message_body'            => $template,
            'buttons'                 => $buttons,
            'vars'                    => $vars,
            'priority'                => 3,
            'created_by'              => 'learn_auto_broadcast',
            'pre_resolved_recipients' => array_values($combined),
        ]);

        // Otomatis jadwalkan Pengingat H-1 jika bukan reminder & tenggat masih > 24 jam ke depan
        $h1CampaignId = null;
        if (!$isReminder && $deadlineAt !== null) {
            $dlTs = strtotime($deadlineAt);
            if ($dlTs !== false && ($dlTs - time()) > 86400) {
                $h1Time = date('Y-m-d H:i:s', $dlTs - 86400);
                $h1Template = "*⏰ PENGINGAT H-1 TENGGAT (" . strtoupper($module) . ")*\n"
                    . "Assalamu'alaikum *{nama}* ({kelas})\n\n"
                    . "Mengingatkan bahwa *{judul}* akan berakhir besok:\n"
                    . "⏳ *Batas Waktu:* {tenggat}\n\n"
                    . 'Pastikan sudah diselesaikan tepat waktu melalui Ilmuna Learn.';
                $h1Res = $this->createCampaign([
                    'title'                   => '[Pengingat H-1] ' . $title,
                    'source_module'           => $module,
                    'source_ref_id'           => $refId,
                    'target_scope'            => 'custom',
                    'target_filter'           => ['kelas_ids' => $kelasIds, 'target_roles' => $targetRoles, 'reminder' => 'H-1'],
                    'message_body'            => $h1Template,
                    'buttons'                 => $buttons,
                    'vars'                    => $vars,
                    'scheduled_at'            => $h1Time,
                    'priority'                => 4,
                    'created_by'              => 'learn_h1_scheduler',
                    'pre_resolved_recipients' => array_values($combined),
                ]);
                $h1CampaignId = $h1Res['campaign_id'] ?? null;
            }
        }

        $mainRes['h1_reminder_campaign_id'] = $h1CampaignId;
        return $mainRes;
    }

    /**
     * Masukkan notifikasi real-time prioritas tertinggi (priority = 1) dari Ilmuna Learn
     * (Absensi Alpa/Terlambat, Pelanggaran BK, Pengajuan Perizinan GPS+Bukti)
     */
    public function enqueuePriorityNotification(
        array $recipients,
        string $payloadType,
        array $payload,
        bool $sendImmediately = true
    ): array {
        $queueIds = [];
        $sentNow  = 0;
        $failedNow = 0;

        $stmt = $this->pdo->prepare(
            "INSERT INTO wa_broadcast_queue
                (campaign_id, priority, recipient_jid, recipient_name, payload_type, payload_json, status, scheduled_at)
             VALUES
                (NULL, 1, :jid, :name, :ptype, :pjson, 'pending', NOW())"
        );

        $seen = [];
        foreach ($recipients as $rec) {
            $rawPhone = is_array($rec) ? (string)($rec['jid'] ?? $rec['phone'] ?? '') : (string)$rec;
            $recName  = is_array($rec) ? (string)($rec['nama'] ?? '') : '';
            $jid = wa_phone_to_jid($rawPhone);
            if ($jid === '' || isset($seen[$jid])) {
                continue;
            }
            $seen[$jid] = true;

            $stmt->execute([
                ':jid'   => $jid,
                ':name'  => $recName !== '' ? mb_substr($recName, 0, 150, 'UTF-8') : null,
                ':ptype' => $payloadType,
                ':pjson' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $qid = (int)$this->pdo->lastInsertId();
            $queueIds[] = $qid;

            if ($sendImmediately) {
                $ok = $this->processSingleQueueRowById($qid);
                if ($ok) {
                    $sentNow++;
                } else {
                    $failedNow++;
                }
            }
        }

        return [
            'queued'     => count($queueIds),
            'queue_ids'  => $queueIds,
            'sent_now'   => $sentNow,
            'retry_later'=> $failedNow,
        ];
    }

    /**
     * Proses 1 batch antrean broadcast dengan anti-banned rate-limiting (jeda acak 3–7 detik)
     * dan retry otomatis maksimal 3× bila gagal jaringan.
     */
    public function processQueueBatch(
        ?int $batchSize = null,
        ?int $delayMinSec = null,
        ?int $delayMaxSec = null,
        ?callable $sleeper = null
    ): array {
        $limit    = max(1, $batchSize ?? (int)wa_get_setting('broadcast_batch_size', '25', $this->pdo));
        $minDelay = max(0, $delayMinSec ?? (int)wa_get_setting('broadcast_delay_min_sec', '3', $this->pdo));
        $maxDelay = max($minDelay, $delayMaxSec ?? (int)wa_get_setting('broadcast_delay_max_sec', '7', $this->pdo));
        $sleepFn  = $sleeper ?? static function (int $sec): void {
            if ($sec > 0) {
                sleep($sec);
            }
        };

        $stmt = $this->pdo->prepare(
            "SELECT q.*
             FROM wa_broadcast_queue q
             LEFT JOIN wa_broadcast_campaigns c ON c.id = q.campaign_id
             WHERE q.status = 'pending'
               AND q.scheduled_at <= NOW()
               AND q.attempts < 3
               AND (q.campaign_id IS NULL OR c.status IN ('scheduled', 'processing'))
             ORDER BY q.priority ASC, q.id ASC
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0;
        $sent      = 0;
        $retried   = 0;
        $failed    = 0;
        $delays    = [];
        $touchedCampaigns = [];

        $totalRows = count($rows);
        foreach ($rows as $idx => $row) {
            $id = (int)$row['id'];
            if (!$this->claimQueueItem($id)) {
                continue; // Sudah diklaim worker lain
            }

            $processed++;
            if ($row['campaign_id'] !== null) {
                $cid = (int)$row['campaign_id'];
                $touchedCampaigns[$cid] = true;
                $this->pdo->prepare("UPDATE wa_broadcast_campaigns SET status = 'processing' WHERE id = :cid AND status = 'scheduled'")
                    ->execute([':cid' => $cid]);
            }

            $attempts = (int)$row['attempts'] + 1;
            $sendRes  = $this->dispatchQueuePayload($row);

            if (!empty($sendRes['success'])) {
                $this->pdo->prepare("UPDATE wa_broadcast_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = :id")
                    ->execute([':id' => $id]);
                if ($row['campaign_id'] !== null) {
                    $this->pdo->prepare('UPDATE wa_broadcast_campaigns SET sent_count = sent_count + 1 WHERE id = :cid')
                        ->execute([':cid' => (int)$row['campaign_id']]);
                }
                $sent++;
            } else {
                $err = mb_substr((string)($sendRes['error'] ?? 'Unknown transport error'), 0, 1000, 'UTF-8');
                if ($attempts < 3) {
                    $backoffSec = 60 * $attempts;
                    $nextRun = date('Y-m-d H:i:s', time() + $backoffSec);
                    $this->pdo->prepare(
                        "UPDATE wa_broadcast_queue SET status = 'pending', scheduled_at = :next, last_error = :err WHERE id = :id"
                    )->execute([':next' => $nextRun, ':err' => $err, ':id' => $id]);
                    $retried++;
                } else {
                    $this->pdo->prepare(
                        "UPDATE wa_broadcast_queue SET status = 'failed', last_error = :err WHERE id = :id"
                    )->execute([':err' => $err, ':id' => $id]);
                    if ($row['campaign_id'] !== null) {
                        $this->pdo->prepare('UPDATE wa_broadcast_campaigns SET failed_count = failed_count + 1 WHERE id = :cid')
                            ->execute([':cid' => (int)$row['campaign_id']]);
                    }
                    $failed++;
                }
            }

            // Jeda anti-banned acak 3–7 detik antar pengiriman broadcast massal (kecuali item terakhir)
            if ($idx < $totalRows - 1) {
                $priority = (int)$row['priority'];
                $waitSec = $priority === 1 ? 0 : ($maxDelay > $minDelay ? random_int($minDelay, $maxDelay) : $minDelay);
                if ($waitSec > 0) {
                    $delays[] = $waitSec;
                    $sleepFn($waitSec);
                }
            }
        }

        // Perbarui status kampanye menjadi 'completed' bila seluruh antreannya sudah selesai
        foreach (array_keys($touchedCampaigns) as $cid) {
            $this->refreshCampaignCompletion($cid);
        }

        return [
            'processed'          => $processed,
            'sent'               => $sent,
            'retried'            => $retried,
            'failed'             => $failed,
            'delays_applied_sec' => $delays,
            'campaigns_updated'  => array_keys($touchedCampaigns),
        ];
    }

    /**
     * Pindai Tugas, Proyek, Agenda/Kaldik, & Asesmen CBT mendatang (H-1 / H-0) dari Ilmuna Learn
     * dan otomatis jadwalkan auto-broadcast pengingat tanpa duplikasi.
     */
    public function runReminderScan(): array
    {
        $resp = $this->learnClient->getUpcomingAcademic(null, 'all', 2);
        if (!$resp['success']) {
            return ['success' => false, 'message' => $resp['message'], 'scheduled' => 0];
        }

        $items = (array)($resp['data']['items'] ?? []);
        $scheduled = 0;
        $skipped   = 0;

        foreach ($items as $item) {
            $module = strtolower((string)($item['module'] ?? 'agenda'));
            $refId  = isset($item['ref_id']) ? (int)$item['ref_id'] : null;
            $tag    = (string)($item['reminder_tag'] ?? 'Pengingat H-1');

            $res = $this->createAcademicBroadcast([
                'source_module' => $module,
                'source_ref_id' => $refId,
                'title'         => (string)($item['title'] ?? 'Agenda Akademik'),
                'kelas_ids'     => (array)($item['kelas_ids'] ?? []),
                'target_roles'  => (array)($item['target_roles'] ?? ['siswa', 'orang_tua', 'group_kelas']),
                'deadline_at'   => $item['deadline_at'] ?? null,
                'summary'       => (string)($item['summary'] ?? ''),
                'action_url'    => (string)($item['action_url'] ?? LEARN_PUBLIC_URL),
                'is_reminder'   => true,
                'reminder_tag'  => $tag,
            ]);

            if (!empty($res['duplicate_skipped'])) {
                $skipped++;
            } elseif (!empty($res['success'])) {
                $scheduled++;
            }
        }

        return [
            'success'         => true,
            'items_inspected' => count($items),
            'scheduled'       => $scheduled,
            'skipped'         => $skipped,
        ];
    }

    private function enqueueRecipientsForCampaign(
        int $campaignId,
        array $recipients,
        string $template,
        ?string $mediaUrl,
        string $mediaType,
        array $buttons,
        array $extraVars,
        int $priority,
        string $scheduledAt
    ): int {
        if ($recipients === []) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO wa_broadcast_queue
                (campaign_id, priority, recipient_jid, recipient_name, payload_type, payload_json, status, scheduled_at)
             VALUES
                (:cid, :prio, :jid, :rname, :ptype, :pjson, 'pending', :sched)"
        );

        $count = 0;
        $seen  = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($recipients as $rec) {
                $jid = (string)($rec['jid'] ?? '');
                if ($jid === '' || isset($seen[$jid])) {
                    continue;
                }
                $seen[$jid] = true;

                $vars = array_merge($extraVars, [
                    'nama'      => (string)($rec['nama'] ?? 'Bapak/Ibu'),
                    'kelas'     => (string)($rec['kelas'] ?? '-'),
                    'nama_anak' => (string)($rec['nama_anak'] ?? ''),
                ]);
                $renderedText = self::renderTemplate($template, $vars);

                // Tentukan payload_type optimal
                $payloadType = 'text';
                $payload = ['body' => $renderedText];

                if ($mediaUrl !== null && $mediaUrl !== '') {
                    $payloadType = 'media';
                    $payload['media_type'] = $mediaType;
                    $payload['media_url']  = $mediaUrl;
                    $payload['caption']    = $renderedText;
                    if ($buttons !== []) {
                        $payload['buttons'] = $buttons;
                    }
                } elseif ($buttons !== []) {
                    $ctaBtn = null;
                    $replyBtns = [];
                    foreach ($buttons as $b) {
                        if (($b['type'] ?? '') === 'cta_url' || !empty($b['url'])) {
                            $ctaBtn = $b;
                        } else {
                            $replyBtns[] = $b;
                        }
                    }

                    // Untuk grup WA, utamakan teks + tautan rapi (grup tidak mendukung interactive button Cloud API)
                    if (wa_is_group_jid($jid)) {
                        $payloadType = 'text';
                        $textWithLink = $renderedText;
                        if ($ctaBtn !== null && !empty($ctaBtn['url'])) {
                            $textWithLink .= "\n\n🔗 *{$ctaBtn['title']}:*\n{$ctaBtn['url']}";
                        }
                        $payload = ['body' => $textWithLink];
                    } elseif ($ctaBtn !== null && $replyBtns === []) {
                        $payloadType = 'cta_url';
                        $payload['button_text'] = (string)($ctaBtn['title'] ?? 'Buka Tautan');
                        $payload['url']         = (string)$ctaBtn['url'];
                    } elseif ($ctaBtn !== null && $replyBtns !== []) {
                        // Sertakan link CTA di body + tombol reply cepat agar 1 pesan interaktif langsung lengkap
                        $payloadType = 'buttons';
                        $payload['body']    = $renderedText . "\n\n🔗 *{$ctaBtn['title']}:*\n{$ctaBtn['url']}";
                        $payload['buttons'] = $replyBtns;
                    } else {
                        $payloadType = 'buttons';
                        $payload['buttons'] = $replyBtns;
                    }
                }

                $stmt->execute([
                    ':cid'   => $campaignId,
                    ':prio'  => $priority,
                    ':jid'   => $jid,
                    ':rname' => mb_substr((string)($rec['nama'] ?? ''), 0, 150, 'UTF-8'),
                    ':ptype' => $payloadType,
                    ':pjson' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':sched' => $scheduledAt,
                ]);
                $count++;
            }

            $this->pdo->prepare('UPDATE wa_broadcast_campaigns SET total_targets = :tot WHERE id = :cid')
                ->execute([':tot' => $count, ':cid' => $campaignId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $count;
    }

    private function processSingleQueueRowById(int $id): bool
    {
        if (!$this->claimQueueItem($id)) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM wa_broadcast_queue WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $res = $this->dispatchQueuePayload($row);
        if (!empty($res['success'])) {
            $this->pdo->prepare("UPDATE wa_broadcast_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = :id")
                ->execute([':id' => $id]);
            return true;
        }

        $err = mb_substr((string)($res['error'] ?? 'Immediate send failed'), 0, 1000, 'UTF-8');
        $nextRun = date('Y-m-d H:i:s', time() + 60);
        $this->pdo->prepare("UPDATE wa_broadcast_queue SET status = 'pending', scheduled_at = :next, last_error = :err WHERE id = :id")
            ->execute([':next' => $nextRun, ':err' => $err, ':id' => $id]);
        return false;
    }

    private function claimQueueItem(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE wa_broadcast_queue
             SET status = 'sending', attempts = attempts + 1
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function dispatchQueuePayload(array $row): array
    {
        $to      = (string)$row['recipient_jid'];
        $type    = (string)$row['payload_type'];
        $payload = json_decode((string)$row['payload_json'], true) ?: [];
        $options = [
            'header' => $payload['header'] ?? null,
            'footer' => $payload['footer'] ?? 'Ilmuna Learn · Notifikasi Resmi Sekolah',
        ];

        if ($type === 'media') {
            $mediaRes = $this->transport->sendMedia(
                $to,
                (string)($payload['media_type'] ?? 'image'),
                (string)($payload['media_url'] ?? ''),
                (string)($payload['caption'] ?? $payload['body'] ?? ''),
                ['filename' => $payload['filename'] ?? null]
            );
            // Jika media disertai tombol aksi (misal Foto Bukti Izin + tombol Setujui/Tolak Guru), kirim tombol tepat setelahnya
            if (!empty($mediaRes['success']) && !empty($payload['buttons']) && is_array($payload['buttons']) && !wa_is_group_jid($to)) {
                $btnBody = (string)($payload['buttons_body'] ?? 'Pilih tindakan melalui tombol di bawah ini:');
                $this->transport->sendButtons($to, $btnBody, $payload['buttons'], $options);
            }
            return $mediaRes;
        }

        if ($type === 'cta_url') {
            return $this->transport->sendCtaUrl(
                $to,
                (string)($payload['body'] ?? ''),
                (string)($payload['button_text'] ?? 'Buka Tautan'),
                (string)($payload['url'] ?? LEARN_PUBLIC_URL),
                $options
            );
        }

        if ($type === 'buttons') {
            return $this->transport->sendButtons(
                $to,
                (string)($payload['body'] ?? ''),
                (array)($payload['buttons'] ?? []),
                $options
            );
        }

        if ($type === 'list') {
            return $this->transport->sendListMenu(
                $to,
                (string)($payload['body'] ?? ''),
                (string)($payload['button_label'] ?? 'Pilih Menu'),
                (array)($payload['sections'] ?? []),
                $options
            );
        }

        return $this->transport->sendText($to, (string)($payload['body'] ?? ''), $options);
    }

    private function refreshCampaignCompletion(int $campaignId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM wa_broadcast_queue
             WHERE campaign_id = :cid AND status IN ('pending', 'sending')"
        );
        $stmt->execute([':cid' => $campaignId]);
        $remaining = (int)$stmt->fetchColumn();

        if ($remaining === 0) {
            $this->pdo->prepare(
                "UPDATE wa_broadcast_campaigns SET status = 'completed'
                 WHERE id = :cid AND status IN ('scheduled', 'processing')"
            )->execute([':cid' => $campaignId]);
        }
    }
}
