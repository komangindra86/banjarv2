@extends('layouts.app')
@section('title', 'Buat event tagihan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ \App\Modules\Finance\FinanceManager::KINDS[$type->kind] }}</span><h1>Buat event tagihan</h1><p class="muted">{{ $type->name }} · nominal standar {{ \App\Modules\Finance\FinanceManager::money($type->default_amount) }}</p></div></div>
@include('finance.nav')
<form class="card form-stack form-width" method="post" action="/admin/finance/events">@csrf<input type="hidden" name="billing_type_id" value="{{ $type->id }}">
<label>Nama kejadian / periode<input name="title" maxlength="150" required value="{{ old('title') }}" placeholder="Iuran September 2026"></label>
<label>Referensi event<input name="reference" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._\/\-]*" required value="{{ old('reference') }}" placeholder="IURAN-2026-09"><span class="help">Kode unik untuk kejadian ini. Gunakan kode yang sama saat memeriksa tagihan agar kejadian tidak dicatat dua kali.</span></label>
<label>Jatuh tempo (opsional)<input name="due_date" type="date" value="{{ old('due_date') }}"></label>
@if($type->kind !== 'DEDOSAN')<label>Target KK<select name="target" id="finance-target"><option value="ALL_ACTIVE" @selected(old('target') !== 'UNIT')>Semua KK aktif</option><option value="UNIT" @selected(old('target') === 'UNIT')>KK pada satu unit</option></select></label><label id="finance-unit" @if(old('target') !== 'UNIT') hidden @endif>Unit<select name="unit_id"><option value="">Pilih unit</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string)old('unit_id') === (string)$unit->id)>{{ $unit->name }}</option>@endforeach</select><span class="help">Mengambil KK yang terdaftar langsung pada unit ini, tanpa unit turunan.</span></label>@else<p class="notice">Setelah menyimpan draf, cari individu dari master krama dan isi alasan dedosan. Maksimal satu individu per KK.</p>@endif
<details @if(old('amount')) open @endif><summary class="cursor-pointer py-3 font-semibold">Gunakan nominal khusus</summary><label class="mt-3">Nominal event (Rp)<input name="amount" inputmode="numeric" pattern="[1-9][0-9]{0,11}" value="{{ old('amount') }}" placeholder="{{ $type->default_amount }}"><span class="help">Kosongkan untuk memakai nominal standar.</span></label></details>
<button class="button">Simpan draf & periksa</button></form>
@endsection
