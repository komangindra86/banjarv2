@extends('layouts.app')
@section('title', 'Ajukan surat')
@section('content')
<a class="back-link" href="/portal/layanan">← Layanan surat</a><div class="page-heading"><div><span class="eyebrow">PENGAJUAN SURAT</span><h1>{{ $service->title }}</h1></div></div>
@if(!$person)<section class="card form-width"><h2>Data krama belum tersedia</h2><p class="muted mt-3">Hubungi Admin Banjar untuk menautkan akun Anda ke krama dan KK aktif.</p></section>
@else
<div class="form-width grid gap-5"><section class="card"><h2 class="mb-5">Identitas dari data Banjar</h2>@include('letters.identity')<p class="help mt-5">Periksa data sebelum mengirim. Nomor identitas lengkap yang tersedia akan tercantum di PDF privat Anda.</p><a class="back-link mt-2 mb-0" href="/admin/krama/mine">Ada data yang perlu diperbaiki?</a></section>
@if($service->requirements || $service->procedure)<details class="card"><summary>Persyaratan dan prosedur</summary><p class="whitespace-pre-line mt-4 letter-copy">{{ $service->requirements }}</p><p class="whitespace-pre-line mt-4 letter-copy">{{ $service->procedure }}</p></details>@endif
<form class="card form-stack" action="/portal/surat" method="post">@csrf
<input type="hidden" name="service_definition_id" value="{{ $service->id }}"><input type="hidden" name="person_version" value="{{ $person->version }}"><input type="hidden" name="household_version" value="{{ $household->version }}"><input type="hidden" name="service_version" value="{{ $service->letter_version }}"><input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>Keperluan surat<textarea name="purpose" maxlength="1500" rows="4" required placeholder="Jelaskan singkat untuk keperluan apa surat ini diajukan."></textarea>@error('purpose')<span class="help text-red-700">{{ $message }}</span>@enderror</label>
<p class="muted">{{ $service->letter_requires_approval ? 'Penyarikan memverifikasi pengajuan, kemudian Kelian memberikan persetujuan.' : 'Penyarikan memverifikasi pengajuan dan menerbitkan surat.' }}</p>
<button class="button">Kirim pengajuan</button></form></div>
@endif
@endsection
