# Fase 10 — Portal Krama

Status: implementasi lokal selesai, 17 September 2026. Portal tidak menambah tabel baru: PORTAL murni antarmuka yang merangkai data dari KRAMA, FINANCE+PAYMENT, ACTIVITY, dan ADMINISTRATION yang sudah ada sejak Fase 3–8, sesuai batas modul ("bukan salinan master/bill/kegiatan").

## Alur

```
Login krama (host tenant yang sama, /portal/beranda)
→ Beranda: ringkasan tagihan, kegiatan terdekat, status pengajuan
→ Layanan surat (/portal) · Aktivitas (/aktivitas) · Profil (/admin/krama/mine)
```

## Fitur dan penggunaan

- **Beranda** (`/portal/beranda`, tab "Portal" pada navigasi utama): sapaan dengan nama krama, lalu kartu yang muncul sesuai entitlement masing-masing — **Tagihan Saya** (total belum lunas KK sendiri + tombol Bayar QRIS, perlu FINANCE+PAYMENT+`payment.self`), **Kegiatan Terdekat** (kegiatan mendatang yang mencatat krama/KK-nya, perlu ACTIVITY Aktif+`activity.self`), **Pengajuan** (jumlah surat sedang diproses, perlu ADMINISTRATION+`letter.submit`). Kartu yang modulnya nonaktif disembunyikan, bukan menampilkan error — Beranda tetap terbuka selama KRAMA aktif dan akun terhubung. Akun yang belum ditautkan Admin Banjar ke data krama melihat pesan "Akun belum terhubung" alih-alih halaman kosong/error.
- **Layanan surat**, **Aktivitas**, dan **Profil** tetap memakai halaman self-service yang sudah ada sejak Fase 4/8/3 (`/portal`, `/aktivitas`, `/admin/krama/mine`) — tidak dibuat ulang. Setiap halaman kini punya tautan "← Beranda" agar krama dapat kembali ke Portal tanpa mencari menu lain.
- Navigasi utama menambah tab **Portal** khusus untuk akun dengan `krama.self`, terpisah dari tab "Beranda" pengurus (`/admin`) yang isinya berorientasi pengelolaan konten — keduanya tetap tampil berdampingan karena satu akun bisa punya kedua peran.

## Migration

Tidak ada migration baru. Satu route ditambahkan: `GET /portal/beranda` (middleware `access:tenant`, `module:KRAMA` saja — sengaja tidak mensyaratkan FINANCE/PAYMENT/ACTIVITY/ADMINISTRATION di level route agar Beranda tetap dapat dibuka ketika salah satu modul tersebut nonaktif; setiap kartu memeriksa modul dan permission miliknya sendiri sebelum menampilkan data).

Tidak ada permission baru — Beranda memakai `krama.self`, `payment.self`, `activity.self`, `letter.submit` yang sudah ada sejak Fase 3, 6, 8, dan 4.

## Keamanan & integritas

- `PortalController::home()` mengambil data krama, tagihan, kegiatan, dan surat melalui `TenantQuery` yang sama dengan modul asalnya — tidak ada query baru yang melewati scoping tenant.
- Tagihan yang dihitung selalu milik `household_id` krama yang login (`kk_id` pada tabel `bills`); pengujian lintas-Banjar memastikan tagihan Banjar lain tidak pernah muncul.
- Pengajuan yang dihitung difilter `requester_id` (bukan `krama_id`), konsisten dengan `LetterAccess::visible()` — mencegah kebocoran pengajuan krama lain meski identitas master pernah ditautkan ulang.
- Setiap kartu diperiksa modul aktif + permission sebelum query dijalankan; tidak ada N+1 query lintas tenant karena seluruhnya memakai `TenantQuery::table()`.

## Pengujian

Hasil di komputer lokal (PHP 8.2.27, MySQL `banjarv2_testing`): **146 tes PHP / 1.359 assertions lulus** (140 sebelumnya + 6 baru), Pint lulus, 4 tes PWA lulus, build Vite berhasil. Diverifikasi langsung di browser pada tenant demo Biaung (skenario nyata: FINANCE+ADMINISTRATION aktif, ACTIVITY masih Dasar, PAYMENT nonaktif — Beranda menampilkan kartu Pengajuan saja dan menyembunyikan kartu Tagihan/Kegiatan dengan benar) dan tampilan mobile 375px, termasuk tautan "← Beranda" dari halaman Surat saya dan Data saya.

`PortalTest` (6): Beranda menyapa dengan nama krama dan menyembunyikan kartu yang modulnya nonaktif, lalu menampilkannya begitu modul terkait aktif dan data tersedia; kartu kegiatan menampilkan kegiatan mendatang milik KK sendiri; kartu pengajuan menghitung hanya pengajuan milik `requester_id` sendiri; akun yang belum tertaut menampilkan pesan ramah, bukan error; permission `krama.self` dan modul KRAMA wajib ada; isolasi tenant memastikan tagihan Banjar lain tidak pernah bocor ke Beranda.

Satu pengujian Fase 4 (`test_module_gates_apply_to_routes_services_and_public_cta`) diperbarui: sebelumnya menganggap `/portal` seluruhnya mati ketika ADMINISTRATION nonaktif. Sejak Fase 10, `/portal/beranda` sengaja tetap terbuka (hanya kartu Pengajuan yang hilang) sementara `/portal` dan `/portal/layanan` (aksi surat) tetap ditolak seperti sebelumnya — pengujian kini memeriksa label "Surat saya" secara spesifik, bukan seluruh prefix `/portal`.

## Batas dan keputusan Product Owner

- PORTAL terdaftar sebagai modul sejak Fase 1 namun belum dijadikan gerbang wajib terpisah untuk seluruh area krama; saat ini Beranda hanya mensyaratkan KRAMA. Menjadikan PORTAL sebagai saklar independen (mis. mematikan seluruh akses krama tanpa mematikan modul yang dipakai pengurus) adalah keputusan desain terbuka yang butuh instruksi PO sebelum diterapkan, karena akan mengubah gerbang akses pada rute yang sudah berjalan sejak Fase 4/6/8.
- Riwayat pembayaran lengkap dan QRIS tetap dibuka lewat `/payments`, bukan disalin ke dalam Beranda — sesuai batas modul PORTAL ("bukan salinan master/bill/kegiatan").
- PWA start_url/manifest belum diarahkan khusus ke `/portal/beranda` untuk akun krama; instalasi PWA tetap memakai manifest tenant umum dari Fase 1–2.
- Notifikasi (pengingat tagihan jatuh tempo, kegiatan mendatang) menunggu Fase 11.

Fase 11 belum dimulai.
