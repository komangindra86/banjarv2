<?php

namespace App\Http\Controllers;

use App\Modules\Finance\FinanceReportManager;
use App\Modules\Portal\PortalInfoManager;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

// §53: laporan kas per periode sangkep dan riwayat pembayaran untuk warga (krama.self, modul FINANCE).
final class PortalInfoController extends Controller
{
    public function __construct(private PortalInfoManager $info) {}

    public function reports()
    {
        return view('portal.reports', ['reports' => $this->info->reports()->paginate(20)]);
    }

    public function report(int $id, FinanceReportManager $reports)
    {
        $report = $this->info->report($id);

        return view('portal.report', ['report' => $report, 'data' => $reports->compute($report)]);
    }

    public function reportPdf(Request $request, int $id, Audit $audit, CurrentBanjar $tenant)
    {
        $report = $this->info->report($id);
        abort_unless($report->pdf_status === 'READY', 404, 'PDF belum tersedia.');
        $bytes = Crypt::decryptString($report->pdf_encrypted);
        abort_unless(hash_equals($report->sha256, hash('sha256', $bytes)), 409, 'Arsip perlu diperiksa oleh pengurus.');
        $audit->record($tenant->id(), $request->user()->id, 'finance.report_downloaded', 'finance_reports', $report->id, [], ['channel' => 'PORTAL']);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="laporan-kas-'.$report->id.'.pdf"']);
    }

    public function payments(Request $request)
    {
        $self = $this->info->self($request->user());

        return view('portal.payments', ['self' => $self, 'rows' => $self ? $this->info->payments($self) : collect(), 'unpaid' => $self ? $this->info->unpaid($self) : null]);
    }
}
