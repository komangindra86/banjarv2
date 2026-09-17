<nav class="inline-links mb-6" aria-label="Kegiatan">
@foreach(['' => 'Ringkasan', '/attendance' => 'Presensi', '/dedosan' => 'Calon dedosan', '/minutes' => 'Notulen'] as $suffix => $label)
@if($suffix !== '/dedosan' || $activity->attendance_status === 'VERIFIED')<a href="/admin/activities/{{ $activity->id }}{{ $suffix }}" @if(request()->getPathInfo() === '/admin/activities/'.$activity->id.$suffix) aria-current="page" @endif>{{ $label }}</a>@endif
@endforeach
</nav>
