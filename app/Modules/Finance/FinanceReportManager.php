<?php

namespace App\Modules\Finance;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Laporan Kas ala dokumen referensi Banjar Biaung (Master Prompt §26.1-26.8): rincian transaksi
 * per kategori dengan subtotal, saldo pindahan, dan posisi dana (kas/bank + piutang, dipisah jelas).
 * compute() selalu mengembalikan array asosiatif polos (bukan objek) agar bentuknya identik baik
 * dihitung langsung dari transaksi (DRAFT) maupun dibaca dari computed_snapshot yang dibekukan (FINALIZED).
 */
final class FinanceReportManager
{
    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private FinanceAccess $access, private FinanceManager $finance, private Audit $audit) {}

    private function fail(string $message, string $field = 'report'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function write(User $actor, callable $work): mixed
    {
        $this->access->authorize($actor, 'cash.manage');

        return DB::transaction(function () use ($actor, $work) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->first();
            $this->access->authorize($actor, 'cash.manage');

            return $work();
        }, 3);
    }

    /** Penanda tangan laporan: Petengen yang memfinalkan (atau Petengen aktif pertama) dan Kelian Adat aktif. @return array{treasurer: ?string, kelian: ?string} */
    public function signers(User $actor): array
    {
        $holder = fn (string $role) => DB::table('banjar_user_memberships as m')->where('m.banjar_id', $this->tenant->id())->where('m.status', 'ACTIVE')
            ->join('tenant_role_assignments as a', fn ($j) => $j->on('a.membership_id', '=', 'm.id')->on('a.banjar_id', '=', 'm.banjar_id'))
            ->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('r.code', $role)->where('u.status', 'ACTIVE')->orderByRaw('u.id = ? desc', [$actor->id])->orderBy('m.id')->value('u.name');

        return ['treasurer' => $holder('PETENGEN'), 'kelian' => $holder('KELIAN')];
    }

    public function row(int $id): object
    {
        $row = $this->query->table('finance_reports')->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    /**
     * §26: Petengen cukup memberi nama dan tanggal sangkep; sisanya dihitung sistem dari buku kas.
     * - Periode mulai: sehari setelah akhir laporan final sebelumnya.
     * - Periode akhir: tanggal sangkep.
     * - Saldo pindahan: saldo akhir laporan sebelumnya.
     * - Laporan pertama: saldo awal Banjar sudah dicatat di buku kas, jadi tidak diisi lagi. Semua yang tercatat pada hari
     *   pertama buku kas menjadi saldo pindahan (§26.4: saldo awal bukan pendapatan periode berjalan), periode mulai esoknya.
     */
    public function create(User $actor, array $input): int
    {
        $data = $this->sangkepInput($input);

        return $this->write($actor, function () use ($actor, $data) {
            if ($draft = $this->query->table('finance_reports')->where('status', 'DRAFT')->first()) {
                $this->fail('Masih ada draf laporan "'.$draft->sangkep_title.'". Finalkan atau batalkan draf itu dulu sebelum membuat laporan baru.', 'sangkep_title');
            }
            $previous = $this->query->table('finance_reports')->where('status', 'FINALIZED')->whereNull('superseded_by_id')->orderByDesc('period_to')->first();
            [$from, $opening] = $this->period($previous, $data['sangkep_date']);
            $id = DB::table('finance_reports')->insertGetId([
                'banjar_id' => $this->tenant->id(), 'period_from' => $from, 'period_to' => $data['sangkep_date'],
                'sangkep_title' => $data['sangkep_title'], 'sangkep_date' => $data['sangkep_date'],
                'previous_report_id' => $previous?->id, 'opening_balance_source' => $previous ? 'PREVIOUS_REPORT' : 'MANUAL', 'opening_balance' => $opening,
                'opening_balance_note' => $previous ? null : Crypt::encryptString('Dihitung otomatis dari buku kas (transaksi hari pertama buku kas sebagai saldo awal).'),
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($this->tenant->id(), $actor->id, 'finance.report_created', 'finance_reports', $id, [], ['period_from' => $from, 'period_to' => $data['sangkep_date']]);

            return $id;
        });
    }

    // Draf boleh diperbaiki sebelum finalisasi: nama & tanggal sangkep (periode dan saldo pindahan dihitung ulang).
    // Draf versi koreksi hanya boleh ganti nama; periodenya harus sama dengan laporan yang dikoreksi.
    public function updateDraft(User $actor, int $id, array $input): void
    {
        $this->write($actor, function () use ($actor, $id, $input) {
            $report = $this->row($id);
            abort_unless($report->status === 'DRAFT', 409, 'Laporan yang sudah final tidak dapat diubah; buat versi koreksi.');
            $revision = $this->query->table('finance_reports')->where('superseded_by_id', $id)->exists();
            $data = $this->sangkepInput($revision ? $input + ['sangkep_date' => $report->sangkep_date ?? $report->period_to] : $input);
            $changes = ['sangkep_title' => $data['sangkep_title']];
            if (! $revision) {
                $previous = $report->previous_report_id ? $this->row((int) $report->previous_report_id) : null;
                [$from, $opening] = $this->period($previous, $data['sangkep_date']);
                $changes += ['sangkep_date' => $data['sangkep_date'], 'period_from' => $from, 'period_to' => $data['sangkep_date'], 'opening_balance' => $opening];
            }
            $this->query->table('finance_reports')->where('id', $id)->update($changes + ['updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'finance.report_updated', 'finance_reports', $id,
                ['period_from' => $report->period_from, 'period_to' => $report->period_to], ['period_from' => $changes['period_from'] ?? $report->period_from, 'period_to' => $changes['period_to'] ?? $report->period_to]);
        });
    }

    // Membatalkan draf: drafnya dihapus, buku kas tidak berubah. Draf koreksi yang dibatalkan memulihkan versi final lama.
    public function discard(User $actor, int $id): string
    {
        return $this->write($actor, function () use ($actor, $id) {
            $report = $this->row($id);
            abort_unless($report->status === 'DRAFT', 409, 'Laporan yang sudah final tidak dapat dibatalkan; buat versi koreksi.');
            $this->query->table('finance_reports')->where('superseded_by_id', $id)->update(['superseded_by_id' => null, 'updated_at' => now()]);
            $this->query->table('finance_reports')->where('id', $id)->delete();
            $this->audit->record($this->tenant->id(), $actor->id, 'finance.report_discarded', 'finance_reports', $id, ['status' => 'DRAFT', 'period_from' => $report->period_from, 'period_to' => $report->period_to], []);

            return $report->sangkep_title;
        });
    }

    private function sangkepInput(array $input): array
    {
        return Validator::make($input, [
            'sangkep_title' => ['required', 'string', 'max:200'],
            'sangkep_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ], [], ['sangkep_title' => 'nama sangkep', 'sangkep_date' => 'tanggal sangkep'])->validate();
    }

    /** Periode mulai & saldo pindahan untuk tanggal sangkep tertentu (lihat create()). @return array{0: string, 1: int} */
    private function period(?object $previous, string $sangkepDate): array
    {
        if ($previous) {
            $from = Carbon::parse($previous->period_to)->addDay()->toDateString();
            if ($sangkepDate < $from) {
                $this->fail('Tanggal sangkep harus setelah '.Carbon::parse($previous->period_to)->translatedFormat('d F Y').' (akhir laporan "'.$previous->sangkep_title.'").', 'sangkep_date');
            }

            return [$from, (int) (json_decode($previous->computed_snapshot, true)['saldo_akhir'] ?? 0)];
        }
        $first = $this->query->table('cash_transactions')->min('transaction_date');
        $first = $first ? Carbon::parse($first)->toDateString() : null;
        $from = $first && $first < $sangkepDate ? Carbon::parse($first)->addDay()->toDateString() : $sangkepDate;
        $before = $this->query->table('cash_transactions')->where('transaction_date', '<', $from)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END), 0) AS balance")->value('balance');

        return [$from, (int) $before + $this->qrisBalance(Carbon::parse($from, 'Asia/Makassar')->startOfDay()->utc()->subSecond())];
    }

    /**
     * Dana QRIS yang masih dipegang (belum dicairkan ke rekening Banjar) per waktu tertentu: saldo kantong PENDING,
     * AVAILABLE, RESERVED, dan EXCEPTION di buku besar pembayaran. Yang sudah dicairkan (PAID_OUT) tercatat di buku kas
     * sebagai pemindahan ke rekening bank. Kantong PLATFORM dan FEE (biaya layanan & biaya iPaymu) milik platform.
     */
    private function qrisBalance(Carbon $asOfUtc): int
    {
        return (int) DB::table('financial_ledger_entries as e')->join('financial_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.banjar_id', $this->tenant->id())->where('a.banjar_id', $this->tenant->id())
            ->whereIn('a.bucket', ['PENDING', 'AVAILABLE', 'RESERVED', 'EXCEPTION'])->where('e.created_at', '<=', $asOfUtc)
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'CREDIT' THEN CAST(e.amount AS SIGNED) ELSE -CAST(e.amount AS SIGNED) END), 0) AS balance")->value('balance');
    }

    public function compute(object $report): array
    {
        if ($report->status === 'FINALIZED' && $report->computed_snapshot) {
            return json_decode($report->computed_snapshot, true);
        }

        return $this->liveCompute($report);
    }

    private function liveCompute(object $report): array
    {
        $categories = $this->query->table('cash_transaction_categories')->orderBy('sort_order')->orderBy('name')->get()->map(fn ($row) => (array) $row);
        $lines = $this->query->table('cash_transactions')
            ->whereBetween('transaction_date', [$report->period_from, $report->period_to])
            ->orderBy('transaction_date')->orderBy('id')->get()->map(fn ($row) => (array) $row);

        // Pelunasan tagihan (tanpa kategori pilihan) dikelompokkan otomatis per jenis tagihan dan diringkas satu baris
        // per event tagihan ("Iuran November · 12 KK"), agar laporan tidak dipenuhi ratusan baris per KK.
        // Pembalikan pelunasan mengurangi ringkasan yang sama; saldo akhir tidak berubah.
        $billTx = $lines->filter(fn ($line) => $line['source'] === 'BILL' && $line['category_id'] === null);
        $reversedBillTx = $lines->filter(fn ($line) => $line['source'] === 'REVERSAL' && $line['category_id'] === null)
            ->filter(fn ($line) => $this->query->table('cash_transactions')->where('id', $line['reversal_of_id'])->where('source', 'BILL')->whereNull('category_id')->exists());
        // Pembayaran QRIS warga (buku besar pembayaran) masuk kelompok yang sama dengan pembayaran tunai, per rincian
        // tagihan (satu QRIS bisa untuk beberapa tagihan) sebesar nilai tagihan (bagian Banjar). Biaya layanan aplikasi
        // dibayar warga dan menjadi pendapatan platform, bukan uang Banjar.
        $fromUtc = Carbon::parse($report->period_from, 'Asia/Makassar')->startOfDay()->utc();
        $toUtc = Carbon::parse($report->period_to, 'Asia/Makassar')->endOfDay()->utc();
        $qrisReceived = DB::table('financial_ledger_transactions as t')->where('t.banjar_id', $this->tenant->id())->where('t.type', 'received')
            ->whereBetween('t.created_at', [$fromUtc, $toUtc])
            ->join('payment_items as i', fn ($j) => $j->on('i.payment_id', '=', 't.payment_id')->on('i.banjar_id', '=', 't.banjar_id'))
            ->orderBy('t.id')->orderBy('i.bill_id')->get(['t.id', 't.created_at', 'i.bill_id', 'i.amount AS banjar_amount',
                DB::raw('EXISTS (SELECT 1 FROM bill_settlements s WHERE s.payment_id = t.payment_id AND s.bill_id = i.bill_id) AS settled')]);
        $billIds = $billTx->pluck('bill_id')->merge($reversedBillTx->map(fn ($line) => $this->query->table('cash_transactions')->where('id', $line['reversal_of_id'])->value('bill_id')))
            ->merge($qrisReceived->pluck('bill_id'))->filter()->unique();
        $billInfo = $this->query->table('bills')->whereIn('bills.id', $billIds)
            ->join('billing_events as e', fn ($j) => $j->on('e.id', '=', 'bills.billing_event_id')->on('e.banjar_id', '=', 'bills.banjar_id'))
            ->get(['bills.id', 'bills.kind', 'e.id as event_id', 'e.title'])->keyBy('id');
        $kindGroups = [];
        $addToEvent = function (int $billId, array $line, int $sign) use (&$kindGroups, $billInfo) {
            $bill = $billInfo->get($billId);
            $kind = $bill->kind ?? 'OTHER';
            $event = $bill->event_id ?? 0;
            $kindGroups[$kind] ??= ['events' => [], 'subtotal' => 0];
            $row = &$kindGroups[$kind]['events'][$event];
            $row ??= ['title' => $bill->title ?? 'Tagihan', 'count' => 0, 'amount' => 0, 'date' => $line['transaction_date'], 'id' => $line['id']];
            $row['count'] += $sign;
            $row['amount'] += $sign * (int) $line['amount'];
            $row['date'] = max($row['date'], $line['transaction_date']);
            $kindGroups[$kind]['subtotal'] += $sign * (int) $line['amount'];
        };
        foreach ($billTx as $line) {
            $addToEvent((int) $line['bill_id'], $line, 1);
        }
        foreach ($reversedBillTx as $line) {
            $addToEvent((int) $this->query->table('cash_transactions')->where('id', $line['reversal_of_id'])->value('bill_id'), $line, -1);
        }
        foreach ($qrisReceived as $payment) {
            $line = ['id' => 'qris-'.$payment->id.'-'.$payment->bill_id, 'transaction_date' => Carbon::parse($payment->created_at)->timezone('Asia/Makassar')->toDateString(), 'amount' => (int) $payment->banjar_amount];
            if (! $payment->settled) {
                // Pembayaran ganda/selisih: uangnya diterima tetapi tidak melunasi tagihan; tetap dihitung agar saldo cocok.
                $kindGroups['OTHER'] ??= ['events' => [], 'subtotal' => 0];
                $row = &$kindGroups['OTHER']['events']['qris-exception'];
                $row ??= ['title' => 'Pembayaran QRIS ganda/selisih (perlu dicek)', 'count' => 0, 'amount' => 0, 'date' => $line['transaction_date'], 'id' => $line['id']];
                $row['count']++;
                $row['amount'] += $line['amount'];
                $row['date'] = max($row['date'], $line['transaction_date']);
                $kindGroups['OTHER']['subtotal'] += $line['amount'];
                unset($row);

                continue;
            }
            $addToEvent((int) $payment->bill_id, $line, 1);
        }
        // Pencairan QRIS ke rekening bank hanya pemindahan uang (pendapatannya sudah dihitung saat warga membayar).
        $transferTx = $lines->filter(fn ($line) => $line['source'] === 'TRANSFER');
        $grouped = $billTx->pluck('id')->merge($reversedBillTx->pluck('id'))->merge($transferTx->pluck('id'))->flip();
        $billSections = [];
        foreach (array_keys(FinanceManager::KINDS) as $kind) {
            if (empty($kindGroups[$kind]) || $kindGroups[$kind]['subtotal'] === 0 && collect($kindGroups[$kind]['events'])->every(fn ($e) => $e['count'] === 0)) {
                continue;
            }
            $eventLines = collect($kindGroups[$kind]['events'])->filter(fn ($e) => $e['count'] !== 0 || $e['amount'] !== 0)->sortBy('date')->map(fn ($e) => [
                'id' => $e['id'], 'transaction_date' => $e['date'], 'direction' => 'IN', 'amount' => $e['amount'],
                'reason' => Crypt::encryptString($e['title'].' · '.$e['count'].' KK'),
            ])->values()->all();
            $billSections[] = ['category' => ['id' => null, 'name' => 'Pembayaran '.FinanceManager::KINDS[$kind], 'always_show' => false], 'lines' => $eventLines, 'subtotal' => $kindGroups[$kind]['subtotal']];
        }

        $groups = ['IN' => [], 'OUT' => []];
        foreach ($categories as $category) {
            $groups[$category['kind']][$category['id']] = ['category' => $category, 'lines' => [], 'subtotal' => 0];
        }
        $uncategorized = ['IN' => ['lines' => [], 'subtotal' => 0], 'OUT' => ['lines' => [], 'subtotal' => 0]];
        foreach ($lines as $line) {
            if ($grouped->has($line['id'])) {
                continue;
            }
            $direction = $line['direction'];
            $categoryId = $line['category_id'];
            if ($categoryId !== null && isset($groups[$direction][$categoryId])) {
                $groups[$direction][$categoryId]['lines'][] = $line;
                $groups[$direction][$categoryId]['subtotal'] += (int) $line['amount'];
            } else {
                $uncategorized[$direction]['lines'][] = $line;
                $uncategorized[$direction]['subtotal'] += (int) $line['amount'];
            }
        }
        $section = function (string $direction) use ($groups, $uncategorized, $billSections) {
            $out = $direction === 'IN' ? $billSections : [];
            foreach ($groups[$direction] as $entry) {
                if (empty($entry['lines']) && ! $entry['category']['always_show']) {
                    continue;
                }
                $out[] = $entry;
            }
            if (! empty($uncategorized[$direction]['lines'])) {
                $out[] = ['category' => ['id' => null, 'name' => 'Belum dikategorikan', 'always_show' => false], 'lines' => $uncategorized[$direction]['lines'], 'subtotal' => $uncategorized[$direction]['subtotal']];
            }

            return $out;
        };

        // Pembalikan pelunasan sudah mengurangi ringkasan pemasukannya, jadi tidak dihitung lagi sebagai pengeluaran.
        $reversedTotal = (int) $reversedBillTx->sum('amount');
        $totalMasuk = (int) $lines->where('direction', 'IN')->sum('amount') - $reversedTotal - (int) $transferTx->sum('amount') + (int) $qrisReceived->sum('banjar_amount');
        $totalKeluar = (int) $lines->where('direction', 'OUT')->sum('amount') - $reversedTotal;
        $opening = (int) $report->opening_balance;
        $saldoAkhir = $opening + $totalMasuk - $totalKeluar;

        $accounts = $this->query->table('cash_accounts')->orderBy('name')->get()->map(fn ($row) => [
            'id' => $row->id, 'name' => $row->name, 'type' => $row->type, 'balance' => $this->finance->balance($row->id, $report->period_to),
        ])->values()->all();
        $piutang = $this->piutangAsOf($report->period_to);
        $qrisBalance = $this->qrisBalance($toUtc);
        $posisiDanaTotal = array_sum(array_column($accounts, 'balance')) + $qrisBalance + $piutang['total'];

        return [
            'period_from' => $report->period_from, 'period_to' => $report->period_to,
            'sangkep_title' => $report->sangkep_title, 'sangkep_date' => $report->sangkep_date,
            'opening_balance' => $opening, 'opening_balance_source' => $report->opening_balance_source,
            'pendapatan' => $section('IN'), 'pengeluaran' => $section('OUT'),
            'total_pemasukan' => $totalMasuk, 'total_pengeluaran' => $totalKeluar, 'saldo_akhir' => $saldoAkhir,
            'accounts' => $accounts, 'qris_balance' => $qrisBalance, 'piutang' => $piutang, 'posisi_dana_total' => $posisiDanaTotal,
        ];
    }

    // Piutang sebagai KEWAJIBAN YANG MASIH TERUTANG PADA TANGGAL cutoff — bukan "semua yang UNPAID sekarang":
    // tagihan yang sudah terbit pada/atau sebelum cutoff dan belum ada pelunasan aktif pada/atau sebelum cutoff.
    private function piutangAsOf(string $cutoff): array
    {
        $row = $this->query->table('bills')
            ->join('billing_events', 'billing_events.id', '=', 'bills.billing_event_id')
            ->where('billing_events.banjar_id', $this->tenant->id())
            ->where('bills.status', '!=', 'VOID')
            ->whereNotNull('billing_events.issued_at')
            ->whereDate('billing_events.issued_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('billing_events.voided_at')->orWhereDate('billing_events.voided_at', '>', $cutoff))
            ->whereNotExists(function ($q) use ($cutoff) {
                $q->select(DB::raw(1))->from('bill_settlements')
                    ->whereColumn('bill_settlements.bill_id', 'bills.id')
                    ->whereDate('bill_settlements.created_at', '<=', $cutoff)
                    ->where(fn ($q2) => $q2->whereNull('bill_settlements.reversed_at')->orWhereDate('bill_settlements.reversed_at', '>', $cutoff));
            })
            ->selectRaw('COALESCE(SUM(bills.gross_amount), 0) as total, COUNT(*) as count')->first();

        return ['total' => (int) $row->total, 'count' => (int) $row->count];
    }

    public function finalize(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, [
            'signer_treasurer_name' => ['nullable', 'string', 'max:150'],
            'signer_kelian_name' => ['nullable', 'string', 'max:150'],
        ])->validate();
        // Nama penanda tangan diisi sistem dari akun pengurus; boleh diubah bila perlu.
        $signers = $this->signers($actor);
        $data['signer_treasurer_name'] = trim((string) ($data['signer_treasurer_name'] ?? '')) ?: $signers['treasurer'];
        $data['signer_kelian_name'] = trim((string) ($data['signer_kelian_name'] ?? '')) ?: $signers['kelian'];

        $this->write($actor, function () use ($actor, $id, $data) {
            $report = $this->row($id);
            abort_unless($report->status === 'DRAFT', 409);
            $computed = $this->liveCompute($report);
            $this->query->table('finance_reports')->where('id', $id)->update([
                'status' => 'FINALIZED', 'computed_snapshot' => json_encode($computed, JSON_THROW_ON_ERROR),
                'signer_treasurer_name' => $data['signer_treasurer_name'], 'signer_kelian_name' => $data['signer_kelian_name'],
                'finalized_by' => $actor->id, 'finalized_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($this->tenant->id(), $actor->id, 'finance.report_finalized', 'finance_reports', $id, [], ['status' => 'FINALIZED']);
            dispatch(new GenerateFinanceReportPdf($this->tenant->id(), $id));
        });
    }

    public function revise(User $actor, int $id, array $input): int
    {
        $data = Validator::make($input, ['reason' => ['required', 'string', 'min:5', 'max:1000']])->validate();

        return $this->write($actor, function () use ($actor, $id, $data) {
            $old = $this->row($id);
            abort_unless($old->status === 'FINALIZED', 409, 'Hanya laporan final yang dapat dikoreksi.');
            abort_if($old->superseded_by_id, 409, 'Laporan ini sudah memiliki versi koreksi.');
            $newId = DB::table('finance_reports')->insertGetId([
                'banjar_id' => $this->tenant->id(), 'period_from' => $old->period_from, 'period_to' => $old->period_to,
                'sangkep_title' => $old->sangkep_title, 'sangkep_date' => $old->sangkep_date,
                'previous_report_id' => $old->previous_report_id, 'version' => $old->version + 1,
                'opening_balance_source' => $old->opening_balance_source, 'opening_balance' => $old->opening_balance,
                'opening_balance_note' => $old->opening_balance_note, 'reason_for_revision' => Crypt::encryptString($data['reason']),
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->query->table('finance_reports')->where('id', $old->id)->update(['superseded_by_id' => $newId, 'updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'finance.report_revised', 'finance_reports', $newId, [], ['version' => $old->version + 1]);

            return $newId;
        });
    }
}
