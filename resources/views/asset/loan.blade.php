@extends('layouts.app')
@section('title', 'Peminjaman #'.$loan->id)
@section('content')
<a href="/admin/assets/loans" class="back-link">← Peminjaman</a>
<div class="page-heading"><div><span class="eyebrow">PEMINJAMAN #{{ $loan->id }}</span><h1>{{ $loan->purpose }}</h1><p class="muted">{{ $borrower->name ?? $loan->borrower_name }} · dipinjam {{ $loan->loan_date }}@if($loan->due_date) · rencana kembali {{ $loan->due_date }}@endif</p></div>
<span class="badge">{{ ['ACTIVE' => 'Berjalan', 'PARTIALLY_RETURNED' => 'Sebagian kembali', 'RETURNED' => 'Selesai'][$loan->status] }}</span>
</div>

<div class="grid gap-4 mb-6">@forelse($items as $item)<section class="card letter-copy"><div class="flex flex-wrap gap-3 justify-between"><h2>{{ $assets[$item->asset_id]->name ?? '-' }}</h2><span class="badge">{{ $item->quantity - $item->returned_quantity }} / {{ $item->quantity }} belum kembali</span></div><p class="muted mt-2">Kondisi saat dipinjam: {{ \App\Modules\Asset\AssetManager::CONDITIONS[$item->condition_out] }}</p>
@if($canManage && $item->returned_quantity < $item->quantity)<form class="form-stack mt-4" method="post" action="/admin/assets/loans/{{ $loan->id }}/items/{{ $item->id }}/return">@csrf<label>Jumlah dikembalikan<input name="quantity" type="number" min="1" max="{{ $item->quantity - $item->returned_quantity }}" value="{{ $item->quantity - $item->returned_quantity }}" required></label><label>Kondisi saat kembali<select name="condition_in" required>@foreach(\App\Modules\Asset\AssetManager::CONDITIONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label><label>Catatan<input name="note" maxlength="500"></label><button class="button-secondary">Catat pengembalian</button></form>@endif
</section>@empty<div class="card"><p class="muted">Belum ada aset pada peminjaman ini.</p></div>@endforelse</div>

@if($canManage && $loan->status !== 'RETURNED')<section class="card form-width mb-6"><h2>Tambah aset dipinjam</h2><form class="form-stack mt-3" method="post" action="/admin/assets/loans/{{ $loan->id }}/items">@csrf<label>Aset<select name="asset_id" required>@foreach($availableAssets as $asset)<option value="{{ $asset->id }}">{{ $asset->name }} ({{ $asset->quantity_available }} tersedia)</option>@endforeach</select></label><label>Jumlah<input name="quantity" type="number" min="1" required value="1"></label><label>Kondisi saat dipinjam<select name="condition_out" required>@foreach(\App\Modules\Asset\AssetManager::CONDITIONS as $value => $label)<option value="{{ $value }}" @selected($value === 'BAIK')>{{ $label }}</option>@endforeach</select></label><button class="button-secondary">Tambahkan</button></form></section>@endif

@if($returns->isNotEmpty())<div class="page-heading"><div><h2>Riwayat pengembalian</h2></div></div>
<div class="grid gap-2 mb-6">@foreach($returns as $return)<section class="card letter-copy"><p>{{ $return->quantity }} unit · {{ \App\Modules\Asset\AssetManager::CONDITIONS[$return->condition_in] }}@if($return->note) · {{ $return->note }}@endif</p></section>@endforeach</div>@endif
@endsection
