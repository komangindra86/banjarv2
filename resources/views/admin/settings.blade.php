@extends('layouts.app')
@section('title', 'Pengaturan website')
@section('content')
<div class="page-heading"><div><span class="eyebrow">IDENTITAS BANJAR</span><h1>Pengaturan website</h1><p class="muted">Identitas yang konsisten untuk {{ $currentBanjar->name }}.</p></div></div>
<form method="post" action="/admin/settings/{{ $settings->id }}" class="card form-stack form-width">@csrf @method('PATCH')
<label>Template<select name="template_code"><option value="template-01">Template 01 · Bali Santih</option></select><span class="help">Template publik dengan tampilan yang menyesuaikan layar ponsel.</span></label>
<label>Warna aksen<input type="color" name="accent_color" value="{{ old('accent_color', $settings->accent_color) }}" required><span class="help">Pilih warna identitas Banjar.</span></label><button class="button">Simpan pengaturan</button></form>
<div class="inline-links mt-6"><a href="/admin/profile">Profil, logo & sampul</a><a href="/admin/qr">Unduh QR website</a><a href="/">Lihat website</a></div>
@endsection
