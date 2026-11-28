<?php

use App\Services\AccessGrantLifecycle;
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

it('serializes renewal creation and revocation against confirmation in separate real processes', function (string $case) {
    config(['database.connections.t39_committed' => config('database.connections.pgsql')]);
    $probe = DB::connection('t39_committed');
    $defaultConnection = config('database.default');
    $categoryExisted = $probe->table('job_categories')->where('slug', 'sales')->exists();
    $locationExisted = $probe->table('locations')->where('code', '01')->exists();
    $fixtures = [];
    $extraActor = null;
    $processes = [];
    $directory = sys_get_temp_dir().'/viz-t39-'.Str::uuid();
    mkdir($directory, 0700);
    try {
        // Separate committed synthetic fixtures let independent processes see the rows.
        config(['database.default' => 't39_committed']);
        $grant = null;
        $pending = null;
        $probe->transaction(function () use (&$fixtures, &$grant, &$pending, $case): void {
            $fixtures[] = applicationAccessFixture();
            $grant = applicationAccessGrant($fixtures[0]);
            if ($case !== 'renew_twice') {
                $pending = app(AccessGrantLifecycle::class)->renew($fixtures[0]['admin'], (string) $grant->id, ['method' => 'bank_transfer']);
            }
        });
        $first = $fixtures[0];
        $lifecycle = app(AccessGrantLifecycle::class);
        $preview = ['reason' => 'security', 'preview_signature' => $lifecycle->previewSignature($first['admin'], $grant, $lifecycle->affected($grant))];
        $confirmation = t38Input(['ends_at' => $grant->ends_at->setTimezone('Asia/Ho_Chi_Minh')->addDays(7)->endOfDay()->format('Y-m-d\TH:i:s')]);
        config(['database.default' => $defaultConnection]);
        $actions = match ($case) {
            'renew_twice' => ['renew', 'renew'],
            'confirm_first' => ['confirm', 'revoke'],
            default => ['revoke', 'confirm'],
        };
        foreach ($actions as $index => $action) {
            file_put_contents($directory.'/payload'.$index.'.json', json_encode(match ($action) {
                'renew' => ['method' => 'bank_transfer'], 'revoke' => $preview, default => $confirmation,
            }, JSON_THROW_ON_ERROR));
        }
        $database = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => 'viz_ecom_test',
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'],
            'T39_TEST_NOW' => CarbonImmutable::now('UTC')->toIso8601String(),
            'ACCESS_MIN_DAYS' => '1', 'ACCESS_MAX_DAYS' => '366', 'ACCESS_DURATION_DAYS' => '7',
            'ACCESS_PRICE_VND' => '123000', 'ACCESS_BANK_NAME' => 'T38 TEST BANK ONLY', 'ACCESS_BANK_ACCOUNT' => '000000000001', 'ACCESS_BANK_HOLDER' => 'T38 TEST HOLDER ONLY',
        ];
        $tag = 't39-'.Str::ulid();
        $worker = base_path('tests/Support/GrantLifecycleWorker.php');
        $idA = $actions[0] === 'confirm' ? $pending->id : $grant->id;
        $idB = $actions[1] === 'confirm' ? $pending->id : $grant->id;
        $processes[] = new Process([PHP_BINARY, $worker, (string) $first['admin']->id, (string) $idA,
            $directory.'/payload0.json', $tag.'-a', $directory, 'yes', $actions[0]], base_path(), $environment, timeout: 20);
        $processes[0]->start();
        $deadline = microtime(true) + 8;
        while (! file_exists($directory.'/locked') && $processes[0]->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($directory.'/locked'))->toBeTrue();
        $processes[] = new Process([PHP_BINARY, $worker, (string) $first['admin']->id, (string) $idB,
            $directory.'/payload1.json', $tag.'-b', $directory, 'no', $actions[1]], base_path(), $environment, timeout: 20);
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
        $scope = $first['job']->company_id;
        if ($case === 'renew_twice') {
            expect($one['order_id'])->toBe($two['order_id']);
            expect($probe->table('orders')->where('company_id', $scope)->where('status', 'pending')->count())->toBe(1);
        } elseif ($case === 'confirm_first') {
            expect($one['created'])->toBeTrue();
            expect($two['status'])->toBe(409);
            expect($probe->table('access_grants')->where('company_id', $scope)->where('status', 'active')->count())->toBe(2);
        } else {
            expect($one['revoked'])->toBe(1);
            expect($two['rejected'])->toBe('renewal_missing');
            expect($probe->table('access_grants')->where('company_id', $scope)->where('status', 'active')->count())->toBe(0);
            expect($probe->table('orders')->where('id', $pending->id)->value('status'))->toBe('pending');
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
        foreach (['payload0.json', 'payload1.json', 'locked', 'release'] as $name) {
            if (is_file($directory.'/'.$name)) {
                unlink($directory.'/'.$name);
            }
        }
        rmdir($directory);
        DB::purge('t39_committed');
    }
})->with(['renew_twice', 'confirm_first', 'revoke_first']);
