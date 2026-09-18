@extends('layouts.app')
@section('title', 'Notifikasi')
@section('content')
<div class="page-heading"><div><span class="eyebrow">KOTAK MASUK</span><h1>Notifikasi</h1><p class="muted">Tindakan yang menunggu Anda di Banjar ini.</p></div>
<form method="post" action="/notifications/read-all">@csrf<button class="button-secondary">Tandai semua dibaca</button></form>
</div>

@if($vapidPublicKey)
<section class="card form-width mb-6">
<h2>Notifikasi push</h2>
<p class="muted mt-2" id="push-status">Memeriksa status…</p>
<button type="button" class="button-secondary mt-4" id="push-toggle" data-vapid-key="{{ $vapidPublicKey }}" hidden>Aktifkan notifikasi push</button>
<noscript><p class="help">Aktifkan JavaScript untuk mengatur notifikasi push.</p></noscript>
</section>
@endif

<div class="grid gap-3">
@forelse($notifications as $item)
<form method="post" action="/notifications/{{ $item->id }}/read">@csrf
<button type="submit" class="card letter-copy w-full text-left {{ $item->read_at ? '' : 'notice' }}">
<div class="flex flex-wrap items-center justify-between gap-2"><h2>{{ $item->title }}</h2>@unless($item->read_at)<span class="badge">Baru</span>@endunless</div>
<p class="mt-2">{{ $item->body }}</p>
<p class="muted mt-2">{{ \Carbon\Carbon::parse($item->created_at)->timezone('Asia/Makassar')->translatedFormat('d M Y, H:i') }} WITA</p>
</button>
</form>
@empty
<div class="card empty-state"><h2>Belum ada notifikasi</h2><p class="muted">Pemberitahuan tindakan yang perlu Anda proses akan muncul di sini.</p></div>
@endforelse
</div>
<div class="mt-6">{{ $notifications->links() }}</div>
@endsection
