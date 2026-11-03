@extends('layouts.app')
@section('title', $row ? $row->name : 'Tambah krama')
@section('content')
<a class="back-link" href="{{ $lockedHousehold ? '/admin/krama/households/'.$lockedHousehold->id : '/admin/krama?tab=people' }}">← {{ $lockedHousehold ? $lockedHousehold->code : 'Daftar krama' }}</a><div class="page-heading"><div><h1>{{ $row ? $row->name : ($lockedHousehold ? 'Tambah anggota · '.$lockedHousehold->code : 'Tambah krama') }}</h1><p class="muted">Identitas dan keanggotaan krama.</p></div>@if($row && $canEdit)<a class="button-secondary" href="/admin/krama/people/{{ $row->id }}/change">Ajukan perubahan</a>@endif</div>
@if($canPatus)
<section class="card form-width mb-6">
    <h2>{{ $row->name }} tercatat meninggal</h2>
    @if($patusTypes->isNotEmpty())
    <p class="muted mt-2">Buat draf tagihan patus untuk almarhum/ah ini — judul dan kode sudah terisi otomatis, langsung dikenakan ke semua KK.</p>
    <button type="button" class="button mt-4" data-open-dialog="#patus-dialog">Tetapkan patus untuk {{ $row->name }} →</button>
    <dialog id="patus-dialog" class="card form-width" @if(session('promptPatus')) data-auto-open @endif>
        <h2>Buat draf tagihan patus?</h2>
        <p class="muted mt-2">Akan dikenakan ke semua kepala keluarga atas nama <strong>{{ $row->name }}</strong>.</p>
        <form method="post" action="/admin/finance/events" class="form-stack mt-4">@csrf
            @if($patusType)
            <input type="hidden" name="billing_type_id" value="{{ $patusType->id }}">
            @else
            <label>Jenis patus<select name="billing_type_id" required>@foreach($patusTypes as $t)<option value="{{ $t->id }}">{{ $t->name }} · {{ \App\Modules\Finance\FinanceManager::money($t->default_amount) }} / KK</option>@endforeach</select></label>
            @endif
            <input type="hidden" name="title" value="{{ $patusDraft['title'] }}">
            <input type="hidden" name="reference" value="{{ $patusDraft['reference'] }}">
            <label>Tanggal penetapan<input type="date" name="event_date" required value="{{ $patusDraft['event_date'] }}"></label>
            <div class="flex flex-wrap gap-3 mt-2">
                <button type="submit" class="button">Ya, buat draf tagihan patus</button>
                <button type="button" class="button-secondary" data-close-dialog>Nanti saja</button>
            </div>
        </form>
    </dialog>
    @else
    <p class="muted mt-2">Belum ada jenis tagihan berkategori Patus. Tambahkan di Keuangan → Jenis & tagihan agar bisa langsung ditetapkan dari sini.</p>
    <a class="button-secondary mt-4" href="/admin/finance/settings">Buka Jenis & tagihan →</a>
    @endif
</section>
@endif
@if($canAccount)
<section class="card form-width mb-6">
    <div class="section-label"><h2>Akun portal warga</h2>@if($portalAccount)<span class="badge">{{ $portalAccount->status === 'ACTIVE' ? 'Aktif' : 'Nonaktif' }}</span>@endif</div>
    @if($portalAccount)
    <p class="muted mt-2">@if($portalAccount->nik_hash)Login di portal dengan <strong>NIK</strong>.@else Login dengan email {{ $portalAccount->email }}.@endif @if($portalAccount->must_change_password) Masih memakai kata sandi awal — warga wajib menggantinya saat pertama masuk.@endif</p>
    @if($canResetAccount)
    <form method="post" action="/admin/krama/people/{{ $row->id }}/account/reset" class="mt-4" data-confirm="Kembalikan kata sandi {{ $row->name }} ke kata sandi awal &quot;{{ \App\Modules\Krama\AccountManager::DEFAULT_PASSWORD }}&quot;?">@csrf<button class="button-secondary" type="submit">Reset kata sandi ke awal</button></form>
    @endif
    @elseif($row->status !== 'ACTIVE')
    <p class="muted mt-2">Akun hanya bisa diaktifkan untuk krama berstatus aktif.</p>
    @elseif(!$row->nik)
    <p class="muted mt-2">Isi NIK krama ini dulu (di bagian NIK di bawah) — NIK dipakai sebagai nama pengguna untuk login.</p>
    @else
    <p class="muted mt-2">Warga login di portal dengan NIK dan kata sandi awal <strong>{{ \App\Modules\Krama\AccountManager::DEFAULT_PASSWORD }}</strong>, lalu wajib menggantinya saat pertama masuk.</p>
    <form method="post" action="/admin/krama/people/{{ $row->id }}/account" class="mt-4">@csrf<button class="button" type="submit">Aktifkan akun warga</button></form>
    @endif
    @error('account')<p class="field-error mt-3" role="alert">{{ $message }}</p>@enderror
</section>
@endif
<section class="card form-width">
@if($canEdit)<form class="form-stack" method="post" action="/admin/krama/people{{ $row ? '/'.$row->id : '' }}">@csrf @if($row) @method('PATCH') @endif<input type="hidden" name="version" value="{{ $row->version ?? 0 }}">
<label>Nama lengkap<input name="name" required maxlength="150" autofocus value="{{ old('name', $row->name ?? '') }}"></label>
@if($lockedHousehold)
<input type="hidden" name="household_id" value="{{ $lockedHousehold->id }}">
<p class="help">Menambahkan anggota ke KK <strong>{{ $lockedHousehold->code }}</strong>. <a class="text-link" href="/admin/krama/people/create">Bukan KK ini? Pilih KK lain →</a></p>
@else
<label>Keluarga<select name="household_id" required><option value="">Pilih KK</option>@foreach($households as $household)<option value="{{ $household->id }}" @selected(old('household_id', $row->household_id ?? '') == $household->id)>{{ $household->code }}</option>@endforeach</select></label>
@endif
<label>Status<select name="status">@foreach(\App\Modules\Krama\KramaManager::STATUSES as $code => $label)<option value="{{ $code }}" @selected(old('status', $row->status ?? 'ACTIVE') === $code)>{{ $label }}</option>@endforeach</select></label>
@if($isHead)
<label>Status hubungan keluarga<input type="text" value="Kepala Keluarga" disabled></label>
<p class="help">Ditentukan lewat halaman KK, bukan lewat pilihan di sini. <a class="text-link" href="/admin/krama/households/{{ $row->household_id }}">Ganti kepala keluarga di sana →</a></p>
@else
<label>Status hubungan keluarga (opsional)<select name="relationship"><option value="">Belum diisi</option>@foreach(\App\Modules\Krama\KramaManager::RELATIONSHIPS as $code => $label)<option value="{{ $code }}" @selected(old('relationship', $row->relationship ?? '') === $code)>{{ $label }}</option>@endforeach</select></label>
@endif
<label>Tanggal lahir (opsional)<input type="date" name="birth_date" value="{{ old('birth_date', $row->birth_date ?? '') }}" max="{{ now()->format('Y-m-d') }}"></label><label>Telepon (opsional)<input name="phone" type="tel" maxlength="30" value="{{ old('phone', $row->phone ?? '') }}"></label>
@include('admin.krama.unit-choices')
<details><summary class="touch-link">NIK (opsional)</summary><p class="muted my-3">{{ \App\Modules\Krama\KramaManager::masked($row->nik ?? null) }}</p><label>Isi atau ganti NIK<input name="nik" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" autocomplete="off"><span class="help">16 digit. Kosongkan untuk mempertahankan NIK yang tersimpan.</span></label><label class="check-option"><input type="checkbox" name="clear_nik" value="1"><span>Hapus NIK yang tersimpan</span></label></details>
@if($access->tenant(auth()->user(), 'krama.configure'))<label>Akun untuk pengajuan mandiri<select name="user_id"><option value="">Belum terhubung</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('user_id', $row->user_id ?? '') == $account->id)>{{ $account->name }} · {{ $account->email ?? 'login dengan NIK' }}</option>@endforeach</select><span class="help">Akun dibuat melalui Akses pengurus oleh Super Admin dengan peran Krama.</span></label>@else<input type="hidden" name="user_id" value="{{ $row->user_id ?? '' }}">@endif
<button class="button">Simpan krama</button></form>
@else<dl class="form-stack"><div><dt>Status</dt><dd>{{ \App\Modules\Krama\KramaManager::STATUSES[$row->status] }}</dd></div><div><dt>Status hubungan keluarga</dt><dd>{{ $isHead ? 'Kepala Keluarga' : ($row->relationship ? \App\Modules\Krama\KramaManager::RELATIONSHIPS[$row->relationship] : 'Belum diisi') }}</dd></div><div><dt>NIK</dt><dd>{{ \App\Modules\Krama\KramaManager::masked($row->nik) }}</dd></div><div><dt>Tanggal lahir</dt><dd>{{ $row->birth_date ?: 'Belum diisi' }}</dd></div><div><dt>Telepon</dt><dd>{{ $row->phone ?: 'Belum diisi' }}</dd></div></dl>@endif
</section>
@endsection
