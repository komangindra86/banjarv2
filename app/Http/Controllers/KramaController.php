<?php

namespace App\Http\Controllers;

use App\Modules\Krama\ChangeManager;
use App\Modules\Krama\KramaAccess;
use App\Modules\Krama\KramaManager;
use App\Platform\Identity\Access;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class KramaController extends Controller
{
    public function __construct(private KramaAccess $scope, private TenantQuery $query, private Access $access) {}

    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'unit' => ['nullable', 'integer'], 'status' => ['nullable', 'in:ACTIVE,INACTIVE,MOVED,DECEASED'], 'tab' => ['nullable', 'in:households,people']]);
        $people = ($filters['tab'] ?? '') === 'people';
        $rows = $people ? $this->scope->people($request->user()) : $this->scope->households($request->user());
        $table = $people ? 'krama' : 'households';
        if ($people) {
            $rows->select('krama.*')
                ->leftJoin('households', 'households.id', '=', 'krama.household_id')
                ->addSelect(['households.code as household_code', 'households.head_krama_id as household_head_krama_id']);
        } else {
            $rows->select('households.*')
                ->leftJoin('krama as head', 'head.id', '=', 'households.head_krama_id')
                ->addSelect('head.name as head_name')
                ->selectSub($this->query->table('krama')->whereColumn('krama.household_id', 'households.id')->selectRaw('count(*)'), 'members_count');
        }
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $rows->where(function ($q) use ($people, $term, $table) {
                if ($people) {
                    $q->where('name', 'like', $term);
                } else {
                    $q->where('code', 'like', $term)->orWhereIn($table.'.id', $this->query->table('krama')->where('name', 'like', $term)->select('household_id'));
                }
            });
        }
        if (! empty($filters['status'])) {
            $rows->where($table.'.status', $filters['status']);
        }
        if (! empty($filters['unit'])) {
            abort_unless($this->scope->units($request->user())->where('id', $filters['unit'])->exists(), 404);
            $rows->where(function ($q) use ($table, $people, $filters) {
                $q->whereIn($table.'.id', $this->query->table($people ? 'krama_unit_memberships' : 'household_unit_memberships')->where('unit_id', $filters['unit'])->select($people ? 'krama_id' : 'household_id'));
                if ($people) {
                    $q->orWhereIn('household_id', $this->query->table('household_unit_memberships')->where('unit_id', $filters['unit'])->select('household_id'));
                }
            });
        }

        return view('admin.krama.index', ['rows' => $rows->orderBy($people ? 'name' : 'code')->paginate(30)->withQueryString(), 'people' => $people, 'units' => $this->scope->units($request->user())->get()]);
    }

    public function household(Request $request, ?int $id = null)
    {
        $actor = $request->user();
        $row = $id ? $this->scope->households($actor)->where('id', $id)->first() : null;
        abort_if($id && ! $row, 404);
        if (! $id) {
            $this->scope->authorize($actor, 'krama.manage');
        }
        $canEdit = $this->access->tenant($actor, 'krama.manage') && (! $id || $this->scope->households($actor, 'krama.manage')->where('id', $id)->exists());

        return view('admin.krama.household', ['row' => $row, 'canEdit' => $canEdit, 'members' => $id ? $this->query->table('krama')->where('household_id', $id)->orderBy('name')->get() : collect(), 'units' => $this->scope->units($actor, $canEdit ? 'krama.manage' : 'krama.view')->get(), 'selectedUnits' => $id ? $this->query->table('household_unit_memberships')->where('household_id', $id)->pluck('unit_id')->all() : []]);
    }

    public function saveHousehold(Request $request, KramaManager $manager, ?int $id = null)
    {
        $id = $manager->household($request->user(), $id, $request->all());

        return redirect('/admin/krama/households/'.$id)->with('success', 'Data KK disimpan.');
    }

    public function person(Request $request, ?int $id = null)
    {
        $actor = $request->user();
        $row = $id ? $this->scope->people($actor)->where('id', $id)->first() : null;
        abort_if($id && ! $row, 404);
        if (! $id) {
            $this->scope->authorize($actor, 'krama.manage');
        }
        $canEdit = $this->access->tenant($actor, 'krama.manage') && (! $id || $this->scope->people($actor, 'krama.manage')->where('id', $id)->exists());
        $households = $canEdit ? $this->scope->households($actor, 'krama.manage')->orderBy('code')->get() : collect();
        if ($row && ! $households->contains('id', $row->household_id)) {
            // Only show a neutral reference when the household itself is outside scope.
            $households->push((object) ['id' => $row->household_id, 'code' => 'KK saat ini']);
        }
        // Datang dari "+ Anggota" pada halaman KK: kunci KK-nya, jangan tampilkan pemilih bebas ke semua KK lain.
        $lockedHousehold = (! $id && $request->filled('household')) ? $households->firstWhere('id', $request->integer('household')) : null;
        // "Kepala Keluarga" hanya ditentukan lewat halaman KK (head_krama_id), bukan lewat pilihan hubungan di sini.
        $isHead = $row && $this->query->table('households')->where('id', $row->household_id)->where('head_krama_id', $row->id)->exists();
        $accounts = $this->access->tenant($actor, 'krama.configure') ? $this->query->table('banjar_user_memberships')->join('users', 'users.id', '=', 'banjar_user_memberships.user_id')->where('banjar_user_memberships.status', 'ACTIVE')->select('users.id', 'users.name', 'users.email')->get() : collect();
        // Patus dikenakan setiap ada kematian: begitu status krama "Meninggal", tawarkan jalan pintas
        // membuat draf tagihan patus langsung dari sini — jenis, judul, kode, dan tanggal sudah terisi
        // otomatis, tinggal konfirmasi (lihat dialog di admin.krama.person dan promptPatus di savePerson()).
        $canPatus = $row && $row->status === 'DECEASED' && $this->access->tenant($actor, 'billing.issue');
        $patusTypes = $canPatus ? $this->query->table('billing_types')->where('kind', 'PATUS')->where('is_active', true)->orderBy('name')->get() : collect();
        // Kalau cuma ada satu jenis Patus (kasus paling umum), judul otomatis pakai namanya. Kalau lebih
        // dari satu (mis. nominal berbeda per situasi), dialog menampilkan pilihan alih-alih menebak.
        $patusType = $patusTypes->count() === 1 ? $patusTypes->first() : null;
        $patusDraft = null;
        if ($patusTypes->isNotEmpty()) {
            $now = now('Asia/Makassar');
            $patusDraft = ['title' => 'Patus - '.$row->name, 'reference' => 'PATUS-'.$now->format('Ymd-His'), 'event_date' => $now->toDateString()];
        }

        return view('admin.krama.person', ['row' => $row, 'canEdit' => $canEdit, 'households' => $households, 'lockedHousehold' => $lockedHousehold, 'isHead' => $isHead, 'accounts' => $accounts, 'canPatus' => $canPatus, 'patusTypes' => $patusTypes, 'patusType' => $patusType, 'patusDraft' => $patusDraft, 'units' => $this->scope->units($actor, $canEdit ? 'krama.manage' : 'krama.view')->get(), 'selectedUnits' => $id ? $this->query->table('krama_unit_memberships')->where('krama_id', $id)->pluck('unit_id')->all() : []]);
    }

    public function savePerson(Request $request, KramaManager $manager, ?int $id = null)
    {
        $creating = ! $id;
        // Dicatat sebelum disimpan supaya bisa dibandingkan setelahnya — lihat catatan auto-clear di
        // KramaManager::person(): kalau orang ini kepala keluarga suatu KK sebelum perubahan, tapi
        // sesudahnya bukan lagi, KK itu baru saja kehilangan kepala keluarganya secara otomatis.
        $wasHeadOf = $id ? $this->query->table('households')->where('head_krama_id', $id)->value('code') : null;
        $wasDeceased = $id && $this->query->table('krama')->where('id', $id)->value('status') === 'DECEASED';
        $newId = $manager->person($request->user(), $id, $request->all());
        if ($creating) {
            // Kembali ke daftar anggota KK, bukan ke halaman krama yang baru dibuat sendirian.
            return redirect('/admin/krama/households/'.$request->integer('household_id'))->with('success', 'Anggota ditambahkan.');
        }
        $message = 'Data krama disimpan.';
        if ($wasHeadOf && ! $this->query->table('households')->where('head_krama_id', $newId)->exists()) {
            $message .= ' KK '.$wasHeadOf.' kini belum memiliki kepala keluarga — tetapkan penggantinya di halaman KK.';
        }
        $redirect = redirect('/admin/krama/people/'.$newId)->with('success', $message);
        // Baru saja berubah jadi Meninggal (bukan yang memang sudah lama begitu): tawarkan langsung
        // lewat dialog konfirmasi di halaman krama, bukan diam-diam menaruh tautan yang mudah terlewat.
        if (! $wasDeceased && $request->input('status') === 'DECEASED') {
            $redirect->with('promptPatus', true);
        }

        return $redirect;
    }

    public function units(Request $request)
    {
        $this->scope->authorize($request->user(), 'units.manage');

        return view('admin.krama.units', ['units' => $this->query->table('organizational_units')->orderBy('name')->get(), 'policy' => $this->query->table('banjar_settings')->value('krama_changes_require_approval')]);
    }

    public function saveUnit(Request $request, KramaManager $manager, ?int $id = null)
    {
        $manager->unit($request->user(), $id, $request->all());

        return redirect('/admin/krama/units')->with('success', 'Unit organisasi disimpan.');
    }

    public function policy(Request $request, KramaManager $manager)
    {
        $manager->policy($request->user(), $request->all());

        return back()->with('success', 'Aturan pengajuan berikutnya diperbarui. Pengajuan yang sudah masuk tetap mengikuti aturan sebelumnya.');
    }

    public function mine(Request $request)
    {
        $this->scope->authorize($request->user(), 'krama.self');
        $row = $this->query->table('krama')->where('user_id', $request->user()->id)->first();
        $isHead = $row && $this->query->table('households')->where('id', $row->household_id)->where('head_krama_id', $row->id)->exists();

        return view('admin.krama.mine', ['row' => $row, 'isHead' => $isHead]);
    }

    public function changes(Request $request, ChangeManager $manager)
    {
        return view('admin.krama.changes', ['rows' => $manager->visible($request->user())->orderByDesc('id')->paginate(20)]);
    }

    public function changeForm(Request $request, int $id, ChangeManager $manager)
    {
        $request->validate(['field' => ['nullable', Rule::in(array_keys(KramaManager::FIELDS))]]);

        return view('admin.krama.change-form', ['row' => $manager->target($request->user(), $id)]);
    }

    public function submitChange(Request $request, int $id, ChangeManager $manager)
    {
        $change = $manager->submit($request->user(), $id, $request->all());

        return redirect('/admin/krama/changes/'.$change)->with('success', 'Pengajuan dikirim untuk diverifikasi.');
    }

    public function change(Request $request, int $id, ChangeManager $manager)
    {
        $row = $manager->visible($request->user())->where('id', $id)->first();
        abort_unless($row, 404);
        $items = $this->query->table('krama_change_items')->where('request_id', $id)->get();
        foreach ($items as $item) {
            foreach (['old_value', 'new_value'] as $field) {
                $value = json_decode(Crypt::decryptString($item->$field), true);
                $item->$field = $item->field === 'nik' && $value ? '•••• •••• •••• '.substr($value, -4) : ($value ?? 'Kosong');
            }
        }
        $permission = $row->status === 'SUBMITTED' ? 'change.verify' : 'change.approve';
        $canReview = in_array($row->status, ['SUBMITTED', 'VERIFIED']) && $row->requester_id !== $request->user()->id && ($row->status !== 'VERIFIED' || $row->verifier_id !== $request->user()->id) && $this->access->tenant($request->user(), $permission) && $this->scope->people($request->user(), $permission)->where('id', $row->krama_id)->exists();

        $subject = $this->query->table('krama')->where('id', $row->krama_id)->value('name');
        $actors = DB::table('users')->whereIn('id', array_filter([$row->requester_id, $row->verifier_id, $row->approver_id]))->pluck('name', 'id');

        return view('admin.krama.change', compact('row', 'items', 'canReview', 'subject', 'actors'));
    }

    public function review(Request $request, int $id, ChangeManager $manager)
    {
        $status = $manager->review($request->user(), $id, $request->all());

        return back()->with('success', $status === 'CONFLICT' ? 'Data master sudah berubah. Pengajuan ditandai konflik; ajukan ulang berdasarkan data terbaru.' : 'Hasil pemeriksaan disimpan.');
    }
}
