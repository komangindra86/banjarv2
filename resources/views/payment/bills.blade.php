@extends('layouts.app')
@section('title', 'Tagihan & QRIS')
@section('content')
@php($money = fn ($v) => \App\Modules\Finance\FinanceManager::money($v))
@php($tiers = \App\Modules\Payment\PaymentManager::feeTiers())
@php($above = \App\Modules\Payment\PaymentManager::feeAboveBasisPoints())
@php($live = fn ($bill) => $attemptsByBill->get($bill->id, collect())->first(fn ($a) => ! in_array($a->status, ['EXPIRED', 'DENIED', 'CANCELLED', 'PAID']) || ($a->blocked && $a->status !== 'PAID')))
@php($selectable = $canPay ? $bills->getCollection()->filter(fn ($bill) => $bill->status === 'UNPAID' && ! $live($bill)) : collect())
@php($selectedTotal = (int) $selectable->sum('gross_amount'))
@php($selectedFee = \App\Modules\Payment\PaymentManager::serviceFee($selectedTotal))
@if(app(\App\Platform\Identity\Access::class)->tenant(auth()->user(), 'payment.self'))<a href="/portal/beranda" class="back-link">← Beranda</a>@endif
<div class="page-heading"><div><span class="eyebrow">PEMBAYARAN BANJAR</span><h1>Tagihan & QRIS</h1><p class="muted">Centang tagihan yang ingin dibayar, lalu bayar sekaligus dengan satu QRIS. Bayar tunai ke Petengen tanpa biaya.</p></div>@if(app(\App\Platform\Identity\Access::class)->tenant(auth()->user(), 'krama.self'))<a class="button-secondary" href="/portal/riwayat-pembayaran">Riwayat pembayaran</a>@endif</div>
@if(!\App\Modules\Payment\PaymentManager::ready())<p class="notice">QRIS belum diaktifkan oleh Bali Santih.</p>@endif
@if($bills->isEmpty())
<div class="card"><h2>Belum ada tagihan</h2><p class="muted mt-2">Tagihan keluarga akan muncul setelah diterbitkan pengurus dan akun Anda terhubung ke data Krama.</p></div>
@else
<form method="post" action="/payments" data-qris-select data-fee-tiers="{{ json_encode($tiers) }}" data-fee-above="{{ $above }}">@csrf
@error('bill_ids')<p class="error-box mb-4">{{ $message }}</p>@enderror
<div class="table-scroll"><table class="data-table"><thead><tr><th>Tagihan</th><th class="text-right">Nominal</th><th>Status</th></tr></thead><tbody>
@foreach($bills as $bill)
@php($waiting = $live($bill))
@php($latest = $attemptsByBill->get($bill->id, collect())->first())
@php($title = $events[$bill->billing_event_id] ?? 'Tagihan')
<tr>
<td>@if($selectable->contains('id', $bill->id))<label class="check-option"><input type="checkbox" name="bill_ids[]" value="{{ $bill->id }}" data-amount="{{ $bill->gross_amount }}" checked><span>{{ $title }}<small class="block muted">{{ \App\Modules\Finance\FinanceManager::KINDS[$bill->kind] }} · {{ $bill->household_code }} · #{{ $bill->id }}</small></span></label>@else{{ $title }}<small class="block muted">{{ \App\Modules\Finance\FinanceManager::KINDS[$bill->kind] }} · {{ $bill->household_code }} · #{{ $bill->id }}</small>@endif</td>
<td class="text-right">{{ $money($bill->gross_amount) }}</td>
<td><span class="badge">{{ \App\Modules\Finance\FinanceManager::STATUSES[$bill->status] }}</span>@if($waiting)<a class="text-link mt-2 block" href="/payments/{{ $waiting->id }}">Menunggu QRIS #{{ $waiting->id }} →</a>@elseif($latest)<a class="text-link mt-2 block" href="/payments/{{ $latest->id }}">QRIS #{{ $latest->id }} · {{ \App\Modules\Payment\PaymentManager::STATUSES[$latest->status] }}</a>@endif</td>
</tr>
@endforeach
</tbody></table></div>
@if($selectable->isNotEmpty())
<section class="card form-width form-stack mt-4">
<div class="table-scroll"><table class="data-table"><tbody>
<tr><td>Tagihan dipilih (<span data-qris-count>{{ $selectable->count() }}</span>)</td><td class="text-right" data-qris-bills>{{ $money($selectedTotal) }}</td></tr>
<tr><td>Biaya layanan aplikasi</td><td class="text-right" data-qris-fee>{{ $money($selectedFee) }}</td></tr>
<tr><td><strong>Total dibayar</strong></td><td class="text-right"><strong data-qris-total>{{ $money($selectedTotal + $selectedFee) }}</strong></td></tr>
</tbody></table></div>
<p class="help">Biaya layanan aplikasi dikenakan sekali per pembayaran, jadi lebih hemat bila beberapa tagihan dibayar sekaligus. Banjar menerima nilai tagihan utuh. Bayar tunai ke Petengen tanpa biaya.</p>
<details><summary class="touch-link">Besaran biaya layanan aplikasi</summary><table class="data-table mt-3"><tbody>
@php($from = 0)
@foreach($tiers as [$limit, $fee])<tr><td>{{ $from ? $money($from + 1).' – ' : 'sampai ' }}{{ $money($limit) }}</td><td class="text-right">{{ $money($fee) }}</td></tr>@php($from = $limit) @endforeach
@if($above > 0)<tr><td>di atas {{ $money($from) }}</td><td class="text-right">{{ rtrim(rtrim(number_format($above / 100, 2, ',', '.'), '0'), ',') }}% dari total tagihan</td></tr>@endif
</tbody></table></details>
<button class="button" data-qris-submit>Bayar dengan QRIS</button>
</section>
@endif
</form>
@endif
<div class="mt-6">{{ $bills->links() }}</div>
@endsection
