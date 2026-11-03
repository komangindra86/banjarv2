@extends('layouts.app')
@section('title', 'Profil & kontak')
@section('content')
@php($options = [''=>'Tanpa foto'] + $media->mapWithKeys(fn ($m)=>[$m->id=>$m->name.' · '.($m->visibility === 'PUBLIC' ? 'Publik' : 'Privat')])->all())
@php($templates = \App\Modules\Website\ContentManager::profileTemplates($currentBanjar->name))
<a class="back-link" href="/admin/website">← Konten website</a>
<div class="page-heading"><div><span class="eyebrow">IDENTITAS BANJAR</span><h1>Profil & kontak</h1><p class="muted">Informasi yang disimpan di sini langsung tampil di website publik.</p></div></div>
<form method="post" action="/admin/profile" class="card form-stack form-width" data-saving>@csrf @method('PATCH')
<x-editor-field name="tagline" label="Kalimat pembuka" :value="$profile->tagline ?? null" help="Contoh kalimat bisa dipakai lalu disesuaikan. Ganti semua [teks dalam kurung]." />
<button type="button" class="button-quiet template-fill" data-fill-template="#field-tagline" data-template="{{ $templates['tagline'] }}">Pakai contoh kalimat</button>
<x-editor-field name="description" label="Tentang Banjar" type="textarea" :value="$profile->description ?? null" />
<button type="button" class="button-quiet template-fill" data-fill-template="#field-description" data-template="{{ $templates['description'] }}">Pakai contoh kalimat</button>
<x-editor-field name="history" label="Sejarah Banjar" type="textarea" :value="$profile->history ?? null" />
<button type="button" class="button-quiet template-fill" data-fill-template="#field-history" data-template="{{ $templates['history'] }}">Pakai contoh kalimat</button>
<x-editor-field name="logo_id" label="Logo" type="select" :value="$profile->logo_id ?? null" :options="$options" help="Logo menggantikan tanda “B” di website, halaman pengurus, portal warga, dan ikon tab browser. Gunakan foto berstatus Publik; gambar persegi paling rapi." />
<button type="button" class="button-quiet" data-open-dialog="#logo-upload-dialog">+ Unggah logo baru</button>
<x-editor-field name="cover_id" label="Foto sampul" type="select" :value="$profile->cover_id ?? null" :options="$options" help="Foto berstatus Privat tidak terlihat pengunjung." />
<button type="button" class="button-quiet" data-open-dialog="#cover-upload-dialog">+ Unggah foto sampul baru</button>
<x-editor-field name="address" label="Alamat" type="textarea" :value="$profile->address ?? null" />
<x-editor-field name="phone" label="Telepon publik" type="tel" :value="$profile->phone ?? null" />
<x-editor-field name="email" label="Email publik" type="email" :value="$profile->email ?? null" />
<x-editor-field name="maps_url" label="Lokasi di Google Maps" type="url" :value="$profile->maps_url ?? null" help="Buka Google Maps, cari Bale Banjar, tekan Bagikan → Salin link, lalu tempel di sini." />
<button class="button">Simpan profil publik</button></form>
<x-media-upload-dialog id="logo-upload-dialog" target-select="#field-logo_id" title="Unggah logo baru" />
<x-media-upload-dialog id="cover-upload-dialog" target-select="#field-cover_id" title="Unggah foto sampul baru" />
@endsection
