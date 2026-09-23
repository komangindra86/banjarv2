@extends('layouts.app')
@section('title', 'Event tagihan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">KEUANGAN</span><h1>Event tagihan</h1><p class="muted">Iuran, patus, dan dedosan yang sudah ditetapkan beserta langkah berikutnya.</p></div>@if($canIssue || $canDedosan)<a class="button" href="/admin/finance/settings">+ Tetapkan tagihan</a>@endif</div>
@include('finance.nav')
<h2 class="mb-2">Siap diterbitkan ({{ $ready->count() }})</h2>
<p class="muted mb-4">Sudah disetujui atau tidak perlu persetujuan. Buka, periksa, lalu klik "Sudah diperiksa, terbitkan" agar menjadi tagihan warga.</p>
@include('finance.event-list', ['events' => $ready, 'empty' => 'Tidak ada event yang menunggu diterbitkan.'])
<h2 class="mb-2 mt-10">Menunggu persetujuan Kelian ({{ $waiting->count() }})</h2>
<p class="muted mb-4">Dedosan yang perlu diperiksa Kelian sebelum bisa diterbitkan.</p>
@include('finance.event-list', ['events' => $waiting, 'empty' => 'Tidak ada yang menunggu persetujuan.'])
<h2 class="mb-4 mt-10">Riwayat</h2>
@include('finance.event-list', ['events' => $history, 'empty' => 'Belum ada event yang terbit, ditolak, atau dibatalkan.'])
<div class="mt-6">{{ $history->links() }}</div>
@endsection
