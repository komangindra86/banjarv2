@extends('layouts.app')
@section('title', 'Kelola Banjar')
@section('content')
<div class="page-heading"><div><span class="eyebrow">BALI SANTIH DIGITAL</span><h1>Banjar dalam satu tempat.</h1><p class="muted">Kelola akses, domain, dan fitur sesuai kebutuhan setiap Banjar.</p></div><a class="button" href="/platform/banjars/create">+ Tambah Banjar</a></div>
<form class="search-form" method="get"><label class="sr-only" for="search">Cari nama Banjar</label><input id="search" name="q" value="{{ $search }}" placeholder="Cari nama Banjar…" maxlength="100"><button class="button-secondary">Cari</button></form>
<div class="section-label"><h2>Daftar Banjar</h2><span>{{ $banjars->total() }} Banjar</span></div>
<div class="card-grid">@forelse($banjars as $banjar)<a class="card tenant-card" href="/platform/banjars/{{ $banjar->id }}"><div class="flex items-center justify-between gap-3"><span class="tenant-initial" aria-hidden="true">{{ mb_substr($banjar->name, 0, 1) }}</span><span class="badge {{ $banjar->status !== 'ACTIVE' ? 'badge-muted' : '' }}">{{ $banjar->status }}</span></div><h2>{{ $banjar->name }}</h2><p class="muted break-all">{{ $banjar->domain }}</p><div class="card-bottom">Kelola Banjar <span aria-hidden="true">↗</span></div></a>@empty<section class="card empty-state"><h2>Mulai dari Banjar pertama</h2><p>Tambahkan Banjar dan akun pengurus untuk memulai.</p></section>@endforelse</div>
<div class="mt-8">{{ $banjars->links() }}</div>
@endsection
