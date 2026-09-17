<?php

namespace App\Http\Middleware;

use App\Platform\Modules\ModuleGate;
use Closure;
use Illuminate\Http\Request;

final class RequireModule
{
    public function handle(Request $request, Closure $next, string $code, string $level = 'BASIC')
    {
        app(ModuleGate::class)->authorize($code, $level);

        return $next($request);
    }
}
