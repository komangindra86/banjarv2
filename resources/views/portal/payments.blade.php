@extends('layouts.app')
@section('title', 'Riwayat pembayaran')
@section('content')
<a href="/portal/beranda" class="back-link">← Beranda</a>
<div class="page-heading"><div><span class="eyebrow">PEMBAYARAN SAYA</span><h1>Riwayat pembayaran</h1><p class="muted">Tagihan KK Anda yang sudah lunas, baik dibayar tunai ke Petengen maupun lewat QRIS.</p></div></div>
@if(!$self)
<div class="card empty-state"><h2>Akun belum terhubung</h2><p class="muted">Hubungi Admin Banjar agar akun dihubungkan ke data krama Anda.</p></div>
@else
@if($unpaid->count > 0)
<p class="notice">Masih ada {{ $unpaid->count }} tagihan belum lunas ({{ \App\Modules\Finance\FinanceManager::money($unpaid->total) }}). <a class="text-link" href="/payments">Lihat tagihan →</a></p>
@endif
@if($rows->isEmpty())
<div class="card empty-state"><h2>Belum ada pembayaran</h2><p class="muted">Tagihan yang sudah lunas akan tercatat di sini.</p></div>
@else
<p class="muted mb-4">{{ $rows->count() }} pembayaran · total {{ \App\Modules\Finance\FinanceManager::money($rows->sum('amount')) }}</p>
<div class="card history-list">
@foreach($rows as $row)
<div class="history-row"><div class="min-w-0"><strong class="break-words">{{ $row->title }}</strong><span class="help">{{ \Carbon\Carbon::parse($row->paid_at)->timezone('Asia/Makassar')->translatedFormat('d M Y') }} · {{ \App\Modules\Finance\FinanceManager::KINDS[$row->kind] }}@if($row->event_date) · ditetapkan {{ \Carbon\Carbon::parse($row->event_date)->translatedFormat('d M Y') }}@endif</span></div><div class="history-amount"><strong>{{ \App\Modules\Finance\FinanceManager::money($row->amount) }}</strong><span class="badge">{{ $row->method === 'GATEWAY' ? 'QRIS' : 'Tunai' }}</span></div></div>
@endforeach
</div>
@endif
@endif
@endsection
