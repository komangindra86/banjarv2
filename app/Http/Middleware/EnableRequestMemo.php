<?php

namespace App\Http\Middleware;

use App\Platform\Support\RequestMemo;
use Closure;
use Illuminate\Http\Request;

// Menyalakan RequestMemo selama satu request HTTP dan membersihkannya setelah respons siap.
final class EnableRequestMemo
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set(RequestMemo::ENABLED, true);
        try {
            return $next($request);
        } finally {
            RequestMemo::stop();
        }
    }
}
