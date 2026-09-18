<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><title>Laporan Kas {{ $currentBanjar->name ?? '' }}</title>
<style>
    @page { margin: 40pt 36pt 56pt; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.5; color: #202020; }
    .text-center { text-align: center; }
    .muted { color: #666; font-size: 8pt; }
    .mt-6 { margin-top: 16pt; }
    .mt-2 { margin-top: 4pt; }
    .help { font-size: 7.5pt; color: #555; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    footer { position: fixed; bottom: -40pt; left: 0; right: 0; font-size: 7pt; color: #666; border-top: 0.5pt solid #bbb; padding-top: 5pt; text-align: center; }
</style></head><body>
<footer>Laporan Kas {{ $currentBanjar->name ?? '' }} · Versi {{ $report->version }} @if($report->status === 'FINALIZED') · Final @endif</footer>
@include('finance.reports._body', ['report' => $report, 'data' => $data])
</body></html>
