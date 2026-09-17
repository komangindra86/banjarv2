@php $at = \Carbon\Carbon::parse($activity->starts_at)->timezone('Asia/Makassar'); @endphp
<a class="back-link" href="/admin/activities">← Kegiatan</a>
<div class="page-heading"><div><span class="eyebrow">{{ \App\Modules\Activity\ActivityManager::KINDS[$activity->kind] }}{{ $activity->visibility === 'INTERNAL' ? ' · INTERNAL' : '' }}</span><h1 class="break-words">{{ $activity->title }}</h1><p class="muted">{{ $at->translatedFormat('l, d F Y · H:i') }} WITA{{ $activity->location ? ' · '.$activity->location : '' }}</p></div>
<span class="badge {{ $activity->attendance_status === 'VERIFIED' ? '' : 'badge-muted' }}">{{ ['NONE' => 'Tanpa presensi', 'DRAFT' => 'Presensi berjalan', 'VERIFIED' => 'Presensi terverifikasi'][$activity->attendance_status] }}</span></div>
@include('activity.nav')
