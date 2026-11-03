<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#b94715"><meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Beranda') · {{ $currentBanjar->name ?? 'Bali Santih Digital' }}</title>
    @php
        // Logo Banjar (Profil website, berstatus Publik) menggantikan tanda "B" di bilah atas dan ikon tab.
        $banjarLogo = ! request()->attributes->get('platform') && $currentBanjar
            ? app(\App\Modules\Website\PublicContent::class)->image(app(\App\Platform\Tenancy\TenantQuery::class)->table('website_profiles')->value('logo_id'))
            : null;
    @endphp
    <link rel="manifest" href="/manifest.webmanifest"><link rel="icon" href="{{ $banjarLogo ?? '/icons/icon-192.png' }}"><link rel="apple-touch-icon" href="{{ $banjarLogo ?? '/icons/icon-192.png' }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-shell @yield('body_class')">
@php
    $isPlatform = request()->attributes->get('platform');
    $isPortal = request()->attributes->get('portal');
    $signedUser = auth($isPlatform ? 'platform' : 'web')->user();
    $access = app(\App\Platform\Identity\Access::class);
    $gate = app(\App\Platform\Modules\ModuleGate::class);
    $nav = [];
    // Halaman login selalu tampil sebagai halaman tamu (tanpa navbar/Keluar) walau sesi lama masih aktif;
    // kartu login sendiri yang memberi tahu akun yang sedang masuk.
    $onLogin = request()->routeIs('login');
    // Portal pusat tanpa Banjar terpilih (mis. /pilih-banjar) tidak punya konteks tenant untuk menyusun menu.
    if ($signedUser && ! $onLogin && ($isPlatform || $currentBanjar)) {
        if ($isPlatform) { $nav = [['/platform', 'Banjar'], ['/platform/payments', 'QRIS'], ['/platform/withdrawals', 'Pencairan'], ['/platform/audit', 'Audit']]; }
        else {
            $nav[] = $isPortal ? ['/portal/beranda', 'Beranda'] : ['/admin', 'Beranda'];
            if ($gate->allows('NOTIFICATION') && $access->tenant($signedUser, 'dashboard.view')) {
                $unread = app(\App\Modules\Notification\NotificationManager::class)->unreadCount($signedUser);
                $nav[] = ['/notifications', $unread ? 'Notifikasi ('.$unread.')' : 'Notifikasi'];
            }
            // §53: portal pusat hanya menampilkan menu warga, walau akunnya juga pengurus.
            if ($isPortal) {
                if ($gate->allows('PAYMENT') && $gate->allows('FINANCE') && $access->tenant($signedUser, 'payment.self')) { $nav[] = ['/payments', 'Tagihan & QRIS']; }
                if ($gate->allows('ADMINISTRATION') && $access->tenant($signedUser, 'letter.submit')) { $nav[] = ['/portal', 'Surat saya']; }
                if ($gate->allows('ACTIVITY', 'ACTIVE') && $access->tenant($signedUser, 'activity.self')) { $nav[] = ['/aktivitas', 'Kegiatan']; }
                $nav[] = ['/admin/krama/mine', 'Data saya'];
                if (app(\App\Modules\Portal\PortalManager::class)->banjars($signedUser)->count() > 1) { $nav[] = ['/pilih-banjar', 'Ganti banjar']; }
            } else {
                if ($gate->allows('KRAMA') && $access->tenant($signedUser, 'krama.self')) { $nav[] = ['/portal/beranda', 'Portal']; }
                if ($gate->allows('PAYMENT') && $gate->allows('FINANCE') && $gate->allows('KRAMA')) {
                    if ($access->tenant($signedUser, 'payment.view')) { $nav[] = ['/admin/payments', 'Dana QRIS']; }
                    elseif ($access->tenant($signedUser, 'payment.self')) { $nav[] = ['/payments', 'Tagihan & QRIS']; }
                }
                if ($gate->allows('FINANCE') && $gate->allows('KRAMA') && $access->tenant($signedUser, 'finance.view')) { $nav[] = ['/admin/finance', 'Keuangan']; }
                // Kesinoman menetapkan/menerbitkan tagihan tanpa akses buku kas: masuk lewat daftar event.
                elseif ($gate->allows('FINANCE') && $gate->allows('KRAMA') && ($access->tenant($signedUser, 'billing.issue') || $access->tenant($signedUser, 'dedosan.issue'))) { $nav[] = ['/admin/finance/events', 'Keuangan']; }
                if ($gate->allows('ADMINISTRATION') && $gate->allows('KRAMA')) {
                    if ($access->tenant($signedUser, 'letter.view')) { $nav[] = ['/admin/letters', 'Surat']; }
                    elseif ($access->tenant($signedUser, 'letter.submit')) { $nav[] = ['/portal', 'Surat saya']; }
                }
                if ($gate->allows('ASSET') && $access->tenant($signedUser, 'asset.view')) { $nav[] = ['/admin/assets', 'Aset']; }
                if ($gate->allows('KRAMA') && $access->tenant($signedUser, 'krama.view')) { $nav[] = ['/admin/krama', 'Krama']; }
                elseif ($gate->allows('KRAMA') && $access->tenant($signedUser, 'krama.self')) { $nav[] = ['/admin/krama/mine', 'Data saya']; }
                if ($access->tenant($signedUser, 'website.manage') && $gate->allows('WEBSITE')) { $nav[] = ['/admin/website', 'Konten']; }
                if ($gate->allows('ACTIVITY', 'ACTIVE') && $gate->allows('KRAMA') && $access->tenant($signedUser, 'activity.view')) { $nav[] = ['/admin/activities', 'Kegiatan']; }
                elseif ($access->tenant($signedUser, 'activity.manage') && $gate->allows('ACTIVITY')) { $nav[] = ['/admin/activity', 'Kegiatan']; }
                elseif ($gate->allows('ACTIVITY', 'ACTIVE') && $gate->allows('KRAMA') && $access->tenant($signedUser, 'activity.self')) { $nav[] = ['/aktivitas', 'Kegiatan saya']; }
                if ($access->tenant($signedUser, 'settings.manage') && $gate->allows('WEBSITE')) { $nav[] = ['/admin/settings', 'Website']; }
            }
        }
    }
@endphp
<a href="#main" class="skip-link">Langsung ke isi</a>
<header class="topbar"><div class="topbar-inner">
    <a class="brand" href="{{ $signedUser && ! $onLogin ? ($isPlatform ? '/platform' : ($isPortal ? '/portal/beranda' : '/admin')) : '/login' }}">@if($banjarLogo)<img class="brand-logo" src="{{ $banjarLogo }}" alt="">@else<span class="brand-mark" aria-hidden="true">B</span>@endif<span>{{ $currentBanjar->name ?? 'Bali Santih' }}<small>{{ $isPlatform ? 'Pusat pengelolaan Banjar' : ($isPortal ? 'Portal Krama' : 'Banjar Digital') }}</small></span></a>
    @if($signedUser && ! $onLogin)<div class="topbar-actions">@if(! $isPlatform && $currentBanjar)<a class="button-quiet" href="/akun/kata-sandi">Kata sandi</a>@endif<form action="/logout" method="post">@csrf<button class="button-quiet" type="submit">Keluar</button></form></div>@else<span class="eyebrow hidden sm:block">DEKAT DENGAN BANJAR</span>@endif
</div></header>
@if($nav)<nav class="navigation" aria-label="Navigasi utama"><div>@foreach($nav as [$url, $label])<a href="{{ $url }}" @if(request()->getPathInfo() === $url || ($url !== '/admin' && $url !== '/platform' && $url !== '/portal' && str_starts_with(request()->getPathInfo(), $url.'/')) || ($url === '/portal' && str_starts_with(request()->getPathInfo(), '/portal/') && ! request()->is('portal/beranda', 'portal/laporan', 'portal/laporan/*', 'portal/riwayat-pembayaran')) || ($url === '/payments' && request()->is('portal/riwayat-pembayaran')) || ($url === '/admin/website' && request()->is('admin/content/*')) || ($url === '/admin/payments' && request()->is('admin/withdrawals', 'admin/withdrawals/*')) || ($url === '/admin/finance/events' && request()->is('admin/finance/*'))) aria-current="page" @endif><x-icon :name="str_starts_with($label, 'Notifikasi') ? 'bell' : match($label) { 'Konten' => 'news', 'Kegiatan' => 'calendar', 'Website' => 'settings', 'Audit' => 'history', 'Keuangan' => 'wallet', 'Aset' => 'box', 'Kegiatan saya' => 'calendar', 'Banjar', 'Ganti banjar', 'Data saya' => 'profile', 'Pencairan', 'Dana QRIS', 'Tagihan & QRIS' => 'wallet', 'Surat', 'Surat saya' => 'news', default => 'home' }" /><span>{{ $label }}</span></a>@endforeach</div></nav>@endif
<main id="main" class="page">
    @if(session('success'))<div class="notice" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="error-box" role="alert"><strong>Periksa kembali isian berikut.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
<footer class="footer">Banjar Digital <span>oleh PT Bali Santih Digital</span></footer>
</body></html>

