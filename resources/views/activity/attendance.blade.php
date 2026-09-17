@extends('layouts.app')
@section('title', 'Presensi · '.$activity->title)
@section('content')
@include('activity.header')
@php $labels = \App\Modules\Activity\ActivityManager::STATUSES; $editable = $canRecord && $activity->attendance_status === 'DRAFT'; $started = \Carbon\Carbon::parse($activity->starts_at)->isPast(); @endphp
@if($activity->attendance_status === 'NONE')<div class="card empty-state"><h2>Presensi belum disiapkan</h2><p class="muted">Atur basis presensi dan target, lalu buat daftar peserta di halaman ringkasan.</p></div>@else
<div class="attendance-summary mb-5">@foreach(['BELUM_DIVERIFIKASI', 'HADIR', 'IZIN', 'SAKIT', 'TIDAK_HADIR'] as $key)<a href="?status={{ $key }}" class="status-chip status-{{ strtolower($key) }}" @if($status === $key) aria-current="page" @endif><strong>{{ $summary[$key] }}</strong><span>{{ $labels[$key] }}</span></a>@endforeach<a href="?" class="status-chip" @if(!$status) aria-current="page" @endif><strong>{{ $summary['TOTAL'] }}</strong><span>Semua</span></a></div>
<form class="search-form" method="get">@if($status)<input type="hidden" name="status" value="{{ $status }}">@endif<label class="grow"><span class="sr-only">Cari</span><input name="cari" value="{{ $search }}" placeholder="Cari nama atau kode KK…"></label><button class="button-secondary">Cari</button></form>
<div class="grid gap-3">@forelse($rows as $row)
<section class="card attendance-card status-{{ strtolower($row->status) }}">
<div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><h2 class="break-words">{{ $row->basis === 'PERSON' ? $row->person_name : 'KK '.$row->household_code }}</h2><p class="muted">{{ $row->basis === 'PERSON' ? 'KK '.$row->household_code : $members->get($row->household_id, collect())->count().' anggota aktif' }}@if($row->source === 'SELF') · pemberitahuan krama @endif</p>@if($row->note)<p class="help mt-1 break-words">Catatan: {{ $row->note }}</p>@endif</div><span class="badge status-badge">{{ $labels[$row->status] }}</span></div>
@if($editable)
<form method="post" action="/admin/activities/{{ $activity->id }}/attendance/{{ $row->id }}" class="mt-4">@csrf
@if($row->basis === 'HOUSEHOLD')<label class="mb-3">Yang hadir mewakili KK<select name="attending_krama_id">@foreach($members->get($row->household_id, collect()) as $m)<option value="{{ $m->id }}" @selected((int)($row->attending_krama_id ?: $row->head_krama_id) === $m->id)>{{ $m->name }}{{ $m->id === (int)$row->head_krama_id ? ' (kepala keluarga)' : '' }}</option>@endforeach</select></label>@endif
<div class="status-buttons">@foreach(['HADIR', 'IZIN', 'SAKIT', 'TIDAK_HADIR'] as $key)<button name="status" value="{{ $key }}" class="status-button status-{{ strtolower($key) }}" @if($row->status === $key) aria-pressed="true" @endif>{{ $labels[$key] }}</button>@endforeach</div>
<details class="mt-3"><summary class="cursor-pointer help">Tambah catatan / kosongkan</summary><label class="mt-2">Catatan<input name="note" maxlength="500" value="{{ $row->note }}"></label><button name="status" value="BELUM_DIVERIFIKASI" class="button-quiet mt-2">Kosongkan catatan presensi</button></details>
</form>@elseif($row->attending_krama_id)<p class="help mt-2">Hadir: {{ optional($members->get($row->household_id, collect())->firstWhere('id', $row->attending_krama_id))->name }}</p>@endif
</section>
@empty<div class="card empty-state"><h2>Tidak ada peserta</h2><p class="muted">Tidak ada peserta yang cocok dengan filter atau berada dalam akses Anda.</p></div>@endforelse</div>
<div class="mt-6">{{ $rows->links() }}</div>
@if($editable && $started && $summary['BELUM_DIVERIFIKASI'])<form class="card form-stack form-width mt-6" method="post" action="/admin/activities/{{ $activity->id }}/attendance/remaining" data-confirm="Tandai semua yang belum dicatat sebagai tidak hadir?">@csrf<h2>Selesaikan pencatatan</h2><p class="muted">{{ $summary['BELUM_DIVERIFIKASI'] }} peserta dalam akses Anda belum dicatat.</p><button class="button-secondary">Tandai sisanya tidak hadir</button></form>@endif
@if($canVerify && $activity->attendance_status === 'DRAFT')<form class="card form-stack form-width mt-6" method="post" action="/admin/activities/{{ $activity->id }}/verify">@csrf<h2>Verifikasi presensi</h2><p class="muted">Setelah diverifikasi, presensi dikunci. KK dengan status tidak hadir menjadi <strong>calon</strong> dedosan untuk diperiksa Petengen — belum menjadi tagihan.</p>@error('verify')<p class="field-error">{{ $message }}</p>@enderror<button class="button" @disabled(!$started)>Verifikasi & kunci presensi</button>@if(!$started)<p class="help">Tersedia setelah kegiatan dimulai.</p>@endif</form>@endif
@if($activity->attendance_status === 'VERIFIED')<p class="notice mt-6">Presensi diverifikasi {{ \Carbon\Carbon::parse($activity->verified_at)->timezone('Asia/Makassar')->translatedFormat('d M Y H:i') }} WITA dan tidak dapat diubah.</p>@endif
@endif
@endsection
