@extends('layouts.app')
@section('title', 'Data saya')
@section('content')
<h1>Data saya</h1>@include('admin.krama.nav')
<section class="card form-width">@if($row)<h2>{{ $row->name }}</h2><dl class="form-stack mt-5"><div><dt>NIK</dt><dd>{{ \App\Modules\Krama\KramaManager::masked($row->nik) }}</dd></div><div><dt>Tanggal lahir</dt><dd>{{ $row->birth_date ?: 'Belum diisi' }}</dd></div><div><dt>Telepon</dt><dd>{{ $row->phone ?: 'Belum diisi' }}</dd></div><div><dt>Status</dt><dd>{{ \App\Modules\Krama\KramaManager::STATUSES[$row->status] }}</dd></div><div><dt>Status hubungan keluarga</dt><dd>{{ $isHead ? 'Kepala Keluarga' : ($row->relationship ? \App\Modules\Krama\KramaManager::RELATIONSHIPS[$row->relationship] : 'Belum diisi') }}</dd></div></dl><a class="button mt-6" href="/admin/krama/people/{{ $row->id }}/change">Ajukan perubahan data</a>@else<h2>Akun belum terhubung</h2><p class="muted mt-3">Hubungi Admin Banjar untuk menghubungkan akun ini dengan data krama Anda.</p>@endif</section>
@endsection
