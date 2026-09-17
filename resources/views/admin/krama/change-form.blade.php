@extends('layouts.app')
@section('title', 'Ajukan perubahan')
@section('content')
<h1>Ajukan perubahan</h1><p class="muted mt-3 mb-6">{{ $row->name }} · Data diperbarui setelah pemeriksaan pengurus.</p>
@php($chosenField = old('field', request('field', 'name')))
<section class="card form-width"><form method="get" class="form-stack mb-6"><label>Data yang ingin diubah<select name="field">@foreach(\App\Modules\Krama\KramaManager::FIELDS as $field => $label)<option value="{{ $field }}" @selected($chosenField === $field)>{{ $label }}</option>@endforeach</select></label><button class="button-secondary">Pilih data</button></form>
@if(array_key_exists($chosenField, \App\Modules\Krama\KramaManager::FIELDS))
<form class="form-stack" method="post" action="/admin/krama/people/{{ $row->id }}/change">@csrf<input type="hidden" name="version" value="{{ $row->version }}"><input type="hidden" name="field" value="{{ $chosenField }}">
<label>{{ \App\Modules\Krama\KramaManager::FIELDS[$chosenField] }} baru
@if($chosenField === 'status')<select name="value">@foreach(\App\Modules\Krama\KramaManager::STATUSES as $code => $label)<option value="{{ $code }}" @selected($row->status === $code)>{{ $label }}</option>@endforeach</select>
@else<input name="value" type="{{ $chosenField === 'birth_date' ? 'date' : ($chosenField === 'phone' ? 'tel' : 'text') }}" @if($chosenField === 'nik') inputmode="numeric" pattern="[0-9]{16}" maxlength="16" @elseif($chosenField === 'birth_date') max="{{ now()->format('Y-m-d') }}" @else maxlength="150" @endif @required($chosenField === 'name') autocomplete="off">@endif
</label>@if(in_array($chosenField, ['nik', 'phone', 'birth_date']))<p class="help">Kosongkan nilai baru bila ingin menghapus data ini.</p>@endif
<label>Alasan<textarea name="reason" required maxlength="1000" rows="3">{{ old('reason') }}</textarea></label><button class="button">Kirim pengajuan</button></form>@endif</section>
@endsection
