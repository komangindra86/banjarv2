@extends('layouts.app')
@section('title', 'Pembayaran QRIS')
@section('content')
@php($money = fn ($v) => \App\Modules\Finance\FinanceManager::money($v))
<div class="page-heading"><div><a class="text-link" href="/payments">← Tagihan & QRIS</a><h1 class="mt-3">Pembayaran QRIS</h1><p class="muted">Tagihan #{{ $payment->bill_id }}</p></div></div>
<section class="card form-width form-stack">
<span class="badge">{{ \App\Modules\Payment\PaymentManager::STATUSES[$payment->status] }}</span>
<table class="data-table"><tbody>
<tr><td>Nilai tagihan</td><td class="text-right">{{ $money($payment->bill_amount ?? $payment->gross_amount) }}</td></tr>
@if((int) $payment->service_fee > 0)<tr><td>Biaya layanan QRIS</td><td class="text-right">{{ $money($payment->service_fee) }}</td></tr>@endif
<tr><td><strong>Total dibayar</strong></td><td class="text-right"><strong>{{ $money($payment->gross_amount) }}</strong></td></tr>
</tbody></table>
@if(!$payment->production)<p class="notice">Mode sandbox · khusus pengujian, tanpa pembayaran nyata.</p>@endif
@if($payment->blocked)<p class="error-box">Transaksi sedang diperiksa Bali Santih. Hubungi pengurus dan sertakan nomor pembayaran #{{ $payment->id }}.</p>
@elseif($payment->status === 'PENDING' && $payment->qr_string)
<canvas class="mx-auto block w-full max-w-72" data-qris="{{ $payment->qr_string }}" role="img" aria-label="Kode QRIS untuk tagihan {{ $payment->bill_id }}"></canvas>
<p class="help">Pindai QR ini dari aplikasi bank atau dompet digital Anda, lalu tekan Periksa status setelah membayar.</p>
@elseif(in_array($payment->status, ['CREATING','UNKNOWN','PENDING']))<p class="notice">Sistem sedang memeriksa transaksi. Jangan membuat pembayaran lain untuk tagihan ini.</p>
@elseif($payment->status === 'PAID')<p class="notice">Pembayaran telah diverifikasi. Tagihan Anda sudah lunas.</p>
@elseif(in_array($payment->status, ['EXPIRED','DENIED','CANCELLED']))<a class="button" href="/payments">Kembali untuk membuat QRIS baru</a>@endif
<p class="help break-all">Referensi: {{ $payment->order_id }}</p>
<form method="post" action="/payments/{{ $payment->id }}/refresh">@csrf<button class="button-secondary">Periksa status</button></form>
</section>
@endsection
