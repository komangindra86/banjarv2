<?php

namespace App\Http\Middleware;

use App\Modules\Portal\PortalManager;
use App\Platform\Tenancy\CurrentBanjar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// §53: di host portal pusat, Banjar ditentukan dari sesi warga (bukan domain). Hanya halaman warga yang dilayani.
final class ResolvePortalBanjar
{
    // Halaman yang boleh dibuka di portal pusat; seluruh halaman pengurus (/admin, /platform, website publik) tertutup.
    private const PATHS = [
        '#^/(login|logout|pilih-banjar|manifest\.webmanifest|robots\.txt|up)$#',
        '#^/(portal|payments|aktivitas|notifications)(/.*)?$#',
        '#^/admin/krama/(mine|changes|changes/\d+|people/\d+/change)$#',
        '#^/akun/kata-sandi$#',
        // Logo Banjar terpilih untuk bilah atas portal (PublicWebsiteController::media tetap memeriksa visibilitas).
        '#^/media/\d+$#',
        // Ikon aplikasi dari logo Banjar; Banjar-nya dari alamat karena peramban mengambil ikon tanpa cookie.
        '#^/brand/\d+/[a-z0-9-]+\.(png|jpg)$#',
    ];

    // Halaman yang tidak memerlukan Banjar terpilih.
    private const TENANTLESS = ['/login', '/logout', '/pilih-banjar', '/manifest.webmanifest', '/robots.txt', '/up'];

    public function __construct(private CurrentBanjar $tenant, private PortalManager $portal) {}

    public static function allows(string $path): bool
    {
        foreach (self::PATHS as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    public function handle(Request $request, Closure $next)
    {
        if (! $request->attributes->get('portal')) {
            return $next($request);
        }
        $path = $request->getPathInfo();
        if ($path === '/') {
            return redirect('/portal/beranda');
        }
        abort_unless(self::allows($path), 404);
        try {
            $user = Auth::guard('web')->user();
            $id = $request->session()->get('portal_banjar_id');
            // Diperiksa ulang tiap request: keanggotaan, peran, atau modul PORTAL bisa dicabut kapan saja.
            $banjar = $user && $id && $request->session()->get('auth_host') === $request->getHost() ? $this->portal->banjar($user, (int) $id) : null;
            if ($banjar) {
                $this->tenant->set($banjar);
            } elseif ($id) {
                $request->session()->forget(['portal_banjar_id', 'auth_context']);
            }
            if (! $banjar && ! in_array($path, self::TENANTLESS, true) && ! str_starts_with($path, '/brand/')) {
                if ($user) {
                    return redirect('/pilih-banjar');
                }
                if ($request->isMethod('GET') && str_starts_with($path, '/portal')) {
                    $request->session()->put('portal_intended', $path);
                }

                return redirect('/login');
            }

            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }
}
