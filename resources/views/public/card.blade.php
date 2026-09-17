@php
    $url = '/'.$entryType.'/'.$entry->slug;
    $picture = $publicContent->image($entry->media_id);
    $person = $entryType === 'pengurus';
@endphp
<article @class(['public-card', 'is-person' => $person, 'is-text' => ! $picture && ! $person])>
@if($picture)<a class="card-media" href="{{ $url }}" tabindex="-1" aria-hidden="true"><img src="{{ $picture }}" alt="" loading="lazy" width="640" height="480"></a>
@elseif($person)<a class="card-media placeholder" href="{{ $url }}" tabindex="-1" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr(trim(preg_replace('/^(i|ni|ida|anak agung|gusti|i gusti|dewa|desak)\s+/i', '', $entry->title)), 0, 1)) }}</span></a>@endif
<div class="public-card-body">
<p class="card-meta">@if($entryType === 'pengumuman' && $entry->priority === 'IMPORTANT')<span class="badge">Penting</span>@endif
@if($entryType === 'agenda')<span>{{ \Carbon\Carbon::parse($entry->starts_at)->timezone('Asia/Makassar')->translatedFormat('d M Y · H:i') }} WITA</span>@elseif($person)<span>{{ $entry->position }}</span>@elseif($entryType === 'layanan')<span>Layanan Banjar</span>@else<span>{{ \Carbon\Carbon::parse($entry->published_at)->timezone('Asia/Makassar')->translatedFormat('d F Y') }}</span>@endif</p>
<h2><a href="{{ $url }}">{{ $entry->title }}</a></h2>
@if($entry->body && ! $person)<p class="excerpt">{{ \Illuminate\Support\Str::limit($entry->body, 140) }}</p>@endif
@if($entryType === 'agenda' && $entry->location)<p class="excerpt">{{ $entry->location }}</p>@endif
<a class="public-read" href="{{ $url }}">{{ $entryType === 'layanan' ? 'Syarat & prosedur' : ($person ? 'Profil' : 'Selengkapnya') }} <span aria-hidden="true">→</span></a>
</div></article>
