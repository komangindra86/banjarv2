@extends('layouts.app')
@section('title', 'Konten website')
@section('content')
<div class="page-heading"><div><span class="eyebrow">INFORMASI UNTUK KRAMA</span><h1>Konten website</h1><p class="muted">Kelola pengumuman, berita, dan informasi resmi Banjar.</p></div><a href="/admin/content/pengumuman/create" class="button">+ Buat pengumuman</a></div>
<div class="card-grid">
@foreach(\App\Modules\Website\ContentCatalog::TYPES as $type => $definition)
@if($type !== 'agenda')<a href="/admin/content/{{ $type }}" class="card tenant-card"><h2>{{ $definition['label'] }}</h2>@if($type === 'dokumen')<p class="muted mt-2">Awig-awig, pararem, laporan, dan formulir.</p>@endif<div class="card-bottom">Kelola {{ strtolower($definition['label']) }} <span>→</span></div></a>@endif
@endforeach
<a href="/admin/profile" class="card tenant-card"><h2>Profil & kontak</h2><p class="muted mt-2">Cerita Banjar, logo, sampul, dan lokasi.</p></a>
<a href="/admin/media" class="card tenant-card"><h2>Semua foto &amp; berkas</h2><p class="muted mt-2">Foto dari semua formulir otomatis tersimpan di sini.</p></a>
<a href="/admin/qr" class="card tenant-card"><h2>QR website</h2><p class="muted mt-2">Unduh QR untuk papan informasi dan surat.</p></a>
</div>
<a href="/" class="back-link mt-6">Lihat website publik →</a>
@endsection
