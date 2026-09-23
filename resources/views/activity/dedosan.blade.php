@extends('layouts.app')
@section('title', 'Calon dedosan · '.$activity->title)
@section('content')
@include('activity.header')
@php $open = $canDedosan && !$activity->dedosan_billing_event_id; $pending = $candidates->where('status', 'PENDING')->count(); $selected = $candidates->where('status', 'SELECTED')->count(); @endphp
<p class="notice">Ketidakhadiran tidak otomatis menjadi dedosan. Dedosan hanya dikenakan ke kepala keluarga, satu per KK — pilih keputusan untuk beberapa KK sekaligus, lalu terapkan dalam satu kali kirim.</p>
@if($activity->dedosan_billing_event_id)<section class="card mb-6"><h2>Event dedosan sudah dibuat</h2><p class="muted mt-2">Persetujuan dan penerbitan dilanjutkan di modul Keuangan.</p>@if($canDedosan)<a class="button-secondary mt-4" href="/admin/finance/events/{{ $activity->dedosan_billing_event_id }}">Buka event dedosan →</a>@endif</section>@endif

@if($open && $candidates->isNotEmpty())
<form method="post" action="/admin/activities/{{ $activity->id }}/dedosan/bulk-decide">@csrf
@if($pending)
<div class="card mb-4"><button type="button" class="button" data-select-all-suggested>Pilih semua yang disarankan ({{ $pending }} KK)</button><p class="help mt-2">Menandai "Pilih untuk dedosan" untuk setiap KK yang belum diputuskan, memakai kepala keluarga yang disarankan. KK tanpa kepala keluarga dilewati — tinjau alasannya di bawah, lalu kirim satu kali.</p></div>
@endif
<div class="grid gap-3">
@foreach($candidates as $c)
<section class="card">
<div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><h2>KK {{ $c->household_code }}</h2><p class="muted">{{ $c->absent_count }} slot tidak hadir @if($absentees->has($c->household_id)) · {{ $absentees[$c->household_id]->pluck('name')->implode(', ') }} @endif</p>@if($c->note)<p class="help mt-1 break-words">Catatan: {{ $c->note }}</p>@endif</div>
<span class="badge {{ in_array($c->status, ['SELECTED', 'BILLED']) ? '' : 'badge-muted' }}">{{ ['PENDING' => 'Perlu diputuskan', 'SELECTED' => 'Dipilih: '.($names[$c->responsible_krama_id] ?? ''), 'DISMISSED' => 'Dibebaskan', 'BILLED' => 'Masuk event: '.($names[$c->responsible_krama_id] ?? '')][$c->status] }}</span></div>
@if($c->status !== 'BILLED')
<div class="form-stack mt-4" data-candidate-status="{{ $c->status }}">
<label>Keputusan untuk KK ini<select name="decisions[{{ $c->id }}][decision]">
<option value="">Tidak diubah</option>
<option value="SELECT">Pilih untuk dedosan</option>
<option value="DISMISS">Bebaskan</option>
@if($c->status !== 'PENDING')<option value="RESET">Kembalikan ke belum diputuskan</option>@endif
</select></label>
@if($members->get($c->household_id, collect())->isEmpty())
<p class="notice">KK ini belum memiliki kepala keluarga yang ditetapkan. Tetapkan kepala keluarga di halaman KK sebelum memilihnya untuk dedosan.</p>
@else
<label>Kepala keluarga (jika "Pilih untuk dedosan")<select name="decisions[{{ $c->id }}][responsible_krama_id]">@foreach($members->get($c->household_id, collect()) as $m)<option value="{{ $m->id }}" @selected((int)($c->responsible_krama_id ?: $c->suggested_krama_id) === $m->id)>{{ $m->name }}{{ $m->id === (int)$c->suggested_krama_id ? ' (disarankan)' : '' }}</option>@endforeach</select></label>
@endif
<label>Catatan / alasan (jika "Bebaskan")<input name="decisions[{{ $c->id }}][note]" maxlength="500" value="{{ $c->note }}"></label>
</div>
@endif
</section>
@endforeach
</div>
<button class="button mt-6">Terapkan keputusan yang dipilih</button>
</form>
@elseif($open)
<div class="card empty-state"><h2>Tidak ada calon dedosan</h2><p class="muted">Semua peserta hadir atau berhalangan dengan izin.</p></div>
@else
<div class="grid gap-3">@foreach($candidates as $c)
<section class="card"><h2>KK {{ $c->household_code }}</h2><span class="badge {{ in_array($c->status, ['SELECTED', 'BILLED']) ? '' : 'badge-muted' }}">{{ ['PENDING' => 'Perlu diputuskan', 'SELECTED' => 'Dipilih: '.($names[$c->responsible_krama_id] ?? ''), 'DISMISSED' => 'Dibebaskan', 'BILLED' => 'Masuk event: '.($names[$c->responsible_krama_id] ?? '')][$c->status] }}</span></section>
@endforeach</div>
@endif

@if($open && $candidates->isNotEmpty())
<form class="card form-stack form-width mt-6" method="post" action="/admin/activities/{{ $activity->id }}/dedosan/event">@csrf<h2>Buat event dedosan</h2>
<p class="muted">{{ $selected }} individu dipilih{{ $pending ? ' · '.$pending.' KK belum diputuskan' : '' }}.</p>
@if($types->isEmpty())<p class="notice">Tambahkan jenis tagihan berkategori Dedosan di Keuangan → Jenis & tagihan.</p>@else
<label>Jenis dedosan<select name="billing_type_id">@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }} · {{ \App\Modules\Finance\FinanceManager::money($t->default_amount) }}</option>@endforeach</select></label>
<x-rupiah-input name="amount" :value="old('amount')" label="Nominal per individu (opsional)" help="Kosongkan untuk mengikuti nominal jenis." />
<p class="help">Tanggal penetapan mengikuti tanggal kegiatan ini secara otomatis.</p>
@error('billing_type_id')<p class="field-error">{{ $message }}</p>@enderror
<button class="button" @disabled($pending || !$selected)>Buat event sebagai draf</button>@endif
</form>
@endif
@endsection
