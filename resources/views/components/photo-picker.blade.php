@props(['name', 'label', 'files', 'multiple' => false, 'documents' => false, 'cover' => false, 'help' => null, 'button' => null])
{{--
    §18/§58: pilih foto langsung dari HP/komputer (bisa beberapa sekaligus), langsung tampil sebagai pratinjau, tanpa
    dialog dan tanpa pilihan Publik/Privat. Berkas lama yang dipertahankan dikirim sebagai keep_{name}; berkas
    baru sebagai {name}. Penanganan pratinjau, hapus, dan pengecilan foto ada di resources/js/app.js.
--}}
@php
    $accept = 'image/*'.($documents ? ',application/pdf' : '');
    $button ??= $multiple ? 'Pilih foto' : ($documents ? 'Pilih berkas' : 'Pilih foto');
@endphp
<fieldset class="photo-picker" data-photo-picker @if($multiple) data-multiple @endif @if($cover) data-cover @endif>
<legend>{{ $label }}</legend>
@unless($multiple)<input type="hidden" name="keep_{{ $name }}" value="{{ $files->first()->id ?? '' }}" data-photo-keep-single>@endunless
<div class="photo-grid" data-photo-grid>
@foreach($files as $file)
<figure class="photo-tile" data-photo-tile>
@if(str_starts_with($file->mime, 'image/'))<img src="/admin/media/{{ $file->id }}/download?lihat=1" alt="" loading="lazy">@else<span class="photo-doc">PDF</span>@endif
<figcaption>{{ $file->name }}</figcaption>
@if($multiple)<input type="hidden" name="keep_{{ $name }}[]" value="{{ $file->id }}">@endif
<button type="button" class="photo-remove" data-photo-remove aria-label="Hapus {{ $file->name }}">Hapus</button>
</figure>
@endforeach
</div>
<label class="button-secondary photo-add">
<span>+ {{ $button }}</span>
<input type="file" name="{{ $name }}{{ $multiple ? '[]' : '' }}" accept="{{ $accept }}" @if($multiple) multiple @endif data-photo-input>
</label>
<span class="help">{{ $help ?? ($cover ? 'Bisa pilih beberapa foto sekaligus. Foto pertama menjadi foto utama.' : '') }}</span>
@if($errors->has($name) || $errors->has($name.'.*') || $errors->has('keep_'.$name))<span class="field-error">{{ $errors->first($name) ?: ($errors->first($name.'.*') ?: $errors->first('keep_'.$name)) }}</span>@endif
</fieldset>
