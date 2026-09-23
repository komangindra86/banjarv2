@php($heading = ['IURAN' => 'Tetapkan iuran', 'PATUS' => 'Tetapkan patus', 'DEDOSAN' => 'Tetapkan dedosan', 'OTHER' => 'Tetapkan tagihan lainnya'][$type->kind])
@php($periodLabel = $type->kind === 'PATUS' ? 'Nama kejadian' : 'Nama kejadian / periode')
@extends('layouts.app')
@section('title', $heading)
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ \App\Modules\Finance\FinanceManager::KINDS[$type->kind] }}</span><h1>{{ $heading }}</h1><p class="muted">{{ $type->name }} · nominal standar {{ \App\Modules\Finance\FinanceManager::money($type->default_amount) }}</p></div></div>
@include('finance.nav')
@if($type->kind === 'PATUS')<p class="notice">Patus dikenakan sekali ke semua KK (kepala keluarga) setiap ada kematian warga — nominalnya bisa berbeda tiap kejadian, tidak seperti iuran bulanan.</p>@endif
<form class="card form-stack form-width" method="post" action="/admin/finance/events">@csrf<input type="hidden" name="billing_type_id" value="{{ $type->id }}">
<label>{{ $periodLabel }}<input name="title" maxlength="150" required value="{{ old('title', $suggestedTitle) }}"></label>
<label>Tanggal penetapan<input name="event_date" type="date" required value="{{ old('event_date', $suggestedEventDate) }}"><span class="help">Tanggal kejadian sebenarnya (mis. tanggal kematian, tanggal kegiatan, atau periode iuran ini berlaku) — bukan jatuh tempo pembayaran.</span></label>
<details @if(old('reference')) open @endif><summary class="cursor-pointer py-3 font-semibold">Gunakan kode referensi khusus</summary>
<label>Referensi event<input name="reference" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._\/\-]*" required value="{{ old('reference', $suggestedReference) }}"><span class="help">Terisi otomatis. Kode ini hanya dipakai sistem untuk mencegah kejadian tercatat dua kali — biarkan saja kecuali Anda ingin kode sendiri.</span></label>
</details>
<details @if(old('due_date')) open @endif><summary class="cursor-pointer py-3 font-semibold">Gunakan jatuh tempo (opsional)</summary>
<label>Jatuh tempo<input name="due_date" type="date" value="{{ old('due_date') }}"><span class="help">Kebanyakan Banjar tidak memakai jatuh tempo formal — kosongkan bila tidak relevan.</span></label>
</details>
@if($type->kind !== 'DEDOSAN')
<label>Target KK<select name="target" id="finance-target"><option value="ALL_ACTIVE" @selected(old('target') !== 'UNIT')>{{ $scoped ? 'Semua KK aktif dalam wilayah akses saya' : 'Semua KK aktif' }}</option><option value="UNIT" @selected(old('target') === 'UNIT')>KK pada satu unit</option></select><span class="help">Tagihan dikenakan ke kepala keluarga masing-masing KK. KK yang belum memiliki kepala keluarga ditetapkan tidak ikut tertagih.</span></label>
<label id="finance-unit" @if(old('target') !== 'UNIT') hidden @endif>Unit<select name="unit_id">
<option value="">Pilih unit</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string)old('unit_id') === (string)$unit->id)>{{ $unit->name }}</option>@endforeach
</select><span class="help">Mengambil KK yang terdaftar langsung pada unit ini, tanpa unit turunan.@if($scoped) Hanya unit dalam wilayah akses Anda yang ditampilkan.@endif</span></label>
@if($scoped && $units->isEmpty())<p class="notice">Anda belum memiliki unit aktif dalam wilayah akses. Hubungi Admin Banjar untuk mengatur penugasan unit.</p>@endif
@else<p class="notice">Setelah menyimpan draf, pilih beberapa kepala keluarga sekaligus lewat checkbox untuk ditetapkan dedosan. Maksimal satu kepala keluarga per KK.</p>@endif
<details @if(old('amount')) open @endif><summary class="cursor-pointer py-3 font-semibold">Gunakan nominal khusus</summary>
<x-rupiah-input name="amount" :value="old('amount')" label="Nominal event (Rp)" :placeholder="'Rp '.number_format($type->default_amount, 0, ',', '.')" help="Kosongkan untuk memakai nominal standar." />
</details>
<button class="button">Simpan draf & periksa</button></form>
@endsection
