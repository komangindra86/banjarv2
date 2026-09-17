@extends('layouts.app')
@section('title', $asset ? 'Ubah aset' : 'Tambah aset')
@section('content')
<a href="{{ $asset ? '/admin/assets/'.$asset->id : '/admin/assets' }}" class="back-link">← {{ $asset ? $asset->name : 'Inventaris' }}</a>
<div class="page-heading"><div><span class="eyebrow">ASET BANJAR</span><h1>{{ $asset ? 'Ubah aset' : 'Tambah aset' }}</h1></div></div>
<form class="card form-stack form-width" method="post" action="{{ $asset ? '/admin/assets/'.$asset->id : '/admin/assets' }}">
@csrf
@if($asset) @method('PATCH') @endif
<label>Kategori<select name="category_id" required>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id', $asset->category_id ?? '') == $category->id)>{{ $category->name }}</option>@endforeach</select></label>
@unless($asset)
<label>Lokasi<select name="location_id" required>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(old('location_id') == $location->id)>{{ $location->name }}</option>@endforeach</select></label>
<label>Jenis<select name="kind" id="asset-kind" required>@foreach(\App\Modules\Asset\AssetManager::KINDS as $value => $label)<option value="{{ $value }}" @selected(old('kind') === $value)>{{ $label }}</option>@endforeach</select></label>
<label id="asset-quantity" @if(old('kind', 'QUANTITY') !== 'QUANTITY') hidden @endif>Jumlah<input name="quantity" type="number" min="1" max="999999" value="{{ old('quantity', 1) }}"><span class="help">Barang identik dihitung sebagai satu aset dengan jumlah, bukan record terpisah.</span></label>
<label>Kode aset<input name="code" maxlength="40" required value="{{ old('code') }}" placeholder="AST-001"><span class="help">Dipakai pada label QR yang dicetak; tidak dapat diganti setelah disimpan.</span></label>
@endunless
<label>Nama<input name="name" maxlength="150" required value="{{ old('name', $asset->name ?? '') }}" placeholder="Sound system"></label>
<label>Tanggal perolehan<input name="acquisition_date" type="date" value="{{ old('acquisition_date', $asset->acquisition_date ?? '') }}"></label>
<label>Biaya perolehan (Rp)<input name="acquisition_cost" inputmode="numeric" pattern="[0-9]{1,12}" value="{{ old('acquisition_cost', $asset->acquisition_cost ?? '') }}" placeholder="1500000"></label>
<label>Catatan<textarea name="notes" maxlength="1000" rows="3">{{ old('notes', $asset->notes ?? '') }}</textarea></label>
<button class="button">{{ $asset ? 'Simpan perubahan' : 'Tambah aset' }}</button>
</form>
@endsection
