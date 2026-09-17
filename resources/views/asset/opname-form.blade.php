@extends('layouts.app')
@section('title', 'Buka stock opname')
@section('content')
<a href="/admin/assets/opname" class="back-link">← Stock opname</a>
<div class="page-heading"><div><span class="eyebrow">ASET BANJAR</span><h1>Buka stock opname</h1><p class="muted">Jumlah yang diharapkan diambil dari stok tersedia saat ini (tidak termasuk yang sedang dipinjam).</p></div></div>
<form class="card form-stack form-width" method="post" action="/admin/assets/opname">
@csrf
<label>Lokasi (kosongkan untuk semua lokasi)<select name="location_id"><option value="">Semua lokasi</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></label>
<label>Catatan<input name="note" maxlength="500"></label>
<button class="button">Buka opname</button>
</form>
@endsection
