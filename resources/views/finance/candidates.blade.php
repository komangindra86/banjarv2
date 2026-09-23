@extends('layouts.app')
@section('title', 'Pilih kepala keluarga untuk dedosan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ $event->reference }}</span><h1>Pilih kepala keluarga untuk dedosan</h1><p class="muted">Dedosan hanya dikenakan ke kepala keluarga, satu per KK. Centang beberapa kepala keluarga sekaligus; pilihan tersimpan walau Anda berpindah halaman atau mengubah pencarian.</p></div></div>
@include('finance.nav')
<form method="get" class="card form-stack form-width mb-4"><label>Cari nama<input name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
<label>Unit<select name="unit_id"><option value="">Semua unit dalam akses saya</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string)($filters['unit_id'] ?? '') === (string)$unit->id)>{{ $unit->name }}</option>@endforeach</select></label>
<button class="button-secondary">Terapkan pencarian</button></form>

<div class="card form-width mb-6 sticky top-4 z-10" data-candidate-picker data-event-url="/admin/finance/events/{{ $event->id }}">
    <p class="font-semibold" data-selection-summary>{{ $summary['count_people'] }} orang · {{ $summary['count_kk'] }} KK · total {{ \App\Modules\Finance\FinanceManager::money($summary['total_amount']) }}</p>
    <div class="flex flex-wrap gap-3 mt-4">
        <button type="button" class="button-secondary" data-select-page>Pilih semua di halaman ini</button>
        <button type="button" class="button-secondary" data-select-filtered>Pilih semua hasil filter</button>
        <button type="button" class="button-quiet" data-clear-selection>Bersihkan pilihan</button>
        <a class="button" href="/admin/finance/events/{{ $event->id }}/candidates/review">Tinjau pilihan →</a>
    </div>
</div>

@if($people->isEmpty())
<div class="card empty-state"><h2>Tidak ada kepala keluarga yang cocok</h2><p class="muted">Ubah kata kunci atau filter unit. KK tanpa kepala keluarga yang ditetapkan tidak muncul di sini.</p></div>
@else
<div class="card candidate-list">
@foreach($people as $person)
@php($taken = $existingKk->contains($person->household_id))
<label class="check-option {{ $taken ? 'opacity-60' : '' }}">
    <input type="checkbox" data-candidate-checkbox value="{{ $person->id }}" @checked(in_array($person->id, $selected)) @disabled($taken)>
    <span class="min-w-0"><strong>{{ $person->name }}</strong><span class="muted block">KK {{ $person->household_code }}</span></span>
    @if($taken)<span class="badge ml-auto">Sudah dipilih di event ini</span>@endif
</label>
@endforeach
</div>
@endif
<div class="mt-6">{{ $people->links() }}</div>
@endsection
