@extends('layouts.app')
@section('title', 'Semua foto & berkas')
@section('content')
<a href="/admin/website" class="back-link">← Konten website</a>
<div class="page-heading"><div><span class="eyebrow">FOTO & BERKAS</span><h1>Semua foto &amp; berkas</h1><p class="muted">Semua foto dan berkas yang pernah diunggah. Pengunjung website hanya melihat foto yang dipasang pada konten terbit.</p></div></div>
<form method="post" action="/admin/media" enctype="multipart/form-data" class="card form-stack form-width mb-8" data-saving>@csrf
<x-photo-picker name="files" label="Unggah foto atau PDF" :files="collect()" multiple documents button="Pilih foto atau PDF" help="Bisa pilih beberapa sekaligus. Foto juga bisa langsung dipilih dari formulir konten dan profil." />
<button class="button">Unggah</button></form>
<div class="media-library">@forelse($items as $item)<figure class="photo-tile">@if(str_starts_with($item->mime, 'image/'))<img src="/admin/media/{{ $item->id }}/download?lihat=1" alt="" loading="lazy">@else<span class="photo-doc">PDF</span>@endif<figcaption>{{ $item->name }}</figcaption><a class="photo-download" href="/admin/media/{{ $item->id }}/download">Unduh · {{ number_format($item->size / 1024, 0, ',', '.') }} KB</a></figure>@empty<div class="card empty-state"><h2>Belum ada foto</h2><p class="muted">Foto yang dipilih di formulir konten dan profil otomatis tersimpan di sini.</p></div>@endforelse</div><div class="mt-6">{{ $items->links() }}</div>
@endsection
