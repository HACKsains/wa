<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — ANTI-CALL ENGINE (AUTO-REJECT, POLITE REPLY & SPAM AUTO-BLOCK)
 * ==============================================================================
 * Melindungi nomor WhatsApp resmi sekolah agar tidak dapat di-call:
 * 1. Menangkap event panggilan masuk (`voice` & `video`)
 * 2. Langsung memanggil `rejectCall()` via Multi-Device Bridge
 * 3. Menghitung frekuensi panggilan dalam jendela menit (default: >= 3x / 5 menit)
 * 4. Jika melewati ambang batas spam -> otomatis `blockContact()` & tandai
 *    `wa_contacts_cache.is_blocked = 1`
 * 5. Mengirim balasan sopan bertombol `[📋 Buka Menu Utama]` & `[📞 Hubungi Admin]`
 * 6. Mencatat seluruh riwayat panggilan ke `wa_call_logs`
 * ==============================================================================
 */

class AntiCallEngine
{
    private PDO $pdo;
    private TransportInterface $transport;

    public function __construct(PDO $pdo, TransportInterface $transport)
    {
        $this->pdo       = $pdo;
        $this->transport = $transport;
    }

    /**
     * Tangani event panggilan suara/video masuk dari WebhookProcessor
     */
    public function handleIncomingCall(array $event): array
    {
        $callerPhone = wa_normalize_phone((string)($event['sender_phone'] ?? $event['chat_jid'] ?? ''));
        $callId      = trim((string)($event['call_id'] ?? ''));
        $callType    = strtolower((string)($event['call_type'] ?? 'voice')) === 'video' ? 'video' : 'voice';

        if ($callerPhone === '') {
            return ['handled' => false, 'reason' => 'invalid_caller_phone'];
        }

        $enabled = wa_get_setting('anti_call_enabled', '1', $this->pdo) === '1';
        if (!$enabled) {
            $this->logCall($callerPhone, $callType, 0, 0, 0);
            return [
                'handled'      => true,
                'rejected'     => false,
                'auto_replied' => false,
                'blocked'      => false,
                'reason'       => 'anti_call_disabled',
            ];
        }

        // 1. Tolak panggilan seketika melalui BridgeMdDriver
        $rejectRes = ['success' => false];
        if ($callId !== '') {
            $rejectRes = $this->transport->rejectCall($callId, $callerPhone);
        }
        $wasRejected = !empty($rejectRes['success']) ? 1 : 0;

        // 2. Hitung frekuensi panggilan spam dalam jendela waktu (default 5 menit)
        $spamLimit  = max(1, (int)wa_get_setting('anti_call_spam_limit', '3', $this->pdo));
        $windowMins = max(1, (int)wa_get_setting('anti_call_spam_window_minutes', '5', $this->pdo));
        $recentCalls = $this->countRecentCalls($callerPhone, $windowMins) + 1;

        $triggeredBlock = 0;
        $autoReplied    = 0;
        $blockRes       = null;
        $replyRes       = null;

        // 3. Cek eskalasi spam call -> Auto-Block
        if ($recentCalls >= $spamLimit) {
            $alreadyBlocked = $this->isAlreadyBlocked($callerPhone);
            $blockRes = $this->transport->blockContact($callerPhone);
            $this->markContactBlocked($callerPhone, (string)($event['sender_name'] ?? ''));
            $triggeredBlock = 1;

            if (!$alreadyBlocked) {
                $notice = "⛔ Nomor Anda terdeteksi melakukan panggilan berulang ({$recentCalls}× dalam {$windowMins} menit) ke nomor bot resmi sekolah.\n\n"
                    . 'Sesuai kebijakan keamanan Anti-Call, akses nomor Anda dibatasi otomatis. Silakan hubungi Admin Sekolah jika memerlukan pemulihan.';
                $replyRes = $this->transport->sendText($callerPhone, $notice);
                $autoReplied = !empty($replyRes['success']) ? 1 : 0;
            }
        } else {
            // 4. Kirim balasan sopan otomatis bertombol interaktif
            $menu = $this->loadAntiCallReplyMenu();
            $replyRes = $this->transport->sendButtons($callerPhone, $menu['body'], $menu['buttons'], [
                'header' => $menu['header'],
                'footer' => $menu['footer'],
            ]);
            $autoReplied = !empty($replyRes['success']) ? 1 : 0;

            // Simpan peta tombol di sesi agar klik tombol / Poll tetap bekerja 100% tanpa ketik
            $this->storeSessionButtonMap($callerPhone, $menu['buttons']);
        }

        // 5. Catat log panggilan ke tabel wa_call_logs
        $logId = $this->logCall($callerPhone, $callType, $wasRejected, $autoReplied, $triggeredBlock);

        return [
            'handled'            => true,
            'log_id'             => $logId,
            'caller_phone'       => $callerPhone,
            'call_type'          => $callType,
            'rejected'           => $wasRejected === 1,
            'auto_replied'       => $autoReplied === 1,
            'blocked'            => $triggeredBlock === 1,
            'calls_in_window'    => $recentCalls,
            'spam_limit'         => $spamLimit,
            'reject_result'      => $rejectRes,
            'block_result'       => $blockRes,
            'reply_result'       => $replyRes,
        ];
    }

    /**
     * Buka blokir nomor dari Admin Panel (`dashboard/anti-call.php`)
     */
    public function unblockContact(string $phoneRaw): bool
    {
        $phone = wa_normalize_phone($phoneRaw);
        if ($phone === '') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE wa_contacts_cache SET is_blocked = 0 WHERE phone_e164 = :phone'
        );
        return $stmt->execute([':phone' => $phone]);
    }

    private function countRecentCalls(string $callerPhone, int $windowMinutes): int
    {
        $since = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM wa_call_logs WHERE caller_phone = :phone AND created_at >= :since'
        );
        $stmt->execute([':phone' => $callerPhone, ':since' => $since]);
        return (int)$stmt->fetchColumn();
    }

    private function isAlreadyBlocked(string $callerPhone): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_blocked FROM wa_contacts_cache WHERE phone_e164 = :phone LIMIT 1'
        );
        $stmt->execute([':phone' => $callerPhone]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function markContactBlocked(string $callerPhone, string $callerName = ''): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO wa_contacts_cache (phone_e164, role, display_name, is_blocked, synced_at)
             VALUES (:phone, 'guest', :name, 1, NOW())
             ON DUPLICATE KEY UPDATE is_blocked = 1"
        );
        $stmt->execute([
            ':phone' => $callerPhone,
            ':name'  => $callerName !== '' ? mb_substr($callerName, 0, 150, 'UTF-8') : 'Spam Caller',
        ]);
    }

    private function logCall(
        string $callerPhone,
        string $callType,
        int $wasRejected,
        int $autoReplied,
        int $triggeredBlock
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wa_call_logs
                (caller_phone, call_type, was_rejected, auto_replied, triggered_block)
             VALUES
                (:phone, :ctype, :rej, :rep, :blk)'
        );
        $stmt->execute([
            ':phone' => $callerPhone,
            ':ctype' => $callType,
            ':rej'   => $wasRejected,
            ':rep'   => $autoReplied,
            ':blk'   => $triggeredBlock,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function loadAntiCallReplyMenu(): array
    {
        $customText = (string)wa_get_setting(
            'anti_call_reply_text',
            'Mohon maaf, nomor WhatsApp resmi sekolah ini hanya melayani pesan teks & tombol interaktif dan tidak dapat menerima panggilan suara/video. Silakan pilih menu di bawah ini.',
            $this->pdo
        );

        $stmt = $this->pdo->prepare(
            "SELECT header_text, body_text, footer_text, buttons_json
             FROM wa_button_menus WHERE menu_code = 'MENU_ANTI_CALL_REPLY' AND is_active = 1 LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $buttons = [
            ['id' => 'MENU_MAIN',          'title' => '📋 Buka Menu Utama'],
            ['id' => 'MENU_CONTACT_ADMIN', 'title' => '📞 Hubungi Admin'],
        ];

        if ($row) {
            $decoded = json_decode((string)$row['buttons_json'], true);
            if (is_array($decoded) && $decoded !== []) {
                $buttons = $decoded;
            }
            return [
                'header'  => (string)($row['header_text'] ?? 'Panggilan Tidak Didukung'),
                'body'    => $customText !== '' ? $customText : (string)$row['body_text'],
                'footer'  => (string)($row['footer_text'] ?? 'Ilmuna WA Bot · Proteksi Anti-Call'),
                'buttons' => $buttons,
            ];
        }

        return [
            'header'  => 'Panggilan Tidak Didukung',
            'body'    => $customText,
            'footer'  => 'Ilmuna WA Bot · Proteksi Anti-Call',
            'buttons' => $buttons,
        ];
    }

    private function storeSessionButtonMap(string $phoneE164, array $buttons): void
    {
        $map = [];
        foreach ($buttons as $b) {
            if (!empty($b['id']) && !empty($b['title'])) {
                $map[] = ['id' => (string)$b['id'], 'title' => (string)$b['title']];
            }
        }
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        $ctx = json_encode(['last_button_map' => $map], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $this->pdo->prepare(
            "INSERT INTO wa_sessions (phone_e164, current_state, context_json, expires_at)
             VALUES (:phone, 'ANTI_CALL_REPLIED', :ctx, :exp)
             ON DUPLICATE KEY UPDATE
                current_state = VALUES(current_state),
                context_json = VALUES(context_json),
                expires_at = VALUES(expires_at)"
        );
        $stmt->execute([':phone' => $phoneE164, ':ctx' => $ctx, ':exp' => $expiresAt]);
    }
}
