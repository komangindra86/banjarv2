@extends('layouts.app')
@section('title', 'Laporan Keuangan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">KEUANGAN</span><h1>Laporan Keuangan</h1><p class="muted">Laporan kas rinci per kategori, saldo pindahan, dan posisi dana untuk sangkep.</p></div>@if($canCash)<a class="button" href="/admin/finance/reports/create">Buat laporan baru</a>@endif</div>
@include('finance.nav')
<div class="grid gap-4">@forelse($rows as $row)
<a class="card" href="/admin/finance/reports/{{ $row->id }}">
<div class="flex flex-wrap justify-between gap-3"><h2>{{ $row->sangkep_title }}</h2><span class="badge">{{ $row->status === 'FINALIZED' ? 'Final v'.$row->version : 'Draf' }}</span></div>
<p class="muted mt-2">{{ $row->period_from }} s.d. {{ $row->period_to }}@if($row->superseded_by_id) · digantikan versi baru @endif</p>
</a>
@empty
<div class="card empty-state"><h2>Belum ada laporan</h2><p class="muted">Buat laporan pertama untuk mulai mencatat sangkep.</p></div>
@endforelse</div>
<div class="mt-6">{{ $rows->links() }}</div>
@endsection
