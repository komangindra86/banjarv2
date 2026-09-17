# Fase 4 — Administrasi surat

Status: selesai, 16 September 2026. Implementasi lokal di atas Fase 1–3.

## Fitur dan alur

1. Admin Banjar membuat/menerbitkan layanan melalui **Konten → Layanan**. Entity tetap `service_definitions` dari Fase 2.
2. **Surat → Aturan layanan** mengaktifkan pengajuan online, menentukan kebutuhan persetujuan Kelian, dan mengisi pernyataan surat. Pengajuan default nonaktif; persetujuan Kelian default diperlukan ketika layanan diaktifkan. Pernyataan berupa teks biasa, dengan identitas dan keperluan diisi otomatis dalam template PDF.
3. Krama yang akunnya terhubung ke individu dan KK aktif membuka **Surat saya → Layanan surat**, atau **Ajukan sekarang** di halaman layanan publik. Login kembali ke halaman portal yang dituju.
4. Form mengambil nama, NIK, tanggal lahir, nomor/kode KK dan alamat dari master. Pemohon hanya mengisi keperluan. Identitas lengkap tidak diketik ulang; nomor identitas disamarkan di halaman dan tersedia lengkap pada PDF privat apabila tercatat.
5. Penyarikan memeriksa identitas, keperluan dan pernyataan, lalu memverifikasi atau menolak dengan alasan. Bila diperlukan, Kelian memberi keputusan setelah verifikasi.
6. Persetujuan terakhir menerbitkan nomor surat dan snapshot arsip secara transaksional. Antrean membuat PDF A4, kemudian pemohon/pengurus yang berizin dapat mengunduhnya.

Pemohon dapat membatalkan sebelum verifikasi. Penolakan, pembatalan dan konflik tetap memiliki riwayat; pengajuan baru memakai data terbaru. Filter status, pencarian nomor surat dan pagination tersedia di daftar serta arsip. Aturan layanan yang berubah tidak mengubah pengajuan berjalan.

## Migration dan integritas

`2026_09_16_000003_create_administration_module.php` menambahkan:

| Entity | Isi |
|---|---|
| service_definitions | letters_enabled, letter_requires_approval, letter_statement, letter_version |
| letter_requests | Pemohon, layanan, krama/KK, versi master, policy dan snapshot, keperluan, status, verifier/approver |
| letter_request_steps | Histori tahap, aktor, waktu dan catatan terenkripsi |
| letter_sequences | Counter per Banjar dan tahun WITA |
| issued_documents | Nomor, snapshot saat terbit, status PDF, byte PDF terenkripsi, SHA-256 dan waktu |

Composite FK mengikat layanan, krama, KK, step dan dokumen pada Banjar yang sama. Unique constraint menjaga satu dokumen per pengajuan, nomor unik per Banjar, dan idempotency key pengiriman per pemohon. Format nomor awal `0001/ADM/2026`; counter berganti per tahun dalam WITA. Format kustom belum tersedia.

Lock baris Banjar menyerialkan submit, review, konfigurasi dan penerbitan; counter juga dikunci. Pemrosesan ulang tahap terminal ditolak dan tidak menghabiskan nomor baru. Pengiriman form yang sama mengembalikan pengajuan awal; key sama dengan payload berbeda ditolak. ID krama, pemohon, tenant dan snapshot dari browser tidak dipercaya.

Versi krama dan KK diperiksa saat submit dan setiap persetujuan. Perubahan master selama proses menghasilkan `CONFLICT` tanpa menerbitkan surat. Snapshot penerbitan mempertahankan identitas, layanan, nama/alamat Banjar, nama/jabatan penerbit dan tanggal. PDF yang sudah tersedia tidak ditimpa saat job diputar ulang atau master berubah.

## Permission

| Peran | Akses |
|---|---|
| Krama | letter.submit; mengajukan untuk diri sendiri dan membaca/mengunduh pengajuan miliknya |
| Penyarikan | letter.view, letter.verify; pemeriksaan dan arsip |
| Kelian | letter.view, letter.approve; persetujuan dan arsip sesuai scope unit yang ditugaskan |
| Admin Banjar | letter.view, letter.configure; pengaturan layanan, monitoring dan arsip |
| Kesinoman / Petengen | Tidak otomatis mendapat akses surat |
| Super Admin | Aktivasi modul; tidak otomatis mendapat akses surat privat |

Admin Banjar tidak otomatis menggantikan verifikasi Penyarikan. Pengaju, verifier dan approver harus berbeda walaupun pengguna mempunyai beberapa role. Scope Kelian menggunakan unit/turunan dari Fase 3; daftar, detail, review dan unduhan semuanya mengikuti scope server. Tautan ulang akun ke krama tidak membuka riwayat pemohon sebelumnya kepada akun baru.

ADMINISTRATION dan KRAMA harus aktif pada route dan service. Portal pengajuan ini tersedia melalui `/portal` pada domain Banjar yang sama dan mengikuti modul ADMINISTRATION, tanpa mewajibkan modul PORTAL penuh Fase 10. Role dan membership aktif diperiksa kembali pada setiap request.

## PDF, privasi dan antrean

- Dompdf 3.1.6 dipasang melalui Composer. Template Blade hanya merender teks yang di-escape; akses remote, PHP dan JavaScript Dompdf dinonaktifkan. Konfigurasi mengikuti [dokumentasi Dompdf](https://github.com/dompdf/dompdf).
- Job `GenerateLetterPdf` dijadwalkan setelah commit memakai koneksi antrean `database`, tiga percobaan, timeout 60 detik, jeda retry 10/30 detik. Payload hanya ID Banjar dan dokumen. Tenant context dibersihkan setelah sukses maupun gagal.
- Byte PDF disimpan terenkripsi di database, bukan di media publik atau direktori yang dapat diakses langsung. SHA-256 diperiksa saat unduh. Snapshot, keperluan dan catatan pemeriksaan juga terenkripsi; APP_KEY harus dipertahankan bersama backup database.
- Status PDF `PENDING`, `READY`, `FAILED` terpisah dari status surat. Sesudah kegagalan terakhir, pengurus berakses arsip dapat memilih **Coba buat PDF lagi** tanpa menerbitkan nomor baru. Jika PDF terus berstatus disiapkan, periksa worker/antrean terlebih dahulu.
- Audit menyimpan aktor, tahap, waktu dan konfigurasi yang diizinkan, tanpa identitas atau isi surat. Unduhan diaudit. Halaman privat dan respons PDF memakai `no-store`; PWA tidak menyimpan data surat. Input keperluan/catatan tidak di-flash ke session saat validasi gagal.
- Pengesahan tanda tangan/stempel tetap dilakukan pengurus. PDF menyediakan ruang tanda tangan dan nama penerbit; tidak mengklaim tanda tangan elektronik tersertifikasi. Upload hasil scan bertanda tangan belum termasuk fase ini.

Jalankan worker bersamaan dengan server lokal:

```powershell
.\scripts\serve.ps1
# Terminal lain:
.\scripts\queue.ps1
```

`queue.ps1` menjalankan worker sampai dihentikan atau satu jam; jalankan kembali bila berhenti. Setelah perubahan kode, jalankan `php artisan queue:restart` dengan PHP 8.2 dan hidupkan worker lagi. Production memerlukan process supervisor, backup dan monitoring antrean pada fase hardening. Kegagalan dapat diperiksa melalui `php artisan queue:failed`.

## Validasi

- Seluruh suite PHP fase 1–4: **70 tes / 714 assertions lulus**. Sebanyak 17 feature test Fase 4 mencakup prefill/allowlist, tenant isolation/FK, role dan separation of duties, scope Kelian, idempotency, policy/version snapshot, konflik master, pembatalan/penolakan, PDF immutable, gagal/retry, integritas unduhan, revocation, tenant context job dan redirect login portal.
- Empat tes PWA lulus; build Vite dan Pint lulus.
- Browser pada database terpisah `banjarv2_testing`: krama mengirim form, Penyarikan memverifikasi, Kelian menyetujui, worker database menyelesaikan PDF dan tombol unduh tersedia.
- Pemeriksaan viewport 390 px (daftar, layanan, form, detail, aturan layanan), 768 px (aturan layanan), dan 1280 px (persetujuan) tanpa overflow horizontal pada halaman yang diperiksa.
- PDF dirender dan diperiksa visual dengan Poppler: surat pendek satu halaman A4; contoh teks panjang tiga halaman, identitas dan isi tetap terbaca, footer/penomoran serta blok tanda tangan tidak terpotong.

## Instalasi lokal dan batas fase

Migration diterapkan pada `banjarv2`. Modul ADMINISTRATION telah diaktifkan melalui service platform untuk Biaung dan Tegal Gundul. Data uji krama, akun dan surat hanya dibuat di database pengujian; tidak menambahkan data penduduk pada dua Banjar demo. Pengurus masih perlu menyiapkan akun Penyarikan/Kelian/Krama, tautan krama, serta mengaktifkan layanan dengan pernyataan yang sesuai melalui UI.

Fase 5 telah dilanjutkan; lihat [laporan keuangan](PHASE_5_FINANCE.md). Belum ada pengajuan atas nama orang lain, upload lampiran permohonan/scan tanda tangan, workflow builder, tanda tangan elektronik, notifikasi otomatis, portal keluarga lengkap, atau deployment produksi.
