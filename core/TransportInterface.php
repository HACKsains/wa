<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — TRANSPORT INTERFACE (DUAL ADAPTER CONTRACT)
 * ==============================================================================
 * Memisahkan 100% logika bisnis PHP dari protokol pengiriman WhatsApp:
 * - CloudApiDriver : Meta WhatsApp Cloud API v21.0 (Button, List, CTA, Broadcast)
 * - BridgeMdDriver : Self-Hosted MD Bridge (Grup, Hapus Pesan, Kick, Anti-Call)
 * - TransportFactory : Router Hybrid yang mengimplementasikan interface ini
 * ==============================================================================
 */

interface TransportInterface
{
    /**
     * Identitas driver aktif ('cloud_api' | 'md_bridge' | 'hybrid')
     */
    public function getDriverName(): string;

    /**
     * Apakah transport mendukung pengiriman & moderasi grup WhatsApp (@g.us)
     */
    public function supportsGroups(): bool;

    /**
     * Apakah transport mendukung auto-reject panggilan masuk & blokir kontak
     */
    public function supportsCallReject(): bool;

    /**
     * Kirim pesan teks biasa (dengan dukungan reply_to / mentions di $options)
     */
    public function sendText(string $to, string $text, array $options = []): array;

    /**
     * Kirim pesan dengan tombol interaktif cepat (Reply Buttons, maks 3 tombol;
     * jika > 3 tombol driver wajib otomatis fallback ke List Menu)
     *
     * @param array<int, array{id:string, title:string, description?:string}> $buttons
     */
    public function sendButtons(string $to, string $body, array $buttons, array $options = []): array;

    /**
     * Kirim pesan dengan Interactive List Menu (hingga 10 item per menu)
     *
     * @param array<int, array{title:string, rows:array<int, array{id:string, title:string, description?:string}>}> $sections
     */
    public function sendListMenu(string $to, string $body, string $buttonLabel, array $sections, array $options = []): array;

    /**
     * Kirim pesan dengan tombol Call-To-Action URL (buka portal Learn / form hash)
     */
    public function sendCtaUrl(string $to, string $body, string $buttonText, string $url, array $options = []): array;

    /**
     * Kirim lampiran media ('image' | 'document' | 'video' | 'audio')
     */
    public function sendMedia(string $to, string $mediaType, string $mediaUrl, string $caption = '', array $options = []): array;

    /**
     * Hapus pesan pelanggaran di grup WhatsApp (revoke for everyone)
     */
    public function deleteGroupMessage(string $groupJid, string $messageId, ?string $participantJid = null): array;

    /**
     * Keluarkan anggota pelanggar berat / strike ke-3 dari grup WhatsApp
     */
    public function kickGroupMember(string $groupJid, string $participantPhoneOrJid): array;

    /**
     * Tolak otomatis panggilan suara/video masuk (Anti-Call)
     */
    public function rejectCall(string $callId, string $callerPhoneOrJid): array;

    /**
     * Blokir nomor pelaku spam call berulang
     */
    public function blockContact(string $phoneOrJid): array;

    /**
     * Cek kesehatan koneksi transport/device
     */
    public function healthCheck(): array;
}
