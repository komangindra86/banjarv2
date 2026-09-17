# Pembaruan UI — 16 September 2026

Permintaan: tetap sederhana, lebih elegan, tanpa warna hijau; referensi aplikasi TOMORO COFFEE.

## Arah visual

Referensi visual ditinjau dari [listing resmi TOMORO COFFEE di App Store](https://apps.apple.com/id/app/tomoro-coffee/id1629441033). Adaptasi menggunakan latar terang, aksen oranye, kartu ringkas dan navigasi mobile. Tidak memakai logo, maskot, foto produk atau aset merek TOMORO.

- Oranye `#f47735`: monogram dan aksen dekoratif.
- Oranye tua `#b94715`: tombol dengan teks putih, tautan dan fokus.
- Charcoal `#2c2825`: panel sambutan/login dan footer publik.
- Ivory `#faf8f5`, peach dan putih: latar serta permukaan kartu.
- Tipografi sans-serif, heading lebih tegas, border hangat, ikon garis konsisten, bayangan ringan.

## Implementasi

- `resources/css/theme.css`: token palet dan penyempurnaan komponen untuk admin/publik/login; `app.css` mengganti palet lama dan mengimpor theme.
- Dashboard: panel sambutan charcoal, ringkasan angka satu baris, ikon tindakan, daftar tindakan ringkas pada ponsel.
- Navigasi admin: ikon + label; fixed bottom navigation pada lebar ≤600px, safe-area padding, indikator halaman aktif termasuk subhalaman konten.
- Login: panel merek charcoal dan formulir putih; susunan satu kolom pada ponsel.
- Website publik: hero krem, judul sans-serif, kartu bersih, agenda/kontak hangat, footer charcoal.
- Ikon PWA dan offline memakai palet baru; cache service worker dinaikkan ke v2.
- Migration `2026_09_16_000001_refresh_default_brand_accent`: default baru dan pembaruan aksen hijau bawaan lama dengan audit. Pilihan custom tenant tidak ditimpa massal. Aksen demo Biaung yang tersimpan turquoise diselaraskan melalui form pengaturan ke oranye sesuai permintaan desain ini.
- Rollback migration mengembalikan default schema saja; warna tenant yang sudah disimpan dan riwayat audit dipertahankan.

## Verifikasi

- Build Vite berhasil, Blade dikompilasi.
- 38 tes aplikasi / 388 assertion dan 4 tes PWA lolos.
- Review browser halaman publik, login, dashboard dan pengaturan; login serta simpan pengaturan berhasil.
- Pemeriksaan mobile dan desktop untuk overflow, panel warna, ikon, navigasi bawah, dan akses formulir.
- Perubahan berfokus pada UI; tidak menambahkan modul bisnis atau mengubah permission.

# Pembaruan UI — 17 September 2026: Website publik & login “Bali modern editorial”

Permintaan: website Banjar lebih elegan sebelum Fase 8. Arah yang dipilih Product Owner: Bali modern editorial; cakupan website publik dan halaman login.

## Arah visual

- Tipografi: judul serif (Palatino Linotype/Book Antiqua/Palatino, cadangan Georgia) dengan huruf miring untuk nama Banjar; teks antarmuka sans-serif. Tidak memuat font eksternal (tanpa permintaan pihak ketiga, tetap cepat dan aman offline).
- Palet: kertas gading `#fbf7f0`, tinta `#231d18`, emas tipis `#9c7a45`/`#d8c19b`, malam `#1d1814`. Warna aksen Banjar (`accent_color`) tetap dipakai untuk tombol, huruf awal, dan sorotan.
- Ornamen orisinal dari bentuk geometris: garis kisi tenun (CSS), motif empat kelopak (`x-ornament`). Tidak memakai logo, foto, atau aset pihak lain.

## Halaman

- Header: strip “Om Swastiastu”, monogram lingkaran bertepi emas (atau logo Banjar), nama serif, navigasi garis bawah halus; menempel di atas pada layar lebar. Menu menambah “Layanan”.
- Beranda: hero penuh layar — foto sampul dengan gradasi gelap bila tersedia, atau latar gading bergradasi aksen bila belum ada foto; kartu sorotan (pengumuman terbaru/penting dan agenda terdekat) menumpuk di tepi hero; pengumuman sebagai kartu editorial; agenda pada pita gelap dengan tanggal serif emas; layanan sebagai daftar bernomor; berita dengan cerita utama dan dua cerita samping; panel kontak berbingkai.
- Daftar konten, detail artikel/agenda/layanan, dan profil: judul tengah dengan ornamen, kolom baca serif dengan huruf awal besar, fakta agenda bergaris tipis, persyaratan/prosedur layanan dua kolom, foto pengurus bulat, tautan jelajah, galeri masonry, panel bagikan WhatsApp.
- Footer: pita tenun, nama dan tagline Banjar, tautan jelajah, kontak, salam penutup.
- Login: panel malam dengan salam dan pita tenun, formulir garis bawah minimalis, tombol tinta; tetap satu kolom di ponsel.

## Implementasi

- `resources/css/public.css` (baru, CSS murni, diimpor `app.css`): token, komponen publik dan login, breakpoint 1000/800/600 px, `prefers-reduced-motion`.
- Aturan publik/login lama dihapus dari `app.css` dan `theme.css`; aturan admin tidak diubah (`inline-links` tetap generik).
- View: `layouts/public`, `public/{home,card,index,show,profile,contact}`, `auth/login`, komponen `components/ornament`. `layouts/app` hanya menerima `@yield('body_class')` agar halaman login bisa diberi gaya tanpa memengaruhi admin.
- Tidak ada perubahan controller, route, data, permission, atau service worker.

## Verifikasi

- Pratinjau Chromium pada 1366 px dan 390 px dengan data contoh, dengan dan tanpa foto: beranda, daftar berita, detail agenda, detail layanan, profil, login. Tidak ada overflow horizontal; hero dan kartu tetap rapi tanpa foto.
- Kompilasi Blade dan build Tailwind berhasil. Suite PHP/PWA dijalankan ulang di komputer lokal setelah perubahan.


Revisi: ilustrasi candi/gerbang dihapus atas permintaan Product Owner; hero tanpa foto menjadi satu kolom tipografi dan panel login hanya berisi salam.
