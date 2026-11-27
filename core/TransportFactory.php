<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — DUAL TRANSPORT FACTORY & HYBRID ROUTER
 * ==============================================================================
 * Mengimplementasikan TransportInterface secara transparan dengan mode:
 * - cloud_api : Seluruh pesan 1-on-1 melalui Meta Cloud API
 * - md_bridge : Seluruh pesan, grup, & anti-call melalui Multi-Device Bridge
 * - hybrid    :
 *     • Grup (@g.us), deleteGroupMessage, kickGroupMember, rejectCall, blockContact
 *       → WAJIB melalui BridgeMdDriver
 *     • Interactive Button / List / CTA / Broadcast 1-on-1
 *       → Prefer CloudApiDriver (jika terkonfigurasi), fallback otomatis ke BridgeMdDriver
 * ==============================================================================
 */

class TransportFactory implements TransportInterface
{
    private string $mode;
    private ?CloudApiDriver $cloudDriver;
    private ?BridgeMdDriver $bridgeDriver;
    private ?PDO $pdo;

    public function __construct(
        string $mode = 'hybrid',
        ?CloudApiDriver $cloudDriver = null,
        ?BridgeMdDriver $bridgeDriver = null,
        ?PDO $pdo = null
    ) {
        $normalized = strtolower(trim($mode));
        $this->mode         = in_array($normalized, ['hybrid', 'cloud_api', 'md_bridge'], true) ? $normalized : 'hybrid';
        $this->cloudDriver  = $cloudDriver;
        $this->bridgeDriver = $bridgeDriver;
        $this->pdo          = $pdo;
    }

    /**
     * Bangun TransportFactory dari database (wa_settings + wa_devices) dengan fallback config.php
     */
    public static function create(?PDO $pdo = null, ?string $modeOverride = null, array $overrides = []): self
    {
        $db = $pdo ?? getDBConnection();

        $mode = $modeOverride ?? (string)wa_get_setting('wa_driver', WA_DRIVER, $db);

        $cloudConfig = [];
        $bridgeConfig = [];

        try {
            $stmt = $db->query(
                'SELECT * FROM wa_devices ORDER BY is_default DESC, id ASC'
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $dev) {
                if ($dev['driver'] === 'cloud_api' && $cloudConfig === []) {
                    $cloudConfig = [
                        'phone_number_id' => (string)($dev['phone_number'] ?? ''),
                        'graph_url'       => (string)($dev['endpoint_url'] ?: WA_CLOUD_GRAPH_URL),
                        'access_token'    => (string)($dev['api_token'] ?? ''),
                        'app_secret'      => (string)($dev['webhook_secret'] ?? ''),
                    ];
                } elseif ($dev['driver'] === 'md_bridge' && $bridgeConfig === []) {
                    $bridgeConfig = [
                        'base_url'       => (string)($dev['endpoint_url'] ?: WA_BRIDGE_BASE_URL),
                        'api_key'        => (string)($dev['api_token'] ?? ''),
                        'webhook_secret' => (string)($dev['webhook_secret'] ?? ''),
                    ];
                }
            }
        } catch (Throwable $e) {
            // Fallback ke konstanta config.php
        }

        if (isset($overrides['dry_run'])) {
            $cloudConfig['dry_run']  = (bool)$overrides['dry_run'];
            $bridgeConfig['dry_run'] = (bool)$overrides['dry_run'];
        }
        if (isset($overrides['cloud']) && is_array($overrides['cloud'])) {
            $cloudConfig = array_merge($cloudConfig, $overrides['cloud']);
        }
        if (isset($overrides['bridge']) && is_array($overrides['bridge'])) {
            $bridgeConfig = array_merge($bridgeConfig, $overrides['bridge']);
        }

        $cloudHttp  = $overrides['cloud_http'] ?? null;
        $bridgeHttp = $overrides['bridge_http'] ?? null;

        return new self(
            $mode,
            new CloudApiDriver($cloudConfig, is_callable($cloudHttp) ? $cloudHttp : null),
            new BridgeMdDriver($bridgeConfig, is_callable($bridgeHttp) ? $bridgeHttp : null),
            $db
        );
    }

    public function getDriverName(): string
    {
        return $this->mode;
    }

    public function getCloudDriver(): ?CloudApiDriver
    {
        return $this->cloudDriver;
    }

    public function getBridgeDriver(): ?BridgeMdDriver
    {
        return $this->bridgeDriver;
    }

    public function supportsGroups(): bool
    {
        return $this->bridgeDriver !== null && $this->mode !== 'cloud_api';
    }

    public function supportsCallReject(): bool
    {
        return $this->bridgeDriver !== null && $this->mode !== 'cloud_api';
    }

    public function sendText(string $to, string $text, array $options = []): array
    {
        $driver = $this->selectMessagingDriver($to, $options);
        if ($driver === null) {
            return $this->noDriverResult($to);
        }
        $res = $driver->sendText($to, $text, $options);
        $this->auditOutgoing($to, 'text', null, $text, $res);
        return $res;
    }

    public function sendButtons(string $to, string $body, array $buttons, array $options = []): array
    {
        $driver = $this->selectMessagingDriver($to, $options);
        if ($driver === null) {
            return $this->noDriverResult($to);
        }
        $res = $driver->sendButtons($to, $body, $buttons, $options);
        $this->auditOutgoing($to, 'buttons', null, $body, $res);
        return $res;
    }

    public function sendListMenu(string $to, string $body, string $buttonLabel, array $sections, array $options = []): array
    {
        $driver = $this->selectMessagingDriver($to, $options);
        if ($driver === null) {
            return $this->noDriverResult($to);
        }
        $res = $driver->sendListMenu($to, $body, $buttonLabel, $sections, $options);
        $this->auditOutgoing($to, 'list', null, $body, $res);
        return $res;
    }

    public function sendCtaUrl(string $to, string $body, string $buttonText, string $url, array $options = []): array
    {
        $driver = $this->selectMessagingDriver($to, $options);
        if ($driver === null) {
            return $this->noDriverResult($to);
        }
        $res = $driver->sendCtaUrl($to, $body, $buttonText, $url, $options);
        $this->auditOutgoing($to, 'cta_url', null, "{$body} [{$buttonText}: {$url}]", $res);
        return $res;
    }

    public function sendMedia(string $to, string $mediaType, string $mediaUrl, string $caption = '', array $options = []): array
    {
        $driver = $this->selectMessagingDriver($to, $options);
        if ($driver === null) {
            return $this->noDriverResult($to);
        }
        $res = $driver->sendMedia($to, $mediaType, $mediaUrl, $caption, $options);
        $this->auditOutgoing($to, $mediaType, null, $caption !== '' ? $caption : $mediaUrl, $res);
        return $res;
    }

    public function deleteGroupMessage(string $groupJid, string $messageId, ?string $participantJid = null): array
    {
        if ($this->bridgeDriver === null) {
            return $this->noDriverResult($groupJid, 'BridgeMdDriver tidak tersedia untuk moderasi grup.');
        }
        return $this->bridgeDriver->deleteGroupMessage($groupJid, $messageId, $participantJid);
    }

    public function kickGroupMember(string $groupJid, string $participantPhoneOrJid): array
    {
        if ($this->bridgeDriver === null) {
            return $this->noDriverResult($groupJid, 'BridgeMdDriver tidak tersedia untuk kick anggota grup.');
        }
        return $this->bridgeDriver->kickGroupMember($groupJid, $participantPhoneOrJid);
    }

    public function rejectCall(string $callId, string $callerPhoneOrJid): array
    {
        if ($this->bridgeDriver === null) {
            return $this->noDriverResult($callerPhoneOrJid, 'BridgeMdDriver tidak tersedia untuk rejectCall.');
        }
        return $this->bridgeDriver->rejectCall($callId, $callerPhoneOrJid);
    }

    public function blockContact(string $phoneOrJid): array
    {
        if ($this->bridgeDriver === null) {
            return $this->noDriverResult($phoneOrJid, 'BridgeMdDriver tidak tersedia untuk blockContact.');
        }
        return $this->bridgeDriver->blockContact($phoneOrJid);
    }

    public function healthCheck(): array
    {
        return [
            'mode'      => $this->mode,
            'cloud_api' => $this->cloudDriver?->healthCheck(),
            'md_bridge' => $this->bridgeDriver?->healthCheck(),
        ];
    }

    /**
     * Pilih driver yang tepat berdasarkan mode (hybrid/cloud_api/md_bridge) & tipe tujuan
     */
    private function selectMessagingDriver(string $to, array $options = []): ?TransportInterface
    {
        // Tujuan grup (@g.us) WAJIB menggunakan MD Bridge
        if (wa_is_group_jid($to)) {
            return $this->bridgeDriver;
        }

        // Override eksplisit per panggilan (misal prefer_driver = 'md_bridge' atau 'cloud_api')
        $prefer = strtolower((string)($options['prefer_driver'] ?? ''));
        if ($prefer === 'cloud_api' && $this->cloudDriver !== null) {
            return $this->cloudDriver;
        }
        if ($prefer === 'md_bridge' && $this->bridgeDriver !== null) {
            return $this->bridgeDriver;
        }

        if ($this->mode === 'cloud_api') {
            return $this->cloudDriver;
        }
        if ($this->mode === 'md_bridge') {
            return $this->bridgeDriver;
        }

        // Mode hybrid: prioritaskan Cloud API jika aktif terkonfigurasi, fallback ke MD Bridge
        if ($this->cloudDriver !== null && $this->cloudDriver->isConfigured()) {
            return $this->cloudDriver;
        }
        return $this->bridgeDriver;
    }

    private function auditOutgoing(string $to, string $type, ?string $buttonId, string $preview, array $result): void
    {
        if ($this->pdo === null || empty($result['success'])) {
            return;
        }
        $jid = wa_phone_to_jid($to);
        if ($jid === '') {
            $jid = $to;
        }
        wa_log_message(
            $this->pdo,
            'out',
            $jid,
            $type,
            $buttonId,
            $preview,
            isset($result['message_id']) ? (string)$result['message_id'] : null
        );
    }

    private function noDriverResult(string $target, string $reason = ''): array
    {
        return [
            'success'     => false,
            'driver'      => $this->mode,
            'message_id'  => null,
            'http_status' => 0,
            'raw'         => null,
            'error'       => $reason !== '' ? $reason : "Tidak ada driver aktif yang cocok untuk tujuan '{$target}'.",
            'fallback'    => null,
        ];
    }
}
