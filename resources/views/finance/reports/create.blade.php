@extends('layouts.app')
@section('title', 'Buat laporan keuangan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">KEUANGAN</span><h1>Buat laporan keuangan</h1><p class="muted">Laporan mendukung periode tanggal bebas, tidak harus mengikuti bulan kalender.</p></div></div>
@include('finance.nav')
<form class="card form-stack form-width" method="post" action="/admin/finance/reports">@csrf
<label>Nama sangkep/agenda<input name="sangkep_title" maxlength="200" required value="{{ old('sangkep_title') }}" placeholder="SANGKEP PEMACEKAN AGUNG"></label>
<label>Tanggal sangkep (opsional)<input name="sangkep_date" type="date" value="{{ old('sangkep_date') }}"></label>
@if($previous)
<input type="hidden" name="previous_report_id" value="{{ $previous->id }}">
<p class="notice">Melanjutkan dari laporan final "{{ $previous->sangkep_title }}" ({{ $previous->period_to }}). Saldo pindahan otomatis mengikuti saldo akhir laporan tersebut.</p>
<label>Tanggal mulai periode<input name="period_from" type="date" required value="{{ old('period_from', \Carbon\Carbon::parse($previous->period_to)->addDay()->toDateString()) }}"></label>
@else
<p class="notice">Belum ada laporan final sebelumnya. Laporan ini memerlukan saldo awal manual beserta catatan/bukti.</p>
<label>Tanggal mulai periode<input name="period_from" type="date" required value="{{ old('period_from') }}"></label>
<x-rupiah-input name="opening_balance" :value="old('opening_balance', 0)" label="Saldo awal (Rp)" />
<label>Catatan/bukti saldo awal<textarea name="opening_balance_note" maxlength="1000" required rows="3">{{ old('opening_balance_note') }}</textarea></label>
@endif
<label>Tanggal akhir periode<input name="period_to" type="date" required value="{{ old('period_to', now('Asia/Makassar')->toDateString()) }}"></label>
<button class="button">Buat draf laporan</button>
</form>
@endsection
