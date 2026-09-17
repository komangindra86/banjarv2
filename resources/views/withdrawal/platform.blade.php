@extends('layouts.app')
@section('title', 'Pencairan dana')
@section('content')
@php $money = fn ($v) => \App\Modules\Finance\FinanceManager::money($v); @endphp
<div class="page-heading"><div><span class="eyebrow">BALI SANTIH</span><h1>Permintaan pencairan</h1><p class="muted">Transfer dilakukan manual melalui bank. Sistem mencatat klaim, referensi, dan bukti.</p></div></div>
@if($accounts->isNotEmpty())<section class="card mb-6"><h2>Rekening menunggu verifikasi</h2><div class="grid gap-4 mt-4">@foreach($accounts as $a)<div class="flex flex-wrap items-center justify-between gap-3"><span><strong>{{ $a->banjar_name }}</strong><small class="block muted mt-1">{{ $a->bank_name }} {{ $a->account_number_masked }} · a.n. {{ $a->account_holder_name }}</small></span><form method="post" action="/platform/bank-accounts/{{ $a->id }}/reveal">@csrf<button class="button-secondary">Periksa →</button></form></div>@endforeach</div></section>@endif
<div class="grid gap-3">@forelse($withdrawals as $w)<a class="card flex flex-wrap items-center justify-between gap-3" href="/platform/withdrawals/{{ $w->id }}"><span><strong>{{ $w->banjar_name }}</strong><small class="block muted mt-2">{{ $w->bank_name }} {{ $w->account_number_masked }} · a.n. {{ $w->account_holder_name }}</small><small class="block muted mt-1">{{ \App\Modules\Payment\WithdrawalManager::STATUSES[$w->status] }}</small></span><strong>{{ $money($w->amount) }} →</strong></a>@empty<p class="card muted">Belum ada permintaan pencairan.</p>@endforelse</div>
<div class="mt-6">{{ $withdrawals->links() }}</div>
@endsection
