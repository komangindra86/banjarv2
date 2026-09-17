@extends('layouts.app')
@section('title', $row ? $row->name : 'Tambah krama')
@section('content')
<a class="back-link" href="/admin/krama?tab=people">← Daftar krama</a><div class="page-heading"><div><h1>{{ $row ? $row->name : 'Tambah krama' }}</h1><p class="muted">Identitas dan keanggotaan krama.</p></div>@if($row && $canEdit)<a class="button-secondary" href="/admin/krama/people/{{ $row->id }}/change">Ajukan perubahan</a>@endif</div>
<section class="card form-width">
@if($canEdit)<form class="form-stack" method="post" action="/admin/krama/people{{ $row ? '/'.$row->id : '' }}">@csrf @if($row) @method('PATCH') @endif<input type="hidden" name="version" value="{{ $row->version ?? 0 }}">
<label>Nama lengkap<input name="name" required maxlength="150" value="{{ old('name', $row->name ?? '') }}"></label><label>Keluarga<select name="household_id" required><option value="">Pilih KK</option>@foreach($households as $household)<option value="{{ $household->id }}" @selected(old('household_id', $row->household_id ?? request('household')) == $household->id)>{{ $household->code }}</option>@endforeach</select></label>
<label>Status<select name="status">@foreach(\App\Modules\Krama\KramaManager::STATUSES as $code => $label)<option value="{{ $code }}" @selected(old('status', $row->status ?? 'ACTIVE') === $code)>{{ $label }}</option>@endforeach</select></label>
<label>Tanggal lahir (opsional)<input type="date" name="birth_date" value="{{ old('birth_date', $row->birth_date ?? '') }}" max="{{ now()->format('Y-m-d') }}"></label><label>Telepon (opsional)<input name="phone" type="tel" maxlength="30" value="{{ old('phone', $row->phone ?? '') }}"></label>
@include('admin.krama.unit-choices')
<details><summary class="touch-link">NIK (opsional)</summary><p class="muted my-3">{{ \App\Modules\Krama\KramaManager::masked($row->nik ?? null) }}</p><label>Isi atau ganti NIK<input name="nik" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" autocomplete="off"><span class="help">16 digit. Kosongkan untuk mempertahankan NIK yang tersimpan.</span></label><label class="check-option"><input type="checkbox" name="clear_nik" value="1"><span>Hapus NIK yang tersimpan</span></label></details>
@if($access->tenant(auth()->user(), 'krama.configure'))<label>Akun untuk pengajuan mandiri<select name="user_id"><option value="">Belum terhubung</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('user_id', $row->user_id ?? '') == $account->id)>{{ $account->name }} · {{ $account->email }}</option>@endforeach</select><span class="help">Akun dibuat melalui Akses pengurus oleh Super Admin dengan peran Krama.</span></label>@else<input type="hidden" name="user_id" value="{{ $row->user_id ?? '' }}">@endif
<button class="button">Simpan krama</button></form>
@else<dl class="form-stack"><div><dt>Status</dt><dd>{{ \App\Modules\Krama\KramaManager::STATUSES[$row->status] }}</dd></div><div><dt>NIK</dt><dd>{{ \App\Modules\Krama\KramaManager::masked($row->nik) }}</dd></div><div><dt>Tanggal lahir</dt><dd>{{ $row->birth_date ?: 'Belum diisi' }}</dd></div><div><dt>Telepon</dt><dd>{{ $row->phone ?: 'Belum diisi' }}</dd></div></dl>@endif
</section>
@endsection
