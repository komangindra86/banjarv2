@php($links = [])
@if($fa->tenant(auth()->user(), 'finance.view'))@php($links['/admin/finance'] = 'Ringkasan')@endif
@php($links['/admin/finance/events'] = 'Event tagihan')
@if($canCash)@php($links['/admin/finance/cash'] = 'Buku kas')@endif
@php($links['/admin/finance/bills'] = 'Tagihan')
@if($canCash)@php($links['/admin/finance/reports'] = 'Laporan')@endif
@if($canIssue || $canDedosan)@php($links['/admin/finance/settings'] = 'Jenis & tagihan')@endif
@if($canCash)@php($links['/admin/finance/settings'] = 'Jenis, tagihan & kategori')@endif
<nav class="inline-links mb-6" aria-label="Keuangan">
@foreach($links as $url => $label)
<a href="{{ $url }}" @if(request()->getPathInfo() === $url || ($url !== '/admin/finance' && str_starts_with(request()->getPathInfo(), $url))) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav>
