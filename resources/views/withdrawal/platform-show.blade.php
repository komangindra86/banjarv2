@extends('layouts.app')
@section('title', 'Proses pencairan')
@section('content')
@php $mine = (int) $withdrawal->claimed_by === auth('platform')->id(); @endphp
<a class="back-link" href="/platform/withdrawals">← Permintaan pencairan</a>
<div class="page-heading"><div><span class="eyebrow break-all">{{ $withdrawal->reference }}</span><h1>{{ \App\Modules\Finance\FinanceManager::money($withdrawal->amount) }}</h1><p class="muted">{{ $withdrawal->banjar_name }}</p><span class="badge">{{ \App\Modules\Payment\WithdrawalManager::STATUSES[$withdrawal->status] }}</span></div></div>
@if($problems)<div class="error-box" role="alert"><strong>Ledger Banjar ini tidak konsisten.</strong> Jangan transfer sebelum diperiksa: {{ implode(', ', $problems) }}</div>@endif
<div class="grid gap-5 lg:grid-cols-2"><div class="grid gap-5 self-start">
<section class="card letter-copy"><h2>Rekening tujuan</h2><p class="mt-3"><strong>{{ $withdrawal->bank_name }}</strong> {{ $revealed ?? $withdrawal->account_number_masked }}</p><p>a.n. {{ $withdrawal->account_holder_name }}</p>
@if($account && $account->verification_status === 'DISABLED')<p class="error-box mt-4">Rekening ini diblokir. Jangan transfer; batalkan permintaan.</p>@elseif($account && $account->verification_status === 'VERIFIED')<form class="mt-3" method="post" action="/platform/bank-accounts/{{ $account->id }}/reveal">@csrf<button class="text-link">Tinjau atau blokir rekening →</button></form>@endif
@if($withdrawal->status === 'PROCESSING' && $mine && !$revealed)<form class="mt-4" method="post" action="/platform/withdrawals/{{ $withdrawal->id }}/reveal">@csrf<button class="button-secondary">Tampilkan nomor lengkap</button><p class="help mt-2">Setiap tampilan dicatat di audit.</p></form>@endif
@if($withdrawal->status === 'PAID')<a class="button-secondary mt-4" href="/platform/withdrawals/{{ $withdrawal->id }}/proof">Unduh bukti transfer</a>@endif
</section>
@if($withdrawal->status === 'REQUESTED')
<form class="card" method="post" action="/platform/withdrawals/{{ $withdrawal->id }}/claim">@csrf<h2>Ambil untuk ditransfer</h2><p class="muted mt-2 mb-4">Hanya satu operator dapat memproses. Setelah diambil, Banjar tidak dapat membatalkan.</p><button class="button">Proses pencairan</button></form>
@elseif($withdrawal->status === 'PROCESSING' && !$mine)
<p class="notice">Sedang diproses operator lain. Hindari transfer ganda.</p>
@elseif($withdrawal->status === 'PROCESSING')
<form class="card form-stack" method="post" enctype="multipart/form-data" action="/platform/withdrawals/{{ $withdrawal->id }}/paid">@csrf<h2>Catat transfer selesai</h2><p class="help">Isi setelah transfer bank berhasil. Jika hasil transfer belum jelas, biarkan tetap diproses.</p>
<label>Nomor referensi transfer<input name="bank_reference" required minlength="4" maxlength="100" autocomplete="off"></label>
<label>Waktu transfer (WITA)<input type="datetime-local" name="transferred_at" required max="{{ now('Asia/Makassar')->format('Y-m-d\TH:i') }}" value="{{ old('transferred_at') }}"></label>
<label>Bukti transfer (foto/PDF, maks 5 MB)<input type="file" name="proof" required accept="image/jpeg,image/png,image/webp,application/pdf"></label>
<button class="button">Tandai sudah dibayar</button></form>
<details class="card"><summary class="cursor-pointer font-semibold py-2">Transfer gagal</summary><form class="form-stack mt-4" method="post" action="/platform/withdrawals/{{ $withdrawal->id }}/failed">@csrf<label>Alasan<textarea name="note" required minlength="10" maxlength="500" rows="3"></textarea></label><label class="check-option"><input type="checkbox" name="confirm_not_sent" value="1" required><span>Saya sudah memastikan dana tidak terkirim.</span></label><button class="button-secondary">Tandai gagal dan kembalikan saldo</button></form></details>
@endif
@if(in_array($withdrawal->status, ['REQUESTED','APPROVAL_PENDING']))
<details class="card"><summary class="cursor-pointer font-semibold py-2">Batalkan permintaan</summary><form class="form-stack mt-4" method="post" action="/platform/withdrawals/{{ $withdrawal->id }}/cancel">@csrf<label>Alasan<textarea name="note" required minlength="10" maxlength="500" rows="3"></textarea></label><button class="button-secondary">Batalkan dan kembalikan saldo</button></form></details>
@endif
</div>@include('withdrawal.steps')</div>
@endsection
