# Fase 7 — Pencairan dana manual

Status: implementasi lokal selesai, 17 September 2026. Transfer bank tetap dilakukan manual oleh Super Admin Bali Santih; sistem mencatat permintaan, cadangan saldo, klaim operator, referensi, bukti, dan jurnal. Tidak ada payout API.

## Fitur dan penggunaan

- **Rekening Banjar** (`/admin/withdrawals/accounts`): Petengen mengajukan rekening (bank dari daftar, nomor, nama pemilik). Hanya satu pengajuan PENDING per Banjar. Nomor disimpan terenkripsi dan ditampilkan masked (`**** 8219`).
- **Verifikasi rekening** (Super Admin → Pencairan): tombol Periksa menampilkan nomor lengkap (diaudit), lalu Verifikasi/Tolak. Rekening terverifikasi baru menjadi utama; rekening lama menjadi REPLACED. Rekening dapat diblokir (DISABLED) dengan alasan.
- **Pencairan** (`/admin/withdrawals`, tautan dari Dana QRIS dan beranda pengurus): Petengen hanya mengisi nominal. Tujuan otomatis rekening utama terverifikasi; form tidak menerima rekening. Nominal maksimal saldo AVAILABLE.
- **Persetujuan Kelian opsional**: Admin Banjar mengatur `payout_requires_kelian_approval` (default tidak perlu). Aturan di-snapshot per permintaan. Kelian tidak dapat menyetujui permintaan yang ia ajukan sendiri. Penolakan wajib beralasan dan mengembalikan saldo.
- **Antrean Super Admin** (`/platform/withdrawals`): Proses (klaim) → transfer manual → Tampilkan nomor lengkap (hanya operator pemegang klaim, diaudit) → catat nomor referensi, waktu transfer WITA, dan bukti (foto/PDF ≤ 5 MB) → Tandai sudah dibayar. Tandai gagal hanya dengan alasan dan konfirmasi dana tidak terkirim. Permintaan yang belum diklaim dapat dibatalkan dengan alasan.
- Banjar dapat membatalkan selama APPROVAL_PENDING/REQUESTED. Setelah PROCESSING, pembatalan dari Banjar ditolak.
- Riwayat langkah (`withdrawal_steps`) dan unduh bukti tersedia untuk pengurus dengan `withdrawal.view` dan operator platform.

## Status dan ledger

```
Petengen request ──(reserve)──► APPROVAL_PENDING ──Kelian setuju──► REQUESTED
        │                            └─Kelian tolak──(release)──► CANCELLED
        └───────────────► REQUESTED ──klaim Super Admin──► PROCESSING ──(paid)──► PAID
                             └─batal──(release)──► CANCELLED      └─gagal pasti──(release)──► FAILED
```

| Movement | Debit | Kredit | Kunci idempotency |
|---|---|---|---|
| Permintaan dibuat | AVAILABLE | RESERVED | `withdrawal:{id}:reserve` |
| Transfer tercatat | RESERVED | PAID_OUT | `withdrawal:{id}:paid` |
| Batal/tolak/gagal | RESERVED | AVAILABLE | `withdrawal:{id}:release` |

`LedgerService::postWithdrawal` memakai batas ledger yang sama dengan pembayaran: kunci baris Banjar, jurnal seimbang, saldo debit diperiksa dari ledger saat itu. Jalur paid dan release saling eksklusif karena transisi status diperiksa di bawah kunci baris Banjar lalu baris withdrawal. `WithdrawalManager::invariants()` memeriksa RESERVED = total permintaan terbuka, PAID_OUT = total PAID, AVAILABLE ≥ 0, dan jumlah jurnal per status; halaman proses Super Admin menampilkan peringatan bila tidak konsisten.

Idempotency: `submission_key` UUID per form (unique per Banjar); klaim ulang oleh operator yang sama, tandai dibayar ulang dengan referensi sama, serta gagal/batal ulang tidak menambah jurnal. Referensi transfer disimpan terenkripsi dengan HMAC unik global sehingga satu transfer tidak dapat menutup dua pencairan.

## Migration

`2026_09_17_000002_create_withdrawal_module.php` (sudah diterapkan ke `banjarv2` lokal):

| Tabel/perubahan | Fungsi |
|---|---|
| banjar_bank_accounts | Versi rekening immutable: bank, nomor terenkripsi + masked, pemilik, status, reviewer; unique generated slot untuk satu rekening utama dan satu PENDING per Banjar |
| withdrawals | Reference `WD-ULID` unik, request_key, FK rekening tenant, snapshot tujuan terenkripsi, nominal, snapshot aturan Kelian, aktor/waktu request-approval-klaim-proses, referensi transfer terenkripsi + hash unik, bukti (path, mime, sha256, ukuran), catatan penutupan |
| withdrawal_steps | Jejak transisi dengan aktor, scope TENANT/PLATFORM, catatan |
| financial_ledger_transactions | `payment_id` nullable, `withdrawal_id` baru dengan FK tenant; CHECK tepat satu sumber |

CHECK database: nominal 1–999.999.999.999; PAID wajib referensi, waktu transfer, bukti dan pemroses; PROCESSING/PAID/FAILED wajib klaim; rekening utama wajib VERIFIED. Rollback migration ditolak di production bila jurnal pencairan ada.

Permission baru (RoleSeeder): `withdrawal.view` (Admin Banjar, Kelian, Petengen), `withdrawal.request` dan `bank.request` (Petengen), `withdrawal.approve` (Kelian), `withdrawal.configure` (Admin Banjar), `platform.withdrawal.process` dan `bank.verify` (Super Admin). Semua permission tenant ini hanya berlaku untuk assignment scope Banjar.

## Keamanan

- Permintaan dan transisi di server; modul PAYMENT, FINANCE, KRAMA wajib aktif untuk aksi Banjar. Penonaktifan modul tidak menghentikan penyelesaian permintaan lama oleh platform; klaim baru ditolak jika Banjar tidak ACTIVE atau rekening diblokir.
- Tenant isolation: query withdrawal selalu dengan `banjar_id` tenant; ID Banjar lain menghasilkan 404.
- Nomor rekening dan referensi transfer tidak masuk audit/log; audit hanya menyimpan masked number, bank code, status, nominal. `account_number`, `bank_reference`, `note` tidak di-flash ke sesi.
- Bukti: MIME allowlist, PDF diperiksa magic header, foto di-re-encode ke WebP (metadata dibuang), disimpan terenkripsi di disk privat dengan sha256; diunduh sebagai attachment `nosniff` + CSP sandbox. File yang ditolak atau duplikat dihapus sehingga tidak ada orphan.
- Rate limit pada endpoint tulis; semua halaman privat `no-store`.

## Pengujian

Hasil di komputer lokal (PHP 8.2.27, MySQL `banjarv2_testing`): **116 tes PHP / 1.159 assertions lulus**, Pint lulus, 4 tes PWA lulus, build Vite berhasil, lalu migration lokal diterapkan.

- `WithdrawalTest` (8): reserve atomik + snapshot + idempotency; nominal nol/negatif/desimal/melebihi saldo, PENDING tidak dapat dicairkan, rekening dari form diabaikan; Kelian on/off, self-approval ditolak, penolakan release, snapshot aturan; klaim tunggal, reveal diaudit, validasi referensi/waktu/bukti, double PAID satu jurnal, referensi ganda ditolak, tanpa orphan file; FAILED/CANCELLED release sekali dan tidak dapat dibuka lagi; ganti rekening tidak mengubah snapshot, rekening diblokir menghentikan klaim, nomor tidak bocor di audit; izin role, lintas tenant, module gate, halaman HTTP tenant/platform; alur HTTP upload PDF dan unduh bukti.
- `WithdrawalConcurrencyTest`: proses PHP terpisah dengan koneksi MySQL sendiri — AVAILABLE 100.000 dengan dua request 80.000 paralel → tepat satu berhasil (AVAILABLE 20.000); submission key sama paralel → satu withdrawal; batal Banjar vs klaim platform → satu menang; dua operator klaim → satu; paid vs failed → satu status terminal dan satu jurnal; invariants bersih dan total dana tetap.

## Batas dan keputusan Product Owner

- Verifikasi rekening cukup satu Super Admin; dual-control (pengaju ≠ pemeriksa platform) seperti bukti settlement belum diwajibkan.
- Belum ada minimum/maksimum nominal, biaya transfer, jam layanan/SLA, atau batas harian.
- Dokumen bukti kepemilikan rekening belum diunggah ke sistem; verifikasi dilakukan di luar sistem.
- Koreksi setelah PAID (misalnya dana dikembalikan bank) belum tersedia; memerlukan proses adjustment berpersetujuan tersendiri.
- Pembersihan terjadwal file bukti yatim akibat crash proses belum dibuat (penolakan normal sudah menghapus file).
- Notifikasi ke Kelian/Super Admin menunggu Fase 11.

Fase 8 belum dimulai.
