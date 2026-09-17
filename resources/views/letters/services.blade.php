@extends('layouts.app')
@section('title', $portal ? 'Layanan surat' : 'Aturan layanan surat')
@section('content')
<div class="page-heading"><div><span class="eyebrow">ADMINISTRASI</span><h1>{{ $portal ? 'Pilih layanan surat' : 'Aturan layanan surat' }}</h1><p class="muted">{{ $portal ? 'Identitas Anda akan diisi otomatis dari data Banjar.' : 'Aktifkan pengajuan pada layanan yang telah dibuat di Konten → Layanan.' }}</p></div></div>
@include('letters.nav')
@if($portal && !$person)<div class="notice">Akun belum terhubung ke krama dan KK aktif. Hubungi Admin Banjar sebelum mengajukan surat.</div>@endif
<div class="{{ $portal ? 'card-grid' : 'grid gap-5 form-width' }}">
@forelse($services as $service)
<section class="card letter-copy"><h2>{{ $service->title }}</h2>
@if($portal)
<p class="muted mt-3">{{ $service->letter_requires_approval ? 'Diperiksa Penyarikan, lalu disetujui Kelian.' : 'Diperiksa dan diterbitkan oleh Penyarikan.' }}</p>@if($service->requirements)<p class="muted mt-4">Persyaratan</p><p class="whitespace-pre-line">{{ $service->requirements }}</p>@endif
@if($person)<a class="button mt-5" href="/portal/layanan/{{ $service->id }}/ajukan">Ajukan surat</a>@endif
@else
<p class="muted mt-2">{{ $service->status === 'PUBLISHED' && $service->published_at <= now() ? 'Layanan telah terbit' : 'Layanan belum terbit; pengajuan belum tersedia' }}</p>
<form class="form-stack mt-5" method="post" action="/admin/letters/services/{{ $service->id }}">@csrf @method('PATCH')<input type="hidden" name="letter_version" value="{{ $service->letter_version }}">
<label>Pengajuan online<select name="letters_enabled"><option value="0" @selected(!$service->letters_enabled)>Nonaktif</option><option value="1" @selected($service->letters_enabled)>Aktif</option></select></label>
<label>Persetujuan Kelian<select name="letter_requires_approval"><option value="1" @selected($service->letter_requires_approval)>Diperlukan setelah verifikasi Penyarikan</option><option value="0" @selected(!$service->letter_requires_approval)>Cukup verifikasi Penyarikan</option></select></label>
<label>Pernyataan surat<textarea name="letter_statement" maxlength="2000" rows="5">{{ $service->letter_statement }}</textarea><span class="help">Teks ini muncul setelah identitas krama dalam PDF. Nama, NIK, nomor KK, dan alamat diambil otomatis. Gunakan teks biasa.</span></label>
<p class="help">Perubahan aturan hanya berlaku pada pengajuan baru. PDF menyediakan ruang tanda tangan dan stempel pengurus.</p><button class="button">Simpan aturan</button></form>
@endif</section>
@empty<div class="card empty-state"><h2>Belum ada layanan surat</h2><p class="muted">{{ $portal ? 'Pengurus belum membuka pengajuan surat online.' : 'Buat layanan melalui Konten → Layanan, lalu atur pengajuannya di sini.' }}</p></div>@endforelse
</div><div class="mt-6">{{ $services->links() }}</div>
@endsection
