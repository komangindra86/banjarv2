@extends('layouts.app')
@section('title', $item->title)
@section('content')
@php($expired = $item->expires_at && \Carbon\Carbon::parse($item->expires_at)->isPast())
<a href="/portal/pengumuman" class="back-link">← Pengumuman</a>
<article class="card form-width">
<span class="eyebrow">PENGUMUMAN @if($item->priority === 'IMPORTANT')· PENTING @endif</span>
<h1 class="mt-3 break-words">{{ $item->title }}</h1>
<p class="muted mt-2">{{ \Carbon\Carbon::parse($item->published_at)->timezone('Asia/Makassar')->translatedFormat('l, d F Y') }} · {{ $currentBanjar->name }}</p>
@if($expired)<p class="notice mt-4">Masa berlaku pengumuman ini telah berakhir.</p>@endif
@if($photo = $publicContent->image($item->media_id))<img class="portal-photo mt-5" src="{{ $photo }}" alt="{{ $item->title }}" width="960" height="600">@endif
@if($item->body)<div class="mt-5 whitespace-pre-line break-words">{{ $item->body }}</div>@endif
@if($photos->isNotEmpty())<div class="photo-grid mt-5">@foreach($photos as $mediaId)@if($image = $publicContent->image($mediaId))<a class="photo-tile" href="{{ $image }}"><img src="{{ $image }}" alt="{{ $item->title }} — foto {{ $loop->iteration }}" loading="lazy" width="240" height="240"></a>@endif @endforeach</div>@endif
@if($related)<a class="button-secondary mt-6" href="/aktivitas">Kegiatan terkait: {{ $related->title }} · {{ \Carbon\Carbon::parse($related->starts_at)->timezone('Asia/Makassar')->translatedFormat('d M Y · H:i') }} WITA</a>@endif
</article>
@endsection
