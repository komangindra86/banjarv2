@extends('layouts.app')
@section('title', 'Keuangan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">KEUANGAN BANJAR</span><h1>Kas & kewajiban</h1><p class="muted">Pantau kas yang dicatat pengurus dan tagihan keluarga.</p></div></div>
@include('finance.nav')
<div class="card-grid mb-6">
@foreach($accounts as $account)<section class="card"><span class="eyebrow">{{ $account->name }}</span><h2 class="mt-3">{{ \App\Modules\Finance\FinanceManager::money($account->balance) }}</h2><a class="text-link mt-3" href="/admin/finance/cash?account={{ $account->id }}">Lihat buku kas →</a></section>@endforeach
<section class="card"><span class="eyebrow">BELUM LUNAS</span><h2 class="mt-3">{{ \App\Modules\Finance\FinanceManager::money($outstanding) }}</h2><a class="text-link mt-3" href="/admin/finance/bills?status=UNPAID">Periksa tagihan →</a></section>
</div>
<div class="page-heading"><div><h2>Event tagihan</h2><p class="muted">Satu kejadian memiliki satu referensi, misalnya IURAN-2026-09.</p></div>@if($canIssue)<a class="button" href="/admin/finance/settings">Buat tagihan</a>@endif</div>
<div class="grid gap-4">@forelse($events as $event)<a class="card letter-copy" href="/admin/finance/events/{{ $event->id }}"><div class="flex flex-wrap gap-3 justify-between"><h2>{{ $event->title }}</h2><span class="badge">{{ \App\Modules\Finance\FinanceManager::STATUSES[$event->status] }}</span></div><p class="muted mt-2">{{ $event->reference }} · {{ \App\Modules\Finance\FinanceManager::KINDS[$event->kind] }} · {{ \App\Modules\Finance\FinanceManager::money($event->amount) }} / {{ $event->kind === 'DEDOSAN' ? 'individu' : 'KK' }}</p></a>@empty<div class="card empty-state"><h2>Belum ada event tagihan</h2><p class="muted">Petengen dapat menyiapkan jenis iuran atau dedosan, lalu membuat event.</p></div>@endforelse</div><div class="mt-6">{{ $events->links() }}</div>
@endsection
