@extends('layouts.app')
@section('title', 'Notifikasi')
@section('content')
<div class="page-heading"><div><span class="eyebrow">KOTAK MASUK</span><h1>Notifikasi</h1><p class="muted">Pengumuman, kegiatan, tagihan, dan tindakan untuk Anda di Banjar ini.</p></div>
<form method="post" action="/notifications/read-all">@csrf<button class="button-secondary">Tandai semua dibaca</button></form>
</div>

@if($vapidPublicKey)
@include('notifications.push')
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
<div class="card empty-state"><h2>Belum ada notifikasi</h2><p class="muted">Pengumuman baru, kegiatan, tagihan, dan hal yang perlu Anda proses akan muncul di sini.</p></div>
@endforelse
</div>
<div class="mt-6">{{ $notifications->links() }}</div>
@endsection
