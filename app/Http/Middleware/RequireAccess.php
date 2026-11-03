<?php

namespace App\Http\Middleware;

use App\Platform\Identity\Access;
use App\Platform\Tenancy\CurrentBanjar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RequireAccess
{
    public function __construct(private Access $access) {}

    public function handle(Request $request, Closure $next, string $scope, string $permission = 'dashboard.view')
    {
        $platform = $request->attributes->get('platform');
        abort_unless(($scope === 'platform') === $platform, 404);
        $guard = $platform ? 'platform' : 'web';
        Auth::shouldUse($guard);
        if (! Auth::guard($guard)->check()) {
            if (! $platform && $request->isMethod('GET') && $request->is('portal', 'portal/*')) {
                $request->session()->put('portal_intended', $request->getPathInfo());
            }

            return redirect('/login');
        }
        // Bind sessions to exact host AND tenant identity, including domain reassignment.
        $context = $platform ? 'platform' : (string) app(CurrentBanjar::class)->id();
        if ($request->session()->get('auth_host') !== $request->getHost() || $request->session()->get('auth_context') !== $context) {
            Auth::guard($guard)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login');
        }
        $user = Auth::guard($guard)->user();
        $platform ? $this->access->requirePlatform($user) : $this->access->requireTenant($user, $permission);
        // Akun warga yang masih memakai kata sandi awal (atau baru direset) wajib menggantinya sebelum membuka halaman lain.
        if (! $platform && $user->must_change_password && $request->getPathInfo() !== '/akun/kata-sandi') {
            return redirect('/akun/kata-sandi');
        }

        return $next($request);
    }
}
