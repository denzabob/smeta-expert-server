<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\BillingPlan;
use App\Models\User;
use App\Services\Storage\StorageUsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Uses committed fixtures and independent PHP connections, never SQLite or test transactions. */
class AccountStorageReservationRaceTest extends TestCase
{
    public function test_only_one_parallel_reservation_can_consume_the_remaining_shared_quota(): void
    {
        $this->runRace(false);
    }

    public function test_parallel_finalize_rechecks_actual_size_using_current_registry_under_repeatable_read(): void
    {
        $this->runRace(true);
    }

    private function runRace(bool $finalize): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real MySQL/MariaDB is required for the reservation race test.');
        }
        $database = $connection->getDatabaseName();
        $this->assertMatchesRegularExpression('/(?:^|_)test(?:_|$)/i', $database,
            'Refusing fixture writes outside an explicitly named test database.');
        $this->assertSame(0, $connection->transactionLevel(), 'Worker fixtures must be committed and visible.');
        foreach (['storage_files', 'storage_file_links', 'storage_usages', 'storage_usage_modules', 'storage_upload_reservations', 'billing_plans', 'billing_subscriptions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), 'Migrate the isolated test database before this integration test.');
        }
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-storage-race-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700));
        $script = $directory.DIRECTORY_SEPARATOR.'worker.php';
        $processes = [];
        $user = null;
        $plan = null;
        try {
            $user = User::factory()->create();
            $plan = BillingPlan::query()->create([
                'code' => 'storage_race_'.Str::uuid(), 'name' => 'Storage race fixture', 'is_active' => true,
                'metadata_json' => ['limits' => ['storage_bytes' => 100]],
            ]);
            DB::table('billing_subscriptions')->insert([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_code' => $plan->code,
                'status' => 'active', 'source' => 'test', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $usage = app(StorageUsageService::class);
            $usage->register((int) $user->id, 'expert', [
                'disk' => 's1', 'path' => 'expert/race-fixtures/'.Str::uuid(), 'purpose' => 'race_fixture',
                'size_bytes' => 90, 'mime_type' => 'application/pdf',
            ]);
            $reservations = $finalize ? [$usage->reserve((int) $user->id, 'smeta', 1), $usage->reserve((int) $user->id, 'smeta', 1)] : ['', ''];
            file_put_contents($script, $this->workerScript());
            // Connection secrets are passed only in the private child environment, never CLI/output/files.
            $environment = ['APP_ENV' => 'testing', 'STORAGE_RACE_CONNECTION' => json_encode($connection->getConfig(), JSON_THROW_ON_ERROR)];
            DB::beginTransaction();
            $usage->lockUserUsage((int) $user->id);
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, $script, base_path(), (string) $user->id, $directory, (string) $index,
                    $finalize ? 'finalize' : 'reserve', $reservations[$index]], base_path(), $environment);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $this->waitForFiles($directory, 'ready', $processes);
            touch($directory.DIRECTORY_SEPARATOR.'start');
            $this->waitForFiles($directory, 'attempting', $processes);
            // Both children have entered reserve while the owner row is locked by this connection.
            usleep(200000);
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Storage race child failed: '.trim($process->getErrorOutput()));
                $results[] = trim($process->getOutput());
            }
            sort($results);
            $this->assertSame(['STORAGE_QUOTA_EXCEEDED', $finalize ? 'finalized' : 'reserved'], $results);
            $snapshot = $usage->getUserUsage((int) $user->id);
            $this->assertSame($finalize ? 98 : 90, $snapshot['used_bytes']);
            $this->assertSame($finalize ? 1 : 8, $snapshot['reserved_bytes']);
            $this->assertSame(1, DB::table('storage_upload_reservations')->where('user_id', $user->id)->where('status', 'reserved')->count());
            $this->assertSame([], $usage->audit((int) $user->id)['mismatches']);
            if ($finalize) {
                foreach ($reservations as $reservation) {
                    $usage->release($reservation);
                }
                $this->assertSame(0, $usage->getUserUsage((int) $user->id)['reserved_bytes']);
                $this->assertSame(98, $usage->getUserUsage((int) $user->id)['used_bytes']);
                $this->assertSame([], $usage->audit((int) $user->id)['mismatches']);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if ($user !== null) {
                DB::table('storage_upload_reservations')->where('user_id', $user->id)->delete();
                $fileIds = DB::table('storage_files')->where('user_id', $user->id)->pluck('id');
                DB::table('storage_file_links')->whereIn('storage_file_id', $fileIds)->delete();
                DB::table('storage_files')->where('user_id', $user->id)->delete();
                DB::table('storage_usage_modules')->where('user_id', $user->id)->delete();
                DB::table('storage_usages')->where('user_id', $user->id)->delete();
                DB::table('billing_subscriptions')->where('user_id', $user->id)->delete();
                $user->forceDelete();
            }
            if ($plan !== null) {
                $plan->delete();
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function waitForFiles(string $directory, string $suffix, array $processes): void
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (is_file($directory.DIRECTORY_SEPARATOR.'0.'.$suffix) && is_file($directory.DIRECTORY_SEPARATOR.'1.'.$suffix)) {
                return;
            }
            foreach ($processes as $process) {
                if (!$process->isRunning()) {
                    $this->fail('Storage race worker exited before reaching the barrier: '.trim($process->getErrorOutput()));
                }
            }
            usleep(20000);
        }
        $this->fail('Storage race barrier timed out.');
    }

    private function workerScript(): string
    {
        return <<<'PHP'
<?php
try {
    require $argv[1].'/vendor/autoload.php';
    $app = require $argv[1].'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connection = json_decode(getenv('STORAGE_RACE_CONNECTION'), true, flags: JSON_THROW_ON_ERROR);
    config(['database.default' => 'storage_race', 'database.connections.storage_race' => $connection]);
    Illuminate\Support\Facades\DB::purge('storage_race');
    Illuminate\Support\Facades\DB::setDefaultConnection('storage_race');
    if (Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql'
        || !preg_match('/(?:^|_)test(?:_|$)/i', Illuminate\Support\Facades\DB::connection()->getDatabaseName())) {
        throw new RuntimeException('Unsafe race connection');
    }
    if ($argv[5] === 'finalize') {
        Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        Illuminate\Support\Facades\DB::beginTransaction();
        // Deliberately establish an old consistent snapshot before waiting for the owner lock.
        Illuminate\Support\Facades\DB::table('storage_files')->where('user_id', (int) $argv[2])->get();
    }
    touch($argv[3].DIRECTORY_SEPARATOR.$argv[4].'.ready');
    $deadline = microtime(true) + 15;
    while (!is_file($argv[3].DIRECTORY_SEPARATOR.'start')) {
        if (microtime(true) >= $deadline) { throw new RuntimeException('Barrier timeout'); }
        usleep(20000);
    }
    touch($argv[3].DIRECTORY_SEPARATOR.$argv[4].'.attempting');
    try {
        $usage = $app->make(App\Services\Storage\StorageUsageService::class);
        if ($argv[5] === 'finalize') {
            $usage->finalize($argv[6], ['disk' => 's1', 'path' => 'smeta/finalize-race/'.$argv[6],
                'purpose' => 'race_fixture', 'size_bytes' => 8, 'mime_type' => 'application/pdf']);
            Illuminate\Support\Facades\DB::commit();
            echo 'finalized';
        } else {
            $usage->reserve((int) $argv[2], 'smeta', 8);
            echo 'reserved';
        }
    } catch (App\Services\Storage\StorageQuotaException $e) {
        while (Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            Illuminate\Support\Facades\DB::rollBack();
        }
        echo $e->errorCode;
    }
} catch (Throwable $e) {
    $diagnostic = ['code' => 'STORAGE_RACE_WORKER_FAILED', 'exception_class' => get_class($e)];
    if ($e instanceof Illuminate\Database\QueryException) {
        $diagnostic['sqlstate'] = $e->errorInfo[0] ?? null;
        $diagnostic['driver_error_code'] = $e->errorInfo[1] ?? null;
    }
    if (in_array($e->getMessage(), ['Unsafe race connection', 'Barrier timeout', 'STORAGE_RESERVATION_CONFLICT',
        'STORAGE_RESERVATION_NOT_FOUND', 'STORAGE_RESERVATION_INACTIVE', 'STORAGE_OBJECT_METADATA_CONFLICT'], true)) {
        $diagnostic['reason'] = $e->getMessage();
    }
    if ($e instanceof App\Services\Storage\StorageQuotaException) {
        $diagnostic['reason'] = $e->errorCode;
    }
    fwrite(STDERR, json_encode($diagnostic));
    exit(1);
}
PHP;
    }
}
