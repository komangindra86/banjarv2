@extends('layouts.public')
@section('content')
<header class="public-page-heading"><span class="eyebrow">{{ $currentBanjar->name }}</span><h1>{{ $archive ? 'Riwayat pengumuman' : $definition['label'] }}</h1><x-ornament /><p>{{ $definition['lead'] }}</p>@if($type === 'pengumuman')<div class="inline-links"><a href="/pengumuman" @if(!$archive) aria-current="page" @endif>Pengumuman aktif</a><a href="/pengumuman?arsip=1" @if($archive) aria-current="page" @endif>Riwayat</a><a href="/berita">Berita Banjar</a></div>@endif</header>
<div class="public-grid">@forelse($items as $entry)@include('public.card', ['entryType'=>$type])@empty<div class="public-empty"><h2>Belum ada {{ strtolower($definition['label']) }}{{ $archive ? ' dalam riwayat' : '' }}</h2><p>Informasi yang sudah diterbitkan pengurus akan tersedia di sini.</p><a href="/" class="public-read">Kembali ke beranda <span aria-hidden="true">→</span></a></div>@endforelse</div><div class="mt-14">{{ $items->links() }}</div>
@endsection
