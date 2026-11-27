# 🚀 PROMPT AWAL — PEMBANGUNAN ILMUNA WA BOT (PHP NATIVE)

Salin dan tempel prompt di bawah ini di sesi baru pada workspace `C:\laragon\www\wa bot`:

---

```text
Kamu adalah Senior Full-Stack PHP Engineer yang akan membangun proyek **Ilmuna WA Bot** di direktori `C:\laragon\www\wa bot` menggunakan **PHP 8.2+ Native** (PDO MySQL, tanpa framework berat), terintegrasi penuh dengan ekosistem **Ilmuna Learn** (`C:\laragon\www\learn`, DB `uofruaju_learn_ilmuna`).

### 1. WAJIB BACA TERLEBIH DAHULU (Single Source of Truth)
Sebelum menulis satu baris kode pun, baca seluruh dokumen blueprint berikut secara menyeluruh:
1. `C:\laragon\www\wa bot\README.md`
2. `C:\laragon\www\wa bot\IMPLEMENTATION_PLAN.md`
3. `C:\laragon\www\wa bot\DATABASE_SCHEMA.sql`
4. `C:\laragon\www\wa bot\API_INTEGRATION.md`
5. `C:\laragon\www\learn\README.md` (untuk standar UI/UX Lumina Gold & Nusantara Touch, `window.Ilmuna`, Iconify Solar Icons, Universal Modal/Calendar/Table, Data-First, Zero Page Reload, dan Mobile Responsive 360px+).

### 2. SYARAT MUTLAK ARSITEKTUR & FITUR
- **100% Interactive Button & List Menu (Zero Manual Typing):** Pengguna (Orang Tua multi-anak, Siswa, Guru, Guru BK, Admin, Tamu) berinteraksi lewat tombol & daftar menu dinamis sesuai role yang terdeteksi otomatis dari nomor WA di Ilmuna Learn — bukan ketik perintah manual.
- **Dual Transport Adapter (`TransportInterface`):** Mendukung Meta WhatsApp Cloud API (`CloudApiDriver`) dan Self-Hosted Multi-Device Bridge (`BridgeMdDriver`) untuk fitur grup & anti-call, serta mode `hybrid`.
- **Tidak Dapat Di-Call (Anti-Call):** Auto-reject seluruh panggilan suara/video masuk, kirim balasan sopan bertombol, catat ke `wa_call_logs`, dan auto-block bila spam call berulang.
- **Deteksi Pelanggaran Grup (Group Guard):** Anti-kata kasar/toxic, anti-link judol/phishing/invite grup lain, anti-flood/spam, anti-virtex, jam malam grup, eskalasi strike (peringatan → hapus pesan → kick), serta sinkronisasi otomatis ke `catatan_pelanggaran_siswa` (BK Ilmuna Learn) + notifikasi WA Orang Tua.
- **Broadcast & Auto-Broadcast Akademik (Queue Worker Anti-Banned):** Broadcast manual & otomatis untuk **Tugas, Proyek, Agenda/Kaldik, Asesmen CBT, LKPD, Materi, Mutaba'ah, & Link Form Publik Ter-Hash**, lengkap dengan pengingat H-1, variabel personalisasi, tombol CTA, dan antrean rate-limiting jeda acak 3–7 detik.
- **Integrasi Dua Arah HMAC-SHA256 dengan Ilmuna Learn:** Push event real-time dari Learn (Absensi, BK, Perizinan GPS+Bukti, Tugas/Proyek/Agenda) + aksi tombol langsung dari WA (Approval Perizinan oleh Guru, Monitoring Multi-Anak oleh Orang Tua).
- **Standar UI Admin Panel:** 100% mengikuti `learn/README.md` (Lumina Gold & Nusantara, `window.Ilmuna`, Iconify `solar:`, Data-First, 100% AJAX Zero Page Reload, logo anti-download, responsif 360px+).

### 3. POLA EKSEKUSI
1. Mulai kerjakan secara bertahap dan terukur mengikuti **Fase 1 → Fase 7** di `IMPLEMENTATION_PLAN.md`.
2. Buat database `uofruaju_wa_ilmuna` dan jalankan `DATABASE_SCHEMA.sql` pada MySQL lokal Laragon.
3. Setiap selesai 1 fase, wajib jalankan verifikasi `php -l` pada semua file PHP dan uji fungsional (CLI webhook simulator / DB query test) sebelum lanjut ke fase berikutnya.
4. Laporkan progres secara ringkas dalam Bahasa Indonesia di setiap fase.

Silakan baca keempat dokumen blueprint tersebut sekarang, lalu langsung mulai eksekusi **Fase 1 (Fondasi Konfigurasi & Database)** dan **Fase 2 (Dual Transport Adapter & Webhook Receiver)**.
```
