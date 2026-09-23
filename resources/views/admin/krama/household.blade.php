@extends('layouts.app')
@section('title', $row ? $row->code : 'Tambah KK')
@section('content')
<a class="back-link" href="/admin/krama">← Keluarga & krama</a><div class="page-heading"><div><h1>{{ $row ? $row->code : 'Tambah KK' }}</h1><p class="muted">{{ $row ? 'Kelola data KK dan anggotanya.' : 'Buat KK sekaligus kepala keluarga dalam satu langkah. Anggota lain bisa ditambahkan setelah ini tersimpan.' }}</p></div></div>
<div class="krama-detail"><section class="card"><h2 class="mb-5">Data keluarga</h2>
@if($canEdit)<form class="form-stack" method="post" action="/admin/krama/households{{ $row ? '/'.$row->id : '' }}">@csrf @if($row) @method('PATCH') @endif<input type="hidden" name="version" value="{{ $row->version ?? 0 }}">
<label>Kode KK internal<input name="code" value="{{ old('code', $row->code ?? '') }}" maxlength="40" required autofocus><span class="help">Contoh: KK-001. Tidak harus nomor KK kependudukan.</span></label>
<label>Alamat<textarea name="address" maxlength="1000" rows="3">{{ old('address', $row->address ?? '') }}</textarea></label>
<label>Status<select name="status"><option value="ACTIVE" @selected(old('status', $row->status ?? 'ACTIVE') === 'ACTIVE')>Aktif</option><option value="INACTIVE" @selected(old('status', $row->status ?? '') === 'INACTIVE')>Nonaktif</option></select></label>
@if($row)
<label>Kepala keluarga<select name="head_krama_id"><option value="">Belum ditetapkan</option>@foreach($members->where('status', 'ACTIVE') as $member)<option value="{{ $member->id }}" @selected(old('head_krama_id', $row->head_krama_id ?? '') == $member->id)>{{ $member->name }}</option>@endforeach</select></label>
@else
<label>Nama kepala keluarga (opsional)<input name="head_name" maxlength="150" value="{{ old('head_name') }}" placeholder="Contoh: I Made Suarta"><span class="help">Langsung dibuat sebagai anggota pertama sekaligus kepala keluarga. Kosongkan bila belum ingin menambahkan anggota.</span></label>
@endif
@include('admin.krama.unit-choices')
<details><summary class="touch-link">Nomor KK kependudukan (opsional)</summary><p class="muted my-3">{{ \App\Modules\Krama\KramaManager::masked($row->family_number ?? null) }}</p><label>Isi atau ganti nomor KK<input name="family_number" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" autocomplete="off"><span class="help">16 digit. Kosongkan untuk mempertahankan nomor yang tersimpan.</span></label><label class="check-option"><input type="checkbox" name="clear_family_number" value="1"><span>Hapus nomor yang tersimpan</span></label></details><button class="button">Simpan KK</button></form>
@else<p>{{ $row->address ?: 'Alamat belum diisi' }}</p><p class="muted mt-3">Nomor KK: {{ \App\Modules\Krama\KramaManager::masked($row->family_number) }}</p>@endif
</section><section><div class="section-label"><h2>Anggota keluarga</h2>@if($row && $canEdit)<a class="button-secondary" href="/admin/krama/people/create?household={{ $row->id }}">+ Anggota</a>@endif</div><div class="form-stack">@forelse($members as $member)@php($relationLabel = $row->head_krama_id == $member->id ? 'Kepala Keluarga' : ($member->relationship ? \App\Modules\Krama\KramaManager::RELATIONSHIPS[$member->relationship] : null))<a href="/admin/krama/people/{{ $member->id }}" class="card tenant-card"><h2>{{ $member->name }}</h2><p class="muted mt-2">{{ $relationLabel ? $relationLabel.' · ' : '' }}{{ \App\Modules\Krama\KramaManager::STATUSES[$member->status] }}</p></a>@empty<div class="card"><p class="muted">{{ $row ? 'Tambahkan anggota pertama untuk keluarga ini.' : 'Simpan KK dahulu untuk menambahkan anggota.' }}</p></div>@endforelse</div></section></div>
@endsection
