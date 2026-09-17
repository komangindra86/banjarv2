<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#b94715"><meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Beranda') · {{ $currentBanjar->name ?? 'Bali Santih Digital' }}</title>
    <link rel="manifest" href="/manifest.webmanifest"><link rel="icon" href="/icons/icon-192.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-shell @yield('body_class')">
@php
    $isPlatform = request()->attributes->get('platform');
    $signedUser = auth($isPlatform ? 'platform' : 'web')->user();
    $access = app(\App\Platform\Identity\Access::class);
    $gate = app(\App\Platform\Modules\ModuleGate::class);
    $nav = [];
    if ($signedUser) {
        if ($isPlatform) { $nav = [['/platform', 'Banjar'], ['/platform/payments', 'QRIS'], ['/platform/withdrawals', 'Pencairan'], ['/platform/audit', 'Audit']]; }
        else {
            $nav[] = ['/admin', 'Beranda'];
            if ($gate->allows('PAYMENT') && $gate->allows('FINANCE') && $gate->allows('KRAMA')) {
                if ($access->tenant($signedUser, 'payment.view')) { $nav[] = ['/admin/payments', 'Dana QRIS']; }
                elseif ($access->tenant($signedUser, 'payment.self')) { $nav[] = ['/payments', 'Tagihan & QRIS']; }
            }
            if ($gate->allows('FINANCE') && $gate->allows('KRAMA') && $access->tenant($signedUser, 'finance.view')) { $nav[] = ['/admin/finance', 'Keuangan']; }
            if ($gate->allows('ADMINISTRATION') && $gate->allows('KRAMA')) {
                if ($access->tenant($signedUser, 'letter.view')) { $nav[] = ['/admin/letters', 'Surat']; }
                elseif ($access->tenant($signedUser, 'letter.submit')) { $nav[] = ['/portal', 'Surat saya']; }
            }
            if ($gate->allows('KRAMA') && $access->tenant($signedUser, 'krama.view')) { $nav[] = ['/admin/krama', 'Krama']; }
            elseif ($gate->allows('KRAMA') && $access->tenant($signedUser, 'krama.self')) { $nav[] = ['/admin/krama/mine', 'Data saya']; }
            if ($access->tenant($signedUser, 'website.manage') && $gate->allows('WEBSITE')) { $nav[] = ['/admin/website', 'Konten']; }
            if ($gate->allows('ACTIVITY', 'ACTIVE') && $gate->allows('KRAMA') && $access->tenant($signedUser, 'activity.view')) { $nav[] = ['/admin/activities', 'Kegiatan']; }
            elseif ($access->tenant($signedUser, 'activity.manage') && $gate->allows('ACTIVITY')) { $nav[] = ['/admin/activity', 'Kegiatan']; }
            elseif ($gate->allows('ACTIVITY', 'ACTIVE') && $gate->allows('KRAMA') && $access->tenant($signedUser, 'activity.self')) { $nav[] = ['/aktivitas', 'Kegiatan saya']; }
            if ($access->tenant($signedUser, 'settings.manage') && $gate->allows('WEBSITE')) { $nav[] = ['/admin/settings', 'Website']; }
        }
    }
@endphp
<a href="#main" class="skip-link">Langsung ke isi</a>
<header class="topbar"><div class="topbar-inner">
    <a class="brand" href="{{ $signedUser ? ($isPlatform ? '/platform' : '/admin') : '/login' }}"><span class="brand-mark" aria-hidden="true">B</span><span>{{ $currentBanjar->name ?? 'Bali Santih' }}<small>{{ $isPlatform ? 'Pusat pengelolaan Banjar' : 'Banjar Digital' }}</small></span></a>
    @if($signedUser)<form action="/logout" method="post">@csrf<button class="button-quiet" type="submit">Keluar</button></form>@else<span class="eyebrow hidden sm:block">DEKAT DENGAN BANJAR</span>@endif
</div></header>
@if($nav)<nav class="navigation" aria-label="Navigasi utama"><div>@foreach($nav as [$url, $label])<a href="{{ $url }}" @if(request()->getPathInfo() === $url || ($url !== '/admin' && $url !== '/platform' && str_starts_with(request()->getPathInfo(), $url.'/')) || ($url === '/admin/website' && request()->is('admin/content/*')) || ($url === '/admin/payments' && request()->is('admin/withdrawals', 'admin/withdrawals/*'))) aria-current="page" @endif><x-icon :name="match($label) { 'Konten' => 'news', 'Kegiatan' => 'calendar', 'Website' => 'settings', 'Audit' => 'history', 'Keuangan' => 'wallet', 'Kegiatan saya' => 'calendar', 'Banjar' => 'profile', 'Pencairan', 'Dana QRIS' => 'wallet', default => 'home' }" /><span>{{ $label }}</span></a>@endforeach</div></nav>@endif
<main id="main" class="page">
    @if(session('success'))<div class="notice" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="error-box" role="alert"><strong>Periksa kembali isian berikut.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
<footer class="footer">Banjar Digital <span>oleh PT Bali Santih Digital</span></footer>
</body></html>

