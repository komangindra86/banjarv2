@extends('layouts.app')
@section('title', 'Tambah Banjar')
@section('content')
<a class="back-link" href="/platform">← Daftar Banjar</a><div class="page-heading"><div><h1>Tambahkan Banjar</h1><p class="muted">Website dan agenda dasar akan aktif sejak awal.</p></div></div>
<form action="/platform/banjars" method="post" class="card form-stack form-width">@csrf
    <h2>Identitas Banjar</h2><label>Nama Banjar<input name="name" value="{{ old('name') }}" required maxlength="150" placeholder="Banjar Biaung"></label>
    <label>Subdomain<input name="slug" value="{{ old('slug') }}" required maxlength="63" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="biaung"><span class="help">Alamat: subdomain.{{ config('platform.tenant_base_domain') }}</span></label>
    <hr><h2>Akun pengurus awal</h2><label>Nama pengurus<input name="admin_name" value="{{ old('admin_name') }}" required maxlength="150" autocomplete="name"></label>
    <label>Email pengurus<input name="admin_email" type="email" value="{{ old('admin_email') }}" required maxlength="255" autocomplete="email"></label>
    <label>Kata sandi awal<input name="admin_password" type="password" minlength="12" required autocomplete="new-password"><span class="help">Minimal 12 karakter, mengandung huruf dan angka. Sampaikan melalui kanal pribadi.</span></label>
    <label>Ulangi kata sandi<input name="admin_password_confirmation" type="password" minlength="12" required autocomplete="new-password"></label>
    <button class="button">Buat Banjar dan akun pengurus</button>
</form>
@endsection
