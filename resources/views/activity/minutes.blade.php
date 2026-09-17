@extends('layouts.app')
@section('title', 'Notulen · '.$activity->title)
@section('content')
@include('activity.header')
@php $final = $minutes && $minutes->status === 'FINAL'; $edit = $canManage && !$final; @endphp
@if($final)<p class="notice">Notulen final ditetapkan {{ \Carbon\Carbon::parse($minutes->finalized_at)->timezone('Asia/Makassar')->translatedFormat('d M Y H:i') }} WITA.</p>@endif
@if($edit)
<form class="card form-stack form-width" method="post" action="/admin/activities/{{ $activity->id }}/minutes">@csrf<input type="hidden" name="version" value="{{ $minutes->version ?? '' }}">
<h2>{{ $activity->kind === 'PARUMAN' ? 'Notulen paruman' : 'Catatan kegiatan' }}</h2>
<label>Agenda pembahasan<textarea name="agenda" rows="4" maxlength="5000">{{ old('agenda', $minutes->agenda ?? '') }}</textarea></label>
<label>Notulen<textarea name="minutes" rows="10" maxlength="100000">{{ old('minutes', $minutes->minutes ?? '') }}</textarea></label>
<label>Keputusan<textarea name="decisions" rows="5">{{ old('decisions', $decisions->pluck('body')->implode("\n")) }}</textarea><span class="help">Satu keputusan per baris.</span></label>
@error('minutes')<p class="field-error">{{ $message }}</p>@enderror
<button class="button">Simpan notulen</button></form>
@else
<section class="card form-width letter-copy">@if($minutes)<h2>Agenda</h2><p class="whitespace-pre-line mt-2">{{ $minutes->agenda ?: '—' }}</p><h2 class="mt-6">Notulen</h2><p class="whitespace-pre-line mt-2">{{ $minutes->minutes ?: '—' }}</p>@else<p class="muted">Belum ada notulen.</p>@endif
@if($decisions->isNotEmpty())<h2 class="mt-6">Keputusan</h2><ol class="list-decimal pl-5 mt-2 grid gap-2">@foreach($decisions as $d)<li class="whitespace-pre-line">{{ $d->body }}</li>@endforeach</ol>@endif</section>
@endif
<section class="card form-width mt-6"><h2>Lampiran</h2><ul class="grid gap-2 mt-3">@forelse($attachments as $a)<li class="target-row"><a class="text-link break-all" href="/admin/activities/{{ $activity->id }}/minutes/attachments/{{ $a->id }}">{{ $a->name }}</a>@if($edit)<form method="post" action="/admin/activities/{{ $activity->id }}/minutes/attachments/{{ $a->id }}">@csrf @method('DELETE')<button class="button-quiet">Lepas</button></form>@endif</li>@empty<li class="muted">Belum ada lampiran.</li>@endforelse</ul>
@if($edit && $media->isNotEmpty())<form class="form-stack mt-4" method="post" action="/admin/activities/{{ $activity->id }}/minutes/attachments">@csrf<label>Pilih dari pustaka media<select name="media_id">@foreach($media as $m)<option value="{{ $m->id }}">{{ $m->name }} · {{ $m->visibility === 'PUBLIC' ? 'Publik' : 'Privat' }}</option>@endforeach</select><span class="help">Unggah berkas di Konten → Pustaka media. Gunakan berkas privat untuk dokumen internal.</span></label><button class="button-secondary">Tambah lampiran</button></form>@endif</section>
@if($edit && $minutes)<form class="card form-width mt-6" method="post" action="/admin/activities/{{ $activity->id }}/minutes/finalize" data-confirm="Tetapkan notulen final? Setelah final, notulen tidak dapat diubah.">@csrf<h2>Tetapkan final</h2><p class="muted mt-2 mb-4">Notulen final menjadi arsip dan tidak dapat diubah.</p><button class="button-secondary">Tetapkan final</button></form>@endif
@endsection
