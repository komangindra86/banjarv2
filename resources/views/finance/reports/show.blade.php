@extends('layouts.app')
@section('title', $report->sangkep_title)
@section('content')
<div class="page-heading print:hidden"><div><span class="eyebrow">{{ $report->status === 'FINALIZED' ? 'FINAL · VERSI '.$report->version : 'DRAF' }}</span><h1>{{ $report->sangkep_title }}</h1><p class="muted">{{ $report->period_from }} s.d. {{ $report->period_to }}</p></div>
<div class="flex flex-wrap gap-3">
<button type="button" class="button-secondary" id="finance-print">Cetak</button>
@if($report->status === 'FINALIZED' && $report->pdf_status === 'READY')<a class="button-secondary" href="/admin/finance/reports/{{ $report->id }}/pdf" target="_blank">Unduh PDF</a>@endif
@if($report->status === 'FINALIZED' && $report->pdf_status === 'PENDING')<span class="badge">PDF sedang disiapkan…</span>@endif
</div></div>
@include('finance.nav')

@if($report->status === 'DRAFT' && $canCash)
<section class="card form-width mb-6"><h2>Finalisasi laporan</h2><p class="help mt-2">Membekukan seluruh angka pada laporan ini. Transaksi baru setelahnya tidak akan mengubah laporan ini lagi.</p>
<form class="form-stack mt-3" method="post" action="/admin/finance/reports/{{ $report->id }}/finalize">@csrf
<label>Nama Petengen/Bendahara (Yang Membuat)<input name="signer_treasurer_name" maxlength="150" required value="{{ old('signer_treasurer_name') }}"></label>
<label>Nama Kelian Adat (Mengetahui)<input name="signer_kelian_name" maxlength="150" required value="{{ old('signer_kelian_name') }}"></label>
<button class="button">Finalisasi laporan</button>
</form></section>
@endif
@if($report->status === 'FINALIZED' && ! $report->superseded_by_id && $canCash)
<details class="card form-width mb-6"><summary class="cursor-pointer py-3 font-semibold">Buat versi koreksi</summary>
<form class="form-stack mt-3" method="post" action="/admin/finance/reports/{{ $report->id }}/revise">@csrf
<p class="help">Versi lama tetap tersimpan sebagai riwayat. Koreksi menghasilkan versi baru sebagai draf.</p>
<label>Alasan koreksi<textarea name="reason" minlength="5" maxlength="1000" required rows="3"></textarea></label>
<button class="button-secondary">Buat versi koreksi</button>
</form></details>
@endif

<div class="card">@include('finance.reports._body', ['report' => $report, 'data' => $data])</div>
@endsection
