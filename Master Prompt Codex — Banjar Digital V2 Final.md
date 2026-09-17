# MASTER PROMPT CODEX — BANJAR DIGITAL V2
## Website-First Multi-Tenant Platform
### PT Bali Santih Digital

Anda bertindak sebagai:

- Senior Software Architect
- Senior Laravel Developer
- Database Architect
- Security Engineer
- UI/UX Engineer
- Multi-Tenant SaaS Architect

Tugas Anda adalah membangun **Banjar Digital V2**, platform digital milik PT Bali Santih Digital untuk membantu Banjar Adat di Bali.

Sistem harus dibangun sebagai **satu platform multi-banjar**, bukan aplikasi terpisah untuk setiap Banjar.

Model pertumbuhan produk:

```text
Website Banjar
      ↓
Data Krama / KK
      ↓
Administrasi
      ↓
Keuangan
      ↓
QRIS
      ↓
Kegiatan & Presensi
      ↓
Aset
      ↓
Portal Krama
      ↓
Banjar Digital Lengkap
```

Website publik merupakan pintu masuk awal.

Contoh tenant:

```text
banjarbiaung.balisantih.com
banjartegalgundul.balisantih.com
banjarkesiman.balisantih.com
```

Semua menggunakan:

- satu codebase;
- satu deployment;
- satu platform Bali Santih;
- satu shared database;
- tenant isolation menggunakan `banjar_id`.

JANGAN membuat project Laravel baru untuk setiap Banjar.

---

# 1. VISI PRODUK

Banjar Digital bukan sekadar website dan bukan sekadar aplikasi administrasi.

Tujuan akhir sistem adalah menjadi sistem operasional sederhana untuk membantu:

- komunikasi Banjar;
- data krama;
- administrasi;
- keuangan;
- kegiatan;
- pembayaran;
- aset;
- pelayanan kepada krama.

Namun Banjar tidak harus menggunakan seluruh fitur sejak awal.

Strategi implementasi adalah:

> Website dulu, kemudian fitur Banjar Digital diaktifkan sedikit demi sedikit.

Banjar tidak boleh merasa harus belajar aplikasi besar sejak hari pertama.

---

# 2. PRINSIP UTAMA

Gunakan prinsip:

> FITUR KUAT DI BELAKANG, SEDERHANA DI DEPAN.

Prioritas:

1. mobile-first;
2. sedikit klik;
3. sedikit input;
4. tidak input data berulang;
5. otomatisasi;
6. bahasa sederhana;
7. cepat;
8. aman;
9. mudah dirawat;
10. scalable untuk banyak Banjar.

Jangan mengejar jumlah fitur.

Jangan membuat UI seperti ERP desktop.

---

# 3. TECHNOLOGY STACK

Gunakan:

- Laravel 12
- PHP 8.2+
- MySQL
- Laravel Query Builder sebagai pendekatan utama database
- Blade
- Tailwind CSS
- Alpine.js bila diperlukan
- PWA
- Service Worker
- Web Push bila modul notification aktif
- Queue/Scheduler bila diperlukan

Gunakan **modular monolith**.

Jangan menggunakan:

- microservices;
- Kubernetes;
- SPA kompleks;
- React/Vue kecuali nanti diperintahkan;
- arsitektur berlebihan.

---

# 4. MOBILE-FIRST

Semua UI harus dirancang mulai dari smartphone.

Berlaku untuk:

- Super Admin Bali Santih;
- Admin Banjar;
- Kelian;
- Petengen;
- Penyarikan;
- Kesinoman;
- pengurus operasional;
- Portal Krama;
- presensi;
- aset;
- website publik.

Gunakan:

- touch target besar;
- card;
- search;
- filter sederhana;
- CTA jelas;
- responsive layout;
- bottom navigation bila cocok.

Hindari tabel lebar pada smartphone.

---

# 5. MULTI-TENANT

Multi-tenancy adalah fondasi.

Gunakan entitas:

```text
banjars
```

Setiap data tenant wajib mempunyai hubungan ke Banjar.

Contoh:

```text
banjar_id
```

pada tabel yang relevan.

Tenant isolation WAJIB dilakukan server-side.

User Banjar A tidak boleh dapat membaca atau memodifikasi Banjar B melalui:

- URL manipulation;
- request manipulation;
- IDOR;
- API;
- form;
- query string;
- direct route access.

---

# 6. DOMAIN TENANT

Gunakan tabel:

```text
tenant_domains
```

Contoh:

```text
banjar_id
domain
is_primary
verification_status
verified_at
```

Request:

```text
banjarbiaung.balisantih.com
```

harus menghasilkan:

```text
CurrentBanjar = Banjar Biaung
```

Flow:

```text
HTTP Request
    ↓
Host
    ↓
tenant_domains
    ↓
Banjar
    ↓
Current Tenant Context
    ↓
Tenant Scoped Application
```

Jangan menggunakan nama subdomain sebagai primary tenant identity.

Primary identity tetap:

```text
banjar.id
```

---

# 7. CUSTOM DOMAIN READY

Sistem harus memungkinkan satu Banjar mempunyai lebih dari satu domain.

Contoh:

```text
banjarbiaung.balisantih.com
banjarbiaung.or.id
```

keduanya menunjuk tenant yang sama.

Custom domain tidak perlu diaktifkan pada MVP, tetapi database dan tenant resolver harus siap.

---

# 8. WILDCARD SUBDOMAIN

Deployment harus dirancang kompatibel dengan:

```text
*.balisantih.com
```

Banjar baru tidak membutuhkan deployment baru.

Contoh:

```text
Create Banjar
↓
subdomain = banjarbaru
↓
banjarbaru.balisantih.com
```

Tidak boleh membutuhkan perubahan source code.

---

# 9. SUPER ADMIN BALI SANTIH

Harus ada Super Admin khusus PT Bali Santih Digital.

Contoh:

```text
admin.balisantih.com
```

Super Admin bukan admin Banjar.

Fungsi:

- membuat Banjar;
- mengelola tenant;
- menentukan subdomain;
- memilih template;
- mengaktifkan/nonaktifkan modul;
- membuat akun awal pengurus;
- mengelola status tenant;
- melihat status integrasi pembayaran;
- melihat request pencairan dana;
- memproses pencairan manual;
- melihat audit sistem.

Super Admin tidak boleh bebas mengubah data finansial dengan mengedit angka saldo.

---

# 10. MODULE ACTIVATION

Setiap Banjar dapat memiliki fitur berbeda.

Gunakan:

```text
banjar_modules
```

Minimal module code:

```text
WEBSITE
KRAMA
ADMINISTRATION
FINANCE
PAYMENT
ACTIVITY
ASSET
PORTAL
NOTIFICATION
```

Contoh Banjar baru:

```text
WEBSITE          ACTIVE
KRAMA            INACTIVE
ADMINISTRATION   INACTIVE
FINANCE          INACTIVE
PAYMENT          INACTIVE
ACTIVITY         BASIC
ASSET            INACTIVE
PORTAL           INACTIVE
```

Ketika modul diaktifkan, menu muncul.

Jangan membuat source code atau database baru per Banjar.

---

# 11. FEATURE GATING

Jika module tidak aktif:

- menu tidak muncul;
- route tidak dapat diakses;
- endpoint tidak dapat digunakan;
- action backend ditolak.

Jangan hanya menyembunyikan menu frontend.

---

# 12. WEBSITE BANJAR SEBAGAI PRODUK PERTAMA

Website awal harus memberikan value walaupun modul aplikasi Banjar lainnya belum digunakan.

Website publik minimal mendukung:

- Beranda
- Profil Banjar
- Struktur Pengurus
- Pengumuman
- Agenda/Kegiatan
- Berita & Dokumentasi
- Informasi Layanan
- Dokumen Publik
- Galeri
- Kontak & Lokasi

Navigation harus tetap sederhana.

Jangan tampilkan terlalu banyak menu sekaligus.

---

# 13. HOMEPAGE

Homepage fokus pada informasi yang dibutuhkan masyarakat.

Contoh:

```text
BANJAR TEGAL GUNDUL

[ Hero ]

PENGUMUMAN PENTING

Paruman Krama
20 September • 19:00

AGENDA TERDEKAT

22 Sep - Ngayah
25 Sep - Piodalan

LAYANAN BANJAR

Informasi Surat
Administrasi Krama
Peminjaman Fasilitas

BERITA TERBARU

KONTAK & LOKASI
```

Website jangan hanya menjadi company profile statis.

---

# 14. PENGUMUMAN

Admin Banjar dapat membuat pengumuman menggunakan form sederhana.

Minimal:

```text
judul
isi
foto_optional
published_at
expires_at_optional
priority
related_activity_id_optional
status
```

Priority:

```text
NORMAL
IMPORTANT
```

Pengumuman yang melewati `expires_at` tidak lagi ditampilkan sebagai aktif tetapi histori tetap tersimpan.

---

# 15. SHARE KE WHATSAPP

Pengumuman, berita dan kegiatan harus memiliki permalink.

Contoh:

```text
banjarbiaung.balisantih.com/pengumuman/paruman-september
```

Sediakan:

```text
[ Bagikan ke WhatsApp ]
```

WhatsApp tetap menjadi kanal komunikasi masyarakat.

Website adalah sumber informasi resmi.

Jangan mencoba menggantikan WhatsApp.

---

# 16. QR WEBSITE

Setiap Banjar dapat menghasilkan QR menuju website utama.

QR dapat digunakan pada:

- Bale Banjar;
- surat;
- papan pengumuman;
- banner;
- kegiatan.

QR harus tetap relevan ketika Portal Krama aktif kemudian.

---

# 17. INFORMASI LAYANAN

Website awal menyediakan informasi layanan.

Contoh:

```text
Surat Keterangan

Syarat
- ...

Prosedur
1. ...
2. ...

Kontak
Penyarikan
```

Ketika module ADMINISTRATION aktif, halaman yang sama dapat memiliki:

```text
[ Ajukan Sekarang ]
```

Jangan membuat fitur baru jika halaman lama dapat berevolusi.

---

# 18. WEBSITE TEMPLATE

Jangan membuat page builder.

Gunakan curated template Bali Santih.

Contoh:

```text
Template 01
Template 02
Template 03
```

Tenant dapat mengganti:

- logo;
- cover;
- warna aksen;
- informasi;
- foto;
- template.

Jangan memberikan custom CSS bebas kepada Banjar.

---

# 19. ADMIN WEBSITE

Admin harus mudah digunakan melalui HP.

Dashboard cukup:

```text
Halo, [Nama]

[ + Buat Pengumuman ]

Agenda Mendatang
3

Pengumuman Aktif
4

Berita
2
```

Navigation maksimal sederhana:

```text
Beranda
Konten
Kegiatan
Website
```

Jangan membuat WordPress clone.

---

# 20. STRUKTUR INTI BANJAR

Setiap Banjar mempunyai empat fungsi inti.

## Kelian Adat

Fokus:

- pengesahan;
- keputusan;
- monitoring;
- approval.

Kelian bukan operator utama.

## Petengen / Bendahara

Fokus:

- kas;
- pemasukan;
- pengeluaran;
- iuran;
- dedosan;
- pembayaran;
- pencairan dana;
- laporan.

## Penyarikan / Sekretaris

Fokus:

- administrasi;
- surat;
- pengumuman;
- arsip;
- agenda;
- notulen.

## Kesinoman

Fokus:

- KK;
- krama;
- keluarga;
- status krama;
- perubahan data.

Keempat fungsi inti ini harus tersedia sebagai role standard.

---

# 21. STRUKTUR OPERASIONAL

Setiap Banjar dapat mempunyai struktur berbeda.

Contoh:

- Tempekan;
- Regu;
- Maksan;
- struktur lokal lainnya.

Gunakan struktur generik:

```text
organizational_units

id
banjar_id
parent_id
type
name
is_active
```

Contoh:

```text
Banjar
└── Tempekan Kangin
    ├── Regu 1
    └── Regu 2
```

atau:

```text
Banjar
└── Maksan Pura Dalem
```

Jangan membuat tabel terpisah:

```text
tempekan
regu
maksan
```

jika satu model generik dapat digunakan.

---

# 22. FUNGSI STRUKTUR OPERASIONAL

Tempekan:

- koordinasi kelompok lebih besar;
- grouping krama;
- target kegiatan;
- scope pengurus.

Regu:

- koordinasi kegiatan;
- kelompok relatif lebih kecil;
- presensi;
- operasional.

Maksan:

- dapat mengelola kegiatan terkait pura;
- odalan;
- ngayah;
- kegiatan khusus Maksan.

Jangan membuat banyak menu khusus.

Gunakan organizational unit sebagai konteks dan scope.

---

# 23. KK DAN KRAMA

KK adalah unit keluarga.

Minimal:

```text
KK
↓
Kepala Keluarga
↓
Anggota
```

Krama merupakan individu.

Data minimal disesuaikan kebutuhan nyata.

Hindari form panjang.

Gunakan grouping/progressive form.

---

# 24. PERUBAHAN DATA KRAMA

Krama dapat mengajukan perubahan.

Tetapi tidak langsung mengubah master data.

Flow:

```text
Krama
↓
Ajukan Perubahan
↓
Kesinoman Verifikasi
↓
Kelian Approval jika diperlukan
↓
Master Data Diperbarui
```

Simpan:

- nilai sebelumnya;
- nilai baru;
- pengaju;
- verifier;
- approver;
- timestamp.

---

# 25. ADMINISTRASI / SURAT

Flow:

```text
Krama
↓
Pilih Surat
↓
Data master otomatis
↓
Lengkapi data tambahan
↓
Submit
↓
Penyarikan Verifikasi
↓
Kelian Approval bila diperlukan
↓
Generate PDF
↓
Selesai
```

Jangan meminta user mengetik ulang:

- nama;
- NIK;
- alamat;
- nomor KK;

jika sudah tersedia.

---

# 26. KEUANGAN

Petengen mengelola:

- kas;
- pemasukan;
- pengeluaran;
- iuran;
- dedosan;
- pembayaran;
- saldo payment;
- request pencairan;
- laporan.

Gunakan tipe data uang yang tepat.

Jangan menggunakan floating point.

---

# 27. RULE IURAN

Iuran adalah kewajiban **KK**.

Contoh:

```text
Iuran Bulanan
Rp50.000
Target: Semua KK Aktif
```

Sistem otomatis membuat tagihan ke seluruh KK aktif.

Petengen tidak memilih satu-satu.

Kepala keluarga menjadi penanggung jawab utama tetapi tagihan tetap melekat kepada KK.

---

# 28. IURAN BERBASIS EVENT

Gunakan:

```text
Billing Type
↓
Billing Event
↓
Bill
```

Contoh:

```text
Billing Type:
Iuran Kematian
```

Dalam September:

```text
Event 001
Kematian A
Rp25.000 / KK

Event 002
Kematian B
Rp25.000 / KK
```

Satu KK boleh menerima:

```text
Event 001   Rp25.000
Event 002   Rp25.000
```

RULE:

```text
Satu billing_event + satu KK = maksimal satu tagihan.
```

Dua event berbeda boleh menagih KK yang sama.

Enforce pada backend dan database.

---

# 29. DEDOSAN

Dedosan dikenakan kepada individu.

Rule:

> Dalam satu event dedosan, satu KK maksimal satu individu terkena dedosan.

Contoh:

```text
Gotong Royong 20 September

KK-001
Made → Rp100.000
```

Anggota lain KK-001 tidak boleh mendapat dedosan kedua untuk event tersebut.

Tetapi event berbeda boleh.

Enforce backend + database bila memungkinkan.

---

# 30. MIDTRANS SEBAGAI PAYMENT GATEWAY

Untuk V1 gunakan **Midtrans** sebagai payment gateway.

Gunakan abstraction:

```text
PaymentGatewayInterface
        ↓
MidtransPaymentGateway
```

Jangan mengimplementasikan provider lain sekarang.

Namun core business logic jangan tightly coupled dengan Midtrans.

Credential harus melalui environment configuration.

Jangan hardcode credential.

---

# 31. QRIS PAYMENT FLOW

Krama dapat membayar tagihan menggunakan QRIS.

Flow:

```text
Tagihan
↓
Bayar
↓
Banjar Digital membuat transaksi
↓
Midtrans QRIS
↓
Krama membayar
↓
Midtrans Notification/Webhook
↓
Backend Verifikasi
↓
Payment Success
↓
Tagihan LUNAS
↓
Ledger Banjar dikreditkan
```

Frontend tidak boleh menentukan transaksi berhasil.

Status pembayaran ditentukan backend berdasarkan data Midtrans yang tervalidasi.

---

# 32. PAYMENT DATA

Minimal simpan:

```text
payment_id
banjar_id
bill_id
order_id
midtrans_transaction_id
gross_amount
gateway_fee
platform_fee
net_amount
payment_method
status
paid_at
settlement_at
raw_reference_optional
created_at
updated_at
```

`order_id` harus unique.

Webhook harus idempotent.

Satu transaksi tidak boleh menghasilkan credit ledger dua kali.

---

# 33. SALDO BANJAR

Setiap Banjar mempunyai saldo internal di Bali Santih.

Jangan menentukan saldo dengan sekadar:

```text
SUM(payments)
```

Gunakan **immutable financial ledger**.

Minimal konsep balance:

```text
PENDING
AVAILABLE
RESERVED
PAID_OUT
```

Makna:

### PENDING

Pembayaran berhasil tetapi dana belum memenuhi syarat pencairan.

### AVAILABLE

Dana sudah dapat digunakan untuk permintaan pencairan berdasarkan reconciliation/settlement rule Bali Santih dan Midtrans.

### RESERVED

Dana sedang berada dalam withdrawal request.

### PAID_OUT

Dana telah diteruskan ke rekening Banjar.

---

# 34. FINANCIAL LEDGER

Gunakan tabel ledger.

Contoh:

```text
financial_ledger_entries

id
banjar_id
type
direction
amount
reference_type
reference_id
status
description
created_at
```

Setiap movement harus traceable.

Contoh credit:

```text
+ Rp50.000
Payment QRIS
Iuran Kematian
Payment #123
```

Contoh debit:

```text
- Rp2.000.000
Withdrawal #456
```

Jangan mengubah angka saldo secara manual.

Balance merupakan hasil ledger.

---

# 35. SETTLEMENT DAN AVAILABILITY

Pisahkan konsep:

```text
payment success
settlement
withdrawal availability
```

Simpan:

```text
paid_at
settlement_at
available_at
```

Jika Bali Santih menerapkan aturan dana baru dapat diajukan setelah 3 hari settlement/availability, rule tersebut harus dikonfigurasi.

Contoh:

```text
settlement_hold_days = 3
```

Jangan menaruh angka 3 tersebar di source code.

Buat sebagai configuration/policy.

---

# 36. FLOW PENCAIRAN V1

Untuk V1, pencairan dari Bali Santih ke rekening Banjar dilakukan **MANUAL oleh Super Admin Bali Santih**.

Yang manual hanya transaksi bank keluar.

Permintaan dan pencatatannya tetap melalui sistem.

Flow:

```text
Krama
↓
Bayar QRIS
↓
Midtrans
↓
Settlement
↓
Saldo Banjar AVAILABLE
↓
Petengen Request Withdrawal
↓
Saldo RESERVED
↓
Super Admin Bali Santih
↓
Transfer Manual melalui Bank
↓
Input Reference / Upload Bukti
↓
Withdrawal PAID
↓
Ledger PAID_OUT
```

JANGAN membuat payout API otomatis pada V1.

JANGAN mengarang endpoint disbursement Midtrans.

---

# 37. WITHDRAWAL REQUEST

Petengen dapat melihat:

```text
Saldo Tersedia
Rp3.500.000

[ Ajukan Pencairan ]
```

Petengen hanya dapat request maksimal sebesar AVAILABLE balance.

Saat request dibuat:

- validasi available balance;
- lock/reserve saldo secara atomic;
- gunakan database transaction;
- cegah double withdrawal;
- generate withdrawal reference.

Status:

```text
REQUESTED
APPROVAL_PENDING
PROCESSING
PAID
FAILED
CANCELLED
```

Gunakan hanya status yang diperlukan.

---

# 38. REKENING BANJAR

Pencairan hanya menuju rekening yang sudah didaftarkan.

Gunakan:

```text
banjar_bank_accounts
```

Minimal:

```text
banjar_id
bank_code
bank_name
account_number_encrypted
account_number_masked
account_holder_name
verification_status
is_primary
```

Pada withdrawal jangan mengizinkan Petengen mengetik rekening tujuan baru.

Gunakan rekening terverifikasi.

---

# 39. PERUBAHAN REKENING

Perubahan rekening harus melalui flow terpisah.

Contoh:

```text
Ajukan Rekening Baru
↓
Verifikasi
↓
Aktifkan
```

Audit wajib.

Perubahan rekening tidak boleh dilakukan diam-diam pada halaman withdrawal.

---

# 40. APPROVAL PENCAIRAN

Secara default:

```text
Petengen
↓
Request Withdrawal
↓
Super Admin Bali Santih
```

Sistem boleh menyediakan setting tenant:

```text
payout_requires_kelian_approval
```

Jika false:

```text
Petengen
↓
Bali Santih
```

Jika true:

```text
Petengen
↓
Kelian Adat
↓
Bali Santih
```

Jangan memaksa approval Kelian pada semua Banjar.

---

# 41. PROSES SUPER ADMIN BALI SANTIH

Super Admin melihat queue:

```text
PERMINTAAN PENCAIRAN

Banjar Biaung
Rp2.500.000

BPD Bali
**** 8219
a.n. Banjar XXXXX

Status:
REQUESTED

[ Proses ]
```

Setelah transfer manual:

```text
Nomor Referensi
Tanggal Transfer
Bukti Transfer

[ Tandai Sudah Dibayar ]
```

Ketika ditandai PAID:

- buat ledger debit;
- release reserved balance;
- ubah withdrawal ke PAID;
- simpan processed_by;
- simpan processed_at;
- simpan bank reference;
- simpan bukti.

---

# 42. FAILED / CANCELLED WITHDRAWAL

Jika pencairan gagal atau dibatalkan:

```text
RESERVED
↓
AVAILABLE
```

menggunakan ledger/reversal yang benar.

Jangan hanya mengedit angka saldo.

Semua reversal harus traceable.

---

# 43. PLATFORM FEE

Arsitektur harus siap jika suatu saat Bali Santih mengambil fee.

Simpan:

```text
gross_amount
gateway_fee
platform_fee
net_amount
```

Untuk sekarang:

```text
platform_fee = 0
```

kecuali Product Owner mengubah kebijakan.

Jangan hardcode model bisnis berbayar.

---

# 44. RECONCILIATION

Sediakan konsep reconciliation antara:

```text
Tagihan
↔
Payment Banjar Digital
↔
Midtrans
↔
Ledger
↔
Withdrawal
↔
Transfer Bank
```

Mismatch tidak boleh diperbaiki dengan edit saldo.

Gunakan adjustment/reversal entry yang mempunyai:

- alasan;
- user;
- timestamp;
- audit;
- approval jika diperlukan.

---

# 45. KEGIATAN

Satu entity kegiatan digunakan untuk:

- kegiatan umum;
- paruman;
- ngayah;
- gotong royong;
- upacara;
- rapat.

Jangan membuat event engine kedua nanti.

Website awal menggunakan entity kegiatan yang sama dalam mode sederhana.

Saat ACTIVITY module aktif penuh, tambahkan fitur operasional.

---

# 46. TARGET KEGIATAN

Target kegiatan dapat berupa:

```text
ALL_HOUSEHOLDS
ALL_KRAMA
ORGANIZATIONAL_UNIT
ROLE
SELECTED_KRAMA
```

Dukung kombinasi bila diperlukan.

Jika individu masuk dari dua target berbeda, hitung satu kali.

---

# 47. BASIS PRESENSI

Kegiatan dapat menggunakan:

```text
HOUSEHOLD
PERSON
```

HOUSEHOLD:

Satu anggota KK dapat mewakili KK jika kegiatan mengizinkan.

PERSON:

Setiap individu wajib hadir sendiri.

Status minimal:

```text
HADIR
IZIN
SAKIT
TIDAK_HADIR
BELUM_DIVERIFIKASI
```

---

# 48. DEDOSAN DARI PRESENSI

Tidak hadir tidak langsung membuat dedosan.

Flow:

```text
Kegiatan
↓
Presensi
↓
Verifikasi
↓
Calon Dedosan
↓
Pilih Individu Penanggung Jawab
↓
Validasi 1 KK / 1 event
↓
Approval jika diperlukan
↓
Terbitkan Dedosan
```

---

# 49. PARUMAN

Paruman menggunakan kegiatan dengan data tambahan:

- agenda;
- peserta;
- presensi;
- notulen;
- keputusan;
- lampiran.

Jangan membuat meeting management kompleks.

---

# 50. ASET BANJAR

Dukung dua jenis:

### Individual Asset

Contoh:

- kendaraan;
- laptop;
- sound system;
- mesin.

### Quantity Asset

Contoh:

- kursi;
- meja;
- tikar;
- piring.

Jangan membuat 300 record individual jika ada 300 kursi identik.

---

# 51. SIKLUS ASET

Dukung:

```text
Perolehan
↓
Inventaris
↓
Lokasi
↓
Kondisi
↓
Peminjaman
↓
Perawatan
↓
Stock Opname
↓
Penghapusan/Penjualan
```

Aset resmi jangan dihapus secara destructively.

Gunakan status/histori.

---

# 52. KONDISI ASET

Minimal:

```text
BAIK
PERLU_PERAWATAN
RUSAK_RINGAN
RUSAK_BERAT
HILANG
TIDAK_DIGUNAKAN
```

Simpan histori pemeriksaan.

---

# 53. PORTAL KRAMA

Portal menggunakan domain Banjar yang sama:

```text
banjarbiaung.balisantih.com/portal
```

Jangan menggunakan domain aplikasi terpisah.

Navigation sederhana:

```text
Beranda
Layanan
Aktivitas
Profil
```

Dashboard:

```text
Rahajeng, Komang

Tagihan Saya
Rp75.000
[ Bayar QRIS ]

Kegiatan Terdekat
Ngayah • Minggu 07.00

Pengajuan
1 sedang diproses
```

---

# 54. PWA

Website/Admin/Portal harus disiapkan sebagai PWA.

Implementasikan:

- manifest;
- service worker;
- installability;
- icons;
- offline fallback aman;
- cache strategy.

Jangan cache data sensitif secara sembarangan.

---

# 55. AUDIT TRAIL

Audit wajib pada:

- tenant;
- role/permission;
- data krama;
- approval;
- keuangan;
- billing;
- dedosan;
- payment;
- ledger;
- withdrawal;
- rekening Banjar;
- aset.

Minimal simpan:

```text
banjar_id
user_id
action
entity_type
entity_id
old_values
new_values
timestamp
```

---

# 56. SECURITY

Implementasikan:

- authentication;
- authorization;
- tenant isolation;
- IDOR protection;
- CSRF;
- validation;
- secure session;
- rate limiting endpoint sensitif;
- secure upload;
- MIME validation;
- encrypted sensitive fields;
- Midtrans webhook verification;
- financial race condition prevention.

Semua keputusan finansial kritis dilakukan server-side.

---

# 57. UX — JANGAN INPUT ULANG

Rule penting:

Jika data sudah dimiliki sistem, jangan minta input lagi.

Contoh:

### Iuran

Jangan pilih KK satu-satu.

Gunakan:

```text
Semua KK Aktif
```

### Surat

Ambil identitas dari master.

### Dedosan

Ambil kandidat dari presensi.

### Aset

Jika berasal dari transaksi pengeluaran, ambil nominal dan tanggal dari transaksi.

### Withdrawal

Ambil rekening dari rekening Banjar terverifikasi.

---

# 58. UX — PROGRESSIVE DISCLOSURE

Jangan tampilkan seluruh pilihan sekaligus.

Contoh:

```text
Buat Tagihan

Iuran
Dedosan
Lainnya
```

Jika user memilih Iuran, baru tampilkan konfigurasi Iuran.

Jika memilih Dedosan, baru tampilkan konfigurasi Dedosan.

---

# 59. DATABASE PRINCIPLES

Gunakan:

- foreign key;
- indexes;
- unique constraint;
- DB transactions;
- status/history;
- timestamps;
- tenant scoping.

Jangan gunakan JSON untuk relasi inti jika relational model lebih tepat.

Index penting:

```text
banjar_id
kk_id
krama_id
billing_event_id
status
created_at
payment_reference
withdrawal_reference
```

---

# 60. CONSTRAINT KRITIS

Iuran:

```text
UNIQUE(
    banjar_id,
    billing_event_id,
    kk_id
)
```

Dedosan:

pastikan satu event + satu KK hanya menghasilkan satu subject dedosan.

Tenant domain harus unique.

Midtrans order ID harus unique.

Ledger reference/idempotency reference harus unique sesuai jenis transaksi.

Withdrawal reference harus unique.

---

# 61. TEST WAJIB

## Multi Tenant

Banjar A tidak bisa mengakses data Banjar B.

## Domain

Subdomain hanya resolve ke tenant yang sesuai.

## Feature Gate

Module inactive tidak dapat diakses.

## Iuran

Event yang sama tidak membuat tagihan ganda pada KK.

Dua event kematian berbeda boleh membuat dua tagihan.

## Dedosan

Satu event tidak boleh mengenai dua anggota KK yang sama.

## Payment

Duplicate Midtrans webhook tidak menghasilkan double payment.

## Ledger

Satu payment hanya menghasilkan satu ledger credit.

## Withdrawal

Tidak boleh menarik melebihi available balance.

Dua request paralel tidak boleh membuat saldo negatif.

## Failed Withdrawal

Saldo reserved harus kembali dengan benar.

## Authorization

User tanpa permission tidak dapat melakukan approval/action finansial.

---

# 62. DEVELOPMENT PHASES

JANGAN membuat semua fitur sekaligus.

---

## FASE 0 — ARCHITECTURE & BLUEPRINT

Belum implementasi fitur besar.

Audit repository.

Jika kosong, perlakukan sebagai greenfield.

Buat:

```text
docs/BANJAR_DIGITAL_BLUEPRINT.md
```

Minimal menjelaskan:

- overall architecture;
- multi-tenancy;
- tenant resolution;
- wildcard domain;
- Super Admin;
- module activation;
- database plan;
- website architecture;
- roles;
- organizational units;
- billing;
- Midtrans;
- ledger;
- withdrawal manual;
- activity;
- assets;
- PWA;
- security;
- testing;
- deployment assumptions.

STOP setelah fase ini.

---

## FASE 1 — PLATFORM FOUNDATION

Bangun:

- Laravel foundation;
- banjars;
- tenant_domains;
- tenant resolver;
- Super Admin;
- authentication;
- role/permission;
- banjar_modules;
- audit foundation;
- base PWA;
- mobile-first admin shell.

---

## FASE 2 — WEBSITE BANJAR

Bangun:

- public website;
- template;
- profil;
- pengurus;
- pengumuman;
- kegiatan basic;
- berita;
- galeri;
- layanan;
- public documents;
- kontak;
- maps;
- WhatsApp sharing;
- QR website;
- SEO;
- Open Graph.

Ini harus sudah dapat digunakan sebagai produk nyata.

---

## FASE 3 — KRAMA & KK

Bangun:

- KK;
- kepala keluarga;
- anggota;
- krama;
- organizational units;
- Tempekan/Regu/Maksan;
- search;
- scoped access;
- perubahan data.

---

## FASE 4 — ADMINISTRASI

Bangun:

- pengajuan surat;
- Penyarikan workflow;
- Kelian approval;
- PDF;
- arsip;
- integrasi portal.

---

## FASE 5 — KEUANGAN

Bangun:

- kas;
- pemasukan;
- pengeluaran;
- billing types;
- billing events;
- bills;
- iuran;
- dedosan;
- laporan.

Testing business rule wajib.

---

## FASE 6 — MIDTRANS QRIS + LEDGER

Bangun:

- PaymentGatewayInterface;
- MidtransPaymentGateway;
- QRIS payment;
- webhook;
- idempotency;
- payment record;
- ledger;
- pending/available balance;
- reconciliation foundation.

Jangan membangun payout API.

---

## FASE 7 — WITHDRAWAL MANUAL

Bangun:

- banjar bank account;
- bank account verification status;
- available balance;
- withdrawal request;
- reserved balance;
- optional Kelian approval;
- Super Admin withdrawal queue;
- manual transfer recording;
- upload proof;
- paid/reversal ledger;
- withdrawal history.

Transfer bank tetap manual.

Sistem pencatatan harus otomatis dan auditable.

---

## FASE 8 — KEGIATAN & PRESENSI

Upgrade kegiatan existing.

Tambahkan:

- target peserta;
- organizational target;
- HOUSEHOLD/PERSON;
- izin;
- presensi;
- kandidat dedosan;
- paruman;
- notulen.

---

## FASE 9 — ASET

Bangun:

- categories;
- locations;
- individual assets;
- quantity assets;
- QR;
- movement;
- loan;
- return;
- inspection;
- maintenance;
- stock opname;
- disposal.

---

## FASE 10 — PORTAL KRAMA

Bangun/rapikan:

- login;
- dashboard;
- data keluarga;
- surat;
- kegiatan;
- tagihan;
- QRIS;
- payment history;
- aktivitas;
- profil;
- PWA.

---

## FASE 11 — DASHBOARD & NOTIFICATION

Bangun:

- Kelian dashboard;
- Petengen dashboard;
- Penyarikan dashboard;
- Kesinoman dashboard;
- operational scoped dashboard;
- notifications;
- optional web push.

Dashboard harus berorientasi tindakan.

---

## FASE 12 — PRODUCTION HARDENING

Lakukan:

- security review;
- tenant isolation;
- permission audit;
- payment review;
- ledger reconciliation test;
- withdrawal concurrency test;
- responsive/mobile test;
- PWA test;
- wildcard subdomain deployment;
- SSL;
- backups;
- logs;
- monitoring;
- production documentation.

---

# 63. CARA BEKERJA SETIAP FASE

Untuk setiap fase:

1. baca Master Blueprint;
2. audit implementasi yang ada;
3. jangan mengulang fitur yang sudah ada;
4. jelaskan rencana singkat;
5. identifikasi migration;
6. implementasikan;
7. buat/update test;
8. jalankan test;
9. perbaiki error;
10. cek mobile UI;
11. update documentation;
12. laporkan hasil.

Jangan lanjut fase berikutnya tanpa instruksi.

---

# 64. NON-NEGOTIABLE BUSINESS RULES

## RULE 01
Sistem multi-banjar sejak awal.

## RULE 02
Website Banjar merupakan entry product.

## RULE 03
Satu Banjar = satu tenant walaupun memiliki beberapa domain.

## RULE 04
Kelian Adat, Petengen, Penyarikan dan Kesinoman adalah fungsi inti.

## RULE 05
Tempekan, Regu, Maksan dan struktur lokal bersifat fleksibel.

## RULE 06
Iuran merupakan kewajiban KK.

## RULE 07
Satu billing event hanya boleh membuat satu tagihan per KK.

## RULE 08
Dua event berbeda boleh menagih KK yang sama.

## RULE 09
Dedosan dikenakan kepada individu.

## RULE 10
Satu event dedosan maksimal satu individu per KK.

## RULE 11
Ketidakhadiran tidak langsung menjadi dedosan.

## RULE 12
Midtrans digunakan untuk QRIS V1.

## RULE 13
Status pembayaran berhasil hanya berdasarkan validasi backend.

## RULE 14
Duplicate webhook tidak boleh menghasilkan double payment.

## RULE 15
Saldo Banjar menggunakan ledger, bukan angka yang dapat diedit.

## RULE 16
Payment success tidak otomatis berarti dana bisa dicairkan.

## RULE 17
Withdrawal hanya menggunakan AVAILABLE balance.

## RULE 18
Saat withdrawal dibuat, dana menjadi RESERVED secara atomic.

## RULE 19
Pencairan V1 dilakukan manual oleh Bali Santih.

## RULE 20
Super Admin mencatat bukti/reference transfer manual sebelum withdrawal dinyatakan PAID.

## RULE 21
Failed/cancelled withdrawal harus mengembalikan reserved balance melalui ledger.

## RULE 22
Rekening pencairan tidak boleh diganti pada saat withdrawal.

## RULE 23
Aset resmi tidak dihapus destructively.

## RULE 24
Authorization penting selalu server-side.

## RULE 25
Jangan meminta input ulang data yang sudah diketahui sistem.

---

# 65. JANGAN OVERBUILD

Jangan membuat fitur yang belum diperlukan seperti:

- marketplace;
- social network;
- chat;
- polling;
- forum;
- mobile native app;
- advanced analytics;
- AI;
- complex workflow builder;
- accounting ERP;
- automated payout API.

Bangun sesuai scope.

---

# 66. DEFINITION OF DONE

Sebuah fitur dianggap selesai jika:

- business rule benar;
- tenant isolation benar;
- permission benar;
- mobile UX baik;
- validasi lengkap;
- audit tersedia bila diperlukan;
- test kritis lolos;
- tidak ada duplicate input;
- tidak merusak fitur sebelumnya;
- dokumentasi diperbarui.

---

# 67. INSTRUKSI PERTAMA UNTUK CODEX

Mulai hanya dari:

# FASE 0 — ARCHITECTURE & BLUEPRINT

JANGAN langsung coding seluruh aplikasi.

Buat:

```text
docs/BANJAR_DIGITAL_BLUEPRINT.md
```

Blueprint harus minimal menjawab:

### Platform

- bagaimana modular monolith disusun;
- module boundaries;
- tenant lifecycle.

### Multi Tenant

- tenant resolver;
- tenant context;
- domain/subdomain;
- custom domain future support;
- tenant isolation.

### Bali Santih Admin

- onboarding Banjar;
- module activation;
- withdrawal management.

### Website

- content entities;
- activity reuse;
- website template;
- public/private separation.

### Role & Permission

- Kelian;
- Petengen;
- Penyarikan;
- Kesinoman;
- organizational scopes.

### Data Model

- Banjar;
- KK;
- Krama;
- organizational unit;
- billing;
- payment;
- ledger;
- withdrawal;
- activity;
- assets.

### Midtrans

- payment creation;
- order ID strategy;
- webhook;
- idempotency;
- reconciliation.

### Ledger

Jelaskan secara detail:

```text
Payment
↓
Pending
↓
Available
↓
Reserved
↓
Paid Out
```

### Withdrawal

Jelaskan V1:

```text
Petengen
↓
Withdrawal Request
↓
Optional Kelian Approval
↓
Bali Santih Super Admin
↓
Manual Bank Transfer
↓
Upload Proof / Reference
↓
PAID
```

JANGAN merancang automatic payout sebagai requirement V1.

### Security

- tenant isolation;
- IDOR;
- finance authorization;
- webhook;
- race condition;
- double withdrawal;
- bank data security.

### Testing

Buat mandatory test plan.

### Roadmap

Buat implementasi FASE 1–12 sesuai Master Prompt.

---

# 68. OUTPUT FASE 0

Setelah blueprint selesai, tampilkan:

1. ringkasan architecture;
2. diagram tenant flow;
3. module boundaries;
4. daftar tabel;
5. relationship penting;
6. billing design;
7. Midtrans payment design;
8. ledger design;
9. withdrawal design;
10. security risks;
11. deployment assumptions;
12. hal yang masih membutuhkan keputusan Product Owner.

Kemudian:

# STOP.

JANGAN mengimplementasikan FASE 1 sampai Product Owner memberikan instruksi.

---

# 69. TARGET AKHIR PRODUK

Banjar baru harus dapat melalui flow:

```text
Bali Santih
↓
Create Banjar
↓
Assign Subdomain
↓
Pilih Template
↓
Isi Profil
↓
Website Aktif
```

Kemudian berkembang tanpa pindah aplikasi:

```text
Website
↓
Krama
↓
Administrasi
↓
Keuangan
↓
QRIS
↓
Kegiatan
↓
Aset
↓
Portal
```

Payment flow akhirnya:

```text
Krama
↓
Bayar QRIS
↓
Midtrans
↓
Webhook
↓
Tagihan Lunas
↓
Ledger Banjar
↓
Pending
↓
Available
↓
Petengen Request Withdrawal
↓
Reserved
↓
Bali Santih Transfer Manual
↓
Bukti Transfer
↓
Paid Out
```

Target pengalaman Banjar:

> Pengurus membuka HP, melihat apa yang perlu dikerjakan, menyelesaikannya dalam beberapa tap, kemudian selesai.

Target pengalaman krama:

> Krama dapat mengetahui informasi Banjar, kegiatan, kewajiban, pembayaran, layanan dan aktivitasnya dari satu tempat.

Target Bali Santih:

> Satu platform dan satu codebase dapat melayani banyak Banjar dengan subdomain dan identitas masing-masing, dimulai dari website sederhana dan berkembang menjadi Banjar Digital lengkap tanpa membangun ulang aplikasi.