@extends('layouts.app')
@section('title', 'Verifikasi rekening')
@section('content')
<a class="back-link" href="/platform/withdrawals">← Permintaan pencairan</a>
<div class="page-heading"><div><span class="eyebrow">VERIFIKASI REKENING</span><h1>{{ $account->banjar_name }}</h1><span class="badge">{{ \App\Modules\Payment\BankAccountManager::STATUSES[$account->verification_status] }}</span></div></div>
<section class="card form-width letter-copy mb-6"><p>{{ $account->bank_name }}</p><p class="stat-number break-all">{{ $number }}</p><p class="mt-3">a.n. {{ $account->account_holder_name }}</p><p class="help mt-3">Cocokkan dengan dokumen kepemilikan rekening Banjar. Tampilan ini dicatat di audit.</p></section>
<form class="card form-stack form-width" method="post" action="/platform/bank-accounts/{{ $account->id }}/review">@csrf<h2>Keputusan</h2><label>Catatan<textarea name="note" maxlength="500" rows="3"></textarea><span class="help">Wajib diisi bila ditolak atau diblokir.</span></label>
@if($account->verification_status === 'PENDING')<button class="button" name="decision" value="VERIFY">Rekening sesuai, verifikasi</button><button class="button-secondary" name="decision" value="REJECT">Tolak</button>@else<button class="button-secondary" name="decision" value="DISABLE">Blokir rekening</button>@endif</form>
@endsection
