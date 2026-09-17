@extends('layouts.app')
@section('title', 'Riwayat aktivitas')
@section('content')
<div class="page-heading"><div><span class="eyebrow">CATATAN PENGELOLAAN</span><h1>Riwayat aktivitas</h1><p class="muted">Perubahan penting tercatat beserta pelaku dan waktunya.</p></div></div>
<div class="audit-list">@forelse($logs as $log)<article class="card"><div class="flex flex-wrap items-center justify-between gap-2"><h2 class="break-all">{{ $log->action }}</h2><time class="muted">{{ $log->created_at }} UTC</time></div><p class="muted mt-2">{{ $log->entity_type }} #{{ $log->entity_id }} · Pengguna #{{ $log->actor_id ?? 'Sistem' }} @if(request()->attributes->get('platform')) · Banjar #{{ $log->banjar_id ?? 'Platform' }} @endif</p></article>@empty<section class="card empty-state"><h2>Belum ada aktivitas</h2><p>Riwayat akan muncul setelah ada perubahan.</p></section>@endforelse</div><div class="mt-8">{{ $logs->links() }}</div>
@endsection
