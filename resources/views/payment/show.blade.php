@extends('layouts.app')
@section('title', 'Pembayaran QRIS')
@section('content')
<div class="page-heading"><div><a class="text-link" href="/payments">← Tagihan & QRIS</a><h1 class="mt-3">Pembayaran QRIS</h1><p class="muted">Tagihan #{{ $payment->bill_id }}</p></div></div>
<section class="card form-width form-stack">
<span class="badge">{{ ['CREATING'=>'Menyiapkan QRIS', 'UNKNOWN'=>'Sedang diperiksa', 'PENDING'=>'Menunggu pembayaran', 'PAID'=>'Pembayaran diterima', 'EXPIRED'=>'QRIS kedaluwarsa', 'DENIED'=>'Pembayaran ditolak', 'CANCELLED'=>'Dibatalkan', 'EXCEPTION'=>'Perlu pemeriksaan pengurus'][$payment->status] }}</span>
<h2>{{ \App\Modules\Finance\FinanceManager::money($payment->gross_amount) }}</h2>
@if(!$payment->production)<p class="notice">Mode sandbox · khusus pengujian, tanpa pembayaran nyata.</p>@endif
@if($payment->blocked)<p class="error-box">Transaksi sedang diperiksa Bali Santih. Hubungi pengurus dan sertakan nomor pembayaran #{{ $payment->id }}.</p>
@elseif($payment->status === 'PENDING' && \App\Modules\Payment\PaymentManager::safeQrUrl($payment->qr_url, (bool)$payment->production))
<img class="mx-auto w-full max-w-72" src="{{ $payment->qr_url }}" alt="Kode QRIS untuk tagihan {{ $payment->bill_id }}" referrerpolicy="no-referrer"><p class="help">Pindai QR ini dari aplikasi pembayaran Anda. Setelah membayar, tekan Periksa status.</p>
@elseif(in_array($payment->status, ['CREATING','UNKNOWN','PENDING']))<p class="notice">Sistem sedang memeriksa transaksi. Jangan membuat pembayaran lain untuk tagihan ini.</p>
@elseif($payment->status === 'PAID')<p class="notice">Pembayaran telah diverifikasi. Tagihan Anda sudah lunas.</p>
@elseif(in_array($payment->status, ['EXPIRED','DENIED','CANCELLED']))<a class="button" href="/payments">Kembali untuk membuat QRIS baru</a>@endif
<p class="help break-all">Referensi: {{ $payment->order_id }}</p>
<form method="post" action="/payments/{{ $payment->id }}/refresh">@csrf<button class="button-secondary">Periksa status</button></form>
</section>
@endsection
