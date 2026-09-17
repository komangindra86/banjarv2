<?php

namespace App\Http\Controllers;

use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;

final class PwaController extends Controller
{
    public function manifest(CurrentBanjar $tenant)
    {
        $color = $tenant->optional() ? app(TenantQuery::class)->table('banjar_settings')->value('accent_color') : '#b94715';

        return response()->json([
            'id' => '/', 'name' => $tenant->optional()?->name ?? 'Bali Santih Digital',
            'short_name' => 'Banjar Digital', 'lang' => 'id', 'start_url' => '/', 'scope' => '/',
            'display' => 'standalone', 'background_color' => '#faf8f5', 'theme_color' => $color ?? '#b94715',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ])->header('Content-Type', 'application/manifest+json');
    }
}
