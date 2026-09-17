@extends('layouts.public')
@section('content')
<header class="public-page-heading"><span class="eyebrow">Mengenal Banjar</span><h1>{{ $currentBanjar->name }}</h1><p>{{ $profile->tagline ?? 'Profil dan informasi Banjar' }}</p><x-ornament /></header>
@if($cover = $publicContent->image($profile->cover_id ?? null))<img class="profile-cover bleed" src="{{ $cover }}" alt="{{ $currentBanjar->name }}" width="1600" height="700">@endif
<section class="profile-story"><div><span class="eyebrow">Tentang</span><h2>Tentang Banjar</h2></div><div><div class="prose-text lead">{{ $profile->description ?? 'Profil Banjar sedang disiapkan oleh pengurus.' }}</div>@if($profile->history ?? null)<div class="profile-history"><h3>Sejarah Banjar</h3><div class="prose-text">{{ $profile->history }}</div></div>@endif</div></section>
<section class="public-section"><div class="section-head center"><span class="eyebrow">Melayani bersama</span><h2>Struktur pengurus</h2><x-ornament class="mt-2" /></div><div class="public-grid">@forelse($officials as $entry)@include('public.card', ['entryType'=>'pengurus'])@empty<div class="public-empty"><p>Informasi pengurus sedang disiapkan.</p></div>@endforelse</div></section>
<nav class="explore-links" aria-label="Jelajahi Banjar"><a href="/layanan">Informasi layanan <span aria-hidden="true">→</span></a><a href="/galeri">Galeri Banjar <span aria-hidden="true">→</span></a><a href="/dokumen">Dokumen publik <span aria-hidden="true">→</span></a></nav>
@include('public.contact')
@endsection
