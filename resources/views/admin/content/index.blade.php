@extends('layouts.app')
@section('title', $definition['label'])
@section('content')
<a href="{{ $type === 'agenda' ? '/admin' : '/admin/website' }}" class="back-link">← Kembali</a>
<div class="page-heading"><div><span class="eyebrow">KELOLA INFORMASI</span><h1>{{ $definition['label'] }}</h1><p class="muted">{{ $definition['hint'] }}</p></div><a class="button" href="/admin/content/{{ $type }}/create">+ Tambah {{ strtolower($definition['label']) }}</a></div>
<form class="search-form" method="get"><label class="grow"><span class="sr-only">Cari judul</span><input name="q" value="{{ $search }}" placeholder="Cari judul…"></label><button class="button-secondary">Cari</button></form>
<div class="card-grid">@forelse($items as $item)
<article class="card"><span class="badge {{ $item->status !== 'PUBLISHED' ? 'badge-muted' : '' }}">{{ ['DRAFT'=>'Draf','PUBLISHED'=>'Terbit','ARCHIVED'=>'Diarsipkan'][$item->status] }}{{ $item->status === 'PUBLISHED' && $item->published_at > now() ? ' · Terjadwal' : '' }}</span>
<h2 class="mt-4 break-words">{{ $item->title }}</h2>
@if($type === 'agenda')<p class="muted mt-2">{{ \Carbon\Carbon::parse($item->starts_at)->timezone('Asia/Makassar')->translatedFormat('d M Y, H:i') }} WITA</p>@endif
<div class="card-bottom"><a class="touch-link" href="/admin/content/{{ $type }}/{{ $item->id }}/edit">Edit</a>@if($item->status === 'PUBLISHED' && $item->published_at <= now())<a class="touch-link" href="/{{ $type }}/{{ $item->slug }}">Lihat publik</a>@endif</div></article>
@empty<div class="card empty-state"><h2>Belum ada {{ strtolower($definition['label']) }}</h2><p class="muted">Mulai dengan menambahkan informasi pertama.</p></div>@endforelse</div>
<div class="mt-6">{{ $items->links() }}</div>
@endsection
