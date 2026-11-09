<?php

// Optional local browser fixture. Never runs against the application's database.
use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Payment\PaymentManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinanceFixture;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
    throw new RuntimeException('Browser fixtures require testing environment and banjarv2_testing.');
}
Artisan::call('migrate:fresh', ['--force' => true]);
$fixture = FinanceFixture::create();
DB::table('banjar_modules')->where('banjar_id', $fixture['tenant'])->where('code', 'PAYMENT')->update(['status' => 'ACTIVE']);
$actor = User::findOrFail($fixture['users']['PETENGEN']);
app(FinanceManager::class)->issue(User::findOrFail($fixture['users']['KESINOMAN']), $fixture['event']);
\Tests\Support\IpaymuFake::configure(['payment.settlement_hold_days' => 0]);
$fake = (new \Tests\Support\IpaymuFake)->install();
$fake->defaultStatus = 1;
$ids = [];
foreach (DB::table('bills')->orderBy('id')->get() as $bill) {
    $ids[] = app(PaymentManager::class)->create(FinanceFixture::payer($fixture['tenant'], $bill->id), $bill->id);
}
app(PaymentManager::class)->sync($ids[0]);
$super = User::whereIn('id', DB::table('platform_role_assignments')->select('user_id'))->firstOrFail();
$super->update(['email' => 'platform-payment@example.test', 'password' => $fixture['password']]);
$reviewer = User::factory()->create(['email' => 'reviewer-payment@example.test', 'password' => $fixture['password']]);
DB::table('platform_role_assignments')->insert(['user_id' => $reviewer->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
file_put_contents(storage_path('app/private/payment-preview.json'), json_encode(['password' => $fixture['password'], 'payments' => $ids]));
echo "Payment preview fixture ready in banjarv2_testing.\n";
