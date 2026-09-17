@php
    $assetPath = request()->getPathInfo();
    $assetActive = match (true) {
        str_starts_with($assetPath, '/admin/assets/loans') => '/admin/assets/loans',
        str_starts_with($assetPath, '/admin/assets/opname') => '/admin/assets/opname',
        default => '/admin/assets',
    };
@endphp
<nav class="inline-links mb-6" aria-label="Aset">
@foreach(['/admin/assets' => 'Inventaris', '/admin/assets/loans' => 'Peminjaman', '/admin/assets/opname' => 'Stock opname'] as $url => $label)
<a href="{{ $url }}" @if($assetActive === $url) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav>
