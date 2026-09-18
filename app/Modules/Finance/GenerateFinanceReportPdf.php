<?php

namespace App\Modules\Finance;

use App\Platform\Audit\Audit;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;
use App\Platform\Tenancy\TenantRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateFinanceReportPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $banjarId, public int $reportId) {}

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(TenantRunner $runner, TenantQuery $query, FinanceReportManager $reports, FinanceReportPdf $renderer, Audit $audit): void
    {
        $runner->run($this->banjarId, function () use ($query, $reports, $renderer, $audit) {
            app(ModuleGate::class)->authorize('FINANCE');
            app(ModuleGate::class)->authorize('KRAMA');
            $row = $query->table('finance_reports')->where('id', $this->reportId)->first();
            abort_unless($row, 404);
            if ($row->pdf_status === 'READY') {
                return;
            }
            $computed = $reports->compute($row);
            $bytes = $renderer->render($row, $computed);
            DB::transaction(function () use ($query, $bytes, $audit) {
                $row = $query->table('finance_reports')->where('id', $this->reportId)->lockForUpdate()->first();
                if ($row->pdf_status === 'READY') {
                    return;
                }
                $query->table('finance_reports')->where('id', $row->id)->update(['pdf_status' => 'READY', 'pdf_encrypted' => Crypt::encryptString($bytes), 'sha256' => hash('sha256', $bytes), 'generated_at' => now()]);
                $audit->record($this->banjarId, null, 'finance.report_pdf_generated', 'finance_reports', $row->id, [], ['status' => 'READY']);
            });
        });
    }

    public function failed(?Throwable $exception): void
    {
        $changed = DB::table('finance_reports')->where('banjar_id', $this->banjarId)->where('id', $this->reportId)
            ->where('pdf_status', 'PENDING')->update(['pdf_status' => 'FAILED']);
        if ($changed) {
            app(Audit::class)->record($this->banjarId, null, 'finance.report_pdf_failed', 'finance_reports', $this->reportId, [], ['status' => 'FAILED']);
        }
    }
}
