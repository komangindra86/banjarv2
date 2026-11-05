@extends('layouts.app')
@section('title', ($item ? 'Edit ' : 'Tambah ').strtolower($definition['label']))
@section('content')
@php
    $dateValue = fn ($key) => !empty($item->$key) ? \Carbon\Carbon::parse($item->$key)->timezone('Asia/Makassar')->format('Y-m-d\TH:i') : null;
@endphp
<a href="/admin/content/{{ $type }}" class="back-link">← {{ $definition['label'] }}</a>
<div class="page-heading"><div><span class="eyebrow">{{ $definition['label'] }}</span><h1>{{ $item ? 'Edit informasi' : 'Informasi baru' }}</h1><p class="muted">{{ $definition['hint'] }}</p><p class="muted mt-2">Isian bertanda * wajib diisi.@if($type === 'agenda') Semua waktu menggunakan WITA.@endif</p></div></div>
<form action="/admin/content/{{ $type }}{{ $item ? '/'.$item->id : '' }}" method="post" enctype="multipart/form-data" class="card form-stack form-width" data-saving>@csrf @if($item) @method('PATCH') @endif
<x-editor-field name="title" :label="$type === 'pengurus' ? 'Nama pengurus' : 'Judul'" :value="$item->title ?? null" required />
@if($type === 'pengurus')
<x-editor-field name="position" label="Jabatan" :value="$item->position ?? null" required />
<x-editor-field name="sort_order" label="Urutan tampil" type="number" :value="$item->sort_order ?? 0" required />
@endif
<x-editor-field name="body" label="{{ $type === 'pengurus' ? 'Keterangan' : 'Isi / deskripsi' }}" type="textarea" :value="$item->body ?? null" help="Tulis teks biasa. Baris baru akan dipertahankan." />
@if($type === 'agenda')
<x-editor-field name="starts_at" label="Mulai (WITA)" type="datetime-local" :value="$dateValue('starts_at')" required />
<x-editor-field name="ends_at" label="Selesai (WITA)" type="datetime-local" :value="$dateValue('ends_at')" />
<x-editor-field name="location" label="Tempat kegiatan" :value="$item->location ?? null" />
@endif
@if($type === 'layanan')
<x-editor-field name="requirements" label="Persyaratan" type="textarea" :value="$item->requirements ?? null" />
<x-editor-field name="procedure" label="Prosedur" type="textarea" :value="$item->procedure ?? null" />
<x-editor-field name="contact" label="Kontak layanan" :value="$item->contact ?? null" />
@endif
@if($type === 'dokumen')
<x-photo-picker name="document" label="Berkas dokumen" :files="$files" documents help="Misalnya awig-awig, pararem, laporan, atau formulir. PDF atau foto, maksimal 10 MB." />
@else
<x-photo-picker name="photos" label="Foto" :files="$files" multiple cover />
@endif
@if($type === 'pengumuman')
<x-editor-field name="priority" label="Prioritas" type="select" :value="$item->priority ?? 'NORMAL'" :options="['NORMAL'=>'Biasa','IMPORTANT'=>'Penting']" />
<x-editor-field name="related_activity_id" label="Agenda terkait" type="select" :value="$item->related_activity_id ?? null" :options="[''=>'Tidak ada'] + $activities->pluck('title', 'id')->all()" />
@endif
<hr>
<x-editor-field name="status" label="Status publikasi" type="select" :value="$item->status ?? 'DRAFT'" :options="['DRAFT'=>'Draf','PUBLISHED'=>'Terbit','ARCHIVED'=>'Diarsipkan']" required />
<x-editor-field name="published_at" label="Waktu terbit (WITA)" type="datetime-local" :value="$dateValue('published_at')" help="Kosongkan untuk waktu sekarang. Konten berstatus Terbit dengan waktu mendatang akan tampil sesuai jadwal." />
@if($type === 'pengumuman')<x-editor-field name="expires_at" label="Berlaku sampai (WITA)" type="datetime-local" :value="$dateValue('expires_at')" help="Setelah waktu ini, pengumuman hanya tampil dalam riwayat." />@endif
<button class="button" type="submit">Simpan konten</button>
</form>
@if($item)<form action="/admin/content/{{ $type }}/{{ $item->id }}" method="post" class="mt-6" data-confirm="Arsipkan konten ini dari website publik?">@csrf @method('DELETE')<button class="button-secondary">Arsipkan konten</button></form>@endif
@endsection
