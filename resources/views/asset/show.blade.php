@extends('layouts.app')
@section('title', $asset->name)
@section('content')
<a href="/admin/assets" class="back-link">← Inventaris</a>
<div class="page-heading"><div><span class="eyebrow">{{ $category->name ?? '-' }}</span><h1>{{ $asset->name }}</h1><p class="muted">{{ $asset->code }} · {{ $location->name ?? '-' }}</p></div>
<div class="flex flex-wrap gap-2">@if($canManage && $asset->status === 'ACTIVE')<a class="button-secondary" href="/admin/assets/{{ $asset->id }}/edit">Ubah</a>@endif<a class="button-secondary" href="/admin/assets/{{ $asset->id }}/qr">QR</a></div>
</div>
<div class="card-grid mb-6">
<section class="card"><span class="eyebrow">STATUS</span><h2 class="mt-3">{{ $asset->status === 'DISPOSED' ? 'Dihapuskan' : \App\Modules\Asset\AssetManager::CONDITIONS[$asset->condition] }}</h2></section>
<section class="card"><span class="eyebrow">TERSEDIA</span><h2 class="mt-3">{{ $asset->quantity_available }} / {{ $asset->quantity }}</h2>@if($asset->quantity_on_loan)<p class="muted mt-2">{{ $asset->quantity_on_loan }} sedang dipinjam</p>@endif</section>
@if($asset->acquisition_cost)<section class="card"><span class="eyebrow">PEROLEHAN</span><h2 class="mt-3">{{ \App\Modules\Finance\FinanceManager::money($asset->acquisition_cost) }}</h2><p class="muted mt-2">{{ $asset->acquisition_date }}</p></section>@endif
</div>
@if($asset->notes)<section class="card mb-6"><h2>Catatan</h2><p class="muted mt-2">{{ $asset->notes }}</p></section>@endif

@if($canManage && $asset->status === 'ACTIVE')
<div class="card-grid mb-6">
<section class="card"><h2>Pindah lokasi</h2><form class="form-stack mt-3" method="post" action="/admin/assets/{{ $asset->id }}/transfer">@csrf<label>Lokasi tujuan<select name="location_id" required>@foreach($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach</select></label><label>Catatan<input name="note" maxlength="500"></label><button class="button-secondary">Pindahkan</button></form></section>
<section class="card"><h2>Catat kondisi</h2><form class="form-stack mt-3" method="post" action="/admin/assets/{{ $asset->id }}/inspect">@csrf<label>Kondisi<select name="condition" required>@foreach(\App\Modules\Asset\AssetManager::CONDITIONS as $value => $label)<option value="{{ $value }}" @selected($asset->condition === $value)>{{ $label }}</option>@endforeach</select></label><label>Catatan<input name="note" maxlength="500"></label><button class="button-secondary">Simpan kondisi</button></form></section>
</div>
@endif

<div class="page-heading"><div><h2>Perawatan</h2></div></div>
<div class="grid gap-3 mb-6">
@forelse($maintenance as $item)
<section class="card letter-copy"><div class="flex flex-wrap gap-3 justify-between"><h3>{{ $item->description }}</h3><span class="badge">{{ $item->status === 'DONE' ? 'Selesai' : 'Dijadwalkan' }}</span></div>
@if($item->scheduled_at)<p class="muted mt-2">Rencana {{ $item->scheduled_at }}</p>@endif
@if($item->status === 'DONE')
<p class="muted mt-2">Selesai {{ \Carbon\Carbon::parse($item->completed_at)->timezone('Asia/Makassar')->translatedFormat('d M Y, H:i') }} WITA{{ $item->cost ? ' · '.\App\Modules\Finance\FinanceManager::money($item->cost) : '' }}</p>
@elseif($canManage)
<form class="form-stack mt-3" method="post" action="/admin/assets/{{ $asset->id }}/maintenance/{{ $item->id }}/complete">@csrf<label>Biaya (Rp, opsional)<input name="cost" inputmode="numeric" pattern="[0-9]{1,12}"></label><label>Kas terkait (opsional)<select name="cash_transaction_id"><option value="">Tanpa kas</option>@foreach($cashOptions as $cash)<option value="{{ $cash->id }}">{{ $cash->reference }} · {{ \App\Modules\Finance\FinanceManager::money($cash->amount) }}</option>@endforeach</select></label><label>Catatan<input name="note" maxlength="500"></label><button class="button-secondary">Tandai selesai</button></form>
@endif
</section>
@empty
<p class="muted">Belum ada catatan perawatan.</p>
@endforelse
</div>
@if($canManage && $asset->status === 'ACTIVE')<details class="card form-width mb-6"><summary class="cursor-pointer font-semibold py-3">Jadwalkan perawatan</summary><form class="form-stack mt-3" method="post" action="/admin/assets/{{ $asset->id }}/maintenance">@csrf<label>Deskripsi<input name="description" maxlength="255" required></label><label>Tanggal rencana<input name="scheduled_at" type="date"></label><button class="button-secondary">Jadwalkan</button></form></details>@endif

@if($loanItems->isNotEmpty())
<div class="page-heading"><div><h2>Sedang dipinjam</h2></div></div>
<div class="grid gap-3 mb-6">@foreach($loanItems as $item)<a class="card letter-copy" href="/admin/assets/loans/{{ $item->loan_id }}"><p>{{ $item->quantity - $item->returned_quantity }} unit · Peminjaman #{{ $item->loan_id }}</p><p class="muted mt-2">{{ $loans[$item->loan_id]->purpose ?? '' }}</p></a>@endforeach</div>
@endif

@if($canDispose && $asset->status === 'ACTIVE')
<div class="page-heading"><div><h2>Penghapusan</h2><p class="muted">Gunakan untuk aset yang dijual, hilang, atau rusak total. Riwayat tetap tersimpan.</p></div></div>
<section class="card form-width mb-6"><form class="form-stack" method="post" action="/admin/assets/{{ $asset->id }}/dispose" data-confirm="Hapuskan aset ini? Tindakan ini tidak dapat dibatalkan.">@csrf<label>Jumlah<input name="quantity" type="number" min="1" max="{{ $asset->quantity_available }}" value="{{ $asset->quantity_available }}" required></label><label>Metode<select name="method" required>@foreach(\App\Modules\Asset\AssetManager::DISPOSAL_METHODS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label><label>Alasan<textarea name="reason" minlength="5" maxlength="500" required rows="2"></textarea></label><label>Hasil penjualan tercatat pada kas (opsional)<select name="proceeds_cash_transaction_id"><option value="">Tanpa kas</option>@foreach($cashOptions as $cash)<option value="{{ $cash->id }}">{{ $cash->reference }} · {{ \App\Modules\Finance\FinanceManager::money($cash->amount) }}</option>@endforeach</select></label><button class="button">Hapuskan aset</button></form></section>
@endif

<div class="page-heading"><div><h2>Riwayat mutasi</h2></div></div>
<div class="grid gap-2 mb-6">
@forelse($movements as $movement)
<section class="card letter-copy"><div class="flex flex-wrap gap-3 justify-between"><span class="badge">{{ $movement->type }}</span><span class="muted">{{ \Carbon\Carbon::parse($movement->created_at)->timezone('Asia/Makassar')->translatedFormat('d M Y, H:i') }} WITA</span></div><p class="mt-2">{{ $movement->quantity }} unit{{ $movement->note ? ' · '.$movement->note : '' }}</p></section>
@empty
<p class="muted">Belum ada riwayat.</p>
@endforelse
</div>
@endsection
