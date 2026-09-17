<nav class="inline-links mb-6" aria-label="Data krama">
@if($access->tenant(auth()->user(), 'krama.self'))<a href="/portal/beranda">← Beranda</a>@endif
@if($access->tenant(auth()->user(), 'krama.view'))<a href="/admin/krama">Keluarga & krama</a>@endif
<a href="/admin/krama/changes">Pengajuan perubahan</a>
@if($access->tenant(auth()->user(), 'units.manage'))<a href="/admin/krama/units">Unit & aturan</a>@endif
@if($access->tenant(auth()->user(), 'krama.self'))<a href="/admin/krama/mine">Data saya</a>@endif
</nav>
