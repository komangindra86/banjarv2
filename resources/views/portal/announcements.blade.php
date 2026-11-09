@extends('layouts.app')
@section('title', 'Pengumuman')
@section('content')
<a href="/portal/beranda" class="back-link">← Beranda</a>
<div class="page-heading"><div><span class="eyebrow">{{ $currentBanjar->name }}</span><h1>Pengumuman</h1><p class="muted">{{ \App\Modules\Website\ContentCatalog::TYPES['pengumuman']['lead'] }}</p></div></div>
@if($items->isEmpty())
<div class="card empty-state"><h2>Belum ada pengumuman</h2><p class="muted">Pengumuman yang diterbitkan pengurus akan tampil di sini.</p></div>
@else
<div class="card history-list">
@foreach($items as $news)
<a class="history-row" href="/portal/pengumuman/{{ $news->slug }}"><div class="min-w-0"><strong class="break-words">{{ $news->title }}</strong><span class="help">{{ \Carbon\Carbon::parse($news->published_at)->timezone('Asia/Makassar')->translatedFormat('d F Y') }}@if($news->body) · {{ \Illuminate\Support\Str::limit($news->body, 90) }}@endif</span></div>@if($news->priority === 'IMPORTANT')<span class="badge">Penting</span>@else<span aria-hidden="true">→</span>@endif</a>
@endforeach
</div>
<div class="mt-6">{{ $items->links() }}</div>
@endif
@endsection
