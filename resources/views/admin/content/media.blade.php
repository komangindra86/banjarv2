@extends('layouts.app')
@section('title', 'Pustaka media')
@section('content')
<a href="/admin/website" class="back-link">← Konten website</a>
<div class="page-heading"><div><span class="eyebrow">FOTO & BERKAS</span><h1>Pustaka media</h1><p class="muted">Berkas publik baru dapat diakses setelah dipasang pada profil atau konten terbit.</p></div></div>
<form method="post" action="/admin/media" enctype="multipart/form-data" class="card form-stack form-width mb-8" data-saving>@csrf
<label>Berkas<input name="file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required><span class="help">JPG, PNG, WebP, atau PDF. Maksimal 10 MB; foto maksimal 20 megapiksel.</span>@error('file')<span class="field-error">{{ $message }}</span>@enderror</label>
<x-editor-field name="visibility" label="Akses berkas" type="select" value="PRIVATE" :options="['PRIVATE'=>'Privat · hanya pengurus','PUBLIC'=>'Publik · dapat dilihat pengunjung saat terpasang']" />
<button class="button">Unggah berkas</button></form>
<div class="card-grid">@forelse($items as $item)<article class="card"><span class="badge {{ $item->visibility === 'PRIVATE' ? 'badge-muted' : '' }}">{{ $item->visibility === 'PUBLIC' ? 'Publik' : 'Privat' }}</span><h2 class="mt-4 break-words">{{ $item->name }}</h2><p class="muted mt-2">{{ $item->mime }} · {{ number_format($item->size / 1024, 0, ',', '.') }} KB</p><a class="back-link" href="/admin/media/{{ $item->id }}/download">Unduh berkas</a><form action="/admin/media/{{ $item->id }}" method="post" class="form-stack">@csrf @method('PATCH')<input type="hidden" name="visibility" value="{{ $item->visibility === 'PUBLIC' ? 'PRIVATE' : 'PUBLIC' }}"><button class="button-secondary">Jadikan {{ $item->visibility === 'PUBLIC' ? 'privat' : 'publik' }}</button></form></article>@empty<div class="card empty-state"><h2>Belum ada berkas</h2><p class="muted">Unggah foto atau dokumen pertama melalui formulir di atas.</p></div>@endforelse</div><div class="mt-6">{{ $items->links() }}</div>
@endsection
