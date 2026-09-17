# Fase 8 — Kegiatan & presensi

Status: implementasi lokal selesai, 17 September 2026. Entitas `activities` dari Fase 2 dipakai ulang; tidak ada event engine kedua.

## Alur

```
Agenda (Konten) → Pengaturan presensi → Target peserta → Daftar peserta (snapshot)
→ Pencatatan presensi / izin mandiri krama → Verifikasi & kunci
→ Calon dedosan per KK → Pilih penanggung jawab / bebaskan → Event dedosan DRAFT
→ Persetujuan Kelian & terbit (alur Keuangan Fase 5)
```

## Fitur dan penggunaan

- **Kegiatan** (`/admin/activities`, menu "Kegiatan" saat modul ACTIVITY = Aktif): daftar kegiatan mendatang/lalu dengan ringkasan kehadiran. Kegiatan dibuat dan diedit lewat formulir agenda yang sama dengan website.
- **Pengaturan** (Admin Banjar/Penyarikan): jenis (Umum, Paruman, Ngayah, Gotong royong, Upacara, Rapat), tampil di website atau internal, basis presensi `HOUSEHOLD` (per KK) atau `PERSON` (per individu), dan aturan perwakilan (anggota mana pun atau harus kepala keluarga). Basis tidak dapat diganti setelah daftar peserta dibuat.
- **Target peserta**: Semua KK aktif, Semua krama aktif, Unit (opsional termasuk sub-unit), Pengurus dengan peran, Krama tertentu. Target digabung dan dideduplikasi; individu/KK dari beberapa target dihitung sekali. Tombol **Buat/Perbarui daftar peserta** membuat snapshot; pembaruan mempertahankan catatan presensi yang ada dan menolak menghapus peserta yang sudah dicatat.
- **Presensi** (`/attendance`): kartu per peserta dengan tombol besar Hadir/Izin/Sakit/Tidak hadir, filter status, pencarian, catatan opsional. Per KK: pilih anggota yang hadir (default kepala keluarga); anggota KK lain ditolak. Per individu: tidak dapat diwakilkan. Setelah kegiatan dimulai tersedia **Tandai sisanya tidak hadir**. Kesinoman/pengurus dengan scope unit hanya melihat dan mencatat KK dalam unitnya.
- **Izin mandiri krama** (`/aktivitas`, menu "Kegiatan saya"): krama yang akunnya terhubung melihat kegiatan yang mencatat dirinya/KK-nya dan dapat memberi tahu Izin/Sakit dengan alasan sampai kegiatan selesai. Pemberitahuan tidak dapat menimpa catatan pengurus.
- **Verifikasi** (Kelian/Penyarikan): hanya setelah kegiatan dimulai dan semua peserta tercatat. Presensi lalu dikunci. KK dengan status Tidak hadir menjadi **calon dedosan** — bukan tagihan (Rule 11). Satu calon per KK per kegiatan, meskipun beberapa anggota tidak hadir (Rule 10).
- **Calon dedosan** (`/dedosan`, Petengen): pilih satu anggota aktif KK sebagai penanggung jawab (disarankan: yang tidak hadir atau kepala keluarga) atau bebaskan dengan alasan. Setelah semua diputuskan, **Buat event dedosan** membuat event Keuangan berstatus DRAFT dengan referensi `DEDOSAN-KEG-{id}`, satu subjek per KK, dan alasan otomatis dari presensi. Persetujuan Kelian dan penerbitan memakai alur Fase 5 tanpa perubahan.
- **Notulen** (`/minutes`): agenda pembahasan, notulen, keputusan (satu per baris), lampiran dari pustaka media (dapat privat), versi untuk mencegah tabrakan edit, dan penetapan final yang membuat notulen tidak dapat diubah.

## Migration

`2026_09_18_000001_create_activity_operations.php`:

| Tabel/perubahan | Fungsi |
|---|---|
| activities (+kolom) | kind, visibility PUBLIC/INTERNAL, attendance_basis, allow_representative, attendance_status NONE/DRAFT/VERIFIED, participants_generated_at, verified_by/at, dedosan_billing_event_id (unik) |
| activity_targets | Target relasional (unit+sub-unit, role, krama) dengan kunci unik per kegiatan; CHECK bentuk target |
| activity_participants | Snapshot peserta: basis, KK + kode KK, krama (PERSON), status, attending_krama_id, source STAFF/SELF, catatan, pencatat/waktu. Unik satu slot per KK (HOUSEHOLD) dan per individu (PERSON); CHECK basis dan perwakilan hanya untuk Hadir |
| dedosan_candidates | Satu calon per KK per kegiatan; jumlah slot tidak hadir, saran & penanggung jawab, status PENDING/SELECTED/DISMISSED/BILLED, event dedosan; CHECK konsistensi keputusan |
| activity_minutes, activity_decisions, activity_attachments | Notulen berversi & final, keputusan terurut, lampiran media tenant |

Permission baru: `activity.view` (Admin, Kelian, Petengen, Penyarikan, Kesinoman), `attendance.record` (Admin, Penyarikan, Kesinoman — boleh scope unit), `attendance.verify` (Kelian, Penyarikan), `activity.self` (Krama). Pengelolaan kegiatan/target/notulen memakai `activity.manage` yang sudah ada; calon dedosan memakai `dedosan.issue`.

Gerbang modul: ACTIVITY **BASIC** tetap hanya agenda publik; semua fitur operasional memerlukan ACTIVITY **ACTIVE** dan KRAMA. Agenda internal tidak tampil di website.

## Keamanan & integritas

- Semua penulisan mengunci baris Banjar lalu baris kegiatan; transisi status diperiksa di server. Unique constraint mencegah peserta/calon ganda walau dijalankan paralel.
- Isolasi tenant lewat `TenantQuery` dan composite FK; ID kegiatan Banjar lain menghasilkan 404. Scope unit diterapkan pada daftar dan pencatatan peserta.
- Presensi terverifikasi tidak dapat diubah; target, basis, dan daftar peserta juga dikunci. Event dedosan dari kegiatan idempotent (satu per kegiatan).
- Audit: konfigurasi, target, pembuatan peserta, setiap pencatatan, verifikasi, keputusan calon, event dedosan, notulen dan finalisasi.

## Pengujian

Hasil di komputer lokal (PHP 8.2.27, MySQL `banjarv2_testing`): **125 tes PHP / 1.259 assertions lulus**, Pint lulus, 4 tes PWA lulus, build Vite berhasil, dan migration diterapkan ke database lokal `banjarv2`. Halaman baru belum diuji dengan data nyata di browser karena Banjar demo belum memiliki data KK/krama.

- `ActivityTest` (8): union & dedup target PERSON/HOUSEHOLD termasuk anggota unit individual; regenerasi mempertahankan catatan dan menolak menghapus peserta tercatat; aturan perwakilan KK dan PERSON tidak dapat diwakili; scope unit Kesinoman, izin permission, gerbang BASIC, agenda internal tidak publik, isolasi tenant; izin mandiri krama hanya slot sendiri dan tidak menimpa catatan pengurus; verifikasi mengunci dan menghasilkan calon (bukan tagihan), satu calon per KK; keputusan calon → event dedosan DRAFT → persetujuan Kelian → tagihan satu individu per KK; notulen/keputusan/lampiran/final dan halaman HTTP.
- `ActivityConcurrencyTest`: dua proses paralel membuat daftar peserta (tanpa duplikasi) dan pencatatan terakhir vs verifikasi (satu keadaan konsisten, tanpa tagihan).

## Batas dan keputusan Product Owner

- Membuka kembali presensi yang sudah diverifikasi (koreksi) belum tersedia; perlu prosedur koreksi berpersetujuan.
- Pemisahan tugas pencatat ≠ pemverifikasi belum diwajibkan.
- Tidak ada presensi QR/geolokasi; pencatatan dilakukan pengurus atau pemberitahuan izin oleh krama.
- Nominal dedosan per jenis kegiatan belum diotomatiskan; dipilih saat membuat event.
- Notifikasi (pengingat kegiatan, izin baru) menunggu Fase 11.

Fase 9 belum dimulai.
