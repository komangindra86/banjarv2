@extends('layouts.app')
@section('title', 'Buat laporan keuangan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">KEUANGAN</span><h1>Buat laporan keuangan</h1><p class="muted">Cukup isi nama dan tanggal sangkep. Periode, saldo pindahan, dan rincian transaksi dihitung otomatis dari buku kas.</p></div></div>
@include('finance.nav')
<form class="card form-stack form-width" method="post" action="/admin/finance/reports">@csrf
<x-editor-field name="sangkep_title" label="Nama sangkep" help="Contoh: Sangkep Purnama Kapat" required />
<x-editor-field name="sangkep_date" label="Tanggal sangkep" type="date" :value="now('Asia/Makassar')->toDateString()" required />
@if($previous)
<p class="help">Laporan ini melanjutkan laporan "{{ $previous->sangkep_title }}": mencakup transaksi sejak {{ \Carbon\Carbon::parse($previous->period_to)->addDay()->translatedFormat('d F Y') }} sampai tanggal sangkep, dengan saldo pindahan dari laporan tersebut.</p>
@else
<p class="help">Ini laporan pertama. Saldo awal Banjar diambil dari buku kas (yang dicatat pada hari pertama buku kas) dan tampil sebagai saldo pindahan, jadi tidak perlu diisi lagi.</p>
@endif
<button class="button">Buat draf laporan</button>
</form>
@endsection
