@extends('layouts.app')
@section('title', 'Tagihan')
@section('content')
<div class="page-heading"><div><span class="eyebrow">KEUANGAN</span><h1>Tagihan keluarga</h1><p class="muted">Periksa kewajiban dan catat pelunasan yang diterima.</p></div></div>
@include('finance.nav')
<form method="get" class="card form-stack form-width mb-6"><label>Cari kode KK<input name="q" maxlength="80" value="{{ $filters['q'] ?? '' }}"></label><label>Status<select name="status"><option value="">Semua status</option>@foreach(['UNPAID','PAID','VOID'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ \App\Modules\Finance\FinanceManager::STATUSES[$status] }}</option>@endforeach</select></label><button class="button-secondary">Tampilkan</button></form>
@include('finance.bill-list')<div class="mt-6">{{ $bills->links() }}</div>
@endsection
