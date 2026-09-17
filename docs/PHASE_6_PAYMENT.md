# Fase 6 — Midtrans QRIS dan ledger

Status: implementasi lokal selesai, 17 September 2026. Integrasi eksternal memakai Midtrans Core API QRIS; kredensial merchant belum dikonfigurasi dan belum dilakukan transaksi sandbox/live ke Midtrans.

## Fitur dan penggunaan

- **Tagihan & QRIS** (`/payments`): satu pembayaran penuh untuk satu tagihan. Anggota Krama aktif yang akunnya terhubung ke master dapat melihat dan membayar tagihan KK aktifnya. Petengen dapat membuat QRIS untuk tagihan tenant. Nominal diambil dari tagihan pada server.
- **Dana QRIS** (`/admin/payments`): Admin Banjar, Kelian dan Petengen dengan scope Banjar melihat saldo ledger, riwayat pembayaran, dan 50 baris jurnal terbaru. Kas manual tetap terpisah.
- **QRIS** pada Super Admin (`/platform/payments`): antrean pembayaran, mismatch, hasil rekonsiliasi, dan pencatatan referensi bukti settlement provider/bank.
- Bukti berisi referensi yang dapat diverifikasi, biaya gateway aktual, dan waktu settlement WITA. Referensi disimpan terenkripsi. Super Admin berbeda wajib memeriksa sebelum persetujuan. Bukti yang disetujui tidak dapat ditimpa.
- Tidak ada payout API atau pencairan dalam fase ini. RESERVED dan PAID_OUT disediakan sebagai akun ledger untuk fase berikutnya.

## Migration

`2026_09_17_000001_create_payment_module.php` telah diterapkan pada database lokal `banjarv2`.

| Tabel | Fungsi |
|---|---|
| payments | Order opaque ULID, snapshot nominal/merchant/environment/hold, status, QR, waktu dan biaya |
| payment_attempt_locks | Satu pointer attempt aktif per Banjar + tagihan |
| payment_webhook_receipts | Inbox durable teredaksi, fingerprint unik, retry/backoff |
| financial_accounts | Akun per Banjar, bucket, IDR |
| financial_ledger_transactions | Jurnal dengan referensi pembayaran, tipe, hash dan idempotency key unik |
| financial_ledger_entries | Debit/kredit positif; relasi akun dan jurnal satu tenant |
| payment_settlement_evidence | Referensi settlement terenkripsi, fee aktual, pengaju dan pemeriksa |
| reconciliation_runs | Waktu pemeriksaan, jumlah diproses/gagal |
| reconciliation_items | Mismatch terbuka yang menahan availability |
| bill_settlements | Ditambahkan payment_id dan constraint unik/FK tenant |

Uang berupa integer rupiah, 1–999.999.999.999 per pembayaran/baris jurnal. `order_id` dan ID transaksi Midtrans unik global. Composite FK mencegah relasi lintas tenant. Ledger hanya ditambahkan melalui `LedgerService`; tidak ada endpoint edit/hapus jurnal atau saldo. Akun database production perlu dibatasi INSERT/SELECT untuk tabel jurnal pada fase hardening.

## Pembuatan, verifikasi, dan concurrency

`PaymentGatewayInterface` memisahkan `createQrisPayment`, `getStatus`, dan `verifyNotification`; binding V1 adalah `MidtransPaymentGateway`. Autentikasi Basic menggunakan server key environment. Tidak ada PII yang dikirim pada charge. QR URL dibatasi pada HTTPS host API Midtrans environment terkait dan path QR yang diizinkan.

Penulisan mengunci baris Banjar yang juga digunakan kas/tagihan fase 5. Attempt CREATING disimpan dan di-commit sebelum HTTP. Request bersamaan mengembalikan attempt yang sama. Timeout menjadi UNKNOWN, tanpa charge ulang otomatis. Status 404 provider juga tidak mengizinkan order baru karena hasil charge masih belum pasti. Hanya EXPIRED/DENIED/CANCELLED yang terverifikasi dapat diganti attempt baru. Pergantian merchant/environment pada konfigurasi menolak pemrosesan order lama dengan konfigurasi yang salah.

Webhook: `POST /webhooks/midtrans` pada host platform, JSON maksimal 32 KiB. Hanya endpoint ini dikecualikan dari CSRF. Verifikasi SHA-512 memakai string nominal asli dan perbandingan constant-time, lalu mencocokkan order, merchant, nominal, currency, metode, dan ID transaksi. Tenant berasal dari payment tersimpan, bukan host/payload. Inbox disimpan sebelum ACK, lalu job memanggil Get Status API. Scheduler memulihkan inbox jika enqueue atau worker gagal. Raw payload, signature, dan nama pembayar tidak disimpan.

Status QRIS `settlement` dari pemeriksaan API server menghasilkan satu pelunasan dan satu jurnal received dalam transaksi yang sama. Callback sebelum respons charge serta notifikasi terlambat tidak menurunkan PAID. Status capture generik tidak dianggap sukses QRIS. Duplikat webhook/polling tidak menambah pelunasan atau ledger lagi. Pemrosesan transaksi lama tetap berjalan saat tenant disuspend atau modul dinonaktifkan; pembuatan transaksi baru tetap ditolak.

Jika pelunasan manual lebih dulu, bill sudah VOID, atau dua attempt ternyata dibayar, uang masuk tambahan dicatat ke EXCEPTION dan mismatch dibuat. Gateway tidak membuat transaksi kas. Jika gateway lebih dulu, pelunasan manual ditolak oleh aturan tagihan fase 5. Pembayaran gateway tidak dapat dibalik melalui tombol pembalikan kas.

## Pending, biaya, dan availability

Saat fee aktual belum diketahui, seluruh gross masuk PENDING sebagai nilai sementara. `gateway_fee` dan `net_amount` tetap NULL, bukan dianggap nol. Platform fee saat ini 0.

Contoh Rp50.000 dengan biaya aktual Rp350 (ilustrasi, bukan tarif Midtrans):

| Tahap | Debit | Kredit |
|---|---|---|
| Pembayaran tervalidasi | CLEARING 50.000 | PENDING 50.000 |
| Bukti/fee disetujui | PENDING 350 | FEE 350 |
| Hold selesai dan status diperiksa ulang | PENDING 49.650 | AVAILABLE 49.650 |

Setiap movement mempunyai kunci `payment:{id}:{type}` unik dan jurnal seimbang. Pengiriman ulang dengan isi berbeda ditolak. Saldo dihitung dari kredit minus debit ledger.

`paid_at` adalah waktu backend memverifikasi pembayaran. `settlement_at` berasal dari bukti yang diperiksa dua pengurus platform. `available_at` = waktu settlement + snapshot hold hari kalender. Policy kosong membuat dana tetap pending; policy tidak diasumsikan sebagai aturan provider. Tetapkan policy sebelum menerima pembayaran; mengganti konfigurasi tidak mengubah snapshot order lama. Fee eksplisit nol tetap memerlukan evidence dan persetujuan.

Rekonsiliasi memeriksa provider, keseimbangan jurnal, keberadaan received, bill dan settlement. Pelepasan memerlukan evidence approved, hold selesai, status provider settlement yang baru diperiksa, serta tidak ada flag mismatch. Status refund/chargeback, nominal/identitas tidak cocok, atau status tak dikenal menahan pembayaran. Bila dana sudah AVAILABLE, journal quarantine memindahkan net ke EXCEPTION. Tidak ada refund otomatis atau penghapusan pelunasan diam-diam.

## Konfigurasi dan operasional

1. Siapkan merchant sandbox Midtrans dengan QRIS aktif. Isi `.env` sendiri: `MIDTRANS_SERVER_KEY`, `MIDTRANS_MERCHANT_ID`, `MIDTRANS_PRODUCTION=false`, `MIDTRANS_ENABLED=true`.
2. Tetapkan `PAYMENT_SETTLEMENT_HOLD_DAYS` sesuai kebijakan yang disetujui (0–365 hari kalender). Kosong berarti availability ditahan. Jangan mengambil angka ilustrasi sebagai kebijakan merchant.
3. Bersihkan/rebuild config cache jika digunakan. Aktifkan modul PAYMENT lewat Super Admin setelah KRAMA dan FINANCE aktif.
4. Konfigurasikan Payment Notification URL pada dashboard Midtrans ke `https://<host-platform>/webhooks/midtrans`. Localhost tidak dapat menerima webhook publik. Deployment/tunnel publik belum dibuat pada fase ini.
5. Jalankan worker menggunakan `scripts/queue.ps1` dan scheduler dengan PHP 8.2: `php artisan schedule:work` untuk lokal. Production memakai pemicu `schedule:run` setiap menit. Command rekonsiliasi dijadwalkan setiap lima menit dengan lock anti-overlap.
6. Pemeriksaan manual: `php artisan payments:reconcile --limit=100`. Maksimum 100 receipts dan 100 payments per run. Pending/UNKNOWN serta transaksi terminal dipoll dengan jarak minimal 15 menit; receipts gagal memakai backoff 30 detik hingga satu jam. Untuk volume tinggi, pisahkan antrean/retensi polling pada fase hardening.
7. Super Admin membuka QRIS → pembayaran diterima → catat referensi bukti serta fee → Super Admin lain menyetujui. Scheduler merilis setelah semua kondisi terpenuhi. Monitor mismatch dan run yang gagal/tidak selesai.

Pada instalasi lokal, PAYMENT pada dua Banjar demo tetap nonaktif dan gateway tidak diaktifkan. Tidak ada transaksi uji yang dimasukkan ke database aplikasi. Semua HTTP gateway dalam tes di-fake; penerimaan sandbox nyata memerlukan kredensial merchant dan endpoint publik. Jangan memasukkan key ke Git.

## Pengujian dan batas

- Suite Fase 1–6: **107 tes PHP / 999 assertions lulus**; 4 tes PWA, build Vite, Pint dan kompilasi Blade lulus.
- 16 feature tests pembayaran: nominal server, reuse attempt, timeout/404, callback lebih awal, signature/host/size, durable inbox retry, status authoritative, urutan notifikasi, mismatch, konflik manual, dua attempt dibayar, evidence/fee/hold/dua approver, refund quarantine, suspend/module, akses keluarga dan lintas tenant, privasi, QR allowlist dan rekonsiliasi.
- Satu concurrency test baru memakai sepuluh proses PHP dengan koneksi MySQL terpisah: pembuatan attempt bersamaan, callback bersamaan, release bersamaan, pembuatan attempt kedua, dan gateway melawan pelunasan manual. Hasil menjaga satu settlement dan satu received/available per pembayaran.
- Browser dengan fixture `banjarv2_testing`: halaman saldo, daftar tagihan dan detail pembayaran diperiksa pada 390 px; bukti settlement diperiksa pada mobile dan desktop. Pengajuan bukti memakai data sintetis, dilanjutkan persetujuan oleh reviewer berbeda. Tidak ada uang sungguhan diproses.
- Halaman privat `no-store`; PWA hanya memakai fallback generik. QRIS, ledger, dan halaman rekonsiliasi tidak di-cache.

Fondasi rekonsiliasi belum mengimpor laporan settlement provider/bank secara otomatis. Evidence berupa referensi teks terenkripsi yang harus dicocokkan secara operasional; upload bukti, koreksi evidence yang salah, refund, adjustment berpersetujuan, dan resolusi mismatch belum tersedia. UNKNOWN/404 yang persisten serta mismatch memerlukan investigasi; jangan mengedit saldo atau membuka attempt baru secara paksa. Fase 7 withdrawal manual belum dimulai.

Referensi integrasi yang diperiksa: [Midtrans QRIS Core API](https://docs.midtrans.com/reference/qris), [HTTP notification](https://docs.midtrans.com/docs/https-notification-webhooks), dan [Get Transaction Status](https://docs.midtrans.com/reference/get-transaction-status).
