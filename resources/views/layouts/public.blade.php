<!DOCTYPE html><html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $seoTitle }}{{ $seoTitle !== $currentBanjar->name ? ' · '.$currentBanjar->name : '' }}</title>
<meta name="description" content="{{ $seoDescription }}"><meta name="theme-color" content="{{ $settings->accent_color }}">
<link rel="canonical" href="{{ $canonical }}"><meta property="og:locale" content="id_ID"><meta property="og:type" content="{{ isset($item) ? 'article' : 'website' }}"><meta property="og:site_name" content="{{ $currentBanjar->name }}"><meta property="og:title" content="{{ $seoTitle }}"><meta property="og:description" content="{{ $seoDescription }}"><meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ $baseUrl.($seoImage ?? '/icons/icon-512.png') }}"><meta name="twitter:card" content="summary_large_image">
@php $siteLogo = $publicContent->image($profile->logo_id ?? null); @endphp
<link rel="manifest" href="/manifest.webmanifest"><link rel="icon" href="{{ $siteLogo ?? '/icons/icon-192.png' }}"><link rel="apple-touch-icon" href="{{ $siteLogo ?? '/icons/icon-192.png' }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head><body class="public-site {{ $settings->template_code ?? 'template-01' }}" style="--banjar-accent: {{ $settings->accent_color }}">

@php
    $bareName = trim(preg_replace('/^banjar(\s+adat)?\s+/i', '', $currentBanjar->name)) ?: $currentBanjar->name;
    $gate = app(\App\Platform\Modules\ModuleGate::class);
@endphp
<a class="skip-link" href="#main">Langsung ke isi</a>
<div class="public-strip"><div><span>Om Swastiastu</span><a href="/login">Masuk pengurus →</a></div></div>
<header class="public-header"><div class="public-header-inner">
<a class="brand" href="/" aria-label="{{ $currentBanjar->name }}, beranda">@if($siteLogo)<img src="{{ $siteLogo }}" alt="" class="public-logo">@else<span class="site-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($bareName, 0, 1)) }}</span>@endif<span class="brand-name">{{ $currentBanjar->name }}<small>Banjar Adat · Bali</small></span></a>
<nav aria-label="Navigasi publik" class="public-nav">@foreach(['/' => 'Beranda', '/pengumuman' => 'Kabar', '/agenda' => 'Agenda', '/layanan' => 'Layanan', '/profil' => 'Profil'] as $url => $label)@if($url !== '/agenda' || $gate->allows('ACTIVITY'))<a href="{{ $url }}" @if(request()->getPathInfo() === $url || ($url !== '/' && str_starts_with(request()->getPathInfo(), $url.'/'))) aria-current="page" @endif>{{ $label }}</a>@endif @endforeach</nav>
</div></header>
<main id="main" class="public-main">@yield('content')</main>
<footer class="public-footer"><div class="lattice" aria-hidden="true"></div>
<div class="footer-grid">
<div class="footer-brand"><strong>{{ $currentBanjar->name }}</strong><p>{{ $profile->tagline ?? 'Kabar, kegiatan, dan layanan Banjar dalam satu tempat.' }}</p></div>
<div><h2>Jelajahi</h2><nav aria-label="Informasi lainnya"><a href="/pengumuman">Pengumuman</a><a href="/berita">Berita & dokumentasi</a><a href="/galeri">Galeri</a><a href="/dokumen">Dokumen publik</a><a href="/layanan">Informasi layanan</a></nav></div>
<div class="footer-contact"><h2>Kontak</h2>@if($profile->address ?? null)<p>{{ $profile->address }}</p>@endif @if($profile->phone ?? null)<a href="tel:{{ preg_replace('/[^+0-9]/', '', $profile->phone) }}">{{ $profile->phone }}</a>@endif @if($profile->email ?? null)<a class="break-all" href="mailto:{{ $profile->email }}">{{ $profile->email }}</a>@endif <a href="/profil#kontak">Lokasi & kontak lengkap →</a></div>
</div>
<div class="footer-bottom"><span>Rahayu, Om Shanti Shanti Shanti Om</span><span>Banjar Digital oleh PT Bali Santih Digital</span></div>
</footer>
</body></html>
