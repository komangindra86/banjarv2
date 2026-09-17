<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenants\TenantManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CreateLocalDemo extends Command
{
    protected $signature = 'platform:demo';

    protected $description = 'Buat dua Banjar demo lokal dan simpan akses acak di storage privat.';

    public function handle(TenantManager $manager, Audit $audit): int
    {
        if (! app()->environment('local') || ! str_ends_with(config('platform.host'), '.localhost')) {
            $this->error('Demo hanya tersedia pada environment local dengan host .localhost.');

            return self::FAILURE;
        }
        if (DB::table('banjars')->exists() || User::where('email', 'superadmin@example.test')->exists()) {
            $this->error('Demo memerlukan database Banjar kosong; data yang ada tidak diubah.');

            return self::FAILURE;
        }
        $password = Str::random(24).'9a';
        DB::transaction(function () use ($password, $manager, $audit) {
            (new RoleSeeder)->run();
            $super = User::create(['name' => 'Admin Bali Santih', 'email' => 'superadmin@example.test', 'password' => $password]);
            DB::table('platform_role_assignments')->insert(['user_id' => $super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->soleValue('id')]);
            $audit->record(null, null, 'platform.demo_created', 'user', $super->id);
            foreach (['biaung' => 'Banjar Biaung', 'tegalgundul' => 'Banjar Tegal Gundul'] as $slug => $name) {
                $manager->create($super, ['name' => $name, 'slug' => $slug, 'admin_name' => 'Pengurus '.$name, 'admin_email' => $slug.'@example.test', 'admin_password' => $password, 'admin_password_confirmation' => $password]);
            }
        });
        Storage::disk('local')->put('demo-access.txt', "AKSES DEMO LOKAL — bukan akun produksi\n\nhttp://".config('platform.host').":18915/login\nEmail: superadmin@example.test\n\nhttp://biaung.".config('platform.tenant_base_domain').":18915/login\nEmail: biaung@example.test\n\nhttp://tegalgundul.".config('platform.tenant_base_domain').":18915/login\nEmail: tegalgundul@example.test\n\nKata sandi ketiga akun: ".$password."\n");
        $this->info('Demo siap. Akses tersimpan di storage/app/private/demo-access.txt (tidak masuk Git).');

        return self::SUCCESS;
    }
}
