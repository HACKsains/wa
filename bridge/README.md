# 🔌 Panduan Koneksi WhatsApp (Hosting cPanel Tanpa Node.js & Lokal Laragon)

Folder `bridge/` ini dikelola **otomatis oleh PHP** melalui [`core/BridgeManager.php`](../core/BridgeManager.php) dan [`workers/bridge-watchdog.php`](../workers/bridge-watchdog.php).

---

## 1. Pilihan Koneksi Sesuai Kondisi Hosting

### A. Hosting cPanel Terbatas **TANPA Node.js** — Opsi Binary Tunggal Go-WhatsApp (Auto-Run via PHP)
Jika cPanel Linux Anda **tidak memiliki Node.js** tetapi mengizinkan `exec()`/`proc_open()` & Cron Job:
1. Unduh 1 file binary statis Linux `whatsapp` dari rilis [`go-whatsapp-web-multidevice`](https://github.com/aldinokemal/go-whatsapp-web-multidevice/releases) (ukuran ~15 MB, **0 dependensi, tanpa Node.js, tanpa Docker**).
2. Letakkan di `bridge/bin/whatsapp` lalu ubah izin menjadi `chmod +x bridge/bin/whatsapp`.
3. Pasang Cron cPanel setiap 1 menit:
   ```cron
   * * * * * /usr/local/bin/php /home/uofruaju/wa.ilmuna.my.id/workers/bridge-watchdog.php >/dev/null 2>&1
   ```
4. PHP (`BridgeManager.php`) otomatis mendeteksi `bridge/bin/whatsapp`, menyalakannya di background, dan menampilkan **QR Code / Pairing Code 8 digit** langsung di Dashboard Admin PHP.

### B. Hosting Shared cPanel Super Ketat (**TANPA Node.js & TANPA Daemon/Exec**) — 100% Pure PHP
Jika cPanel mematikan `exec()` dan tidak mengizinkan proses background sama sekali, **seluruh proyek tetap berjalan 100% Pure PHP Native** lewat salah satu jalur berikut:
1. **Meta WhatsApp Cloud API Resmi** (`WA_DRIVER = 'cloud_api'`) — cukup cURL HTTPS dari PHP, mendukung native Reply Buttons, List Menu, CTA URL, dan balasan pesan user gratis tanpa batas.
2. **Gateway WA Multi-Device HTTP REST** (`WA_BRIDGE_FLAVOR = 'whapi'` atau `'fonnte'`) — cukup isi `WA_BRIDGE_BASE_URL` dan `WA_BRIDGE_API_KEY` di `config.local.php` atau Dashboard → Devices.
3. **Bridge di PC Sekolah / Laragon / VPS Mini + Cloudflare Tunnel (Gratis)** — jalankan `bridge/` di PC kantor sekolah, lalu hubungkan URL tunnel ke `WA_BRIDGE_BASE_URL` di hosting cPanel.

### C. Lokal Laragon / VPS (Ada Node.js — Auto-Start oleh PHP)
1. Pasang dependensi sekali saja:
   ```powershell
   cd "C:\laragon\www\wa bot\bridge"
   npm install
   ```
2. Setelah itu Anda **tidak perlu buka terminal lagi** — PHP (`BridgeManager::ensureRunning()` / tombol **Start & Scan QR** di Dashboard Devices) akan menyalakan bridge otomatis di background!
