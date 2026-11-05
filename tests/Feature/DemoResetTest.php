<?php

namespace Tests\Feature;

use App\Models\User;
use App\Platform\Tenants\DemoResetManager;
use App\Platform\Tenants\TenantManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

// Banjar demo reviewer: dipulihkan ke snapshot; data, berkas, dan akun buatan reviewer hilang, Banjar lain utuh.
class DemoResetTest extends TestCase
{
    use RefreshDatabase;

    private int $demo;

    private int $other;

    private User $admin;

    private string $host = 'http://demo.localhost';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new RuntimeException('Refusing to refresh anything except banjarv2_testing on MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.host' => 'admin.banjar.localhost', 'platform.tenant_base_domain' => 'localhost']);
        $this->seed(RoleSeeder::class);
        $super = User::factory()->create(['status' => 'ACTIVE']);
        DB::table('platform_role_assignments')->insert(['user_id' => $super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        foreach (['demo' => 'demo', 'other' => 'lain'] as $key => $slug) {
            $this->$key = app(TenantManager::class)->create($super, ['name' => 'Banjar '.$slug, 'slug' => $slug, 'admin_name' => 'Admin '.$slug, 'admin_email' => $slug.'@example.test', 'admin_password' => 'Test-Password-12345', 'admin_password_confirmation' => 'Test-Password-12345']);
        }
        $this->admin = User::where('email', 'demo@example.test')->firstOrFail();
        DB::table('users')->where('id', $this->admin->id)->update(['password' => Hash::make('balisantih'), 'password_locked' => true]);
        config(['platform.demo_banjar_id' => $this->demo]);
        Storage::fake('local');
    }

    private function photo(int $banjar, string $name): int
    {
        $path = 'banjars/'.$banjar.'/media/'.$name.'.webp';
        Storage::disk('local')->put($path, 'foto '.$name);

        return DB::table('media_files')->insertGetId(['banjar_id' => $banjar, 'path' => $path, 'name' => $name, 'mime' => 'image/webp', 'size' => 10, 'visibility' => 'PUBLIC', 'uploader_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function announcement(int $banjar, string $title, ?int $media = null): int
    {
        return DB::table('announcements')->insertGetId(['banjar_id' => $banjar, 'title' => $title, 'slug' => Str::slug($title), 'status' => 'PUBLISHED', 'priority' => 'NORMAL', 'published_at' => now()->subHour(), 'media_id' => $media, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_reset_restores_demo_and_removes_reviewer_data_files_and_accounts(): void
    {
        $baselinePhoto = $this->photo($this->demo, 'awal');
        $this->announcement($this->demo, 'Pengumuman awal', $baselinePhoto);
        $otherPhoto = $this->photo($this->other, 'milik-lain');
        $otherNews = $this->announcement($this->other, 'Kabar Banjar lain', $otherPhoto);
        $result = app(DemoResetManager::class)->snapshot($this->demo);
        $this->assertSame(1, $result['files']);

        // Reviewer mengubah demo: unggah foto lewat formulir, buat pengumuman, ganti template, buat akun warga.
        $this->post($this->host.'/login', ['email' => 'demo@example.test', 'password' => 'balisantih'])->assertRedirect('/admin');
        $this->post($this->host.'/admin/content/pengumuman', ['title' => 'Coretan reviewer', 'status' => 'PUBLISHED', 'priority' => 'NORMAL', 'photos' => [UploadedFile::fake()->image('reviewer.jpg', 50, 50)]])->assertSessionHasNoErrors();
        $reviewerFile = DB::table('media_files')->where('name', 'reviewer.jpg')->value('path');
        Storage::disk('local')->assertExists($reviewerFile);
        DB::table('banjar_settings')->where('banjar_id', $this->demo)->update(['template_code' => 'template-03']);
        DB::table('announcements')->where('title', 'Pengumuman awal')->update(['title' => 'Diubah reviewer']);
        $reviewerUser = DB::table('users')->insertGetId(['name' => 'Warga buatan reviewer', 'password' => Hash::make('x'), 'nik_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('banjar_user_memberships')->insert(['banjar_id' => $this->demo, 'user_id' => $reviewerUser, 'status' => 'ACTIVE']);
        // Akun warga Banjar lain yang ikut terpasang ke demo (NIK sama) hanya dilepas dari demo, tidak dihapus.
        $shared = User::where('email', 'lain@example.test')->firstOrFail();
        DB::table('banjar_user_memberships')->insert(['banjar_id' => $this->demo, 'user_id' => $shared->id, 'status' => 'ACTIVE']);
        DB::table('users')->where('id', $this->admin->id)->update(['password' => Hash::make('dibajak')]);

        Artisan::call('demo:reset');

        $this->assertSame(['Pengumuman awal'], DB::table('announcements')->where('banjar_id', $this->demo)->pluck('title')->all());
        $this->assertSame([$baselinePhoto], DB::table('media_files')->where('banjar_id', $this->demo)->pluck('id')->map(fn ($id) => (int) $id)->all());
        Storage::disk('local')->assertMissing($reviewerFile);
        Storage::disk('local')->assertExists('banjars/'.$this->demo.'/media/awal.webp');
        $this->assertNotSame('template-03', DB::table('banjar_settings')->where('banjar_id', $this->demo)->value('template_code'));
        $this->assertDatabaseMissing('users', ['id' => $reviewerUser]);
        $this->assertDatabaseHas('users', ['id' => $shared->id]);
        $this->assertDatabaseMissing('banjar_user_memberships', ['banjar_id' => $this->demo, 'user_id' => $shared->id]);
        $this->assertTrue(Hash::check('balisantih', DB::table('users')->where('id', $this->admin->id)->value('password')));
        // Banjar lain tidak tersentuh.
        $this->assertDatabaseHas('announcements', ['id' => $otherNews, 'banjar_id' => $this->other, 'title' => 'Kabar Banjar lain']);
        Storage::disk('local')->assertExists('banjars/'.$this->other.'/media/milik-lain.webp');
        $this->assertDatabaseHas('banjar_user_memberships', ['banjar_id' => $this->other, 'user_id' => $shared->id]);
        // Setelah dipulihkan, akun reviewer tetap bisa masuk dan data demo tampil seperti semula.
        $this->post($this->host.'/login', ['email' => 'demo@example.test', 'password' => 'balisantih'])->assertRedirect('/admin');
        $this->get($this->host.'/pengumuman/pengumuman-awal')->assertOk()->assertSee('/media/'.$baselinePhoto, false);
    }

    public function test_only_the_configured_demo_banjar_can_be_snapshotted_or_reset(): void
    {
        foreach (['snapshot', 'reset'] as $action) {
            try {
                app(DemoResetManager::class)->$action($this->other);
                $this->fail('Banjar non-demo tidak boleh diproses.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('bukan Banjar demo', $e->getMessage());
            }
        }
        config(['platform.demo_banjar_id' => null]);
        $this->expectException(RuntimeException::class);
        app(DemoResetManager::class)->reset($this->demo);
    }

    public function test_reset_requires_snapshot_and_is_scheduled_nightly(): void
    {
        try {
            app(DemoResetManager::class)->reset($this->demo);
            $this->fail('Reset tanpa snapshot harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Belum ada snapshot', $e->getMessage());
        }
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'demo:reset'));
        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Asia/Makassar', $event->timezone);
    }
}
