<?php

use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/BoostFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['access-payments.min_days' => 1, 'access-payments.max_days' => 366, 'access-payments.duration_days' => 7, 'access-payments.amount_vnd' => '123000', 'access-payments.bank_name' => 'T38 TEST BANK ONLY',
        'access-payments.account_number' => '000000000001', 'access-payments.account_holder' => 'T38 TEST HOLDER ONLY']);
    $this->travelTo(CarbonImmutable::parse('2026-11-28T16:30:00Z'));
});

it('serializes boost requests confirmations and bank reconciliation in real processes', function (string $case) {
    config(['database.connections.t40_committed' => config('database.connections.pgsql')]);
    $probe = DB::connection('t40_committed');
    $defaultConnection = config('database.default');
    $categoryExisted = $probe->table('job_categories')->where('slug', 'sales')->exists();
    $locationExisted = $probe->table('locations')->where('code', '01')->exists();
    $fixtures = [];
    $extraActor = null;
    $processes = [];
    $directory = sys_get_temp_dir().'/viz-t40-'.Str::uuid();
    mkdir($directory, 0700);
    try {
        // Separate committed synthetic fixtures let independent processes see the rows.
        config(['database.default' => 't40_committed']);
        $probe->transaction(function () use (&$fixtures, &$extraActor, $case): void {
            $fixtures[] = boostFixture();
            if ($case !== 'request_twice') {
                $fixtures[0]['order'] = boostPending($fixtures[0]);
            }
            $extraActor = jobManager(Role::Admin);
            if ($case === 'duplicate_reference') {
                $fixtures[] = boostFixture();
                $fixtures[1]['order'] = boostPending($fixtures[1]);
            }
        });
        config(['database.default' => $defaultConnection]);
        $payload = $case === 'request_twice' ? ['plan_id' => $fixtures[0]['plan']->id, 'method' => 'bank_transfer'] : boostPayment(['transaction_reference' => 'T40 CONCURRENT REF']);
        file_put_contents($directory.'/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));
        $database = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => 'viz_ecom_test',
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'],
            'T40_TEST_NOW' => CarbonImmutable::now('UTC')->toIso8601String(),
            'ACCESS_MIN_DAYS' => '1', 'ACCESS_MAX_DAYS' => '366',
            'ACCESS_BANK_NAME' => 'T38 TEST BANK ONLY', 'ACCESS_BANK_ACCOUNT' => '000000000001', 'ACCESS_BANK_HOLDER' => 'T38 TEST HOLDER ONLY',
        ];
        $tag = 't40-'.Str::ulid();
        $first = $fixtures[0];
        $second = $case === 'duplicate_reference' ? $fixtures[1] : $first;
        $secondActorId = $case === 'duplicate_reference' ? $second['admin']->id : $extraActor->id;
        $action = $case === 'request_twice' ? 'request' : 'confirm';
        $idA = $action === 'request' ? $first['job']->id : $first['order']->id;
        $idB = $action === 'request' ? $second['job']->id : $second['order']->id;
        $worker = base_path('tests/Support/BoostOrderWorker.php');
        $processes[] = new Process([PHP_BINARY, $worker, (string) ($action === 'request' ? $first['staff']->id : $first['admin']->id), (string) $idA,
            $directory.'/payload.json', $tag.'-a', $directory, $case === 'duplicate_reference' ? 'bank' : 'job', $action], base_path(), $environment, timeout: 20);
        $processes[0]->start();
        $deadline = microtime(true) + 8;
        while (! file_exists($directory.'/locked') && $processes[0]->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($directory.'/locked'))->toBeTrue();
        $processes[] = new Process([PHP_BINARY, $worker, (string) $secondActorId, (string) $idB,
            $directory.'/payload.json', $tag.'-b', $directory, 'none', $action], base_path(), $environment, timeout: 20);
        $processes[1]->start();
        $waiting = false;
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline && $processes[1]->isRunning()) {
            $waiting = $probe->selectOne("select exists(select 1 from pg_stat_activity where application_name = ? and wait_event_type = 'Lock') as waiting", [$tag.'-b'])->waiting;
            if ($waiting) {
                break;
            }
            usleep(10000);
        }
        expect($waiting)->toBeTrue();
        file_put_contents($directory.'/release', '1');
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue();
        }
        $one = json_decode($processes[0]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $two = json_decode($processes[1]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $companies = array_map(fn ($f) => $f['job']->company_id, $fixtures);
        if ($case === 'request_twice') {
            expect($one['order_id'])->toBe($two['order_id']);
            expect($probe->table('orders')->whereIn('company_id', $companies)->where('status', 'pending')->count())->toBe(1);
        } elseif ($case === 'confirm_twice') {
            expect($one['created'])->toBeTrue();
            expect($two['created'])->toBeFalse();
            expect($one['boost_id'])->toBe($two['boost_id']);
            expect($probe->table('job_boosts')->whereIn('company_id', $companies)->count())->toBe(1);
            expect($probe->table('audit_logs')->whereIn('company_id', $companies)->count())->toBe(2);
        } else {
            expect($one['created'])->toBeTrue();
            expect($two['rejected'])->toBe('duplicate_reference');
            expect($probe->table('orders')->where('id', $second['order']->id)->value('status'))->toBe('pending');
            expect($probe->table('job_boosts')->whereIn('company_id', $companies)->count())->toBe(1);
        }
    } finally {
        config(['database.default' => $defaultConnection]);
        file_put_contents($directory.'/release', '1');
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach ($fixtures as $fixture) {
            $companyId = $fixture['job']->company_id;
            foreach (['audit_logs', 'job_boosts', 'orders'] as $table) {
                $probe->table($table)->where('company_id', $companyId)->delete();
            }
            $probe->table('staff_company')->where('company_id', $companyId)->delete();
            $probe->table('company_user')->where('company_id', $companyId)->delete();
            $probe->table('jobs')->where('id', $fixture['job']->id)->delete();
            $probe->table('companies')->where('id', $companyId)->delete();
            $probe->table('plans')->where('id', $fixture['plan']->id)->delete();
            $probe->table('users')->whereIn('id', [$fixture['admin']->id, $fixture['staff']->id])->delete();
        }
        if ($extraActor) {
            $probe->table('users')->where('id', $extraActor->id)->delete();
        }
        if ($fixtures && ! $categoryExisted) {
            $probe->table('job_categories')->where('id', $fixtures[0]['job']->category_id)->delete();
        }
        if ($fixtures && ! $locationExisted) {
            $probe->table('locations')->where('id', $fixtures[0]['job']->location_id)->delete();
        }
        foreach (['payload.json', 'locked', 'release'] as $name) {
            if (is_file($directory.'/'.$name)) {
                unlink($directory.'/'.$name);
            }
        }
        rmdir($directory);
        DB::purge('t40_committed');
    }
})->with(['request_twice', 'confirm_twice', 'duplicate_reference']);
