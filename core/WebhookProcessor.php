<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — WEBHOOK PROCESSOR (SIGNATURE, IDEMPOTENCY & FAST ROUTING)
 * ==============================================================================
 * Menangani seluruh event masuk dari Meta Cloud API & Self-Hosted MD Bridge:
 * 1. Verifikasi challenge GET Meta Cloud API (hub.mode & hub.verify_token)
 * 2. Deteksi provider & verifikasi signature HMAC-SHA256 (tolak 401 bila invalid)
 * 3. Idempotency atomik via wa_webhook_events (anti-proses ganda)
 * 4. Audit trail pesan masuk ke wa_message_logs
 * 5. Routing cepat ke 3 engine utama:
 *    - incoming_call   -> AntiCallEngine
 *    - group_message   -> GroupGuardEngine
 *    - private_message -> InteractiveMenuEngine
 *    - device_status   -> Sinkron status wa_devices
 * ==============================================================================
 */

class WebhookProcessor
{
    private PDO $pdo;
    private TransportFactory $transport;

    /** @var object|null */
    private ?object $antiCallEngine;

    /** @var object|null */
    private ?object $groupGuardEngine;

    /** @var object|null */
    private ?object $interactiveMenuEngine;

    public function __construct(
        PDO $pdo,
        TransportFactory $transport,
        array $engines = []
    ) {
        $this->pdo                   = $pdo;
        $this->transport             = $transport;
        $this->antiCallEngine        = $engines['anti_call'] ?? null;
        $this->groupGuardEngine      = $engines['group_guard'] ?? null;
        $this->interactiveMenuEngine = $engines['interactive_menu'] ?? null;
    }

    /**
     * Verifikasi GET Challenge dari Meta WhatsApp Cloud API
     */
    public function verifyMetaChallenge(array $query): array
    {
        $mode      = (string)($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token     = (string)($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string)($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        $expectedToken = $this->transport->getCloudDriver()?->getVerifyToken() ?: WA_CLOUD_VERIFY_TOKEN;

        if ($mode === 'subscribe' && $challenge !== '' && $expectedToken !== '' && hash_equals($expectedToken, $token)) {
            return [
                'valid'       => true,
                'http_status' => 200,
                'challenge'   => $challenge,
                'message'     => 'Webhook Meta terverifikasi.',
            ];
        }

        return [
            'valid'       => false,
            'http_status' => 403,
            'challenge'   => '',
            'message'     => 'Verify token Meta tidak valid.',
        ];
    }

    /**
     * Proses payload mentah POST webhook (dipakai oleh webhook.php & CLI simulator)
     */
    public function processRawWebhook(string $rawBody, array $headers = []): array
    {
        $normHeaders = [];
        foreach ($headers as $k => $v) {
            $normHeaders[strtolower((string)$k)] = (string)$v;
        }

        if (trim($rawBody) === '') {
            return [
                'success'     => false,
                'http_status' => 400,
                'message'     => 'Body webhook kosong.',
                'data'        => [],
            ];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [
                'success'     => false,
                'http_status' => 400,
                'message'     => 'Payload JSON webhook tidak valid.',
                'data'        => [],
            ];
        }

        // 1. Deteksi provider (Meta Cloud API vs Self-Hosted MD Bridge)
        $provider = $this->detectProvider($payload, $normHeaders);

        // 2. Verifikasi Signature HMAC-SHA256
        if (!$this->verifySignature($provider, $rawBody, $normHeaders)) {
            return [
                'success'     => false,
                'http_status' => 401,
                'message'     => "Signature webhook {$provider} tidak valid atau tidak ditemukan.",
                'data'        => ['provider' => $provider],
            ];
        }

        // 3. Normalisasi payload menjadi daftar event kanonis
        $events = $provider === 'cloud_api'
            ? CloudApiDriver::parseWebhookPayload($payload)
            : BridgeMdDriver::parseWebhookPayload($payload);

        $results = [];
        foreach ($events as $event) {
            $eventId   = (string)($event['event_id'] ?? '');
            $eventType = (string)($event['event_type'] ?? 'unknown');

            // 4. Atomic Idempotency Check (Anti-Proses Ganda)
            if ($eventId !== '' && !$this->claimIdempotencyKey($provider . ':' . $eventId, $eventType)) {
                $results[] = [
                    'event_id'   => $eventId,
                    'event_type' => $eventType,
                    'status'     => 'duplicate_skipped',
                ];
                continue;
            }

            // 5. Routing cepat ke Engine yang sesuai
            $results[] = $this->dispatchEvent($event);
        }

        return [
            'success'     => true,
            'http_status' => 200,
            'message'     => 'Webhook berhasil diproses.',
            'data'        => [
                'provider'        => $provider,
                'events_received' => count($events),
                'results'         => $results,
            ],
        ];
    }

    private function detectProvider(array $payload, array $headers): string
    {
        if (isset($headers['x-hub-signature-256'])) {
            return 'cloud_api';
        }
        $sourceHint = strtolower(trim($headers['x-webhook-source'] ?? ''));
        if ($sourceHint === 'cloud_api' || $sourceHint === 'meta') {
            return 'cloud_api';
        }
        if ($sourceHint === 'md_bridge' || $sourceHint === 'bridge') {
            return 'md_bridge';
        }
        if (($payload['object'] ?? null) === 'whatsapp_business_account') {
            return 'cloud_api';
        }
        return 'md_bridge';
    }

    private function verifySignature(string $provider, string $rawBody, array $headers): bool
    {
        if ($provider === 'cloud_api') {
            $cloud = $this->transport->getCloudDriver();
            $secret = $cloud?->getAppSecret() ?: WA_CLOUD_APP_SECRET;
            $sig = $headers['x-hub-signature-256'] ?? '';
            if ($secret === '') {
                return false;
            }
            return $cloud !== null
                ? $cloud->verifyWebhookSignature($rawBody, $sig, $secret)
                : false;
        }

        $bridge = $this->transport->getBridgeDriver();
        $secret = $bridge?->getWebhookSecret() ?: WA_BRIDGE_WEBHOOK_SECRET;
        $sig = $headers['x-webhook-signature']
            ?? $headers['x-bridge-signature']
            ?? $headers['x-ilmuna-signature']
            ?? '';

        if ($secret === '') {
            return false;
        }

        return $bridge !== null
            ? $bridge->verifyWebhookSignature($rawBody, $sig, $secret)
            : false;
    }

    /**
     * Klaim kunci idempotency secara atomik pada tabel wa_webhook_events.
     * Return true jika event BARU (boleh diproses), false jika DUPLIKAT.
     */
    private function claimIdempotencyKey(string $providerEventId, string $eventType): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO wa_webhook_events (provider_event_id, event_type)
             VALUES (:eid, :etype)'
        );
        $stmt->execute([
            ':eid'   => mb_substr($providerEventId, 0, 128, 'UTF-8'),
            ':etype' => mb_substr($eventType, 0, 50, 'UTF-8'),
        ]);
        return $stmt->rowCount() > 0;
    }

    private function dispatchEvent(array $event): array
    {
        $type    = (string)($event['event_type'] ?? 'unknown');
        $eventId = (string)($event['event_id'] ?? '');

        return match ($type) {
            'incoming_call'   => $this->routeIncomingCall($event),
            'group_message'   => $this->routeGroupMessage($event),
            'private_message' => $this->routePrivateMessage($event),
            'device_status'   => $this->routeDeviceStatus($event),
            default           => [
                'event_id'   => $eventId,
                'event_type' => $type,
                'status'     => 'ignored_unknown_type',
            ],
        };
    }

    private function routeIncomingCall(array $event): array
    {
        $engine = $this->antiCallEngine;
        if ($engine === null && class_exists('AntiCallEngine')) {
            $engine = new AntiCallEngine($this->pdo, $this->transport);
        }

        if ($engine !== null && method_exists($engine, 'handleIncomingCall')) {
            $res = $engine->handleIncomingCall($event);
            return [
                'event_id'     => $event['event_id'],
                'event_type'   => 'incoming_call',
                'route_target' => 'anti_call',
                'status'       => 'handled',
                'detail'       => $res,
            ];
        }

        // Baseline Fase 2: tolak panggilan segera melalui BridgeMdDriver bila anti_call_enabled aktif
        $enabled = wa_get_setting('anti_call_enabled', '1', $this->pdo) === '1';
        $rejectRes = null;
        if ($enabled && !empty($event['call_id']) && !empty($event['sender_phone'])) {
            $rejectRes = $this->transport->rejectCall((string)$event['call_id'], (string)$event['sender_phone']);
        }

        return [
            'event_id'     => $event['event_id'],
            'event_type'   => 'incoming_call',
            'route_target' => 'anti_call',
            'status'       => 'routed_baseline_reject',
            'detail'       => ['anti_call_enabled' => $enabled, 'reject_result' => $rejectRes],
        ];
    }

    private function routeGroupMessage(array $event): array
    {
        wa_log_message(
            $this->pdo,
            'in',
            (string)($event['chat_jid'] ?? ''),
            'group_' . (string)($event['message_type'] ?? 'text'),
            $event['button_id'] ?? null,
            trim(((string)($event['sender_phone'] ?? '')) . ': ' . ((string)($event['text'] ?? ''))),
            (string)($event['message_id'] ?? '')
        );

        $engine = $this->groupGuardEngine;
        if ($engine === null && class_exists('GroupGuardEngine')) {
            $engine = new GroupGuardEngine($this->pdo, $this->transport);
        }

        if ($engine !== null && method_exists($engine, 'inspectMessage')) {
            $res = $engine->inspectMessage($event);
            return [
                'event_id'     => $event['event_id'],
                'event_type'   => 'group_message',
                'route_target' => 'group_guard',
                'status'       => 'handled',
                'detail'       => $res,
            ];
        }

        return [
            'event_id'     => $event['event_id'],
            'event_type'   => 'group_message',
            'route_target' => 'group_guard',
            'status'       => 'routed_pending_engine',
            'detail'       => [
                'group_jid'    => $event['group_jid'] ?? $event['chat_jid'],
                'sender_phone' => $event['sender_phone'] ?? '',
            ],
        ];
    }

    private function routePrivateMessage(array $event): array
    {
        $senderPhone = (string)($event['sender_phone'] ?? '');
        wa_log_message(
            $this->pdo,
            'in',
            (string)($event['chat_jid'] ?? $senderPhone),
            (string)($event['message_type'] ?? 'text'),
            isset($event['button_id']) ? (string)$event['button_id'] : null,
            (string)($event['text'] ?? ''),
            (string)($event['message_id'] ?? '')
        );

        // Jangan proses pesan dari kontak yang diblokir karena spam call/abuse
        if ($senderPhone !== '' && $this->isContactBlocked($senderPhone)) {
            return [
                'event_id'     => $event['event_id'],
                'event_type'   => 'private_message',
                'route_target' => 'blocked_filter',
                'status'       => 'ignored_blocked_contact',
            ];
        }

        $engine = $this->interactiveMenuEngine;
        if ($engine === null && class_exists('InteractiveMenuEngine')) {
            $engine = new InteractiveMenuEngine($this->pdo, $this->transport);
        }

        if ($engine !== null && method_exists($engine, 'handleIncomingMessage')) {
            $res = $engine->handleIncomingMessage($event);
            return [
                'event_id'     => $event['event_id'],
                'event_type'   => 'private_message',
                'route_target' => 'interactive_menu',
                'status'       => 'handled',
                'detail'       => $res,
            ];
        }

        return [
            'event_id'     => $event['event_id'],
            'event_type'   => 'private_message',
            'route_target' => 'interactive_menu',
            'status'       => 'routed_pending_engine',
            'detail'       => [
                'sender_phone' => $senderPhone,
                'message_type' => $event['message_type'] ?? 'text',
                'button_id'    => $event['button_id'] ?? null,
            ],
        ];
    }

    private function routeDeviceStatus(array $event): array
    {
        $status = (string)($event['status'] ?? 'disconnected');
        if (!in_array($status, ['connected', 'disconnected', 'qr_pending'], true)) {
            $status = 'disconnected';
        }

        $phone = (string)($event['phone_number'] ?? '');
        if ($phone !== '') {
            $stmt = $this->pdo->prepare(
                "UPDATE wa_devices
                 SET status = :status, phone_number = :phone, last_seen_at = NOW()
                 WHERE driver = 'md_bridge'
                 ORDER BY is_default DESC, id ASC
                 LIMIT 1"
            );
            $stmt->execute([':status' => $status, ':phone' => $phone]);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE wa_devices
                 SET status = :status, last_seen_at = NOW()
                 WHERE driver = 'md_bridge'
                 ORDER BY is_default DESC, id ASC
                 LIMIT 1"
            );
            $stmt->execute([':status' => $status]);
        }

        return [
            'event_id'     => $event['event_id'],
            'event_type'   => 'device_status',
            'route_target' => 'device_sync',
            'status'       => 'updated',
            'detail'       => ['device_status' => $status, 'rows_affected' => $stmt->rowCount()],
        ];
    }

    private function isContactBlocked(string $phoneE164): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT is_blocked FROM wa_contacts_cache WHERE phone_e164 = :phone LIMIT 1'
            );
            $stmt->execute([':phone' => $phoneE164]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}
