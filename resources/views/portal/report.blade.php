@extends('layouts.app')
@section('title', $report->sangkep_title)
@section('content')
<a href="/portal/laporan" class="back-link print:hidden">← Laporan keuangan</a>
<div class="page-heading print:hidden"><div><span class="eyebrow">LAPORAN KAS · FINAL{{ $report->version > 1 ? ' · VERSI '.$report->version : '' }}</span><h1>{{ $report->sangkep_title }}</h1><p class="muted">Periode {{ \Carbon\Carbon::parse($report->period_from)->translatedFormat('d M Y') }} – {{ \Carbon\Carbon::parse($report->period_to)->translatedFormat('d M Y') }}</p></div>
<div class="flex flex-wrap gap-3">
<button type="button" class="button-secondary" id="finance-print">Cetak</button>
@if($report->pdf_status === 'READY')<a class="button-secondary" href="/portal/laporan/{{ $report->id }}/pdf" target="_blank">Unduh PDF</a>@endif
</div></div>
<div class="card table-scroll">@include('finance.reports._body', ['report' => $report, 'data' => $data])</div>
@endsection
