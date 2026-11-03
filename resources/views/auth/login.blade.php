@extends('layouts.app')
@section('title', 'Masuk')
@section('body_class', 'login-page')
@section('content')
@php $platform = request()->attributes->get('platform'); $portal = request()->attributes->get('portal'); @endphp
<div class="login-shell">
<div class="login-grid">
    <section class="login-intro"><div class="login-scene" aria-hidden="true"><img src="{{ asset('images/login-bali.webp') }}" alt="" width="1774" height="887" fetchpriority="high" decoding="async"></div><span class="eyebrow">{{ $platform ? 'Bali Santih Digital' : ($portal ? 'Portal Krama' : 'Banjar Digital') }}</span><h1>Terhubung,<br> terlayani,<br> <em>bersama Banjar.</em></h1><p>Satu tempat untuk informasi dan pengelolaan Banjar, selangkah demi selangkah.</p><small>{{ $currentBanjar->name ?? 'PT Bali Santih Digital' }}</small></section>
    <section class="card login-card"><span class="login-watermark" aria-hidden="true">@include('auth.partials.jepun', ['id' => 'mark'])</span><div class="login-greeting"><span class="login-jepun" aria-hidden="true">@include('auth.partials.jepun', ['id' => 'head'])</span><span class="badge">{{ $platform ? 'SUPER ADMIN' : ($portal ? 'KRAMA BANJAR' : 'PENGURUS BANJAR') }}</span></div><h2>Om Swastiastu</h2><span class="login-divider" aria-hidden="true"></span><p class="muted">@if($portal)Rahajeng rauh. Masuk untuk melihat tagihan, kegiatan, dan layanan surat Banjar Anda. @else Rahajeng rauh. Masuk untuk mengelola {{ $currentBanjar->name ?? 'platform Bali Santih' }}. @endif</p>
    <form action="/login" method="post" class="form-stack">@csrf
        @if($platform)<label>Email<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus maxlength="255"></label>@else<label>{{ $portal ? 'NIK atau email' : 'Email atau NIK' }}<input name="email" type="text" value="{{ old('email') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus maxlength="255"><span class="help">Warga: masukkan NIK 16 digit.</span></label>@endif
        <label>Kata sandi<input name="password" type="password" autocomplete="current-password" required maxlength="255"></label>
        <button class="button" type="submit">{{ $portal ? 'Masuk ke portal' : 'Masuk ke dashboard' }} <span aria-hidden="true">→</span></button>
    </form>
    @php $current = auth($platform ? 'platform' : 'web')->user(); @endphp
    @if($current)<p class="help login-current">Anda sedang masuk sebagai <strong>{{ $current->name }}</strong>. <a class="text-link" href="{{ $platform ? '/platform' : ($portal ? '/portal/beranda' : '/admin') }}">{{ $portal ? 'Kembali ke portal →' : 'Kembali ke dashboard →' }}</a> Isi form di atas untuk masuk dengan akun lain.</p>@endif
    <p class="help">{{ $portal ? 'Satu akun untuk semua layanan Banjar. Hubungi pengurus Banjar Anda jika belum memiliki akun.' : 'Akses diberikan oleh pengelola. Hubungi Admin Banjar jika memerlukan bantuan akun.' }}</p></section>
</div>
</div>
@endsection
