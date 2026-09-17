# Fase 9 — Aset

Status: implementasi lokal selesai, 17 September 2026. Modul baru `ASSET`, tidak bergantung pada KRAMA; tautan ke kas FINANCE bersifat opsional dan hanya referensi (tidak mengubah tabel Finance).

## Alur

```
Perolehan → Inventaris (kategori/lokasi) → Kondisi (inspeksi)
→ Peminjaman → Pengembalian (kondisi dicatat)
→ Perawatan
→ Stock opname (hitung fisik → sesuaikan jumlah)
→ Penghapusan (status, bukan hapus baris)
```

## Fitur dan penggunaan

- **Inventaris** (`/admin/assets`, menu "Aset" saat modul ASSET aktif): kategori dan lokasi dikelola dengan status aktif/nonaktif (tidak dihapus). Aset punya jenis **Barang individual** (kendaraan, sound system — selalu jumlah 1) atau **Barang jumlah** (kursi, tikar — satu record untuk banyak barang identik, bukan satu record per unit). Kode aset dipakai untuk label QR cetak dan tidak dapat diganti setelah disimpan.
- **Kondisi**: BAIK, PERLU_PERAWATAN, RUSAK_RINGAN, RUSAK_BERAT, HILANG, TIDAK_DIGUNAKAN. Setiap pencatatan kondisi (`Catat kondisi`, dan pengembalian pinjaman untuk barang individual) tersimpan pada riwayat inspeksi.
- **Pindah lokasi**: memindahkan seluruh stok yang sedang tersedia (tidak sedang dipinjam) ke lokasi lain dan mencatat mutasi. Stok yang sedang dipinjam tetap tercatat pada lokasi asal sampai dikembalikan; aset tidak dapat dipindah sebagian antar lokasi dalam satu record — bila diperlukan, pisahkan sebagai aset berbeda.
- **Peminjaman** (`/admin/assets/loans`): dibuat untuk krama terdaftar (dicari dari master) atau peminjam luar (nama bebas). Aset ditambahkan satu per satu ke peminjaman dengan jumlah dan kondisi saat dipinjam; stok tersedia berkurang seketika. Pengembalian dicatat per baris pinjaman, boleh bertahap; jumlah dipinjam sebuah aset otomatis berkurang dan status peminjaman berubah **Sebagian kembali** atau **Selesai**. Kondisi saat kembali selalu tercatat sebagai inspeksi; untuk barang individual kondisi ini juga menjadi kondisi resmi aset (identitasnya tunggal), sedangkan untuk barang jumlah kondisi keseluruhan aset tidak otomatis berubah dari satu pengembalian saja — perlu **Catat kondisi** eksplisit bila kerusakan mewakili seluruh batch.
- **Perawatan**: jadwal dan penyelesaian per aset, dengan biaya opsional dan tautan opsional ke transaksi kas pengeluaran yang sudah dicatat Petengen di Keuangan (Fase 5). Aset modul tidak membuat transaksi kas baru.
- **Stock opname** (`/admin/assets/opname`): dibuka untuk satu lokasi atau seluruh lokasi; jumlah yang diharapkan diambil dari stok tersedia saat itu (tidak termasuk yang sedang dipinjam, karena secara fisik tidak berada di lokasi). Setiap aset dihitung manual; menyelesaikan opname menyesuaikan jumlah aset sebesar selisih hitung dan mencatat mutasi ADJUSTMENT. Penyesuaian yang membuat jumlah kurang dari yang sedang dipinjam ditolak — data peminjaman harus diperiksa dahulu.
- **Penghapusan** (permission terpisah `asset.dispose`, default hanya Admin Banjar): mengurangi jumlah aset (dijual/rusak total/hilang/dihibahkan/lainnya) dengan alasan wajib dan tautan opsional ke kas pemasukan hasil penjualan. Tidak dapat menghapus stok yang sedang dipinjam. Aset berjenis individual maupun jumlah menjadi berstatus **Dihapuskan** hanya ketika jumlahnya mencapai nol dan tidak ada yang sedang dipinjam; riwayat tetap tersimpan dan tidak ada hapus baris.
- **QR** (`/admin/assets/{id}/qr`): label cetak berisi tautan ke halaman detail aset, memakai pola QR yang sama dengan QR website (Fase 2), untuk memudahkan pemindaian saat stock opname.

## Migration

`2026_09_19_000001_create_asset_module.php`:

| Tabel | Fungsi |
|---|---|
| asset_categories, asset_locations | Master, status aktif/nonaktif, nama unik per tenant |
| assets | kind INDIVIDUAL/QUANTITY, code unik, quantity, quantity_on_loan, quantity_available (kolom generated quantity − quantity_on_loan), condition, status ACTIVE/DISPOSED, acquisition_date/cost/cash_transaction_id (referensi opsional ke cash_transactions Finance) |
| asset_movements | Log mutasi: ACQUIRED/TRANSFER/LOAN_OUT/LOAN_RETURN/CONDITION_CHANGE/ADJUSTMENT/DISPOSED, jumlah positif, lokasi asal/tujuan, aktor |
| asset_loans, asset_loan_items | Peminjaman (krama atau nama luar, wajib salah satu), item per aset dengan jumlah/jumlah kembali, kondisi saat dipinjam |
| asset_returns | Riwayat setiap pengembalian (dapat bertahap), kondisi saat kembali |
| asset_inspections | Riwayat kondisi per aset, terpisah dari kolom `condition` saat ini pada tabel assets |
| asset_maintenance | Jadwal/selesai, biaya opsional, referensi kas opsional |
| stock_opnames, stock_opname_items | Sesi hitung fisik, jumlah harapan (snapshot saat dibuka) vs jumlah dihitung |
| asset_disposals | Riwayat penghapusan: jumlah, metode, alasan, referensi kas hasil penjualan opsional |

CHECK penting: `asset_kind_quantity` (individual hanya 0 atau 1, jumlah hanya berkurang lewat penghapusan), `asset_loan_bounds` (jumlah dipinjam tidak melebihi jumlah dimiliki — menjaga `quantity_available` generated tidak pernah negatif), `asset_status_shape` (status DISPOSED hanya bila jumlah dan jumlah dipinjam nol), `loan_item_returned_bounds`, `loan_borrower_identified`, jumlah mutasi/pengembalian/penghapusan selalu positif.

Permission baru: `asset.view`, `asset.manage` (kategori, lokasi, aset, mutasi, inspeksi, peminjaman, perawatan, stock opname — Admin Banjar dan Petengen), `asset.dispose` (Admin Banjar saja secara default; keputusan PO dapat menambah Petengen atau mewajibkan persetujuan Kelian di kemudian hari). `TenantQuery` diperbarui dengan seluruh tabel aset pada allowlist tenant-owned.

Modul `ASSET` sudah terdaftar sejak Fase 1 (`config/platform.php`) tanpa dependensi modul lain — default INACTIVE untuk tenant baru, aktifkan lewat Super Admin → Banjar → Modul.

## Keamanan & integritas

- Semua penulisan mengunci baris Banjar sebelum baris aset/peminjaman/opname terkait, mengikuti pola guard tenant yang sama dengan modul lain; permission diperiksa ulang setelah lock diperoleh.
- Isolasi tenant lewat `TenantQuery` (tabel aset ditambahkan ke allowlist) dan composite FK `(banjar_id, id)`; ID aset milik Banjar lain menghasilkan 404 pada seluruh operasi termasuk lintas tenant setelah pindah context.
- Jumlah tersedia (`quantity_available`) adalah kolom generated MySQL, bukan dihitung ulang di aplikasi, sehingga tidak dapat menyimpang dari `quantity` dan `quantity_on_loan` yang tersimpan.
- Penghapusan dan penyesuaian stock opname tidak pernah menghapus baris; seluruh perubahan jumlah tercatat sebagai mutasi beserta aktor dan waktu.
- Audit mencatat pembuatan/toggle kategori dan lokasi, pembuatan aset, pembaruan, pindah lokasi, inspeksi, peminjaman dan item, pengembalian, perawatan, pembukaan/penyelesaian opname beserta penyesuaian, dan penghapusan.

## Pengujian

Hasil di komputer lokal (PHP 8.2.27, MySQL `banjarv2_testing`): **140 tes PHP / 1.335 assertions lulus** (125 sebelumnya + 15 baru), Pint lulus, 4 tes PWA lulus, build Vite berhasil, dan migration diterapkan ke database lokal `banjarv2`. Alur utama (tambah kategori/lokasi/aset, ubah jenis pada formulir, buat peminjaman untuk peminjam luar, tambah item pinjaman, QR, tampilan mobile 375px) diverifikasi langsung di browser pada tenant demo Biaung dengan modul ASSET diaktifkan sementara untuk pengujian.

`AssetTest` (15): kategori/lokasi duplikat dan toggle memerlukan permission; barang individual selalu jumlah 1 sedangkan barang jumlah mempertahankan jumlah yang diminta, kode duplikat dan kategori/lokasi nonaktif ditolak; tautan opsional ke transaksi kas divalidasi; pembaruan tidak pernah mengubah lokasi atau jenis; pindah lokasi menolak lokasi sama dan stok yang sedang dipinjam penuh; inspeksi mencatat kondisi dan riwayat; peminjaman mewajibkan peminjam dan membatasi jumlah sesuai stok tersedia, menolak aset ganda pada satu peminjaman; pengembalian memperbarui status peminjaman dan hanya memperbarui kondisi resmi aset untuk barang individual; perawatan jadwal/selesai; stock opname mengambil snapshot stok tersedia (bukan termasuk yang dipinjam) dan menyesuaikan jumlah sesuai selisih hitung; penghapusan memerlukan permission terpisah dan tidak dapat melebihi stok tersedia, serta mengunci penulisan lain setelah jumlah mencapai nol; constraint database menolak barang individual dengan jumlah lebih dari satu secara langsung di level SQL; gerbang modul dan isolasi tenant lintas Banjar; alur HTTP aset dan peminjaman penuh dari pembuatan sampai pengembalian.

## Batas dan keputusan Product Owner

- Kebijakan persetujuan untuk penghapusan (mis. wajib persetujuan Kelian seperti pencairan Fase 7) belum ditentukan; saat ini `asset.dispose` hanya permission tunggal tanpa alur approval berjenjang.
- Aset tidak dapat dipindah sebagian antar lokasi dalam satu record; kebutuhan pemisahan stok lintas lokasi untuk barang jumlah yang sama memerlukan keputusan desain lanjutan bila muncul kebutuhan nyata.
- Kondisi keseluruhan aset barang-jumlah tidak diagregasi otomatis dari riwayat inspeksi/pengembalian; pengurus perlu mencatat kondisi eksplisit bila mayoritas batch berubah.
- Tidak ada pemindaian QR langsung dari kamera untuk peminjaman/opname pada fase ini; QR hanya tautan ke halaman detail yang tetap memerlukan login pengurus.
- Notifikasi (jatuh tempo peminjaman, jadwal perawatan) menunggu Fase 11.

Fase 10 belum dimulai.
