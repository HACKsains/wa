<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — GROUP VIOLATION GUARD ENGINE (MODERASI GRUP OTOMATIS)
 * ==============================================================================
 * Evaluasi berurutan 5 tahap sesuai IMPLEMENTATION_PLAN.md Fase 4:
 *   1. Quiet Hours (Jam Malam Grup, mendukung lintas tengah malam misal 21:30–05:00)
 *   2. Anti-Virtex (panjang ekstrem > 4000 karakter / bom karakter Unicode tak terlihat)
 *   3. Anti-Flood / Spam (batas pesan beruntun per jendela detik, misal 5 pesan / 15 detik)
 *   4. Anti-Link Berbahaya, Judol, Phishing & Invite Grup Lain (dengan whitelist resmi)
 *   5. Anti-Kata Kasar / Toxic (kamus kata + normalisasi leetspeak `4nj1ng`, `g0bl0k`, `a.n.j.i.n.g`)
 *
 * Eskalasi Strike Berjenjang (`wa_violation_logs`):
 *   Strike 1 -> Peringatan / Hapus Pesan sesuai `action_mode`
 *   Strike 2 -> Hapus Pesan + Peringatan Keras (@mention)
 *   Strike >= 3 (atau pelanggaran berat `delete_kick`) -> Hapus Pesan + Kick Anggota
 *
 * Integrasi Ilmuna Learn BK & Notifikasi Orang Tua:
 *   Jika nomor pelaku terdaftar sebagai `siswa` di Ilmuna Learn:
 *   - Sinkron otomatis ke `catatan_pelanggaran_siswa` (poin BK 70/30 Afektif)
 *   - Kirim notifikasi WA bertombol ke Orang Tua siswa
 * ==============================================================================
 */

class GroupGuardEngine
{
    private PDO $pdo;
    private TransportInterface $transport;
    private LearnBridgeClient $learnClient;

    /** @var callable */
    private $clock;

    public function __construct(
        PDO $pdo,
        TransportInterface $transport,
        ?LearnBridgeClient $learnClient = null,
        ?callable $clock = null
    ) {
        $this->pdo         = $pdo;
        $this->transport   = $transport;
        $this->learnClient = $learnClient ?? new LearnBridgeClient($pdo);
        $this->clock       = $clock ?? static fn(): int => time();
    }

    /**
     * Inspeksi pesan grup masuk dari WebhookProcessor
     */
    public function inspectMessage(array $event): array
    {
        $groupJid    = trim((string)($event['group_jid'] ?? $event['chat_jid'] ?? ''));
        $senderPhone = wa_normalize_phone((string)($event['sender_phone'] ?? $event['sender_jid'] ?? ''));
        $senderJid   = (string)($event['sender_jid'] ?? ($senderPhone !== '' ? $senderPhone . '@s.whatsapp.net' : ''));
        $senderName  = trim((string)($event['sender_name'] ?? ''));
        $messageId   = trim((string)($event['message_id'] ?? ''));
        $text        = (string)($event['text'] ?? '');

        if (!wa_is_group_jid($groupJid) || $senderPhone === '') {
            return ['violated' => false, 'reason' => 'invalid_group_event'];
        }

        // 1. Cek sakelar global Group Guard
        if (wa_get_setting('group_guard_global_enabled', '1', $this->pdo) !== '1') {
            return ['violated' => false, 'reason' => 'global_guard_disabled'];
        }

        // 2. Muat atau auto-daftarkan grup di tabel `wa_groups`
        $group = $this->getOrRegisterGroup($groupJid, (string)($event['raw']['groupName'] ?? $event['raw']['group_name'] ?? ''));
        if ((int)$group['guard_enabled'] !== 1) {
            return ['violated' => false, 'reason' => 'group_guard_disabled'];
        }

        // 3. Cek peran pengirim: Admin / Guru / Guru BK dibebaskan dari moderasi otomatis
        $senderContact = $this->resolveSenderContact($senderPhone, $senderName);
        if (in_array($senderContact['role'], ['admin', 'guru', 'guru_bk'], true)) {
            return ['violated' => false, 'reason' => 'staff_exempt', 'role' => $senderContact['role']];
        }

        // 4. Muat seluruh aturan aktif dari `wa_group_rules`
        $rules = $this->loadActiveRules();

        // 5. Evaluasi berurutan: Quiet Hours -> Anti-Virtex -> Anti-Flood -> Anti-Link/Judol -> Anti-Kata Kasar
        $violation = $this->checkQuietHours($group)
            ?? $this->checkVirtex($text, $rules['virtex'])
            ?? $this->checkFlood($groupJid, $senderPhone, $rules['flood'])
            ?? $this->checkLinksAndJudol($text, $rules['link_whitelist'], $rules['link_blacklist'])
            ?? $this->checkBadwords($text, $rules['badword']);

        if ($violation === null) {
            return ['violated' => false, 'reason' => 'clean'];
        }

        // 6. Hitung eskalasi strike (30 hari terakhir pada grup ini)
        $strikeCount   = $this->countRecentStrikes($groupJid, $senderPhone) + 1;
        $maxStrikeKick = max(2, (int)wa_get_setting('group_max_strike_kick', '3', $this->pdo));
        $ruleAction    = (string)$violation['action_mode'];

        $shouldKick   = ($ruleAction === 'delete_kick') || ($strikeCount >= $maxStrikeKick);
        $shouldDelete = $shouldKick || ($ruleAction === 'delete_warn') || ($strikeCount >= 2);

        $deleteRes = null;
        $kickRes   = null;
        $actionTaken = 'warned';

        if ($shouldDelete && $messageId !== '') {
            $deleteRes = $this->transport->deleteGroupMessage($groupJid, $messageId, $senderJid);
            $actionTaken = 'deleted_and_warned';
        }

        if ($shouldKick) {
            $kickRes = $this->transport->kickGroupMember($groupJid, $senderPhone);
            $actionTaken = 'deleted_and_kicked';
        }

        // 7. Kirim pesan peringatan/tindakan resmi di grup dengan @mention
        $noticeText = $this->buildGroupModerationNotice(
            $senderPhone,
            $senderName !== '' ? $senderName : $senderContact['display_name'],
            $violation,
            $strikeCount,
            $maxStrikeKick,
            $actionTaken
        );
        $warnRes = $this->transport->sendText($groupJid, $noticeText, [
            'mentions' => [$senderJid],
        ]);

        // 8. Sinkronisasi ke BK Ilmuna Learn & Notifikasi WA Orang Tua (khusus jika pelaku adalah siswa)
        $learnSiswaId   = $senderContact['role'] === 'siswa' ? ($senderContact['learn_ref_id'] ?? null) : null;
        $syncedToBk     = 0;
        $parentNotified = 0;
        $bkPoin         = (int)($violation['bk_poin'] ?? 0);

        if ($learnSiswaId !== null && $learnSiswaId > 0) {
            if ((int)$group['sync_bk_enabled'] === 1 && $bkPoin > 0) {
                $desc = sprintf(
                    'Pelanggaran Grup WA "%s" (%s): %s [Strike #%d]',
                    (string)$group['group_name'],
                    (string)$violation['label'],
                    wa_truncate($text, 180),
                    $strikeCount
                );
                $bkRes = $this->learnClient->recordGroupViolation(
                    $learnSiswaId,
                    (string)$group['group_name'],
                    (string)$violation['type'],
                    $desc,
                    $bkPoin
                );
                if (!empty($bkRes['success'])) {
                    $syncedToBk = 1;
                }
            }

            if ((int)$group['notify_parent_enabled'] === 1) {
                $parentNotified = $this->notifyStudentParents(
                    $learnSiswaId,
                    $senderContact,
                    (string)$group['group_name'],
                    $violation,
                    $strikeCount,
                    $actionTaken,
                    $syncedToBk === 1
                );
            }
        }

        // 9. Catat audit pelanggaran ke `wa_violation_logs`
        $logId = $this->insertViolationLog([
            'group_jid'       => $groupJid,
            'sender_phone'    => $senderPhone,
            'sender_name'     => $senderName !== '' ? $senderName : $senderContact['display_name'],
            'learn_siswa_id'  => $learnSiswaId,
            'violation_type'  => (string)$violation['type'],
            'matched_pattern' => (string)$violation['matched_pattern'],
            'message_excerpt' => wa_truncate($text, 500),
            'strike_count'    => $strikeCount,
            'action_taken'    => $actionTaken,
            'synced_to_bk'    => $syncedToBk,
            'parent_notified' => $parentNotified,
        ]);

        return [
            'violated'        => true,
            'log_id'          => $logId,
            'violation_type'  => $violation['type'],
            'matched_pattern' => $violation['matched_pattern'],
            'severity'        => $violation['severity'],
            'strike_count'    => $strikeCount,
            'max_strike_kick' => $maxStrikeKick,
            'action_taken'    => $actionTaken,
            'learn_siswa_id'  => $learnSiswaId,
            'synced_to_bk'    => $syncedToBk === 1,
            'parent_notified' => $parentNotified === 1,
            'delete_result'   => $deleteRes,
            'kick_result'     => $kickRes,
            'warn_result'     => $warnRes,
        ];
    }

    /**
     * Normalisasi leetspeak & variasi ejaan untuk deteksi kata kasar/toxic
     * Contoh: "4nj1ng", "a.n.j.i.n.g", "g0bl0k", "b@ngs4t" -> "anjing", "goblok", "bangsat"
     */
    public static function normalizeForProfanityCheck(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');

        // Hanya ubah '!' / '|' menjadi 'i' jika berada di tengah kata (misal: anj!ng -> anjing), bukan tanda seru akhir kalimat!
        $lower = (string)preg_replace('/(?<=[a-z0-9])[!|](?=[a-z0-9])/u', 'i', $lower);

        $leetMap = [
            '4' => 'a', '@' => 'a',
            '3' => 'e',
            '1' => 'i',
            '0' => 'o',
            '5' => 's', '$' => 's',
            '7' => 't',
            '8' => 'b',
            '6' => 'g', '9' => 'g',
        ];
        $mapped = strtr($lower, $leetMap);

        // Versi 1: pertahankan spasi kata (contoh: "dasar 4nj1ng kamu!" -> "dasar anjing kamu")
        $spaced = trim((string)preg_replace('/[^a-z\s]+/u', ' ', $mapped));
        $spacedCollapsed = (string)preg_replace('/([a-z])\1{2,}/u', '$1', $spaced);

        // Versi 2: hapus tanda baca penyisip di tengah kata (contoh: "a.n.j.1.n.g" -> "anjing")
        $tokens = preg_split('/\s+/u', $mapped, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cleanedTokens = [];
        foreach ($tokens as $tok) {
            $letters = (string)preg_replace('/[^a-z]+/u', '', $tok);
            if ($letters !== '') {
                $cleanedTokens[] = (string)preg_replace('/([a-z])\1{2,}/u', '$1', $letters);
            }
        }
        $rejoined = implode(' ', $cleanedTokens);

        // Versi 3: gabungkan huruf tunggal terpisah spasi (contoh: "a n j i n g" -> "anjing")
        $compact = (string)preg_replace('/\b([a-z])\s+(?=[a-z]\b)/u', '$1', $rejoined);

        return [
            'spaced'   => $spacedCollapsed,
            'rejoined' => $rejoined,
            'compact'  => $compact,
        ];
    }

    // ==========================================================================
    // 5 TAHAP EVALUASI PELANGGARAN GRUP
    // ==========================================================================

    private function checkQuietHours(array $group): ?array
    {
        $start = trim((string)($group['quiet_start'] ?? ''));
        $end   = trim((string)($group['quiet_end'] ?? ''));
        if ($start === '' || $end === '') {
            return null;
        }

        $nowTime = date('H:i:s', ($this->clock)());
        $isQuiet = false;

        if ($start < $end) {
            // Rentang di hari yang sama (misal 13:00:00 - 15:00:00)
            $isQuiet = ($nowTime >= $start && $nowTime <= $end);
        } elseif ($start > $end) {
            // Rentang lintas tengah malam (misal 21:30:00 - 05:00:00)
            $isQuiet = ($nowTime >= $start || $nowTime <= $end);
        }

        if (!$isQuiet) {
            return null;
        }

        return [
            'type'            => 'quiet_hours',
            'label'           => 'Jam Malam Grup',
            'matched_pattern' => substr($start, 0, 5) . '-' . substr($end, 0, 5),
            'severity'        => 'ringan',
            'action_mode'     => 'delete_warn',
            'bk_poin'         => 0,
        ];
    }

    private function checkVirtex(string $text, array $virtexRules): ?array
    {
        $maxLen = 4000;
        $matchedRule = null;

        foreach ($virtexRules as $rule) {
            if (preg_match('/len_gt_(\d+)/i', (string)$rule['pattern'], $m)) {
                $threshold = (int)$m[1];
                if (mb_strlen($text, 'UTF-8') > $threshold) {
                    $maxLen = $threshold;
                    $matchedRule = $rule;
                    break;
                }
            }
        }

        // Deteksi bom karakter zero-width / combining Unicode (virtex pendek tapi bikin lag)
        $invisibleCount = preg_match_all('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', $text);
        if ($matchedRule === null && $invisibleCount !== false && $invisibleCount >= 150) {
            $fallbackRule = $virtexRules[0] ?? ['severity' => 'berat', 'action_mode' => 'delete_kick', 'bk_poin' => 25];
            return [
                'type'            => 'virtex',
                'label'           => 'Pesan Virtex / Karakter Berbahaya',
                'matched_pattern' => "invisible_unicode_gte_{$invisibleCount}",
                'severity'        => (string)$fallbackRule['severity'],
                'action_mode'     => (string)$fallbackRule['action_mode'],
                'bk_poin'         => (int)$fallbackRule['bk_poin'],
            ];
        }

        if ($matchedRule !== null) {
            return [
                'type'            => 'virtex',
                'label'           => 'Pesan Virtex / Panjang Ekstrem',
                'matched_pattern' => 'len_gt_' . $maxLen,
                'severity'        => (string)$matchedRule['severity'],
                'action_mode'     => (string)$matchedRule['action_mode'],
                'bk_poin'         => (int)$matchedRule['bk_poin'],
            ];
        }

        return null;
    }

    private function checkFlood(string $groupJid, string $senderPhone, array $floodRules): ?array
    {
        if ($floodRules === []) {
            return null;
        }

        foreach ($floodRules as $rule) {
            $maxMsgs = 5;
            $windowSec = 15;
            if (preg_match('/(\d+)_msg_in_(\d+)_sec/i', (string)$rule['pattern'], $m)) {
                $maxMsgs   = max(2, (int)$m[1]);
                $windowSec = max(3, (int)$m[2]);
            }

            $since = date('Y-m-d H:i:s', ($this->clock)() - $windowSec);
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM wa_message_logs
                 WHERE direction = 'in'
                   AND remote_jid = :gjid
                   AND content_preview LIKE :senderPrefix
                   AND created_at >= :since"
            );
            $stmt->execute([
                ':gjid'         => $groupJid,
                ':senderPrefix' => $senderPhone . ':%',
                ':since'        => $since,
            ]);
            $count = (int)$stmt->fetchColumn();

            if ($count >= $maxMsgs) {
                return [
                    'type'            => 'flood',
                    'label'           => 'Spam / Flood Pesan Beruntun',
                    'matched_pattern' => (string)$rule['pattern'],
                    'severity'        => (string)$rule['severity'],
                    'action_mode'     => (string)$rule['action_mode'],
                    'bk_poin'         => (int)$rule['bk_poin'],
                ];
            }
        }

        return null;
    }

    private function checkLinksAndJudol(string $text, array $whitelistRules, array $blacklistRules): ?array
    {
        if (trim($text) === '') {
            return null;
        }

        // 1. Evaluasi pola blacklist (judol, phishing, invite grup WA luar, shortlink judol)
        foreach ($blacklistRules as $rule) {
            $pattern = trim((string)$rule['pattern']);
            if ($pattern === '') {
                continue;
            }
            $regex = '~(?:' . str_replace('~', '\~', $pattern) . ')~iu';
            if (@preg_match($regex, $text) === 1) {
                $isJudol = (bool)preg_match('/slot|gacor|maxwin|judi|togel|zeus|pgsoft|pragmatic|sbobet|scatter|deposit/i', $pattern);
                return [
                    'type'            => $isJudol ? 'judol' : 'link',
                    'label'           => $isJudol ? 'Promosi Judol / Konten Terlarang' : 'Tautan / Invite Grup Terlarang',
                    'matched_pattern' => wa_truncate($pattern, 120),
                    'severity'        => (string)$rule['severity'],
                    'action_mode'     => (string)$rule['action_mode'],
                    'bk_poin'         => (int)$rule['bk_poin'],
                ];
            }
        }

        // 2. Ekstrak URL apa pun dalam pesan & cocokkan dengan whitelist domain resmi
        $urlRegex = '/(?:https?:\/\/|www\.)[^\s<>"\']+/iu';
        if (preg_match_all($urlRegex, $text, $matches) > 0) {
            $allowedDomains = array_values(array_filter(array_map(
                static fn($r) => strtolower(trim((string)$r['pattern'])),
                $whitelistRules
            )));

            foreach ($matches[0] as $foundUrl) {
                $normalizedUrl = str_starts_with(strtolower($foundUrl), 'http') ? $foundUrl : ('https://' . $foundUrl);
                $host = strtolower((string)(parse_url($normalizedUrl, PHP_URL_HOST) ?? ''));
                if ($host === '') {
                    continue;
                }

                $whitelisted = false;
                foreach ($allowedDomains as $dom) {
                    if ($host === $dom || str_ends_with($host, '.' . $dom)) {
                        $whitelisted = true;
                        break;
                    }
                }

                if (!$whitelisted) {
                    return [
                        'type'            => 'link',
                        'label'           => 'Tautan Eksternal Non-Whitelist',
                        'matched_pattern' => wa_truncate($host, 120),
                        'severity'        => 'sedang',
                        'action_mode'     => 'delete_warn',
                        'bk_poin'         => 10,
                    ];
                }
            }
        }

        return null;
    }

    private function checkBadwords(string $text, array $badwordRules): ?array
    {
        if (trim($text) === '' || $badwordRules === []) {
            return null;
        }

        $variants = self::normalizeForProfanityCheck($text);
        $haystacks = array_unique(array_values($variants));

        foreach ($badwordRules as $rule) {
            $word = mb_strtolower(trim((string)$rule['pattern']), 'UTF-8');
            if ($word === '') {
                continue;
            }
            $escaped = preg_quote($word, '/');
            $regex = '/(?<![a-z])' . $escaped . '(?![a-z])/iu';

            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && preg_match($regex, $haystack) === 1) {
                    return [
                        'type'            => 'badword',
                        'label'           => 'Kata Kasar / Tidak Pantas',
                        'matched_pattern' => $word,
                        'severity'        => (string)$rule['severity'],
                        'action_mode'     => (string)$rule['action_mode'],
                        'bk_poin'         => (int)$rule['bk_poin'],
                    ];
                }
            }
        }

        return null;
    }

    // ==========================================================================
    // HELPER DATABASE, NOTIFIKASI & FORMAT PESAN
    // ==========================================================================

    private function getOrRegisterGroup(string $groupJid, string $groupName = ''): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wa_groups WHERE group_jid = :jid LIMIT 1');
        $stmt->execute([':jid' => $groupJid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        $name = trim($groupName) !== '' ? mb_substr(trim($groupName), 0, 160, 'UTF-8') : ('Grup WA ' . substr($groupJid, 0, 16));
        $ins = $this->pdo->prepare(
            "INSERT IGNORE INTO wa_groups
                (group_jid, group_name, group_type, guard_enabled, sync_bk_enabled, notify_parent_enabled)
             VALUES
                (:jid, :name, 'kelas', 1, 1, 1)"
        );
        $ins->execute([':jid' => $groupJid, ':name' => $name]);

        $stmt->execute([':jid' => $groupJid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'group_jid'             => $groupJid,
            'group_name'            => $name,
            'guard_enabled'         => 1,
            'sync_bk_enabled'       => 1,
            'notify_parent_enabled' => 1,
            'quiet_start'           => null,
            'quiet_end'             => null,
        ];
    }

    private function resolveSenderContact(string $phoneE164, string $fallbackName): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wa_contacts_cache WHERE phone_e164 = :p LIMIT 1');
        $stmt->execute([':p' => $phoneE164]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'phone_e164'   => (string)$row['phone_e164'],
                'role'         => (string)$row['role'],
                'learn_ref_id' => $row['learn_ref_id'] !== null ? (int)$row['learn_ref_id'] : null,
                'display_name' => (string)($row['display_name'] ?: $fallbackName ?: $phoneE164),
                'extra'        => json_decode((string)($row['extra_json'] ?? '{}'), true) ?: [],
            ];
        }

        // Lookup sekali ke Ilmuna Learn agar siswa teridentifikasi untuk sinkron BK & ortu
        $resp = $this->learnClient->lookupContact($phoneE164);
        if ($resp['success'] && !empty($resp['data'])) {
            $d = $resp['data'];
            $role = in_array($d['role'] ?? '', ['admin', 'guru', 'guru_bk', 'orang_tua', 'siswa', 'guest'], true)
                ? (string)$d['role'] : 'guest';
            $refId = isset($d['learn_ref_id']) && is_numeric($d['learn_ref_id']) ? (int)$d['learn_ref_id'] : null;
            $name  = trim((string)($d['display_name'] ?? $d['nama'] ?? $fallbackName ?: $phoneE164));
            $extra = is_array($d['extra'] ?? null) ? $d['extra'] : [];
            if (isset($d['parent_phones']) && is_array($d['parent_phones'])) {
                $extra['parent_phones'] = $d['parent_phones'];
            }

            $up = $this->pdo->prepare(
                'INSERT INTO wa_contacts_cache (phone_e164, role, learn_ref_id, display_name, extra_json, synced_at)
                 VALUES (:p, :r, :ref, :n, :ex, NOW())
                 ON DUPLICATE KEY UPDATE role = VALUES(role), learn_ref_id = VALUES(learn_ref_id), display_name = VALUES(display_name), extra_json = VALUES(extra_json), synced_at = NOW()'
            );
            $up->execute([
                ':p'   => $phoneE164,
                ':r'   => $role,
                ':ref' => $refId,
                ':n'   => mb_substr($name, 0, 150, 'UTF-8'),
                ':ex'  => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return [
                'phone_e164'   => $phoneE164,
                'role'         => $role,
                'learn_ref_id' => $refId,
                'display_name' => $name,
                'extra'        => $extra,
            ];
        }

        return [
            'phone_e164'   => $phoneE164,
            'role'         => 'guest',
            'learn_ref_id' => null,
            'display_name' => $fallbackName !== '' ? $fallbackName : $phoneE164,
            'extra'        => [],
        ];
    }

    private function loadActiveRules(): array
    {
        $grouped = [
            'badword'        => [],
            'link_blacklist' => [],
            'link_whitelist' => [],
            'flood'          => [],
            'virtex'         => [],
        ];

        $rows = $this->pdo->query('SELECT * FROM wa_group_rules WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $type = (string)$row['rule_type'];
            if (isset($grouped[$type])) {
                $grouped[$type][] = $row;
            }
        }
        return $grouped;
    }

    private function countRecentStrikes(string $groupJid, string $senderPhone): int
    {
        $since = date('Y-m-d H:i:s', ($this->clock)() - (30 * 86400));
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM wa_violation_logs
             WHERE group_jid = :gjid AND sender_phone = :phone AND created_at >= :since'
        );
        $stmt->execute([':gjid' => $groupJid, ':phone' => $senderPhone, ':since' => $since]);
        return (int)$stmt->fetchColumn();
    }

    private function buildGroupModerationNotice(
        string $senderPhone,
        string $senderName,
        array $violation,
        int $strikeCount,
        int $maxStrikeKick,
        string $actionTaken
    ): string {
        $label = (string)$violation['label'];

        if ($actionTaken === 'deleted_and_kicked') {
            return "🛡️ *ILMUNA GROUP GUARD — TINDAKAN TEGAS*\n\n"
                . "Anggota @{$senderPhone} ({$senderName}) telah *dikeluarkan dari grup* dan pesannya dihapus otomatis.\n"
                . "• Jenis Pelanggaran : *{$label}*\n"
                . "• Status Strike     : *{$strikeCount}/{$maxStrikeKick}*\n\n"
                . '_Mari jaga etika dan keamanan komunikasi grup resmi sekolah._';
        }

        if ($actionTaken === 'deleted_and_warned') {
            return "⚠️ *ILMUNA GROUP GUARD — PERINGATAN STRIKE #{$strikeCount}/{$maxStrikeKick}*\n\n"
                . "Halo @{$senderPhone}, pesan Anda dihapus otomatis karena melanggar aturan grup.\n"
                . "• Pelanggaran : *{$label}*\n"
                . ($strikeCount >= ($maxStrikeKick - 1)
                    ? "• Catatan     : *Peringatan terakhir sebelum dikeluarkan otomatis dari grup.*\n"
                    : '')
                . "\n_Mohon menjaga sopan santun dan tata tertib grup sekolah._";
        }

        return "ℹ️ *ILMUNA GROUP GUARD — PENGINGAT (Strike #{$strikeCount}/{$maxStrikeKick})*\n\n"
            . "Halo @{$senderPhone}, mohon perhatikan tata tertib grup (*{$label}*). Pelanggaran berulang akan memicu penghapusan pesan hingga pengeluaran otomatis.";
    }

    private function notifyStudentParents(
        int $siswaId,
        array $studentContact,
        string $groupName,
        array $violation,
        int $strikeCount,
        string $actionTaken,
        bool $syncedToBk
    ): int {
        $parentPhones = array_values(array_filter(array_map(
            'wa_normalize_phone',
            (array)($studentContact['extra']['parent_phones'] ?? [])
        )));

        if ($parentPhones === []) {
            $resp = $this->learnClient->getStudentParentPhones($siswaId);
            if ($resp['success']) {
                $rawPhones = (array)($resp['data']['phones'] ?? $resp['data']['parent_phones'] ?? []);
                $parentPhones = array_values(array_filter(array_map('wa_normalize_phone', $rawPhones)));
            }
        }

        if ($parentPhones === []) {
            return 0;
        }

        $notified = 0;
        $studentName = (string)$studentContact['display_name'];
        $actionLabel = match ($actionTaken) {
            'deleted_and_kicked' => 'Pesan dihapus & dikeluarkan dari grup',
            'deleted_and_warned' => 'Pesan dihapus & diberi peringatan',
            default              => 'Diberi peringatan tertulis',
        };

        $body = "Assalamu'alaikum Bapak/Ibu Wali Murid.\n\n"
            . "Sistem *Ilmuna Group Guard* mendeteksi pelanggaran etika komunikasi oleh ananda *{$studentName}* di grup *{$groupName}*:\n"
            . "• Jenis Pelanggaran : *{$violation['label']}*\n"
            . "• Strike Grup       : *#{$strikeCount}*\n"
            . "• Tindakan Bot      : {$actionLabel}\n"
            . ($syncedToBk ? "• Status BK Learn   : *Tercatat otomatis (-{$violation['bk_poin']} poin disiplin)*\n" : '')
            . "\nMohon pendampingan dan pembinaan bersama di rumah.";

        foreach (array_unique($parentPhones) as $pPhone) {
            $res = $this->transport->sendButtons($pPhone, $body, [
                ['id' => 'MENU_ORTU_BK', 'title' => '⚠️ Lihat Catatan BK'],
                ['id' => 'MENU_MAIN',    'title' => '📋 Menu Utama'],
            ], [
                'header' => '🛡️ Pemberitahuan Pembinaan',
                'footer' => 'Ilmuna Learn · BK & Group Guard',
            ]);
            if (!empty($res['success'])) {
                $notified = 1;
            }
        }

        return $notified;
    }

    private function insertViolationLog(array $row): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wa_violation_logs
                (group_jid, sender_phone, sender_name, learn_siswa_id, violation_type,
                 matched_pattern, message_excerpt, strike_count, action_taken, synced_to_bk, parent_notified)
             VALUES
                (:gjid, :phone, :name, :siswa_id, :vtype, :pat, :excerpt, :strike, :action, :bk, :parent)'
        );
        $stmt->execute([
            ':gjid'     => $row['group_jid'],
            ':phone'    => $row['sender_phone'],
            ':name'     => mb_substr((string)$row['sender_name'], 0, 150, 'UTF-8'),
            ':siswa_id' => $row['learn_siswa_id'],
            ':vtype'    => $row['violation_type'],
            ':pat'      => mb_substr((string)$row['matched_pattern'], 0, 160, 'UTF-8'),
            ':excerpt'  => $row['message_excerpt'],
            ':strike'   => $row['strike_count'],
            ':action'   => $row['action_taken'],
            ':bk'       => $row['synced_to_bk'],
            ':parent'   => $row['parent_notified'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}
