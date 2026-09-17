# Fase 1 — Platform Foundation

Status: selesai, 15 September 2026. Fase 2 belum dimulai.

Update: Fase 2 kemudian diselesaikan pada 15 September 2026; lihat [laporan Fase 2](PHASE_2_WEBSITE.md). Catatan di bawah mempertahankan cakupan saat Fase 1 selesai.

## Hasil

- Laravel 12.69.2; runtime yang diverifikasi PHP 8.2.27, MySQL 8.0.30, Node 22.22.2, Vite 7.3.6, Tailwind 4 (versi pasti di lockfile).
- Satu aplikasi dan database untuk semua Banjar. Query Builder untuk data bisnis; Eloquent hanya identitas autentikasi.
- Onboarding atomik membuat Banjar aktif, wildcard domain milik platform, pengaturan template, 9 entitlement modul, akun dan membership Admin Banjar, serta audit.
- Resolver exact host: domain VERIFIED + verified_at + Banjar ACTIVE. Domain tidak dikenal, PENDING, dan tenant nonaktif ditolak. Custom alias terverifikasi dapat menunjuk tenant yang sama; UI verifikasi DNS custom domain belum dibuat.
- CurrentBanjar scoped dan fail closed, TenantQuery untuk tabel tenant, TenantRunner untuk pekerjaan CLI/job mendatang dengan pembersihan context dalam finally.
- Login/logout, regenerasi sesi, rate limit, status akun dan membership dicek ulang, CSRF Laravel, cookie host-only/HttpOnly, Secure secara default pada production. Guard platform terpisah dari guard pengurus.
- Session menyimpan host dan ID tenant; pemindahan cookie atau reassignment domain tidak membawa akses lama.
- Super Admin: daftar/cari Banjar, onboarding, status ACTIVE/SUSPENDED/ARCHIVED, modul dan dependensinya, audit, tambah pengurus dan perubahan peran/akses. Admin aktif terakhir dilindungi transaksi.
- Shell pengurus: Beranda, Konten, Kegiatan, Website menurut izin/modul. Pengaturan template-01 dan aksen disimpan; audit dibatasi ke tenant.
- Role standar tersedia: SUPER_ADMIN, ADMIN_BANJAR, KELIAN, PETENGEN, PENYARIKAN, KESINOMAN. Fase ini UI mengelola satu peran per membership. Skema mendukung banyak assignment untuk kebutuhan selanjutnya.
- WEBSITE ACTIVE, ACTIVITY BASIC, modul lain INACTIVE saat onboarding. BASIC hanya valid untuk ACTIVITY pada validasi dan CHECK database. Ketergantungan modul diperiksa sebelum setiap perubahan, termasuk penonaktifan dependency.
- PWA dasar: manifest tenant-aware, icon 192/512 maskable, SW hanya cache offline.html dan icon. HTML akun tidak dicache. Offline navigation mendapat halaman generik tanpa data user/tenant. Static icon dibuat dari geometri kode.

## Migration

Migration bawaan: users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs.

`2026_09_15_000001_create_platform_foundation.php`: status users; banjars; tenant_domains; banjar_settings; banjar_modules; banjar_user_memberships; roles; permissions; role_permissions; platform_role_assignments; tenant_role_assignments; audit_logs.

Domain unik global. Banjar primary domain memakai FK composite sehingga tidak bisa menunjuk domain Banjar lain. Membership/assignment memakai FK composite tenant. Role scope memakai ENUM dengan daftar identik pada kedua sisi FK dan CHECK scope per tabel (urutan internal ENUM MySQL harus sama). Migration memerlukan MySQL 8.0.16+ untuk CHECK constraints; telah dijalankan pada MySQL 8.0.30. Rollback migration fondasi juga diverifikasi sebelum data demo dibuat.

## Batas izin Fase 1

| Peran | Izin saat ini |
|---|---|
| Super Admin | platform.manage; tidak otomatis mendapat akses pengurus tenant |
| Admin Banjar | dashboard.view, website.manage, activity.manage, settings.manage, audit.view |
| Kelian | dashboard.view, audit.view |
| Penyarikan | dashboard.view, website.manage, activity.manage |
| Petengen, Kesinoman | dashboard.view; izin bisnis ditambahkan saat modul terkait diimplementasikan |

ModuleGate dan Access diperiksa pada route serta service mutasi. Request banjar_id tidak dipakai sebagai sumber tenant. Assignment role tenant tidak dapat menerima role platform. Perubahan role dan penonaktifan hanya boleh dilakukan Super Admin.

## Bukti pengujian

- PHPUnit Feature: **26 tes, 137 assertion**, seluruhnya lolos pada database **banjarv2_testing**, terpisah dari database demo banjarv2. Guard pemeriksaan nama DB dijalankan sebelum RefreshDatabase.
- Skenario: tenant A/B, host palsu/unknown/unverified/alias/suspend, forwarded host, session lintas host dan domain reassignment, login/logout/rate limit, CSRF, pencabutan akun/membership, IDOR read/write, form/query banjar_id palsu, permission, module off route/service/menu, dependency/BASIC, composite FK MySQL, keunikan domain, last admin, role scope, audit redaction, password tidak masuk old input, worker context cleanup, manifest.
- Node: **4 tes PWA**, lolos. Menguji install cache allowlist, cache cleanup, fallback offline untuk halaman privat, online network-only, dan pengecualian POST/API/signed URL/cross-origin. Ini simulasi event SW, bukan uji instalasi PWA di perangkat fisik.
- Build Vite, validasi Composer, kompilasi Blade, dan format Pint dijalankan.
- Browser lokal: login Super Admin dan pengurus, sesi bertahan saat reload, navigasi dashboard, form onboarding, dan penyimpanan pengaturan website berhasil. Viewport 360, 390, 768, dan 1280 px diperiksa tanpa overflow horizontal; screenshot login/dashboard ditinjau. Tidak ada error console pada halaman pengurus yang diperiksa.

## Cara mengembangkan fase berikutnya

1. Baca blueprint dan dokumen ini. Jangan membuat aplikasi atau DB baru untuk Banjar berikutnya.
2. Tambahkan tabel milik modul dengan banjar_id, index dan composite FK untuk relasi tenant.
3. Daftarkan tabel tenant baru pada TenantQuery atau repository modul yang selalu mewajibkan CurrentBanjar. QueryBuilder ini helper scoped, bukan sandbox untuk SQL buatan developer; review join/orWhere tetap wajib.
4. Periksa Access dan ModuleGate pada service, bukan hanya menu. Tambahkan permission katalog saat implementasi modul tersedia.
5. Job membawa ID tenant tepercaya dan memakai TenantRunner; service tetap memeriksa actor dan izin. Tidak ada bisnis async pada Fase 1.
6. Audit dibuat dalam transaksi yang sama. Audit memakai allowlist nilai; jangan tambahkan password/NIK/rekening ke snapshot. Audit tidak memiliki endpoint update/delete. Hak DB produksi append-only disiapkan pada hardening.
7. Pengujian baru minimal mencakup tenant A terhadap ID B dan modul nonaktif. Jangan menjalankan refresh/fresh pada database demo atau produksi.

## Belum termasuk

Website publik, profil/konten/agenda CRUD dan template publik (Fase 2); KK/krama dan scope unit; administrasi/keuangan/pembayaran/ledger/pencairan; MFA/reset email; workflow penggabungan identitas user lintas Banjar; DNS custom domain, TLS production, undangan email, push notification, PWA perangkat fisik, deployment produksi. Halaman Konten/Kegiatan saat ini shell yang menjelaskan belum tersedianya fitur.

Wildcard platform ditandai verified saat provisioning hanya dengan asumsi base domain telah dikendalikan dan DNS/TLS wildcard telah disiapkan operator. Domain contoh lokal `.localhost` tidak memerlukan perubahan hosts. Jangan set base domain production sebelum infrastruktur tersebut siap.

Tidak ada commit dibuat; folder ini berada di bawah Git induk `C:/laragon/www` dan diabaikan oleh aturan induk. Source dan lockfile tersimpan di workspace.

Rujukan implementasi middleware: [Laravel 12 Middleware](https://laravel.com/docs/12.x/middleware).
