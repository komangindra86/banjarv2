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

Target utama pengurus termasuk orang tua/lanjut usia yang belum terbiasa menggunakan aplikasi. Kemudahan penggunaan wajib menjadi kriteria penerimaan setiap modul: bahasa sehari-hari, tulisan dan tombol jelas, sedikit langkah, tanpa mengingat letak menu, serta tanpa kehilangan isian ketika perlu menambah data pendukung. Terapkan aturan operasional UX pada bagian 57–58.

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

Template yang tersedia (semua bernuansa Bali; `SettingsManager::TEMPLATES`, CSS di `public.css` + `public-templates.css`):

```text
template-01  Bali Santih  editorial gading, tinta, garis emas      (aksen #b94715)
template-02  Subak        hijau sawah berundak, emas padi          (aksen #2f6b3a)
template-03  Poleng       kotak hitam-putih poleng, merah, tegas   (aksen #a3201b)
template-04  Prada        merah marun, hiasan emas prada, simetris (aksen #8c1c22)
template-05  Segara       biru laut, pasir pantai, ombak, lapang   (aksen #0f6e8c)
```

Memilih template di Pengaturan website ikut mengisi warna aksen yang serasi; warna aksen tetap boleh diganti Banjar.

Logo Banjar (Profil website, media berstatus Publik) menggantikan tanda huruf "B" di **semua** tempat: website publik, bilah atas halaman pengurus, login, portal warga (Banjar terpilih), dan ikon tab/favicon. Tanpa logo publik, dipakai logo Bali Santih (lihat di bawah).

Logo yang sama dipakai saat tautan dibagikan dan saat aplikasi dipasang: pratinjau WhatsApp/Facebook (`og:image`) memakai logo Banjar dalam JPEG 1200×630, untuk semua halaman website publik termasuk berita yang berfoto; ikon aplikasi (manifest PWA, ikon layar utama iOS, favicon) memakai PNG persegi 192/512/180 plus varian maskable. Berkas dibuat otomatis dari logo (tepi kosong dipotong, latar putih) dan disimpan per media logo; mengganti logo = berkas baru. Di portal pusat, aplikasi yang dipasang setelah warga masuk memakai nama dan logo Banjar warga tersebut; sebelum masuk, identitasnya umum (Portal Krama · Bali Santih). Logo Bali Santih (dari landing page balisantih.com: `public/images/balisantih-logo.png`, ikon bawaan `public/icons/icon-*.png`, pratinjau `public/images/balisantih-share.jpg`) dipakai di Super Admin, portal pusat sebelum Banjar terpilih, kartu Banjar di dasbor Super Admin, dan setiap Banjar yang belum mengunggah logo. Begitu Banjar mengunggah logo resminya, logo Banjar itu yang dipakai di semua tempat.

Unggah foto di semua formulir (konten website, Profil, Semua foto & berkas, lampiran notulen) memakai satu cara: tombol **Pilih foto** langsung membuka galeri HP/komputer, bisa memilih beberapa foto sekaligus, foto langsung tampil sebagai pratinjau dengan tombol **Hapus**, dan ikut tersimpan saat formulir disimpan. Tidak ada dialog unggah terpisah dan tidak ada pilihan Publik/Privat:
- Foto konten website, logo, dan sampul otomatis Publik, tetapi pengunjung tetap hanya bisa membuka foto yang dipasang pada konten terbit (atau logo/sampul). Foto Privat lama yang dipasang lewat formulir menjadi Publik.
- Lampiran notulen otomatis Privat (hanya pengurus).
- Konten (selain Dokumen) bisa berisi banyak foto (maks. 50); foto pertama = foto utama (`media_id`, dipakai kartu dan pratinjau), sisanya tampil di bawah isi halaman. Galeri memakai `gallery_items`, konten lain `content_photos`. Logo, sampul, dan berkas Dokumen tetap satu berkas.
- Foto diperkecil di browser (sisi terpanjang 1600 px) sebelum dikirim, dan di server dibatasi 2000 px lalu disimpan WebP. Satu kali simpan maks. 20 berkas (batas server 22 MB).
- Semua foto yang diunggah otomatis masuk halaman **Semua foto & berkas**.
- Bila isian lain salah (mis. judul kosong), foto yang sudah terunggah tetap tampil di formulir, tidak perlu dipilih ulang.
- Setelah menyimpan konten, pengurus kembali ke daftar konten jenis itu; setelah menyimpan Profil, kembali ke Konten website.

Profil website menyediakan tombol **Pakai contoh kalimat** untuk Kalimat pembuka, Tentang Banjar, dan Sejarah (`ContentManager::profileTemplates`); teks dalam [kurung] wajib diganti pengurus.

Lokasi Banjar cukup **satu tautan Google Maps** (`website_profiles.maps_url`; hanya https dari maps.app.goo.gl, goo.gl/maps, google.<tld>/maps, maps.google.<tld>), bukan lintang/bujur. Koordinat lama otomatis dijadikan tautan.

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
- penerimaan pembayaran tunai iuran, patus, dan dedosan (klik **Lunas** dari daftar tagihan, lihat bagian 29.3);
- rekonsiliasi pembayaran QRIS yang dibayar warga sendiri dari portal;
- pencairan dana;
- laporan.

Petengen **tidak** membuat pembayaran QRIS atas nama warga; QRIS hanya dimulai warga dari portal.

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
- perubahan data;
- penetapan iuran dan patus secara kolektif per KK;
- penetapan dedosan kepada kepala keluarga, termasuk pemilihan massal melalui checkbox.

Kesinoman adalah operator penetapan dan penerbitan tagihan iuran, patus, dan dedosan dalam wilayah aksesnya. Petengen menangani penerimaan uang, pencatatan kas, rekonsiliasi, dan laporan. Hak menetapkan tagihan tidak otomatis memberikan hak mengelola kas atau pencairan. Persetujuan Kelian tetap berlaku bila diwajibkan kebijakan Banjar.

Kesinoman tidak memegang izin `finance.view`, tetapi tetap mendapat menu **Keuangan** yang membuka halaman **Event tagihan** (siap diterbitkan, menunggu persetujuan Kelian, riwayat), daftar Tagihan tanpa tombol Lunas, serta Jenis & tagihan. Buku kas, saldo, dan laporan tetap tertutup. Beranda menampilkan jumlah event yang siap diterbitkan, dan pembuat dedosan menerima notifikasi saat Kelian menyetujui/menolak.

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

## 23.1 Kepala keluarga dan hubungan keluarga

- Kepala keluarga ditetapkan **hanya di halaman KK** (`households.head_krama_id`), bukan lewat pilihan hubungan di halaman krama. KK baru dapat dibuat sekaligus dengan kepala keluarganya dalam satu formulir.
- Anggota lain memakai hubungan: Istri/Suami, Anak, Orang Tua, Mertua, Famili Lain. Label "Kepala Keluarga" selalu diturunkan dari `head_krama_id` dan menimpa hubungan yang tersimpan.
- Kepala keluarga adalah penanggung jawab semua tagihan KK (iuran, patus, dedosan). **KK tanpa kepala keluarga dikecualikan** dari sasaran iuran/patus dan dari calon/pilihan dedosan sampai kepala keluarganya ditetapkan.
- Bila kepala keluarga dipindah ke KK lain atau statusnya menjadi tidak aktif (Nonaktif/Pindah/Meninggal), sistem **otomatis mengosongkan** kepala keluarga KK asal (tercatat di audit), lalu memberi tahu pengurus bahwa KK tersebut perlu kepala keluarga pengganti. Jangan menolak perubahan dan memaksa pengurus bolak-balik ke halaman KK.

## 23.2 Tampilan daftar

Satu Banjar bisa memiliki ±200 KK dengan 3–5 anggota. Daftar KK dan daftar krama ditampilkan sebagai **tabel ringkas** (kode KK, kepala keluarga, jumlah anggota, status / nama, KK, hubungan, status) dengan pencarian dan filter, bukan kartu besar per baris. Kartu tetap boleh untuk daftar pendek seperti anggota satu KK.

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
- penerimaan pembayaran iuran/patus yang ditetapkan Kesinoman;
- penerimaan pembayaran dedosan yang ditetapkan Kesinoman;
- pembayaran;
- saldo payment;
- request pencairan;
- laporan.

Gunakan tipe data uang yang tepat.

Jangan menggunakan floating point.

## 26.1 Acuan hasil laporan

Acuan format adalah dokumen pengguna **LAPORAN KEUANGAN BANJAR BIAUNG APRIL 2025.pdf**, dua halaman, dengan judul “LAPORAN KAS BANJAR BIAUNG” dan agenda “SANGKEP PEMACEKAN AGUNG / 28 APRIL 2025”.

Lokasi referensi pada saat revisi: `E:/Users/Indra/Downloads/LAPORAN KEUANGAN BANJAR BIAUNG APRIL 2025.pdf`. Path ini hanya rujukan pengembangan, bukan dependensi runtime atau path yang harus tersedia di server.

Gunakan dokumen sebagai contoh isi dan tata letak. Nama orang, nama rekening, kategori, tanggal, dan nominal di dalamnya bukan nilai yang boleh di-hardcode untuk semua Banjar. Jangan mengimpor transaksi contoh ke data operasional secara otomatis.

Laporan wajib berisi rincian transaksi seperti contoh, bukan hanya kartu ringkasan saldo atau daftar status tagihan. Ringkasan dashboard tetap boleh tersedia sebagai pendamping.

## 26.2 Input transaksi Petengen

Alur utama: **Keuangan → Buku kas → Catat pemasukan / pengeluaran**.

Form transaksi menyediakan:

- tanggal transaksi;
- jenis: pemasukan atau pengeluaran;
- rekening kas/bank yang menerima atau mengeluarkan uang;
- kategori laporan sesuai jenis transaksi;
- keterangan transaksi yang cukup panjang untuk menjelaskan kegiatan, pihak terkait, dan periode pembayaran;
- nominal rupiah bulat;
- referensi bukti/kuitansi dan dukungan lampiran bukti privat;
- hubungan ke tagihan atau kegiatan bila relevan, tanpa mewajibkannya untuk transaksi umum.

### Format rupiah langsung saat mengetik

Semua input nominal uang wajib menampilkan format rupiah secara otomatis saat pengguna mengetik, menempelkan nilai, atau mengubah nominal; jangan menunggu kolom kehilangan fokus atau formulir disimpan.

- Tampilkan awalan **Rp** secara tetap di dekat angka dan pemisah ribuan berupa titik: mengetik `1000` tampil **Rp 1.000**, `50000` tampil **Rp 50.000**, dan `1500000` tampil **Rp 1.500.000**.
- Pengguna cukup mengetik angka; awalan Rp tidak perlu diketik dan tidak mengganggu pengeditan. Gunakan keyboard numerik pada perangkat seluler.
- Pertahankan posisi kursor secara wajar saat menyisipkan/menghapus angka di tengah, melakukan seleksi, mengganti nominal, atau memakai Backspace/Delete di dekat pemisah ribuan.
- Dukung paste angka polos dan format rupiah Indonesia seperti `1500000`, `1.500.000`, atau `Rp 1.500.000`. Input pecahan, negatif, atau format ambigu harus ditolak dengan pesan yang jelas; jangan membuang koma/minus sehingga mengubah nilai uang tanpa diketahui pengguna.
- Kolom kosong tetap kosong, bukan otomatis nol. Nilai nol hanya diterima pada kolom yang memang mengizinkannya. Terapkan batas nominal/digit sesuai aturan backend tanpa memangkas angka diam-diam.
- Nilai awal dari database dan nilai yang muncul kembali setelah validasi gagal harus menggunakan format tampilan yang sama.
- Format tampilan terpisah dari nilai yang dikirim: **Rp 1.500.000** dikirim dan disimpan sebagai integer rupiah `1500000`, tanpa awalan Rp atau titik. Backend tetap memvalidasi nominal, batas, dan izin; jangan menggunakan floating point atau hanya mengandalkan validasi browser.
- Ganti petunjuk “tanpa titik atau koma” dengan petunjuk yang sesuai, misalnya “Ketik nominal, pemisah ribuan otomatis.”

Gunakan komponen/perilaku yang konsisten untuk nominal pemasukan, pengeluaran, nominal standar tagihan/iuran/dedosan, nominal khusus event, pencairan, biaya gateway, dan biaya aset/perawatan. Jangan menerapkan format uang pada nomor rekening, NIK, nomor KK, nomor telepon, atau jumlah barang.

Kriteria penerimaan: `1000` langsung terlihat sebagai **Rp 1.000** sebelum blur; paste **Rp 1.500.000** menghasilkan nilai kirim `1500000`; edit/hapus di tengah tetap nyaman; kolom opsional dapat dikosongkan; input tidak valid tidak berubah menjadi nominal berbeda secara diam-diam; validasi server dan pengiriman ulang setelah error tetap benar.

Kategori laporan berbeda dari rekening kas/bank, billing type, dan unit organisasi. Contoh: “Biaya Melasti” adalah kategori pengeluaran; “BPD Bali” adalah rekening dana; Tempekan/Regu adalah unit organisasi.

Pemasukan seperti sewa tanah, bunga bank, parkir, bantuan, dan dana punia harus bisa dicatat tanpa membuat tagihan KK terlebih dahulu. Pengeluaran dapat berupa biaya rutin, kegiatan, upacara, atau pura.

Pelunasan tagihan manual otomatis menghasilkan satu transaksi penerimaan pada rekening yang dipilih dengan kategori yang sesuai. Hindari meminta Petengen mencatat pemasukan yang sama lagi. Pertahankan idempotency, audit, dan koreksi melalui pembalikan.

Untuk Petengen, wilayah akses adalah Seluruh Banjar. Pada form pengurus, sembunyikan/nonaktifkan Unit tugas dan Sertakan unit turunannya ketika tidak berlaku. Pembatasan unit hanya tersedia bagi peran yang didukung; dropdown unit kosong harus menjelaskan bahwa belum ada unit aktif.

## 26.3 Kategori dan kelompok laporan

Kategori dimiliki masing-masing Banjar, memiliki jenis pemasukan/pengeluaran, nama, urutan tampil, status aktif, dan pilihan tetap ditampilkan saat nihil. Kategori yang sudah digunakan tidak boleh dihapus sehingga merusak riwayat.

Pada kolom kategori transaksi, selalu sediakan tindakan **+ Tambah kategori** bagi pengurus berizin, termasuk saat daftar kosong atau pencarian tidak menemukan hasil. Petengen harus memiliki izin menambah kategori transaksi dalam Banjarnya tanpa harus membuka menu pengaturan atau mendapat hak administrasi umum. Gunakan panel/dialog sederhana di formulir yang sama; cukup minta nama kategori, sementara jenis pemasukan/pengeluaran mengikuti transaksi. Pengaturan lanjutan memakai default yang aman dan dapat diubah melalui pengelolaan kategori.

Setelah kategori berhasil disimpan, perbarui daftar dan otomatis pilih kategori baru, lalu kembalikan fokus ke formulir transaksi. Nominal, tanggal, kas, keterangan, referensi, dan pilihan lain tetap utuh. Membatalkan atau gagal menambah kategori tidak boleh menghapus isian transaksi. Pembuatan kategori tidak otomatis menyimpan transaksi. Validasi duplikasi nama untuk jenis dan Banjar yang sama; tawarkan kategori yang sudah ada, bukan membuat duplikat atau memilihnya diam-diam. Ikuti pola umum bagian 58.

Sediakan templat kategori Banjar Biaung yang dapat disesuaikan pengurus berizin:

| Kelompok | Kategori |
|---|---|
| Pendapatan/Pemasukan | Pendapatan Rutin |
| Pendapatan/Pemasukan | Pendapatan Sewa/Kontrak |
| Pendapatan/Pemasukan | Pendapatan Lain-lain |
| Pengeluaran/Biaya | Pengeluaran Rutin Banjar |
| Pengeluaran/Biaya | Pengeluaran Lain-lain Banjar |
| Pengeluaran/Biaya | Biaya Melasti |
| Pengeluaran/Biaya | Biaya Odalan Tumpek Wariga |
| Pengeluaran/Biaya | Biaya Pangrupukan & Atensi Nyepi |
| Pengeluaran/Biaya | Pura Dalem Wirasana |
| Pengeluaran/Biaya | Pura Bangun Sakti |
| Pengeluaran/Biaya | Pura Pengiangan |
| Pengeluaran/Biaya | Pura Ratu Gede Mas Mecaling |
| Pengeluaran/Biaya | Pura Khayangan |

Setiap kategori menampilkan rincian transaksi dan subtotal otomatis. Kategori yang disetel tetap tampil harus bertuliskan **NIHIL** ketika tidak memiliki transaksi periode tersebut. Perubahan nama/urutan kategori tidak boleh mengubah laporan yang sudah difinalkan.

## 26.4 Periode sangkep dan saldo pindahan

Laporan mendukung periode tanggal bebas, nama agenda/sangkep, tanggal sangkep, dan rujukan laporan sebelumnya. Jangan membatasi laporan pada bulan kalender: contoh berjudul April 2025 memuat transaksi sejak 19 Maret 2025 sampai menjelang sangkep 28 April 2025.

Tampilkan baris **SALDO PINDAHAN SANGKEP TGL. ...** sebelum rincian periode. Saldo awal berasal dari penutupan sebelumnya atau saldo awal migrasi yang memiliki rincian dan bukti. Saldo awal tidak dihitung sebagai pendapatan periode berjalan.

Definisikan batas penutupan secara eksplisit. Jika laporan sebelumnya dan transaksi berikutnya berada pada tanggal yang sama, simpan batas transaksi/keanggotaan periode yang stabil agar tidak ada transaksi terlewat atau masuk dua laporan. Jangan menebak batas hanya dari judul bulan atau tanggal saldo pindahan.

Urutkan kategori sesuai konfigurasi; rincian di dalam kategori berurutan tanggal lalu nomor transaksi yang stabil. Transaksi bertanggal mundur setelah finalisasi memerlukan alur koreksi yang terlacak, bukan mengubah laporan final secara diam-diam.

## 26.5 Susunan cetak dan PDF

Sediakan **Pratinjau**, **Cetak**, dan **Unduh PDF** dari Keuangan → Laporan. Hasil PDF dihasilkan server dan menggunakan sumber data/perhitungan yang sama dengan pratinjau.

Susunan mengikuti contoh:

1. Judul tengah: LAPORAN KAS [NAMA BANJAR].
2. Subjudul nama sangkep/agenda dan tanggal; keterangan “dalam rupiah”.
3. Tabel berkolom **TGL. | KETERANGAN | MASUK | KELUAR | SALDO**.
4. Baris saldo pindahan, kelompok PENDAPATAN/PEMASUKAN beserta kategori/rinciannya, lalu kelompok PENGELUARAN/BIAYA beserta kategori/rinciannya.
5. Baris **TOTAL SALDO/KAS** berisi total masuk, total keluar, dan saldo akhir sesuai basis laporan yang dinyatakan.
6. Bagian **POSISI DANA** berisi rincian rekening dan piutang serta total.
7. Blok **Yang Membuat** untuk Petengen/Bendahara dan **Mengetahui** untuk Kelian Adat, disertai nama dan ruang tanda tangan.

Pada format acuan, nominal tiap transaksi berada di kolom Masuk atau Keluar; kolom Saldo memuat saldo pindahan, subtotal pada baris kategori, dan saldo akhir. Subtotal kategori bukan saldo berjalan. Bedakan label/formatnya dengan jelas; subtotal tidak boleh dijumlahkan lagi sebagai transaksi.

Gunakan tabel bergaris, judul tebal, latar lembut untuk pemisah kelompok, nominal rata kanan, dan keterangan yang dapat membungkus. Subtotal pemasukan dapat berwarna biru dan pengeluaran merah seperti contoh, tetapi cetakan hitam-putih tetap harus terbaca. Format rupiah konsisten tanpa desimal, dengan pemisah ribuan Indonesia.

Dokumen acuan berukuran kira-kira 210 × 330 mm, orientasi portrait. Jadikan F4/folio tersebut templat acuan dan sediakan A4 bila diperlukan. Jumlah halaman mengikuti data, tidak dipaksa selalu dua halaman.

Ulangi kepala tabel pada setiap halaman; jangan memotong satu baris transaksi atau memisahkan judul kategori dari rincian pertamanya. Pastikan total, posisi dana, dan blok tanda tangan terbaca tanpa tertimpa footer. Nama penandatangan berasal dari pengurus yang dipilih untuk laporan dan disimpan pada versi final; ruang tanda tangan bukan bukti persetujuan digital otomatis.

## 26.6 Posisi dana, piutang, dan rekonsiliasi

Pisahkan rekening kas/bank, piutang, dan saldo payment gateway secara eksplisit. **Piutang bukan uang kas yang tersedia untuk dibelanjakan.** Piutang Patus harus mempunyai rincian kewajiban/penanggung jawab dan saldo pada tanggal tutup laporan, bukan sekadar angka manual di footer atau seluruh tagihan semua jenis yang belum lunas saat ini.

Referensi Biaung mencantumkan:

| Komponen posisi dana | Nilai contoh |
|---|---:|
| LPD Kesiman | Rp216.648.196 |
| BPD Bali | Rp616.802.657 |
| Cash | Rp11.209.221 |
| Jumlah kas dan bank | Rp844.660.074 |
| Piutang Patus | Rp6.600.000 |
| Total posisi dana termasuk piutang | Rp851.260.074 |

Karena total dokumen acuan memasukkan piutang, tambahkan penjelasan basis di laporan: **Total posisi dana termasuk piutang; kas dan bank ditampilkan terpisah.** Jangan menyebut Rp851.260.074 sebagai kas tersedia.

Untuk buku kas, berlaku saldo kas akhir = saldo kas awal + penerimaan kas - pengeluaran kas. Untuk total posisi dana, gunakan saldo kas/bank akhir + piutang akhir dan komponen dana lain yang memang termasuk cakupan. Bila menampilkan mutasi posisi dana, jelaskan perubahan piutang/nonkas dalam rekonsiliasi agar saldo pindahan sampai saldo akhir dapat ditelusuri. Jangan membuat transaksi kas fiktif untuk memaksa keseimbangan.

Pembayaran piutang memindahkan nilai dari piutang ke kas/bank; nilainya tidak boleh diakui sebagai pertambahan total dana dua kali. Penerbitan tagihan belum lunas tidak menghasilkan pemasukan kas. Transfer antar-rekening Banjar juga tidak menghasilkan pendapatan atau biaya pada laporan gabungan.

Integrasi QRIS/withdrawal mengikuti ledger yang sudah ditetapkan: tagihan yang dibayar, dana pending/available, biaya, dan pencairan harus dapat ditelusuri tanpa dihitung sebagai pendapatan berulang. Jika saldo gateway masuk cakupan laporan, tampilkan komponennya terpisah dan pastikan tidak tumpang tindih dengan kas/bank; jika belum masuk, nyatakan cakupannya.

Semua komponen posisi dana dan piutang dihitung pada tanggal/batas penutupan yang sama dengan laporan. Posisi tagihan saat ini boleh tersedia sebagai tampilan terpisah, tetapi bukan pengganti posisi historis pada tanggal laporan.

## 26.7 Penyimpanan dan akses laporan

Petengen menyiapkan laporan; Kelian dapat memeriksa sesuai izin yang berlaku. Finalisasi menyimpan versi, periode/batas penutupan, rincian transaksi yang digunakan, subtotal, saldo, posisi dana, dan penandatangan. Unduh ulang versi final tidak boleh berubah akibat pembayaran berikutnya atau perubahan master data. Koreksi menghasilkan versi baru dengan alasan dan jejak audit, mempertahankan versi sebelumnya.

Terapkan isolasi Banjar untuk kategori, rekening, transaksi, piutang, laporan, ekspor, dan lampiran. Otorisasi wajib diperiksa di backend; file laporan/bukti tidak dibuka melalui URL publik tanpa otorisasi.

Perluas schema, service, form, dan laporan yang sudah ada melalui migration bertahap. Pertahankan data historis dan audit. Transaksi lama yang belum berkategori harus tetap masuk total dengan penanda “Belum dikategorikan” untuk dipetakan; jangan menghilangkannya dari laporan. Saldo awal historis harus direkonsiliasi tanpa dihitung ulang sebagai pemasukan baru.

## 26.8 Kriteria penerimaan laporan Biaung

Gunakan data contoh hanya sebagai fixture pengujian terisolasi, bukan seed produksi. Angka berikut telah dicocokkan dengan penjumlahan rincian dalam PDF:

- Pendapatan Rutin: Rp16.259.790.
- Pendapatan Sewa/Kontrak: Rp225.516.656.
- Pendapatan Lain-lain: Rp31.836.600.
- Total pemasukan: **Rp273.613.046**.
- Total pengeluaran: **Rp77.637.789**.
- Saldo pindahan pada contoh: **Rp655.284.817**.
- Rekonsiliasi total acuan: Rp655.284.817 + Rp273.613.046 - Rp77.637.789 = **Rp851.260.074**.
- Jumlah posisi dana: Rp216.648.196 + Rp616.802.657 + Rp11.209.221 + Rp6.600.000 = **Rp851.260.074**.

Angka acuan ini membuktikan konsistensi aritmetika dokumen; PDF tidak menyediakan rincian piutang/saldo awal per rekening yang cukup untuk menyimpulkan seluruh jurnal. Jangan mengarang rincian tersebut. Pengujian pencatatan operasional menggunakan fixture dengan saldo awal dan mutasi kas/piutang yang lengkap serta konsisten.

Verifikasi kategori nihil, periode lintas bulan, batas sangkep pada tanggal yang sama, transfer internal, pelunasan piutang, transaksi pembalik, data historis tanpa kategori, saldo pada tanggal tutup, finalisasi/koreksi, isolasi Banjar, dan pencegahan pencatatan QRIS/pelunasan ganda. Render PDF dan periksa visual semua halaman, termasuk keterangan panjang, pengulangan kepala tabel, serta posisi dana/tanda tangan pada halaman akhir.

---

# 27. RULE IURAN, PATUS, DAN DEDOSAN

Tiga kewajiban ini **berbeda** dan disimpan sebagai kategori (`kind`) terpisah agar tampil dan terlapor sendiri-sendiri:

| Kategori | Arti | Pemicu | Sasaran |
|---|---|---|---|
| **Iuran** (`IURAN`) | Kontribusi rutin/keagamaan. Kebijakan tiap Banjar berbeda: ada yang wajib bulanan, ada yang per kegiatan keagamaan, ada yang tidak memakai iuran sama sekali. | Periode atau kegiatan | Semua KK aktif / KK satu unit |
| **Patus** (`PATUS`) | Iuran yang ditetapkan nominalnya setiap ada **kematian** warga. Nominal boleh berbeda tiap kejadian. | Kematian warga | Semua KK aktif / KK satu unit |
| **Dedosan** (`DEDOSAN`) | Denda karena **tidak hadir** dalam kegiatan Banjar. | Presensi kegiatan atau penetapan manual | Kepala keluarga KK yang dikenai (bagian 29) |

Satu Banjar boleh memiliki beberapa jenis dalam satu kategori dengan nominal berbeda (mis. "Dedosan" dan "Dedosan Samua"). Jenis tagihan dibuat sekali per Banjar lalu dipakai berulang; tiap kejadian menjadi event tersendiri.

Iuran dan patus adalah kewajiban **KK**. Penetapannya dilakukan **Kesinoman secara kolektif**, bukan per orang dan bukan dengan membuka formulir satu per satu untuk setiap KK.

Contoh:

```text
Iuran Bulanan
Rp50.000
Target: Semua KK Aktif
```

Kesinoman memilih jenis tagihan, lalu formulir hanya menanyakan yang perlu: nama kejadian, **tanggal penetapan**, target kolektif (semua KK aktif dalam wilayah aksesnya atau unit yang boleh dikelolanya), dan nominal khusus bila berbeda dari nominal standar. Sistem menampilkan jumlah KK unik dan total nominal sebelum penerbitan.

Dengan satu tindakan penerbitan, sistem membuat satu tagihan untuk setiap KK yang memenuhi target **dan memiliki kepala keluarga** (bagian 23.1). Kesinoman tidak memilih warga atau KK satu-satu untuk alur iuran/patus. Satu KK yang tergabung dalam beberapa unit target tetap hanya menerima satu tagihan dalam event yang sama.

Kepala keluarga menjadi penanggung jawab tetapi tagihan tetap melekat kepada KK.

## 27.1 Tanggal penetapan, bukan jatuh tempo

Banjar tidak mengenal jatuh tempo formal. Yang penting adalah **tanggal penetapan** (`billing_events.event_date`, wajib): tanggal kejadian sebenarnya, yaitu tanggal kematian untuk patus, tanggal kegiatan untuk dedosan, dan periode berlaku untuk iuran. Tanggal penetapan ditampilkan di daftar event, rincian event, daftar tagihan, dan rincian tagihan. Jatuh tempo tetap tersedia sebagai isian opsional tersembunyi ("Gunakan jatuh tempo") untuk Banjar yang memakainya. Laporan piutang tetap memakai tanggal terbit sistem (`issued_at`).

## 27.2 Isian otomatis

Jangan meminta Kesinoman mengarang judul atau kode:

- judul terisi otomatis: iuran `[Jenis] [Bulan Tahun]`, patus `[Jenis] - [nama almarhum/ah]` atau tanggal, dan tetap dapat diubah;
- kode referensi dibuat otomatis (`[KIND]-[YYYYMMDD-HHMMSS]`) dan disembunyikan di balik "Gunakan kode referensi khusus";
- tanggal penetapan terisi hari ini, dedosan dari kegiatan memakai tanggal kegiatan.

Untuk kasus umum, Kesinoman cukup membuka formulir lalu menekan simpan.

## 27.3 Patus dari status Meninggal

Saat status krama disimpan berubah menjadi **Meninggal**, halaman krama langsung menampilkan dialog **"Buat draf tagihan patus?"** kepada pengguna berizin penetapan: jenis patus (dipilih otomatis bila hanya ada satu, dropdown bila lebih dari satu), judul atas nama almarhum/ah, dan tanggal penetapan hari ini yang dapat diubah. Satu klik "Ya" membuat draf event patus ke semua KK. Tombol yang sama tetap tersedia di halaman krama untuk kunjungan berikutnya. Bila belum ada jenis berkategori Patus, beri pintasan ke Jenis & tagihan.

Simpan snapshot daftar KK sasaran saat penetapan agar perubahan keanggotaan/status/unit berikutnya tidak mengubah tagihan terbit secara diam-diam. Jika ada perubahan sasaran sebelum terbit, tampilkan pratinjau terbaru untuk diperiksa kembali.

Kesinoman dengan akses unit tidak boleh menetapkan tagihan di luar unit yang diizinkan beserta turunannya bila hak tersebut aktif. Pilihan “Semua KK Aktif” harus jelas menyebut cakupan akses, bukan otomatis seluruh Banjar untuk setiap pengguna.

---

# 28. IURAN BERBASIS EVENT

Kesinoman membuat dan menerbitkan event iuran/patus secara kolektif. Gunakan:

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
Patus Kematian (kategori PATUS)
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

Penerbitan massal harus idempotent, termasuk saat tombol ditekan ulang, permintaan dicoba ulang, atau dua pengurus memproses event bersamaan. Simpan aktor, waktu, sasaran, nominal, dan hasil penerbitan dalam audit. Jika pemrosesan dilakukan melalui antrean, tampilkan status proses dan ringkasan hasil; jangan menyatakan semua berhasil sebelum selesai.

---

# 29. DEDOSAN

Dedosan dikenakan kepada **kepala keluarga** KK yang bersangkutan (satu orang per KK), siapa pun anggota yang tidak hadir, dan ditetapkan oleh **Kesinoman**. Tersedia dua sumber penetapan: **manual melalui daftar kepala keluarga** dan **calon dedosan dari presensi**. Penetapan manual tidak wajib mempunyai data kegiatan/presensi, tetapi tetap wajib berada dalam satu event dedosan yang jelas.

Rule:

> Dalam satu event dedosan, satu KK maksimal satu orang terkena dedosan, dan orang itu harus kepala keluarga aktif KK tersebut.

Contoh:

```text
Gotong Royong 20 September

KK-001 (kepala keluarga: Made)
Anggota yang absen: Ketut → dedosan dikenakan ke Made, Rp100.000
```

Anggota lain KK-001 tidak boleh mendapat dedosan kedua untuk event tersebut. Backend menolak subjek dedosan yang bukan `households.head_krama_id`, baik dari input manual, pilihan massal, maupun keputusan calon presensi.

Tetapi event berbeda boleh.

Enforce backend dan constraint database; aturan ini berlaku sama untuk input manual, pilihan massal, dan calon dari presensi.

## 29.1 Penetapan dedosan manual secara massal

Alur:

```text
Kesinoman → Pilih jenis dedosan → Simpan draf (judul, kode, tanggal penetapan terisi otomatis)
→ Tampilkan daftar KEPALA KELUARGA aktif dalam wilayah akses
→ Centang beberapa kepala keluarga melalui checkbox
→ Tinjau jumlah orang, KK, dan total nominal, isi alasan
→ Persetujuan Kelian jika diwajibkan
→ Kesinoman menerbitkan dari Keuangan → Event tagihan → "Siap diterbitkan"
```

Daftar hanya berisi kepala keluarga (satu baris per KK); KK tanpa kepala keluarga tidak muncul. Sediakan pencarian nama, filter unit, identitas KK, status sudah ditetapkan dalam event, checkbox per baris dalam satu daftar rapi (checkbox memakai gaya `.check-option`), dan pilihan semua yang jelas cakupannya. Jangan mewajibkan membuka halaman setiap warga untuk menetapkan dedosan. Pencarian "Tambah satu KK" di halaman event juga hanya menampilkan kepala keluarga.

Pilihan lintas halaman harus dipertahankan dan jumlah pilihan selalu terlihat. Bedakan **Pilih semua di halaman ini** dari **Pilih semua hasil filter**; perubahan filter tidak boleh diam-diam menambah penerima. Sediakan hapus pilihan serta tinjauan daftar terpilih sebelum terbit.

Karena hanya kepala keluarga yang dapat dipilih, konflik dua orang dari KK yang sama tidak terjadi lagi di UI; backend tetap menolak batch yang berisi bukan-kepala-keluarga atau lebih dari satu orang per KK, dengan rincian masalah. KK yang sudah dikenai dedosan pada event tersebut harus ditandai dan tidak dapat ditambahkan lagi.

Validasi semua sasaran di backend, termasuk identitas warga dan KK, keanggotaan Banjar, cakupan unit, duplikasi dalam event, nominal, serta kewenangan penerbit. Jangan percaya daftar ID, filter, atau jumlah pilihan dari frontend. Tolak batch yang tidak valid dengan rincian masalah sebelum menerbitkan tagihan; penerbitan harus atomik dan aman terhadap pengulangan/konkurensi.

Pilihan “semua hasil filter” harus mengacu pada snapshot sasaran yang ditinjau, bukan menjalankan ulang filter yang dapat berubah ketika job diproses. Perubahan sasaran atau nominal setelah persetujuan membatalkan persetujuan lama dan memerlukan peninjauan kembali jika approval diwajibkan.

## 29.2 Hak akses penetapan

Pisahkan izin melihat daftar/tagihan yang diperlukan Kesinoman, membuat event, memilih sasaran, dan menerbitkan iuran/dedosan dari izin `cash.manage`, rekonsiliasi, dan pencairan milik Petengen. Sesuaikan role seed, menu, route, policy/service, dan query scope agar alur benar-benar dapat dijalankan Kesinoman, termasuk Kesinoman dengan cakupan unit.

Hak penetapan iuran/patus dan dedosan tidak diberikan kepada Petengen hanya karena memiliki peran Bendahara. Akun dengan beberapa peran mengikuti gabungan izin yang secara eksplisit ditetapkan. Akses Kesinoman ke tagihan dibatasi pada wilayah tugas; jangan membuka seluruh laporan kas/rekening Banjar untuk mendukung penetapan.

## 29.3 Pembayaran iuran/patus dan dedosan

Setiap tagihan iuran/patus maupun dedosan dapat dibayar melalui:

1. **Tunai/cash:** warga datang membayar tunai ke Petengen. Petengen membuka **Keuangan → Tagihan** (tabel yang secara bawaan hanya menampilkan tagihan belum lunas, dapat dicari dengan nama kepala keluarga/kode KK dan disaring per jenis), menekan **Lunas** pada baris warga tersebut, lalu mengonfirmasi, dan selesai. Tanggal penerimaan = hari ini dan nomor bukti dibuat server (`TUNAI-[KK]-[id]`); kas penerima dipilih sekali di atas daftar bila ada lebih dari satu kas. Sistem melunasi tagihan dan membuat satu transaksi pemasukan secara atomik; `submission_key` per baris membuat klik ganda tidak tercatat dua kali. Formulir pelunasan lengkap di halaman rincian tagihan tetap tersedia untuk kasus khusus (kategori, tanggal lain, nomor kuitansi).
2. **QRIS melalui portal warga saja:** warga melihat tagihan yang berhak diaksesnya, memilih Bayar QRIS, dan membayar melalui Midtrans. Pengurus (termasuk Petengen) tidak membuat QRIS atas nama warga dan tidak melihat tombol QRIS di halaman tagihan pengurus; izin `payment.create` tidak diberikan ke peran pengurus. Backend memvalidasi webhook/status provider, melunasi tagihan, dan mencatat ledger sesuai aturan payment. Jangan meminta Petengen mencatat pemasukan manual kedua untuk pembayaran yang sama.

Penetapan tagihan belum berarti penerimaan uang. Pisahkan status tagihan, metode pembayaran, bukti penerimaan, dan status dana tersedia. Kedua metode harus menampilkan status pelunasan yang konsisten kepada Kesinoman, Petengen, dan warga sesuai hak akses masing-masing.

Jika tagihan sudah lunas secara tunai, jangan buat QRIS baru. Jika ada QRIS warga yang masih diproses ketika Petengen akan menerima tunai, baris tagihan ditandai **"QRIS diproses"** dan konfirmasi Lunas menyebutkannya; ini peringatan, bukan blokir, agar Petengen tidak terkunci saat warga memang membayar tunai. Kunci tagihan dan gunakan idempotency untuk mencegah pelunasan ganda.

Jika dana QRIS ternyata diterima setelah pelunasan tunai karena race atau notifikasi terlambat, catat penerimaan provider sebagai kelebihan pembayaran yang perlu direkonsiliasi; jangan menghilangkan uang yang benar-benar diterima, melunasi tagihan dua kali, atau menghitungnya sebagai pendapatan tagihan kedua. Tampilkan kebutuhan tindak lanjut kepada petugas berizin dengan jejak audit.

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

Krama dapat membayar tagihan iuran, patus, dan dedosan melalui QRIS di portal warga. QRIS hanya dimulai oleh warga sendiri (`payment.self`); pengurus tidak membuat QRIS atas nama warga. Metode tunai tetap tersedia sesuai bagian 29.3; QRIS bukan satu-satunya cara pelunasan.

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
Patus Kematian
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

Kesinoman meninjau calon dari presensi dengan aturan bagian 29. Alur ini melengkapi penetapan manual, bukan menjadi satu-satunya sumber dedosan.

- Saat presensi diverifikasi, satu calon dibuat per KK yang ada anggotanya tidak hadir, dengan penanggung jawab yang disarankan = **kepala keluarga** (basis presensi KK maupun perorangan). Nama anggota yang benar-benar absen tetap ditampilkan sebagai informasi.
- KK tanpa kepala keluarga **tidak** dibuatkan calon.
- Pilihan penanggung jawab hanya berisi kepala keluarga; tombol **"Pilih semua yang disarankan (N KK)"** menandai semua calon yang belum diputuskan dalam satu klik, lalu Kesinoman meninjau dan mengirim sekali.
- Event dedosan yang dibuat memakai **tanggal kegiatan** sebagai tanggal penetapan; formulir tidak meminta jatuh tempo.

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
Kesinoman Memilih Penanggung Jawab melalui Checkbox Massal
↓
Validasi 1 KK / 1 event
↓
Approval jika diperlukan
↓
Kesinoman Menerbitkan Dedosan secara Massal
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

Portal krama memakai **satu alamat pusat untuk semua Banjar**:

```text
portal.balisantih.com   (config platform.portal_host / PORTAL_HOST)
```

Aturan:

- Banjar **tidak** ditentukan dari domain, melainkan dari akun warga yang login. Banjar aktif disimpan di sesi (`portal_banjar_id`) dan diperiksa ulang setiap request.
- Warga boleh masuk ke suatu Banjar bila: keanggotaan & akun aktif, Banjar aktif, memiliki peran dengan `krama.self` (lingkup BANJAR), serta modul **KRAMA** dan **PORTAL** Banjar itu aktif.
- Satu Banjar → langsung ke `/portal/beranda`. Lebih dari satu → `/pilih-banjar` (bisa berpindah lewat menu "Ganti banjar"). Tidak ada → login ditolak.
- Modul PORTAL dimatikan di tengah sesi → warga dikembalikan ke `/pilih-banjar`.
- Host portal hanya melayani halaman warga: `/portal/*`, `/payments` (QRIS warga), `/aktivitas`, `/notifications`, `/admin/krama/mine` & pengajuan perubahan data diri. Halaman pengurus (`/admin/*` lainnya, `/platform`) dan website publik = 404.
- Menu portal hanya berisi menu warga walau akunnya juga pengurus.
- Sesi tetap host-only: login di portal tidak berlaku di domain Banjar, dan sebaliknya.
- Subdomain Banjar tidak boleh sama dengan host portal maupun host platform.
- Halaman warga di domain Banjar (`<banjar>.balisantih.com/portal`) tetap berfungsi.

Informasi yang dapat dilihat warga (peran Krama / `krama.self`):

- **Laporan keuangan** (`/portal/laporan`, modul FINANCE): laporan kas per periode sangkep yang sudah **FINALIZED**, sama dengan yang dibacakan saat sangkep (rincian masuk/keluar, posisi dana, piutang total, tanda tangan) beserta PDF. Draf tidak tampil. Saat koreksi masih draf, versi final terakhir tetap tampil; versi lama disembunyikan setelah koreksinya final.
- **Jadwal & kegiatan** (`/aktivitas`): jadwal kegiatan Banjar yang sudah terbit (status PUBLISHED dan `published_at` sudah lewat) mulai hari ini. Kegiatan PUBLIC tampil untuk semua warga; kegiatan INTERNAL (mis. paruman tertutup) hanya bagi warga/KK yang menjadi pesertanya. Ada penanda bila ada presensi untuk warga/KK-nya, dan catatan kehadiran + pemberitahuan izin.
- **Riwayat pembayaran** (`/portal/riwayat-pembayaran`, modul FINANCE): tagihan KK warga yang sudah lunas — tanggal bayar, tagihan, cara bayar (**Tunai** dicatat Petengen / **QRIS**), jumlah. Pelunasan yang dibatalkan tidak tampil.
- Beranda portal merangkum: tagihan belum lunas, kegiatan terdekat (presensi KK-nya atau kegiatan Banjar terdekat), pembayaran terakhir, laporan sangkep terakhir, pengajuan surat.

Akun portal warga (aktivasi oleh Admin Banjar, izin `krama.configure`):

- Admin Banjar cukup klik **Aktifkan akun warga** di halaman krama atau kolom "Akun portal" pada Daftar krama. Syarat: krama berstatus Aktif dan NIK sudah diisi.
- Nama pengguna = **NIK** (disimpan sebagai HMAC `users.nik_hash`; NIK asli tetap hanya terenkripsi di tabel krama). Kata sandi awal = **`balisantih`**. Email tidak wajib.
- Kata sandi awal **wajib diganti** saat pertama masuk (`users.must_change_password`); semua halaman tenant dialihkan ke `/akun/kata-sandi` sampai diganti. Kata sandi baru minimal 8 karakter berisi huruf dan angka, tidak boleh `balisantih`.
- NIK yang sama di Banjar lain = orang yang sama: akun yang ada dipakai ulang (kata sandi tidak berubah) dan warga memilih Banjar di portal.
- Warga lupa kata sandi → Admin Banjar menekan **Reset kata sandi ke awal** (kembali ke `balisantih`, wajib diganti lagi). Reset hanya untuk akun warga murni (dibuat lewat NIK, tanpa peran pengurus/platform di Banjar mana pun).
- NIK krama diubah → nama pengguna akunnya ikut berubah.
- Login dengan NIK berlaku di portal pusat dan domain Banjar; Super Admin tetap login dengan email.
- Semua pengguna tenant dapat mengganti kata sandi sendiri lewat tautan "Kata sandi" di bilah atas.

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


**Keamanan akun lintas Banjar.** Satu akun warga boleh terdaftar di beberapa Banjar (NIK sama), tetapi Admin satu Banjar tidak boleh mereset kata sandi atau mengganti NIK login akun yang juga terdaftar di Banjar lain. Mengetahui NIK seseorang tidak boleh cukup untuk mengambil alih akunnya. Warga mengganti kata sandinya sendiri, atau meminta bantuan Bali Santih.

**Akun demo reviewer.** Akun demo bersama (`users.password_locked`) memakai kata sandi yang diumumkan dan tidak dapat diganti maupun direset, supaya semua reviewer tetap bisa masuk. Akun demo hanya terdaftar di Banjar demo. Banjar demo (`DEMO_BANJAR_ID`) dikembalikan setiap hari pukul 03.00 WITA ke snapshot (`demo:snapshot` menyimpan kondisi awal, `demo:reset` memulihkan): semua data Banjar demo, berkas (foto, PDF), keanggotaan, dan akun yang dibuat reviewer dihapus. Akun yang juga terdaftar di Banjar lain hanya dilepas dari demo. Perintah ini menolak berjalan untuk Banjar selain `DEMO_BANJAR_ID`.
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

Kesinoman menetapkan iuran/patus secara kolektif. Jangan pilih orang atau KK satu-satu.

Gunakan:

```text
Semua KK Aktif dalam Cakupan Akses / Unit yang Diizinkan
```

### Surat

Ambil identitas dari master.

### Dedosan

Kesinoman dapat mengambil kandidat dari presensi atau memilih manual dari daftar kepala keluarga melalui checkbox massal. Gunakan data identitas dan KK yang sudah tersedia; jangan meminta input identitas ulang.

### Patus

Ambil nama almarhum/ah dan tanggal dari perubahan status krama menjadi Meninggal (bagian 27.3); jangan meminta Kesinoman mengetik ulang nama atau membuat jenis tagihan baru untuk setiap kematian.

### Pelunasan tunai

Ambil nominal, KK, dan tanggal dari tagihan; Petengen cukup menekan Lunas (bagian 29.3).

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
Patus
Dedosan
Lainnya
```

Jika user memilih Iuran, baru tampilkan konfigurasi Iuran. Judul halaman mengikuti kategori ("Tetapkan iuran", "Tetapkan patus", "Tetapkan dedosan").

Jika memilih Dedosan, baru tampilkan konfigurasi Dedosan.

## 58.1 Mudah digunakan pengurus lanjut usia

- Gunakan istilah yang dikenal pengurus, misalnya “Catat pemasukan”, “Catat pengeluaran”, “Tetapkan iuran/patus”, dan “Tetapkan dedosan”. Istilah teknis seperti ledger, scope, entity, atau idempotency tidak muncul sebagai petunjuk kerja pengguna.
- Ukuran teks isi dan input minimal 16 px; tombol/area sentuh utama minimal 44 × 44 px, berjarak cukup, dan memiliki label teks. Jangan hanya memakai ikon, warna, hover, atau tooltip untuk menjelaskan tindakan penting.
- Tampilkan satu tindakan utama yang jelas untuk setiap tahap, label kolom yang selalu terlihat, tanda wajib/opsional, serta contoh singkat pada kolom yang membutuhkannya. Hindari formulir panjang dengan pilihan yang belum relevan.
- Jangan meminta pengurus mengisi hal teknis yang bisa ditentukan sistem. Contoh: alamat halaman konten website (slug) tidak punya kolom isian; dibuat otomatis dari judul saat konten dibuat (judul kembar diberi nomor, misalnya `sangkep-2`; judul tanpa huruf Latin memakai nama jenis konten) dan tidak berubah saat judul diedit agar tautan yang sudah dibagikan tetap berfungsi.
- Sediakan nilai awal yang masuk akal tanpa membuat transaksi otomatis. Format nominal rupiah langsung mengikuti bagian 26.2. Tampilkan ringkasan penerima dan total sebelum menerbitkan tagihan massal.
- Pesan kesalahan harus berada dekat kolom terkait, menggunakan bahasa sederhana, dan menjelaskan cara memperbaikinya. Pertahankan semua isian yang masih valid. Setelah berhasil, tampilkan tindakan yang berhasil dan langkah berikutnya dengan jelas.
- Pastikan dapat digunakan dengan keyboard, pembesaran teks/zoom, dan layar HP; fokus terlihat, label terhubung ke input, serta panel/dialog mengelola dan mengembalikan fokus dengan benar. Jangan menutup tombol simpan dengan keyboard virtual atau elemen tetap.

## 58.2 Tambah data pendukung tanpa meninggalkan pekerjaan

Pengguna tidak boleh harus mencari menu lain hanya karena pilihan yang dibutuhkan belum tersedia. Setiap pemilih data yang dapat ditambah harus menyediakan tindakan tambah yang terlihat di dekat kolom sesuai izin pengguna, bukan hanya pada halaman pengaturan.

Utamakan formulir singkat di panel/dialog pada halaman yang sama. Contoh: **+ Tambah kategori** pada transaksi, **+ Tambah kas** pada pilihan rekening kas manual, serta **+ Tambah jenis iuran/patus** pada penetapan tagihan bila pengguna berizin. Pengaturan lanjutan tetap tersedia melalui pintasan yang jelas. Jangan menumpuk beberapa dialog.

Alur wajib untuk kategori yang belum ada:

```text
Petengen mengisi transaksi
→ Kategori belum tersedia
→ Klik + Tambah kategori di dekat kolom
→ Isi nama → Simpan kategori
→ Kategori baru otomatis terpilih
→ Lanjutkan transaksi dengan semua isian sebelumnya tetap utuh
```

Pola ini berlaku lintas modul untuk data pendukung yang relevan. Data sensitif seperti rekening tujuan pencairan tetap mengikuti proses verifikasi yang diwajibkan; pintasan tidak boleh melewati persetujuan, validasi, atau hak akses backend. Penambahan rekening kas pencatatan berbeda dari pendaftaran rekening bank tujuan pencairan.

Jika pengguna tidak berizin menambah pilihan, jelaskan siapa yang dapat membantu atau sediakan alur permintaan yang memang tersedia. Jangan menampilkan tombol yang pasti gagal, membiarkan dropdown kosong tanpa penjelasan, atau memberikan hak kelola yang tidak diperlukan.

## 58.3 Pintasan dan kembali ke formulir semula

Jika penambahan/pengaturan memang harus dilakukan pada halaman terpisah, **wajib sediakan pintasan langsung dari formulir asal** dengan label tujuan yang jelas. Jangan hanya memberi instruksi “buka Pengaturan” tanpa tautan/tombol.

Sebelum berpindah, simpan draf isian secara aman dalam cakupan pengguna dan Banjar. Setelah selesai, sediakan **Simpan dan kembali ke [nama formulir asal]** atau tombol kembali yang setara; pulihkan draf, perbarui pilihan, dan pilih data yang baru dibuat bila relevan. Saat batal, kembali ke draf yang sama. Jelaskan kepada pengguna bahwa isian sudah disimpan sementara; draf bukan transaksi/tagihan yang telah diterbitkan.

Nominal, tanggal, keterangan, sasaran/checkbox lintas halaman, dan pilihan lain tidak boleh hilang. Jika formulir memiliki lampiran, pertahankan melalui unggahan sementara yang aman dan terotorisasi; jangan mengandalkan pemulihan otomatis file input setelah navigasi. Draf/unggahan sementara harus berumur terbatas, dihapus setelah selesai atau dibatalkan, dan tidak bocor antar-akun/Banjar.

Tujuan kembali harus internal dan divalidasi. Saat pengguna kembali setelah sesi berakhir atau hak akses berubah, validasi ulang izin dan data sebelum penyimpanan. Draf tidak boleh menerbitkan transaksi tanpa tindakan simpan/terbit yang eksplisit.

## 58.4 Kriteria penerimaan UX

Setiap modul harus ditinjau menggunakan skenario pekerjaan pengurus, bukan hanya pemeriksaan bahwa tombol berfungsi. Untuk transaksi: isi nominal/tanggal/keterangan, tambah kategori yang belum tersedia, dan simpan transaksi tanpa membuka menu lain atau mengetik ulang isian. Uji pula batal, nama kategori duplikat, validasi gagal, dan gangguan jaringan saat menambah kategori; transaksi asal harus tetap utuh dan tidak terkirim dua kali.

Untuk alur yang memerlukan halaman lain, uji pintasan, kembali setelah simpan/batal, pemulihan draf termasuk sasaran massal/lampiran bila ada, dan pergantian sesi/akun. Uji pada layar HP, keyboard, dan pembesaran teks. Fitur belum dianggap selesai jika pengguna harus menelusuri menu secara manual untuk menambah pilihan atau kehilangan pekerjaan saat kembali.

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

## Kemudahan penggunaan pengurus

Verifikasi skenario bagian 58.1–58.4: tambah kategori dari formulir transaksi, kategori otomatis terpilih, isian tetap utuh saat simpan/batal/error, serta pintasan dan pemulihan draf jika perlu berpindah halaman. Periksa ukuran teks/tombol, pesan kesalahan yang mudah dipahami, keyboard/zoom, dan tampilan HP. Pengujian wajib mencakup pengguna berizin maupun tanpa izin menambah data pendukung.

## Multi Tenant

Banjar A tidak bisa mengakses data Banjar B.

## Domain

Subdomain hanya resolve ke tenant yang sesuai.

## Feature Gate

Module inactive tidak dapat diakses.

## Iuran

Event yang sama tidak membuat tagihan ganda pada KK.

Dua event patus (dua kematian) berbeda boleh membuat dua tagihan.

Kesinoman dapat menetapkan iuran/patus untuk semua KK aktif dalam cakupan akses dalam satu proses. KK dalam beberapa unit target tidak mendapat tagihan ganda. KK tanpa kepala keluarga tidak ikut tertagih. Kesinoman dengan akses unit tidak dapat memperluas sasaran melalui manipulasi request. Petengen tanpa peran/izin penetapan tidak dapat menerbitkan tagihan. Tanggal penetapan wajib dan tersimpan pada event.

## KK

Kepala keluarga yang pindah KK atau menjadi tidak aktif/meninggal otomatis dilepas dari KK asal tanpa menolak perubahan.

## Dedosan

Satu event tidak boleh mengenai dua anggota KK yang sama, dan subjek selain kepala keluarga ditolak (manual, massal, dan keputusan calon presensi).

Penetapan manual dapat dilakukan tanpa presensi melalui checkbox beberapa kepala keluarga sekaligus. Uji pilihan lintas halaman/filter, sasaran bukan kepala keluarga, sasaran di luar akses, perubahan sasaran setelah approval, pengulangan submit, dan dua penerbitan bersamaan. Kesalahan satu sasaran tidak boleh menghasilkan batch terbit sebagian tanpa diketahui pengguna. Kesinoman tanpa `finance.view` dapat menemukan event yang sudah disetujui dan menerbitkannya.

## Payment

Duplicate Midtrans webhook tidak menghasilkan double payment.

Iuran, patus, dan dedosan sama-sama dapat dilunasi tunai atau QRIS melalui portal warga. Uji akses tagihan portal, pelunasan tunai satu klik dari daftar (idempotent terhadap klik ganda, ditolak untuk pengguna tanpa `cash.manage`), transaksi kas atomik, penolakan pembuatan QRIS oleh pengurus, konflik QRIS aktif dengan pembayaran tunai, webhook terlambat setelah lunas, serta rekonsiliasi kelebihan pembayaran tanpa menggandakan pendapatan/tagihan.

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

Laporan pada fase ini wajib memenuhi bagian **26.1–26.8**, termasuk kategori transaksi, periode sangkep, rincian masuk/keluar, saldo pindahan, subtotal/NIHIL, rekening kas/bank, piutang Patus, rekonsiliasi posisi dana, penandatangan, cetak/PDF, serta versi final dan koreksi. Ringkasan kartu kas/tagihan saja belum memenuhi hasil yang diminta.

Penetapan tagihan mengikuti bagian **27–29**: Kesinoman menetapkan iuran/patus kolektif per KK dan dedosan manual melalui checkbox massal; Petengen mencatat penerimaan tunai. Fase 6 menambahkan pembayaran kedua jenis tagihan melalui QRIS portal warga beserta pencegahan pelunasan ganda antarmetode.

Testing business rule dan pemeriksaan visual PDF wajib. Integrasi ledger QRIS/withdrawal dilengkapi pada fase 6–7 sesuai aturan rekonsiliasi bagian 26.6.

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
Iuran, patus, dan dedosan adalah tiga kategori berbeda. Iuran dan patus merupakan kewajiban KK dan ditetapkan Kesinoman secara kolektif, bukan per orang; patus dipicu kematian warga.

## RULE 07
Satu billing event hanya boleh membuat satu tagihan per KK.

## RULE 08
Dua event berbeda boleh menagih KK yang sama.

## RULE 09
Dedosan dikenakan kepada kepala keluarga KK yang bersangkutan dan ditetapkan Kesinoman, termasuk manual melalui checkbox massal pada daftar kepala keluarga.

## RULE 10
Satu event dedosan maksimal satu orang per KK, yaitu kepala keluarganya. KK tanpa kepala keluarga tidak dikenai iuran, patus, maupun dedosan sampai kepala keluarganya ditetapkan.

## RULE 11
Ketidakhadiran tidak langsung menjadi dedosan.

## RULE 12
Midtrans digunakan untuk QRIS V1 yang hanya dimulai warga dari portal. Iuran, patus, dan dedosan juga dapat dibayar tunai; Petengen cukup menekan Lunas dari daftar tagihan.

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
