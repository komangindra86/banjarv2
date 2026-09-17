<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><title>{{ $document['number'] }}</title>
<style>
    @page { margin: 48pt 54pt 62pt; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; line-height: 1.65; color: #202020; overflow-wrap: break-word; }
    header { text-align: center; border-bottom: 2pt solid #333; padding-bottom: 14pt; margin-bottom: 24pt; }
    header h1 { font-size: 18pt; margin: 0 0 5pt; }
    header p { font-size: 9pt; margin: 0; white-space: pre-wrap; }
    h2 { text-align: center; font-size: 13pt; margin: 0; }
    .number { text-align: center; margin: 4pt 0 24pt; }
    .identity { margin: 16pt 0 20pt; }
    .identity p { margin: 5pt 0; word-wrap: break-word; }
    .identity span { display: inline-block; width: 110pt; color: #555; }
    .label { color: #666; font-size: 8pt; margin: 0; }
    .block { white-space: pre-wrap; margin: 6pt 0 18pt; word-wrap: break-word; }
    .closing { page-break-inside: avoid; }
    .signature { margin-top: 20pt; margin-left: 52%; }
    .signature p { margin: 2pt 0; }
    .signature .space { height: 48pt; }
    footer { position: fixed; bottom: -34pt; left: 0; right: 45pt; font-size: 7pt; color: #666; border-top: 0.5pt solid #bbb; padding-top: 6pt; }
</style></head><body>
<footer>{{ $document['number'] }} | Arsip Banjar Digital. Pengesahan tanda tangan/stempel dilakukan oleh pengurus.</footer>
<header><h1>{{ $document['banjar_name'] }}</h1>@if($document['banjar_address'])<p>{{ $document['banjar_address'] }}</p>@endif</header>
<h2>{{ $document['service']['title'] }}</h2><p class="number">Nomor: {{ $document['number'] }}</p>
<p>Yang bertanda tangan di bawah ini menerangkan data krama berikut:</p>
<div class="identity">
@foreach(['name' => 'Nama', 'nik' => 'NIK', 'birth_date' => 'Tanggal lahir', 'family_number' => 'Nomor KK', 'household_code' => 'Kode KK Banjar'] as $field => $label)
@if($document['identity'][$field])<p><span>{{ $label }}</span>{{ $field === 'birth_date' ? \Carbon\Carbon::parse($document['identity'][$field])->translatedFormat('d F Y') : $document['identity'][$field] }}</p>@endif
@endforeach
</div>
@if($document['identity']['address'])<p class="label">Alamat</p><p class="block">{{ $document['identity']['address'] }}</p>@endif
<p class="block">{{ $document['service']['statement'] }}</p>
<p class="label">Keperluan surat</p><p class="block">{{ $document['purpose'] }}</p>
<div class="closing"><p>Demikian surat ini dibuat untuk dipergunakan sebagaimana mestinya.</p>
<div class="signature"><p>{{ $document['date'] }}</p><p>{{ $document['issuer_title'] }}</p><div class="space"></div><p><strong>{{ $document['issuer_name'] }}</strong></p></div>
</div>
</body></html>
