<?php

use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

function grantOrder(array $overrides = []): array
{
    $admin = jobManager(Role::Admin);
    $job = managedJob($admin);
    $order = new Order;
    $order->forceFill(array_replace(['company_id' => $job->company_id, 'type' => 'access_grant', 'amount' => 100000,
        'method' => 'bank_transfer', 'status' => 'paid', 'confirmed_by' => $admin->id, 'confirmed_at' => now()], $overrides))->save();

    return [$order, $job, $admin];
}

function dataGrant($order, $job, $admin, array $overrides = []): AccessGrant
{
    $grant = new AccessGrant;
    $grant->forceFill(array_replace(['company_id' => $job->company_id, 'job_id' => $job->id, 'order_id' => $order->id,
        'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_by' => $admin->id], $overrides));
    $grant->save();

    return $grant;
}

it('rejects grants without an order', function () {
    [$order,$job,$admin] = grantOrder();
    dataGrant($order, $job, $admin, ['order_id' => null]);
})->throws(ValidationException::class);

it('rejects unpaid wrong type or other company orders', function (string $case) {
    [$order,$job,$admin] = grantOrder(match ($case) {
        'unpaid' => ['status' => 'pending'], 'wrong_type' => ['type' => 'job_boost'], default => [],
    });
    if ($case === 'other_company') {
        $job = managedJob($admin);
    }
    dataGrant($order, $job, $admin);
})->with(['unpaid', 'wrong_type', 'other_company'])->throws(ValidationException::class);

it('requires a nonempty reason and a separate method for zero amount orders', function (array $override) {
    grantOrder($override);
})->with([
    [['amount' => 0]], [['amount' => 0, 'method' => 'gift']], [['amount' => 0, 'method' => 'free_trial', 'reason' => " \t\n"]],
])->throws(ValidationException::class);

it('requires a current active admin to confirm a paid order', function () {
    $staff = jobManager();
    grantOrder(['confirmed_by' => $staff->id]);
})->throws(ValidationException::class);

it('rejects paid orders without confirmation metadata', function (string $field) {
    grantOrder([$field => null]);
})->with(['confirmed_by', 'confirmed_at'])->throws(ValidationException::class);

it('rejects a non admin grant creator', function () {
    [$order,$job] = grantOrder();
    dataGrant($order, $job, jobManager());
})->throws(ValidationException::class);

it('does not allow rebinding an existing grant to another job or order', function () {
    [$order,$job,$admin] = grantOrder();
    $grant = dataGrant($order, $job, $admin);
    $other = managedJob($admin);
    $grant->forceFill(['job_id' => $other->id]);
    expect(fn () => $grant->save())->toThrow(ValidationException::class);
    expect($grant->fresh()->job_id)->toBe($job->id);
});

it('fails closed when reading a grant whose paid order was changed through a raw maintenance query', function () {
    [$order,$job,$admin] = grantOrder();
    $grant = dataGrant($order, $job, $admin);
    DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled']);
    expect($grant->isEffective())->toBeFalse();
});

it('creates an effective paid grant including zero amount gifts and trials with reasons', function (string $method) {
    [$order,$job,$admin] = grantOrder($method === 'bank_transfer' ? [] : ['amount' => 0, 'method' => $method, 'reason' => 'Approved demo']);
    $grant = dataGrant($order, $job, $admin);
    expect($grant->isEffective())->toBeTrue()->and($grant->order->id)->toBe($order->id);
    expect($order->accessGrant->id)->toBe($grant->id);
})->with(['bank_transfer', 'free_trial', 'gift']);

it('rejects equal reversed or missing grant dates', function (string $case) {
    [$order,$job,$admin] = grantOrder();
    dataGrant($order, $job, $admin, ['ends_at' => match ($case) {
        'equal' => now(),'reversed' => now()->subSecond(),default => null
    }]);
})->with(['equal', 'reversed', 'missing'])->throws(ValidationException::class);

it('enforces one grant per order in the database', function () {
    [$order,$job,$admin] = grantOrder();
    dataGrant($order, $job, $admin);
    dataGrant($order, $job, $admin);
})->throws(QueryException::class);

it('checks exact UTC start and end boundaries and rechecks revocation on a previously loaded model', function () {
    $this->freezeTime();
    [$order,$job,$admin] = grantOrder();
    $start = now()->addHour()->setMicrosecond(123456);
    $end = $start->copy()->addHour();
    $grant = dataGrant($order, $job, $admin, ['starts_at' => $start, 'ends_at' => $end]);
    expect($grant->isEffective())->toBeFalse();
    $this->travelTo($start);
    expect($grant->isEffective())->toBeTrue();
    $this->travelTo($end->copy()->subMicrosecond());
    expect($grant->isEffective())->toBeTrue();
    $this->travelTo($end);
    expect($grant->isEffective())->toBeFalse();
    $this->travelTo($start);
    $other = AccessGrant::findOrFail($grant->id);
    $other->forceFill(['status' => 'revoked'])->save();
    expect($grant->isEffective())->toBeFalse();
});

it('blocks changing a paid order until its grant is revoked even if the grant has expired', function () {
    [$order,$job,$admin] = grantOrder();
    $grant = dataGrant($order, $job, $admin, ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    try {
        $order->forceFill(['status' => 'cancelled'])->save();
        test()->fail('Must reject');
    } catch (ValidationException) {
    }
    expect($order->fresh()->status)->toBe('paid');
    $grant->forceFill(['status' => 'revoked'])->save();
    $order->forceFill(['status' => 'cancelled'])->save();
    expect($order->fresh()->status)->toBe('cancelled')->and($grant->isEffective())->toBeFalse();
});

it('does not use a stale order model to undo the paid state behind a live grant', function () {
    [$order,$job,$admin] = grantOrder(['status' => 'pending']);
    $stale = Order::findOrFail($order->id);
    $order->forceFill(['status' => 'paid'])->save();
    dataGrant($order, $job, $admin);
    $stale->forceFill(['status' => 'cancelled']);
    expect(fn () => $stale->save())->toThrow(ValidationException::class);
    expect($order->fresh()->status)->toBe('paid');
});

it('enforces cross company order and job links dates and zero amount reasons with database constraints', function (string $case) {
    [$order,$job,$admin] = grantOrder();
    if ($case === 'zero_reason') {
        DB::table('orders')->where('id', $order->id)->update(['amount' => 0, 'method' => 'gift', 'reason' => null]);

        return;
    }
    $other = managedJob($admin);
    DB::table('access_grants')->insert(['company_id' => $case === 'order_company' ? $other->company_id : $job->company_id,
        'job_id' => $case === 'order_company' ? $other->id : ($case === 'job_company' ? $other->id : $job->id),
        'order_id' => $order->id, 'created_by' => $admin->id, 'status' => 'active', 'starts_at' => now(),
        'ends_at' => $case === 'dates' ? now() : now()->addDay()]);
})->with(['order_company', 'job_company', 'dates', 'zero_reason'])->throws(QueryException::class);

it('locks the paid order throughout grant creation so a competing state change cannot pass', function () {
    [$order,$job,$admin] = grantOrder();
    config(['database.connections.grant_lock_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('grant_lock_probe');
    try {
        DB::transaction(function () use ($order, $job, $admin, $probe) {
            dataGrant($order, $job, $admin);
            $probe->statement("SET lock_timeout = '100ms'");
            try {
                $probe->table('orders')->where('id', $order->id)->update(['status' => 'cancelled']);
                test()->fail('Order must be locked');
            } catch (QueryException $exception) {
                expect($exception->getCode())->toBe('55P03');
            }
        });
    } finally {
        DB::purge('grant_lock_probe');
    }
    expect($order->fresh()->status)->toBe('paid');
});
