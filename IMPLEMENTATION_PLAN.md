# 🛠️ IMPLEMENTATION PLAN — ILMUNA WA BOT (PHP NATIVE)

Dokumen ini menjadi panduan eksekusi terstruktur untuk membangun **Ilmuna WA Bot** di direktori `C:\laragon\www\wa bot` hingga siap produksi dan terintegrasi penuh dengan **Ilmuna Learn (`C:\laragon\www\learn`)**.

---

## 1. 📂 Struktur Direktori Target (`C:\laragon\www\wa bot`)

```text
C:\laragon\www\wa bot\
├── README.md                          # Blueprint utama sistem
├── IMPLEMENTATION_PLAN.md             # Rencana kerja bertahap (dokumen ini)
├── DATABASE_SCHEMA.sql                # Skema 14 tabel MySQL InnoDB
├── API_INTEGRATION.md                 # Spesifikasi API Learn <-> WA Bot
├── .htaccess                          # Clean URL & proteksi file sensitif
├── config.php                         # Konfigurasi DB, HMAC secret, Driver WA, URL Learn
├── database.php                       # Singleton PDO + auto-migrasi skema
├── webhook.php                        # Endpoint penerima event WA (pesan, klik tombol, grup, call)
├── index.php                          # Redirect ke dashboard admin / login
├── login.php                          # Login admin WA Bot (atau SSO Margo One)
│
├── core/
│   ├── TransportInterface.php         # Kontrak driver pengiriman WA
│   ├── CloudApiDriver.php             # Driver Meta WhatsApp Cloud API (Button/List/Template)
│   ├── BridgeMdDriver.php             # Driver Self-Hosted MD Bridge (Grup, Hapus Pesan, Reject Call)
│   ├── TransportFactory.php           # Pemilih driver (Cloud / Bridge / Hybrid)
│   ├── InteractiveMenuEngine.php      # State machine tombol interaktif berbasis Role (Zero Typing)
│   ├── GroupGuardEngine.php           # Detektor pelanggaran grup (toxic, judol, link, flood, quiet hours)
│   ├── AntiCallEngine.php             # Penolak panggilan masuk + pelacak spam call
│   ├── BroadcastEngine.php            # Penyusun target, personalisasi variabel, dan antrean kirim
│   └── LearnBridgeClient.php          # Klien HTTP HMAC-SHA256 ke Ilmuna Learn
│
├── api/
│   ├── v1/
│   │   ├── notify.php                 # Penerima push event dari Learn (absensi, BK, izin, tugas)
│   │   ├── broadcast.php              # API trigger broadcast dari Learn / Admin
│   │   └── status.php                 # Cek kesehatan koneksi device & antrean
│
├── workers/
│   └── broadcast-worker.php           # CLI Worker pengirim antrean broadcast (rate-limit 3–7 detik)
│
├── dashboard/
│   ├── index.php                      # Overview live (status device, antrean, log pelanggaran & call)
│   ├── devices.php                    # Pengaturan koneksi Cloud API / QR Bridge MD
│   ├── menus.php                      # Editor visual tombol interaktif & alur menu per role
│   ├── broadcast.php                  # Buat kampanye broadcast (filter kelas/ortu/siswa/guru/grup)
│   ├── group-guard.php                # Pengaturan grup, daftar kata terlarang, whitelist link, log pelanggaran
│   ├── anti-call.php                  # Pengaturan auto-reject call, pesan balasan, daftar nomor diblokir
│   └── logs.php                       # Log pesan masuk/keluar & sinkronisasi Ilmuna Learn
│
└── assets/
    ├── css/                           # Tema Lumina Gold & Nusantara (seragam dengan Learn)
    ├── js/                            # alert.js (window.Ilmuna) & modul AJAX panel admin
    └── img/                           # Logo & watermark Gunungan
```

---

## 2. 🗓️ Tahapan Implementasi (7 Fase)

### Fase 1 — Fondasi Konfigurasi & Database
- [ ] Membuat `config.php` (koneksi DB `uofruaju_wa_ilmuna`, `WA_DRIVER` = `hybrid|cloud_api|md_bridge`, `LEARN_API_BASE_URL`, `LEARN_HMAC_SECRET`, zona waktu `Asia/Jakarta`).
- [ ] Menjalankan `DATABASE_SCHEMA.sql` untuk membentuk 14 tabel inti dan seed menu tombol default + kamus kata terlarang awal.
- [ ] Menyiapkan `.htaccess` (clean URL, blokir akses langsung ke `core/`, `workers/`, `.sql`, `.md`).

### Fase 2 — Transport Layer (Dual Driver) & Webhook Receiver
- [ ] Membangun `TransportInterface.php` dengan method standar:
  - `sendText()`, `sendButtons()`, `sendListMenu()`, `sendCtaUrl()`, `sendMedia()`
  - `deleteGroupMessage()`, `kickGroupMember()`, `rejectCall()`, `blockContact()`
- [ ] Implementasi `CloudApiDriver.php` (Meta Graph API v21.0: `interactive.type = button | list | cta_url`).
- [ ] Implementasi `BridgeMdDriver.php` (adapter HTTP JSON ke self-hosted MD bridge untuk fitur grup & reject call, termasuk fallback *interactive/poll* bila klien WA lama).
- [ ] Membangun `webhook.php` dengan verifikasi signature, idempotency check (`wa_webhook_events` agar 1 pesan tidak diproses 2×), dan routing cepat ke 3 engine utama.

### Fase 3 — Interactive Button Engine (Zero Manual Typing)
- [ ] Membangun `InteractiveMenuEngine.php` berbasis state di `wa_sessions`:
  - Deteksi role otomatis dari nomor HP (cache di `wa_contacts_cache` + refresh berkala ke Ilmuna Learn).
  - Handler klik tombol berdasarkan `button_id` / `list_row_id` (misal `MENU_ORTU_NILAI`, `ORTU_PICK_CHILD_12`, `GURU_APPROVE_IZIN_45`).
  - Dukungan **Multi-Anak** untuk role `orang_tua` (`orang_tua_siswa`).
  - Fallback ramah: jika pengguna mengetik teks bebas, bot tidak error melainkan membalas dengan tombol menu utama sesuai role.

### Fase 4 — Anti-Call & Group Violation Guard
- [ ] Membangun `AntiCallEngine.php`:
  - Menangkap event panggilan masuk (`voice` & `video`), langsung memanggil `rejectCall()`.
  - Mencatat ke `wa_call_logs`, menghitung frekuensi panggilan dalam 5 menit terakhir, dan memicu `blockContact()` jika melewati ambang batas spam.
  - Mengirim balasan sopan otomatis berisi tombol `[📋 Menu Utama]` dan `[📞 Kontak Admin]`.
- [ ] Membangun `GroupGuardEngine.php`:
  - Evaluasi berurutan: `Quiet Hours` → `Anti-Virtex` → `Anti-Flood` → `Anti-Link/Judol` → `Anti-Kata Kasar`.
  - Menghitung strike per nomor per grup di `wa_violation_logs`.
  - Aksi otomatis sesuai `wa_group_rules` (hapus pesan, kirim teguran dengan mention, kick pada strike ke-3).
  - Sinkronisasi opsional ke `catatan_pelanggaran_siswa` di Ilmuna Learn + notifikasi WA ke Orang Tua jika pelaku adalah siswa.

### Fase 5 — Broadcast Engine, Auto-Broadcast Akademik & Queue Worker
- [ ] Membangun `BroadcastEngine.php`:
  - Resolver audiens dari Ilmuna Learn (semua siswa, siswa per kelas, semua orang tua, orang tua per kelas, semua guru, atau daftar grup WA).
  - **Auto-Broadcast Sumber Akademik Ilmuna Learn** (bisa ditarik langsung 1-klik dari Admin WA Bot maupun terpicu otomatis dari Learn):
    - **Tugas** (`tugas`): rilis tugas baru + pengingat tenggat H-1 / 3 jam sebelum deadline.
    - **Proyek** (`proyek`): rilis proyek baru, info kelompok, & pengingat tenggat proyek.
    - **Agenda & Kaldik** (`kalender_pendidikan`): agenda harian/mingguan, kegiatan sekolah, & hari libur.
    - **Asesmen / CBT / LKPD / Remedial** (`asesmen_*`, `lkpd`, `pengayaan_remedial`): jadwal ujian & pengingat sesi.
    - **Materi Baru & Pengingat Mutaba'ah** (`materi`, `mutabaah_logs`) + **Link Form Publik Ter-Hash** (`public_form_links`).
  - Template personalisasi variabel (`{nama}`, `{kelas}`, `{nama_anak}`, `{judul}`, `{tenggat}`, `{tanggal}`, `{link_learn}`).
  - Dukungan lampiran media + tombol CTA/Quick Reply.
- [ ] Membangun `workers/broadcast-worker.php` & `workers/reminder-scheduler.php`:
  - Berjalan via Cron / Windows Task Scheduler (tiap 1 menit untuk queue, tiap 15 menit untuk pengecekan reminder H-1 Tugas/Proyek/Agenda/Asesmen) dengan *file lock* anti-tumpuk.
  - Mengirim maksimal N pesan per batch dengan jeda acak `3–7 detik`, *retry* otomatis maksimal 3× bila gagal jaringan, dan pembaruan status *real-time* di `wa_broadcast_campaigns`.

### Fase 6 — Bridge Integrasi Dua Arah dengan Ilmuna Learn
- [ ] Di sisi **Ilmuna Learn (`C:\laragon\www\learn`)**:
  - Menambahkan endpoint `api/wa-bridge.php` (autentikasi HMAC-SHA256) untuk:
    - Lookup role & data profil berdasarkan nomor HP (`lookup-contact`).
    - Tarik ringkasan Nilai, Tugas, Proyek, Absensi, Perizinan, & Catatan BK anak (`student-summary`).
    - Aksi approval perizinan dari tombol WA Guru (`approve-perizinan`).
    - Ambil daftar penerima broadcast per kelas/role (`audience-list`) & tautan hash form publik aktif (`public-forms`).
  - Menambahkan helper `includes/wa-notifier.php` (non-blocking / timeout 2 detik) yang dipanggil saat:
    - Guru/Ketua Kelas mencatat absensi Alpa/Terlambat.
    - Guru BK mencatat pelanggaran baru.
    - Siswa mengajukan perizinan baru.
    - Guru menerbitkan tugas/asesmen baru.
- [ ] Di sisi **WA Bot (`C:\laragon\www\wa bot`)**:
  - Membangun `api/v1/notify.php` untuk menerima push event dari Learn dan langsung memasukkan ke antrean prioritas tinggi (*instant send*).

### Fase 7 — Admin Panel (Lumina Gold) & Pengujian
- [ ] Membangun 7 halaman dashboard admin (100% AJAX Zero Page Reload, `window.Ilmuna`, Iconify Solar, Mobile Responsive 360px+).
- [ ] Verifikasi menyeluruh:
  1. `php -l` seluruh file PHP.
  2. Simulasi payload webhook via PHP CLI (`call.offer`, `group.message` dengan kata kasar/link judol, `button.reply` untuk Ortu multi-anak & Guru approval izin).
  3. Uji antrean broadcast dengan 20 penerima uji (pastikan jeda rate-limit, personalisasi, dan idempotency bekerja).
  4. Uji keamanan: tolak request tanpa signature HMAC valid, uji SQL injection & XSS pada input pesan grup.
