@extends('layouts.app')
@section('title', 'QR website')
@section('content')
<a href="/admin/website" class="back-link">← Konten website</a><div class="page-heading"><div><span class="eyebrow">BAGIKAN WEBSITE</span><h1>QR {{ $currentBanjar->name }}</h1><p class="muted">Pasang pada papan informasi, surat, atau undangan Banjar.</p></div></div>
<section class="card form-width form-stack"><canvas data-url="{{ $websiteUrl }}" aria-label="QR website {{ $currentBanjar->name }}" class="qr-canvas"></canvas><p class="break-words">{{ $websiteUrl }}</p><p role="status" class="muted qr-status">Menyiapkan QR…</p><a class="button qr-download" hidden download="qr-website.png">Unduh QR PNG</a><a class="button-secondary" href="{{ $websiteUrl }}">Buka website</a><noscript>Aktifkan JavaScript untuk menghasilkan QR.</noscript></section>
@endsection
