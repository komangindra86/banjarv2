@extends('layouts.app')
@section('title', 'Unit & aturan')
@section('content')
<h1>Unit & aturan</h1>@include('admin.krama.nav')
<p class="muted mb-6">Susun Tempekan, Regu, dan Maksan. Akses pengurus per unit diatur oleh Super Admin melalui Akses pengurus.</p>
<div class="card-grid">@foreach($units as $unit)<section class="card"><h2>{{ $unit->name }}</h2><form class="form-stack mt-5" method="post" action="/admin/krama/units/{{ $unit->id }}">@csrf @method('PATCH') @include('admin.krama.unit-form', ['editing' => $unit])<button class="button-secondary">Simpan unit</button></form></section>@endforeach
<section class="card"><h2>Tambah unit</h2><form class="form-stack mt-5" method="post" action="/admin/krama/units">@csrf @include('admin.krama.unit-form', ['editing' => null])<button class="button">Tambah unit</button></form></section></div>
@if($access->tenant(auth()->user(), 'krama.configure'))<section class="card form-width mt-8"><h2>Persetujuan perubahan data</h2><form class="form-stack mt-5" method="post" action="/admin/krama/policy">@csrf @method('PATCH')<label>Setelah verifikasi Kesinoman<select name="krama_changes_require_approval"><option value="0" @selected(!$policy)>Langsung terapkan</option><option value="1" @selected($policy)>Minta persetujuan Kelian</option></select></label><p class="help">Berlaku untuk pengajuan baru. Pengaju tidak bisa memeriksa pengajuannya sendiri.</p><button class="button-secondary">Simpan aturan</button></form></section>@endif
@endsection
