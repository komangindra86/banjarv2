@php($money = fn ($n) => number_format((int) $n, 0, ',', '.'))
<style>
    .finance-report table { width: 100%; border-collapse: collapse; margin-top: 10pt; font-size: 13px; }
    .finance-report th, .finance-report td { border: 1px solid #ccc; padding: 6px 8px; vertical-align: top; }
    .finance-report th { background: #f3ede3; font-size: 11px; text-transform: uppercase; }
    .finance-report td.num, .finance-report th.num { text-align: right; white-space: nowrap; }
    .finance-report tr.group-header td { background: #f2ece2; font-weight: bold; }
    .finance-report tr.opening td { background: #f7f3ec; font-weight: bold; }
    .finance-report tr.subtotal-in td { font-weight: bold; color: #1a4fa0; }
    .finance-report tr.subtotal-out td { font-weight: bold; color: #a01a1a; }
    .finance-report tr.total-row td { background: #e9e2d3; font-weight: bold; }
    .finance-report .signatures { margin-top: 26px; width: 100%; }
    .finance-report .signatures td { border: none; padding: 0 18px; width: 50%; text-align: center; vertical-align: top; }
    .finance-report .signatures .space { height: 44px; }
    @media print { .print\:hidden { display: none !important; } }
</style>
<div class="finance-report">
<h1 class="text-center">LAPORAN KAS {{ mb_strtoupper($currentBanjar->name ?? '') }}</h1>
<p class="text-center">{{ $report->sangkep_title }}@if($report->sangkep_date) · {{ \Carbon\Carbon::parse($report->sangkep_date)->translatedFormat('d F Y') }}@endif</p>
<p class="text-center muted">Periode {{ \Carbon\Carbon::parse($data['period_from'])->translatedFormat('d F Y') }} s.d. {{ \Carbon\Carbon::parse($data['period_to'])->translatedFormat('d F Y') }} · dalam rupiah</p>

<table>
<thead><tr><th>TGL.</th><th>KETERANGAN</th><th class="num">MASUK</th><th class="num">KELUAR</th><th class="num">SALDO</th></tr></thead>
<tbody>
<tr class="opening"><td colspan="2">SALDO PINDAHAN SANGKEP TGL. {{ \Carbon\Carbon::parse($data['period_from'])->subDay()->translatedFormat('d F Y') }}</td><td class="num"></td><td class="num"></td><td class="num">{{ $money($data['opening_balance']) }}</td></tr>

<tr class="group-header"><td colspan="5">PENDAPATAN/PEMASUKAN</td></tr>
@forelse($data['pendapatan'] as $group)
@if(empty($group['lines']))
<tr><td colspan="4">{{ $group['category']['name'] }}</td><td class="num">NIHIL</td></tr>
@else
<tr><td colspan="5"><strong>{{ $group['category']['name'] }}</strong></td></tr>
@foreach($group['lines'] as $line)
<tr><td>{{ \Carbon\Carbon::parse($line['transaction_date'])->format('d/m/Y') }}</td><td>{{ \Illuminate\Support\Facades\Crypt::decryptString($line['reason']) }}</td><td class="num">{{ $money($line['amount']) }}</td><td class="num"></td><td class="num"></td></tr>
@endforeach
<tr class="subtotal-in"><td colspan="2">Subtotal {{ $group['category']['name'] }}</td><td class="num">{{ $money($group['subtotal']) }}</td><td class="num"></td><td class="num">{{ $money($group['subtotal']) }}</td></tr>
@endif
@empty
<tr><td colspan="5">Tidak ada kategori pemasukan.</td></tr>
@endforelse

<tr class="group-header"><td colspan="5">PENGELUARAN/BIAYA</td></tr>
@forelse($data['pengeluaran'] as $group)
@if(empty($group['lines']))
<tr><td colspan="4">{{ $group['category']['name'] }}</td><td class="num">NIHIL</td></tr>
@else
<tr><td colspan="5"><strong>{{ $group['category']['name'] }}</strong></td></tr>
@foreach($group['lines'] as $line)
<tr><td>{{ \Carbon\Carbon::parse($line['transaction_date'])->format('d/m/Y') }}</td><td>{{ \Illuminate\Support\Facades\Crypt::decryptString($line['reason']) }}</td><td class="num"></td><td class="num">{{ $money($line['amount']) }}</td><td class="num"></td></tr>
@endforeach
<tr class="subtotal-out"><td colspan="3">Subtotal {{ $group['category']['name'] }}</td><td class="num">{{ $money($group['subtotal']) }}</td><td class="num">{{ $money($group['subtotal']) }}</td></tr>
@endif
@empty
<tr><td colspan="5">Tidak ada kategori pengeluaran.</td></tr>
@endforelse

<tr class="total-row"><td colspan="2">TOTAL SALDO/KAS</td><td class="num">{{ $money($data['total_pemasukan']) }}</td><td class="num">{{ $money($data['total_pengeluaran']) }}</td><td class="num">{{ $money($data['saldo_akhir']) }}</td></tr>
</tbody>
</table>

<h2 class="mt-6">POSISI DANA</h2>
<table>
<thead><tr><th>Komponen</th><th class="num">Nilai</th></tr></thead>
<tbody>
@foreach($data['accounts'] as $account)
<tr><td>{{ $account['name'] }} ({{ $account['type'] === 'BANK' ? 'Bank' : 'Kas tunai' }})</td><td class="num">{{ $money($account['balance']) }}</td></tr>
@endforeach
<tr><td>Piutang ({{ $data['piutang']['count'] }} tagihan belum lunas)</td><td class="num">{{ $money($data['piutang']['total']) }}</td></tr>
<tr class="total-row"><td>Total posisi dana termasuk piutang</td><td class="num">{{ $money($data['posisi_dana_total']) }}</td></tr>
</tbody>
</table>
<p class="help mt-2">Total posisi dana termasuk piutang; kas dan bank ditampilkan terpisah pada baris di atas. Piutang bukan uang kas yang tersedia untuk dibelanjakan.</p>

<table class="signatures">
<tr>
<td><p>Yang Membuat,</p><div class="space"></div><p><strong>{{ $report->signer_treasurer_name ?? '............................' }}</strong></p><p>Petengen / Bendahara</p></td>
<td><p>Mengetahui,</p><div class="space"></div><p><strong>{{ $report->signer_kelian_name ?? '............................' }}</strong></p><p>Kelian Adat</p></td>
</tr>
</table>
</div>
