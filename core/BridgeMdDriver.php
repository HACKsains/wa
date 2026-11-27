<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — SELF-HOSTED & GATEWAY MULTI-DEVICE BRIDGE DRIVER
 * ==============================================================================
 * Adapter HTTP JSON universal untuk koneksi WhatsApp Multi-Device (TANPA harus
 * punya Node.js di hosting cPanel!):
 * - generic : Ilmuna Bridge (Baileys / custom HTTP gateway)
 * - gowa    : Go-WhatsApp Single Binary (whatsmeow — 1 binary Linux/Win, ZERO Node.js!)
 * - waha    : WAHA (WhatsApp HTTP API)
 * - whapi   : Whapi.cloud (Managed Cloud REST API — 100% PHP cURL di cPanel)
 * - fonnte  : Fonnte Gateway (Managed REST API — 100% PHP cURL di cPanel)
 *
 * Fitur lengkap:
 * - Grup WhatsApp (kirim pesan, mention, hapus pesan, kick)
 * - Proteksi Anti-Call (auto-reject voice/video + block kontak)
 * - QR Code & Pairing Code (8 digit) langsung via PHP
 * - Interactive Buttons / List Menu dengan fallback otomatis Poll / Formatted Text
 * ==============================================================================
 */

class BridgeMdDriver implements TransportInterface
{
    private string $baseUrl;
    private string $apiKey;
    private string $session;
    private string $webhookSecret;
    private string $flavor;
    private bool $dryRun;

    /** @var callable|null */
    private $httpClient;

    public function __construct(array $config = [], ?callable $httpClient = null)
    {
        $this->baseUrl       = rtrim((string)($config['base_url'] ?? $config['endpoint_url'] ?? WA_BRIDGE_BASE_URL), '/');
        $this->apiKey        = trim((string)($config['api_key'] ?? $config['api_token'] ?? WA_BRIDGE_API_KEY));
        $this->session       = trim((string)($config['session'] ?? WA_BRIDGE_SESSION));
        $this->webhookSecret = trim((string)($config['webhook_secret'] ?? WA_BRIDGE_WEBHOOK_SECRET));
        $this->flavor        = strtolower(trim((string)($config['flavor'] ?? WA_BRIDGE_FLAVOR)));
        $this->dryRun        = (bool)($config['dry_run'] ?? WA_DRY_RUN)
            || str_starts_with($this->baseUrl, 'dry-run://');
        $this->httpClient    = $httpClient;
    }

    public function getDriverName(): string
    {
        return 'md_bridge';
    }

    public function supportsGroups(): bool
    {
        return true;
    }

    public function supportsCallReject(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' || $this->dryRun;
    }

    public function getWebhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public function getFlavor(): string
    {
        return $this->flavor;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Ambil QR Code (Data URI base64 PNG / teks QR) langsung lewat PHP
     */
    public function getQrCode(): array
    {
        if ($this->dryRun) {
            return [
                'success'     => true,
                'driver'      => 'md_bridge',
                'status'      => 'qr_pending',
                'qr_data_url' => null,
                'qr_raw'      => 'DRY_RUN_QR_TOKEN',
                'http_status' => 200,
                'error'       => null,
            ];
        }

        switch ($this->flavor) {
            case 'gowa':
                $path = '/app/login';
                break;
            case 'waha':
                $path = '/api/' . rawurlencode($this->session) . '/auth/qr?format=image';
                break;
            case 'whapi':
                $path = '/users/login';
                break;
            default:
                $path = '/api/sessions/' . rawurlencode($this->session) . '/qr';
                break;
        }

        $resp = $this->httpRequest('GET', $this->baseUrl . $path, null);
        $data = is_array($resp['data']) ? $resp['data'] : [];

        return [
            'success'     => $resp['ok'],
            'driver'      => 'md_bridge',
            'status'      => (string)($data['status'] ?? ($resp['ok'] ? 'qr_pending' : 'disconnected')),
            'qr_data_url' => $data['qr_data_url'] ?? $data['qr'] ?? ($data['results']['qr_link'] ?? null),
            'qr_raw'      => $data['qr_raw'] ?? $data['code'] ?? null,
            'http_status' => $resp['http_status'],
            'raw'         => $resp['data'],
            'error'       => $resp['error'],
        ];
    }

    /**
     * Minta 8-digit Pairing Code (tautkan WA via nomor HP tanpa kamera scan QR)
     */
    public function requestPairingCode(string $phoneNumber): array
    {
        $phone = wa_normalize_phone($phoneNumber);
        if ($phone === '') {
            return $this->errorResult('Nomor HP untuk Pairing Code tidak valid (gunakan format 08xx / 628xx).');
        }

        if ($this->dryRun) {
            return [
                'success'      => true,
                'driver'       => 'md_bridge',
                'pairing_code' => 'ABCD-1234',
                'phone'        => $phone,
                'http_status'  => 200,
                'error'        => null,
            ];
        }

        if ($this->flavor === 'gowa') {
            $resp = $this->httpRequest('GET', $this->baseUrl . '/app/login-with-code?phone=' . rawurlencode($phone), null);
            $code = is_array($resp['data']) ? ($resp['data']['results']['pair_code'] ?? $resp['data']['code'] ?? null) : null;
        } else {
            $resp = $this->httpRequest('POST', $this->baseUrl . '/api/sessions/' . rawurlencode($this->session) . '/pairing-code', [
                'session' => $this->session,
                'phone'   => $phone,
            ]);
            $code = is_array($resp['data']) ? ($resp['data']['pairing_code'] ?? $resp['data']['code'] ?? null) : null;
        }

        return [
            'success'      => $resp['ok'] && !empty($code),
            'driver'       => 'md_bridge',
            'pairing_code' => is_string($code) ? $code : null,
            'phone'        => $phone,
            'http_status'  => $resp['http_status'],
            'raw'          => $resp['data'],
            'error'        => $resp['error'],
        ];
    }

    public function sendText(string $to, string $text, array $options = []): array
    {
        $chatId = $this->formatChatId($to);
        if ($chatId === '') {
            return $this->errorResult('Tujuan chatId tidak valid.');
        }

        // Adaptasi otomatis untuk flavor eksternal (tanpa Node.js di hosting)
        if ($this->flavor === 'gowa') {
            return $this->dispatchBridge('POST', '/send/message', [
                'phone'   => $chatId,
                'message' => $text,
                'reply_message_id' => $options['reply_to'] ?? null,
            ]);
        }

        if ($this->flavor === 'fonnte') {
            return $this->dispatchBridge('POST', '/send', [
                'target'  => wa_is_group_jid($chatId) ? $chatId : wa_normalize_phone($to),
                'message' => $text,
            ]);
        }

        if ($this->flavor === 'whapi') {
            return $this->dispatchBridge('POST', '/messages/text', [
                'to'   => $chatId,
                'body' => $text,
            ]);
        }

        $payload = [
            'session' => $this->session,
            'chatId'  => $chatId,
            'text'    => $text,
        ];

        if (!empty($options['reply_to'])) {
            $payload['reply_to'] = (string)$options['reply_to'];
        }
        if (!empty($options['mentions']) && is_array($options['mentions'])) {
            $payload['mentions'] = array_values(array_map(
                fn($m) => $this->formatChatId((string)$m),
                $options['mentions']
            ));
        }

        return $this->dispatchBridge('POST', '/api/sendText', $payload);
    }

    public function sendButtons(string $to, string $body, array $buttons, array $options = []): array
    {
        $chatId = $this->formatChatId($to);
        if ($chatId === '') {
            return $this->errorResult('Tujuan chatId tidak valid untuk sendButtons.');
        }

        $cleanButtons = [];
        foreach (array_values($buttons) as $idx => $btn) {
            if (!is_array($btn) || empty($btn['title'])) {
                continue;
            }
            $cleanButtons[] = [
                'id'    => (string)($btn['id'] ?? ('BTN_' . ($idx + 1))),
                'title' => wa_truncate((string)$btn['title'], 24),
            ];
        }

        if ($cleanButtons === []) {
            return $this->sendText($chatId, $body, $options);
        }

        $payload = [
            'session' => $this->session,
            'chatId'  => $chatId,
            'header'  => isset($options['header']) ? (string)$options['header'] : null,
            'body'    => $body,
            'footer'  => (string)($options['footer'] ?? 'Ilmuna Learn · Asisten Resmi Sekolah'),
            'buttons' => $cleanButtons,
        ];

        $resp = $this->dispatchBridge('POST', '/api/sendButtons', $payload);
        if ($resp['success']) {
            return $resp;
        }

        // Fallback otomatis ke Interactive Poll bila bridge/WA MD lama menolak native buttons (400/404/422/501)
        if (in_array($resp['http_status'], [400, 404, 422, 501], true)) {
            $pollPayload = [
                'session' => $this->session,
                'chatId'  => $chatId,
                'poll'    => [
                    'name'            => wa_truncate($body, 255),
                    'options'         => array_map(static fn($b) => $b['title'], array_slice($cleanButtons, 0, 12)),
                    'multipleAnswers' => false,
                ],
                'buttonMap' => $cleanButtons,
            ];
            $pollResp = $this->dispatchBridge('POST', '/api/sendPoll', $pollPayload);
            if ($pollResp['success']) {
                $pollResp['fallback'] = 'poll';
                return $pollResp;
            }

            // Fallback terakhir: kirim teks terformat rapi dengan daftar tombol
            $lines = [];
            if (!empty($options['header'])) {
                $lines[] = '*' . trim((string)$options['header']) . '*';
                $lines[] = '';
            }
            $lines[] = $body;
            $lines[] = '';
            foreach ($cleanButtons as $i => $b) {
                $lines[] = ($i + 1) . '. ' . $b['title'];
            }
            if (!empty($options['footer'])) {
                $lines[] = '';
                $lines[] = '_' . trim((string)$options['footer']) . '_';
            }
            $txtResp = $this->sendText($chatId, implode("\n", $lines), $options);
            $txtResp['fallback'] = 'formatted_buttons';
            return $txtResp;
        }

        return $resp;
    }

    public function sendListMenu(string $to, string $body, string $buttonLabel, array $sections, array $options = []): array
    {
        $chatId = $this->formatChatId($to);
        if ($chatId === '') {
            return $this->errorResult('Tujuan chatId tidak valid untuk sendListMenu.');
        }

        $payload = [
            'session'     => $this->session,
            'chatId'      => $chatId,
            'header'      => isset($options['header']) ? (string)$options['header'] : null,
            'body'        => $body,
            'footer'      => (string)($options['footer'] ?? 'Ilmuna Learn · Asisten Resmi Sekolah'),
            'buttonLabel' => $buttonLabel !== '' ? $buttonLabel : 'Pilih Menu',
            'sections'    => $sections,
        ];

        $resp = $this->dispatchBridge('POST', '/api/sendList', $payload);
        if ($resp['success']) {
            return $resp;
        }

        // Fallback ke sendButtons (yang otomatis dapat jatuh ke poll/text) bila bridge belum support sendList
        if (in_array($resp['http_status'], [400, 404, 422, 501], true)) {
            $flatButtons = [];
            foreach ($sections as $sec) {
                foreach ((array)($sec['rows'] ?? []) as $row) {
                    if (!empty($row['title'])) {
                        $flatButtons[] = [
                            'id'    => (string)($row['id'] ?? ''),
                            'title' => (string)$row['title'],
                        ];
                    }
                }
            }
            $fb = $this->sendButtons($chatId, $body, $flatButtons, $options);
            $fb['fallback'] = $fb['fallback'] ?? 'list_to_buttons';
            return $fb;
        }

        return $resp;
    }

    public function sendCtaUrl(string $to, string $body, string $buttonText, string $url, array $options = []): array
    {
        $chatId = $this->formatChatId($to);
        if ($chatId === '') {
            return $this->errorResult('Tujuan chatId tidak valid untuk sendCtaUrl.');
        }

        $payload = [
            'session' => $this->session,
            'chatId'  => $chatId,
            'header'  => isset($options['header']) ? (string)$options['header'] : null,
            'body'    => $body,
            'footer'  => (string)($options['footer'] ?? 'Ilmuna Learn · Asisten Resmi Sekolah'),
            'buttons' => [
                [
                    'type'  => 'url',
                    'title' => wa_truncate($buttonText, 24),
                    'url'   => trim($url),
                ],
            ],
        ];

        $resp = $this->dispatchBridge('POST', '/api/sendButtons', $payload);
        if ($resp['success']) {
            return $resp;
        }

        if (in_array($resp['http_status'], [400, 404, 422, 501], true)) {
            $fallbackText = trim($body) . "\n\n🔗 *{$buttonText}:*\n" . trim($url);
            $txtResp = $this->sendText($chatId, $fallbackText, $options);
            $txtResp['fallback'] = 'cta_link_text';
            return $txtResp;
        }

        return $resp;
    }

    public function sendMedia(string $to, string $mediaType, string $mediaUrl, string $caption = '', array $options = []): array
    {
        $chatId = $this->formatChatId($to);
        if ($chatId === '') {
            return $this->errorResult('Tujuan chatId tidak valid untuk sendMedia.');
        }

        $payload = [
            'session'   => $this->session,
            'chatId'    => $chatId,
            'mediaType' => strtolower(trim($mediaType)),
            'mediaUrl'  => trim($mediaUrl),
            'caption'   => $caption,
            'filename'  => $options['filename'] ?? null,
        ];

        return $this->dispatchBridge('POST', '/api/sendMedia', $payload);
    }

    public function deleteGroupMessage(string $groupJid, string $messageId, ?string $participantJid = null): array
    {
        if (!wa_is_group_jid($groupJid) || trim($messageId) === '') {
            return $this->errorResult('groupJid atau messageId tidak valid untuk deleteGroupMessage.');
        }

        if ($this->flavor === 'gowa') {
            return $this->dispatchBridge('POST', '/message/' . rawurlencode($messageId) . '/revoke', [
                'phone' => $groupJid,
            ]);
        }

        if ($this->flavor === 'whapi') {
            return $this->dispatchBridge('DELETE', '/messages/' . rawurlencode($messageId), []);
        }

        $payload = [
            'session'        => $this->session,
            'groupJid'       => $groupJid,
            'messageId'      => $messageId,
            'participantJid' => $participantJid !== null ? $this->formatChatId($participantJid) : null,
        ];

        return $this->dispatchBridge('POST', '/api/groups/deleteMessage', $payload);
    }

    public function kickGroupMember(string $groupJid, string $participantPhoneOrJid): array
    {
        if (!wa_is_group_jid($groupJid)) {
            return $this->errorResult('groupJid harus berformat @g.us.');
        }

        $participant = $this->formatChatId($participantPhoneOrJid);
        if ($participant === '' || wa_is_group_jid($participant)) {
            return $this->errorResult('Nomor anggota grup yang akan dikeluarkan tidak valid.');
        }

        if ($this->flavor === 'gowa') {
            return $this->dispatchBridge('POST', '/group/participants/remove', [
                'group_id'     => $groupJid,
                'participants' => [wa_normalize_phone($participantPhoneOrJid)],
            ]);
        }

        if ($this->flavor === 'whapi') {
            return $this->dispatchBridge('DELETE', '/groups/' . rawurlencode($groupJid) . '/participants', [
                'participants' => [wa_normalize_phone($participantPhoneOrJid)],
            ]);
        }

        return $this->dispatchBridge('POST', '/api/groups/kick', [
            'session'      => $this->session,
            'groupJid'     => $groupJid,
            'participants' => [$participant],
        ]);
    }

    public function rejectCall(string $callId, string $callerPhoneOrJid): array
    {
        $callerJid = $this->formatChatId($callerPhoneOrJid);
        if (trim($callId) === '' || $callerJid === '') {
            return $this->errorResult('callId atau caller tidak valid untuk rejectCall.');
        }

        return $this->dispatchBridge('POST', '/api/calls/reject', [
            'session' => $this->session,
            'callId'  => $callId,
            'from'    => $callerJid,
        ]);
    }

    public function blockContact(string $phoneOrJid): array
    {
        $contactJid = $this->formatChatId($phoneOrJid);
        if ($contactJid === '' || wa_is_group_jid($contactJid)) {
            return $this->errorResult('Nomor kontak yang akan diblokir tidak valid.');
        }

        return $this->dispatchBridge('POST', '/api/contacts/block', [
            'session' => $this->session,
            'chatId'  => $contactJid,
        ]);
    }

    public function unblockContact(string $phoneOrJid): array
    {
        $contactJid = $this->formatChatId($phoneOrJid);
        if ($contactJid === '' || wa_is_group_jid($contactJid)) {
            return $this->errorResult('Nomor kontak yang akan dibuka blokirnya tidak valid.');
        }

        return $this->dispatchBridge('POST', '/api/contacts/unblock', [
            'session' => $this->session,
            'chatId'  => $contactJid,
        ]);
    }

    public function healthCheck(): array
    {
        if ($this->dryRun) {
            return [
                'success'     => true,
                'driver'      => 'md_bridge',
                'status'      => 'connected',
                'http_status' => 200,
                'dry_run'     => true,
                'error'       => null,
            ];
        }

        switch ($this->flavor) {
            case 'gowa':
                $path = '/app/devices';
                break;
            case 'fonnte':
                $path = '/device';
                break;
            case 'whapi':
                $path = '/health';
                break;
            default:
                $path = '/api/sessions/' . rawurlencode($this->session) . '/status';
                break;
        }
        $method = $this->flavor === 'fonnte' ? 'POST' : 'GET';
        $resp = $this->httpRequest($method, $this->baseUrl . $path, null);
        $rawStatus = strtolower((string)($resp['data']['status'] ?? $resp['data']['device_status'] ?? ''));

        if ($resp['ok'] && in_array($rawStatus, ['connected', 'working', 'open', 'ready', 'connect', 'ok'], true)) {
            $mappedStatus = 'connected';
        } elseif ($resp['ok'] && $this->flavor === 'gowa' && !empty($resp['data']['results'])) {
            $mappedStatus = 'connected';
        } elseif ($resp['ok'] && in_array($rawStatus, ['qr', 'scan_qr_code', 'qr_pending'], true)) {
            $mappedStatus = 'qr_pending';
        } else {
            $mappedStatus = 'disconnected';
        }

        return [
            'success'     => $resp['ok'],
            'driver'      => 'md_bridge',
            'status'      => $mappedStatus,
            'http_status' => $resp['http_status'],
            'raw'         => $resp['data'],
            'error'       => $resp['error'],
        ];
    }

    /**
     * Verifikasi signature HMAC-SHA256 webhook dari MD Bridge
     */
    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, ?string $secretOverride = null): bool
    {
        $secret = $secretOverride !== null && $secretOverride !== '' ? $secretOverride : $this->webhookSecret;
        if ($secret === '') {
            return false;
        }
        $provided = strtolower(trim($signatureHeader));
        if (str_starts_with($provided, 'sha256=')) {
            $provided = substr($provided, 7);
        }
        if ($provided === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $provided);
    }

    /**
     * Normalisasi payload webhook MD Bridge (WAHA / Evolution / Go-WA / Generic JSON)
     * menjadi daftar event kanonis untuk WebhookProcessor
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseWebhookPayload(array $payload): array
    {
        $eventName = strtolower(trim((string)($payload['event'] ?? $payload['type'] ?? 'message')));
        $data = isset($payload['payload']) && is_array($payload['payload'])
            ? $payload['payload']
            : (isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload);

        // 1. Event Panggilan Masuk (Anti-Call)
        if (in_array($eventName, ['call.offer', 'call', 'incoming_call', 'call.received'], true)) {
            $callId = (string)($data['call_id'] ?? $data['callId'] ?? $data['id'] ?? ('call_' . bin2hex(random_bytes(6))));
            $fromRaw = (string)($data['from'] ?? $data['caller'] ?? $data['caller_jid'] ?? $data['caller_phone'] ?? '');
            $callerPhone = wa_normalize_phone($fromRaw);
            if ($callerPhone === '') {
                return [];
            }

            $isVideo = (bool)($data['isVideo'] ?? $data['is_video'] ?? false)
                || strtolower((string)($data['call_type'] ?? $data['mediaType'] ?? 'voice')) === 'video';

            return [[
                'provider'     => 'md_bridge',
                'event_id'     => 'call:' . $callId,
                'event_type'   => 'incoming_call',
                'call_id'      => $callId,
                'call_type'    => $isVideo ? 'video' : 'voice',
                'is_group'     => false,
                'chat_jid'     => $callerPhone . '@s.whatsapp.net',
                'sender_jid'   => $callerPhone . '@s.whatsapp.net',
                'sender_phone' => $callerPhone,
                'sender_name'  => (string)($data['caller_name'] ?? $data['pushName'] ?? ''),
                'timestamp'    => (int)($data['timestamp'] ?? time()),
                'raw'          => $data,
            ]];
        }

        // 2. Event Status Koneksi Session / Device
        if (in_array($eventName, ['session.status', 'connection.update', 'device.status'], true)) {
            $rawStatus = strtolower((string)($data['status'] ?? $data['state'] ?? 'disconnected'));
            if (in_array($rawStatus, ['connected', 'working', 'open', 'ready'], true)) {
                $mapped = 'connected';
            } elseif (in_array($rawStatus, ['qr', 'qr_pending', 'scan_qr_code'], true)) {
                $mapped = 'qr_pending';
            } else {
                $mapped = 'disconnected';
            }

            return [[
                'provider'     => 'md_bridge',
                'event_id'     => (string)($payload['id'] ?? ('status:' . $mapped . ':' . time())),
                'event_type'   => 'device_status',
                'status'       => $mapped,
                'session'      => (string)($payload['session'] ?? $data['session'] ?? WA_BRIDGE_SESSION),
                'phone_number' => wa_normalize_phone((string)($data['me']['id'] ?? $data['phone'] ?? '')),
                'timestamp'    => (int)($data['timestamp'] ?? time()),
                'raw'          => $data,
            ]];
        }

        // 3. Event Pesan Masuk (Private / Grup / Button Reply / List Reply / Poll Vote)
        if (!empty($data['fromMe']) || !empty($data['from_me'])) {
            return []; // Abaikan pesan yang dikirim oleh bot sendiri
        }

        $chatJid = (string)($data['chatId'] ?? $data['group_jid'] ?? $data['remoteJid'] ?? $data['from'] ?? '');
        $isGroup = wa_is_group_jid($chatJid) || $eventName === 'group.message';

        if ($isGroup) {
            $senderRaw = (string)($data['participant'] ?? $data['author'] ?? $data['sender'] ?? $data['sender_phone'] ?? '');
        } else {
            $senderRaw = (string)($data['from'] ?? $data['sender'] ?? $data['sender_phone'] ?? $chatJid);
        }

        $senderPhone = wa_normalize_phone($senderRaw);
        if ($senderPhone === '' && !$isGroup) {
            return [];
        }

        $msgId = (string)($data['id'] ?? $data['message_id'] ?? $data['messageId'] ?? ('md_' . bin2hex(random_bytes(8))));
        $messageType = strtolower((string)($data['message_type'] ?? $data['type'] ?? 'text'));
        $text = (string)($data['body'] ?? $data['text'] ?? $data['caption'] ?? '');
        $buttonId = isset($data['button_id']) ? (string)$data['button_id'] : null;
        $buttonTitle = isset($data['button_title']) ? (string)$data['button_title'] : null;

        // Normalisasi event klik tombol / list / poll dari berbagai bridge
        if ($eventName === 'button.reply' || $messageType === 'button_reply' || isset($data['selectedButtonId'])) {
            $messageType = 'button_reply';
            $buttonId    = (string)($data['selectedButtonId'] ?? $data['button_id'] ?? $data['buttonId'] ?? $buttonId ?? '');
            $buttonTitle = (string)($data['selectedDisplayText'] ?? $data['button_title'] ?? $text);
            $text        = $buttonTitle;
        } elseif ($eventName === 'list.reply' || $messageType === 'list_reply' || isset($data['selectedRowId'])) {
            $messageType = 'list_reply';
            $buttonId    = (string)($data['selectedRowId'] ?? $data['list_row_id'] ?? $data['button_id'] ?? $buttonId ?? '');
            $buttonTitle = (string)($data['title'] ?? $data['button_title'] ?? $text);
            $text        = $buttonTitle;
        } elseif ($eventName === 'poll.vote' || $messageType === 'poll_vote') {
            $messageType = 'poll_vote';
            $selected = (array)($data['selectedOptions'] ?? $data['selected_options'] ?? []);
            $firstOpt = (string)($selected[0] ?? $text);
            $buttonId    = (string)($data['button_id'] ?? $firstOpt);
            $buttonTitle = $firstOpt;
            $text        = $firstOpt;
        }

        $normalizedChatJid = $isGroup
            ? $chatJid
            : ($senderPhone . '@s.whatsapp.net');

        return [[
            'provider'     => 'md_bridge',
            'event_id'     => $msgId,
            'event_type'   => $isGroup ? 'group_message' : 'private_message',
            'is_group'     => $isGroup,
            'chat_jid'     => $normalizedChatJid,
            'group_jid'    => $isGroup ? $normalizedChatJid : null,
            'sender_jid'   => $senderPhone !== '' ? ($senderPhone . '@s.whatsapp.net') : $senderRaw,
            'sender_phone' => $senderPhone,
            'sender_name'  => (string)($data['sender_name'] ?? $data['pushName'] ?? $data['notifyName'] ?? ''),
            'message_id'   => $msgId,
            'message_type' => $messageType,
            'text'         => $text,
            'button_id'    => $buttonId,
            'button_title' => $buttonTitle,
            'timestamp'    => (int)($data['timestamp'] ?? time()),
            'raw'          => $data,
        ]];
    }

    private function formatChatId(string $to): string
    {
        $trimmed = trim($to);
        if ($trimmed === '') {
            return '';
        }
        if (wa_is_group_jid($trimmed)) {
            return $trimmed;
        }
        $phone = wa_normalize_phone($trimmed);
        if ($phone === '') {
            return '';
        }
        $suffix = $this->flavor === 'waha' ? 'c.us' : 's.whatsapp.net';
        return $phone . '@' . $suffix;
    }

    private function dispatchBridge(string $method, string $path, array $payload): array
    {
        $url = $this->baseUrl . $path;

        if ($this->dryRun) {
            return [
                'success'     => true,
                'driver'      => 'md_bridge',
                'message_id'  => 'md.dryrun.' . bin2hex(random_bytes(6)),
                'http_status' => 200,
                'raw'         => ['dry_run' => true, 'url' => $url, 'payload' => $payload],
                'error'       => null,
                'fallback'    => null,
            ];
        }

        if ($this->baseUrl === '') {
            return $this->errorResult('Endpoint URL Self-Hosted MD Bridge belum dikonfigurasi.');
        }

        $resp = $this->httpRequest($method, $url, $payload);
        $msgId = null;
        if (is_array($resp['data'])) {
            $msgId = $resp['data']['id']
                ?? $resp['data']['messageId']
                ?? ($resp['data']['key']['id'] ?? null);
        }

        return [
            'success'     => $resp['ok'],
            'driver'      => 'md_bridge',
            'message_id'  => is_string($msgId) ? $msgId : null,
            'http_status' => $resp['http_status'],
            'raw'         => $resp['data'],
            'error'       => $resp['error'],
            'fallback'    => null,
        ];
    }

    private function httpRequest(string $method, string $url, ?array $payload): array
    {
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ];
        if ($this->apiKey !== '') {
            if ($this->flavor === 'fonnte') {
                $headers[] = 'Authorization: ' . $this->apiKey;
            } else {
                $headers[] = 'X-Api-Key: ' . $this->apiKey;
                $headers[] = 'Authorization: Bearer ' . $this->apiKey;
            }
        }

        if ($this->httpClient !== null) {
            return ($this->httpClient)($method, $url, $headers, $payload);
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'Ekstensi PHP cURL tidak aktif.'];
        }

        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $isLocal = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => $isLocal ? 1 : 5,
            CURLOPT_TIMEOUT        => $isLocal ? 3 : 15,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $body = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'cURL error: ' . $curlErr];
        }

        $decoded = json_decode((string)$body, true);
        $ok = $status >= 200 && $status < 300;
        $errMsg = null;
        if (!$ok) {
            $errMsg = is_array($decoded) && isset($decoded['error'])
                ? (is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']))
                : "HTTP {$status}: " . mb_substr((string)$body, 0, 200);
        }

        return [
            'ok'          => $ok,
            'http_status' => $status,
            'data'        => is_array($decoded) ? $decoded : $body,
            'error'       => $errMsg,
        ];
    }

    private function errorResult(string $message): array
    {
        return [
            'success'     => false,
            'driver'      => 'md_bridge',
            'message_id'  => null,
            'http_status' => 0,
            'raw'         => null,
            'error'       => $message,
            'fallback'    => null,
        ];
    }
}
