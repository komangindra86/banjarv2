<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinanceFixture;
use Tests\TestCase;

class FinanceConcurrencyTest extends TestCase
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

    public function test_real_parallel_issue_settlement_reversal_and_spending_keep_financial_invariants(): void
    {
        $fixture = FinanceFixture::create();
        $base = ['tenant' => $fixture['tenant'], 'actor' => $fixture['users']['PETENGEN']];
        // Kesinoman menerbitkan tagihan; Petengen mencatat kas/pelunasan/pembalikan (Master Prompt §27-29).
        $issue = array_replace($base, ['actor' => $fixture['users']['KESINOMAN']]) + ['action' => 'issue', 'id' => $fixture['event']];
        $this->assertSame(['SUCCESS', 'SUCCESS'], $this->race($issue, $issue));
        $this->assertDatabaseCount('bills', 3);
        $bill = DB::table('bills')->value('id');
        $input = ['cash_account_id' => $fixture['account'], 'transaction_date' => now('Asia/Makassar')->toDateString(), 'reference' => 'RACE-TEST', 'submission_key' => (string) Str::uuid()];
        $settle = $base + ['action' => 'settle', 'id' => $bill, 'input' => $input];
        $this->assertSame(['REJECTED', 'SUCCESS'], $this->race($settle, array_replace($settle, ['input' => array_replace($input, ['submission_key' => (string) Str::uuid()])])));
        $this->assertDatabaseCount('bill_settlements', 1);
        $finance = app(FinanceManager::class);
        $this->assertSame(50000, $finance->balance($fixture['account']));
        $cash = DB::table('cash_transactions')->value('id');
        $reverse = $base + ['action' => 'reverse', 'id' => $cash];
        $this->assertSame(['SUCCESS', 'SUCCESS'], $this->race($reverse, $reverse));
        $this->assertSame(0, $finance->balance($fixture['account']));
        $this->assertDatabaseCount('cash_transactions', 2);
        $treasurer = User::findOrFail($base['actor']);
        $finance->cash($treasurer, array_replace($input, ['direction' => 'IN', 'amount' => 100000, 'reason' => 'Modal pengujian', 'submission_key' => (string) Str::uuid()]));
        $spendInput = array_replace($input, ['direction' => 'OUT', 'amount' => 75000, 'reason' => 'Belanja paralel', 'submission_key' => (string) Str::uuid()]);
        $spend = $base + ['action' => 'cash', 'input' => $spendInput];
        $this->assertSame(['REJECTED', 'SUCCESS'], $this->race($spend, array_replace($spend, ['input' => array_replace($spendInput, ['submission_key' => (string) Str::uuid()])])));
        $this->assertSame(25000, $finance->balance($fixture['account']));
    }
}
