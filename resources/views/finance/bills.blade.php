@extends('layouts.app')
@section('title', 'Tagihan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">KEUANGAN</span><h1>Tagihan warga</h1><p class="muted">{{ $canCash ? 'Warga membayar tunai? Cari namanya, lalu klik Lunas.' : 'Daftar kewajiban iuran, patus, dan dedosan per KK.' }}</p></div></div>
@include('finance.nav')
<form method="get" class="card krama-filters mb-6">
    @if($account)<input type="hidden" name="kas" value="{{ $account->id }}">@endif
    <label>Cari kode KK / nama kepala keluarga<input name="q" maxlength="80" value="{{ $filters['q'] ?? '' }}" placeholder="Ketik nama atau kode"></label>
    <label>Jenis<select name="kind"><option value="">Semua jenis</option>@foreach(\App\Modules\Finance\FinanceManager::KINDS as $value => $label)<option value="{{ $value }}" @selected(($filters['kind'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
    <label>Status<select name="status">@foreach(['UNPAID' => 'Belum lunas', 'PAID' => 'Lunas', 'VOID' => 'Dibatalkan', 'ALL' => 'Semua status'] as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></label>
    <button class="button-secondary">Cari</button>
</form>
@if($canCash)
    @if(! $account)
    <p class="notice mb-6">Belum ada kas untuk menerima pembayaran. <a class="text-link" href="/admin/finance/cash">Tambahkan kas di Buku kas →</a></p>
    @elseif($accounts->count() > 1)
    <form method="get" class="flex flex-wrap items-center gap-3 mb-4">@foreach(['q', 'kind', 'status'] as $keep)@if(! empty($filters[$keep]))<input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">@endif @endforeach
        <label class="flex items-center gap-3">Pembayaran tunai masuk ke<select name="kas" data-auto-submit>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected($a->id === $account->id)>{{ $a->name }}</option>@endforeach</select></label>
        <noscript><button class="button-secondary">Ganti</button></noscript>
    </form>
    @else
    <p class="muted mb-4">Pembayaran tunai masuk ke <strong>{{ $account->name }}</strong>.</p>
    @endif
@endif
<p class="muted mb-4">{{ $bills->total() }} tagihan ditemukan</p>
@if($bills->isEmpty())
<section class="card empty-state"><h2>Tidak ada tagihan yang cocok</h2><p class="muted">Ubah pencarian atau pilih status lain.</p></section>
@else
<div class="table-scroll"><table class="data-table"><thead><tr><th>KK</th><th>Kepala keluarga</th><th>Tagihan</th><th>Nominal</th><th>Status</th></tr></thead><tbody>
@foreach($bills as $bill)
@php($event = $events[$bill->billing_event_id])
<tr>
    <td><a href="/admin/finance/bills/{{ $bill->id }}">{{ $bill->household_code }}</a></td>
    <td>{{ $bill->head_name ?? '—' }}</td>
    <td class="muted">{{ \App\Modules\Finance\FinanceManager::KINDS[$bill->kind] }} · {{ $event->title }}<br><small>{{ \Carbon\Carbon::parse($event->event_date)->translatedFormat('d M Y') }}</small></td>
    <td>{{ \App\Modules\Finance\FinanceManager::money($bill->gross_amount) }}</td>
    <td>
        @if($bill->status === 'UNPAID' && $canCash && $account)
        <form method="post" action="/admin/finance/bills/{{ $bill->id }}/cash-paid" data-confirm="{{ in_array($bill->id, $pendingQris) ? 'Ada pembayaran QRIS warga ini yang masih diproses. Tetap catat lunas tunai?' : 'Tandai lunas tunai: '.$bill->household_code.($bill->head_name ? ' · '.$bill->head_name : '').' · '.\App\Modules\Finance\FinanceManager::money($bill->gross_amount).'?' }}">@csrf
            <input type="hidden" name="cash_account_id" value="{{ $account->id }}"><input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <button class="button">Lunas</button>@if(in_array($bill->id, $pendingQris))<span class="badge badge-muted ml-2">QRIS diproses</span>@endif
        </form>
        @else
        <span class="badge {{ $bill->status === 'PAID' ? '' : 'badge-muted' }}">{{ \App\Modules\Finance\FinanceManager::STATUSES[$bill->status] }}</span>
        @endif
    </td>
</tr>
@endforeach
</tbody></table></div>
@endif
<div class="mt-6">{{ $bills->links() }}</div>
@endsection
