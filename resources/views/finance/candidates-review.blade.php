@extends('layouts.app')
@section('title', 'Tinjau pilihan dedosan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ $event->reference }}</span><h1>Tinjau pilihan dedosan</h1><p class="muted">Periksa daftar sebelum menetapkan. Isian ini belum menerbitkan tagihan.</p></div></div>
@include('finance.nav')
@if($errors->has('selection'))
<section class="card form-width mb-6"><h2>Beberapa pilihan bermasalah</h2><ul class="mt-3 list-disc pl-5">@foreach($errors->get('selection') as $message)<li class="field-error">{{ $message }}</li>@endforeach</ul><p class="help mt-3">Perbaiki di bawah, lalu terbitkan ulang. Pilihan Anda masih tersimpan.</p></section>
@endif
@if(!empty($invalid))
<section class="card form-width mb-6"><h2>Tidak dapat disertakan ({{ count($invalid) }})</h2><ul class="mt-3 list-disc pl-5">@foreach($invalid as $row)<li>{{ $row['name'] }} — <span class="muted">{{ $row['reason'] }}</span></li>@endforeach</ul></section>
@endif
<form method="post" action="/admin/finance/events/{{ $event->id }}/subjects/bulk" class="form-stack form-width">@csrf
@foreach($valid as $entry)<input type="hidden" name="krama_ids[]" value="{{ $entry['id'] }}">@endforeach

@if(!empty($conflicts))
<section class="card"><h2>Konflik satu KK, lebih dari satu warga ({{ count($conflicts) }})</h2><p class="help mt-2">Pilih tepat satu penanggung jawab untuk setiap KK berikut.</p>
@foreach($conflicts as $i => $conflict)
<fieldset class="mt-4 border-t pt-4"><legend class="font-semibold">KK {{ $conflict['household_code'] }}</legend>
@foreach($conflict['options'] as $option)
<label class="check-option"><input type="radio" name="conflicts[{{ $i }}]" value="{{ $option['id'] }}" required @checked((string) old('conflicts.'.$i) === (string) $option['id'])> {{ $option['name'] }}</label>
@endforeach
</fieldset>
@endforeach
</section>
@endif

<section class="card"><h2>Ringkasan</h2><p class="mt-3">{{ count($valid) }} warga siap ditetapkan tanpa konflik @if(!empty($conflicts)) · {{ count($conflicts) }} KK menunggu pemilihan penanggung jawab @endif.</p>
<label class="mt-4">Alasan dedosan (berlaku untuk semua yang dipilih)<textarea name="reason" maxlength="1000" minlength="5" required rows="3">{{ old('reason') }}</textarea></label>
</section>
<div class="flex flex-wrap gap-3"><button class="button" @disabled(empty($valid) && empty($conflicts))>Tetapkan dedosan untuk semua yang dipilih</button><a class="button-secondary" href="/admin/finance/events/{{ $event->id }}/candidates">← Ubah pilihan</a></div>
</form>
@endsection
