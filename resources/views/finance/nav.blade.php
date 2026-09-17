<nav class="inline-links mb-6" aria-label="Keuangan">
@foreach(['/admin/finance' => 'Ringkasan', '/admin/finance/cash' => 'Buku kas', '/admin/finance/bills' => 'Tagihan', '/admin/finance/report' => 'Laporan', '/admin/finance/settings' => 'Jenis & aturan'] as $url => $label)
<a href="{{ $url }}" @if(request()->getPathInfo() === $url) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav>
