<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class PrivateResponse
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        // Default: halaman tidak disimpan cache (data akun). Respons yang sengaja ditandai publik oleh controller
        // (ikon brand, foto publik website) tetap boleh disimpan browser agar tidak diunduh ulang tiap pindah menu.
        if (! str_contains((string) $response->headers->get('Cache-Control'), 'public')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
