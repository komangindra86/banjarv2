@extends('layouts.app')
@section('title', 'Pembayaran QRIS #'.$payment->id)
@section('content')
@php($money = fn ($v) => \App\Modules\Finance\FinanceManager::money($v))
<div class="page-heading"><div><a class="text-link" href="/platform/payments">← Rekonsiliasi QRIS</a><h1 class="mt-3">Pembayaran #{{ $payment->id }}</h1><p class="muted">{{ $payment->banjar_name }} · Tagihan #{{ $payment->bill_id }} · {{ \App\Modules\Payment\PaymentManager::STATUSES[$payment->status] }}{{ $payment->blocked ? ' · ditahan untuk pemeriksaan' : '' }}</p></div></div>
<section class="card form-width mb-6">
<table class="data-table"><tbody>
<tr><td>Dibayar warga</td><td class="text-right">{{ $money($payment->gross_amount) }}</td></tr>
<tr><td>Untuk Banjar (nilai tagihan)</td><td class="text-right">{{ $money($payment->bill_amount ?? $payment->gross_amount) }}</td></tr>
<tr><td>Biaya layanan (platform)</td><td class="text-right">{{ $money($payment->service_fee) }}</td></tr>
<tr><td>Biaya iPaymu</td><td class="text-right">{{ $payment->gateway_fee === null ? 'belum diketahui' : $money($payment->gateway_fee) }}</td></tr>
<tr><td><strong>Pendapatan platform</strong></td><td class="text-right"><strong>{{ $money(max(0, (int) $payment->service_fee - (int) $payment->gateway_fee)) }}</strong></td></tr>
</tbody></table>
<p class="help mt-4 break-all">Referensi: {{ $payment->order_id }} · Transaksi iPaymu: {{ $payment->provider_transaction_id ?? '—' }}</p>
<p class="help mt-2">Masa tahan: {{ $payment->hold_days === null ? 'belum ditetapkan (dana tetap tertahan)' : $payment->hold_days.' hari' }}@if($payment->available_at) · tersedia {{ \Carbon\Carbon::parse($payment->available_at)->timezone('Asia/Makassar')->translatedFormat('d M Y, H:i') }} WITA @endif @if($payment->released_at)· sudah dapat dicairkan Banjar @endif</p>
</section>
@if($items->isNotEmpty())<section class="card form-width"><h2>Catatan pemeriksaan</h2>@foreach($items as $item)<p class="mt-3">{{ $item->code }} · {{ $item->status }} · {{ \Carbon\Carbon::parse($item->created_at)->timezone('Asia/Makassar')->translatedFormat('d M Y, H:i') }} WITA</p>@endforeach</section>@endif
@endsection
