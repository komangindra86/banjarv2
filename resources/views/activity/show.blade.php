@extends('layouts.app')
@section('title', $activity->title)
@section('content')
@include('activity.header')
@php $types = \App\Modules\Activity\ActivityManager::TARGETS; $locked = $activity->attendance_status === 'VERIFIED'; @endphp
@if($summary['TOTAL'])<div class="stats-grid stats-grid-4 mb-6">@foreach(['TOTAL' => 'Peserta', 'HADIR' => 'Hadir', 'IZIN' => 'Izin/sakit', 'TIDAK_HADIR' => 'Tidak hadir'] as $key => $label)<section class="card"><h2>{{ $label }}</h2><p class="stat-number">{{ $key === 'IZIN' ? $summary['IZIN'] + $summary['SAKIT'] : $summary[$key] }}</p></section>@endforeach</div>@endif
<div class="grid gap-5 lg:grid-cols-2">
<div class="grid gap-5 self-start">
<section class="card"><h2>Informasi kegiatan</h2><p class="muted mt-2">Judul, waktu, tempat, dan foto diubah melalui tombol di bawah.</p>@if($canManage)<a class="button-secondary mt-4" href="/admin/content/agenda/{{ $activity->id }}/edit">Edit kegiatan</a>@endif</section>
@if($canManage)
<form class="card form-stack" method="post" action="/admin/activities/{{ $activity->id }}/settings">@csrf @method('PATCH')<h2>Pengaturan presensi</h2>
<label>Jenis kegiatan<select name="kind" @disabled($locked)>@foreach(\App\Modules\Activity\ActivityManager::KINDS as $k => $l)<option value="{{ $k }}" @selected($activity->kind === $k)>{{ $l }}</option>@endforeach</select></label>
<label>Tampil di website<select name="visibility" @disabled($locked)><option value="PUBLIC" @selected($activity->visibility === 'PUBLIC')>Ya, agenda publik</option><option value="INTERNAL" @selected($activity->visibility === 'INTERNAL')>Tidak, hanya pengurus & peserta</option></select></label>
<label>Basis presensi<select name="attendance_basis" @disabled($locked)><option value="">Tanpa presensi</option><option value="HOUSEHOLD" @selected($activity->attendance_basis === 'HOUSEHOLD')>Per KK — satu anggota mewakili KK</option><option value="PERSON" @selected($activity->attendance_basis === 'PERSON')>Per individu — wajib hadir sendiri</option></select><span class="help">Tidak dapat diganti setelah daftar peserta dibuat.</span></label>
<label>Perwakilan KK<select name="allow_representative" @disabled($locked)><option value="1" @selected($activity->allow_representative)>Anggota KK mana pun boleh mewakili</option><option value="0" @selected(!$activity->allow_representative)>Harus kepala keluarga</option></select><span class="help">Hanya berlaku untuk presensi per KK.</span></label>
@unless($locked)<button class="button">Simpan pengaturan</button>@endunless</form>
@endif
</div>
<div class="grid gap-5 self-start">
<section class="card"><h2>Target peserta</h2><p class="muted mt-2">Gabungan semua target; individu atau KK yang masuk dari beberapa target dihitung satu kali.</p>
<ul class="grid gap-2 mt-4">@forelse($targets as $t)<li class="target-row"><span>{{ $types[$t->target_type] }}@if($t->unit_id): <strong>{{ $unitNames[$t->unit_id] ?? '—' }}</strong>{{ $t->include_descendants ? ' + sub-unit' : '' }}@elseif($t->role_id): <strong>{{ $roleNames[$t->role_id] ?? '—' }}</strong>@elseif($t->krama_id): <strong>{{ $kramaNames[$t->krama_id] ?? '—' }}</strong>@endif</span>
@if($canManage && !$locked)<form method="post" action="/admin/activities/{{ $activity->id }}/targets/{{ $t->id }}">@csrf @method('DELETE')<button class="button-quiet" aria-label="Hapus target">Hapus</button></form>@endif</li>@empty<li class="muted">Belum ada target.</li>@endforelse</ul>
@if($canManage && !$locked)
<details class="mt-5" @if($targets->isEmpty()) open @endif><summary class="cursor-pointer font-semibold py-2">+ Tambah target</summary>
<div class="grid gap-3 mt-3">
<form method="post" action="/admin/activities/{{ $activity->id }}/targets" class="target-quick">@csrf<input type="hidden" name="target_type" value="ALL_HOUSEHOLDS"><button class="button-secondary">Semua KK aktif</button></form>
<form method="post" action="/admin/activities/{{ $activity->id }}/targets" class="target-quick">@csrf<input type="hidden" name="target_type" value="ALL_KRAMA"><button class="button-secondary">Semua krama aktif</button></form>
@if($units->isNotEmpty())<form method="post" action="/admin/activities/{{ $activity->id }}/targets" class="form-stack border-t pt-4">@csrf<input type="hidden" name="target_type" value="ORGANIZATIONAL_UNIT"><label>Unit<select name="unit_id" required>@foreach($units as $u)<option value="{{ $u->id }}">{{ $u->name }} · {{ $u->type }}</option>@endforeach</select></label><label class="check-option"><input type="checkbox" name="include_descendants" value="1"><span>Termasuk sub-unit (misal Regu di bawah Tempekan)</span></label><button class="button-secondary">Tambah unit</button></form>@endif
<form method="post" action="/admin/activities/{{ $activity->id }}/targets" class="form-stack border-t pt-4">@csrf<input type="hidden" name="target_type" value="ROLE"><label>Pengurus dengan peran<select name="role_id" required>@foreach($roles as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select></label><button class="button-secondary">Tambah peran</button></form>
<form method="get" class="form-stack border-t pt-4"><label>Cari krama tertentu<input name="cari" value="{{ $search }}" maxlength="100" placeholder="Nama krama"></label><button class="button-secondary">Cari</button></form>
@foreach($people as $p)<form method="post" action="/admin/activities/{{ $activity->id }}/targets" class="target-row">@csrf<input type="hidden" name="target_type" value="SELECTED_KRAMA"><input type="hidden" name="krama_id" value="{{ $p->id }}"><span>{{ $p->name }} <small class="muted">· {{ $p->code }}</small></span><button class="button-quiet">Tambah</button></form>@endforeach
@if($search !== '' && $people->isEmpty())<p class="muted">Tidak ada krama aktif yang cocok.</p>@endif
</div></details>
<form class="mt-5 border-t pt-5" method="post" action="/admin/activities/{{ $activity->id }}/participants">@csrf<p class="help mb-3">{{ $activity->participants_generated_at ? 'Perbarui setelah target atau data krama berubah. Catatan presensi yang sudah ada tetap tersimpan.' : 'Buat daftar peserta dari target di atas.' }}</p><button class="button" @disabled(!$activity->attendance_basis || $targets->isEmpty())>{{ $activity->participants_generated_at ? 'Perbarui daftar peserta' : 'Buat daftar peserta' }}</button>@if(!$activity->attendance_basis)<p class="help mt-2">Pilih basis presensi terlebih dahulu.</p>@endif</form>
@endif
@if($summary['TOTAL'])<a class="button-secondary mt-5" href="/admin/activities/{{ $activity->id }}/attendance">Buka presensi →</a>@endif
</section>
</div></div>
@endsection
