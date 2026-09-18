# Deployment produksi Banjar V2

Deployment pertama: 17 September 2026. Versi aplikasi: commit `5580195`.

## Alamat dan konfigurasi

- Super Admin: https://banjar.balisantih.com (mengarah ke `/login`).
- VPS: `72.62.120.163`, Ubuntu 24.04, Nginx, PHP 8.3 FPM, MySQL 8.
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://banjar.balisantih.com`.
- `PLATFORM_HOST=banjar.balisantih.com`.
- Konfigurasi rilis terbaru menetapkan `TENANT_BASE_DOMAIN=balisantih.com`; calon alamat Banjar baru adalah `<slug>.balisantih.com`, sejalan dengan wildcard `*.balisantih.com` pada blueprint. Domain Super Admin dan domain tenant dikonfigurasi terpisah: pembuatan tenant menolak domain yang sama dengan `PLATFORM_HOST`, sehingga `banjar.balisantih.com` tidak dapat didaftarkan sebagai domain tenant.
- DNS, virtual host, dan SSL untuk subdomain tenant belum dipasang oleh deployment ini. Siapkan DNS dan sertifikat tenant sebelum penggunaan publik. Saat deployment, set `TENANT_BASE_DOMAIN=balisantih.com` di environment produksi lalu perbarui cache konfigurasi. Perubahan ini berlaku untuk Banjar yang dibuat berikutnya; domain Banjar yang sudah tersimpan di database tetap dipertahankan.
- Instalasi memakai database baru, tanpa mengimpor data demo lokal atau aplikasi Banjar lama.
- Midtrans belum aktif dan email masih memakai mailer `log`.

## Direktori dan layanan

| Komponen | Lokasi atau nama |
| --- | --- |
| Aplikasi aktif | `/var/www/banjarv2/current` |
| Rilis pertama | `/var/www/banjarv2/releases/5580195` |
| Environment privat | `/var/www/banjarv2/shared/.env` |
| Penyimpanan persisten | `/var/www/banjarv2/shared/storage` |
| Database | `banjarv2` |
| User database | `banjarv2_app@localhost` |
| User proses aplikasi | `banjarv2` |
| Konfigurasi PHP pool | `/etc/php/8.3/fpm/pool.d/banjarv2.conf` |
| Socket PHP | `/run/php/banjarv2.sock` |
| Konfigurasi Nginx | `/etc/nginx/sites-available/banjar.balisantih.com` |
| Worker antrean | `banjarv2-queue.service` |
| Scheduler setiap menit | `banjarv2-scheduler.timer` |
| Log Nginx | `/var/log/nginx/banjarv2-access.log`, `/var/log/nginx/banjarv2-error.log` |

Environment berizin `0640 root:banjarv2`. Password database dan APP_KEY dibuat khusus instalasi produksi, tidak disimpan dalam Git. Source berada dalam arsip rilis, bukan checkout Git; jangan menjalankan `git pull` di direktori `current`.

## SSL

Sertifikat Let's Encrypt khusus `banjar.balisantih.com` berada di `/etc/letsencrypt/live/banjar.balisantih.com/`. Sertifikat pertama berlaku sampai 16 Desember 2026. HTTP dialihkan ke HTTPS. `certbot.timer` aktif dan renewal hook me-reload Nginx.

Validasi menggunakan HTTP-01 dengan webroot `/var/www/banjarv2-acme`. Pertahankan akses port 80 dan lokasi `/.well-known/acme-challenge/` untuk pembaruan otomatis.

```bash
certbot renew --cert-name banjar.balisantih.com --dry-run --no-random-sleep-on-renew
```

## Akun Super Admin

Seeder produksi hanya menyiapkan katalog role; tidak membuat akun atau password bawaan. Akun Super Admin pertama sudah dibuat sesuai email yang ditentukan pemilik, dan login serta logout berhasil diverifikasi. Untuk membuat akun tambahan, jalankan secara interaktif dari VPS:

```bash
cd /var/www/banjarv2/current
runuser -u banjarv2 -- php artisan platform:create-admin email-pemilik@example.com
```

Perintah meminta password secara tersembunyi. Jangan menyalin credential demo lokal ke produksi.

## Verifikasi dan operasi

Pada deployment pertama: 125 tes PHP / 1.259 assertions, 4 tes PWA, Pint, dan build Vite lokal lolos. Semua migrasi dan cache produksi berhasil. `/login` dan `/up` mengembalikan 200, aset CSS/JS 200, `.env` 403, jalur privat yang diuji 404. Browser menampilkan formulir Super Admin tanpa error console. Simulasi renewal SSL berhasil. Login Super Admin berhasil membuka dashboard `/platform`; logout dan penolakan akses dashboard setelah logout juga sudah diverifikasi.

```bash
systemctl status banjarv2-queue.service banjarv2-scheduler.timer
journalctl -u banjarv2-queue.service -n 50 --no-pager
journalctl -u banjarv2-scheduler.service -n 50 --no-pager
cd /var/www/banjarv2/current
runuser -u banjarv2 -- php artisan migrate:status
runuser -u banjarv2 -- php artisan schedule:list
```

Untuk rilis berikutnya, siapkan direktori rilis baru dan aset Vite, pasang dependency produksi dari lock file, tautkan environment dan storage persisten, backup database sebelum migrasi, lalu lakukan migrasi serta cache sebagai user aplikasi. Setelah verifikasi, alihkan symlink `current` secara atomik, reload PHP FPM, dan restart `banjarv2-queue.service`. Jangan mengganti APP_KEY instalasi yang sudah berjalan. Rollback kode memakai rilis sebelumnya yang kompatibel dengan schema; jangan otomatis melakukan rollback migrasi atau menghapus data.

Backup berkala di luar VPS belum dikonfigurasi pada deployment pertama.
