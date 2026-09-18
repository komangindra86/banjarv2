<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Administration\GenerateLetterPdf;
use App\Modules\Administration\LetterManager;
use App\Modules\Administration\LetterPdf;
use App\Modules\Krama\KramaManager;
use App\Modules\Website\ContentManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    private User $self;

    private User $secretary;

    private User $kelian;

    private int $a;

    private int $b;

    private int $person;

    private int $household;

    private int $service;

    private string $host = 'http://alpha.localhost';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Refusing to refresh anything except banjarv2_testing on MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        config(['platform.host' => 'admin.banjar.localhost', 'platform.tenant_base_domain' => 'localhost']);
        $this->seed(RoleSeeder::class);
        $this->super = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $this->super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        foreach (['a' => 'alpha', 'b' => 'beta'] as $key => $slug) {
            $this->$key = app(TenantManager::class)->create($this->super, ['name' => 'Banjar '.$slug, 'slug' => $slug, 'admin_name' => 'Admin '.$slug, 'admin_email' => $slug.'@example.test', 'admin_password' => 'Test-Password-12345', 'admin_password_confirmation' => 'Test-Password-12345']);
            DB::table('banjar_modules')->where('banjar_id', $this->$key)->whereIn('code', ['KRAMA', 'ADMINISTRATION'])->update(['status' => 'ACTIVE']);
        }
        $this->admin = User::where('email', 'alpha@example.test')->firstOrFail();
        $this->context();
        $this->self = $this->role('KRAMA');
        $this->secretary = $this->role('PENYARIKAN');
        $this->kelian = $this->role('KELIAN');
        $this->household = app(KramaManager::class)->household($this->admin, null, ['code' => 'KK-SURAT', 'status' => 'ACTIVE', 'address' => 'Jalan Banjar 12', 'family_number' => '9876543210987654']);
        $this->person = app(KramaManager::class)->person($this->admin, null, ['household_id' => $this->household, 'name' => 'Made Pemohon', 'nik' => '1234567890123456', 'birth_date' => '1990-01-15', 'user_id' => $this->self->id, 'status' => 'ACTIVE']);
        $this->service = app(ContentManager::class)->save($this->admin, 'layanan', null, ['title' => 'Surat Keterangan Krama', 'status' => 'PUBLISHED']);
        $this->policy(true);
    }

    private function context(): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->a));
    }

    private function role(string $code, ?int $unit = null): User
    {
        $user = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->a, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->a, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => $unit ? 'ORGANIZATIONAL_UNIT' : 'BANJAR', 'unit_id' => $unit]);

        return $user;
    }

    private function login(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_host' => 'alpha.localhost', 'auth_context' => (string) $this->a]);
    }

    private function policy(bool $approval): void
    {
        $this->context();
        app(LetterManager::class)->configure($this->admin, $this->service, ['letters_enabled' => 1, 'letter_requires_approval' => $approval, 'letter_statement' => 'Yang bersangkutan tercatat sebagai krama Banjar.', 'letter_version' => DB::table('service_definitions')->find($this->service)->letter_version]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['service_definition_id' => $this->service, 'purpose' => 'Keperluan administrasi pendidikan', 'person_version' => DB::table('krama')->find($this->person)->version,
            'household_version' => DB::table('households')->find($this->household)->version, 'service_version' => DB::table('service_definitions')->find($this->service)->letter_version, 'submission_key' => (string) Str::uuid()];
    }

    private function submit(): int
    {
        $this->context();

        return app(LetterManager::class)->submit($this->self, $this->payload());
    }

    private function issue(): int
    {
        $id = $this->submit();
        $manager = app(LetterManager::class);
        $manager->review($this->secretary, $id, ['action' => 'verify']);
        $manager->review($this->kelian, $id, ['action' => 'approve']);

        return $id;
    }

    private function generate(int $id): void
    {
        $document = DB::table('issued_documents')->where('request_id', $id)->value('id');
        app()->call([new GenerateLetterPdf($this->a, $document), 'handle']);
        $this->context();
    }

    public function test_portal_prefills_identity_and_never_accepts_another_subject(): void
    {
        $this->login($this->self);
        foreach (['/portal', '/portal/layanan', '/portal/layanan/'.$this->service.'/ajukan'] as $path) {
            $this->get($this->host.$path)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertDontSee('1234567890123456')->assertDontSee('9876543210987654');
        }
        $this->get($this->host.'/portal/layanan/'.$this->service.'/ajukan')->assertSee('Made Pemohon')->assertSee('Jalan Banjar 12')->assertDontSee('name="nik"', false);
        $this->post($this->host.'/portal/surat', $this->payload(['krama_id' => 999, 'requester_id' => $this->admin->id, 'banjar_id' => $this->b, 'identity_snapshot' => 'FORGED', 'name' => 'FORGED']))->assertRedirect()->assertSessionHasNoErrors();
        $row = DB::table('letter_requests')->first();
        $this->assertSame($this->person, $row->krama_id);
        $this->assertSame($this->self->id, $row->requester_id);
        $this->assertSame('Made Pemohon', LetterManager::unpack($row->identity_snapshot)['name']);
        $this->assertStringNotContainsString('1234567890123456', $row->identity_snapshot);
        $this->assertStringNotContainsString('Keperluan administrasi', $row->purpose);
        $this->assertStringNotContainsString('Made Pemohon', DB::table('audit_logs')->where('action', 'like', 'letter.%')->get()->toJson());
    }

    public function test_full_approval_pdf_archive_and_repeat_generation_are_immutable(): void
    {
        $id = $this->issue();
        Queue::assertPushed(GenerateLetterPdf::class, 1);
        $this->assertDatabaseHas('letter_requests', ['id' => $id, 'status' => 'ISSUED', 'verifier_id' => $this->secretary->id, 'approver_id' => $this->kelian->id]);
        $this->login($this->self);
        $this->get($this->host.'/portal/surat/'.$id.'/pdf')->assertNotFound();
        $this->generate($id);
        $doc = DB::table('issued_documents')->where('request_id', $id)->first();
        $bytes = Crypt::decryptString($doc->pdf_encrypted);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(hash('sha256', $bytes), $doc->sha256);
        $this->assertStringNotContainsString('%PDF-', $doc->pdf_encrypted);
        DB::table('krama')->where('id', $this->person)->update(['name' => 'Changed after issue', 'version' => 2]);
        DB::table('service_definitions')->where('id', $this->service)->update(['title' => 'Changed service']);
        $this->generate($id);
        $this->assertSame($doc->pdf_encrypted, DB::table('issued_documents')->where('id', $doc->id)->value('pdf_encrypted'));
        $this->assertSame('Made Pemohon', LetterManager::unpack($doc->snapshot)['identity']['name']);
        $this->get($this->host.'/portal/surat/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertContent($bytes);
        $this->get($this->host.'/portal?status=ISSUED&number='.urlencode($doc->number))->assertSee($doc->number)->assertDontSee('Changed service');
        $this->get($this->host.'/portal?number=missing')->assertDontSee('Made Pemohon')->assertSee('Belum ada pengajuan');
        foreach ([$this->self, $this->admin, $this->secretary, $this->kelian] as $user) {
            $this->login($user);
            $prefix = $user->id === $this->self->id ? '/portal/surat/' : '/admin/letters/';
            $this->get($this->host.$prefix.$id)->assertOk()->assertSee('Made Pemohon')->assertDontSee('1234567890123456');
        }
    }

    public function test_policy_snapshot_and_no_approval_workflow(): void
    {
        $this->policy(false);
        $id = $this->submit();
        $this->policy(true);
        $this->assertSame('ISSUED', app(LetterManager::class)->review($this->secretary, $id, ['action' => 'verify']));
        $this->assertDatabaseHas('letter_requests', ['id' => $id, 'requires_approval' => false, 'approver_id' => null]);
        $doc = DB::table('issued_documents')->where('request_id', $id)->first();
        $this->assertSame('Penyarikan', LetterManager::unpack($doc->snapshot)['issuer_title']);
        $second = $this->submit();
        $this->policy(false);
        $this->assertSame('VERIFIED', app(LetterManager::class)->review($this->secretary, $second, ['action' => 'verify']));
    }

    public function test_duplicate_submission_and_replayed_approval_do_not_duplicate_numbers(): void
    {
        $input = $this->payload();
        $this->login($this->self);
        $this->post($this->host.'/portal/surat', $input)->assertSessionHasNoErrors();
        $this->post($this->host.'/portal/surat', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('letter_requests', 1);
        $this->post($this->host.'/portal/surat', array_replace($input, ['purpose' => 'Changed payload']))->assertStatus(409);
        $id = DB::table('letter_requests')->value('id');
        $this->login($this->secretary);
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'verify'])->assertSessionHasNoErrors();
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'verify'])->assertSessionHasErrors('action');
        $this->login($this->kelian);
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'approve'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('issued_documents', 1);
        $this->assertSame(1, DB::table('letter_sequences')->value('last_number'));
        $this->issue();
        $this->assertSame(2, DB::table('letter_sequences')->value('last_number'));
        $this->assertSame(2, DB::table('issued_documents')->distinct()->count('number'));
    }

    public function test_foreign_tenant_requests_downloads_and_service_ids_are_rejected(): void
    {
        $id = $this->issue();
        $this->generate($id);
        $foreign = DB::table('service_definitions')->insertGetId(['banjar_id' => $this->b, 'title' => 'Private Beta', 'slug' => 'private-beta', 'status' => 'PUBLISHED', 'published_at' => now(), 'letters_enabled' => true]);
        $this->login($this->self);
        $this->get($this->host.'/portal/layanan/'.$foreign.'/ajukan')->assertNotFound();
        $this->post($this->host.'/portal/surat', $this->payload(['service_definition_id' => $foreign]))->assertNotFound();
        $this->get($this->host.'/portal/layanan')->assertDontSee('Private Beta');
        $this->login($this->admin);
        $this->patch($this->host.'/admin/letters/services/'.$foreign, ['letters_enabled' => 0, 'letter_requires_approval' => 0, 'letter_version' => 1])->assertNotFound();
        $betaAdmin = User::where('email', 'beta@example.test')->firstOrFail();
        $this->actingAs($betaAdmin)->withSession(['auth_host' => 'beta.localhost', 'auth_context' => (string) $this->b]);
        foreach (['', '/pdf'] as $path) {
            $this->get('http://beta.localhost/admin/letters/'.$id.$path)->assertNotFound();
        }
        $this->post('http://beta.localhost/admin/letters/'.$id.'/review', ['action' => 'verify'])->assertNotFound();
    }

    public function test_another_krama_cannot_see_cancel_or_download_requests_even_after_relink(): void
    {
        $id = $this->issue();
        $this->generate($id);
        $other = $this->role('KRAMA');
        DB::table('krama')->where('id', $this->person)->update(['user_id' => $other->id]);
        $this->login($other);
        $this->get($this->host.'/portal')->assertDontSee('Made Pemohon');
        foreach (['', '/pdf'] as $path) {
            $this->get($this->host.'/portal/surat/'.$id.$path)->assertNotFound();
        }
        $this->post($this->host.'/portal/surat/'.$id.'/cancel')->assertNotFound();
    }

    public function test_role_gates_and_separation_of_duties(): void
    {
        $id = $this->submit();
        foreach ([$this->admin, $this->role('PETENGEN'), $this->role('KESINOMAN'), $this->self, $this->kelian] as $actor) {
            $this->login($actor);
            $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'verify'])->assertForbidden();
        }
        $this->login($this->secretary);
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'approve'])->assertForbidden();
        $this->get($this->host.'/admin/letters/services')->assertForbidden();
        $this->get($this->host.'/admin/krama')->assertForbidden();
        $member = DB::table('banjar_user_memberships')->where('user_id', $this->self->id)->value('id');
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->a, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'PENYARIKAN')->value('id')]);
        $this->login($this->self);
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'verify'])->assertForbidden();
        $this->context();
        app(LetterManager::class)->review($this->secretary, $id, ['action' => 'verify']);
        $member = DB::table('banjar_user_memberships')->where('user_id', $this->secretary->id)->value('id');
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->a, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'KELIAN')->value('id')]);
        $this->login($this->secretary);
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'approve'])->assertForbidden();
    }

    public function test_scoped_kelian_only_reviews_and_downloads_assigned_units(): void
    {
        $unit = app(KramaManager::class)->unit($this->admin, null, ['name' => 'Kangin', 'type' => 'TEMPEKAN', 'is_active' => true]);
        $kelian = $this->role('KELIAN', $unit);
        $id = $this->submit();
        app(LetterManager::class)->review($this->secretary, $id, ['action' => 'verify']);
        $this->login($kelian);
        $this->get($this->host.'/admin/letters')->assertDontSee('Made Pemohon');
        $this->get($this->host.'/admin/letters/'.$id)->assertNotFound();
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'approve'])->assertNotFound();
        DB::table('household_unit_memberships')->insert(['banjar_id' => $this->a, 'household_id' => $this->household, 'unit_id' => $unit]);
        $this->get($this->host.'/admin/letters/'.$id)->assertOk()->assertSee('Setujui dan terbitkan');
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->generate($id);
        $this->get($this->host.'/admin/letters/'.$id.'/pdf')->assertOk();
        DB::table('household_unit_memberships')->where('household_id', $this->household)->delete();
        $this->get($this->host.'/admin/letters/'.$id.'/pdf')->assertNotFound();
    }

    public function test_changed_identity_conflicts_at_each_review_stage_and_stale_submission_is_rejected(): void
    {
        $input = $this->payload();
        $id = $this->submit();
        DB::table('krama')->where('id', $this->person)->increment('version');
        $this->assertSame('CONFLICT', app(LetterManager::class)->review($this->secretary, $id, ['action' => 'verify']));
        $this->login($this->self);
        $this->post($this->host.'/portal/surat', $input)->assertSessionHasErrors('version');
        $second = $this->submit();
        app(LetterManager::class)->review($this->secretary, $second, ['action' => 'verify']);
        DB::table('households')->where('id', $this->household)->increment('version');
        $this->assertSame('CONFLICT', app(LetterManager::class)->review($this->kelian, $second, ['action' => 'approve']));
        $this->assertDatabaseCount('issued_documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_rejection_cancel_and_out_of_order_actions_preserve_history(): void
    {
        $id = $this->submit();
        $this->login($this->kelian);
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'approve'])->assertSessionHasErrors('action');
        $this->login($this->secretary);
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'reject'])->assertSessionHasErrors('review_note');
        $this->post($this->host.'/admin/letters/'.$id.'/review', ['action' => 'reject', 'review_note' => 'Keperluan perlu diperjelas'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('letter_requests', ['id' => $id, 'status' => 'REJECTED']);
        $this->assertStringNotContainsString('Keperluan perlu diperjelas', DB::table('letter_request_steps')->get()->toJson());
        $second = $this->submit();
        $this->login($this->self);
        $this->post($this->host.'/portal/surat/'.$second.'/cancel')->assertSessionHasNoErrors();
        $this->post($this->host.'/portal/surat/'.$second.'/cancel')->assertSessionHasErrors('action');
        $this->assertDatabaseHas('letter_request_steps', ['request_id' => $second, 'status' => 'CANCELLED']);
        $third = $this->submit();
        app(LetterManager::class)->review($this->secretary, $third, ['action' => 'verify']);
        $this->post($this->host.'/portal/surat/'.$third.'/cancel')->assertSessionHasErrors('action');
        $this->context();
        $this->assertSame('REJECTED', app(LetterManager::class)->review($this->kelian, $third, ['action' => 'reject', 'review_note' => 'Belum sesuai']));
    }

    public function test_module_gates_apply_to_routes_services_and_public_cta(): void
    {
        $this->get($this->host.'/layanan/surat-keterangan-krama')->assertOk()->assertSee('Ajukan sekarang');
        $this->login($this->self);
        DB::table('banjar_modules')->where('banjar_id', $this->a)->where('code', 'ADMINISTRATION')->update(['status' => 'INACTIVE']);
        $this->get($this->host.'/portal')->assertForbidden();
        $this->get($this->host.'/admin')->assertDontSee('Surat saya');
        $this->get($this->host.'/layanan/surat-keterangan-krama')->assertDontSee('Ajukan sekarang');
        $this->context();
        $this->expectException(HttpException::class);
        app(LetterManager::class)->submit($this->self, $this->payload());
    }

    public function test_inactive_unlinked_and_unpublished_service_cannot_submit_and_sensitive_input_is_not_flashed(): void
    {
        $this->login($this->self);
        $this->post($this->host.'/portal/surat', $this->payload(['submission_key' => 'invalid', 'purpose' => 'Private purpose']))->assertSessionHasErrors('submission_key')->assertSessionMissing('_old_input.purpose');
        DB::table('service_definitions')->where('id', $this->service)->update(['published_at' => now()->addDay()]);
        $this->post($this->host.'/portal/surat', $this->payload())->assertNotFound();
        DB::table('service_definitions')->where('id', $this->service)->update(['published_at' => now(), 'status' => 'ARCHIVED']);
        $this->post($this->host.'/portal/surat', $this->payload())->assertNotFound();
        DB::table('service_definitions')->where('id', $this->service)->update(['status' => 'PUBLISHED', 'letters_enabled' => false]);
        $this->post($this->host.'/portal/surat', $this->payload())->assertNotFound();
        DB::table('krama')->where('id', $this->person)->update(['status' => 'INACTIVE']);
        $this->post($this->host.'/portal/surat', $this->payload())->assertSessionHasErrors('applicant');
        $this->login($this->role('KRAMA'));
        $this->get($this->host.'/portal/layanan')->assertOk()->assertSee('Akun belum terhubung');
        $this->post($this->host.'/portal/surat', $this->payload())->assertSessionHasErrors('applicant');
    }

    public function test_pdf_failure_retry_tenant_context_and_tamper_protection(): void
    {
        $id = $this->issue();
        $doc = DB::table('issued_documents')->where('request_id', $id)->first();
        $job = new GenerateLetterPdf($this->a, $doc->id);
        $this->mock(LetterPdf::class, fn ($mock) => $mock->shouldReceive('render')->once()->andThrow(new \RuntimeException('Renderer failed')));
        try {
            app()->call([$job, 'handle']);
            $this->fail('Expected renderer failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Renderer failed', $e->getMessage());
        }
        $job->failed(new \RuntimeException('Private exception'));
        $this->assertDatabaseHas('issued_documents', ['id' => $doc->id, 'pdf_status' => 'FAILED', 'pdf_encrypted' => null]);
        $this->context();
        $this->login($this->admin);
        $this->get($this->host.'/admin/letters/'.$id)->assertSee('Coba buat PDF lagi');
        $this->post($this->host.'/admin/letters/'.$id.'/retry')->assertSessionHasNoErrors();
        $this->post($this->host.'/admin/letters/'.$id.'/retry')->assertSessionHasErrors('pdf');
        $this->app->forgetInstance(LetterPdf::class);
        $this->generate($id);
        $job->failed(new \RuntimeException('Late failure'));
        $this->assertDatabaseHas('issued_documents', ['id' => $doc->id, 'pdf_status' => 'READY']);
        DB::table('issued_documents')->where('id', $doc->id)->update(['sha256' => str_repeat('0', 64)]);
        $this->get($this->host.'/admin/letters/'.$id.'/pdf')->assertStatus(409);
        $this->assertStringNotContainsString('Private exception', DB::table('audit_logs')->get()->toJson());
    }

    public function test_service_configuration_authorization_validation_and_versioning(): void
    {
        $this->login($this->admin);
        $this->get($this->host.'/admin/letters/services')->assertOk()->assertSee('Surat Keterangan Krama');
        $url = $this->host.'/admin/letters/services/'.$this->service;
        $this->patch($url, ['letter_version' => 1, 'letters_enabled' => 0, 'letter_requires_approval' => 0])->assertSessionHasErrors('letter_version');
        $this->patch($url, ['letter_version' => 2, 'letters_enabled' => 1, 'letter_requires_approval' => 0, 'letter_statement' => ''])->assertSessionHasErrors('letter_statement');
        $this->patch($url, ['letter_version' => 2, 'letters_enabled' => 0, 'letter_requires_approval' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('service_definitions', ['id' => $this->service, 'letter_version' => 3, 'letters_enabled' => false]);
    }

    public function test_composite_foreign_key_protects_requests_and_documents(): void
    {
        $id = $this->issue();
        $this->expectException(QueryException::class);
        DB::table('issued_documents')->where('request_id', $id)->update(['banjar_id' => $this->b]);
    }

    public function test_portal_login_returns_to_requested_form_without_external_redirect(): void
    {
        $path = '/portal/layanan/'.$this->service.'/ajukan';
        $this->get($this->host.$path)->assertRedirect('/login');
        $this->self->update(['password' => 'Testing-Password-12345']);
        $this->post($this->host.'/login', ['email' => $this->self->email, 'password' => 'Testing-Password-12345'])->assertRedirect($path);
        $this->post($this->host.'/logout');
        $this->withSession(['portal_intended' => 'https://example.test/portal'])->post($this->host.'/login', ['email' => $this->self->email, 'password' => 'Testing-Password-12345'])->assertRedirect('/admin');
    }

    public function test_pdf_worker_cannot_cross_tenants_and_revocation_blocks_archive_access(): void
    {
        $id = $this->issue();
        $doc = DB::table('issued_documents')->where('request_id', $id)->first();
        $wrongJob = new GenerateLetterPdf($this->b, $doc->id);
        try {
            app()->call([$wrongJob, 'handle']);
            $this->fail('Cross-tenant job must fail');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertNull(app(CurrentBanjar::class)->optional());
        $wrongJob->failed(new \RuntimeException('Foreign ID'));
        $this->assertDatabaseHas('issued_documents', ['id' => $doc->id, 'pdf_status' => 'PENDING']);
        $this->generate($id);
        $this->login($this->self);
        DB::table('banjar_user_memberships')->where('banjar_id', $this->a)->where('user_id', $this->self->id)->update(['status' => 'INACTIVE']);
        $this->get($this->host.'/portal/surat/'.$id.'/pdf')->assertForbidden();
        $this->login($this->admin);
        DB::table('banjar_modules')->where('banjar_id', $this->a)->where('code', 'ADMINISTRATION')->update(['status' => 'INACTIVE']);
        $this->get($this->host.'/admin/letters/'.$id.'/pdf')->assertForbidden();
    }
}
