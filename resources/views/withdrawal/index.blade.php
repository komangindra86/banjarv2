@extends('layouts.app')
@section('title', 'Pencairan dana')
@section('content')
@php $money = fn ($v) => \App\Modules\Finance\FinanceManager::money($v); @endphp
<div class="page-heading"><div><span class="eyebrow">DANA QRIS</span><h1>Pencairan dana</h1><p class="muted">Dana dikirim manual oleh Bali Santih ke rekening Banjar yang terverifikasi.</p></div></div>
@include('withdrawal.nav')
<section class="card mb-6"><p class="eyebrow">SALDO TERSEDIA</p><p class="stat-number">{{ $money($available) }}</p><p class="muted mt-3">Dicadangkan {{ $money($reserved) }} · Sudah dicairkan {{ $money($paidOut) }}</p></section>
@if($canRequest)
@if(!$account)
<section class="card form-width mb-6"><h2>Rekening belum siap</h2><p class="muted mt-2">{{ $pendingAccount ? 'Rekening '.$pendingAccount->account_number_masked.' sedang diverifikasi Bali Santih.' : 'Daftarkan rekening Banjar terlebih dahulu. Pencairan hanya dapat dikirim ke rekening terverifikasi.' }}</p><a class="button-secondary mt-4" href="/admin/withdrawals/accounts">Buka rekening Banjar →</a></section>
@elseif($available > 0)
<form class="card form-stack form-width mb-6" method="post" action="/admin/withdrawals">@csrf<input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">
<h2>Ajukan pencairan</h2>
<div><p class="help">Dikirim ke</p><p><strong>{{ $account->bank_name }}</strong> {{ $account->account_number_masked }}</p><p class="muted">a.n. {{ $account->account_holder_name }}</p></div>
<label>Nominal (Rp)<input name="amount" inputmode="numeric" pattern="[1-9][0-9]{0,11}" required value="{{ old('amount', $available) }}"><span class="help">Maksimal {{ $money($available) }}. Rupiah bulat, tanpa titik.</span></label>
<p class="help">{{ $requiresApproval ? 'Permintaan perlu disetujui Kelian sebelum diproses Bali Santih.' : 'Permintaan langsung diteruskan ke Bali Santih.' }} Saldo langsung dicadangkan saat diajukan.</p>
<button class="button">Ajukan pencairan</button></form>
@else
<p class="notice">Belum ada saldo tersedia untuk dicairkan. Dana QRIS tersedia setelah settlement diverifikasi dan masa tunggu selesai.</p>
@endif
@endif
<h2 class="mb-4">Riwayat permintaan</h2>
<div class="grid gap-3">@forelse($withdrawals as $w)<a class="card flex flex-wrap items-center justify-between gap-3" href="/admin/withdrawals/{{ $w->id }}"><span><strong>{{ $money($w->amount) }}</strong><small class="block muted mt-2">{{ \App\Modules\Payment\WithdrawalManager::STATUSES[$w->status] }} · {{ \Carbon\Carbon::parse($w->created_at)->timezone('Asia/Makassar')->format('d/m/Y') }}</small></span><span class="badge">{{ $w->status === 'APPROVAL_PENDING' && $canApprove ? 'Perlu persetujuan' : $w->account_number_masked }}</span></a>@empty<p class="card muted">Belum ada permintaan pencairan.</p>@endforelse</div>
<div class="my-5">{{ $withdrawals->links() }}</div>
@if($canConfigure)<section class="card form-width mt-6"><h2>Persetujuan Kelian</h2><form class="form-stack mt-4" method="post" action="/admin/withdrawals/policy">@csrf @method('PATCH')<label>Sebelum diteruskan ke Bali Santih<select name="payout_requires_kelian_approval"><option value="0" @selected(!$requiresApproval)>Tidak perlu persetujuan Kelian</option><option value="1" @selected($requiresApproval)>Perlu persetujuan Kelian</option></select></label><p class="help">Berlaku untuk permintaan baru.</p><button class="button-secondary">Simpan aturan</button></form></section>@endif
@endsection
