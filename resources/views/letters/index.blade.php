@extends('layouts.app')
@section('title', $portal ? 'Surat saya' : 'Administrasi surat')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ $portal ? 'LAYANAN KRAMA' : 'ADMINISTRASI' }}</span><h1>{{ $portal ? 'Surat saya' : 'Administrasi surat' }}</h1><p class="muted">{{ $portal ? 'Ajukan surat dan ikuti prosesnya dari sini.' : 'Tinjau pengajuan dan temukan arsip surat yang sudah terbit.' }}</p></div>@if($portal)<a class="button" href="/portal/layanan">+ Ajukan surat</a>@endif</div>
@include('letters.nav')
<form class="card grid gap-4 sm:grid-cols-3 mb-6" method="get"><label>Status<select name="status"><option value="">Semua status</option>@foreach(\App\Modules\Administration\LetterManager::STATUSES as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label><label>Nomor surat<input name="number" maxlength="80" value="{{ request('number') }}" placeholder="Contoh: 0001/ADM/2026"></label><button class="button-secondary self-end">Tampilkan</button></form>
<div class="card-grid">
@forelse($rows as $row)
@php($identity = \App\Modules\Administration\LetterManager::unpack($row->identity_snapshot))
@php($service = \App\Modules\Administration\LetterManager::unpack($row->service_snapshot))
<a class="card tenant-card letter-copy" href="{{ $portal ? '/portal/surat/' : '/admin/letters/' }}{{ $row->id }}"><span class="badge">{{ \App\Modules\Administration\LetterManager::STATUSES[$row->status] }}</span><h2>{{ $service['title'] }}</h2><p>{{ $identity['name'] }}</p><p class="muted mt-2">{{ \Carbon\Carbon::parse($row->created_at)->timezone('Asia/Makassar')->translatedFormat('d F Y, H:i') }} WITA</p>@if(isset($documents[$row->id]))<p class="mt-3">{{ $documents[$row->id]->number }}</p>@endif<div class="card-bottom">Lihat pengajuan <span>→</span></div></a>
@empty<div class="card empty-state"><h2>Belum ada pengajuan</h2><p class="muted">{{ $portal ? 'Pilih layanan surat untuk membuat pengajuan pertama Anda.' : 'Pengajuan yang sesuai dengan akses dan filter Anda akan tampil di sini.' }}</p></div>@endforelse
</div><div class="mt-6">{{ $rows->links() }}</div>
@endsection
