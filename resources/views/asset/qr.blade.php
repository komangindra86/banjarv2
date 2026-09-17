@extends('layouts.app')
@section('title', 'QR '.$asset->name)
@section('content')
<a href="/admin/assets/{{ $asset->id }}" class="back-link">← {{ $asset->name }}</a>
<div class="page-heading"><div><span class="eyebrow">LABEL ASET</span><h1>QR {{ $asset->name }}</h1><p class="muted">Cetak dan tempel pada barang untuk memudahkan stock opname.</p></div></div>
<section class="card form-width form-stack"><canvas data-url="{{ $assetUrl }}" aria-label="QR {{ $asset->name }}" class="qr-canvas"></canvas><p class="muted">{{ $asset->code }}</p><p role="status" class="muted qr-status">Menyiapkan QR…</p><a class="button qr-download" hidden download="qr-{{ $asset->code }}.png">Unduh QR PNG</a><noscript>Aktifkan JavaScript untuk menghasilkan QR.</noscript></section>
@endsection
