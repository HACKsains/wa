<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — META WHATSAPP CLOUD API DRIVER (GRAPH API v21.0)
 * ==============================================================================
 * Mendukung penuh:
 * - Interactive Reply Buttons (interactive.type = button)
 * - Interactive List Menu     (interactive.type = list)
 * - Interactive CTA URL       (interactive.type = cta_url)
 * - Media (image, document, video, audio) & Template Broadcast
 * - Verifikasi signature webhook Meta (X-Hub-Signature-256)
 *
 * Catatan: Meta Cloud API tidak mendukung grup (@g.us) maupun reject call.
 * Fitur tersebut otomatis diarahkan ke BridgeMdDriver pada mode hybrid.
 * ==============================================================================
 */

class CloudApiDriver implements TransportInterface
{
    private string $phoneNumberId;
    private string $accessToken;
    private string $graphUrl;
    private string $appSecret;
    private string $verifyToken;
    private bool $dryRun;

    /** @var callable|null */
    private $httpClient;

    public function __construct(array $config = [], ?callable $httpClient = null)
    {
        $this->phoneNumberId = trim((string)($config['phone_number_id'] ?? WA_CLOUD_PHONE_NUMBER_ID));
        $this->accessToken   = trim((string)($config['access_token'] ?? WA_CLOUD_ACCESS_TOKEN));
        $this->graphUrl      = rtrim((string)($config['graph_url'] ?? WA_CLOUD_GRAPH_URL), '/');
        $this->appSecret     = trim((string)($config['app_secret'] ?? WA_CLOUD_APP_SECRET));
        $this->verifyToken   = trim((string)($config['verify_token'] ?? WA_CLOUD_VERIFY_TOKEN));
        $this->dryRun        = (bool)($config['dry_run'] ?? WA_DRY_RUN)
            || str_starts_with($this->graphUrl, 'dry-run://');
        $this->httpClient    = $httpClient;
    }

    public function getDriverName(): string
    {
        return 'cloud_api';
    }

    public function supportsGroups(): bool
    {
        return false;
    }

    public function supportsCallReject(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return ($this->phoneNumberId !== '' && $this->accessToken !== '') || $this->dryRun;
    }

    public function getAppSecret(): string
    {
        return $this->appSecret;
    }

    public function getVerifyToken(): string
    {
        return $this->verifyToken;
    }

    public function sendText(string $to, string $text, array $options = []): array
    {
        $phone = $this->resolveIndividualRecipient($to);
        if ($phone === '') {
            return $this->errorResult('Nomor tujuan tidak valid atau berupa grup (tidak didukung Cloud API).');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => 'text',
            'text'              => [
                'preview_url' => (bool)($options['preview_url'] ?? false),
                'body'        => wa_truncate($text, 4096),
            ],
        ];

        if (!empty($options['reply_to'])) {
            $payload['context'] = ['message_id' => (string)$options['reply_to']];
        }

        return $this->postMessages($payload);
    }

    public function sendButtons(string $to, string $body, array $buttons, array $options = []): array
    {
        $phone = $this->resolveIndividualRecipient($to);
        if ($phone === '') {
            return $this->errorResult('Nomor tujuan tidak valid untuk interactive buttons Cloud API.');
        }

        $cleanButtons = array_values(array_filter($buttons, static fn($b) => is_array($b) && !empty($b['title'])));
        if ($cleanButtons === []) {
            return $this->sendText($phone, $body, $options);
        }

        // Meta Cloud API membatasi reply button maksimal 3 tombol; otomatis naikkan ke List Menu bila > 3
        if (count($cleanButtons) > 3) {
            $rows = [];
            foreach (array_slice($cleanButtons, 0, 10) as $idx => $btn) {
                $rows[] = [
                    'id'          => (string)($btn['id'] ?? ('OPT_' . ($idx + 1))),
                    'title'       => (string)$btn['title'],
                    'description' => (string)($btn['description'] ?? ''),
                ];
            }
            $listResult = $this->sendListMenu(
                $phone,
                $body,
                (string)($options['list_button'] ?? 'Pilih Menu'),
                [['title' => (string)($options['section_title'] ?? 'Pilihan Layanan'), 'rows' => $rows]],
                $options
            );
            $listResult['fallback'] = 'list_menu';
            return $listResult;
        }

        $actionButtons = [];
        foreach ($cleanButtons as $idx => $btn) {
            $actionButtons[] = [
                'type'  => 'reply',
                'reply' => [
                    'id'    => mb_substr((string)($btn['id'] ?? ('BTN_' . ($idx + 1))), 0, 256, 'UTF-8'),
                    'title' => wa_truncate((string)$btn['title'], 20),
                ],
            ];
        }

        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => wa_truncate($body, 1024)],
            'action' => ['buttons' => $actionButtons],
        ];

        $header = $this->buildInteractiveHeader($options);
        if ($header !== null) {
            $interactive['header'] = $header;
        }
        if (!empty($options['footer'])) {
            $interactive['footer'] = ['text' => wa_truncate((string)$options['footer'], 60)];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ];

        if (!empty($options['reply_to'])) {
            $payload['context'] = ['message_id' => (string)$options['reply_to']];
        }

        return $this->postMessages($payload);
    }

    public function sendListMenu(string $to, string $body, string $buttonLabel, array $sections, array $options = []): array
    {
        $phone = $this->resolveIndividualRecipient($to);
        if ($phone === '') {
            return $this->errorResult('Nomor tujuan tidak valid untuk interactive list Cloud API.');
        }

        $formattedSections = [];
        $totalRows = 0;

        foreach ($sections as $secIdx => $section) {
            if ($totalRows >= 10) {
                break;
            }
            $secTitle = wa_truncate((string)($section['title'] ?? ('Menu ' . ($secIdx + 1))), 24);
            $rows = [];
            foreach ((array)($section['rows'] ?? []) as $rowIdx => $row) {
                if ($totalRows >= 10) {
                    break;
                }
                if (empty($row['title'])) {
                    continue;
                }
                $item = [
                    'id'    => mb_substr((string)($row['id'] ?? ('ROW_' . ($totalRows + 1))), 0, 200, 'UTF-8'),
                    'title' => wa_truncate((string)$row['title'], 24),
                ];
                if (!empty($row['description'])) {
                    $item['description'] = wa_truncate((string)$row['description'], 72);
                }
                $rows[] = $item;
                $totalRows++;
            }
            if ($rows !== []) {
                $formattedSections[] = [
                    'title' => $secTitle,
                    'rows'  => $rows,
                ];
            }
        }

        if ($formattedSections === []) {
            return $this->sendText($phone, $body, $options);
        }

        $interactive = [
            'type'   => 'list',
            'body'   => ['text' => wa_truncate($body, 1024)],
            'action' => [
                'button'   => wa_truncate($buttonLabel !== '' ? $buttonLabel : 'Pilih Menu', 20),
                'sections' => $formattedSections,
            ],
        ];

        if (!empty($options['header'])) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => wa_truncate((string)$options['header'], 60),
            ];
        }
        if (!empty($options['footer'])) {
            $interactive['footer'] = ['text' => wa_truncate((string)$options['footer'], 60)];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ];

        return $this->postMessages($payload);
    }

    public function sendCtaUrl(string $to, string $body, string $buttonText, string $url, array $options = []): array
    {
        $phone = $this->resolveIndividualRecipient($to);
        if ($phone === '') {
            return $this->errorResult('Nomor tujuan tidak valid untuk CTA URL Cloud API.');
        }

        $interactive = [
            'type'   => 'cta_url',
            'body'   => ['text' => wa_truncate($body, 1024)],
            'action' => [
                'name'       => 'cta_url',
                'parameters' => [
                    'display_text' => wa_truncate($buttonText, 20),
                    'url'          => trim($url),
                ],
            ],
        ];

        $header = $this->buildInteractiveHeader($options);
        if ($header !== null) {
            $interactive['header'] = $header;
        }
        if (!empty($options['footer'])) {
            $interactive['footer'] = ['text' => wa_truncate((string)$options['footer'], 60)];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ];

        return $this->postMessages($payload);
    }

    public function sendMedia(string $to, string $mediaType, string $mediaUrl, string $caption = '', array $options = []): array
    {
        $phone = $this->resolveIndividualRecipient($to);
        if ($phone === '') {
            return $this->errorResult('Nomor tujuan tidak valid untuk pengiriman media Cloud API.');
        }

        $allowedTypes = ['image', 'document', 'video', 'audio'];
        $type = strtolower(trim($mediaType));
        if (!in_array($type, $allowedTypes, true)) {
            return $this->errorResult("Tipe media '{$mediaType}' tidak didukung Meta Cloud API.");
        }

        $mediaObj = ['link' => trim($mediaUrl)];
        if ($caption !== '' && $type !== 'audio') {
            $mediaObj['caption'] = wa_truncate($caption, 1024);
        }
        if ($type === 'document' && !empty($options['filename'])) {
            $mediaObj['filename'] = (string)$options['filename'];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => $type,
            $type               => $mediaObj,
        ];

        return $this->postMessages($payload);
    }

    /**
     * Kirim pesan Template Resmi Meta (untuk broadcast luar jendela 24 jam)
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode = 'id', array $components = []): array
    {
        $phone = $this->resolveIndividualRecipient($to);
        if ($phone === '') {
            return $this->errorResult('Nomor tujuan tidak valid untuk template Cloud API.');
        }

        $template = [
            'name'     => $templateName,
            'language' => ['code' => $languageCode],
        ];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->postMessages([
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => $template,
        ]);
    }

    /**
     * Tandai pesan masuk sebagai sudah dibaca (centang biru)
     */
    public function markAsRead(string $messageId): array
    {
        if (trim($messageId) === '') {
            return $this->errorResult('messageId kosong.');
        }

        return $this->postMessages([
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $messageId,
        ]);
    }

    public function deleteGroupMessage(string $groupJid, string $messageId, ?string $participantJid = null): array
    {
        return $this->unsupportedResult('Hapus pesan grup hanya didukung oleh Multi-Device Bridge (BridgeMdDriver).');
    }

    public function kickGroupMember(string $groupJid, string $participantPhoneOrJid): array
    {
        return $this->unsupportedResult('Kick anggota grup hanya didukung oleh Multi-Device Bridge (BridgeMdDriver).');
    }

    public function rejectCall(string $callId, string $callerPhoneOrJid): array
    {
        return $this->unsupportedResult('Auto-reject panggilan masuk hanya didukung oleh Multi-Device Bridge (BridgeMdDriver).');
    }

    public function blockContact(string $phoneOrJid): array
    {
        return $this->unsupportedResult('Blokir kontak otomatis hanya didukung oleh Multi-Device Bridge (BridgeMdDriver).');
    }

    public function healthCheck(): array
    {
        if ($this->dryRun) {
            return [
                'success'     => true,
                'driver'      => 'cloud_api',
                'status'      => 'connected',
                'http_status' => 200,
                'dry_run'     => true,
                'error'       => null,
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'success'     => false,
                'driver'      => 'cloud_api',
                'status'      => 'disconnected',
                'http_status' => 0,
                'error'       => 'Phone Number ID atau Access Token Meta Cloud API belum dikonfigurasi.',
            ];
        }

        $url = "{$this->graphUrl}/{$this->phoneNumberId}?fields=display_phone_number,verified_name,quality_rating";
        $resp = $this->httpRequest('GET', $url, null);

        return [
            'success'     => $resp['ok'],
            'driver'      => 'cloud_api',
            'status'      => $resp['ok'] ? 'connected' : 'disconnected',
            'http_status' => $resp['http_status'],
            'raw'         => $resp['data'],
            'error'       => $resp['error'],
        ];
    }

    /**
     * Verifikasi signature webhook Meta: X-Hub-Signature-256 = "sha256=<hex>"
     */
    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, ?string $secretOverride = null): bool
    {
        $secret = $secretOverride !== null && $secretOverride !== '' ? $secretOverride : $this->appSecret;
        if ($secret === '') {
            return false;
        }
        $signatureHeader = trim($signatureHeader);
        if (!str_starts_with(strtolower($signatureHeader), 'sha256=')) {
            return false;
        }
        $provided = strtolower(substr($signatureHeader, 7));
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $provided);
    }

    /**
     * Normalisasi payload webhook Meta WhatsApp Cloud API menjadi daftar event kanonis
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseWebhookPayload(array $payload): array
    {
        $events = [];
        $entries = (array)($payload['entry'] ?? []);

        foreach ($entries as $entry) {
            foreach ((array)($entry['changes'] ?? []) as $change) {
                $value = (array)($change['value'] ?? []);
                $contactsMap = [];
                foreach ((array)($value['contacts'] ?? []) as $c) {
                    $waId = wa_normalize_phone((string)($c['wa_id'] ?? ''));
                    if ($waId !== '') {
                        $contactsMap[$waId] = (string)($c['profile']['name'] ?? '');
                    }
                }

                foreach ((array)($value['messages'] ?? []) as $msg) {
                    $msgId = (string)($msg['id'] ?? ('cloud_' . bin2hex(random_bytes(8))));
                    $fromPhone = wa_normalize_phone((string)($msg['from'] ?? ''));
                    if ($fromPhone === '') {
                        continue;
                    }

                    $rawType = (string)($msg['type'] ?? 'text');
                    $messageType = $rawType;
                    $text = '';
                    $buttonId = null;
                    $buttonTitle = null;

                    if ($rawType === 'text') {
                        $text = (string)($msg['text']['body'] ?? '');
                    } elseif ($rawType === 'interactive') {
                        $interactive = (array)($msg['interactive'] ?? []);
                        $iType = (string)($interactive['type'] ?? '');
                        if ($iType === 'button_reply') {
                            $messageType = 'button_reply';
                            $buttonId    = (string)($interactive['button_reply']['id'] ?? '');
                            $buttonTitle = (string)($interactive['button_reply']['title'] ?? '');
                            $text        = $buttonTitle;
                        } elseif ($iType === 'list_reply') {
                            $messageType = 'list_reply';
                            $buttonId    = (string)($interactive['list_reply']['id'] ?? '');
                            $buttonTitle = (string)($interactive['list_reply']['title'] ?? '');
                            $text        = $buttonTitle;
                        }
                    } elseif ($rawType === 'button') {
                        $messageType = 'button_reply';
                        $buttonId    = (string)($msg['button']['payload'] ?? $msg['button']['text'] ?? '');
                        $buttonTitle = (string)($msg['button']['text'] ?? '');
                        $text        = $buttonTitle;
                    } elseif (in_array($rawType, ['image', 'document', 'video'], true)) {
                        $text = (string)($msg[$rawType]['caption'] ?? '');
                    }

                    $events[] = [
                        'provider'      => 'cloud_api',
                        'event_id'      => $msgId,
                        'event_type'    => 'private_message',
                        'is_group'      => false,
                        'chat_jid'      => $fromPhone . '@s.whatsapp.net',
                        'sender_jid'    => $fromPhone . '@s.whatsapp.net',
                        'sender_phone'  => $fromPhone,
                        'sender_name'   => $contactsMap[$fromPhone] ?? '',
                        'message_id'    => $msgId,
                        'message_type'  => $messageType,
                        'text'          => $text,
                        'button_id'     => $buttonId,
                        'button_title'  => $buttonTitle,
                        'timestamp'     => (int)($msg['timestamp'] ?? time()),
                        'raw'           => $msg,
                    ];
                }
            }
        }

        return $events;
    }

    private function buildInteractiveHeader(array $options): ?array
    {
        if (!empty($options['header_image'])) {
            return ['type' => 'image', 'image' => ['link' => (string)$options['header_image']]];
        }
        if (!empty($options['header_document'])) {
            $doc = ['link' => (string)$options['header_document']];
            if (!empty($options['header_filename'])) {
                $doc['filename'] = (string)$options['header_filename'];
            }
            return ['type' => 'document', 'document' => $doc];
        }
        if (!empty($options['header'])) {
            return ['type' => 'text', 'text' => wa_truncate((string)$options['header'], 60)];
        }
        return null;
    }

    private function resolveIndividualRecipient(string $to): string
    {
        if (wa_is_group_jid($to)) {
            return '';
        }
        return wa_normalize_phone($to);
    }

    private function postMessages(array $payload): array
    {
        $url = "{$this->graphUrl}/{$this->phoneNumberId}/messages";

        if ($this->dryRun) {
            return [
                'success'     => true,
                'driver'      => 'cloud_api',
                'message_id'  => 'wamid.dryrun.' . bin2hex(random_bytes(6)),
                'http_status' => 200,
                'raw'         => ['dry_run' => true, 'url' => $url, 'payload' => $payload],
                'error'       => null,
                'fallback'    => null,
            ];
        }

        if (!$this->isConfigured()) {
            return $this->errorResult('Kredensial Meta WhatsApp Cloud API belum dikonfigurasi.');
        }

        $resp = $this->httpRequest('POST', $url, $payload);
        $msgId = $resp['data']['messages'][0]['id'] ?? null;

        return [
            'success'     => $resp['ok'],
            'driver'      => 'cloud_api',
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
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ];

        if ($this->httpClient !== null) {
            return ($this->httpClient)($method, $url, $headers, $payload);
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'Ekstensi PHP cURL tidak aktif.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
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
            $errMsg = is_array($decoded) && isset($decoded['error']['message'])
                ? (string)$decoded['error']['message']
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
            'driver'      => 'cloud_api',
            'message_id'  => null,
            'http_status' => 0,
            'raw'         => null,
            'error'       => $message,
            'fallback'    => null,
        ];
    }

    private function unsupportedResult(string $message): array
    {
        $res = $this->errorResult($message);
        $res['unsupported'] = true;
        return $res;
    }
}
