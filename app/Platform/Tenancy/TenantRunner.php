<?php

namespace App\Platform\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;

final class TenantRunner
{
    public function __construct(private CurrentBanjar $tenant) {}

    /** Entry point for tenant-owned CLI work and future queue jobs. */
    public function run(int $banjarId, Closure $work): mixed
    {
        $this->tenant->clear();
        try {
            $banjar = DB::table('banjars')->where('id', $banjarId)->where('status', 'ACTIVE')->first();
            abort_unless($banjar, 404);
            $this->tenant->set($banjar);

            return $work();
        } finally {
            $this->tenant->clear();
        }
    }
}
