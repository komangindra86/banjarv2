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
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $rows->where(function ($q) use ($people, $term) {
                if ($people) {
                    $q->where('name', 'like', $term);
                } else {
                    $q->where('code', 'like', $term)->orWhereIn('id', $this->query->table('krama')->where('name', 'like', $term)->select('household_id'));
                }
            });
        }
        if (! empty($filters['status'])) {
            $rows->where('status', $filters['status']);
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

        return view('admin.krama.index', ['rows' => $rows->orderBy($people ? 'name' : 'code')->paginate(18)->withQueryString(), 'people' => $people, 'units' => $this->scope->units($request->user())->get()]);
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
        $accounts = $this->access->tenant($actor, 'krama.configure') ? $this->query->table('banjar_user_memberships')->join('users', 'users.id', '=', 'banjar_user_memberships.user_id')->where('banjar_user_memberships.status', 'ACTIVE')->select('users.id', 'users.name', 'users.email')->get() : collect();

        return view('admin.krama.person', ['row' => $row, 'canEdit' => $canEdit, 'households' => $households, 'accounts' => $accounts, 'units' => $this->scope->units($actor, $canEdit ? 'krama.manage' : 'krama.view')->get(), 'selectedUnits' => $id ? $this->query->table('krama_unit_memberships')->where('krama_id', $id)->pluck('unit_id')->all() : []]);
    }

    public function savePerson(Request $request, KramaManager $manager, ?int $id = null)
    {
        $id = $manager->person($request->user(), $id, $request->all());

        return redirect('/admin/krama/people/'.$id)->with('success', 'Data krama disimpan.');
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

        return view('admin.krama.mine', ['row' => $this->query->table('krama')->where('user_id', $request->user()->id)->first()]);
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
