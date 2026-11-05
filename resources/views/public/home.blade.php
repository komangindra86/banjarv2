@extends('layouts.public')
@section('content')
@php
    $cover = $publicContent->image($profile->cover_id ?? null);
    $name = $currentBanjar->name;
    $headline = preg_match('/^(banjar(?:\s+adat)?)\s+(.+)$/iu', $name, $m) ? e($m[1]).' <em>'.e($m[2]).'</em>' : e($name);
    $firstNotice = $announcements->first();
    $nextActivity = $activities->first();
    $showActivity = app(\App\Platform\Modules\ModuleGate::class)->allows('ACTIVITY');
@endphp
<section @class(['hero', 'bleed', 'has-cover' => $cover])>
@if($cover)<img src="{{ $cover }}" alt="" fetchpriority="high" width="1600" height="900">@endif
<div class="hero-inner">
    <div>
        <span class="eyebrow">Om Swastiastu · Selamat datang</span>
        <h1>{!! $headline !!}</h1>
        <p class="hero-lead">{{ $profile->tagline ?? 'Pengumuman, kegiatan, dan layanan Banjar dalam satu tempat.' }}</p>
        <div class="hero-actions"><a href="/pengumuman" class="button">Lihat pengumuman</a><a href="/profil" class="button-secondary">Mengenal Banjar</a></div>
    </div>
</div>
<div class="lattice" aria-hidden="true"></div>
</section>

@if($firstNotice || ($showActivity && $nextActivity))
<div class="highlights">
@if($firstNotice)<a class="highlight" href="/pengumuman/{{ $firstNotice->slug }}"><span class="highlight-icon" aria-hidden="true"><x-ornament class="ornament-mark" /></span><span class="highlight-label">{{ $firstNotice->priority === 'IMPORTANT' ? 'Pengumuman penting' : 'Pengumuman terbaru' }}</span><h3>{{ $firstNotice->title }}</h3><p>{{ \Carbon\Carbon::parse($firstNotice->published_at)->timezone('Asia/Makassar')->translatedFormat('d F Y') }}</p><span class="highlight-go" aria-hidden="true">→</span></a>@endif
@if($showActivity && $nextActivity)@php $at = \Carbon\Carbon::parse($nextActivity->starts_at)->timezone('Asia/Makassar'); @endphp<a class="highlight" href="/agenda/{{ $nextActivity->slug }}"><span class="highlight-icon" aria-hidden="true"><span>{{ $at->format('d') }}<small>{{ $at->translatedFormat('M') }}</small></span></span><span class="highlight-label">Agenda terdekat</span><h3>{{ $nextActivity->title }}</h3><p>{{ $at->translatedFormat('l') }}, {{ $at->format('H:i') }} WITA{{ $nextActivity->location ? ' · '.$nextActivity->location : '' }}</p><span class="highlight-go" aria-hidden="true">→</span></a>@endif
</div>
@endif

<section class="public-section">
<div class="section-head"><div><span class="eyebrow">Informasi terkini</span><h2>Pengumuman Banjar</h2></div><a href="/pengumuman" class="section-link">Semua pengumuman <span aria-hidden="true">→</span></a></div>
<div class="public-grid">@forelse($announcements as $entry)@include('public.card', ['entryType'=>'pengumuman'])@empty<div class="public-empty"><x-ornament /><h3 class="mt-4">Belum ada pengumuman aktif</h3><p>Pengumuman resmi Banjar akan tersedia di sini.</p></div>@endforelse</div>
</section>

@if($showActivity)
<section class="agenda-band bleed"><div class="p-wrap">
<div class="section-head"><div><span class="eyebrow">Catat waktunya</span><h2>Kegiatan terdekat</h2><p>Paruman, ngayah, dan kegiatan bersama krama Banjar.</p></div><a class="section-link" href="/agenda">Semua kegiatan <span aria-hidden="true">→</span></a></div>
<div class="agenda-list">@forelse($activities as $entry)@php $at = \Carbon\Carbon::parse($entry->starts_at)->timezone('Asia/Makassar'); @endphp<a href="/agenda/{{ $entry->slug }}" class="agenda-row"><time datetime="{{ $entry->starts_at }}"><strong>{{ $at->format('d') }}</strong><span>{{ $at->translatedFormat('M Y') }}</span></time><div><h3>{{ $entry->title }}</h3><p>{{ $at->translatedFormat('l') }} · {{ $at->format('H:i') }} WITA{{ $entry->location ? ' · '.$entry->location : '' }}</p></div><span aria-hidden="true">→</span></a>@empty<div class="public-empty"><h3>Belum ada kegiatan mendatang</h3><p>Jadwal kegiatan berikutnya akan diumumkan di sini.</p></div>@endforelse</div>
</div></section>
@endif

<section class="public-section">
<div class="section-head"><div><span class="eyebrow">Keperluan krama</span><h2>Informasi layanan</h2></div><a href="/layanan" class="section-link">Semua layanan <span aria-hidden="true">→</span></a></div>
@if($services->isNotEmpty())<div class="service-list">@foreach($services as $entry)<a class="service-item" href="/layanan/{{ $entry->slug }}"><b>{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</b><div><h3>{{ $entry->title }}</h3>@if($entry->body)<p>{{ \Illuminate\Support\Str::limit($entry->body, 110) }}</p>@endif</div><span aria-hidden="true">→</span></a>@endforeach</div>
@else<div class="public-empty"><h3>Informasi layanan sedang disiapkan</h3><a href="/profil#kontak" class="public-read">Hubungi pengurus <span aria-hidden="true">→</span></a></div>@endif
</section>

<section class="public-section">
<div class="section-head"><div><span class="eyebrow">Cerita dari Banjar</span><h2>Berita</h2></div><a href="/berita" class="section-link">Semua berita <span aria-hidden="true">→</span></a></div>
@if($news->isNotEmpty())
<div class="news-grid">
@php $lead = $news->first(); $leadPicture = $publicContent->image($lead->media_id); @endphp
<article @class(['public-card', 'is-text' => ! $leadPicture])>@if($leadPicture)<a class="card-media" href="/berita/{{ $lead->slug }}" tabindex="-1" aria-hidden="true"><img src="{{ $leadPicture }}" alt="" loading="lazy" width="900" height="675"></a>@endif<div class="public-card-body"><p class="card-meta">{{ \Carbon\Carbon::parse($lead->published_at)->timezone('Asia/Makassar')->translatedFormat('d F Y') }}</p><h3><a href="/berita/{{ $lead->slug }}">{{ $lead->title }}</a></h3>@if($lead->body)<p class="excerpt">{{ \Illuminate\Support\Str::limit($lead->body, 220) }}</p>@endif<a class="public-read" href="/berita/{{ $lead->slug }}">Baca cerita <span aria-hidden="true">→</span></a></div></article>
<div class="news-side">@foreach($news->slice(1) as $entry)@php $pic = $publicContent->image($entry->media_id); @endphp<article class="public-card">@if($pic)<a class="card-media" href="/berita/{{ $entry->slug }}" tabindex="-1" aria-hidden="true"><img src="{{ $pic }}" alt="" loading="lazy" width="300" height="300"></a>@endif<div class="public-card-body"><p class="card-meta">{{ \Carbon\Carbon::parse($entry->published_at)->timezone('Asia/Makassar')->translatedFormat('d F Y') }}</p><h3><a href="/berita/{{ $entry->slug }}">{{ $entry->title }}</a></h3><a class="public-read" href="/berita/{{ $entry->slug }}">Selengkapnya <span aria-hidden="true">→</span></a></div></article>@endforeach</div>
</div>
@else<div class="public-empty"><h3>Belum ada berita terbit</h3><p>Cerita kegiatan Banjar akan ditampilkan di sini.</p></div>@endif
</section>

@include('public.contact')
@endsection
