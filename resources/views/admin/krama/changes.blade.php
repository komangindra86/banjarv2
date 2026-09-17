@extends('layouts.app')
@section('title', 'Pengajuan perubahan')
@section('content')
<h1>Pengajuan perubahan</h1>@include('admin.krama.nav')
<div class="card-grid">@forelse($rows as $row)<a class="card tenant-card" href="/admin/krama/changes/{{ $row->id }}"><span class="badge">@include('admin.krama.change-status', ['status' => $row->status])</span><h2>Pengajuan #{{ $row->id }}</h2><p class="muted">{{ \Carbon\Carbon::parse($row->requester_at)->timezone('Asia/Makassar')->format('d M Y H:i') }} WITA</p><div class="card-bottom">Lihat pengajuan <span>→</span></div></a>@empty<div class="card empty-state"><h2>Belum ada pengajuan</h2><p class="muted">Pengajuan yang menjadi tanggung jawab Anda akan muncul di sini.</p></div>@endforelse</div><div class="mt-6">{{ $rows->links() }}</div>
@endsection
