@extends('layouts.app')
@section('title', 'Tagihan & QRIS')
@section('content')
@if(app(\App\Platform\Identity\Access::class)->tenant(auth()->user(), 'payment.self'))<a href="/portal/beranda" class="back-link">← Beranda</a>@endif
<div class="page-heading"><div><span class="eyebrow">PEMBAYARAN BANJAR</span><h1>Tagihan & QRIS</h1><p class="muted">Bayar satu tagihan penuh dengan QRIS. Status diperiksa oleh sistem.</p></div>@if(app(\App\Platform\Identity\Access::class)->tenant(auth()->user(), 'krama.self'))<a class="button-secondary" href="/portal/riwayat-pembayaran">Riwayat pembayaran</a>@endif</div>
@if(!\App\Modules\Payment\PaymentManager::ready())<p class="notice">QRIS belum diaktifkan oleh Bali Santih.</p>@endif
<div class="grid gap-4 md:grid-cols-2">
@forelse($bills as $bill)
<section class="card"><div class="flex flex-wrap items-center justify-between gap-3"><span class="eyebrow">{{ $bill->household_code }} · #{{ $bill->id }}</span><span class="badge">{{ \App\Modules\Finance\FinanceManager::STATUSES[$bill->status] }}</span></div><h2 class="mt-4">{{ \App\Modules\Finance\FinanceManager::money($bill->gross_amount) }}</h2><p class="muted mt-2">{{ \App\Modules\Finance\FinanceManager::KINDS[$bill->kind] }}</p>
<p class="mt-2">{{ $events[$bill->billing_event_id] }}</p>
@php($attempts = $attemptsByBill->get($bill->id, collect()))
@foreach($attempts as $attempt)<a class="text-link mt-3 block" href="/payments/{{ $attempt->id }}">Pembayaran #{{ $attempt->id }} · {{ \App\Modules\Payment\PaymentManager::STATUSES[$attempt->status] }} →</a>@endforeach
@if($bill->status === 'UNPAID' && \App\Modules\Payment\PaymentManager::ready() && (app(\App\Platform\Identity\Access::class)->tenant(auth()->user(), 'payment.create') || app(\App\Platform\Identity\Access::class)->tenant(auth()->user(), 'payment.self')))<form class="mt-4" method="post" action="/payments/bills/{{ $bill->id }}">@csrf<p class="help mb-3">Lewat QRIS: tagihan {{ \App\Modules\Finance\FinanceManager::money($bill->gross_amount) }} + biaya layanan {{ \App\Modules\Finance\FinanceManager::money(\App\Modules\Payment\PaymentManager::serviceFee((int) $bill->gross_amount)) }} = <strong>{{ \App\Modules\Finance\FinanceManager::money($bill->gross_amount + \App\Modules\Payment\PaymentManager::serviceFee((int) $bill->gross_amount)) }}</strong>. Bayar tunai ke Petengen tanpa biaya.</p><button class="button">Bayar dengan QRIS</button></form>@endif</section>
@empty<div class="card"><h2>Belum ada tagihan</h2><p class="muted mt-2">Tagihan keluarga akan muncul setelah diterbitkan pengurus dan akun Anda terhubung ke data Krama.</p></div>@endforelse
</div><div class="mt-6">{{ $bills->links() }}</div>
@endsection
