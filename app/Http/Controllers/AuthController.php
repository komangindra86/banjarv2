<?php

namespace App\Http\Controllers;

use App\Platform\Audit\Audit;
use App\Platform\Identity\Access;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $request, Access $access, CurrentBanjar $tenant, Audit $audit)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        $key = 'login:'.hash('sha256', strtolower($data['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        }
        $platform = $request->attributes->get('platform');
        $guard = Auth::guard($platform ? 'platform' : 'web');
        $ok = $guard->attempt(['email' => strtolower($data['email']), 'password' => $data['password'], 'status' => 'ACTIVE']);
        if (! $ok || ! ($platform ? $access->platform($guard->user()) : $access->tenant($guard->user()))) {
            $guard->logout();
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Email, kata sandi, atau akses Banjar tidak sesuai.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put(['auth_host' => $request->getHost(), 'auth_context' => $platform ? 'platform' : (string) $tenant->id()]);
        $audit->record($platform ? null : $tenant->id(), $guard->id(), 'auth.login', 'user', $guard->id());

        $intended = $request->session()->pull('portal_intended');
        if (! $platform && is_string($intended) && ($intended === '/portal' || str_starts_with($intended, '/portal/')) && ! str_contains($intended, '\\') && $access->tenant($guard->user(), 'letter.submit')) {
            return redirect($intended);
        }

        return redirect($platform ? '/platform' : '/admin');
    }

    public function destroy(Request $request, CurrentBanjar $tenant, Audit $audit)
    {
        $platform = $request->attributes->get('platform');
        $guard = Auth::guard($platform ? 'platform' : 'web');
        if ($guard->check()) {
            $audit->record($platform ? null : $tenant->id(), $guard->id(), 'auth.logout', 'user', $guard->id());
        }
        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
