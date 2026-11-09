@extends('layouts.app')
@section('title', 'Rekonsiliasi QRIS')
@section('content')
<div class="page-heading"><div><span class="eyebrow">BALI SANTIH</span><h1>Rekonsiliasi QRIS</h1><p class="muted">Pembayaran QRIS lewat iPaymu. Nilai tagihan milik Banjar (ditahan {{ config('payment.settlement_hold_days') ?? '—' }} hari lalu otomatis tersedia); biaya layanan aplikasi bertingkat dari warga menjadi pendapatan platform setelah dikurangi biaya iPaymu.</p></div></div>
<div class="grid gap-4 sm:grid-cols-2 mb-6"><section class="card"><p class="eyebrow">Pendapatan layanan aplikasi</p><h2 class="mt-3">{{ \App\Modules\Finance\FinanceManager::money((int) ($totals['PLATFORM'] ?? 0)) }}</h2><p class="help mt-2">Biaya layanan dari warga setelah dikurangi biaya iPaymu.</p></section><section class="card"><p class="eyebrow">Biaya iPaymu</p><h2 class="mt-3">{{ \App\Modules\Finance\FinanceManager::money((int) ($totals['FEE'] ?? 0)) }}</h2></section></div>
<div class="notice">
@if($run)
Pemeriksaan terakhir: {{ \Carbon\Carbon::parse($run->started_at)->timezone('Asia/Makassar')->translatedFormat('d M Y, H:i') }} WITA · {{ $run->checked }} diperiksa · {{ $run->failed }} perlu dicoba lagi. @if(!$run->finished_at)Proses belum selesai.@endif
@else
Belum ada rekonsiliasi terjadwal.
@endif
</div>
@if($items->isNotEmpty())<section class="card mb-6"><h2>Perlu ditinjau</h2>@foreach($items as $item)<p class="mt-3"><a class="text-link" href="/platform/payments/{{ $item->payment_id }}">Pembayaran #{{ $item->payment_id }}</a> · {{ $item->code }}</p>@endforeach<p class="help mt-3">Dana terkait ditahan. Resolusi refund atau koreksi membutuhkan pemeriksaan dan prosedur terpisah.</p></section>@endif
<div class="grid gap-4">@forelse($payments as $payment)<a class="card flex flex-wrap items-center justify-between gap-3" href="/platform/payments/{{ $payment->id }}"><span><strong>{{ $payment->banjar_name }}</strong><small class="block muted mt-2">#{{ $payment->id }} · {{ \App\Modules\Payment\PaymentManager::STATUSES[$payment->status] }}{{ $payment->blocked ? ' · Ditahan' : '' }}</small></span><span>{{ \App\Modules\Finance\FinanceManager::money($payment->gross_amount) }} →</span></a>@empty<p class="card muted">Belum ada pembayaran QRIS.</p>@endforelse</div><div class="mt-6">{{ $payments->links() }}</div>
@endsection
