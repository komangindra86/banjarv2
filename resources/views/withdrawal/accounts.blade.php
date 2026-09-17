@extends('layouts.app')
@section('title', 'Rekening Banjar')
@section('content')
<div class="page-heading"><div><span class="eyebrow">DANA QRIS</span><h1>Rekening Banjar</h1><p class="muted">Pencairan hanya dikirim ke rekening utama yang telah diverifikasi Bali Santih.</p></div></div>
@include('withdrawal.nav')
<div class="grid gap-3 mb-6">@forelse($accounts as $a)<section class="card flex flex-wrap items-center justify-between gap-3"><span><strong>{{ $a->bank_name }}</strong> {{ $a->account_number_masked }}<small class="block muted mt-2">a.n. {{ $a->account_holder_name }}</small></span><span class="badge">{{ $a->is_primary ? 'Utama · ' : '' }}{{ \App\Modules\Payment\BankAccountManager::STATUSES[$a->verification_status] }}</span></section>@empty<p class="card muted">Belum ada rekening terdaftar.</p>@endforelse</div>
@if($canSubmit)
<details class="card form-width" @if($accounts->isEmpty() || $errors->any()) open @endif><summary class="cursor-pointer font-semibold py-3">{{ $accounts->isEmpty() ? 'Daftarkan rekening' : 'Ajukan rekening baru' }}</summary>
<form class="form-stack mt-4" method="post" action="/admin/withdrawals/accounts">@csrf
<label>Bank<select name="bank_code">@foreach(\App\Modules\Payment\BankAccountManager::BANKS as $code => $name)<option value="{{ $code }}" @selected(old('bank_code') === (string)$code)>{{ $name }}</option>@endforeach</select></label>
<label>Nama bank (jika lainnya)<input name="bank_name" maxlength="100" value="{{ old('bank_name') }}"></label>
<label>Nomor rekening<input name="account_number" inputmode="numeric" autocomplete="off" required maxlength="30"></label>
<label>Nama pemilik rekening<input name="account_holder_name" required maxlength="150" value="{{ old('account_holder_name') }}"><span class="help">Sesuai buku rekening, sebaiknya atas nama Banjar.</span></label>
<p class="help">Rekening baru aktif setelah diverifikasi. Permintaan pencairan yang sudah dibuat tetap memakai rekening lama.</p>
<button class="button">Ajukan untuk verifikasi</button></form></details>
@endif
@endsection
