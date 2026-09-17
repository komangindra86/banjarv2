@extends('layouts.app')
@section('title', 'Stock opname')
@section('content')
<div class="page-heading"><div><span class="eyebrow">ASET BANJAR</span><h1>Stock opname</h1><p class="muted">Hitung fisik aset dan sesuaikan jumlah bila berbeda.</p></div>@if($canManage)<a class="button" href="/admin/assets/opname/create">Buka opname baru</a>@endif</div>
@include('asset.nav')
<div class="grid gap-4">@forelse($opnames as $opname)<a class="card letter-copy" href="/admin/assets/opname/{{ $opname->id }}"><div class="flex flex-wrap gap-3 justify-between"><h2>{{ $opname->location_id ? ($locations[$opname->location_id]->name ?? '-') : 'Semua lokasi' }}</h2><span class="badge">{{ $opname->status === 'COMPLETED' ? 'Selesai' : 'Draf' }}</span></div><p class="muted mt-2">Dibuka {{ \Carbon\Carbon::parse($opname->created_at)->timezone('Asia/Makassar')->translatedFormat('d M Y, H:i') }} WITA</p></a>@empty<div class="card empty-state"><h2>Belum ada stock opname</h2></div>@endforelse</div>
<div class="mt-6">{{ $opnames->links() }}</div>
@endsection
