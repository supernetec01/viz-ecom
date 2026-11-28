<?php

use App\Enums\Role;
use App\Models\Order;
use App\Models\Plan;
use App\Services\BoostOrders;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function boostFixture(): array
{
    $admin = jobManager(Role::Admin);
    $staff = jobManager(Role::Staff);
    $job = publishManagedJob(managedJob($staff));
    $plan = Plan::create(['name' => 'Gói thử 3 ngày', 'duration_days' => 3, 'priority' => 10, 'price' => 225000]);

    return compact('admin', 'staff', 'job', 'plan');
}

function boostPending(array $f, array $input = []): Order
{
    return app(BoostOrders::class)->request($f['staff'], (string) $f['job']->id, array_replace(['plan_id' => $f['plan']->id, 'method' => 'bank_transfer'], $input));
}

function boostPayment(array $input = []): array
{
    return array_replace(['received_amount' => 225000, 'transaction_reference' => 'BOOST-'.Str::ulid()], $input);
}

function boostConfirm(array $f, Order $order): array
{
    return app(BoostOrders::class)->confirm($f['admin'], (string) $order->id, boostPayment(['received_amount' => $order->amount]), Request::create('/'));
}
