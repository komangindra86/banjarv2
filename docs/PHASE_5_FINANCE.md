# Fase 5 — Keuangan

Status: selesai, 17 September 2026. Implementasi lokal di atas Fase 1–4.

## Fitur dan penggunaan

Modul **FINANCE** memerlukan **KRAMA** aktif. Menu **Keuangan** dan kartu **Kas & tagihan** muncul sesuai izin pengurus.

1. Super Admin menugaskan role **Petengen** melalui Akses pengurus. Admin Banjar tidak otomatis boleh mencatat transaksi.
2. Petengen membuka **Buku kas → Tambah kas**. Saldo awal dicatat sebagai pemasukan dengan tanggal, referensi bukti, dan keterangan. Saldo dihitung dari transaksi, bukan kolom yang bisa diedit.
3. **Jenis & aturan → Tambah jenis tagihan** menyediakan kategori Iuran, Dedosan, dan Lainnya beserta nominal standar. Jenis dapat dinonaktifkan; nominal khusus dapat ditentukan ketika membuat event baru.
4. **Buat event** mengisi kejadian/periode dan referensi unik, misalnya `IURAN-2026-09`. Nominal standar diambil otomatis. Iuran/Lainnya menargetkan semua KK aktif atau KK aktif yang terdaftar langsung pada satu unit. Unit turunan tidak otomatis disertakan.
5. Draf menampilkan jumlah KK saat ini dan perkiraan total. Petengen memeriksa lalu menerbitkan. Penerbitan mengambil snapshot KK dan nominal; perubahan master berikutnya tidak memindahkan tagihan lama.
6. Dedosan memakai individu dari pencarian master krama dan alasan eksplisit. Maksimal satu individu per KK pada event yang sama. Default memerlukan pemeriksaan Kelian sebelum Petengen menerbitkan. Pembuat event tidak dapat menyetujui eventnya sendiri meskipun memiliki dua role. Admin Banjar dapat mengubah kebutuhan persetujuan untuk event baru melalui Jenis & aturan.
7. **Tagihan → rincian → Catat pelunasan manual** mencatat pembayaran penuh yang sudah diterima. Nominal berasal dari tagihan; pelunasan dan pemasukan kas disimpan dalam transaksi database yang sama.
8. **Buku kas** mencatat pemasukan/pengeluaran lain dan menyediakan pembalikan dengan alasan. Transaksi asli tetap tersimpan. Membalik pemasukan pelunasan membuka tagihan kembali. Pengeluaran/pembalikan ditolak jika saldo kas saat ini tidak mencukupi.
9. Event dapat dibatalkan dengan alasan. Seluruh tagihannya menjadi VOID; event dengan tagihan lunas harus membalik pelunasan terlebih dahulu. Pembatalan dan penolakan mempertahankan riwayat.
10. **Laporan** menyediakan saldo awal, pemasukan, pengeluaran, dan saldo akhir per kas untuk rentang tanggal, serta posisi tagihan saat ini menurut kategori/status. Laporan dapat dicetak melalui browser.

Dedosan fase ini adalah pencatatan manual yang diperiksa pengurus. Kandidat dari presensi dan relasi kegiatan resmi ditambahkan pada Fase 8; tidak ada dedosan otomatis akibat ketidakhadiran.

## Migration dan integritas

`2026_09_16_000004_create_finance_module.php` menambahkan:

| Tabel | Peran |
|---|---|
| cash_accounts | Daftar kas per Banjar, tanpa saldo yang dapat diedit |
| cash_transactions | Pemasukan/pengeluaran permanen, referensi bukti, aktor, sumber dan pembalikan |
| billing_types | Kategori, nama, nominal standar, status aktif |
| billing_events | Kejadian, referensi unik, snapshot kategori/nominal/policy, target, pemeriksaan, status |
| billing_event_subjects | Individu dan alasan dedosan pada draf, satu subjek per event + KK |
| bills | Snapshot KK, subjek dedosan, nominal dan status tagihan |
| bill_settlements | Pelunasan penuh, relasi kas, aktor dan waktu pembalikan |
| banjar_settings | `dedosan_requires_approval`, default true |

Uang disimpan sebagai BIGINT rupiah bulat, tanpa operasi floating point. Nominal per transaksi/tagihan 1–999.999.999.999 rupiah. Database memiliki CHECK nominal positif, bentuk target, sumber kas dan kewajiban subjek dedosan. Composite FK memastikan setiap relasi bisnis berada dalam Banjar yang sama; data finansial memakai RESTRICT saat penghapusan.

`UNIQUE(banjar_id, billing_event_id, kk_id)` melindungi bills serta subjek dedosan. Dua event berbeda tetap dapat menagih KK yang sama. Keanggotaan individu dalam KK diperiksa server dalam transaksi saat pemilihan, pemeriksaan, dan penerbitan. Relasi historis tidak memakai FK yang memaksa tagihan berpindah ketika individu pindah KK setelah terbit.

Penerbitan memakai batch 250 KK dalam satu transaksi dan baru mengubah event menjadi ISSUED setelah semua batch selesai. Pengiriman ulang event ISSUED tidak membuat tagihan tambahan. Kegagalan menggulung seluruh batch kembali. Referensi kejadian unik mencegah pencatatan ulang dengan kode yang sama; pengurus tetap bertanggung jawab memakai referensi yang konsisten untuk kejadian nyata yang sama.

Semua perubahan finansial mengunci baris Banjar yang juga dipakai modul master, lalu memeriksa kembali izin. Ini menyerialkan penerbitan, pelunasan, pembalikan, dan pengeluaran. Form kas/pelunasan memakai UUID dan hash payload; pengiriman ulang yang sama mengembalikan transaksi awal, sementara key sama dengan isi berbeda ditolak. Nominal, tenant, pembuat dan status dari browser tidak menjadi sumber kebenaran.

Generated column `bill_settlements.active_bill_id` dan unique constraint menjaga satu pelunasan aktif per tagihan. Pembalikan mengisi `reversed_at` sehingga pelunasan baru dapat dicatat tanpa menghapus histori. Unique `reversal_of_id` mencegah pembalikan ganda. Transaksi pembalik tidak dapat dibalik kembali.

## Hak akses

| Role | Izin Fase 5 |
|---|---|
| Petengen | finance.view, cash.manage, billing.issue, dedosan.issue |
| Kelian, scope Banjar | finance.view, dedosan.approve |
| Admin Banjar | finance.view, finance.configure |
| Kelian dengan scope unit | Tidak mendapat akses keuangan seluruh Banjar |
| Penyarikan, Kesinoman, Krama | Tidak otomatis mendapat akses keuangan |
| Super Admin | Aktivasi modul dan penugasan role; tidak otomatis membaca/mengubah kas tenant |

Keuangan fase ini menggunakan scope Banjar. Portal tagihan keluarga dan otorisasi keluarga lengkap belum ditambahkan. Module gate, membership, status user/tenant dan permission diperiksa pada server, termasuk pemanggilan service langsung untuk mutasi.

## Laporan, audit dan privasi

- Saldo kas mencakup seluruh transaksi termasuk pembalik. Filter laporan memakai tanggal transaksi yang dicatat pengurus. Pencatatan tanggal lampau didukung; proteksi kecukupan dana memeriksa saldo saat ini, bukan melarang semua kemungkinan saldo historis negatif.
- Pembalikan memakai tanggal hari ini dalam WITA, sehingga koreksi tidak mengubah transaksi asli. Posisi tagihan diberi label **saat ini · seluruh periode** dan tidak mengikuti filter tanggal kas.
- Pembacaan laporan dibungkus transaksi untuk snapshot konsisten pada MySQL dengan isolation default REPEATABLE READ.
- Nama subjek pada snapshot tagihan, alasan dedosan/kas, catatan pemeriksaan dan alasan pembatalan disimpan terenkripsi. Halaman keuangan tidak menampilkan NIK atau nomor KK nasional.
- Audit mencatat aktor, aksi, ID entitas, status serta angka finansial yang diizinkan. Alasan dan isi pribadi tidak disalin ke audit; input alasan tidak di-flash ke session saat validasi gagal.
- Semua halaman privat memakai `no-store`. PWA tidak menyimpan halaman keuangan; tes offline juga mencakup buku keuangan, laporan, dan detail tagihan.
- Pelunasan manual adalah pencatatan penerimaan oleh pengurus. Kas tidak digabung dengan saldo gateway. Metode GATEWAY pada schema disediakan untuk integrasi berikutnya, tanpa endpoint pembayaran gateway pada fase ini.

## Pengujian

- Suite PHP Fase 1–5: **90 tes / 875 assertions lulus**.
- Fase 5 memiliki 19 feature test aturan bisnis/akses/laporan dan satu test concurrency MySQL dengan delapan proses PHP terpisah. Skenario paralel mencakup penerbitan event yang sama, dua pelunasan untuk satu tagihan, pembalikan yang sama, dan dua pengeluaran yang melebihi saldo jika keduanya diterima.
- Tes penerbitan 262 KK membuktikan pergantian batch tidak melewatkan atau menggandakan KK. Tes laporan memeriksa saldo awal, transaksi dalam periode, dan pengecualian transaksi setelah periode.
- Empat tes PWA, build Vite, Pint dan kompilasi seluruh template Blade lulus.
- Browser pada `banjarv2_testing`: membuat event dari form, menerbitkan tiga tagihan iuran, melunasi satu, mencatat pengeluaran, dan memverifikasi saldo laporan; Petengen menambahkan subjek dedosan, Kelian menyetujui, lalu Petengen menerbitkan satu tagihan.
- Layout diperiksa pada 390 px (ringkasan, tagihan, kas, laporan, dedosan dan form event), 768 px (form event dan pilihan target unit), serta 1280 px (detail/persetujuan dedosan). Tidak ditemukan overflow horizontal pada halaman yang diukur; screenshot ringkasan, laporan dan detail dedosan juga diperiksa visual.

Jalankan `php artisan test` dengan PHP 8.2 yang dijelaskan README. Tes memakai database khusus `banjarv2_testing`. Concurrency test memakai proses terpisah dan penghalang file sementara agar kedua worker dilepas bersamaan pada Windows maupun Linux; bukan simulasi pemanggilan berurutan.

## Instalasi lokal dan batas fase

Migration telah diterapkan pada `banjarv2`. FINANCE aktif pada Biaung dan Tegal Gundul melalui service platform. Tidak ada transaksi kas atau tagihan uji yang dimasukkan ke dua Banjar demo lokal. Pengurus perlu menyiapkan akun Petengen/Kelian scope Banjar, data KK, kas, dan jenis tagihan sesuai kondisi nyata.

Belum ada cicilan, checkout beberapa tagihan sekaligus, upload bukti transaksi, ekspor spreadsheet, tagihan berulang otomatis, QRIS, ledger gateway, withdrawal, atau deployment produksi. Pencetakan laporan menggunakan browser. Fase 6 menunggu instruksi berikutnya.
