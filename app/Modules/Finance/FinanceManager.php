<?php

namespace App\Modules\Finance;

use App\Models\User;
use App\Modules\Notification\NotificationManager;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FinanceManager
{
    // Tiga hal berbeda: iuran (rutin/opsional sesuai kebijakan Banjar), patus (santunan yang dikenakan
    // ke semua KK setiap ada kematian warga), dan dedosan (denda ketidakhadiran kegiatan Banjar).
    public const KINDS = ['IURAN' => 'Iuran', 'PATUS' => 'Patus', 'DEDOSAN' => 'Dedosan', 'OTHER' => 'Lainnya'];

    public const STATUSES = ['DRAFT' => 'Draf', 'APPROVED' => 'Disetujui', 'REJECTED' => 'Ditolak', 'ISSUED' => 'Terbit', 'VOID' => 'Dibatalkan', 'UNPAID' => 'Belum lunas', 'PAID' => 'Lunas'];

    // Templat kategori Banjar Biaung (Master Prompt §26.3). Hanya dimuat lewat seedCategoryTemplate()
    // atas permintaan eksplisit pengurus berizin, tidak pernah otomatis pada tenant baru.
    public const BIAUNG_STARTER_CATEGORIES = [
        ['name' => 'Pendapatan Rutin', 'kind' => 'IN'],
        ['name' => 'Pendapatan Sewa/Kontrak', 'kind' => 'IN'],
        ['name' => 'Pendapatan Lain-lain', 'kind' => 'IN'],
        ['name' => 'Pengeluaran Rutin Banjar', 'kind' => 'OUT'],
        ['name' => 'Pengeluaran Lain-lain Banjar', 'kind' => 'OUT'],
        ['name' => 'Biaya Melasti', 'kind' => 'OUT'],
        ['name' => 'Biaya Odalan Tumpek Wariga', 'kind' => 'OUT'],
        ['name' => 'Biaya Pangrupukan & Atensi Nyepi', 'kind' => 'OUT'],
        ['name' => 'Pura Dalem Wirasana', 'kind' => 'OUT'],
        ['name' => 'Pura Bangun Sakti', 'kind' => 'OUT'],
        ['name' => 'Pura Pengiangan', 'kind' => 'OUT'],
        ['name' => 'Pura Ratu Gede Mas Mecaling', 'kind' => 'OUT'],
        ['name' => 'Pura Khayangan', 'kind' => 'OUT'],
    ];

    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private FinanceAccess $access, private Audit $audit, private NotificationManager $notifications) {}

    public static function money(int|string $amount): string
    {
        return 'Rp '.preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', (string) $amount);
    }

    public static function amountRules(): array
    {
        return ['required', 'regex:/^[1-9][0-9]{0,11}$/'];
    }

    private function fail(string $message, string $field = 'finance'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function write(User $actor, string $permission, callable $work): mixed
    {
        $this->access->authorize($actor, $permission);

        return DB::transaction(function () use ($actor, $permission, $work) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->first();
            $this->access->authorize($actor, $permission);

            return $work();
        }, 3);
    }

    private function insert(string $table, array $data): int
    {
        return DB::table($table)->insertGetId($data + ['banjar_id' => $this->tenant->id(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function row(string $table, int $id): object
    {
        $row = $this->query->table($table)->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function audit(User $actor, string $action, string $table, int $id, array $data = []): void
    {
        $this->audit->record($this->tenant->id(), $actor->id, $action, $table, $id, [], $data);
    }

    public function account(User $actor, array $input): int
    {
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:100'], 'type' => ['nullable', Rule::in(['CASH', 'BANK'])]])->validate();
        $data['type'] = $data['type'] ?? 'CASH';

        return $this->write($actor, 'cash.manage', function () use ($actor, $data) {
            if ($this->query->table('cash_accounts')->where('name', $data['name'])->exists()) {
                $this->fail('Nama kas sudah digunakan.', 'name');
            }
            $id = $this->insert('cash_accounts', $data);
            $this->audit($actor, 'cash.account_created', 'cash_accounts', $id);

            return $id;
        });
    }

    public function billingType(User $actor, array $input): int
    {
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:100'], 'kind' => ['required', Rule::in(array_keys(self::KINDS))], 'default_amount' => self::amountRules()])->validate();

        return $this->write($actor, 'billing.issue', function () use ($actor, $data) {
            if ($this->query->table('billing_types')->where('name', $data['name'])->exists()) {
                $this->fail('Nama jenis tagihan sudah digunakan.', 'name');
            }
            $id = $this->insert('billing_types', $data);
            $this->audit($actor, 'billing.type_created', 'billing_types', $id);

            return $id;
        });
    }

    public function toggleType(User $actor, int $id): void
    {
        $this->write($actor, 'billing.issue', function () use ($actor, $id) {
            $row = $this->row('billing_types', $id);
            $this->query->table('billing_types')->where('id', $id)->update(['is_active' => ! $row->is_active, 'updated_at' => now()]);
            $this->audit($actor, 'billing.type_toggled', 'billing_types', $id, ['status' => $row->is_active ? 'INACTIVE' : 'ACTIVE']);
        });
    }

    public function category(User $actor, array $input): int
    {
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:100'], 'kind' => ['required', Rule::in(['IN', 'OUT'])], 'always_show' => ['nullable', 'boolean']])->validate();

        return $this->write($actor, 'cash.manage', function () use ($actor, $data) {
            if ($this->query->table('cash_transaction_categories')->where('name', $data['name'])->exists()) {
                $this->fail('Nama kategori sudah digunakan.', 'name');
            }
            $id = $this->insert('cash_transaction_categories', ['name' => $data['name'], 'kind' => $data['kind'], 'always_show' => (bool) ($data['always_show'] ?? false), 'sort_order' => 0]);
            $this->audit($actor, 'finance.category_created', 'cash_transaction_categories', $id, ['name' => $data['name'], 'kind' => $data['kind']]);

            return $id;
        });
    }

    public function toggleCategory(User $actor, int $id): void
    {
        $this->write($actor, 'cash.manage', function () use ($actor, $id) {
            $row = $this->row('cash_transaction_categories', $id);
            $this->query->table('cash_transaction_categories')->where('id', $id)->update(['is_active' => ! $row->is_active, 'updated_at' => now()]);
            $this->audit($actor, 'finance.category_toggled', 'cash_transaction_categories', $id, ['status' => $row->is_active ? 'INACTIVE' : 'ACTIVE']);
        });
    }

    public function seedCategoryTemplate(User $actor): int
    {
        return $this->write($actor, 'cash.manage', function () use ($actor) {
            $count = 0;
            foreach (self::BIAUNG_STARTER_CATEGORIES as $index => $category) {
                if ($this->query->table('cash_transaction_categories')->where('name', $category['name'])->exists()) {
                    continue;
                }
                $this->insert('cash_transaction_categories', ['name' => $category['name'], 'kind' => $category['kind'], 'sort_order' => $index, 'always_show' => true]);
                $count++;
            }
            $this->audit($actor, 'finance.categories_seeded', 'banjars', $this->tenant->id(), ['count' => $count]);

            return $count;
        });
    }

    private function resolveCategory(?int $categoryId, string $direction): ?int
    {
        if ($categoryId === null) {
            return null;
        }
        $category = $this->query->table('cash_transaction_categories')->where('id', $categoryId)->first();
        if (! $category || ! $category->is_active) {
            $this->fail('Pilih kategori yang aktif.', 'category_id');
        }
        if ($category->kind !== $direction) {
            $this->fail('Kategori tidak sesuai dengan jenis transaksi.', 'category_id');
        }

        return $category->id;
    }

    public function policy(User $actor, array $input): void
    {
        $data = Validator::make($input, ['dedosan_requires_approval' => ['required', 'boolean']])->validate();
        $this->write($actor, 'finance.configure', function () use ($actor, $data) {
            $this->query->table('banjar_settings')->update($data + ['updated_at' => now()]);
            $this->audit($actor, 'finance.policy_updated', 'banjars', $this->tenant->id(), $data);
        });
    }

    public function event(User $actor, array $input): int
    {
        $data = Validator::make($input, ['billing_type_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:150'],
            'reference' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,79}$/'],
            // Tanggal penetapan = tanggal kejadian sebenarnya (tanggal kematian/kegiatan/periode iuran),
            // ini yang dipakai Banjar sehari-hari — bukan tanggal jatuh tempo yang tidak dikenal di sini.
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'amount' => ['nullable', 'regex:/^[1-9][0-9]{0,11}$/'], 'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'target' => ['nullable', Rule::in(['ALL_ACTIVE', 'UNIT'])], 'unit_id' => ['nullable', 'integer']])->validate();

        return $this->write($actor, 'billing.issue', function () use ($actor, $data) {
            $type = $this->row('billing_types', (int) $data['billing_type_id']);
            if (! $type->is_active) {
                $this->fail('Jenis tagihan sudah nonaktif.');
            }
            if ($type->kind === 'DEDOSAN') {
                $this->access->authorize($actor, 'dedosan.issue');
            }
            if ($this->query->table('billing_events')->where('reference', $data['reference'])->exists()) {
                $this->fail('Referensi event sudah digunakan. Buka event yang sudah ada.', 'reference');
            }
            $target = $type->kind === 'DEDOSAN' ? 'INDIVIDUAL' : ($data['target'] ?? 'ALL_ACTIVE');
            $unitId = $target === 'UNIT' ? (int) ($data['unit_id'] ?? 0) : null;
            if ($unitId !== null && ! $this->query->table('organizational_units')->where('id', $unitId)->where('is_active', true)->exists()) {
                $this->fail('Pilih unit aktif milik Banjar ini.', 'unit_id');
            }
            // Kesinoman ber-scope unit hanya boleh menargetkan wilayah aksesnya; "Semua KK Aktif" untuknya
            // berarti aktif dalam scope, bukan seluruh Banjar. Dedosan tidak pakai target massal di sini
            // (subjeknya dipilih individu per individu lewat subject()/bulkSubjects()).
            $scope = $type->kind === 'DEDOSAN' ? null : $this->access->unitIds($actor, 'billing.issue');
            if ($scope !== null && $target === 'UNIT' && ! in_array($unitId, $scope, true)) {
                $this->fail('Unit di luar wilayah akses Anda.', 'unit_id');
            }
            $requiresApproval = $type->kind === 'DEDOSAN' && $this->query->table('banjar_settings')->value('dedosan_requires_approval');
            $id = $this->insert('billing_events', ['billing_type_id' => $type->id, 'kind' => $type->kind,
                'amount' => $data['amount'] ?? $type->default_amount, 'title' => $data['title'], 'reference' => $data['reference'],
                'event_date' => $data['event_date'], 'due_date' => $data['due_date'] ?? null, 'target' => $target, 'unit_id' => $unitId, 'created_by' => $actor->id,
                'requires_approval' => $requiresApproval, 'scope_unit_ids' => $scope === null ? null : json_encode($scope)]);
            $this->audit($actor, 'billing.event_created', 'billing_events', $id);
            if ($type->kind !== 'DEDOSAN') {
                $this->snapshotTargets($this->row('billing_events', $id));
            }
            if ($requiresApproval) {
                $this->notifications->notifyPermission($this->tenant->id(), 'dedosan.approve', 'dedosan.approval_pending', 'Dedosan menunggu persetujuan', 'Ada event dedosan menunggu persetujuan Kelian.', '/admin/finance/events/'.$id);
            }

            return $id;
        });
    }

    private function targetHouseholds(object $event): Builder
    {
        // Setiap tagihan KK ditujukan ke kepala keluarga; KK tanpa kepala keluarga dikecualikan
        // sampai kepala keluarganya ditetapkan (lihat juga subject()/bulkSubjects() untuk dedosan).
        $households = $this->query->table('households')->where('status', 'ACTIVE')->whereNotNull('head_krama_id');
        if ($event->target === 'UNIT') {
            $households->whereIn('id', $this->query->table('household_unit_memberships')->where('unit_id', $event->unit_id)->select('household_id'));
        }
        if ($event->scope_unit_ids !== null) {
            $scope = json_decode($event->scope_unit_ids, true);
            $households->whereIn('id', $this->query->table('household_unit_memberships')->whereIn('unit_id', $scope)->select('household_id'));
        }

        return $households;
    }

    // Menulis ulang snapshot KK sasaran. Dipanggil saat event dibuat dan lewat refreshTarget();
    // issue() membaca dari tabel ini, bukan query live, agar penerbitan stabil terhadap perubahan keanggotaan.
    private function snapshotTargets(object $event): void
    {
        $ids = $this->targetHouseholds($event)->pluck('id');
        $this->query->table('billing_event_targets')->where('billing_event_id', $event->id)->delete();
        foreach ($ids->chunk(500) as $chunk) {
            DB::table('billing_event_targets')->insert($chunk->map(fn ($householdId) => [
                'banjar_id' => $this->tenant->id(), 'billing_event_id' => $event->id, 'household_id' => $householdId,
                'created_at' => now(), 'updated_at' => now(),
            ])->all());
        }
        $this->query->table('billing_events')->where('id', $event->id)->update([
            'target_kk_count' => $ids->count(), 'target_total_amount' => $ids->count() * $event->amount,
            'target_snapshot_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Hitungan KK & total nominal saat ini (tanpa menulis snapshot) — untuk banner "data berubah sejak pratinjau".
    public function liveTargetCount(object $event): array
    {
        $count = $event->kind === 'DEDOSAN'
            ? $this->query->table('billing_event_subjects')->where('billing_event_id', $event->id)->count()
            : (clone $this->targetHouseholds($event))->count();

        return ['count' => $count, 'total' => $count * $event->amount];
    }

    public function refreshTarget(User $actor, int $eventId): array
    {
        return $this->write($actor, 'billing.issue', function () use ($actor, $eventId) {
            $event = $this->row('billing_events', $eventId);
            abort_unless($event->kind !== 'DEDOSAN', 409);
            abort_unless(in_array($event->status, ['DRAFT', 'APPROVED']), 409);
            $this->snapshotTargets($event);
            $refreshed = $this->row('billing_events', $eventId);
            if ($refreshed->target_kk_count === 0) {
                $this->fail('Tidak ada KK aktif untuk target ini.');
            }
            $this->audit($actor, 'billing.target_snapshotted', 'billing_events', $eventId, ['target_kk_count' => $refreshed->target_kk_count, 'target_total_amount' => $refreshed->target_total_amount]);

            return ['target_kk_count' => $refreshed->target_kk_count, 'target_total_amount' => $refreshed->target_total_amount];
        });
    }

    public function subject(User $actor, int $eventId, array $input): void
    {
        $data = Validator::make($input, ['subject_krama_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:5', 'max:1000']])->validate();
        $this->write($actor, 'dedosan.issue', function () use ($actor, $eventId, $data) {
            $event = $this->row('billing_events', $eventId);
            abort_unless($event->kind === 'DEDOSAN' && $event->status === 'DRAFT', 409);
            $person = $this->row('krama', (int) $data['subject_krama_id']);
            $household = $this->row('households', $person->household_id);
            if ($person->status !== 'ACTIVE' || $household->status !== 'ACTIVE') {
                $this->fail('Individu dan KK harus aktif.');
            }
            // Dedosan hanya dikenakan ke kepala keluarga (satu penanggung jawab per KK).
            if ((int) $household->head_krama_id !== $person->id) {
                $this->fail('Dedosan hanya dapat dikenakan kepada kepala keluarga.');
            }
            $scope = $this->access->unitIds($actor, 'dedosan.issue');
            if ($scope !== null && ! $this->query->table('household_unit_memberships')->where('household_id', $household->id)->whereIn('unit_id', $scope)->exists()) {
                $this->fail('Individu di luar wilayah akses Anda.');
            }
            if ($this->query->table('billing_event_subjects')->where('billing_event_id', $eventId)->where('kk_id', $household->id)->exists()) {
                $this->fail('KK ini sudah memiliki satu individu pada event dedosan ini.');
            }
            $id = $this->insert('billing_event_subjects', ['billing_event_id' => $eventId, 'kk_id' => $household->id, 'subject_krama_id' => $person->id, 'reason' => Crypt::encryptString($data['reason'])]);
            $this->audit($actor, 'dedosan.subject_added', 'billing_event_subjects', $id, ['billing_event_id' => $eventId]);
        });
    }

    public function removeSubject(User $actor, int $eventId, int $id): void
    {
        $this->write($actor, 'dedosan.issue', function () use ($actor, $eventId, $id) {
            abort_unless($this->row('billing_events', $eventId)->status === 'DRAFT', 409);
            $subject = $this->row('billing_event_subjects', $id);
            abort_unless($subject->billing_event_id === $eventId, 404);
            $this->query->table('billing_event_subjects')->where('id', $id)->delete();
            $this->audit($actor, 'dedosan.draft_subject_removed', 'billing_event_subjects', $id, ['billing_event_id' => $eventId]);
        });
    }

    // Penetapan dedosan massal (checkbox lintas halaman/filter, Master Prompt §29.1). Satu transaksi,
    // seluruh sasaran divalidasi ulang di backend; jika ada masalah apa pun, batch DITOLAK SELURUHNYA
    // (tidak ada baris yang ditulis) sehingga tidak pernah terbit sebagian tanpa diketahui pengguna.
    public function bulkSubjects(User $actor, int $eventId, array $input): void
    {
        $data = Validator::make($input, [
            'krama_ids' => ['required', 'array', 'min:1', 'max:500'],
            'krama_ids.*' => ['integer', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ])->validate();

        $this->write($actor, 'dedosan.issue', function () use ($actor, $eventId, $data) {
            $event = $this->row('billing_events', $eventId);
            abort_unless($event->kind === 'DEDOSAN' && $event->status === 'DRAFT', 409);
            $scope = $this->access->unitIds($actor, 'dedosan.issue');
            $existingKk = $this->query->table('billing_event_subjects')->where('billing_event_id', $eventId)->pluck('kk_id');
            $errors = [];
            $byHousehold = [];
            foreach ($data['krama_ids'] as $kramaId) {
                $person = $this->query->table('krama')->where('id', $kramaId)->first();
                if (! $person || $person->status !== 'ACTIVE') {
                    $errors[] = "Individu #{$kramaId} tidak aktif atau tidak ditemukan.";

                    continue;
                }
                $household = $this->query->table('households')->where('id', $person->household_id)->first();
                if (! $household || $household->status !== 'ACTIVE') {
                    $errors[] = "KK dari individu {$person->name} tidak aktif.";

                    continue;
                }
                if ((int) $household->head_krama_id !== (int) $kramaId) {
                    $errors[] = "{$person->name} bukan kepala keluarga KK {$household->code}. Dedosan hanya untuk kepala keluarga.";

                    continue;
                }
                if ($scope !== null) {
                    $inScope = $this->query->table('household_unit_memberships')->where('household_id', $household->id)->whereIn('unit_id', $scope)->exists();
                    if (! $inScope) {
                        $errors[] = "Individu {$person->name} di luar wilayah akses Anda.";

                        continue;
                    }
                }
                if ($existingKk->contains($household->id)) {
                    $errors[] = "KK {$household->code} sudah memiliki subjek pada event ini.";

                    continue;
                }
                $byHousehold[$household->id][] = ['krama_id' => (int) $kramaId, 'name' => $person->name, 'household_code' => $household->code];
            }
            foreach ($byHousehold as $entries) {
                if (count($entries) > 1) {
                    $errors[] = 'KK '.$entries[0]['household_code'].' memiliki lebih dari satu individu terpilih ('.implode(', ', array_column($entries, 'name')).'). Pilih tepat satu penanggung jawab.';
                }
            }
            if ($errors) {
                throw ValidationException::withMessages(['selection' => $errors]);
            }
            $rows = [];
            foreach ($byHousehold as $kkId => $entries) {
                $rows[] = ['banjar_id' => $this->tenant->id(), 'billing_event_id' => $eventId, 'kk_id' => $kkId, 'subject_krama_id' => $entries[0]['krama_id'],
                    'reason' => Crypt::encryptString($data['reason']), 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('billing_event_subjects')->insert($rows);
            $this->audit($actor, 'dedosan.subjects_bulk_added', 'billing_events', $eventId, ['count' => count($rows)]);
        });
    }

    private function subjects(object $event): Collection
    {
        $subjects = $this->query->table('billing_event_subjects')->where('billing_event_id', $event->id)->get();
        if ($subjects->isEmpty()) {
            $this->fail('Tambahkan minimal satu individu dan alasan dedosan.');
        }
        $people = $this->query->table('krama')->whereIn('id', $subjects->pluck('subject_krama_id'))->get()->keyBy('id');
        $households = $this->query->table('households')->whereIn('id', $subjects->pluck('kk_id'))->get()->keyBy('id');
        foreach ($subjects as $subject) {
            $person = $people->get($subject->subject_krama_id);
            $household = $households->get($subject->kk_id);
            abort_unless($person && $household, 404);
            if ($person->status !== 'ACTIVE' || $person->household_id !== $subject->kk_id || $household->status !== 'ACTIVE') {
                $this->fail('Data individu atau KK telah berubah. Batalkan event dan tinjau ulang.');
            }
        }

        return $subjects;
    }

    public function review(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, ['action' => ['required', Rule::in(['approve', 'reject'])], 'review_note' => ['required', 'string', 'min:5', 'max:1000']])->validate();
        $this->write($actor, 'dedosan.approve', function () use ($actor, $id, $data) {
            $event = $this->row('billing_events', $id);
            abort_unless($event->status === 'DRAFT' && $event->kind === 'DEDOSAN' && $event->requires_approval, 409);
            abort_if($event->created_by === $actor->id, 403, 'Pengaju tidak dapat menyetujui pengajuannya sendiri.');
            $this->subjects($event);
            $status = $data['action'] === 'approve' ? 'APPROVED' : 'REJECTED';
            $this->query->table('billing_events')->where('id', $id)->update(['status' => $status, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => Crypt::encryptString($data['review_note']), 'updated_at' => now()]);
            $this->audit($actor, 'dedosan.reviewed', 'billing_events', $id, ['status' => $status]);
            // Pembuat (biasanya Kesinoman) perlu tahu kapan harus kembali menerbitkan atau memperbaiki.
            $this->notifications->notify($this->tenant->id(), $event->created_by, 'dedosan.reviewed',
                $status === 'APPROVED' ? 'Dedosan disetujui' : 'Dedosan ditolak',
                $status === 'APPROVED' ? '"'.$event->title.'" disetujui Kelian dan siap diterbitkan.' : '"'.$event->title.'" ditolak Kelian. Lihat catatan pemeriksaan.',
                '/admin/finance/events/'.$id);
        });
    }

    public function issue(User $actor, int $id): void
    {
        $this->write($actor, 'billing.issue', function () use ($actor, $id) {
            $event = $this->row('billing_events', $id);
            if ($event->kind === 'DEDOSAN') {
                $this->access->authorize($actor, 'dedosan.issue');
            }
            if ($event->status === 'ISSUED') {
                return;
            }
            abort_unless(in_array($event->status, ['DRAFT', 'APPROVED']), 409);
            if ($event->requires_approval && $event->status !== 'APPROVED') {
                $this->fail('Dedosan harus disetujui Kelian sebelum diterbitkan.');
            }
            $subjects = $event->kind === 'DEDOSAN' ? $this->subjects($event)->keyBy('kk_id') : collect();
            $households = $this->query->table('households')->where('status', 'ACTIVE');
            if ($event->kind === 'DEDOSAN') {
                $households->whereIn('id', $subjects->keys());
            } else {
                // Sumber kebenaran adalah snapshot yang dibekukan saat event dibuat/di-refresh, bukan
                // query live: perubahan unit/keanggotaan setelah pratinjau tidak diam-diam mengubah tagihan.
                if (! $event->target_snapshot_at) {
                    $this->snapshotTargets($event);
                    $event = $this->row('billing_events', $id);
                }
                $targetIds = $this->query->table('billing_event_targets')->where('billing_event_id', $event->id)->pluck('household_id');
                if ($targetIds->isEmpty()) {
                    $this->fail('Tidak ada KK aktif untuk target ini.');
                }
                $households->whereIn('id', $targetIds);
            }
            if (! (clone $households)->exists()) {
                $this->fail('Tidak ada KK aktif untuk target ini.');
            }
            // One transaction holds the tenant guard through every batch and the final state.
            $households->orderBy('id')->chunkById(250, function ($rows) use ($event, $subjects) {
                $inserts = [];
                foreach ($rows as $household) {
                    $subject = $subjects->get($household->id);
                    $inserts[] = ['banjar_id' => $this->tenant->id(), 'billing_event_id' => $event->id, 'kk_id' => $household->id,
                        'subject_krama_id' => $subject?->subject_krama_id, 'household_code' => $household->code,
                        'subject_name' => $subject ? Crypt::encryptString($this->row('krama', $subject->subject_krama_id)->name) : null,
                        'kind' => $event->kind, 'gross_amount' => $event->amount, 'status' => 'UNPAID', 'created_at' => now(), 'updated_at' => now()];
                }
                DB::table('bills')->insert($inserts);
            });
            $this->query->table('billing_events')->where('id', $id)->update(['status' => 'ISSUED', 'issued_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'billing.issued', 'billing_events', $id, ['status' => 'ISSUED', 'amount' => $event->amount]);
        });
    }

    public function void(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, ['reason' => ['required', 'string', 'min:5', 'max:1000']])->validate();
        $this->write($actor, 'billing.issue', function () use ($actor, $id, $data) {
            $event = $this->row('billing_events', $id);
            if ($event->kind === 'DEDOSAN') {
                $this->access->authorize($actor, 'dedosan.issue');
            }
            if ($event->status === 'VOID') {
                return;
            }
            if ($this->query->table('bills')->where('billing_event_id', $id)->where('status', 'PAID')->exists()) {
                $this->fail('Event memiliki tagihan lunas. Balikkan pelunasan terlebih dahulu.');
            }
            $this->query->table('bills')->where('billing_event_id', $id)->update(['status' => 'VOID', 'updated_at' => now()]);
            $this->query->table('billing_events')->where('id', $id)->update(['status' => 'VOID', 'void_reason' => Crypt::encryptString($data['reason']), 'voided_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'billing.voided', 'billing_events', $id, ['status' => 'VOID']);
        });
    }

    public function balance(int $accountId, ?string $asOf = null): int
    {
        $q = $this->query->table('cash_transactions')->where('cash_account_id', $accountId);
        if ($asOf !== null) {
            $q->where('transaction_date', '<=', $asOf);
        }

        return (int) $q->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN CAST(amount AS SIGNED) ELSE -CAST(amount AS SIGNED) END), 0) AS balance")->value('balance');
    }

    private function cashInput(array $input, bool $manual): array
    {
        return Validator::make($input, ['cash_account_id' => ['required', 'integer'], 'submission_key' => ['required', 'uuid'],
            'transaction_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now('Asia/Makassar')->toDateString()],
            'reference' => ['required', 'string', 'max:100'], 'category_id' => ['nullable', 'integer'],
            ...($manual ? ['direction' => ['required', Rule::in(['IN', 'OUT'])], 'amount' => self::amountRules(), 'reason' => ['required', 'string', 'min:5', 'max:1000']] : [])])->validate();
    }

    private function hash(array $data): string
    {
        // Browser form strings and programmatic integer IDs are the same logical input.
        ksort($data);

        return hash_hmac('sha256', json_encode(array_map(fn ($v) => (string) $v, $data), JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function existingCash(array $data): ?int
    {
        $row = $this->query->table('cash_transactions')->where('submission_key', $data['submission_key'])->first();
        if ($row) {
            abort_unless(hash_equals($row->submission_hash, $this->hash($data)), 409, 'Form sudah dikirim dengan isian berbeda.');
        }

        return $row?->id;
    }

    private function appendCash(User $actor, array $data, array $hashData): int
    {
        $this->row('cash_accounts', (int) $data['cash_account_id']);
        $balance = $this->balance((int) $data['cash_account_id']);
        if ($data['direction'] === 'OUT' && $balance < (int) $data['amount']) {
            $this->fail('Saldo kas tidak mencukupi untuk pengeluaran atau pembalikan ini.');
        }
        if ($data['direction'] === 'IN' && $balance > 9000000000000000 - (int) $data['amount']) {
            $this->fail('Batas saldo kas terlampaui.');
        }
        $id = $this->insert('cash_transactions', $data + ['created_by' => $actor->id, 'submission_hash' => $this->hash($hashData)]);
        $this->audit($actor, 'cash.posted', 'cash_transactions', $id, $data);

        return $id;
    }

    public function cash(User $actor, array $input): int
    {
        $data = $this->cashInput($input, true) + ['source' => 'MANUAL', 'actor' => $actor->id];

        return $this->write($actor, 'cash.manage', function () use ($actor, $data) {
            if ($existing = $this->existingCash($data)) {
                return $existing;
            }
            $insert = $data;
            unset($insert['actor']);
            $insert['category_id'] = $this->resolveCategory($insert['category_id'] ?? null, $data['direction']);
            $insert['reason'] = Crypt::encryptString($data['reason']);

            return $this->appendCash($actor, $insert, $data);
        });
    }

    public function settle(User $actor, int $billId, array $input): int
    {
        $data = $this->cashInput($input, false) + ['source' => 'BILL', 'bill_id' => $billId, 'actor' => $actor->id];

        return $this->write($actor, 'cash.manage', function () use ($actor, $billId, $data) {
            if ($existing = $this->existingCash($data)) {
                return $existing;
            }
            $bill = $this->row('bills', $billId);
            abort_unless($bill->status === 'UNPAID', 409, 'Tagihan tidak dapat dilunasi lagi.');
            // Tunai boleh "menang" atas QRIS yang masih pending/belum selesai (mis. warga membayar tunai setelah
            // menampilkan QR tapi belum membayarnya). Bill dikunci begitu lunas (status UNPAID check di atas +
            // idempotency submission_key), dan bila dana QRIS tetap masuk belakangan (race/notifikasi terlambat),
            // PaymentManager::sync() sudah merutekannya ke bucket EXCEPTION sebagai kelebihan pembayaran yang
            // direkonsiliasi manual — bukan dihilangkan, bukan melunasi dua kali, bukan pendapatan kedua.
            // Peringatan "QRIS masih diproses" ditampilkan di halaman tagihan (lihat FinanceController::bill()),
            // bukan sebagai blokir backend, agar tidak mengunci Petengen saat warga memang membayar tunai.
            $insert = $data;
            unset($insert['actor']);
            $insert['direction'] = 'IN';
            $insert['amount'] = $bill->gross_amount;
            $insert['category_id'] = $this->resolveCategory($insert['category_id'] ?? null, 'IN');
            $insert['reason'] = Crypt::encryptString('Pelunasan tagihan #'.$billId);
            $cashId = $this->appendCash($actor, $insert, $data);
            $this->insert('bill_settlements', ['bill_id' => $billId, 'method' => 'MANUAL', 'amount' => $bill->gross_amount, 'cash_transaction_id' => $cashId, 'created_by' => $actor->id]);
            $this->query->table('bills')->where('id', $billId)->update(['status' => 'PAID', 'updated_at' => now()]);
            $this->audit($actor, 'billing.settled', 'bills', $billId, ['status' => 'PAID', 'gross_amount' => $bill->gross_amount]);

            return $cashId;
        });
    }

    public function reverse(User $actor, int $id, array $input): int
    {
        $data = Validator::make($input, ['reason' => ['required', 'string', 'min:5', 'max:1000']])->validate();

        return $this->write($actor, 'cash.manage', function () use ($actor, $id, $data) {
            $original = $this->row('cash_transactions', $id);
            abort_if($original->source === 'REVERSAL', 409, 'Transaksi pembalik tidak dapat dibalik lagi.');
            $existing = $this->query->table('cash_transactions')->where('reversal_of_id', $id)->value('id');
            if ($existing) {
                return $existing;
            }
            $insert = ['cash_account_id' => $original->cash_account_id, 'direction' => $original->direction === 'IN' ? 'OUT' : 'IN',
                'amount' => $original->amount, 'category_id' => $original->category_id, 'transaction_date' => now('Asia/Makassar')->toDateString(), 'reason' => Crypt::encryptString($data['reason']),
                'reference' => 'REV-'.$id, 'source' => 'REVERSAL', 'bill_id' => $original->bill_id, 'reversal_of_id' => $id, 'submission_key' => (string) Str::uuid()];
            $reverseId = $this->appendCash($actor, $insert, $insert);
            if ($original->source === 'BILL') {
                $settlement = $this->query->table('bill_settlements')->where('cash_transaction_id', $id)->whereNull('reversed_at')->first();
                abort_unless($settlement, 409);
                $this->query->table('bill_settlements')->where('id', $settlement->id)->update(['reversed_at' => now(), 'updated_at' => now()]);
                $this->query->table('bills')->where('id', $original->bill_id)->update(['status' => 'UNPAID', 'updated_at' => now()]);
                $this->audit($actor, 'billing.settlement_reversed', 'bills', $original->bill_id, ['status' => 'UNPAID']);
            }
            $this->audit($actor, 'cash.reversed', 'cash_transactions', $reverseId, ['reversal_of_id' => $id]);

            return $reverseId;
        });
    }
}
