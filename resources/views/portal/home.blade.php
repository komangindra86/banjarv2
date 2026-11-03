@extends('layouts.app')
@section('title', 'Beranda')
@section('content')
@if(!$self)
<div class="page-heading"><div><span class="eyebrow">PORTAL KRAMA</span><h1>Rahajeng rauh</h1></div></div>
<div class="card empty-state"><h2>Akun belum terhubung</h2><p class="muted">Hubungi Admin Banjar agar akun ini dihubungkan ke data krama Anda.</p></div>
@else
<div class="page-heading"><div><span class="eyebrow">PORTAL KRAMA</span><h1>Rahajeng, {{ $self->name }}</h1><p class="muted">{{ $currentBanjar->name }}</p></div></div>
<div class="card-grid mb-6">

@if($canBill)
<section class="card"><span class="eyebrow">TAGIHAN SAYA</span><h2 class="mt-3">{{ \App\Modules\Finance\FinanceManager::money($bill->total) }}</h2>
@if($bill->count > 0)<p class="muted mt-2">{{ $bill->count }} tagihan belum lunas</p><a class="button mt-4" href="/payments">Bayar QRIS →</a>
@else<p class="muted mt-2">Tidak ada tagihan belum lunas.</p>@endif</section>
@endif

@if($canActivity)
<section class="card"><span class="eyebrow">KEGIATAN TERDEKAT</span>
@if($activity)<h2 class="mt-3 break-words">{{ $activity->title }}</h2><p class="muted mt-2">{{ \Carbon\Carbon::parse($activity->starts_at)->timezone('Asia/Makassar')->translatedFormat('l, d M Y · H:i') }} WITA{{ $activity->location ? ' · '.$activity->location : '' }}</p>
@else<h2 class="mt-3">Belum ada jadwal</h2><p class="muted mt-2">Kegiatan Banjar yang akan datang akan tampil di sini.</p>@endif
<a class="text-link mt-4" href="/aktivitas">Lihat semua kegiatan →</a></section>
@endif

@if($canFinance)
<section class="card"><span class="eyebrow">RIWAYAT PEMBAYARAN</span>
@if($lastPayment)<h2 class="mt-3">{{ \App\Modules\Finance\FinanceManager::money($lastPayment->amount) }}</h2><p class="muted mt-2 break-words">Terakhir: {{ $lastPayment->title }} · {{ $lastPayment->method === 'GATEWAY' ? 'QRIS' : 'Tunai' }} · {{ \Carbon\Carbon::parse($lastPayment->paid_at)->timezone('Asia/Makassar')->translatedFormat('d M Y') }}</p>
@else<h2 class="mt-3">Belum ada</h2><p class="muted mt-2">Pembayaran tunai maupun QRIS akan tercatat di sini.</p>@endif
<a class="text-link mt-4" href="/portal/riwayat-pembayaran">Lihat riwayat →</a></section>

<section class="card"><span class="eyebrow">LAPORAN KEUANGAN</span>
@if($report)<h2 class="mt-3 break-words">{{ $report->sangkep_title }}</h2><p class="muted mt-2">Saldo akhir {{ \App\Modules\Finance\FinanceManager::money(json_decode($report->computed_snapshot ?? '{}', true)['saldo_akhir'] ?? 0) }}</p>
@else<h2 class="mt-3">Belum ada laporan</h2><p class="muted mt-2">Laporan kas sangkep akan tampil setelah difinalkan.</p>@endif
<a class="text-link mt-4" href="/portal/laporan">Lihat laporan →</a></section>
@endif

@if($canLetter)
<section class="card"><span class="eyebrow">PENGAJUAN</span><h2 class="mt-3">{{ $pendingLetters }} sedang diproses</h2><a class="text-link mt-4" href="/portal/layanan">Ajukan surat →</a></section>
@endif

</div>

<div class="flex flex-wrap gap-3">
@if($canLetter)<a class="button-secondary" href="/portal">Layanan surat</a>@endif
@if($canActivity)<a class="button-secondary" href="/aktivitas">Aktivitas</a>@endif
<a class="button-secondary" href="/admin/krama/mine">Profil</a>
</div>
@endif
@endsection
