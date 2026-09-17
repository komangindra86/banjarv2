<dl class="grid gap-4 sm:grid-cols-2 letter-copy">
@foreach(['name' => 'Nama', 'nik' => 'NIK', 'birth_date' => 'Tanggal lahir', 'family_number' => 'Nomor KK', 'household_code' => 'Kode KK Banjar', 'address' => 'Alamat'] as $field => $label)
<div @class(['sm:col-span-2' => $field === 'address'])><dt class="muted">{{ $label }}</dt><dd>{{ in_array($field, ['nik', 'family_number']) ? \App\Modules\Administration\LetterManager::mask($identity[$field]) : ($identity[$field] ?: 'Belum tercatat') }}</dd></div>
@endforeach
</dl>
