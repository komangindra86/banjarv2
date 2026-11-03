@extends('layouts.app')
@section('title', 'Pengaturan website')
@section('content')
<div class="page-heading"><div><span class="eyebrow">IDENTITAS BANJAR</span><h1>Pengaturan website</h1><p class="muted">Identitas yang konsisten untuk {{ $currentBanjar->name }}.</p></div></div>
<form method="post" action="/admin/settings/{{ $settings->id }}" class="card form-stack form-width">@csrf @method('PATCH')
<fieldset class="template-picker"><legend>Template website</legend><span class="help">Semua template bernuansa Bali dan menyesuaikan layar ponsel. Memilih template ikut mengisi warna aksen yang serasi.</span>
<div class="template-grid">@foreach(\App\Platform\Tenants\SettingsManager::TEMPLATES as $code => $template)
<label class="template-choice"><input type="radio" name="template_code" value="{{ $code }}" @checked(old('template_code', $settings->template_code) === $code) data-template-accent="{{ $template['accent'] }}" data-accent-target="#accent-color">
<span class="template-swatches" aria-hidden="true">@foreach($template['swatches'] as $color)<span style="background: {{ $color }}"></span>@endforeach<span style="background: {{ $template['accent'] }}"></span></span>
<strong>{{ $template['name'] }}</strong><span class="help">{{ $template['note'] }}</span></label>
@endforeach</div></fieldset>
<label>Warna aksen<input id="accent-color" type="color" name="accent_color" value="{{ old('accent_color', $settings->accent_color) }}" required><span class="help">Warna tombol dan sorotan. Boleh diganti sesuai warna identitas Banjar.</span></label><button class="button">Simpan pengaturan</button></form>
<div class="inline-links mt-6"><a href="/admin/profile">Profil, logo & sampul</a><a href="/admin/qr">Unduh QR website</a><a href="/" target="_blank">Lihat website</a></div>
@endsection
