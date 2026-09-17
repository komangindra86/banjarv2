<?php

namespace App\Http\Middleware;

use App\Platform\Tenancy\CurrentBanjar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ResolveBanjar
{
    public function __construct(private CurrentBanjar $tenant) {}

    public function handle(Request $request, Closure $next)
    {
        $this->tenant->clear();
        try {
            $platform = $request->getHost() === config('platform.host');
            $request->attributes->set('platform', $platform);
            if (! $platform) {
                $banjar = DB::table('tenant_domains as d')->join('banjars as b', 'b.id', '=', 'd.banjar_id')
                    ->where('d.domain', $request->getHost())->where('d.verification_status', 'VERIFIED')
                    ->whereNotNull('d.verified_at')->where('b.status', 'ACTIVE')->select('b.*')->first();
                abort_unless($banjar, 404);
                $this->tenant->set($banjar);
            }

            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }
}
