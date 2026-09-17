@extends('layouts.app')
@section('title', 'Kegiatan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">KEGIATAN BANJAR</span><h1>Kegiatan & presensi</h1><p class="muted">Paruman, ngayah, gotong royong, dan upacara memakai agenda yang sama dengan website.</p></div>@if($canManage)<a class="button" href="/admin/content/agenda/create">+ Buat kegiatan</a>@endif</div>
<nav class="inline-links mb-6" aria-label="Periode"><a href="/admin/activities" @if($upcoming) aria-current="page" @endif>Mendatang</a><a href="/admin/activities?periode=lalu" @if(!$upcoming) aria-current="page" @endif>Sudah lewat</a></nav>
<div class="grid gap-3">@forelse($activities as $a)@php $c = $counts[$a->id] ?? null; $at = \Carbon\Carbon::parse($a->starts_at)->timezone('Asia/Makassar'); @endphp
<a class="card activity-row" href="/admin/activities/{{ $a->id }}"><time class="activity-date" datetime="{{ $a->starts_at }}"><strong>{{ $at->format('d') }}</strong><span>{{ $at->translatedFormat('M') }}</span></time>
<span class="min-w-0"><strong class="block break-words">{{ $a->title }}</strong><small class="block muted mt-1">{{ \App\Modules\Activity\ActivityManager::KINDS[$a->kind] }} · {{ $at->format('H:i') }} WITA{{ $a->location ? ' · '.$a->location : '' }}</small>
@if($c)<small class="block mt-2">{{ $c->hadir }} hadir dari {{ $c->total }} peserta{{ $c->belum ? ' · '.$c->belum.' belum dicatat' : '' }}</small>@endif</span>
<span class="badge {{ $a->attendance_status === 'VERIFIED' ? '' : 'badge-muted' }}">{{ ['NONE' => $a->status === 'PUBLISHED' ? 'Agenda' : 'Draf', 'DRAFT' => 'Presensi', 'VERIFIED' => 'Terverifikasi'][$a->attendance_status] }}</span></a>
@empty<div class="card empty-state"><h2>Belum ada kegiatan {{ $upcoming ? 'mendatang' : 'sebelumnya' }}</h2><p class="muted">Buat kegiatan melalui agenda, lalu atur target dan presensi dari sini.</p></div>@endforelse</div>
<div class="mt-6">{{ $activities->links() }}</div>
@endsection
