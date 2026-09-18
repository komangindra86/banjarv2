@extends('layouts.app')
@section('title', 'Tetapkan iuran/patus')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ \App\Modules\Finance\FinanceManager::KINDS[$type->kind] }}</span><h1>Tetapkan iuran/patus</h1><p class="muted">{{ $type->name }} · nominal standar {{ \App\Modules\Finance\FinanceManager::money($type->default_amount) }}</p></div></div>
@include('finance.nav')
<form class="card form-stack form-width" method="post" action="/admin/finance/events">@csrf<input type="hidden" name="billing_type_id" value="{{ $type->id }}">
<label>Nama kejadian / periode<input name="title" maxlength="150" required value="{{ old('title') }}" placeholder="Iuran September 2026"></label>
<label>Referensi event<input name="reference" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._\/\-]*" required value="{{ old('reference') }}" placeholder="IURAN-2026-09"><span class="help">Kode unik untuk kejadian ini. Gunakan kode yang sama saat memeriksa tagihan agar kejadian tidak dicatat dua kali.</span></label>
<label>Jatuh tempo (opsional)<input name="due_date" type="date" value="{{ old('due_date') }}"></label>
@if($type->kind !== 'DEDOSAN')
<label>Target KK<select name="target" id="finance-target"><option value="ALL_ACTIVE" @selected(old('target') !== 'UNIT')>{{ $scoped ? 'Semua KK aktif dalam wilayah akses saya' : 'Semua KK aktif' }}</option><option value="UNIT" @selected(old('target') === 'UNIT')>KK pada satu unit</option></select></label>
<label id="finance-unit" @if(old('target') !== 'UNIT') hidden @endif>Unit<select name="unit_id">
<option value="">Pilih unit</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string)old('unit_id') === (string)$unit->id)>{{ $unit->name }}</option>@endforeach
</select><span class="help">Mengambil KK yang terdaftar langsung pada unit ini, tanpa unit turunan.@if($scoped) Hanya unit dalam wilayah akses Anda yang ditampilkan.@endif</span></label>
@if($scoped && $units->isEmpty())<p class="notice">Anda belum memiliki unit aktif dalam wilayah akses. Hubungi Admin Banjar untuk mengatur penugasan unit.</p>@endif
@else<p class="notice">Setelah menyimpan draf, pilih beberapa warga sekaligus lewat checkbox untuk ditetapkan dedosan. Maksimal satu individu per KK.</p>@endif
<details @if(old('amount')) open @endif><summary class="cursor-pointer py-3 font-semibold">Gunakan nominal khusus</summary>
<x-rupiah-input name="amount" :value="old('amount')" label="Nominal event (Rp)" :placeholder="'Rp '.number_format($type->default_amount, 0, ',', '.')" help="Kosongkan untuk memakai nominal standar." />
</details>
<button class="button">Simpan draf & periksa</button></form>
@endsection
