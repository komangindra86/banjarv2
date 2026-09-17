<?php

namespace App\Modules\Administration;

use App\Platform\Audit\Audit;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;
use App\Platform\Tenancy\TenantRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateLetterPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $banjarId, public int $documentId) {}

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(TenantRunner $runner, TenantQuery $query, LetterPdf $renderer, Audit $audit): void
    {
        $runner->run($this->banjarId, function () use ($query, $renderer, $audit) {
            app(ModuleGate::class)->authorize('ADMINISTRATION');
            app(ModuleGate::class)->authorize('KRAMA');
            $row = $query->table('issued_documents')->where('id', $this->documentId)->first();
            abort_unless($row, 404);
            if ($row->pdf_status === 'READY') {
                return;
            }
            $bytes = $renderer->render(LetterManager::unpack($row->snapshot));
            DB::transaction(function () use ($query, $bytes, $audit) {
                $row = $query->table('issued_documents')->where('id', $this->documentId)->lockForUpdate()->first();
                if ($row->pdf_status === 'READY') {
                    return;
                }
                $query->table('issued_documents')->where('id', $row->id)->update(['pdf_status' => 'READY', 'pdf_encrypted' => Crypt::encryptString($bytes), 'sha256' => hash('sha256', $bytes), 'generated_at' => now()]);
                $audit->record($this->banjarId, null, 'letter.pdf_generated', 'issued_documents', $row->id, [], ['status' => 'READY']);
            });
        });
    }

    public function failed(?Throwable $exception): void
    {
        // Explicit tenant predicate also works after a tenant is suspended. Never store exception payloads with PII.
        $changed = DB::table('issued_documents')->where('banjar_id', $this->banjarId)->where('id', $this->documentId)
            ->where('pdf_status', 'PENDING')->update(['pdf_status' => 'FAILED']);
        if ($changed) {
            app(Audit::class)->record($this->banjarId, null, 'letter.pdf_failed', 'issued_documents', $this->documentId, [], ['status' => 'FAILED']);
        }
    }
}
