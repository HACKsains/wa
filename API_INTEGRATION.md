# 🔗 API INTEGRATION CONTRACT — ILMUNA LEARN ↔ ILMUNA WA BOT

Dokumen ini mendefinisikan protokol pertukaran data dua arah antara:
- **Ilmuna Learn (SIAKAD/LMS):** `C:\laragon\www\learn` (`https://learn.ilmuna.my.id`)
- **Ilmuna WA Bot (PHP Native):** `C:\laragon\www\wa bot` (`https://wa.ilmuna.my.id`)

---

## 1. 🔐 Standar Autentikasi (HMAC-SHA256 + Anti-Replay)

Setiap request server-to-server wajib menyertakan header berikut:

| Header | Keterangan |
|---|---|
| `X-Ilmuna-Key` | Public key integrasi (`WA_BRIDGE_PUBLIC_KEY`) |
| `X-Ilmuna-Timestamp` | Unix timestamp (detik). Ditolak jika selisih > 300 detik |
| `X-Ilmuna-Signature` | `hash_hmac('sha256', $timestamp . '.' . $rawBody, $sharedSecret)` |
| `Content-Type` | `application/json; charset=utf-8` |

---

## 2. 📤 Arah 1: Ilmuna Learn → WA Bot (Push Notifikasi Otomatis)

Endpoint di WA Bot: **`POST /api/v1/notify.php`**

### A. Notifikasi Absensi Siswa (Alpa / Terlambat)
```json
{
  "event": "absensi_alert",
  "student_name": "Ahmad Fauzan",
  "kelas": "X PAI-1 (Imam Al-Bukhari)",
  "status": "Alpa",
  "tanggal": "2026-11-27",
  "recipients": ["6281234567890", "6281298765432"],
  "portal_url": "https://learn.ilmuna.my.id/dashboard/orang-tua"
}
```
> **Hasil di WA Orang Tua:** Pesan otomatis lengkap + 2 tombol interaktif `[📩 Konfirmasi Alasan]` & `[🌐 Buka Portal Ortu]`.

### B. Notifikasi Catatan Pelanggaran BK
```json
{
  "event": "pelanggaran_bk",
  "student_name": "Ahmad Fauzan",
  "kelas": "X PAI-1",
  "jenis_pelanggaran": "Terlambat masuk kelas > 15 menit",
  "poin": 10,
  "indeks_disiplin_sisa": 90,
  "recipients": ["6281234567890"]
}
```

### C. Notifikasi Pengajuan Perizinan Siswa (ke Guru / Wali Kelas)
```json
{
  "event": "perizinan_baru",
  "izin_id": 45,
  "student_name": "Aisyah Putri",
  "kelas": "XI PAI-1",
  "jenis_izin": "Sakit",
  "alasan": "Demam tinggi (surat dokter terlampir)",
  "gps_link": "https://maps.google.com/?q=-7.1234,110.4321",
  "bukti_url": "https://learn.ilmuna.my.id/uploads/perizinan/bukti_45.jpg",
  "recipients": ["6281311122233"]
}
```
> **Hasil di WA Guru:** Foto bukti + info lokasi GPS + 2 tombol aksi langsung **`[✅ Setujui Izin]`** (`GURU_APPROVE_IZIN_45`) dan **`[❌ Tolak Izin]`** (`GURU_REJECT_IZIN_45`).

### D. Auto-Broadcast Tugas, Proyek, Agenda, Asesmen & Materi Baru
```json
{
  "event": "academic_broadcast",
  "source_module": "tugas | proyek | agenda | asesmen | lkpd | materi | mutabaah",
  "source_ref_id": 108,
  "title": "Tugas Fiqih Ibadah — Praktik Wudhu",
  "kelas_ids": [1, 2],
  "target_roles": ["siswa", "orang_tua", "group_kelas"],
  "deadline_at": "2026-11-30 23:59:00",
  "summary": "Kumpulkan video praktik wudhu melalui portal Ilmuna Learn sebelum tenggat.",
  "action_url": "https://learn.ilmuna.my.id/dashboard/siswa/tugas"
}
```
> **Hasil di WA Siswa, Orang Tua & Grup Kelas:** Pesan terformat rapi + tombol CTA **`[📚 Buka di Ilmuna Learn]`** & tombol cepat **`[✅ Tandai Sudah Dibaca]`** (serta penjadwalan pengingat otomatis H-1 tenggat).

---

## 3. 📥 Arah 2: WA Bot → Ilmuna Learn (Query & Aksi Tombol)

Endpoint di Ilmuna Learn: **`POST /api/wa-bridge.php`**

| `action` | Dipicu Oleh | Fungsi |
|---|---|---|
| `lookup_contact` | Pesan masuk pertama kali | Cek `phone_e164` di `orang_tua`, `siswa`, `guru`, `users` → kembalikan `role`, `nama`, dan daftar anak (jika `orang_tua`) |
| `student_summary` | Klik tombol `[📊 Nilai & Tugas]`, `[📅 Absensi & Izin]`, `[⚠️ Catatan BK]` | Mengembalikan ringkasan nilai, tugas aktif, proyek, rekap hadir/sakit/izin/alpa, dan total poin BK siswa |
| `upcoming_academic` | Scheduler reminder & tombol `[📅 Agenda & Tenggat]` | Mengambil daftar **Tugas**, **Proyek**, **Agenda/Kaldik**, dan **Asesmen CBT** yang mendekati tenggat (H-1 / hari ini) untuk di-broadcast otomatis |
| `approve_perizinan` | Klik tombol `[✅ Setujui Izin]` / `[❌ Tolak Izin]` oleh Guru | Validasi nomor WA adalah guru sah → update status `perizinan` di DB Learn → kirim konfirmasi balik ke siswa & orang tua |
| `record_group_violation` | Deteksi pelanggaran berat di Grup WA oleh siswa | Opsional: simpan catatan pelanggaran ke `catatan_pelanggaran_siswa` agar langsung masuk perhitungan 70/30 Afektif BK |
| `audience_list` | Kampanye Broadcast dari Admin WA Bot | Mengambil daftar nomor WA valid sesuai filter (`all_ortu`, `ortu_kelas`, `all_siswa`, `siswa_kelas`, `all_guru`) |
| `public_forms` | Klik tombol `[📝 Isi Form Siswa]` / `[👨‍🏫 Isi Form Guru]` | Mengambil tautan hash terbaru yang aktif dari tabel `public_form_links` |

---

## 4. 🔁 Normalisasi Nomor WhatsApp

Semua nomor HP dari database Ilmuna Learn (`0812-3456-789`, `+62 812...`, `62812...`) wajib dinormalisasi oleh helper `wa_normalize_phone()` sebelum pencocokan atau pengiriman:
1. Hapus seluruh karakter non-digit (`preg_replace('/\D+/', '', $raw)`).
2. Jika diawali `0`, ganti dengan `62`.
3. Jika diawali `8`, tambahkan prefix `62`.
4. Validasi panjang minimal 10 digit dan maksimal 15 digit.
