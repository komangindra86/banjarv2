@extends('layouts.app')
@section('title', 'Masuk')
@section('body_class', 'login-page')
@section('content')
@php $platform = request()->attributes->get('platform'); @endphp
<div class="login-shell">
<div class="login-grid">
    <section class="login-intro"><span class="eyebrow">{{ $platform ? 'Bali Santih Digital' : 'Banjar Digital' }}</span><h1>Terhubung,<br> terlayani,<br> <em>bersama Banjar.</em></h1><p>Satu tempat untuk informasi dan pengelolaan Banjar, selangkah demi selangkah.</p><small>{{ $currentBanjar->name ?? 'PT Bali Santih Digital' }}</small></section>
    <section class="card login-card"><span class="badge">{{ $platform ? 'SUPER ADMIN' : 'PENGURUS BANJAR' }}</span><h2>Om Swastiastu</h2><p class="muted">Masuk untuk mengelola {{ $currentBanjar->name ?? 'platform Bali Santih' }}.</p>
    <form action="/login" method="post" class="form-stack">@csrf
        <label>Email<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus maxlength="255"></label>
        <label>Kata sandi<input name="password" type="password" autocomplete="current-password" required maxlength="255"></label>
        <button class="button" type="submit">Masuk ke dashboard <span aria-hidden="true">→</span></button>
    </form><p class="help">Akses diberikan oleh pengelola. Hubungi Admin Banjar jika memerlukan bantuan akun.</p></section>
</div>
</div>
@endsection
