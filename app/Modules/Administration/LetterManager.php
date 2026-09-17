<?php

namespace App\Modules\Administration;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LetterManager
{
    public const STATUSES = ['SUBMITTED' => 'Menunggu Penyarikan', 'VERIFIED' => 'Menunggu Kelian', 'ISSUED' => 'Terbit', 'REJECTED' => 'Ditolak', 'CANCELLED' => 'Dibatalkan', 'CONFLICT' => 'Data sudah berubah'];

    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private LetterAccess $access, private Audit $audit) {}

    public function services(): Builder
    {
        return $this->query->table('service_definitions')->where('letters_enabled', true)->where('status', 'PUBLISHED')->where('published_at', '<=', now());
    }

    public function configure(User $actor, int $id, array $input): void
    {
        $this->access->authorize($actor, 'letter.configure');
        $data = Validator::make($input, [
            'letter_version' => ['required', 'integer'], 'letters_enabled' => ['required', 'boolean'],
            'letter_requires_approval' => ['required', 'boolean'], 'letter_statement' => ['nullable', 'required_if:letters_enabled,1', 'string', 'max:2000'],
        ])->validate();
        DB::transaction(function () use ($actor, $id, $data) {
            $this->lock();
            $this->access->authorize($actor, 'letter.configure');
            $row = $this->query->table('service_definitions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->letter_version !== (int) $data['letter_version']) {
                throw ValidationException::withMessages(['letter_version' => 'Pengaturan sudah berubah. Muat ulang halaman.']);
            }
            $data['letter_version']++;
            $this->query->table('service_definitions')->where('id', $id)->update($data + ['updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'letter.service_configured', 'service_definitions', $id,
                ['letters_enabled' => $row->letters_enabled, 'letter_requires_approval' => $row->letter_requires_approval], $data);
        });
    }

    public function identity(object $person, object $household): array
    {
        return ['name' => $person->name, 'nik' => $person->nik ? Crypt::decryptString($person->nik) : null,
            'birth_date' => $person->birth_date, 'address' => $household->address,
            'household_code' => $household->code, 'family_number' => $household->family_number ? Crypt::decryptString($household->family_number) : null];
    }

    public static function unpack(string $snapshot): array
    {
        return json_decode(Crypt::decryptString($snapshot), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function pack(array $snapshot): string
    {
        return Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public static function mask(?string $value): string
    {
        return $value ? '•••• •••• •••• '.substr($value, -4) : 'Belum tercatat';
    }

    public function submit(User $actor, array $input): int
    {
        $this->access->authorize($actor, 'letter.submit');
        $data = Validator::make($input, ['service_definition_id' => ['required', 'integer'], 'purpose' => ['required', 'string', 'max:1500'],
            'submission_key' => ['required', 'uuid'], 'person_version' => ['required', 'integer'], 'household_version' => ['required', 'integer'], 'service_version' => ['required', 'integer']])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $this->lock();
            $this->access->authorize($actor, 'letter.submit');
            $hash = hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), config('app.key'));
            $existing = $this->query->table('letter_requests')->where('requester_id', $actor->id)->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->submission_hash, $hash), 409, 'Form ini sudah dikirim dengan isian berbeda.');

                return $existing->id;
            }
            $person = $this->access->applicant($actor);
            if (! $person) {
                throw ValidationException::withMessages(['applicant' => 'Akun harus terhubung ke krama dan KK aktif. Hubungi pengurus.']);
            }
            $household = $this->query->table('households')->where('id', $person->household_id)->first();
            $service = $this->services()->where('id', $data['service_definition_id'])->lockForUpdate()->first();
            abort_unless($service, 404);
            if ($person->version !== (int) $data['person_version'] || $household->version !== (int) $data['household_version'] || $service->letter_version !== (int) $data['service_version']) {
                throw ValidationException::withMessages(['version' => 'Data atau aturan layanan sudah berubah. Muat ulang formulir.']);
            }
            $id = DB::table('letter_requests')->insertGetId([
                'banjar_id' => $this->tenant->id(), 'service_definition_id' => $service->id, 'krama_id' => $person->id, 'household_id' => $household->id,
                'person_version' => $person->version, 'household_version' => $household->version, 'requester_id' => $actor->id,
                'submission_key' => $data['submission_key'], 'submission_hash' => $hash,
                'identity_snapshot' => self::pack($this->identity($person, $household)),
                'service_snapshot' => self::pack(['title' => $service->title, 'statement' => $service->letter_statement]),
                'purpose' => Crypt::encryptString($data['purpose']), 'requires_approval' => $service->letter_requires_approval,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->step($id, $actor, 'SUBMITTED');

            return $id;
        });
    }

    public function review(User $actor, int $id, array $input): string
    {
        $data = Validator::make($input, ['action' => ['required', Rule::in(['verify', 'approve', 'reject'])], 'review_note' => ['nullable', 'required_if:action,reject', 'string', 'max:1500']])->validate();

        return DB::transaction(function () use ($actor, $id, $data) {
            $this->lock();
            $row = $this->query->table('letter_requests')->where('id', $id)->first();
            abort_unless($row, 404);
            $permission = $data['action'] === 'approve' || ($data['action'] === 'reject' && $row->status === 'VERIFIED') ? 'letter.approve' : 'letter.verify';
            $this->access->requireReview($actor, $row, $permission);
            $expected = $permission === 'letter.approve' ? 'VERIFIED' : 'SUBMITTED';
            if ($row->status !== $expected) {
                throw ValidationException::withMessages(['action' => 'Pengajuan sudah diproses atau belum memasuki tahap ini. Muat ulang halaman.']);
            }
            $person = $this->query->table('krama')->where('id', $row->krama_id)->first();
            $household = $this->query->table('households')->where('id', $row->household_id)->first();
            $stage = $permission === 'letter.approve' ? 'approver' : 'verifier';
            if ($data['action'] === 'reject') {
                $status = 'REJECTED';
            } elseif ($person->version !== $row->person_version || $household->version !== $row->household_version || $person->household_id !== $household->id || $person->status !== 'ACTIVE' || $household->status !== 'ACTIVE') {
                $status = 'CONFLICT';
            } elseif ($data['action'] === 'verify' && $row->requires_approval) {
                $status = 'VERIFIED';
            } else {
                $status = 'ISSUED';
            }
            $this->query->table('letter_requests')->where('id', $id)->update(['status' => $status, $stage.'_id' => $actor->id, $stage.'_at' => now(), 'updated_at' => now()]);
            $this->step($id, $actor, $status, $data['review_note'] ?? null);
            if ($status === 'ISSUED') {
                $this->issue($row, $actor, $person, $household);
            }

            return $status;
        });
    }

    private function issue(object $row, User $actor, object $person, object $household): void
    {
        $year = (int) now()->timezone('Asia/Makassar')->format('Y');
        DB::table('letter_sequences')->insertOrIgnore(['banjar_id' => $this->tenant->id(), 'year' => $year, 'last_number' => 0]);
        $sequence = $this->query->table('letter_sequences')->where('year', $year)->lockForUpdate()->first();
        $next = $sequence->last_number + 1;
        $this->query->table('letter_sequences')->where('year', $year)->update(['last_number' => $next]);
        $number = sprintf('%04d/ADM/%d', $next, $year);
        $profile = $this->query->table('website_profiles')->first();
        $document = DB::table('issued_documents')->insertGetId([
            'banjar_id' => $this->tenant->id(), 'request_id' => $row->id, 'number' => $number, 'issued_at' => now(),
            'snapshot' => self::pack(['identity' => $this->identity($person, $household), 'service' => self::unpack($row->service_snapshot),
                'purpose' => Crypt::decryptString($row->purpose), 'banjar_name' => DB::table('banjars')->where('id', $this->tenant->id())->value('name'),
                'banjar_address' => $profile?->address, 'issuer_name' => DB::table('users')->where('id', $actor->id)->value('name'),
                'issuer_title' => $row->requires_approval ? 'Kelian Adat' : 'Penyarikan', 'number' => $number,
                'date' => now()->timezone('Asia/Makassar')->translatedFormat('d F Y')]),
        ]);
        GenerateLetterPdf::dispatch($this->tenant->id(), $document)->onConnection('database')->afterCommit();
    }

    public function cancel(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $this->lock();
            $row = $this->access->visible($actor, true)->where('id', $id)->first();
            abort_unless($row, 404);
            if ($row->status !== 'SUBMITTED') {
                throw ValidationException::withMessages(['action' => 'Hanya pengajuan yang belum diverifikasi yang dapat dibatalkan.']);
            }
            $this->query->table('letter_requests')->where('id', $id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            $this->step($id, $actor, 'CANCELLED');
        });
    }

    public function retryPdf(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $this->lock();
            $row = $this->access->visible($actor)->where('id', $id)->first();
            abort_unless($row, 404);
            $doc = $this->query->table('issued_documents')->where('request_id', $id)->lockForUpdate()->first();
            abort_unless($doc, 404);
            if ($doc->pdf_status !== 'FAILED') {
                throw ValidationException::withMessages(['pdf' => 'PDF masih diproses atau sudah tersedia.']);
            }
            $this->query->table('issued_documents')->where('id', $doc->id)->update(['pdf_status' => 'PENDING']);
            GenerateLetterPdf::dispatch($this->tenant->id(), $doc->id)->onConnection('database')->afterCommit();
            $this->audit->record($this->tenant->id(), $actor->id, 'letter.pdf_retried', 'issued_documents', $doc->id);
        });
    }

    private function step(int $id, User $actor, string $status, ?string $note = null): void
    {
        DB::table('letter_request_steps')->insert(['banjar_id' => $this->tenant->id(), 'request_id' => $id, 'actor_id' => $actor->id,
            'status' => $status, 'note' => $note ? Crypt::encryptString($note) : null, 'created_at' => now()]);
        $this->audit->record($this->tenant->id(), $actor->id, 'letter.'.strtolower($status), 'letter_requests', $id, [], ['status' => $status]);
    }

    private function lock(): void
    {
        abort_unless(DB::table('banjars')->where('id', $this->tenant->id())->where('status', 'ACTIVE')->lockForUpdate()->first(), 404);
    }
}
