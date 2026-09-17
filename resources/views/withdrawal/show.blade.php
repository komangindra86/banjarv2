@extends('layouts.app')
@section('title', 'Detail pencairan')
@section('content')
<a class="back-link" href="/admin/withdrawals">← Pencairan dana</a>
<div class="page-heading"><div><span class="eyebrow break-all">{{ $withdrawal->reference }}</span><h1>{{ \App\Modules\Finance\FinanceManager::money($withdrawal->amount) }}</h1><span class="badge">{{ \App\Modules\Payment\WithdrawalManager::STATUSES[$withdrawal->status] }}</span></div></div>
<div class="grid gap-5 lg:grid-cols-2"><div class="grid gap-5 self-start">
<section class="card letter-copy"><h2>Rekening tujuan</h2><p class="mt-3"><strong>{{ $withdrawal->bank_name }}</strong> {{ $withdrawal->account_number_masked }}</p><p class="muted">a.n. {{ $withdrawal->account_holder_name }}</p><p class="help mt-3">Tujuan dikunci saat permintaan dibuat.</p>
@if($withdrawal->status === 'PAID')<p class="mt-4">Ditransfer {{ \Carbon\Carbon::parse($withdrawal->transferred_at)->timezone('Asia/Makassar')->format('d M Y H:i') }} WITA</p><a class="button-secondary mt-4" href="/admin/withdrawals/{{ $withdrawal->id }}/proof">Unduh bukti transfer</a>@endif
@if($withdrawal->closing_note && in_array($withdrawal->status, ['FAILED','CANCELLED']))<p class="notice mt-4">Saldo telah dikembalikan ke saldo tersedia.</p>@endif</section>
@if($withdrawal->status === 'APPROVAL_PENDING' && $canApprove && (int)$withdrawal->requested_by !== auth()->id())
<section class="card"><h2>Persetujuan Kelian</h2><p class="muted mt-2">Pastikan nominal dan rekening sesuai keputusan Banjar.</p><form class="form-stack mt-5" method="post" action="/admin/withdrawals/{{ $withdrawal->id }}/approve">@csrf<label>Catatan<textarea name="note" maxlength="500" rows="3"></textarea><span class="help">Wajib diisi bila ditolak.</span></label><button class="button" name="decision" value="APPROVE">Setujui pencairan</button><button class="button-secondary" name="decision" value="REJECT">Tolak</button></form></section>
@endif
@if(in_array($withdrawal->status, ['APPROVAL_PENDING','REQUESTED']) && $canRequest)
<details class="card"><summary class="cursor-pointer font-semibold py-2">Batalkan permintaan</summary><form class="form-stack mt-4" method="post" action="/admin/withdrawals/{{ $withdrawal->id }}/cancel">@csrf<label>Alasan<textarea name="note" required minlength="5" maxlength="500" rows="3"></textarea></label><button class="button-secondary">Ya, batalkan dan kembalikan saldo</button></form></details>
@elseif($withdrawal->status === 'PROCESSING')<p class="notice">Bali Santih sedang memproses transfer. Permintaan tidak dapat dibatalkan dari Banjar.</p>@endif
</div>@include('withdrawal.steps')</div>
@endsection
