# Fase 3 — KK & Krama

Status: selesai, 16 September 2026. Implementasi lokal di atas Fase 1–2. Fase 4 kemudian diselesaikan; lihat [laporan administrasi](PHASE_4_ADMINISTRATION.md).

## Fitur

- Menu **Krama** pada dashboard ketika modul KRAMA aktif dan pengguna berizin.
- KK dengan kode internal unik per Banjar, alamat, status, nomor KK opsional, kepala keluarga, dan beberapa keanggotaan unit.
- Anggota krama dengan nama, status aktif/nonaktif/pindah/meninggal, tanggal lahir dan telepon opsional, NIK opsional, dan keanggotaan unit individu.
- Kepala keluarga harus anggota aktif dari KK yang sama. Kepala keluarga tidak dapat dipindahkan atau dinonaktifkan sebelum penunjuk kepala keluarga diganti/dikosongkan.
- Pindah KK mempertahankan identitas krama. Tidak ada penghapusan master; status menjaga histori.
- Tempekan, Regu, Maksan, atau jenis Lainnya dalam satu tabel organisasi. Induk harus berasal dari Banjar yang sama; siklus ditolak. Unit yang masih memiliki anak, anggota, atau assignment akses tidak dapat dinonaktifkan.
- Pencarian kode KK/nama anggota atau nama krama, filter unit/status, dan pagination. Filter krama per unit mencakup keanggotaan individu dan KK.
- Form singkat, bagian nomor identitas dapat dibuka bila diperlukan, kartu responsif, dan navigasi mobile.

## Migration

`2026_09_16_000002_create_krama_module.php` menambahkan:

| Tabel | Fungsi |
|---|---|
| organizational_units | Hierarki unit organisasi |
| households | Master KK, kepala keluarga, version |
| krama | Master individu, hubungan KK/akun, version |
| household_unit_memberships | Keanggotaan unit KK |
| krama_unit_memberships | Keanggotaan unit individu |
| krama_change_requests | Status, versi dasar, policy snapshot, pengaju/verifier/approver dan waktu |
| krama_change_items | Field perubahan dan nilai lama/baru terenkripsi |

`tenant_role_assignments` mendapat `unit_id`, `include_descendants`, scope `ORGANIZATIONAL_UNIT`, dan CHECK konsistensi scope. Scope seluruh tenant tetap bernama `BANJAR` untuk kompatibilitas Fase 1. `banjar_settings` mendapat `krama_changes_require_approval` (default false).

Composite foreign key mengikat unit, KK, krama, akun membership dan change item pada Banjar yang sama. FK kepala keluarga mencakup `(banjar_id, household_id, id)`, sehingga anggota dari KK lain juga ditolak oleh MySQL. Penulisan modul memakai transaksi dan lock baris Banjar untuk menyerialkan perubahan struktur/master/workflow. Form master memakai version untuk menolak penyimpanan dari halaman lama.

Migration sudah dijalankan pada database lokal `banjarv2`. Modul KRAMA diaktifkan melalui service platform untuk dua Banjar demo yang sudah ada: Biaung dan Tegal Gundul. Tidak membuat data penduduk contoh atau credential baru.

## Permission dan scope

| Peran | Akses Fase 3 |
|---|---|
| Admin Banjar | Lihat/kelola KK dan krama, unit, aturan perubahan, tautan akun, verifikasi |
| Kesinoman | Lihat/kelola KK dan krama serta verifikasi sesuai scope |
| Kelian | Lihat data dan persetujuan perubahan sesuai scope |
| Krama | Data individu yang terhubung dengan akunnya dan pengajuan miliknya |
| Petengen / Penyarikan | Tidak otomatis mendapat akses master krama |
| Super Admin | Mengaktifkan modul dan mengatur akun/scope; tidak otomatis bisa membaca data krama |

Permission baru: `krama.view`, `krama.manage`, `krama.self`, `units.manage`, `krama.configure`, `change.verify`, `change.approve`.

Super Admin → Banjar → **Akses pengurus** menyediakan seluruh Banjar atau satu unit tugas untuk Kesinoman/Kelian. Unit turunan hanya disertakan bila dicentang. Satu role assignment per membership tetap mengikuti UI Fase 1; beberapa unit tugas dapat diwakili unit induk dengan akses turunan. Scope dari beberapa assignment dengan permission yang sama digabung oleh service.

Akses KK berasal dari keanggotaan unit KK. Individu juga dapat diakses lewat unitnya sendiri; akses individu tidak membuka anggota lain dalam KK. Operator unit tidak boleh memindahkan individu ke/dari KK di luar aksesnya. Perubahan keanggotaan oleh operator unit mempertahankan keanggotaan di luar scope. Pencarian, detail, target form, service mutation, dan review memakai scope server. Permission tenant-wide seperti konfigurasi, website, dan audit tidak ikut terbuka melalui assignment unit.

## Pengajuan perubahan

1. Super Admin membuat akun dengan peran **Krama** melalui Akses pengurus.
2. Admin Banjar membuka individu dan memilih akun pada **Akun untuk pengajuan mandiri**. Hanya satu individu per akun per Banjar, dan akun harus memiliki membership aktif di Banjar tersebut.
3. Krama masuk ke domain Banjar → **Data saya** → **Ajukan perubahan data**. Pilih field dan isi nilai baru serta alasan. Nama, NIK, tanggal lahir, telepon, dan status diperbolehkan; KK/akun/unit bukan field pengajuan mandiri.
4. Kesinoman atau Admin Banjar → Krama → **Pengajuan perubahan** → verifikasi atau tolak dengan alasan.
5. Bila policy meminta, Kelian memberikan keputusan setelah verifikasi. Policy disalin saat submit; perubahan setting tidak mengubah pengajuan berjalan.
6. Pengaju tidak bisa memverifikasi/menyetujui pengajuan sendiri; verifier juga tidak bisa menjadi approver. Tanpa approval tambahan, verifikasi langsung menerapkan perubahan.
7. Jika versi master berubah, pengajuan menjadi **Data sudah berubah** tanpa menimpa master. Ajukan ulang berdasarkan data terbaru. Aksi berulang terhadap status terminal ditolak.

Nilai lama/baru, pengaju, verifier, approver, dan waktunya tersimpan. Setiap tahap mencatat audit teredaksi. Pengurus juga dapat mengoreksi master langsung sesuai izin; policy approval berlaku untuk alur pengajuan perubahan, bukan seluruh pekerjaan pendataan oleh operator.

## Privasi dan keputusan implementasi

- Kode internal KK dan nama adalah identitas operasional minimum; NIK/nomor KK tidak wajib, tidak dipakai dalam pencarian, dan tidak dianggap sudah diverifikasi ke instansi kependudukan.
- NIK/nomor KK disimpan memakai enkripsi Laravel dan hanya ditampilkan empat digit terakhir. Nilai perubahan, alasan, dan catatan pemeriksaan juga terenkripsi. APP_KEY harus tetap tersimpan aman agar data dapat dibaca.
- Input nomor identitas kosong saat edit mempertahankan nilai lama; checkbox terpisah menghapusnya. Input sensitif tidak di-flash ke session saat validasi gagal.
- Master serta payload identitas tidak dimasukkan ke audit umum. Halaman privat memakai `no-store`; PWA tetap hanya menyimpan aset/fallback generik.
- Pengajuan mandiri hanya membuka data individu sendiri. Akses keluarga/portal terpadu menunggu fase terkait dan kebijakan privasi; tidak diberikan secara implisit kepada kepala keluarga.
- Default approval tambahan tidak diperlukan. Admin dapat mengaktifkan kebutuhan Kelian di **Unit & aturan**.
- Retensi/penghapusan data, impor/ekspor massal, reset/invitation email, dan keputusan pengumpulan data penduduk nyata memerlukan kebijakan operasional sebelum penggunaan produksi.

## Pengujian

- MySQL `banjarv2_testing`: **53 tes / 543 assertions lulus**, termasuk 15 tes Fase 3 dan seluruh regresi Fase 1–2.
- NIK/nomor KK encrypted dan masked; no-store; data krama tidak muncul publik; input sensitif tidak di-flash.
- Kepala keluarga lintas KK ditolak di service dan FK; kepala keluarga tidak bisa pindah/nonaktif; perubahan status melalui pengajuan mengikuti invariant yang sama.
- Isolasi tenant, scope unit/turunan, scope individu, filter/search, penolakan perubahan unit di luar akses, dan preservasi membership di luar scope.
- Module/role gate di route dan service, scope review, pemilik pengajuan, allowlist, separation of duties, optional approval, policy snapshot, penolakan, replay, konflik version, dan perpindahan KK.
- `npm test`: **4 tes PWA lulus**.
- `npm run build`: aset Vite berhasil dibangun.
- `vendor/bin/pint --test app config database routes tests`: lulus.
- Browser lokal: daftar KK, formulir KK/krama, unit/aturan dan navigasi diperiksa pada viewport mobile/tablet/desktop; tidak ada overflow horizontal pada halaman yang diperiksa. Uji penyimpanan dan workflow dilakukan lewat feature test MySQL.

## Batas fase

Fase 3 tidak mencakup administrasi surat, keuangan, pembayaran, presensi, portal lengkap atau deployment produksi. Administrasi surat dilanjutkan pada [Fase 4](PHASE_4_ADMINISTRATION.md).
