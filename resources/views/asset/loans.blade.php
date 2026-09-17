@extends('layouts.app')
@section('title', 'Peminjaman aset')
@section('content')
<div class="page-heading"><div><span class="eyebrow">ASET BANJAR</span><h1>Peminjaman</h1><p class="muted">Catat barang yang dipinjam dan pengembaliannya.</p></div>@if($canManage)<a class="button" href="/admin/assets/loans/create">Buat peminjaman</a>@endif</div>
@include('asset.nav')
<div class="grid gap-4">@forelse($loans as $loan)<a class="card letter-copy" href="/admin/assets/loans/{{ $loan->id }}"><div class="flex flex-wrap gap-3 justify-between"><h2>{{ $loan->purpose }}</h2><span class="badge">{{ ['ACTIVE' => 'Berjalan', 'PARTIALLY_RETURNED' => 'Sebagian kembali', 'RETURNED' => 'Selesai'][$loan->status] }}</span></div><p class="muted mt-2">{{ $loan->borrower_krama_id ? ($borrowers[$loan->borrower_krama_id]->name ?? '-') : $loan->borrower_name }} · dipinjam {{ $loan->loan_date }}@if($loan->due_date) · kembali {{ $loan->due_date }}@endif</p></a>@empty<div class="card empty-state"><h2>Belum ada peminjaman</h2></div>@endforelse</div>
<div class="mt-6">{{ $loans->links() }}</div>
@endsection
