@extends('layouts.app')
@section('title', 'Laporan keuangan')
@section('content')
<a href="/portal/beranda" class="back-link">← Beranda</a>
<div class="page-heading"><div><span class="eyebrow">TRANSPARANSI BANJAR</span><h1>Laporan keuangan</h1><p class="muted">Laporan kas per periode sangkep yang sudah difinalkan Petengen dan diketahui Kelian Adat.</p></div></div>
@if($reports->isEmpty())
<div class="card empty-state"><h2>Belum ada laporan</h2><p class="muted">Laporan kas akan tampil di sini setelah difinalkan untuk sangkep.</p></div>
@else
<div class="card history-list">
@foreach($reports as $report)
@php($snapshot = json_decode($report->computed_snapshot ?? '{}', true))
<a class="history-row" href="/portal/laporan/{{ $report->id }}"><div class="min-w-0"><strong class="break-words">{{ $report->sangkep_title }}</strong><span class="help">Periode {{ \Carbon\Carbon::parse($report->period_from)->translatedFormat('d M Y') }} – {{ \Carbon\Carbon::parse($report->period_to)->translatedFormat('d M Y') }}@if($report->sangkep_date) · sangkep {{ \Carbon\Carbon::parse($report->sangkep_date)->translatedFormat('d M Y') }}@endif</span></div><div class="history-amount"><strong>{{ \App\Modules\Finance\FinanceManager::money($snapshot['saldo_akhir'] ?? 0) }}</strong><span class="help">saldo akhir</span></div></a>
@endforeach
</div>
<div class="mt-6">{{ $reports->links() }}</div>
@endif
@endsection
