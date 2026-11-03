@extends('layouts.app')
@section('title', 'Pilih Banjar')
@section('content')
<div class="page-heading"><div><span class="eyebrow">PORTAL KRAMA</span><h1>Pilih Banjar</h1><p class="muted">Pilih Banjar yang ingin dibuka. Anda bisa berpindah Banjar kapan saja dari menu.</p></div></div>
@if($banjars->isEmpty())
<div class="card empty-state"><h2>Portal Banjar belum aktif</h2><p class="muted">Banjar Anda belum mengaktifkan Portal Krama, atau akun belum terdaftar sebagai krama. Hubungi pengurus Banjar.</p></div>
@else
<div class="card-grid">
@foreach($banjars as $banjar)
<form class="card" action="/pilih-banjar" method="post">@csrf<input type="hidden" name="banjar_id" value="{{ $banjar->id }}">
    <span class="eyebrow">{{ (int) $banjar->id === $active ? 'SEDANG DIBUKA' : 'BANJAR' }}</span><h2 class="mt-3 break-words">{{ $banjar->name }}</h2>
    <button class="{{ (int) $banjar->id === $active ? 'button-secondary' : 'button' }} mt-4" type="submit">{{ (int) $banjar->id === $active ? 'Lanjutkan' : 'Buka portal' }} <span aria-hidden="true">→</span></button>
</form>
@endforeach
</div>
@endif
@endsection
