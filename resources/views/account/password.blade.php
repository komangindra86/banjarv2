@extends('layouts.app')
@section('title', 'Ganti kata sandi')
@section('content')
<div class="page-heading"><div><span class="eyebrow">AKUN SAYA</span><h1>Ganti kata sandi</h1><p class="muted">{{ $forced ? 'Anda masih memakai kata sandi awal. Buat kata sandi baru dulu agar akun Anda aman.' : 'Gunakan kata sandi yang mudah Anda ingat tetapi sulit ditebak orang lain.' }}</p></div></div>
<section class="card form-width">
@if(auth()->user()->password_locked)
<p>Ini akun demo bersama untuk mencoba aplikasi. Kata sandinya dikunci agar semua pengunjung tetap bisa masuk.</p>
@else
<form class="form-stack" method="post" action="/akun/kata-sandi">@csrf
    <label>{{ $forced ? 'Kata sandi awal' : 'Kata sandi saat ini' }}<input name="current_password" type="password" autocomplete="current-password" required maxlength="255" autofocus></label>
    <label>Kata sandi baru<input name="password" type="password" autocomplete="new-password" required minlength="8" maxlength="255"><span class="help">Minimal 8 karakter, berisi huruf dan angka.</span></label>
    <label>Ulangi kata sandi baru<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="8" maxlength="255"></label>
    <button class="button" type="submit">Simpan kata sandi baru</button>
</form>
@endif
</section>
@endsection
