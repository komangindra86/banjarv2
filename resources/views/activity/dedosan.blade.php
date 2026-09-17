@extends('layouts.app')
@section('title', 'Calon dedosan · '.$activity->title)
@section('content')
@include('activity.header')
@php $open = $canDedosan && !$activity->dedosan_billing_event_id; $pending = $candidates->where('status', 'PENDING')->count(); $selected = $candidates->where('status', 'SELECTED')->count(); @endphp
<p class="notice">Ketidakhadiran tidak otomatis menjadi dedosan. Pilih satu individu penanggung jawab per KK atau bebaskan dengan alasan, lalu buat event dedosan untuk diperiksa di Keuangan.</p>
@if($activity->dedosan_billing_event_id)<section class="card mb-6"><h2>Event dedosan sudah dibuat</h2><p class="muted mt-2">Persetujuan dan penerbitan dilanjutkan di modul Keuangan.</p>@if($canDedosan)<a class="button-secondary mt-4" href="/admin/finance/events/{{ $activity->dedosan_billing_event_id }}">Buka event dedosan →</a>@endif</section>@endif
<div class="grid gap-3">@forelse($candidates as $c)
<section class="card">
<div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><h2>KK {{ $c->household_code }}</h2><p class="muted">{{ $c->absent_count }} slot tidak hadir @if($absentees->has($c->household_id)) · {{ $absentees[$c->household_id]->pluck('name')->implode(', ') }} @endif</p>@if($c->note)<p class="help mt-1 break-words">Catatan: {{ $c->note }}</p>@endif</div>
<span class="badge {{ in_array($c->status, ['SELECTED', 'BILLED']) ? '' : 'badge-muted' }}">{{ ['PENDING' => 'Perlu diputuskan', 'SELECTED' => 'Dipilih: '.($names[$c->responsible_krama_id] ?? ''), 'DISMISSED' => 'Dibebaskan', 'BILLED' => 'Masuk event: '.($names[$c->responsible_krama_id] ?? '')][$c->status] }}</span></div>
@if($open && $c->status !== 'BILLED')
<details class="mt-4" @if($c->status === 'PENDING') open @endif><summary class="cursor-pointer font-semibold py-2">{{ $c->status === 'PENDING' ? 'Putuskan' : 'Ubah keputusan' }}</summary>
<form class="form-stack mt-3" method="post" action="/admin/activities/{{ $activity->id }}/dedosan/{{ $c->id }}">@csrf
<label>Individu penanggung jawab<select name="responsible_krama_id">@foreach($members->get($c->household_id, collect()) as $m)<option value="{{ $m->id }}" @selected((int)($c->responsible_krama_id ?: $c->suggested_krama_id) === $m->id)>{{ $m->name }}{{ $m->id === (int)$c->suggested_krama_id ? ' (disarankan)' : '' }}</option>@endforeach</select></label>
<label>Catatan / alasan<input name="note" maxlength="500" value="{{ $c->note }}"><span class="help">Wajib jika dibebaskan.</span></label>
<div class="flex flex-wrap gap-3"><button class="button" name="decision" value="SELECT">Pilih untuk dedosan</button><button class="button-secondary" name="decision" value="DISMISS">Bebaskan</button>@if($c->status !== 'PENDING')<button class="button-quiet" name="decision" value="RESET">Kembalikan ke belum diputuskan</button>@endif</div>
</form></details>
@endif
</section>
@empty<div class="card empty-state"><h2>Tidak ada calon dedosan</h2><p class="muted">Semua peserta hadir atau berhalangan dengan izin.</p></div>@endforelse</div>
@if($open && $candidates->isNotEmpty())
<form class="card form-stack form-width mt-6" method="post" action="/admin/activities/{{ $activity->id }}/dedosan/event">@csrf<h2>Buat event dedosan</h2>
<p class="muted">{{ $selected }} individu dipilih{{ $pending ? ' · '.$pending.' KK belum diputuskan' : '' }}.</p>
@if($types->isEmpty())<p class="notice">Tambahkan jenis tagihan berkategori Dedosan di Keuangan → Jenis & aturan.</p>@else
<label>Jenis dedosan<select name="billing_type_id">@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }} · {{ \App\Modules\Finance\FinanceManager::money($t->default_amount) }}</option>@endforeach</select></label>
<label>Nominal per individu (opsional)<input name="amount" inputmode="numeric" pattern="[1-9][0-9]{0,11}" placeholder="Ikuti nominal jenis"></label>
<label>Jatuh tempo (opsional)<input type="date" name="due_date"></label>
@error('billing_type_id')<p class="field-error">{{ $message }}</p>@enderror
<button class="button" @disabled($pending || !$selected)>Buat event sebagai draf</button>@endif
</form>
@endif
@endsection
