<nav class="inline-links mb-6" aria-label="Pencairan">
@foreach(['/admin/payments' => 'Dana QRIS', '/admin/withdrawals' => 'Pencairan', '/admin/withdrawals/accounts' => 'Rekening Banjar'] as $url => $label)
<a href="{{ $url }}" @if(request()->getPathInfo() === $url) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav>
