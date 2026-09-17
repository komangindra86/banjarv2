@extends('layouts.app')
@section('title', 'Buat peminjaman')
@section('content')
<a href="/admin/assets/loans" class="back-link">← Peminjaman</a>
<div class="page-heading"><div><span class="eyebrow">ASET BANJAR</span><h1>Buat peminjaman</h1><p class="muted">Simpan peminjaman lalu tambahkan aset yang dipinjam.</p></div></div>

<section class="card form-width mb-6"><h2>Peminjam krama terdaftar</h2><form class="form-stack mt-3" method="get"><label>Cari nama krama<input name="q" maxlength="100" value="{{ $search }}" placeholder="Nama krama"></label><button class="button-secondary">Cari</button></form>
@if($search !== '')<div class="grid gap-3 mt-4">@forelse($candidates as $candidate)<details class="border-t pt-3"><summary class="cursor-pointer py-2 font-semibold">{{ $candidate->name }}</summary><form class="form-stack mt-3" method="post" action="/admin/assets/loans">@csrf<input type="hidden" name="borrower_krama_id" value="{{ $candidate->id }}"><label>Keperluan<input name="purpose" maxlength="255" required placeholder="Ngayah bersih Banjar"></label><label>Tanggal pinjam<input name="loan_date" type="date" required value="{{ now('Asia/Makassar')->toDateString() }}"></label><label>Rencana kembali<input name="due_date" type="date"></label><button class="button">Buat peminjaman untuk {{ $candidate->name }}</button></form></details>@empty<p class="muted">Tidak ditemukan krama aktif dengan nama tersebut.</p>@endforelse</div>@endif
</section>

<section class="card form-width"><h2>Peminjam luar (bukan krama terdaftar)</h2><form class="form-stack mt-3" method="post" action="/admin/assets/loans">
@csrf
<label>Nama peminjam<input name="borrower_name" maxlength="150" required value="{{ old('borrower_name') }}"></label>
<label>Keperluan<input name="purpose" maxlength="255" required value="{{ old('purpose') }}"></label>
<label>Tanggal pinjam<input name="loan_date" type="date" required value="{{ old('loan_date', now('Asia/Makassar')->toDateString()) }}"></label>
<label>Rencana kembali<input name="due_date" type="date" value="{{ old('due_date') }}"></label>
<button class="button">Buat peminjaman</button>
</form></section>
@endsection
