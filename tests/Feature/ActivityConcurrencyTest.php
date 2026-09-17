<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Activity\ActivityManager;
use App\Modules\Website\ContentManager;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinanceFixture;
use Tests\TestCase;

class ActivityConcurrencyTest extends TestCase
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

    public function test_parallel_generation_and_record_versus_verify_keep_one_consistent_state(): void
    {
        $fixture = FinanceFixture::create();
        $tenant = $fixture['tenant'];
        DB::table('banjar_modules')->where('banjar_id', $tenant)->where('code', 'ACTIVITY')->update(['status' => 'ACTIVE']);
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($tenant));
        $admin = User::where('email', 'admin-finance@example.test')->firstOrFail();
        $kelian = User::findOrFail($fixture['users']['KELIAN']);
        $manager = app(ActivityManager::class);
        $id = app(ContentManager::class)->save($admin, 'agenda', null, ['title' => 'Ngayah serentak', 'status' => 'PUBLISHED', 'starts_at' => now('Asia/Makassar')->addDay()->format('Y-m-d H:i')]);
        $manager->configure($admin, $id, ['kind' => 'NGAYAH', 'visibility' => 'PUBLIC', 'attendance_basis' => 'PERSON', 'allow_representative' => '1']);
        $manager->addTarget($admin, $id, ['target_type' => 'ALL_KRAMA']);

        $generate = ['tenant' => $tenant, 'actor' => $admin->id, 'action' => 'activity-generate', 'id' => $id];
        $this->assertSame(['SUCCESS', 'SUCCESS'], $this->race($generate, $generate));
        $this->assertSame(3, DB::table('activity_participants')->where('activity_id', $id)->count());

        DB::table('activities')->where('id', $id)->update(['starts_at' => now()->subHour()]);
        $rows = DB::table('activity_participants')->where('activity_id', $id)->orderBy('id')->pluck('id');
        foreach ($rows->slice(0, 2) as $row) {
            $manager->record($admin, $id, $row, ['status' => 'HADIR']);
        }
        $results = $this->race(['tenant' => $tenant, 'actor' => $admin->id, 'action' => 'activity-record', 'id' => $id, 'participant' => $rows->last()],
            ['tenant' => $tenant, 'actor' => $kelian->id, 'action' => 'activity-verify', 'id' => $id]);
        $this->assertContains($results, [['REJECTED', 'SUCCESS'], ['SUCCESS', 'SUCCESS']]);
        $activity = DB::table('activities')->find($id);
        if ($activity->attendance_status === 'VERIFIED') {
            $this->assertSame(0, DB::table('activity_participants')->where('activity_id', $id)->where('status', 'BELUM_DIVERIFIKASI')->count());
            $this->assertSame(1, DB::table('dedosan_candidates')->where('activity_id', $id)->count());
        } else {
            $this->assertSame('TIDAK_HADIR', DB::table('activity_participants')->where('id', $rows->last())->value('status'));
            $this->assertSame(0, DB::table('dedosan_candidates')->where('activity_id', $id)->count());
        }
        $this->assertDatabaseCount('bills', 0);
    }
}
