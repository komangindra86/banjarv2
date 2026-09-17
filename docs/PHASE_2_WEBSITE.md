# Fase 2 — Website Banjar

Status: selesai, 15 September 2026. Implementasi lokal pada fondasi Fase 1. Fase 3 belum dimulai.

## Hasil

- Website publik tenant pada `/`, dengan Template 01, logo/sampul opsional, warna aksen tenant, dan navigasi mobile. Tidak membuat aplikasi atau deployment baru.
- Beranda menampilkan pengumuman aktif (prioritas penting dahulu), agenda mendatang, informasi layanan, berita, serta kontak/lokasi.
- Profil, sejarah, pengurus publik, pengumuman, berita, agenda basic, layanan (syarat/prosedur/kontak), dokumen publik, dan galeri dapat dikelola oleh pengurus berizin.
- Dashboard menampilkan jumlah pengumuman aktif, agenda mendatang, berita terbit, serta tindakan Buat Pengumuman.
- Draf, publikasi terjadwal, pengarsipan, pencarian daftar admin, dan pagination. Aksi arsip mempertahankan data dan slug; konten dapat diterbitkan kembali lewat Edit.
- Waktu formulir dan tampilan menggunakan **WITA / Asia Makassar**; database tetap UTC. Jadwal diperiksa pada setiap pembacaan, sehingga tidak memerlukan cron untuk mulai/berhenti tampil.
- Pengumuman kedaluwarsa tidak tampil sebagai aktif. Riwayat ada di `/pengumuman?arsip=1`; permalink tetap dapat dibaca dengan label masa berlaku berakhir. Arsip manual disembunyikan dari publik.
- Permalink memiliki tautan berbagi WhatsApp, canonical, judul/deskripsi SEO, Open Graph, dan social image dari foto publik atau ikon fallback. `/sitemap.xml` hanya memuat konten terbit yang memenuhi waktu/modul.
- Canonical, share, dan QR menggunakan primary domain tenant yang VERIFIED. Skema dan port berasal dari APP_URL, bukan header Host/Forwarded-Host. Alias terverifikasi tetap melayani tenant yang sama dengan canonical primary.
- QR dibuat di browser dengan `qrcode` 1.5.4, dapat diunduh sebagai PNG 768 px dengan quiet zone. Library dibundel lokal; tidak mengirim URL ke layanan QR pihak ketiga.
- Peta berupa tautan Google Maps dari koordinat tervalidasi. Tidak menggunakan iframe atau API key.

## Migration dan struktur

`2026_09_15_000002_create_website_module.php` menambahkan:

| Tabel | Fungsi |
|---|---|
| website_profiles | Profil 1:1 tenant, alamat/kontak, koordinat, logo/sampul |
| media_files | Metadata berkas, lokasi privat, visibility, pengunggah |
| activities | Agenda basic; akan dipakai kembali pada Fase 8 |
| announcements | Pengumuman, prioritas, masa berlaku, relasi agenda |
| news_posts | Berita dan dokumentasi |
| public_officials | Nama/jabatan publik, urutan; tidak wajib akun login |
| service_definitions | Layanan yang akan dikembangkan untuk administrasi |
| public_documents | Informasi dan lampiran dokumen publik |
| galleries, gallery_items | Album dan pilihan foto, maksimal 50 foto per album di form |

Seluruh tabel memakai `banjar_id`. Slug unik per tenant per jenis konten. FK composite mencegah referensi media, agenda, atau galeri tenant lain. Tabel terdaftar dalam TenantQuery. Migration sudah diterapkan pada database lokal `banjarv2`; migration pengujian hanya pada `banjarv2_testing`.

`app/Modules/Website` berisi katalog konten, ContentManager, MediaManager, dan query publik. Validasi berada pada batas service agar pemanggilan langsung tetap diperiksa. Controller admin/public terpisah; Blade berbagi layout dan komponen form.

## Izin dan media

- WEBSITE + `website.manage`: profil, pengurus, seluruh konten website, pustaka media, QR.
- ACTIVITY BASIC/ACTIVE + `activity.manage`: agenda. Halaman agenda publik juga memerlukan WEBSITE aktif.
- `settings.manage`: template/warna, mengikuti Fase 1. Admin Banjar dan Penyarikan memiliki izin konten; Kelian tidak dapat mengubahnya.
- Tenant berasal dari CurrentBanjar; parameter `banjar_id` pengguna diabaikan. Lookup ID admin, download, relasi dan mutasi menggunakan tenant yang aktif. Mutasi menulis audit dalam transaksi yang sama.
- Unggahan JPG/PNG/WebP/PDF maksimal 10 MB. Foto maksimal 8.000 px per sisi dan 20 megapiksel, dibaca lalu dienkode ulang ke WebP untuk membuang metadata/payload tambahan. SVG/executable ditolak.
- Semua berkas disimpan pada disk privat di `storage/app/private/banjars/{id}/media/{uuid}`. Tidak memerlukan `storage:link`.
- `/media/{id}` hanya melayani berkas PUBLIC milik tenant yang direferensikan oleh profil atau konten yang sudah terbit. Berkas draf, future, orphan, privat, tenant lain dan konten berstatus ARCHIVED ditolak. Foto dari pengumuman kedaluwarsa tetap tersedia melalui histori publik.
- Pengurus berizin dapat mengunduh berkas privat tenant melalui `/admin/media/{id}/download` dan mencabut akses publik melalui Pustaka media. Tidak ada endpoint penghapusan fisik berkas.
- PDF diunduh sebagai attachment bernama aman, dilengkapi nosniff dan CSP sandbox. Konten teks memakai escaped Blade dan mempertahankan baris baru; tidak menerima rich HTML/custom CSS.
- Header no-store dan perilaku service worker tetap mengikuti Fase 1. Tidak menyimpan halaman admin/publik berisi data tenant ke cache offline.

## Cara menggunakan

1. Buka `http://biaung.banjar.localhost:18915/` atau `http://tegalgundul.banjar.localhost:18915/` untuk website publik.
2. Masuk melalui `/login`; akses demo tetap di `storage/app/private/demo-access.txt`.
3. **Konten → Pustaka media**: unggah foto/dokumen; pilih Publik bila akan ditampilkan pada website.
4. **Konten → Profil & kontak**: isi informasi nyata, logo, sampul, alamat dan koordinat. Penyimpanan profil langsung mengubah halaman publik.
5. **Konten**: pilih jenis informasi → Tambah → isi → pilih Draf/Terbit. Atur waktu mendatang bila ingin menjadwalkan. Berkas privat tetap tidak ditampilkan meskipun kontennya terbit.
6. **Kegiatan**: kelola agenda; tidak ada peserta/presensi/dedosan pada fase ini.
7. **Konten → QR website**: unduh PNG. QR lokal berisi domain `.localhost`; sebelum mencetak untuk penggunaan masyarakat, konfigurasi domain produksi terverifikasi dan APP_URL HTTPS yang sesuai.

Konten nyata, nama/foto pengurus, dokumen, serta kontak belum diisikan. Empty state ditampilkan sampai pengurus menerbitkan informasi. Satu pengumuman bertanda “uji fase 2” yang dipakai untuk uji browser sudah diarsipkan.

## Bukti pengujian

- PHPUnit: **38 tes, 388 assertion**, lulus pada MySQL `banjarv2_testing` (26 tes fondasi + 12 tes website).
- Mencakup seluruh jenis konten: create/edit/draft/publish/archive; isolasi host/ID/relasi; query/form tenant palsu; FK lintas tenant; izin route/service; modul nonaktif; status/waktu publikasi; expiry; UTC/WITA; slug unik; input judul malformed; escaping XSS; unggahan terlarang; regenerasi raster; media privat/orphan/draf; unduh PDF; galeri; profil; canonical/share/QR/sitemap.
- **4 tes PWA lulus**. Build Vite, kompilasi Blade, dan format Pint lulus.
- Browser: penyimpanan draf → publikasi → detail/tautan WhatsApp → arsip berhasil. QR menghasilkan kanvas 768 px dan tautan unduh PNG. Tidak mengirim pesan WhatsApp dalam pengujian.
- Tampilan publik 390/768/1280 px serta form/QR pengurus 360 px diperiksa tanpa overflow horizontal; screenshot publik mobile dan QR ditinjau. Tidak ada error console pada halaman yang diperiksa.

## Batas fase dan instalasi

- Satu template curated siap dipakai (Template 01), sesuai prioritas blueprint. Variasi template lain belum tersedia.
- Peta memakai link Google Maps; logo, sampul, galeri dan dokumen memakai berkas yang diunggah pengguna. Tidak mengarang aset/fakta Banjar.
- Informasi layanan belum memiliki Ajukan Sekarang karena alur pemohon administrasi belum tersedia.
- Fitur KK/krama, presensi, pembayaran, custom-domain DNS/TLS, deployment produksi dan hardening operasional tetap mengikuti fase berikutnya.
- PHP memerlukan GD dengan WebP. Atur `upload_max_filesize >= 10M`, `post_max_size > 10M`, serta batas upload web server yang sesuai; preview Laragon saat ini mencukupi. Gunakan PHP 8.2+ yang dijelaskan README.
- Tidak dibuat commit: workspace berada dalam Git induk yang mengabaikan folder aplikasi. Source, migration, test dan lockfile tetap tersimpan di workspace.
