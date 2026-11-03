<?php

namespace App\Modules\Krama;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class KramaManager
{
    public const FIELDS = ['name' => 'Nama', 'nik' => 'NIK', 'birth_date' => 'Tanggal lahir', 'phone' => 'Telepon', 'status' => 'Status', 'relationship' => 'Status hubungan keluarga'];

    public const STATUSES = ['ACTIVE' => 'Aktif', 'INACTIVE' => 'Nonaktif', 'MOVED' => 'Pindah', 'DECEASED' => 'Meninggal'];

    // "Kepala Keluarga" bukan pilihan di sini — itu ditentukan lewat households.head_krama_id (lihat household.blade.php)
    // dan selalu menimpa label hubungan yang tersimpan pada anggota tersebut.
    public const RELATIONSHIPS = ['ISTRI_SUAMI' => 'Istri/Suami', 'ANAK' => 'Anak', 'ORANG_TUA' => 'Orang Tua', 'MERTUA' => 'Mertua', 'FAMILI_LAIN' => 'Famili Lain'];

    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private KramaAccess $access, private Audit $audit) {}

    private function lock(): void
    {
        // All module writes serialize against this row, including tree and scope changes.
        DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->first();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function version(object $row, array $input): void
    {
        if ((int) ($input['version'] ?? 0) !== $row->version) {
            $this->fail('version', 'Data sudah berubah. Muat ulang halaman sebelum menyimpan.');
        }
    }

    private function unitInput(User $actor, array $input): array
    {
        $data = Validator::make($input, ['unit_ids' => ['nullable', 'array', 'max:30'], 'unit_ids.*' => ['integer', 'distinct']])->validate();
        $ids = array_map('intval', $data['unit_ids'] ?? []);
        $allowed = $this->access->units($actor, 'krama.manage')->where('is_active', true)->pluck('id')->all();
        if (array_diff($ids, $allowed)) {
            $this->fail('unit_ids', 'Unit tidak aktif atau berada di luar akses Anda.');
        }

        return $ids;
    }

    private function syncUnits(string $entity, int $id, array $ids, ?array $scope): void
    {
        // A scoped operator may change only memberships in their own scope.
        $this->query->table($entity.'_unit_memberships')->where($entity.'_id', $id)
            ->when($scope !== null, fn ($q) => $q->whereIn('unit_id', $scope))->delete();
        if ($ids) {
            $now = now();
            $rows = array_map(fn ($unit) => ['banjar_id' => $this->tenant->id(), $entity.'_id' => $id, 'unit_id' => $unit, 'created_at' => $now, 'updated_at' => $now], $ids);
            DB::table($entity.'_unit_memberships')->insertOrIgnore($rows);
        }
    }

    public function household(User $actor, ?int $id, array $input): int
    {
        $this->access->authorize($actor, 'krama.manage');

        return DB::transaction(function () use ($actor, $id, $input) {
            $this->lock();
            $old = $id ? $this->access->households($actor, 'krama.manage')->where('id', $id)->first() : null;
            abort_if($id && ! $old, 404);
            if ($old) {
                $this->version($old, $input);
            }
            $data = Validator::make($input, [
                'code' => ['required', 'string', 'max:40', Rule::unique('households')->where('banjar_id', $this->tenant->id())->ignore($id)],
                'address' => ['nullable', 'string', 'max:1000'], 'family_number' => ['nullable', 'regex:/^[0-9]{16}$/'],
                'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])], 'head_krama_id' => ['nullable', 'integer'],
                'clear_family_number' => ['sometimes', 'boolean'],
                // Anggota pertama hanya berlaku saat membuat KK baru, agar KK + kepala keluarga bisa selesai dalam satu formulir.
                'head_name' => ['nullable', 'string', 'max:150'],
            ])->validate();
            $headName = $id ? null : trim((string) ($data['head_name'] ?? ''));
            unset($data['head_name']);
            $head = $data['head_krama_id'] ?? null;
            if ($head && (! $id || ! $this->query->table('krama')->where('id', $head)->where('household_id', $id)->where('status', 'ACTIVE')->exists())) {
                $this->fail('head_krama_id', 'Kepala keluarga harus anggota aktif KK ini.');
            }
            $data['head_krama_id'] = $head;
            if (! empty($data['family_number'])) {
                $data['family_number'] = Crypt::encryptString($data['family_number']);
            } elseif (! empty($data['clear_family_number'])) {
                $data['family_number'] = null;
            } else {
                unset($data['family_number']);
            }
            unset($data['clear_family_number']);
            $units = $this->unitInput($actor, $input);
            $scope = $this->access->unitIds($actor, 'krama.manage');
            if ($scope !== null && ! $units) {
                $this->fail('unit_ids', 'Pilih minimal satu unit dalam akses Anda.');
            }
            $data['version'] = $old ? $old->version + 1 : 1;
            $data['updated_at'] = now();
            if ($id) {
                $this->query->table('households')->where('id', $id)->update($data);
            } else {
                $id = DB::table('households')->insertGetId($data + ['banjar_id' => $this->tenant->id(), 'created_at' => now()]);
            }
            $this->syncUnits('household', $id, $units, $scope);
            $this->audit->record($this->tenant->id(), $actor->id, $old ? 'household.updated' : 'household.created', 'household', $id);

            if ($headName !== '' && $headName !== null) {
                // Anggota baru mewarisi akses lewat unit KK-nya sendiri; tidak perlu unit_ids terpisah.
                $headId = DB::table('krama')->insertGetId(['banjar_id' => $this->tenant->id(), 'household_id' => $id, 'name' => $headName,
                    'status' => 'ACTIVE', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $this->query->table('households')->where('id', $id)->update(['head_krama_id' => $headId, 'updated_at' => now()]);
                $this->audit->record($this->tenant->id(), $actor->id, 'krama.created', 'krama', $headId);
            }

            return $id;
        });
    }

    public function personRules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'nik' => ['nullable', 'regex:/^[0-9]{16}$/'], 'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'phone' => ['nullable', 'regex:/^[+0-9 ()-]{6,30}$/'], 'status' => ['required', Rule::in(array_keys(self::STATUSES))], 'relationship' => ['nullable', Rule::in(array_keys(self::RELATIONSHIPS))]];
    }

    public function person(User $actor, ?int $id, array $input): int
    {
        $this->access->authorize($actor, 'krama.manage');

        return DB::transaction(function () use ($actor, $id, $input) {
            $this->lock();
            $old = $id ? $this->access->people($actor, 'krama.manage')->where('id', $id)->first() : null;
            abort_if($id && ! $old, 404);
            if ($old) {
                $this->version($old, $input);
            }
            $data = Validator::make($input, $this->personRules() + ['household_id' => ['required', 'integer'], 'user_id' => ['nullable', 'integer'], 'clear_nik' => ['sometimes', 'boolean']])->validate();
            // "nullable" lets an empty <select> value pass validation as '', but the ENUM column rejects '' — normalize.
            if (($data['relationship'] ?? null) === '') {
                $data['relationship'] = null;
            }
            // Editing a unit-specific person does not allow moving an inaccessible household.
            $household = $this->query->table('households')->where('id', $data['household_id'])->first();
            abort_unless($household, 404);
            if (! $old || $old->household_id !== (int) $data['household_id']) {
                abort_unless($this->access->households($actor, 'krama.manage')->where('id', $data['household_id'])->exists(), 404);
                if ($old) {
                    abort_unless($this->access->households($actor, 'krama.manage')->where('id', $old->household_id)->exists(), 404);
                }
                if ($household->status !== 'ACTIVE') {
                    $this->fail('household_id', 'KK tujuan harus aktif.');
                }
            }
            // Kepala keluarga yang pindah KK atau tidak lagi aktif (meninggal/nonaktif/pindah) otomatis
            // dilepas dari KK asalnya — Kesinoman tidak perlu bolak-balik ke halaman KK untuk mengosongkan
            // kepala keluarga secara manual dulu sebelum bisa menyimpan perubahan ini. KK yang jadi tanpa
            // kepala keluarga otomatis dikecualikan dari dedosan/iuran/patus sampai penggantinya ditetapkan.
            if ($old && ($old->household_id !== (int) $data['household_id'] || $data['status'] !== 'ACTIVE')) {
                $vacated = $this->query->table('households')->where('head_krama_id', $id)->first();
                if ($vacated) {
                    $this->query->table('households')->where('id', $vacated->id)->update(['head_krama_id' => null, 'updated_at' => now()]);
                    $this->audit->record($this->tenant->id(), $actor->id, 'krama.head_auto_cleared', 'households', $vacated->id, ['head_krama_id' => $id], ['head_krama_id' => null]);
                }
            }
            $scope = $this->access->unitIds($actor, 'krama.manage');
            $userId = array_key_exists('user_id', $data) ? $data['user_id'] : ($old->user_id ?? null);
            if ($userId != ($old->user_id ?? null)) {
                $this->access->authorize($actor, 'krama.configure');
                if ($userId && ! $this->query->table('banjar_user_memberships')->where('user_id', $userId)->where('status', 'ACTIVE')->exists()) {
                    $this->fail('user_id', 'Akun harus menjadi anggota aktif Banjar ini.');
                }
            }
            if ($userId && $this->query->table('krama')->where('user_id', $userId)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                $this->fail('user_id', 'Akun sudah terhubung ke krama lain.');
            }
            $data['user_id'] = $userId;
            if (! empty($data['nik'])) {
                // Akun portal warga login dengan NIK: NIK baru ikut memperbarui nama pengguna akunnya.
                if ($userId && DB::table('users')->where('id', $userId)->whereNotNull('nik_hash')->exists()) {
                    $hash = AccountManager::nikHash($data['nik']);
                    if (DB::table('users')->where('nik_hash', $hash)->where('id', '<>', $userId)->exists()) {
                        $this->fail('nik', 'NIK ini sudah dipakai akun warga lain.');
                    }
                    DB::table('users')->where('id', $userId)->update(['nik_hash' => $hash, 'updated_at' => now()]);
                }
                $data['nik'] = Crypt::encryptString($data['nik']);
            } elseif (! empty($data['clear_nik'])) {
                $data['nik'] = null;
            } else {
                unset($data['nik']);
            }
            unset($data['clear_nik']);
            $units = $this->unitInput($actor, $input);
            if ($scope !== null && ! $this->access->households($actor, 'krama.manage')->where('id', $data['household_id'])->exists() && ! $units) {
                $this->fail('unit_ids', 'Pertahankan minimal satu unit dalam akses Anda.');
            }
            $data['version'] = $old ? $old->version + 1 : 1;
            $data['updated_at'] = now();
            if ($id) {
                $this->query->table('krama')->where('id', $id)->update($data);
            } else {
                $id = DB::table('krama')->insertGetId($data + ['banjar_id' => $this->tenant->id(), 'created_at' => now()]);
            }
            $this->syncUnits('krama', $id, $units, $scope);
            $this->audit->record($this->tenant->id(), $actor->id, $old ? 'krama.updated' : 'krama.created', 'krama', $id);

            return $id;
        });
    }

    public function unit(User $actor, ?int $id, array $input): int
    {
        $this->access->authorize($actor, 'units.manage');

        return DB::transaction(function () use ($actor, $id, $input) {
            $this->lock();
            abort_if($id && ! $this->query->table('organizational_units')->where('id', $id)->exists(), 404);
            $data = Validator::make($input, ['name' => ['required', 'string', 'max:150'], 'type' => ['required', Rule::in(['TEMPEKAN', 'REGU', 'MAKSAN', 'OTHER'])], 'parent_id' => ['nullable', 'integer'], 'is_active' => ['required', 'boolean']])->validate();
            $parent = $data['parent_id'] ?? null;
            $visited = [];
            while ($parent) {
                if ((int) $parent === $id || in_array($parent, $visited)) {
                    $this->fail('parent_id', 'Struktur unit tidak boleh berputar.');
                }
                $visited[] = $parent;
                $row = $this->query->table('organizational_units')->where('id', $parent)->first();
                if (! $row || ! $row->is_active) {
                    $this->fail('parent_id', 'Unit induk harus aktif dan milik Banjar ini.');
                }
                $parent = $row->parent_id;
            }
            if (! $data['is_active'] && $id) {
                foreach (['organizational_units' => 'parent_id', 'household_unit_memberships' => 'unit_id', 'krama_unit_memberships' => 'unit_id', 'tenant_role_assignments' => 'unit_id'] as $table => $field) {
                    if ($this->query->table($table)->where($field, $id)->exists()) {
                        $this->fail('is_active', 'Pindahkan anggota, unit anak, dan akses pengurus sebelum menonaktifkan unit.');
                    }
                }
            }
            $data['updated_at'] = now();
            if ($id) {
                $this->query->table('organizational_units')->where('id', $id)->update($data);
            } else {
                $id = DB::table('organizational_units')->insertGetId($data + ['banjar_id' => $this->tenant->id(), 'created_at' => now()]);
            }
            $this->audit->record($this->tenant->id(), $actor->id, 'unit.saved', 'unit', $id);

            return $id;
        });
    }

    public function policy(User $actor, array $input): void
    {
        $this->access->authorize($actor, 'krama.configure');
        $data = Validator::make($input, ['krama_changes_require_approval' => ['required', 'boolean']])->validate();
        DB::transaction(function () use ($actor, $data) {
            $this->lock();
            $this->query->table('banjar_settings')->update($data);
            $this->audit->record($this->tenant->id(), $actor->id, 'krama.policy.updated', 'settings', $this->tenant->id(), [], $data);
        });
    }

    public static function masked(?string $encrypted): string
    {
        return $encrypted ? '•••• •••• •••• '.substr(Crypt::decryptString($encrypted), -4) : 'Belum diisi';
    }
}
