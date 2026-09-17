# Banjar Digital V2

Super Admin produksi: https://banjar.balisantih.com. Detail layanan VPS, SSL, dan operasi tersedia di [catatan deployment](docs/DEPLOYMENT.md).

Platform multi-Banjar PT Bali Santih Digital. **Fase 1–10 selesai**: fondasi multi-tenant, autentikasi/izin, audit, PWA dasar, admin mobile, website publik, KK/krama, unit organisasi, pengajuan perubahan data, administrasi surat dan arsip PDF privat, serta kas, iuran, dedosan, pelunasan manual, QRIS Midtrans, ledger gateway, fondasi rekonsiliasi, pencairan dana manual ke rekening Banjar terverifikasi, kegiatan, presensi, calon dedosan, notulen paruman, inventaris aset (kategori/lokasi, peminjaman/pengembalian, perawatan, stock opname, dan penghapusan), serta Portal Krama (Beranda ringkasan tagihan/kegiatan/pengajuan yang merangkai fitur self-service yang sudah ada).

## Menjalankan instalasi lokal yang sudah disiapkan

Database MySQL `banjarv2` sudah dimigrasikan dan berisi dua Banjar demo. Jalankan server jika belum aktif:

```powershell
cd C:\laragon\www\banjarv2
.\scripts\serve.ps1
```

Untuk memproses PDF surat, jalankan `.\scripts\queue.ps1` pada terminal lain. Worker berhenti setelah satu jam atau ketika dihentikan; jalankan kembali jika diperlukan.

- Super Admin: http://admin.banjar.localhost:18915/login
- Website Biaung: http://biaung.banjar.localhost:18915/
- Website Tegal Gundul: http://tegalgundul.banjar.localhost:18915/
- Pengurus Biaung: http://biaung.banjar.localhost:18915/login
- Pengurus Tegal Gundul: http://tegalgundul.banjar.localhost:18915/login

Email dan kata sandi acak demo ada di `storage/app/private/demo-access.txt`. File ini privat dan tidak masuk Git. Domain `.localhost` didukung browser Chromium tanpa edit hosts. Port 18915 dipilih untuk menghindari benturan aplikasi lokal lain.

PHP default terminal komputer ini 7.4. Gunakan PHP 8.2 berikut untuk perintah Artisan:

```powershell
$phpRuntime = 'C:\laragon\bin\php\php-8.2.27-Win32-vs16-x64\php.exe'
& $phpRuntime artisan migrate:status
```

## Instalasi baru

Prasyarat: PHP 8.2+, Composer 2, MySQL 8.0.16+ dengan InnoDB/CHECK, Node 22. Extension PHP standar Laravel, pdo_mysql, dan GD untuk regenerasi icon.

1. `composer install` dan `npm ci` menggunakan runtime yang sesuai.
2. Salin `.env.example` ke `.env`, isi koneksi database sendiri dan domain platform, lalu `php artisan key:generate` **sekali pada instalasi baru**.
3. Buat database aplikasi dan database pengujian `banjarv2_testing` yang terpisah.
4. `php artisan migrate --seed` untuk schema dan katalog role. Seeder tidak membuat password default.
5. `php artisan platform:create-admin email@domain-anda.id` meminta password secara tersembunyi. Alternatif khusus database lokal kosong: `php artisan platform:demo` membuat dua tenant dan credential acak.
6. `npm run build`, lalu `scripts/serve.ps1` di Laragon atau `php artisan serve --port=18915` di environment lain.

APP_KEY dan credential tidak boleh dimasukkan ke Git. Production memakai document root `public/`, APP_DEBUG=false, HTTPS, DNS wildcard, dan akun database terbatas. PHP Laragon/Apache harus dipilih 8.2+ bila tidak menggunakan server preview.

## Pengujian

```powershell
$phpRuntime = 'C:\laragon\bin\php\php-8.2.27-Win32-vs16-x64\php.exe'
& $phpRuntime artisan test
npm test
npm run build
& $phpRuntime vendor/bin/pint --test app config database routes tests
```

PHPUnit selalu memakai MySQL `banjarv2_testing` dan dapat menghapus isi database tersebut. Jangan gunakan database yang menyimpan data penting. Koneksi pengujian dapat diatur dalam `.env.testing`; nama database tetap diperiksa sebelum migration test. Hasil Fase 10: **146 tes PHP / 1.359 assertions dan 4 tes PWA lolos**; rincian ada di laporan fase. `scripts/verify.ps1` menjalankan test, Pint, PWA test, build, lalu migration lokal jika semua lolos.

## Alur pengguna

Super Admin masuk → tambah Banjar dan admin awal → kelola modul/status → kelola akses pengurus.

Pengurus masuk pada domain Banjar → Beranda → **Konten** untuk pengumuman, berita, pengurus, layanan, galeri, dokumen, profil dan media → **Kegiatan** untuk agenda → **Website** untuk template/warna. Konten mendukung draf, jadwal terbit (WITA), dan arsip. Role dan modul nonaktif membatasi menu sekaligus akses server.

Unggah foto/PDF melalui Konten → Pustaka media, lalu pilih pada konten/profil. Media hanya tampil publik jika berstatus Publik dan terhubung ke profil atau konten terbit. Konten → QR website menyediakan PNG untuk dicetak. Data dan foto nyata Banjar perlu diisi pengurus.

**Krama** → tambah KK → tambah anggota → tetapkan kepala keluarga. **Unit & aturan** mengelola Tempekan/Regu/Maksan dan kebutuhan persetujuan Kelian. Super Admin mengatur scope Kesinoman/Kelian melalui Akses pengurus. Akun berperan Krama yang ditautkan Admin Banjar ke individu dapat melihat **Data saya** dan mengajukan koreksi untuk diperiksa. Modul KRAMA sudah aktif di dua Banjar demo lokal; data penduduk belum diisi.

**Surat → Aturan layanan** mengaktifkan pengajuan pada layanan website yang telah terbit. **Surat saya** menyediakan pengajuan mandiri dengan identitas dari master → verifikasi Penyarikan → persetujuan Kelian jika diperlukan → PDF/arsip privat. ADMINISTRATION aktif di dua Banjar demo; pengurus perlu mengatur layanan dan akun yang sesuai. Tanda tangan/stempel dilakukan pengurus pada surat hasil cetak.

**Keuangan** → Petengen menambah kas dan jenis tagihan → buat event iuran untuk semua KK aktif atau satu unit → periksa dan terbitkan → catat pelunasan manual. Dedosan memilih individu dari master, mengisi alasan, dan default memerlukan persetujuan Kelian. Buku kas menyediakan pemasukan/pengeluaran dan pembalikan dengan riwayat; Laporan menampilkan pergerakan kas dan posisi tagihan. FINANCE aktif pada dua Banjar demo. Super Admin perlu menugaskan akun Petengen melalui Akses pengurus; Admin Banjar mendapat akses monitoring dan aturan, sedangkan pencatatan transaksi memerlukan role Petengen.

**QRIS** → Krama dapat membayar tagihan keluarga yang terhubung; Petengen membuat QRIS melalui daftar tagihan. **Dana QRIS** menampilkan ledger terpisah dari kas. **Super Admin → QRIS** mencatat bukti settlement dan biaya untuk diperiksa Super Admin lain. Jalankan worker dan scheduler agar webhook/reconciliation diproses. PAYMENT tetap nonaktif pada dua demo sampai konfigurasi siap; panduan lengkap ada di `docs/PHASE_6_PAYMENT.md`.

**Pencairan** → Petengen mendaftarkan rekening Banjar → Super Admin memeriksa nomor dan memverifikasi → Petengen mengajukan nominal dari saldo tersedia (saldo langsung dicadangkan) → Kelian menyetujui jika aturan diaktifkan Admin Banjar → Super Admin **Pencairan** mengambil permintaan, transfer manual lewat bank, lalu mencatat referensi, waktu, dan bukti. Gagal atau batal mengembalikan saldo melalui jurnal. Panduan lengkap di `docs/PHASE_7_WITHDRAWAL.md`.

**Kegiatan** → buat agenda → atur jenis, basis presensi (per KK/individu) dan target (semua KK, unit, peran, krama tertentu) → buat daftar peserta → catat presensi di HP → Kelian/Penyarikan verifikasi → Petengen memutuskan calon dedosan dan membuat event dedosan untuk alur Keuangan. Krama dapat memberi tahu izin lewat **Kegiatan saya**. Modul ACTIVITY harus berstatus Aktif (bukan Dasar). Panduan di `docs/PHASE_8_ACTIVITY.md`.

**Aset** → Admin Banjar/Petengen menambah kategori dan lokasi → tambah aset individual (jumlah 1) atau jumlah (barang identik dalam satu record) → pindah lokasi, catat kondisi, jadwalkan perawatan sesuai kebutuhan. **Peminjaman** membuat catatan untuk krama terdaftar atau peminjam luar, menambah aset yang dipinjam, dan mencatat pengembalian bertahap. **Stock opname** menghitung fisik per lokasi dan menyesuaikan jumlah otomatis dari selisih hitung. **Penghapusan** (permission terpisah, default Admin Banjar saja) mengurangi jumlah dengan alasan wajib; riwayat tetap tersimpan. ASSET aktif pada dua Banjar demo. Panduan di `docs/PHASE_9_ASSET.md`.

**Portal Krama** (tab "Portal" pada navigasi, `/portal/beranda`) → merangkai fitur self-service krama yang sudah ada: sapaan, kartu Tagihan Saya (jika FINANCE+PAYMENT aktif), Kegiatan Terdekat (jika ACTIVITY Aktif), dan Pengajuan (jika ADMINISTRATION aktif) — kartu yang modulnya nonaktif otomatis disembunyikan. Layanan surat, Aktivitas, dan Profil tetap memakai halaman yang sudah ada (`/portal`, `/aktivitas`, `/admin/krama/mine`) dengan tautan "← Beranda" untuk kembali. Panduan di `docs/PHASE_10_PORTAL.md`.

## Dokumen

- `docs/BANJAR_DIGITAL_BLUEPRINT.md`: desain lintas fase.
- `docs/PHASE_1_FOUNDATION.md`: implementasi, migration, permission, bukti test dan batas Fase 1.
- `docs/PHASE_2_WEBSITE.md`: fitur website, migration, publikasi/media, panduan pengurus, dan bukti pengujian.
- `docs/PHASE_3_KRAMA.md`: KK/krama, unit/scope, pengajuan perubahan, privasi, dan bukti pengujian.
- `docs/PHASE_4_ADMINISTRATION.md`: surat, role/persetujuan, portal pengajuan, arsip/PDF, worker, dan bukti pengujian.
- `docs/PHASE_5_FINANCE.md`: kas, iuran/dedosan, pelunasan/pembalikan, izin, laporan, dan pengujian serentak.
- `docs/PHASE_6_PAYMENT.md`: QRIS, webhook, ledger, evidence/availability, konfigurasi dan rekonsiliasi.
- `docs/PHASE_7_WITHDRAWAL.md`: rekening, pencairan manual, ledger reserve/paid/release, bukti transfer, dan pengujian serentak.
- `docs/PHASE_8_ACTIVITY.md`: target & snapshot peserta, presensi, izin mandiri, verifikasi, calon dedosan, notulen, dan pengujian.
- `docs/PHASE_9_ASSET.md`: kategori/lokasi, individual vs jumlah, peminjaman/pengembalian, perawatan, stock opname, penghapusan, dan pengujian.
- `docs/PHASE_10_PORTAL.md`: Beranda krama, kartu tagihan/kegiatan/pengajuan, tautan unifikasi ke halaman self-service, dan pengujian.
- `Master Prompt Codex — Banjar Digital V2 Final.md`: aturan produk dan roadmap.

Fase 7 selesai secara lokal. QRIS belum diaktifkan: kredensial merchant, kebijakan settlement dan endpoint webhook publik perlu disiapkan sebelum pengujian sandbox nyata; pencairan baru berguna setelah ada saldo tersedia. Fase 8, 9, dan 10 selesai; Fase 11 menunggu instruksi berikutnya. Deployment produksi pertama dilakukan pada 17 September 2026; lihat [catatan deployment](docs/DEPLOYMENT.md).
