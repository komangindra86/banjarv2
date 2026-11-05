# Banjar Digital V2 — catatan kerja untuk Claude

Selalu berkomunikasi dalam **Bahasa Indonesia**.

## Sumber kebenaran
- `Master Prompt Codex — Banjar Digital V2 Final.md` = aturan bisnis & UX. Bagian yang paling sering dipakai: §20 (peran), §23 (KK, kepala keluarga), §27 (iuran/patus/dedosan, tanggal penetapan), §29 (dedosan & pembayaran), §48 (dedosan dari presensi), §57–58 (UX), §61 (test wajib). Komentar kode merujuk nomor bagian ini — jangan ubah penomorannya.
- Setiap perubahan aturan bisnis dari user: perbarui Master Prompt juga.
- `docs/PHASE_*.md` = catatan per fase.

## Lingkungan (Windows + Laragon)
- PHP di PATH adalah 7.4 — **jangan dipakai**. Gunakan `C:\laragon\bin\php\php-8.2.27-Win32-vs16-x64\php.exe` untuk `artisan` dan `vendor/bin/phpunit`.
- Test: MySQL `banjarv2_testing` (RefreshDatabase). Jalankan seluruh suite sebelum menyatakan selesai.
- Aset: tidak ada Vite dev server; setelah mengubah `resources/css` atau `resources/js`, jalankan `npm run build`.
- Dev server: `.claude/launch.json` (port 18915). Tenant diakses lewat `http://demo.localhost:18915` (bukan `localhost` → 404). Platform: `admin.banjar.localhost`. Portal krama pusat: `portal.banjar.localhost` (§53; Banjar dari sesi, bukan domain — middleware `ResolvePortalBanjar`). Akun warga demo: I Wayan Merta (login NIK uji `5171000000000001`) dan `nyoman@demo.test`; kata sandi akun lokal di `storage/app/private/demo-access.txt`.
- Akun demo: `admin@`, `petengen@`, `kesinoman@`, `kelian@demo.test`. Kata sandi tidak dicatat; minta izin user sebelum mereset.
- Data demo milik user sedang dipakai untuk belajar: jangan hapus/ubah tanpa izin; bersihkan data uji milik sendiri (batalkan event, kembalikan status).

## Server & deploy
- SSH: alias `balisantih` (VPS 72.62.120.163, kunci `~/.ssh/balisantih_deploy`). Prosedur lengkap: `docs/DEPLOYMENT.md`.
- Banjar V2 = `banjar.balisantih.com` (Super Admin), tenant `demobanjar.balisantih.com` dan `banjarbiaung.balisantih.com` (dialihkan dari aplikasi lama 2026-11-03; cadangan vhost lama di `/root/nginx-backups/`), serta portal krama pusat `portal.balisantih.com` (`PORTAL_HOST`). **`banjartesting`, `puradalemwirasana` (dan `portal` sebelum dialihkan ke V2) adalah aplikasi lama `/var/www/banjar-app`**, bukan V2 — jangan diubah dari proyek ini. `banjartegalgundul.balisantih.com` = aplikasi terpisah `/var/www/banjartegalgundul`.
- Akun reviewer produksi (Demo Banjar, id 2): `<peran>demobanjar@balisantih.com` untuk admin, kelian, petengen, penyarikan, kesinoman, warga (warga juga NIK uji `5171000000000001`); kata sandi publik `balisantih`, dikunci (`password_locked`). Jangan ditambah ke Banjar lain.
- Tidak ada DNS wildcard: tenant baru butuh record DNS + vhost nginx (pola `sites-available/demobanjar.balisantih.com`, pool `banjarv2.sock`) + sertifikat SSL.
- Rilis = folder `/var/www/banjarv2/releases/<commit>` dari `git archive` + `public/build` hasil build lokal; symlink `.env` & `storage` ke `shared/`; `vendor` disalin dari rilis aktif bila `composer.lock` sama (selain itu `composer install --no-dev`); `chmod -R go-w`; cache (`config/route/event/view:cache`) sebagai user `banjarv2`; backup DB bila ada migrasi; pindah `current` dengan `ln -sfn` + `mv -Tf`; `systemctl reload php8.3-fpm` dan `restart banjarv2-queue`. Di Git Bash pakai `tar --force-local`.

## Konvensi kode
- Logika bisnis di `app/Modules/*/…Manager.php`; controller tipis. Tabel tenant diakses via `TenantQuery::table()`; izin via `Access` / `FinanceAccess` (`authorize`, `authorizeAny`).
- Blade: beri spasi di antara directive berurutan (`@endif @if`, bukan `@endif@if` — tidak ter-compile). Checkbox wajib memakai `.check-option`. Daftar panjang = tabel `.table-scroll` + `.data-table`.
- Tanpa JS inline: pakai atribut `data-*` + handler di `resources/js/app.js` (`data-confirm`, `data-auto-open`, `data-close-dialog`, `data-auto-submit`, `data-rupiah`).
- Test yang membuat event tagihan wajib mengirim `event_date`. QRIS di test dibuat oleh warga: `FinanceFixture::payer($tenant, $billId)`.
- Verifikasi perubahan UI di browser pane sebelum melapor selesai.

## Aturan bisnis kunci (ringkas; detail di Master Prompt)
- Kategori tagihan: IURAN, PATUS, DEDOSAN, OTHER. Semua tagihan ke kepala keluarga; KK tanpa kepala keluarga dikecualikan.
- Kepala keluarga pindah/nonaktif/meninggal → `head_krama_id` otomatis dikosongkan.
- Status krama → Meninggal → dialog "Buat draf tagihan patus?".
- Kesinoman menetapkan & menerbitkan (menu Keuangan → Event tagihan, tanpa `finance.view`); Kelian menyetujui dedosan; Petengen menekan **Lunas** (tunai) di daftar Tagihan.
- QRIS hanya dari portal warga (`payment.self`); pengurus tidak punya `payment.create`.
- Akun warga: Admin Banjar klik **Aktifkan akun warga** (butuh NIK) → login NIK + kata sandi awal `balisantih`, wajib diganti saat pertama masuk (`AccountManager`, §53).
