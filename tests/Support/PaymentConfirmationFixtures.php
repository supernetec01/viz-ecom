<?php

use App\Models\Order;
use App\Services\RequestApplicationAccess;
use App\Support\GrantDuration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

require_once __DIR__.'/ApplicationPolicyFixtures.php';

function t38Pending(bool $legacy = false): array
{
    $fixture = applicationAccessFixture();
    if ($legacy) {
        $order = new Order;
        $order->forceFill(['company_id' => $fixture['job']->company_id, 'job_id' => $fixture['job']->id, 'type' => 'access_grant',
            'amount' => 123000, 'payment_reference' => 'LEGACY-'.Str::ulid(),
            'payment_instructions' => ['bank' => 'T38 TEST BANK ONLY', 'account' => '000000000001', 'holder' => 'T38 TEST HOLDER ONLY']])->save();
    } else {
        $order = app(RequestApplicationAccess::class)->request($fixture['employer'], (string) $fixture['job']->id);
    }
    $fixture['order'] = $order;

    return $fixture;
}

function t38Input(array $overrides = []): array
{
    return array_replace(['received_amount' => 123000, 'transaction_reference' => 'BANK REF 001',
        'ends_at' => CarbonImmutable::now(GrantDuration::TIMEZONE)->addDays(7)->endOfDay()->format('Y-m-d\TH:i:s')], $overrides);
}
