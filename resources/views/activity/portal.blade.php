@extends('layouts.app')
@section('title', 'Kegiatan saya')
@section('content')
<div class="page-heading"><div><span class="eyebrow">AKTIVITAS</span><h1>Kegiatan saya</h1><p class="muted">Kegiatan Banjar yang mencatat kehadiran Anda atau KK Anda.</p></div></div>
@if(!$self)<div class="card empty-state"><h2>Akun belum terhubung</h2><p class="muted">Hubungi Admin Banjar agar akun dihubungkan ke data krama Anda.</p></div>@else
<div class="grid gap-3">@forelse($rows as $r)@php $at = \Carbon\Carbon::parse($r->starts_at)->timezone('Asia/Makassar'); $end = \Carbon\Carbon::parse($r->ends_at ?? \Carbon\Carbon::parse($r->starts_at)->addHours(6)); @endphp
<section class="card"><div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><h2 class="break-words">{{ $r->title }}</h2><p class="muted">{{ $at->translatedFormat('l, d M Y · H:i') }} WITA{{ $r->location ? ' · '.$r->location : '' }}</p><p class="help mt-1">{{ $r->basis === 'HOUSEHOLD' ? 'Presensi per KK — satu anggota dapat mewakili' : 'Presensi per individu' }}</p></div><span class="badge {{ $r->status === 'HADIR' ? '' : 'badge-muted' }}">{{ \App\Modules\Activity\ActivityManager::STATUSES[$r->status] }}</span></div>
@if($r->attendance_status === 'DRAFT' && $end->isFuture() && $r->source !== 'STAFF')
<details class="mt-4"><summary class="cursor-pointer font-semibold py-2">{{ $r->source === 'SELF' ? 'Ubah pemberitahuan izin' : 'Tidak bisa hadir? Beri tahu pengurus' }}</summary>
<form class="form-stack mt-3" method="post" action="/aktivitas/{{ $r->id }}/izin">@csrf<label>Keterangan<select name="status"><option value="IZIN" @selected($r->status === 'IZIN')>Izin</option><option value="SAKIT" @selected($r->status === 'SAKIT')>Sakit</option></select></label><label>Alasan<input name="note" required minlength="5" maxlength="500" value="{{ $r->source === 'SELF' ? $r->note : '' }}"></label><button class="button">Kirim pemberitahuan</button></form></details>
@elseif($r->source === 'STAFF')<p class="help mt-3">Sudah dicatat pengurus.</p>@endif
</section>
@empty<div class="card empty-state"><h2>Belum ada kegiatan</h2><p class="muted">Kegiatan dengan presensi untuk Anda akan tampil di sini.</p></div>@endforelse</div>
@endif
@endsection
