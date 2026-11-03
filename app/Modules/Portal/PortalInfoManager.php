<?php

namespace App\Modules\Portal;

use App\Models\User;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Support\Collection;

// §53: informasi yang dapat dilihat warga di portal — hanya baca, tidak memiliki data sendiri.
final class PortalInfoManager
{
    public function __construct(private TenantQuery $query) {}

    // Krama aktif milik akun ini (dalam KK aktif), atau null bila akun belum terhubung.
    public function self(User $user): ?object
    {
        return $this->query->table('krama')->where('user_id', $user->id)->where('status', 'ACTIVE')
            ->whereIn('household_id', $this->query->table('households')->where('status', 'ACTIVE')->select('id'))->first();
    }

    // Laporan kas yang sudah difinalkan (dibacakan saat sangkep). Versi lama disembunyikan hanya setelah versi
    // koreksinya juga final — selama koreksi masih draf, warga tetap melihat versi final terakhir.
    public function reports()
    {
        return $this->query->table('finance_reports')->where('status', 'FINALIZED')
            ->whereNotExists(fn ($q) => $q->from('finance_reports as n')->whereColumn('n.id', 'finance_reports.superseded_by_id')->where('n.status', 'FINALIZED'))
            ->orderByDesc('period_to')->orderByDesc('id');
    }

    public function report(int $id): object
    {
        $report = $this->reports()->where('id', $id)->first();
        abort_unless($report, 404);

        return $report;
    }

    // Riwayat pelunasan tagihan KK warga: tunai (dicatat Petengen) maupun QRIS, terbaru dulu.
    public function payments(object $self): Collection
    {
        return $this->query->table('bills')
            ->join('bill_settlements as s', fn ($j) => $j->on('s.bill_id', '=', 'bills.id')->on('s.banjar_id', '=', 'bills.banjar_id')->whereNull('s.reversed_at'))
            ->join('billing_events as e', fn ($j) => $j->on('e.id', '=', 'bills.billing_event_id')->on('e.banjar_id', '=', 'bills.banjar_id'))
            ->where('bills.kk_id', $self->household_id)->where('bills.status', 'PAID')
            ->orderByDesc('s.created_at')->orderByDesc('bills.id')
            ->get(['bills.id', 'bills.kind', 'bills.gross_amount', 'e.title', 'e.event_date', 's.method', 's.amount', 's.created_at as paid_at']);
    }

    public function unpaid(object $self): object
    {
        return $this->query->table('bills')->where('kk_id', $self->household_id)->where('status', 'UNPAID')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(gross_amount), 0) AS total')->first();
    }
}
