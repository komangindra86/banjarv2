@extends('layouts.app')
@section('title', 'QR website')
@section('content')
<a href="/admin/website" class="back-link">← Konten website</a><div class="page-heading"><div><span class="eyebrow">BAGIKAN WEBSITE</span><h1>QR {{ $currentBanjar->name }}</h1><p class="muted">Pasang pada papan informasi, surat, atau undangan Banjar.</p></div></div>
<section class="card form-width form-stack"><canvas id="website-qr" data-url="{{ $websiteUrl }}" aria-label="QR website {{ $currentBanjar->name }}" class="qr-canvas"></canvas><p class="break-words">{{ $websiteUrl }}</p><p id="qr-status" role="status" class="muted">Menyiapkan QR…</p><a id="qr-download" class="button" hidden download="qr-website.png">Unduh QR PNG</a><a class="button-secondary" href="{{ $websiteUrl }}">Buka website</a><noscript>Aktifkan JavaScript untuk menghasilkan QR.</noscript></section>
@endsection
