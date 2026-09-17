<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Payment\LedgerService;
use App\Modules\Payment\WithdrawalManager;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\WithdrawalFixture;
use Tests\TestCase;

class WithdrawalConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Concurrency test requires banjarv2_testing on MySQL.');
        }
    }

    // Two independent PHP processes with separate MySQL connections, released by a file barrier.
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

    public function test_parallel_requests_claims_and_terminal_actions_keep_ledger_consistent(): void
    {
        $fixture = WithdrawalFixture::create();
        $tenant = $fixture['tenant'];
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($tenant));
        $petengen = User::findOrFail($fixture['users']['PETENGEN']);
        [$operatorA, $operatorB] = $fixture['platform'];
        $ledger = app(LedgerService::class);
        $manager = app(WithdrawalManager::class);
        $base = ['tenant' => $tenant, 'actor' => $petengen->id];

        // Leave exactly 100.000 available, then two parallel 80.000 requests: only one may reserve.
        $manager->request($petengen, ['amount' => '48950', 'submission_key' => (string) Str::uuid()]);
        $this->assertSame(100000, $ledger->balance($tenant, 'AVAILABLE'));
        $request = fn () => $base + ['action' => 'withdraw-request', 'input' => ['amount' => '80000', 'submission_key' => (string) Str::uuid()]];
        $this->assertSame(['REJECTED', 'SUCCESS'], $this->race($request(), $request()));
        $this->assertSame(20000, $ledger->balance($tenant, 'AVAILABLE'));
        $this->assertSame(128950, $ledger->balance($tenant, 'RESERVED'));
        $big = (int) DB::table('withdrawals')->where('amount', 80000)->value('id');

        // Same idempotency key submitted twice in parallel creates one withdrawal.
        $key = (string) Str::uuid();
        $same = $base + ['action' => 'withdraw-request', 'input' => ['amount' => '5000', 'submission_key' => $key]];
        $this->assertSame(['SUCCESS', 'SUCCESS'], $this->race($same, $same));
        $this->assertSame(1, DB::table('withdrawals')->where('request_key', $key)->count());

        // Tenant cancel racing a platform claim: exactly one wins.
        $contested = (int) DB::table('withdrawals')->where('request_key', $key)->value('id');
        $this->assertSame(['REJECTED', 'SUCCESS'], $this->race($base + ['action' => 'withdraw-cancel', 'id' => $contested], ['tenant' => $tenant, 'actor' => $operatorA, 'action' => 'withdraw-claim', 'id' => $contested]));
        $this->assertContains(DB::table('withdrawals')->where('id', $contested)->value('status'), ['CANCELLED', 'PROCESSING']);

        // Two operators claiming the same transfer: one claim only.
        $this->assertSame(['REJECTED', 'SUCCESS'], $this->race(['tenant' => $tenant, 'actor' => $operatorA, 'action' => 'withdraw-claim', 'id' => $big], ['tenant' => $tenant, 'actor' => $operatorB, 'action' => 'withdraw-claim', 'id' => $big]));
        $claimer = (int) DB::table('withdrawals')->where('id', $big)->value('claimed_by');

        // Paid racing failed on the same claimed transfer: one terminal state, one journal.
        $this->assertSame(['REJECTED', 'SUCCESS'], $this->race(['tenant' => $tenant, 'actor' => $claimer, 'action' => 'withdraw-paid', 'id' => $big, 'reference' => 'RACE-TRF-001'], ['tenant' => $tenant, 'actor' => $claimer, 'action' => 'withdraw-failed', 'id' => $big]));
        $status = DB::table('withdrawals')->where('id', $big)->value('status');
        $this->assertContains($status, ['PAID', 'FAILED']);
        $this->assertSame(1, DB::table('financial_ledger_transactions')->where('withdrawal_id', $big)->whereIn('type', ['paid', 'release'])->count());
        $this->assertSame([], $manager->invariants($tenant));
        $this->assertGreaterThanOrEqual(0, $ledger->balance($tenant, 'AVAILABLE'));
        $this->assertSame(148950, $ledger->balance($tenant, 'AVAILABLE') + $ledger->balance($tenant, 'RESERVED') + $ledger->balance($tenant, 'PAID_OUT'));
    }
}
