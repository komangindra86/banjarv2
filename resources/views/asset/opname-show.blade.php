@extends('layouts.app')
@section('title', 'Stock opname #'.$opname->id)
@section('content')
<a href="/admin/assets/opname" class="back-link">← Stock opname</a>
<div class="page-heading"><div><span class="eyebrow">STOCK OPNAME #{{ $opname->id }}</span><h1>{{ $opname->status === 'COMPLETED' ? 'Selesai' : 'Sedang berjalan' }}</h1></div></div>
<div class="grid gap-3 mb-6">@forelse($items as $item)<section class="card letter-copy"><div class="flex flex-wrap gap-3 justify-between"><h3>{{ $assets[$item->asset_id]->name ?? '-' }}</h3><span class="badge">Harapan {{ $item->expected_quantity }}</span></div>
@if($opname->status === 'DRAFT' && $canManage)<form class="form-stack mt-3" method="post" action="/admin/assets/opname/{{ $opname->id }}/items/{{ $item->id }}">@csrf<label>Jumlah dihitung<input name="counted_quantity" type="number" min="0" required value="{{ $item->counted_quantity }}"></label><label>Catatan<input name="note" maxlength="500" value="{{ $item->note }}"></label><button class="button-secondary">Simpan hitungan</button></form>
@else<p class="muted mt-2">Dihitung: {{ $item->counted_quantity ?? 'Belum dihitung' }}@if($item->note) · {{ $item->note }}@endif</p>@endif
</section>@empty<p class="muted">Tidak ada aset pada opname ini.</p>@endforelse</div>
@if($opname->status === 'DRAFT' && $canManage)<form class="card form-width" method="post" action="/admin/assets/opname/{{ $opname->id }}/complete" data-confirm="Selesaikan opname? Selisih akan menyesuaikan jumlah aset secara otomatis."><p class="help mb-4">Pastikan seluruh aset sudah dihitung. Selisih akan tercatat sebagai penyesuaian pada riwayat mutasi aset.</p>@csrf<button class="button">Selesaikan opname</button></form>@endif
@endsection
