@extends('layouts.app')
@section('title', 'Keluarga & krama')
@section('content')
<div class="page-heading"><div><span class="eyebrow">DATA BANJAR</span><h1>Keluarga & krama</h1><p class="muted">Kelola keluarga dan anggota dalam wilayah tugas Anda.</p></div>@if($access->tenant(auth()->user(), 'krama.manage'))<a class="button" href="/admin/krama/{{ $people ? 'people' : 'households' }}/create">+ {{ $people ? 'Tambah krama' : 'Tambah KK' }}</a>@endif</div>
@include('admin.krama.nav')
<div class="inline-links mb-6"><a href="/admin/krama" @if(!$people) aria-current="page" @endif>Kartu keluarga</a><a href="/admin/krama?tab=people" @if($people) aria-current="page" @endif>Daftar krama</a></div>
<form class="card krama-filters mb-6" method="get"><input type="hidden" name="tab" value="{{ $people ? 'people' : 'households' }}"><label>Cari {{ $people ? 'nama krama' : 'kode KK / nama anggota' }}<input name="q" value="{{ request('q') }}" maxlength="100" placeholder="Ketik nama atau kode"></label><label>Unit<select name="unit"><option value="">Semua unit</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(request('unit') == $unit->id)>{{ $unit->name }}</option>@endforeach</select></label><label>Status<select name="status"><option value="">Semua status</option><option value="ACTIVE" @selected(request('status') === 'ACTIVE')>Aktif</option><option value="INACTIVE" @selected(request('status') === 'INACTIVE')>Nonaktif</option>@if($people)<option value="MOVED" @selected(request('status') === 'MOVED')>Pindah</option><option value="DECEASED" @selected(request('status') === 'DECEASED')>Meninggal</option>@endif</select></label><button class="button-secondary">Cari</button></form>
<p class="muted mb-4">{{ $rows->total() }} {{ $people ? 'krama' : 'KK' }} ditemukan</p>
@if($rows->isEmpty())
<section class="card empty-state"><h2>Belum ada data yang cocok</h2><p class="muted">Tambahkan data atau ubah pencarian dan filter.</p></section>
@else
<div class="table-scroll"><table class="data-table"><thead>
@if($people)
<tr><th>Nama</th><th>KK</th><th>Hubungan</th><th>Status</th></tr>
@else
<tr><th>Kode KK</th><th>Kepala keluarga</th><th>Anggota</th><th>Status</th></tr>
@endif
</thead><tbody>
@foreach($rows as $row)
@if($people)
@php($relationLabel = $row->household_head_krama_id == $row->id ? 'Kepala Keluarga' : ($row->relationship ? \App\Modules\Krama\KramaManager::RELATIONSHIPS[$row->relationship] : '—'))
<tr>
    <td><a href="/admin/krama/people/{{ $row->id }}">{{ $row->name }}</a></td>
    <td class="muted">{{ $row->household_code ?? '—' }}</td>
    <td class="muted">{{ $relationLabel }}</td>
    <td><span class="badge">{{ \App\Modules\Krama\KramaManager::STATUSES[$row->status] }}</span></td>
</tr>
@else
<tr>
    <td><a href="/admin/krama/households/{{ $row->id }}">{{ $row->code }}</a></td>
    <td class="muted">{{ $row->head_name ?? 'Belum ditetapkan' }}</td>
    <td class="muted">{{ $row->members_count }} orang</td>
    <td><span class="badge">{{ \App\Modules\Krama\KramaManager::STATUSES[$row->status] }}</span></td>
</tr>
@endif
@endforeach
</tbody></table></div>
@endif
<div class="mt-6">{{ $rows->links() }}</div>
@endsection

