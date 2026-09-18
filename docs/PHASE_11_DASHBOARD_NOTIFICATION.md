# Fase 11 — Dashboard & Notification

Status: implementasi lokal selesai, 18 September 2026. NOTIFICATION murni merangkai event dari modul lain (ADMINISTRATION, KRAMA, FINANCE, PAYMENT); tidak menyalin data bill/surat/kegiatan, sesuai batas modul yang sudah ditetapkan sejak Fase 0.

## Alur

```
Modul lain mencapai state "perlu tindakan" (surat SUBMITTED, perubahan data SUBMITTED,
dedosan DRAFT+requires_approval, pencairan APPROVAL_PENDING/selesai diproses)
→ NotificationManager::notify()/notifyPermission() menulis baris notifications
→ (opsional) langganan push aktif → notification_deliveries PENDING → job kirim web push
→ Kotak masuk (/notifications) dan lencana pada navigasi menampilkan yang belum dibaca
```

## Fitur dan penggunaan

- **Beranda pengurus** (`/admin`) kini menampilkan hitungan tindakan yang menunggu **hanya untuk permission yang dimiliki pengurus tersebut**: dedosan menunggu persetujuan (`dedosan.approve`), pencairan menunggu persetujuan (`withdrawal.approve`), surat menunggu verifikasi/persetujuan (`letter.verify`/`letter.approve`), pengajuan perubahan data (`change.verify`/`change.approve`), dan presensi yang masih perlu dicatat di unit yang ditugaskan (`attendance.record`, dihitung lewat `ActivityAccess::participants()` yang sama dengan pencatatan presensi asli — jumlah yang tampil tidak pernah melebihi apa yang memang boleh diproses pengguna tersebut). Kartu menampilkan deskripsi umum ketika tidak ada yang menunggu.
- **Kotak masuk** (`/notifications`, tab "Notifikasi" pada navigasi dengan lencana jumlah belum dibaca): daftar notifikasi milik akun yang login, tombol tandai dibaca per item (sekaligus membuka tautan tujuannya) dan tandai semua dibaca.
- **Notifikasi push** (opsional, mengikuti VAPID di `.env`): tombol aktifkan/nonaktifkan pada halaman Kotak masuk memakai Web Push API browser. Payload yang dikirim ke perangkat selalu generik (judul singkat dan kalimat umum) — tidak pernah memuat nama krama, nominal, atau nomor surat, sesuai aturan redaksi Fase 1. Endpoint langganan disimpan terenkripsi; berhenti berlangganan menonaktifkan (bukan menghapus) baris langganan karena riwayat pengiriman lama masih merujuknya.
- Event yang memicu notifikasi saat ini: pengajuan surat baru (Penyarikan), surat menunggu persetujuan Kelian, pengajuan perubahan data baru (Kesinoman), perubahan data menunggu persetujuan Kelian, event dedosan menunggu persetujuan Kelian, permintaan pencairan menunggu persetujuan Kelian, dan hasil pencairan (disetujui/ditolak/dibayar/gagal) untuk Petengen yang mengajukan.

## Migration

`2026_09_19_000002_create_notification_module.php`:

| Tabel | Fungsi |
|---|---|
| notifications | banjar_id, user_id, type, title, body (selalu generik), url, read_at |
| notification_deliveries | Satu baris per (notifikasi, langganan push); status PENDING/SENT/FAILED/EXPIRED, attempts dibatasi CHECK, idempotency lewat UNIQUE(notification_id, subscription_id) |
| push_subscriptions | endpoint terenkripsi + endpoint_hash UNIQUE per tenant untuk pencarian, kunci p256dh/auth, expires_at (diisi otomatis saat endpoint dilaporkan 404/410 oleh browser atau saat pengguna berhenti berlangganan) |

Modul `NOTIFICATION` sudah terdaftar sejak Fase 1 tanpa dependensi modul lain (default INACTIVE untuk tenant baru). Tidak ada permission baru — event memakai permission yang sudah ada sejak Fase 3/4/5/7 (`letter.verify`, `letter.approve`, `change.verify`, `change.approve`, `dedosan.approve`, `withdrawal.approve`).

Dependency baru: `minishlink/web-push` (murni PHP, MIT). Kunci VAPID dibuat sekali per instalasi lewat `php artisan webpush:vapid` dan disalin ke `.env`; instalasi tanpa kunci tetap berjalan normal dengan push dinonaktifkan otomatis (`config('webpush.enabled')`).

## Keamanan & integritas

- `NotificationManager::notify()`/`notifyPermission()` menerima `banjar_id` eksplisit (bukan `CurrentBanjar` ambient) karena beberapa pemicu berjalan di jalur Super Admin yang tidak terikat satu tenant (mis. `WithdrawalManager` sisi platform) — konsisten dengan pola `Audit::record()` yang sudah ada.
- Pencarian penerima `notifyPermission()` selalu tenant-wide karena permission approval/verifikasi memang dipaksa scope BANJAR oleh `Access::assignments()` sejak Fase 1; tidak ada kebocoran ke unit lain karena tidak ada penyempitan unit yang perlu ditegakkan di sini.
- Payload push tidak pernah memuat data dari `body` pemanggil di luar string generik yang sudah ditulis kode pemanggil sendiri — tidak ada interpolasi nama/nominal ke dalam judul atau isi di titik manapun.
- Endpoint push disimpan terenkripsi (`Crypt::encryptString`); pencarian memakai hash SHA-256 sehingga endpoint asli tidak pernah menjadi kunci pencarian biasa.
- `SendPushNotification` memuat ulang tenant context lewat `TenantRunner` (pola yang sama dengan `GenerateLetterPdf`), retry dengan backoff, dan berhenti otomatis (FAILED) setelah 10 percobaan; balasan 404/410 langsung menandai langganan kedaluwarsa alih-alih retry sia-sia.
- Job push baru dikirim setelah transaksi pemicunya commit (`->afterCommit()`), mengikuti pola `GenerateLetterPdf` yang sudah ada.

## Pengujian

Hasil di komputer lokal (PHP 8.2.27, MySQL `banjarv2_testing`): **154 tes PHP / 1.377 assertions lulus** (146 sebelumnya + 8 baru), Pint lulus, **7 tes PWA lulus** (4 sebelumnya + 3 baru untuk push/notificationclick), build Vite berhasil, migration diterapkan ke database lokal. Diverifikasi langsung di browser pada tenant demo Biaung: Kotak masuk, kartu Beranda, dan alur aktifkan notifikasi push (izin ditolak oleh browser otomatis pada lingkungan pengujian ini, ditangani dengan pesan yang jelas alih-alih error).

`NotificationTest` (8): alur surat menotifikasi Penyarikan lalu Kelian sesuai tahap; alur perubahan data menotifikasi Kesinoman lalu Kelian; event dedosan hanya menotifikasi ketika kebijakan mewajibkan persetujuan; permintaan dan resolusi pencairan menotifikasi pihak yang tepat; hitungan belum dibaca serta tandai dibaca/semua dibaca; modul nonaktif membuat notifikasi tidak pernah tertulis; berlangganan mendorong job pengiriman push dan berhenti berlangganan menonaktifkan (bukan menghapus) baris langganan; notifikasi tidak pernah bocor lintas tenant.

Tiga tes PWA baru memverifikasi service worker: payload push yang ditampilkan selalu hanya teks generik dari server (dengan fallback aman bila payload rusak/kosong), dan notificationclick memfokuskan tab yang sudah terbuka atau membuka tab baru ke tujuan yang benar.

## Batas dan keputusan Product Owner

- Cakupan pemicu notifikasi sengaja dibatasi pada titik status yang paling jelas (submit/verify/approve/resolve); belum mencakup setiap transisi di semua modul (mis. persetujuan surat oleh Kelian sendiri belum memicu notifikasi kembali ke pengaju/Penyarikan). Menambah pemicu baru adalah pekerjaan tambahan per modul, bukan perubahan arsitektur.
- Belum ada preferensi notifikasi per pengguna (mis. memilih jenis event mana yang ingin di-push); saat ini semua event yang berlaku untuk permission pengguna selalu dikirim bila push aktif.
- PWA start_url/manifest belum diarahkan otomatis ke Kotak masuk saat notifikasi push diklik dari kondisi aplikasi tertutup di semua platform; perilaku bergantung pada dukungan browser terhadap `notificationclick`.
- Pembersihan terjadwal untuk `push_subscriptions` yang sudah lama kedaluwarsa (`expires_at` terisi lama) belum ada scheduler khusus; baris tetap tersimpan sebagai riwayat.

Fase 12 (Production hardening) belum dimulai.
