<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Payment\LedgerService;
use App\Modules\Payment\SettlementManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinanceFixture;
use Tests\TestCase;

class PaymentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Concurrency test requires banjarv2_testing on MySQL.');
        }
    }

    private function race(array $one, array $two): array
    {
        $processes = [];
        $gate = storage_path('framework/testing-race-'.Str::uuid());
        $readyFiles = [];
        foreach ([$one, $two] as $index => $data) {
            $data['gate'] = $gate;
            $data['ready'] = $gate.'-'.$index;
            $readyFiles[] = $data['ready'];
            $process = new Process([PHP_BINARY, base_path('tests/Support/finance-race-worker.php'), base64_encode(json_encode($data))], base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'banjarv2_testing',
                'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
                'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_URL' => '',
            ], null, 25);
            $process->start();
            $processes[] = $process;
        }
        try {
            $deadline = microtime(true) + 10;
            foreach ($readyFiles as $index => $readyFile) {
                while (! is_file($readyFile) && microtime(true) < $deadline && $processes[$index]->isRunning()) {
                    usleep(10000);
                    clearstatcache();
                }
                $this->assertFileExists($readyFile, $processes[$index]->getErrorOutput().$processes[$index]->getOutput());
            }
            // Release both independent workers together; stdin pipes on Windows may buffer.
            file_put_contents($gate, 'go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = trim(str_replace('READY', '', $process->getOutput()));
            }
            sort($results);

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ([$gate, ...$readyFiles] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_parallel_charge_callbacks_manual_settlement_and_release_preserve_invariants(): void
    {
        $fixture = FinanceFixture::create();
        DB::table('banjar_modules')->where('banjar_id', $fixture['tenant'])->where('code', 'PAYMENT')->update(['status' => 'ACTIVE']);
        $actor = User::findOrFail($fixture['users']['PETENGEN']);
        app(FinanceManager::class)->issue(User::findOrFail($fixture['users']['KESINOMAN']), $fixture['event']);
        $base = ['tenant' => $fixture['tenant'], 'actor' => $actor->id];
        $bill = DB::table('bills')->orderBy('id')->value('id');
        // QRIS dibuat warga pemilik tagihan (portal), bukan Petengen.
        $create = ['action' => 'payment-create', 'id' => $bill, 'actor' => FinanceFixture::payer($fixture['tenant'], $bill)->id] + $base;
        $this->assertSame(['SUCCESS', 'SUCCESS'], $this->race($create, $create));
        $this->assertDatabaseCount('payments', 1);
        $id = DB::table('payments')->value('id');
        $sync = $base + ['action' => 'payment-sync', 'id' => $id];
        $this->assertSame(['SUCCESS', 'SUCCESS'], $this->race($sync, $sync));
        $this->assertDatabaseCount('bill_settlements', 1);
        $this->assertDatabaseCount('financial_ledger_transactions', 1);
        $admins = [];
        foreach ([1, 2] as $i) {
            $admins[] = $admin = User::factory()->create();
            DB::table('platform_role_assignments')->insert(['user_id' => $admin->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        }
        app(SettlementManager::class)->submit($admins[0], $id, ['reference' => 'Bank evidence concurrency test', 'gateway_fee' => 350, 'settled_at' => now('Asia/Makassar')->format('Y-m-d\TH:i')]);
        app(SettlementManager::class)->approve($admins[1], $id);
        $release = $base + ['action' => 'payment-release', 'id' => $id];
        $this->assertSame(['SUCCESS', 'SUCCESS'], $this->race($release, $release));
        $ledger = app(LedgerService::class);
        $this->assertSame(49650, $ledger->balance($fixture['tenant'], 'AVAILABLE'));
        $this->assertSame(0, $ledger->balance($fixture['tenant'], 'PENDING'));
        $this->assertDatabaseCount('financial_ledger_transactions', 3);

        $bill2 = DB::table('bills')->where('status', 'UNPAID')->orderBy('id')->value('id');
        $create2 = ['action' => 'payment-create', 'id' => $bill2, 'actor' => FinanceFixture::payer($fixture['tenant'], $bill2)->id] + $base;
        $this->assertSame(['SUCCESS', 'SUCCESS'], $this->race($create2, $create2));
        $id2 = DB::table('payments')->where('bill_id', $bill2)->value('id');
        $input = ['cash_account_id' => $fixture['account'], 'transaction_date' => now('Asia/Makassar')->toDateString(), 'reference' => 'RACE-GATEWAY', 'submission_key' => (string) Str::uuid()];
        $results = $this->race($base + ['action' => 'payment-sync', 'id' => $id2], $base + ['action' => 'settle', 'id' => $bill2, 'input' => $input]);
        $this->assertContains($results, [['REJECTED', 'SUCCESS'], ['SUCCESS', 'SUCCESS']]);
        $this->assertSame(1, DB::table('bill_settlements')->where('bill_id', $bill2)->whereNull('reversed_at')->count());
        $this->assertSame(50000, $ledger->balance($fixture['tenant'], 'PENDING') + $ledger->balance($fixture['tenant'], 'EXCEPTION'));
        $this->assertSame(1, DB::table('financial_ledger_transactions')->where('payment_id', $id2)->where('type', 'received')->count());
    }
}
