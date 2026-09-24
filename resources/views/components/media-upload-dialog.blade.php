@props(['id', 'targetSelect' => null, 'targetChecklist' => null, 'accept' => 'image/jpeg,image/png,image/webp', 'title' => 'Unggah foto baru'])
<dialog id="{{ $id }}" class="card form-width">
<h2>{{ $title }}</h2>
<form data-quick-add @if($targetSelect) data-target-select="{{ $targetSelect }}" @endif @if($targetChecklist) data-target-checklist="{{ $targetChecklist }}" @endif method="post" action="/admin/media" enctype="multipart/form-data" class="form-stack mt-3">@csrf
<label>Berkas<input name="file" type="file" accept="{{ $accept }}" required></label>
<label>Akses berkas<select name="visibility"><option value="PUBLIC" selected>Publik · tampil di website</option><option value="PRIVATE">Privat · hanya pengurus</option></select></label>
<p class="field-error" data-quick-error></p>
<div class="flex flex-wrap gap-3"><button type="submit" class="button">Unggah &amp; pilih</button><button type="button" class="button-secondary" data-cancel-dialog>Batal</button></div>
</form>
</dialog>
