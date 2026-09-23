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
- Dev server: `.claude/launch.json` (port 18915). Tenant diakses lewat `http://demo.localhost:18915` (bukan `localhost` → 404). Platform: `admin.banjar.localhost`.
- Akun demo: `admin@`, `petengen@`, `kesinoman@`, `kelian@demo.test`. Kata sandi tidak dicatat; minta izin user sebelum mereset.
- Data demo milik user sedang dipakai untuk belajar: jangan hapus/ubah tanpa izin; bersihkan data uji milik sendiri (batalkan event, kembalikan status).

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
