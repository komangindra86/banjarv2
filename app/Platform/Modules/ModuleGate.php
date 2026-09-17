<?php

namespace App\Platform\Modules;

use App\Platform\Tenancy\TenantQuery;

final class ModuleGate
{
    public function __construct(private TenantQuery $query) {}

    public function allows(string $code, string $level = 'BASIC'): bool
    {
        if (! array_key_exists($code, config('platform.modules')) || ! in_array($level, ['BASIC', 'ACTIVE'], true)) {
            return false;
        }
        $status = $this->query->table('banjar_modules')->where('code', $code)->value('status');

        return $status === 'ACTIVE' || ($level === 'BASIC' && $code === 'ACTIVITY' && $status === 'BASIC');
    }

    public function authorize(string $code, string $level = 'BASIC'): void
    {
        abort_unless($this->allows($code, $level), 403, 'Modul belum diaktifkan.');
    }
}
