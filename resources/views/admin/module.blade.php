@extends('layouts.app')
@section('title', $title)
@section('content')
<div class="page-heading"><div><span class="eyebrow">BANJAR DIGITAL</span><h1>{{ $title }}</h1></div></div><section class="card empty-state"><span class="badge">TAHAP BERIKUTNYA</span><h2 class="mt-5">Ruang ini sedang disiapkan</h2><p class="muted">{{ $description }}</p><a class="button mt-6" href="/admin">Kembali ke beranda</a></section>
@endsection
