@extends('layouts.app')
@section('title', 'Profil & kontak')
@section('content')
@php($options = [''=>'Tanpa foto'] + $media->mapWithKeys(fn ($m)=>[$m->id=>$m->name.' · '.$m->visibility])->all())
<a class="back-link" href="/admin/website">← Konten website</a>
<div class="page-heading"><div><span class="eyebrow">IDENTITAS BANJAR</span><h1>Profil & kontak</h1><p class="muted">Informasi yang disimpan di sini langsung tampil di website publik.</p></div></div>
<form method="post" action="/admin/profile" class="card form-stack form-width" data-saving>@csrf @method('PATCH')
<x-editor-field name="tagline" label="Kalimat pembuka" :value="$profile->tagline ?? null" />
<x-editor-field name="description" label="Tentang Banjar" type="textarea" :value="$profile->description ?? null" />
<x-editor-field name="history" label="Sejarah Banjar" type="textarea" :value="$profile->history ?? null" />
<x-editor-field name="logo_id" label="Logo" type="select" :value="$profile->logo_id ?? null" :options="$options" />
<x-editor-field name="cover_id" label="Foto sampul" type="select" :value="$profile->cover_id ?? null" :options="$options" help="Pilih foto berstatus PUBLIC dari Pustaka media agar terlihat pengunjung." />
<x-editor-field name="address" label="Alamat" type="textarea" :value="$profile->address ?? null" />
<x-editor-field name="phone" label="Telepon publik" type="tel" :value="$profile->phone ?? null" />
<x-editor-field name="email" label="Email publik" type="email" :value="$profile->email ?? null" />
<x-editor-field name="latitude" label="Lintang lokasi" type="number" :value="$profile->latitude ?? null" help="Salin koordinat dari peta, misalnya -8.65. Isi bersama bujur." />
<x-editor-field name="longitude" label="Bujur lokasi" type="number" :value="$profile->longitude ?? null" />
<button class="button">Simpan profil publik</button></form>
@endsection
