@extends('layouts.app')
@section('title', 'Aset')
@section('content')
<div class="page-heading"><div><span class="eyebrow">ASET BANJAR</span><h1>Inventaris</h1><p class="muted">Kelola kategori, lokasi, dan barang milik Banjar.</p></div>@if($canManage)<a class="button" href="/admin/assets/create">Tambah aset</a>@endif</div>
@include('asset.nav')
<div class="card-grid mb-6">
<section class="card"><h2>Kategori</h2><div class="grid gap-2 mt-3">@forelse($categories as $category)<div class="flex flex-wrap justify-between items-center gap-2"><span>{{ $category->name }} @if(!$category->is_active)<span class="badge">Nonaktif</span>@endif</span>@if($canManage)<form method="post" action="/admin/assets/categories/{{ $category->id }}/toggle">@csrf<button class="button-quiet">{{ $category->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>@endif</div>@empty<p class="muted">Belum ada kategori.</p>@endforelse</div>
@if($canManage)<form class="form-stack mt-4" method="post" action="/admin/assets/categories">@csrf<label>Kategori baru<input name="name" maxlength="100" required placeholder="Perlengkapan upacara"></label><button class="button-secondary">Tambah kategori</button></form>@endif</section>
<section class="card"><h2>Lokasi</h2><div class="grid gap-2 mt-3">@forelse($locations as $location)<div class="flex flex-wrap justify-between items-center gap-2"><span>{{ $location->name }} @if(!$location->is_active)<span class="badge">Nonaktif</span>@endif</span>@if($canManage)<form method="post" action="/admin/assets/locations/{{ $location->id }}/toggle">@csrf<button class="button-quiet">{{ $location->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>@endif</div>@empty<p class="muted">Belum ada lokasi.</p>@endforelse</div>
@if($canManage)<form class="form-stack mt-4" method="post" action="/admin/assets/locations">@csrf<label>Lokasi baru<input name="name" maxlength="100" required placeholder="Balai Banjar"></label><button class="button-secondary">Tambah lokasi</button></form>@endif</section>
</div>
<form method="get" class="card form-stack form-width mb-6">
<label>Cari nama atau kode<input name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
<label>Kategori<select name="category"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category'] ?? '') == $category->id)>{{ $category->name }}</option>@endforeach</select></label>
<label>Lokasi<select name="location"><option value="">Semua lokasi</option>@foreach($locations as $id => $location)<option value="{{ $id }}" @selected(($filters['location'] ?? '') == $id)>{{ $location->name }}</option>@endforeach</select></label>
<label>Status<select name="status"><option value="ACTIVE" @selected(($filters['status'] ?? 'ACTIVE') === 'ACTIVE')>Aktif</option><option value="DISPOSED" @selected(($filters['status'] ?? '') === 'DISPOSED')>Dihapuskan</option></select></label>
<button class="button-secondary">Tampilkan</button>
</form>
<div class="grid gap-4">@forelse($assets as $asset)<a class="card letter-copy" href="/admin/assets/{{ $asset->id }}"><div class="flex flex-wrap gap-3 justify-between"><h2>{{ $asset->name }}</h2><span class="badge">{{ \App\Modules\Asset\AssetManager::CONDITIONS[$asset->condition] }}</span></div><p class="muted mt-2">{{ $asset->code }} · {{ $locations[$asset->location_id]->name ?? '-' }} · tersedia {{ $asset->quantity_available }} / {{ $asset->quantity }}@if($asset->quantity_on_loan) · dipinjam {{ $asset->quantity_on_loan }}@endif</p></a>@empty<div class="card empty-state"><h2>Belum ada aset</h2><p class="muted">Tambahkan aset pertama setelah kategori dan lokasi siap.</p></div>@endforelse</div>
<div class="mt-6">{{ $assets->links() }}</div>
@endsection
