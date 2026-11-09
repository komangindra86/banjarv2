<?php

namespace App\Platform\Modules;

use App\Platform\Support\RequestMemo;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;

final class ModuleGate
{
    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant) {}

    public function allows(string $code, string $level = 'BASIC'): bool
    {
        if (! array_key_exists($code, config('platform.modules')) || ! in_array($level, ['BASIC', 'ACTIVE'], true)) {
            return false;
        }
        // Semua status modul Banjar dibaca sekali per request (menu memeriksa banyak modul di setiap halaman).
        $tenantId = $this->tenant->optional()?->id;
        $status = $tenantId
            ? RequestMemo::remember('modules:'.$tenantId, fn () => $this->query->table('banjar_modules')->pluck('status', 'code')->all())[$code] ?? null
            : $this->query->table('banjar_modules')->where('code', $code)->value('status');

        return $status === 'ACTIVE' || ($level === 'BASIC' && $code === 'ACTIVITY' && $status === 'BASIC');
    }

    public function authorize(string $code, string $level = 'BASIC'): void
    {
        abort_unless($this->allows($code, $level), 403, 'Modul belum diaktifkan.');
    }
}
