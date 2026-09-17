<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Platform\Audit\Audit;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

final class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {--name=Super Admin Bali Santih}';

    protected $description = 'Buat akun platform baru; kata sandi diminta secara tersembunyi.';

    public function handle(Audit $audit): int
    {
        $data = ['email' => strtolower($this->argument('email')), 'name' => $this->option('name'), 'password' => $this->secret('Kata sandi (minimal 12 karakter, huruf dan angka)')];
        $validator = Validator::make($data, ['email' => ['required', 'email', 'unique:users,email'], 'name' => ['required', 'string', 'max:150'], 'password' => ['required', Password::min(12)->letters()->numbers()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data, $audit) {
            (new RoleSeeder)->run();
            $user = User::create($data);
            DB::table('platform_role_assignments')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->soleValue('id')]);
            $audit->record(null, null, 'platform.admin_created', 'user', $user->id);
        });
        $this->info('Akun Super Admin dibuat.');

        return self::SUCCESS;
    }
}
