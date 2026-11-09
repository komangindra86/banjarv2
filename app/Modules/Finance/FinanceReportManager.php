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
        $data = Validator::make($input, [
            'sangkep_title' => ['required', 'string', 'max:200'],
            'sangkep_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ], [], ['sangkep_title' => 'nama sangkep', 'sangkep_date' => 'tanggal sangkep'])->validate();

        return $this->write($actor, function () use ($actor, $data) {
            if ($draft = $this->query->table('finance_reports')->where('status', 'DRAFT')->first()) {
                $this->fail('Masih ada draf laporan "'.$draft->sangkep_title.'". Finalkan draf itu dulu sebelum membuat laporan baru.', 'sangkep_title');
            }
            $previous = $this->query->table('finance_reports')->where('status', 'FINALIZED')->whereNull('superseded_by_id')->orderByDesc('period_to')->first();
            if ($previous) {
                $from = Carbon::parse($previous->period_to)->addDay()->toDateString();
                if ($data['sangkep_date'] < $from) {
                    $this->fail('Tanggal sangkep harus setelah '.Carbon::parse($previous->period_to)->translatedFormat('d F Y').' (akhir laporan "'.$previous->sangkep_title.'").', 'sangkep_date');
                }
                $opening = (int) (json_decode($previous->computed_snapshot, true)['saldo_akhir'] ?? 0);
            } else {
                $first = $this->query->table('cash_transactions')->min('transaction_date');
                $first = $first ? Carbon::parse($first)->toDateString() : null;
                $from = $first && $first < $data['sangkep_date'] ? Carbon::parse($first)->addDay()->toDateString() : $data['sangkep_date'];
                $before = $this->query->table('cash_transactions')->where('transaction_date', '<', $from)
                    ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END), 0) AS balance")->value('balance');
                $opening = (int) $before;
            }
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

        $groups = ['IN' => [], 'OUT' => []];
        foreach ($categories as $category) {
            $groups[$category['kind']][$category['id']] = ['category' => $category, 'lines' => [], 'subtotal' => 0];
        }
        $uncategorized = ['IN' => ['lines' => [], 'subtotal' => 0], 'OUT' => ['lines' => [], 'subtotal' => 0]];
        foreach ($lines as $line) {
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
        $section = function (string $direction) use ($groups, $uncategorized) {
            $out = [];
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

        $totalMasuk = (int) $lines->where('direction', 'IN')->sum('amount');
        $totalKeluar = (int) $lines->where('direction', 'OUT')->sum('amount');
        $opening = (int) $report->opening_balance;
        $saldoAkhir = $opening + $totalMasuk - $totalKeluar;

        $accounts = $this->query->table('cash_accounts')->orderBy('name')->get()->map(fn ($row) => [
            'id' => $row->id, 'name' => $row->name, 'type' => $row->type, 'balance' => $this->finance->balance($row->id, $report->period_to),
        ])->values()->all();
        $piutang = $this->piutangAsOf($report->period_to);
        $posisiDanaTotal = array_sum(array_column($accounts, 'balance')) + $piutang['total'];

        return [
            'period_from' => $report->period_from, 'period_to' => $report->period_to,
            'sangkep_title' => $report->sangkep_title, 'sangkep_date' => $report->sangkep_date,
            'opening_balance' => $opening, 'opening_balance_source' => $report->opening_balance_source,
            'pendapatan' => $section('IN'), 'pengeluaran' => $section('OUT'),
            'total_pemasukan' => $totalMasuk, 'total_pengeluaran' => $totalKeluar, 'saldo_akhir' => $saldoAkhir,
            'accounts' => $accounts, 'piutang' => $piutang, 'posisi_dana_total' => $posisiDanaTotal,
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
