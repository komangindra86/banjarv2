<?php

namespace App\Http\Controllers;

use App\Modules\Website\BrandIcon;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PwaController extends Controller
{
    public function manifest(CurrentBanjar $tenant, BrandIcon $brand)
    {
        // Portal pusat dipakai semua Banjar: tanpa sesi warga identitasnya umum; setelah warga masuk, aplikasi yang
        // dipasang memakai nama dan logo Banjar warga tersebut.
        $portal = request()->attributes->get('portal');
        $banjar = $tenant->optional();
        $color = $banjar ? app(TenantQuery::class)->table('banjar_settings')->value('accent_color') : null;
        $name = $banjar->name ?? 'Bali Santih';
        $icons = $banjar ? array_filter([
            ($src = $brand->url('icon-192')) ? ['src' => $src, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'] : null,
            ($src = $brand->url('icon-512')) ? ['src' => $src, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'] : null,
            ($src = $brand->url('maskable-512')) ? ['src' => $src, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'] : null,
        ]) : [];
        // Tanpa logo Banjar (Super Admin, portal sebelum masuk, Banjar yang belum mengunggah logo) = logo Bali Santih.
        $icons = $icons ? array_values($icons) : [
            ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];

        return response()->json([
            'id' => '/', 'name' => $portal ? 'Portal Krama · '.$name : ($banjar->name ?? 'Bali Santih Digital'),
            'short_name' => $portal ? 'Portal Krama' : ($banjar && mb_strlen($banjar->name) <= 15 ? $banjar->name : 'Banjar Digital'),
            'lang' => 'id', 'start_url' => $portal ? '/portal/beranda' : '/', 'scope' => '/',
            'display' => 'standalone', 'background_color' => '#faf8f5', 'theme_color' => $color ?? '#b94715',
            'icons' => $icons,
        ])->header('Content-Type', 'application/manifest+json');
    }

    // Ikon PNG/JPEG dari logo Banjar. Di portal pusat dan dasbor Super Admin Banjar diambil dari alamat (peramban
    // mengambil ikon tanpa cookie sesi); portal hanya untuk Banjar yang modul Portal Krama-nya aktif. Logo memang
    // berkas publik (BrandIcon tetap memeriksa logo Publik dan modul Website).
    public function icon(Request $request, CurrentBanjar $tenant, BrandIcon $brand, int $banjar, string $file)
    {
        $variant = pathinfo($file, PATHINFO_FILENAME);
        abort_unless(isset(BrandIcon::VARIANTS[$variant]) && pathinfo($file, PATHINFO_EXTENSION) === BrandIcon::VARIANTS[$variant][3], 404);
        if ($request->attributes->get('portal') || $request->attributes->get('platform')) {
            $row = DB::table('banjars as b')->where('b.id', $banjar)->where('b.status', 'ACTIVE')
                ->when($request->attributes->get('portal'), fn ($q) => $q->whereExists(fn ($q) => $q->from('banjar_modules as m')->whereColumn('m.banjar_id', 'b.id')->where('m.code', 'PORTAL')->where('m.status', 'ACTIVE')))
                ->select('b.*')->first();
            abort_unless($row, 404);
            $tenant->set($row);
        }
        abort_unless($tenant->optional() && $tenant->id() === $banjar, 404);
        $path = $brand->path($variant);
        abort_unless($path, 404);

        return response()->file($path, ['Content-Type' => $variant === 'share' ? 'image/jpeg' : 'image/png', 'Cache-Control' => 'public, max-age=604800']);
    }
}
