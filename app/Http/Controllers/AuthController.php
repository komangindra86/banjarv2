<?php

namespace App\Http\Controllers;

use App\Modules\Krama\AccountManager;
use App\Modules\Portal\PortalManager;
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

    public function store(Request $request, Access $access, CurrentBanjar $tenant, Audit $audit, PortalManager $portals)
    {
        $platform = $request->attributes->get('platform');
        // Warga login dengan NIK (16 digit) di domain Banjar maupun portal pusat; Super Admin tetap memakai email.
        $byNik = ! $platform && preg_match('/^[0-9]{16}$/', trim((string) $request->input('email')));
        $data = $request->validate(['email' => ['required', 'string', 'max:255', ...($byNik ? [] : ['email'])], 'password' => ['required', 'string', 'max:255']], ['email.email' => 'Isi email atau NIK 16 digit.']);
        $login = strtolower(trim($data['email']));
        $key = 'login:'.hash('sha256', $login.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        }
        $portal = $request->attributes->get('portal');
        $guard = Auth::guard($platform ? 'platform' : 'web');
        $ok = $guard->attempt([$byNik ? 'nik_hash' : 'email' => $byNik ? AccountManager::nikHash($login) : $login, 'password' => $data['password'], 'status' => 'ACTIVE']);
        $banjars = $ok && $portal ? $portals->banjars($guard->user()) : collect();
        if (! $ok || ! ($portal ? $banjars->isNotEmpty() : ($platform ? $access->platform($guard->user()) : $access->tenant($guard->user())))) {
            $guard->logout();
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => $portal ? 'NIK/email, kata sandi, atau akses Portal Krama tidak sesuai.' : 'Email/NIK, kata sandi, atau akses Banjar tidak sesuai.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        if ($portal) {
            // Satu Banjar langsung masuk; lebih dari satu memilih dulu di /pilih-banjar.
            $request->session()->put('auth_host', $request->getHost());

            return redirect($banjars->count() === 1 ? $portals->enter($request->session(), $guard->user(), $banjars->first()) : '/pilih-banjar');
        }
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
            $audit->record($platform || ! $tenant->optional() ? null : $tenant->id(), $guard->id(), 'auth.logout', 'user', $guard->id());
        }
        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
