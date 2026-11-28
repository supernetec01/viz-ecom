<?php

use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/PaymentConfirmationFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['access-payments.min_days' => 1, 'access-payments.max_days' => 366, 'access-payments.duration_days' => 7, 'access-payments.amount_vnd' => '123000', 'access-payments.bank_name' => 'T38 TEST BANK ONLY',
        'access-payments.account_number' => '000000000001', 'access-payments.account_holder' => 'T38 TEST HOLDER ONLY']);
    $this->travelTo(CarbonImmutable::parse('2026-11-28T16:30:00Z'));
});

it('serializes two real admin processes confirming the same order and reuses one grant', function (bool $sameOrder) {
    config(['database.connections.t38_committed' => config('database.connections.pgsql')]);
    $probe = DB::connection('t38_committed');
    $defaultConnection = config('database.default');
    $categoryExisted = $probe->table('job_categories')->where('slug', 'sales')->exists();
    $locationExisted = $probe->table('locations')->where('code', '01')->exists();
    $fixtures = [];
    $extraActor = null;
    $processes = [];
    $directory = sys_get_temp_dir().'/viz-t38-'.Str::uuid();
    mkdir($directory, 0700);
    try {
        // Separate committed synthetic fixtures let independent processes see the rows.
        config(['database.default' => 't38_committed']);
        $probe->transaction(function () use (&$fixtures, &$extraActor, $sameOrder): void {
            $fixtures[] = t38Pending();
            if ($sameOrder) {
                $extraActor = jobManager(Role::Admin);
            } else {
                $fixtures[] = t38Pending();
            }
        });
        config(['database.default' => $defaultConnection]);
        file_put_contents($directory.'/payload.json', json_encode(t38Input(), JSON_THROW_ON_ERROR));
        $database = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => 'viz_ecom_test',
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'],
            'T38_TEST_NOW' => CarbonImmutable::now('UTC')->toIso8601String(),
            'ACCESS_MIN_DAYS' => '1', 'ACCESS_MAX_DAYS' => '366',
        ];
        $tag = 't38-'.Str::ulid();
        $first = $fixtures[0];
        $second = $sameOrder ? $first : $fixtures[1];
        $secondActorId = $sameOrder ? $extraActor->id : $second['admin']->id;
        $worker = base_path('tests/Support/ConfirmPaymentWorker.php');
        $processes[] = new Process([PHP_BINARY, $worker, (string) $first['admin']->id, (string) $first['order']->id,
            $directory.'/payload.json', $tag.'-a', $directory, $sameOrder ? 'job' : 'bank'], base_path(), $environment, timeout: 20);
        $processes[0]->start();
        $deadline = microtime(true) + 8;
        while (! file_exists($directory.'/locked') && $processes[0]->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($directory.'/locked'))->toBeTrue();
        $processes[] = new Process([PHP_BINARY, $worker, (string) $secondActorId, (string) $second['order']->id,
            $directory.'/payload.json', $tag.'-b', $directory, 'none'], base_path(), $environment, timeout: 20);
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
        expect($one['created'])->toBeTrue();
        if ($sameOrder) {
            expect($two['created'])->toBeFalse();
            expect($two['grant_id'])->toBe($one['grant_id']);
        } else {
            expect($two['rejected'])->toBe('duplicate_reference');
            expect($probe->table('orders')->where('id', $second['order']->id)->value('status'))->toBe('pending');
        }
        $companyIds = array_column(array_map(fn ($f) => ['id' => $f['job']->company_id], $fixtures), 'id');
        expect($probe->table('access_grants')->whereIn('company_id', $companyIds)->count())->toBe(1);
        expect($probe->table('audit_logs')->whereIn('company_id', $companyIds)->whereIn('action', ['order.paid_confirmed', 'access_grant.created'])->count())->toBe(2);
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
            foreach (['audit_logs', 'access_grants', 'orders', 'applications'] as $table) {
                $probe->table($table)->where('company_id', $companyId)->delete();
            }
            $probe->table('files')->where('id', $fixture['fileId'])->delete();
            $probe->table('staff_company')->where('company_id', $companyId)->delete();
            $probe->table('company_user')->where('company_id', $companyId)->delete();
            $probe->table('jobs')->where('id', $fixture['job']->id)->delete();
            $probe->table('companies')->where('id', $companyId)->delete();
            $probe->table('users')->whereIn('id', [$fixture['admin']->id, $fixture['employer']->id])->delete();
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
        DB::purge('t38_committed');
    }
})->with(['same order' => true, 'different orders same bank transaction' => false]);
