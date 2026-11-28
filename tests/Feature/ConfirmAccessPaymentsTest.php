<?php

use App\Enums\Role;
use App\Filament\Resources\PaymentConfirmationResource\Pages\ListPaymentConfirmations;
use App\Models\AccessGrant;
use App\Models\Order;
use App\Policies\OrderPolicy;
use App\Services\RequestApplicationAccess;
use App\Support\ApplicationRestriction;
use App\Support\GrantDuration;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/PaymentConfirmationFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false, 'access-payments.min_days' => 1, 'access-payments.max_days' => 366, 'access-payments.duration_days' => 7, 'access-payments.amount_vnd' => '123000', 'access-payments.bank_name' => 'T38 TEST BANK ONLY',
        'access-payments.account_number' => '000000000001', 'access-payments.account_holder' => 'T38 TEST HOLDER ONLY']);
    $this->travelTo(CarbonImmutable::parse('2026-11-28T16:30:00Z'));
});

it('denies the payment confirmation list to employers and staff with 403', function (Role $role) {
    $this->actingAs(jobManager($role))->get('/app/xac-nhan-thanh-toan')->assertForbidden();
})->with([Role::Employer, Role::Staff]);

it('confirms the exact order atomically with one grant and two safe audit events', function () {
    $f = t38Pending();
    $original = $f['order']->only(['amount', 'company_id', 'job_id', 'payment_instructions', 'duration_days']);
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertOk()->assertJsonPath('already_confirmed', false);
    $order = $f['order']->fresh();
    $grant = AccessGrant::firstOrFail();
    expect($order->only(array_keys($original)))->toEqual($original);
    expect($order->status)->toBe('paid');
    expect($order->confirmed_by)->toBe($f['admin']->id);
    expect($order->transaction_reference)->toBe('BANKREF001');
    expect($grant->order_id)->toBe($order->id);
    expect($grant->starts_at->toISOString())->toBe(CarbonImmutable::now('UTC')->toISOString());
    expect($order->confirmed_at->toISOString())->toBe($grant->starts_at->toISOString());
    expect($grant->ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-12-05 16:59:59');
    expect(DB::table('audit_logs')->pluck('action')->all())->toBe(['order.paid_confirmed', 'access_grant.created']);
    foreach (DB::table('audit_logs')->get() as $audit) {
        expect($audit->actor_id)->toBe($f['admin']->id);
        expect($audit->actor_role)->toBe('admin');
        expect(array_keys(json_decode($audit->metadata, true)))->toEqualCanonicalizing(['order_id', 'grant_id', 'company_id', 'job_id', 'starts_at', 'ends_at']);
    }
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain('PRIVATE_CONTACT', '0912345678', 'BANKREF001', 'T38 TEST HOLDER', 'internal_notes');
});

it('returns the original grant on a second confirmation even if new form values differ', function () {
    $f = t38Pending();
    $this->actingAs($f['admin']);
    $first = $this->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertOk();
    $this->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['ends_at' => '2030-01-01T00:00:00', 'received_amount' => 1]))
        ->assertOk()->assertJsonPath('already_confirmed', true)->assertJsonPath('grant_id', $first->json('grant_id'));
    expect(AccessGrant::count())->toBe(1);
    expect(DB::table('audit_logs')->count())->toBe(2);
});

it('rejects missing empty or whitespace only bank transaction references', function (mixed $reference) {
    $f = t38Pending();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['transaction_reference' => $reference]))->assertUnprocessable();
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
})->with([null, '', '   ', "\t\n"]);

it('rejects reused bank references including casing whitespace and Unicode spaces', function (string $reference) {
    $first = t38Pending();
    $this->actingAs($first['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$first['order']->id, t38Input())->assertOk();
    $second = t38Pending();
    $this->actingAs($second['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$second['order']->id, t38Input(['transaction_reference' => $reference]))
        ->assertConflict()->assertJsonPath('message', __('payments.rejections.duplicate_reference'));
    expect($second['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'order.confirm_rejected')->where('reason_code', 'duplicate_reference')->count())->toBe(1);
})->with(['BANKREF001', 'bankref001', 'b a n k r e f 0 0 1', "bank\tref\n001", "bank\u{00A0}ref\u{200B}001"]);

it('does not confuse the same transaction reference at a different receiving account', function () {
    $first = t38Pending();
    $this->actingAs($first['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$first['order']->id, t38Input())->assertOk();
    config(['access-payments.account_number' => '000000000002']);
    $second = t38Pending();
    $this->actingAs($second['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$second['order']->id, t38Input())->assertOk();
    expect(AccessGrant::count())->toBe(2);
});

it('rejects smaller and larger received amounts with one exact financial rejection audit and no changes', function (int $received) {
    $f = t38Pending();
    $response = $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['received_amount' => $received]))->assertConflict();
    expect($response->json('message'))->toContain('123.000', number_format($received, 0, ',', '.'), 'chức năng hủy');
    expect($f['order']->fresh()->status)->toBe('pending');
    expect($f['order']->fresh()->amount)->toBe(123000);
    expect(AccessGrant::count())->toBe(0);
    $rows = DB::table('audit_logs')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->action)->toBe('order.confirm_rejected');
    expect($rows[0]->reason_code)->toBe('amount_mismatch');
    expect(json_decode($rows[0]->metadata, true))->toEqual(['order_id' => $f['order']->id, 'order_amount' => 123000, 'received_amount' => $received]);
})->with([122999, 123001]);

it('rejects nonnumeric zero negative fractional and overflowing bank amounts', function (mixed $amount) {
    $f = t38Pending();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['received_amount' => $amount]))->assertUnprocessable();
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
})->with(['not a number', 0, -1, '123000.5', '999999999999999999999']);

it('confirms zero value gifts and trials without transaction references when a reason exists', function (string $method) {
    $f = applicationAccessFixture();
    $order = new Order;
    $order->forceFill(['company_id' => $f['job']->company_id, 'job_id' => $f['job']->id, 'type' => 'access_grant', 'amount' => 0,
        'method' => $method, 'reason' => 'Approved synthetic test gift', 'duration_days' => 3])->save();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$order->id, t38Input(['received_amount' => 0, 'transaction_reference' => null,
        'ends_at' => CarbonImmutable::now(GrantDuration::TIMEZONE)->addDays(3)->endOfDay()->format('Y-m-d\TH:i:s')]))->assertOk();
    expect($order->fresh()->transaction_reference)->toBeNull();
    expect($order->fresh()->payment_bank_key)->toBeNull();
    expect(AccessGrant::count())->toBe(1);
})->with(['gift', 'free_trial']);
it('rejects a zero value order without a reason through the existing model guard', function () {
    $f = applicationAccessFixture();
    $order = new Order;
    $order->forceFill(['company_id' => $f['job']->company_id, 'job_id' => $f['job']->id, 'type' => 'access_grant', 'amount' => 0, 'method' => 'gift']);
    expect(fn () => $order->save())->toThrow(ValidationException::class);
});

it('rolls back paid status grant and both audit events when an audit write fails', function () {
    $f = t38Pending();
    DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT t38_reject_created CHECK (action <> 'access_grant.created')");
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertStatus(503);
    expect($f['order']->fresh()->status)->toBe('pending');
    expect($f['order']->fresh()->confirmed_by)->toBeNull();
    expect($f['order']->fresh()->payment_bank_key)->toBeNull();
    expect(AccessGrant::count())->toBe(0);
    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('refuses overlapping active or scheduled grants and records the rejection without extending them', function (string $case) {
    $f = t38Pending();
    $existing = applicationAccessGrant($f, $case === 'scheduled' ? ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(4)] : []);
    $originalEnd = $existing->ends_at->toISOString();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertConflict();
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(1);
    expect($existing->fresh()->ends_at->toISOString())->toBe($originalEnd);
    expect(DB::table('audit_logs')->where('action', 'order.confirm_rejected')->where('reason_code', 'overlap')->count())->toBe(1);
})->with(['active', 'scheduled']);

it('does not repair paid orders without a grant or legacy orders without scope', function (string $case) {
    $f = t38Pending();
    if ($case === 'paid') {
        DB::table('orders')->where('id', $f['order']->id)->update(['status' => 'paid', 'confirmed_by' => $f['admin']->id, 'confirmed_at' => now()]);
    } else {
        DB::table('orders')->where('id', $f['order']->id)->update(['job_id' => null]);
    }
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertConflict();
    expect($f['order']->fresh()->status)->toBe($case === 'paid' ? 'paid' : 'pending');
    expect(AccessGrant::count())->toBe(0);
    expect(DB::table('audit_logs')->where('action', 'order.confirm_rejected')->count())->toBe(1);
})->with(['paid', 'missing_scope']);

it('rejects cancelled wrong type soft deleted and redrafted order scopes', function (string $case) {
    $f = t38Pending();
    if ($case === 'cancelled') {
        DB::table('orders')->where('id', $f['order']->id)->update(['status' => 'cancelled']);
    } elseif ($case === 'type') {
        DB::table('orders')->where('id', $f['order']->id)->update(['type' => 'job_boost']);
    } elseif ($case === 'draft') {
        DB::table('jobs')->where('id', $f['job']->id)->update(['status' => 'draft', 'published_at' => null]);
    } else {
        DB::table($case === 'company' ? 'companies' : 'jobs')->where('id', $case === 'company' ? $f['job']->company_id : $f['job']->id)->update(['deleted_at' => now()]);
    }
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertConflict();
    expect(AccessGrant::count())->toBe(0);
})->with(['cancelled', 'type', 'job', 'company', 'draft']);

it('uses the shared restriction seam before granting access when violation checks block the scope', function () {
    $f = t38Pending();
    app()->instance(ApplicationRestriction::class, new class
    {
        public function blocked(int $companyId, int $jobId): bool
        {
            return true;
        }
    });
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertConflict();
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
});

it('derives Vietnam end of day correctly at late night and across the date boundary', function (string $at, string $expected) {
    $this->travelTo(CarbonImmutable::parse($at));
    $f = t38Pending();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertOk();
    expect(AccessGrant::firstOrFail()->ends_at->utc()->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    ['2026-11-28T16:30:00Z', '2026-12-05 16:59:59'], ['2026-11-28T16:59:59Z', '2026-12-05 16:59:59'],
    ['2026-11-28T17:00:01Z', '2026-12-06 16:59:59'],
]);

it('leaves legacy ends empty and requires an explicit future end for orders without a duration', function () {
    $f = t38Pending(legacy: true);
    $this->actingAs($f['admin'])->get('/app/xac-nhan-thanh-toan/'.$f['order']->id)->assertOk()->assertSee('name="ends_at" value=""', false);
    $this->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['ends_at' => '']))->assertUnprocessable();
    $this->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertOk();
    expect($f['order']->fresh()->duration_days)->toBeNull();
});

it('rejects past too short too long and invalid calendar expiry inputs', function (string $end) {
    $f = t38Pending();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['ends_at' => $end]))->assertUnprocessable();
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
})->with(['2026-11-27T23:59:59', '2026-11-29T12:00:00', '2028-01-01T23:59:59', '2026-02-30T12:00:00']);

it('honors configurable expiry bounds without changing the order duration snapshot', function () {
    $f = t38Pending();
    config(['access-payments.min_days' => 2, 'access-payments.max_days' => 10, 'access-payments.duration_days' => 30]);
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['ends_at' => '2026-12-09T23:59:59']))->assertUnprocessable();
    expect($f['order']->fresh()->duration_days)->toBe(7);
});

it('records the default and selected ends only when an admin overrides a suggestion', function () {
    $f = t38Pending();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['ends_at' => '2026-12-06T23:59:59']))->assertOk();
    $row = DB::table('audit_logs')->where('action', 'order.expiry_overridden')->first();
    expect($row)->not->toBeNull();
    expect(json_decode($row->metadata, true))->toBe(['default_ends_at' => '2026-12-05T16:59:59+00:00', 'selected_ends_at' => '2026-12-06T16:59:59+00:00']);
    expect(DB::table('audit_logs')->count())->toBe(3);
});

it('keeps an untouched form correct across midnight and does not mislabel it as an admin override', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28T16:59:59Z'));
    $f = t38Pending();
    $default = '2026-12-05T23:59:59';
    $signature = app(GrantDuration::class)->signDefault($f['admin']->id, $f['order']->id, $default);
    $this->travel(2)->seconds();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['ends_at' => $default, 'default_ends_at' => $default, 'default_signature' => $signature]))->assertOk();
    expect(AccessGrant::firstOrFail()->ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-12-06 16:59:59');
    expect(DB::table('audit_logs')->where('action', 'order.expiry_overridden')->count())->toBe(0);
});

it('rejects a forged suggested end signature and manually supplied start time', function (string $case) {
    $f = t38Pending();
    $payload = $case === 'signature' ? ['default_ends_at' => '2026-12-05T23:59:59', 'default_signature' => 'FORGED'] : ['starts_at' => '2020-01-01T00:00:00'];
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input($payload))->assertUnprocessable();
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
})->with(['signature', 'start']);

it('snapshots configured days on new orders ignores forged duration fields and keeps the snapshot after configuration changes', function () {
    $f = applicationAccessFixture();
    $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$f['job']->id, ['duration_days' => 300, 'duration_max_days' => 999999])->assertRedirect();
    $order = Order::firstOrFail();
    expect($order->duration_days)->toBe(7);
    expect($order->duration_max_days)->toBe(366);
    config(['access-payments.duration_days' => 30]);
    $this->post('/app/mo-ho-so/'.$f['job']->id)->assertRedirect();
    expect(Order::count())->toBe(1);
    expect($order->fresh()->duration_days)->toBe(7);
    $this->actingAs($f['admin'])->get('/app/xac-nhan-thanh-toan/'.$order->id)->assertOk()->assertSee('value="2026-12-05T23:59:59"', false);
    $order->duration_days = 30;
    expect(fn () => $order->saveQuietly())->toThrow(ValidationException::class);
});

it('fails closed when the server duration configuration is missing zero or above its maximum', function (mixed $days) {
    config(['access-payments.duration_days' => $days]);
    $f = applicationAccessFixture();
    $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$f['job']->id)->assertStatus(503);
    expect(Order::count())->toBe(0);
})->with([null, 0, 367, true]);

it('enforces positive and snapshot bounded durations in the database', function (int $days) {
    $f = t38Pending();
    try {
        DB::transaction(fn () => DB::table('orders')->where('id', $f['order']->id)->update(['duration_days' => $days]));
        test()->fail('Database duration constraint must reject the value');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23514');
    }
    expect($f['order']->fresh()->duration_days)->toBe(7);
})->with([0, -1, 367]);

it('keeps raw SQL paid transaction uniqueness normalized at the database layer', function () {
    $first = t38Pending();
    $this->actingAs($first['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$first['order']->id, t38Input())->assertOk();
    $second = t38Pending();
    try {
        DB::transaction(fn () => DB::table('orders')->where('id', $second['order']->id)->update([
            'status' => 'paid', 'confirmed_by' => $second['admin']->id, 'confirmed_at' => now(),
            'payment_bank_key' => $first['order']->fresh()->payment_bank_key, 'transaction_reference' => ' bAnK rEf 001 ',
        ]));
        test()->fail('Database transaction uniqueness must reject duplicate identity');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23505');
    }
    expect($second['order']->fresh()->status)->toBe('pending');
});

it('rolls everything back if the end override audit cannot be written', function () {
    $f = t38Pending();
    DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT t38_reject_override CHECK (action <> 'order.expiry_overridden')");
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['ends_at' => '2026-12-06T23:59:59']))->assertStatus(503);
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('fails closed if writing the rejection audit fails without altering the pending order', function () {
    $f = t38Pending();
    DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT t38_reject_error_log CHECK (action <> 'order.confirm_rejected')");
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['received_amount' => 123001]))->assertStatus(503);
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('does not reactivate an already confirmed grant after it has been revoked', function () {
    $f = t38Pending();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertOk();
    DB::table('access_grants')->update(['status' => 'revoked']);
    $this->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertOk()->assertJsonPath('already_confirmed', true);
    expect(AccessGrant::firstOrFail()->status)->toBe('revoked');
    expect(DB::table('audit_logs')->count())->toBe(2);
});

it('never accepts a stale former admin actor or missing and overflowing order identifiers', function (string $case) {
    $f = t38Pending();
    $this->actingAs($f['admin']);
    if ($case === 'role') {
        DB::table('users')->where('id', $f['admin']->id)->update(['role' => 'staff']);
    }
    $id = match ($case) {
        'missing' => '999999999', 'overflow' => '999999999999999999999', default => (string) $f['order']->id,
    };
    $this->postJson('/app/xac-nhan-thanh-toan/'.$id, t38Input())->assertForbidden();
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
})->with(['role', 'missing', 'overflow']);

it('keeps duration columns empty for old orders through the new migration round trip', function () {
    $f = t38Pending(legacy: true);
    $migration = require database_path('migrations/2026_11_28_000023_add_payment_confirmation_integrity.php');
    $boostSchema = require database_path('migrations/2026_11_28_000025_create_plans_and_job_boosts.php');
    $boostSchema->down();
    $lifecycle = require database_path('migrations/2026_11_28_000024_add_grant_lifecycle_history.php');
    $lifecycle->down();
    $migration->down();
    expect(Schema::hasColumn('orders', 'duration_days'))->toBeFalse();
    $migration->up();
    $lifecycle->up();
    $boostSchema->up();
    expect($f['order']->fresh()->duration_days)->toBeNull();
    expect($f['order']->fresh()->duration_max_days)->toBeNull();
    expect($f['order']->fresh()->status)->toBe('pending');
});

it('refuses to roll back duration or bank confirmation proof already stored', function () {
    t38Pending();
    $migration = require database_path('migrations/2026_11_28_000023_add_payment_confirmation_integrity.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

it('accepts the exact minimum and last maximum calendar day but rejects the next second', function (string $kind) {
    $f = t38Pending();
    $end = match ($kind) {
        'min' => CarbonImmutable::now(GrantDuration::TIMEZONE)->addDay(),
        'max' => CarbonImmutable::now(GrantDuration::TIMEZONE)->addDays(366)->endOfDay(),
        default => CarbonImmutable::now(GrantDuration::TIMEZONE)->addDays(367)->startOfDay(),
    };
    $response = $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['ends_at' => $end->format('Y-m-d\TH:i:s')]));
    if ($kind === 'beyond') {
        $response->assertUnprocessable();
        expect(AccessGrant::count())->toBe(0);
    } else {
        $response->assertOk();
        expect(AccessGrant::firstOrFail()->ends_at->timestamp)->toBe($end->timestamp);
    }
})->with(['min', 'max', 'beyond']);

it('immediately unlocks older applications through the existing policy after successful payment', function () {
    $f = t38Pending();
    $this->actingAs($f['employer'])->get('/app/ho-so/'.$f['applicationId'])->assertForbidden();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertOk();
    $this->actingAs($f['employer'])->get('/app/ho-so/'.$f['applicationId'])->assertOk()->assertSee('0912345678')->assertSee('PRIVATE_CONTACT');
    expect(DB::table('audit_logs')->where('action', 'application.viewed_detail')->count())->toBe(1);
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain('0912345678', 'PRIVATE_CONTACT', 'PRIVATE_INTERNAL_NOTES');
});

it('keeps native form amount mismatch feedback clear without changing the order or granting access', function () {
    $f = t38Pending();
    $this->actingAs($f['admin'])->post('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['received_amount' => 122999]))->assertConflict()
        ->assertSee('123.000 VND')->assertSee('122.999 VND')->assertSee('chờ chức năng hủy')->assertDontSee('PRIVATE_CONTACT');
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
    expect(DB::table('audit_logs')->where('action', 'order.confirm_rejected')->count())->toBe(1);
});

it('ignores forged scope price bank and confirming admin fields', function () {
    $f = t38Pending();
    $foreign = applicationAccessFixture();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input([
        'company_id' => $foreign['job']->company_id, 'job_id' => $foreign['job']->id, 'amount' => 1,
        'confirmed_by' => $foreign['admin']->id, 'payment_instructions' => ['account' => 'FORGED'],
    ]))->assertOk();
    $grant = AccessGrant::firstOrFail();
    expect($grant->company_id)->toBe($f['job']->company_id);
    expect($grant->job_id)->toBe($f['job']->id);
    expect($grant->created_by)->toBe($f['admin']->id);
    expect($f['order']->fresh()->confirmed_by)->toBe($f['admin']->id);
    expect($f['order']->fresh()->amount)->toBe(123000);
    expect($f['order']->fresh()->payment_instructions['account'])->toBe('000000000001');
});

it('accepts a native datetime local value whose zero seconds component was omitted by the browser', function () {
    $f = t38Pending();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['ends_at' => '2026-12-05T23:59']))->assertOk();
    expect(AccessGrant::firstOrFail()->ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-12-05 16:59:00');
});

it('includes known historical paid bank snapshots in uniqueness without altering original financial fields or filling days', function () {
    $f = t38Pending(legacy: true);
    $originalReference = " b a n k\u{00A0}ref\u{200B}001 ";
    DB::table('orders')->where('id', $f['order']->id)->update(['status' => 'paid', 'transaction_reference' => $originalReference,
        'confirmed_by' => $f['admin']->id, 'confirmed_at' => now()]);
    $grant = new AccessGrant;
    $grant->forceFill(['order_id' => $f['order']->id, 'company_id' => $f['job']->company_id, 'job_id' => $f['job']->id,
        'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_by' => $f['admin']->id])->save();
    $migration = require database_path('migrations/2026_11_28_000023_add_payment_confirmation_integrity.php');
    $migration->down();
    $migration->up();
    $saved = $f['order']->fresh();
    expect($saved->duration_days)->toBeNull();
    expect($saved->transaction_reference)->toBe($originalReference);
    expect($saved->amount)->toBe(123000);
    expect($saved->status)->toBe('paid');
    expect($saved->payment_bank_key)->not->toBeNull();
    $second = t38Pending();
    $this->actingAs($second['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$second['order']->id, t38Input())->assertConflict()
        ->assertJsonPath('message', __('payments.rejections.duplicate_reference'));
    expect($second['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(1);
});

it('shows confirmed start end and bank reference in Vietnam time without candidate data', function () {
    $f = t38Pending();
    $this->actingAs($f['admin'])->post('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input())->assertRedirect();
    $this->get('/app/xac-nhan-thanh-toan/'.$f['order']->id)->assertOk()->assertSee('28/11/2026 23:30:00')
        ->assertSee('05/12/2026 23:59:59')->assertSee('BANKREF001')->assertDontSee('PRIVATE_CONTACT')->assertDontSee('0912345678');
});

it('rejects a JSON boolean instead of treating it as a one VND received amount', function () {
    config(['access-payments.amount_vnd' => '1']);
    $f = t38Pending();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input(['received_amount' => true]))->assertUnprocessable();
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
});

it('refreshes an untouched expiry suggestion after an amount error across midnight without logging a false override', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28T16:59:59Z'));
    $f = t38Pending();
    $oldDefault = '2026-12-05T23:59:59';
    $signature = app(GrantDuration::class)->signDefault($f['admin']->id, $f['order']->id, $oldDefault);
    $this->travel(2)->seconds();
    $response = $this->actingAs($f['admin'])->post('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input([
        'received_amount' => 122999, 'ends_at' => $oldDefault, 'default_ends_at' => $oldDefault, 'default_signature' => $signature,
    ]))->assertConflict();
    expect($response->viewData('values')['ends_at'])->toBe('2026-12-06T23:59:59');
    $this->postJson('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input([
        'ends_at' => $response->viewData('values')['ends_at'], 'default_ends_at' => $response->viewData('default'), 'default_signature' => $response->viewData('signature'),
    ]))->assertOk();
    expect(AccessGrant::firstOrFail()->ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-12-06 16:59:59');
    expect(DB::table('audit_logs')->where('action', 'order.expiry_overridden')->count())->toBe(0);
});

it('renders a safe validation error for nonscalar native form fields', function (string $field) {
    $f = t38Pending();
    $this->actingAs($f['admin'])->post('/app/xac-nhan-thanh-toan/'.$f['order']->id, t38Input([$field => ['forged']]))->assertUnprocessable();
    expect($f['order']->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
})->with(['ends_at', 'received_amount', 'transaction_reference']);

it('renders only order scope fields in the admin confirmation list and Livewire payload', function () {
    $fixture = applicationAccessFixture();
    $order = app(RequestApplicationAccess::class)->request($fixture['employer'], (string) $fixture['job']->id);
    $this->actingAs($fixture['admin'])->get('/app/xac-nhan-thanh-toan')->assertOk()->assertSee('123.000 VND')
        ->assertDontSee('PRIVATE_CONTACT')->assertDontSee('0912345678')->assertDontSee('PRIVATE_CV');
    $component = Livewire::test(ListPaymentConfirmations::class)->assertCanSeeTableRecords([$order]);
    $component->call('$refresh');
    $payload = json_encode([$component->snapshot, $component->effects], JSON_THROW_ON_ERROR);
    expect($payload)->not->toContain('PRIVATE_CONTACT', '0912345678', 'PRIVATE_CV', 'PRIVATE_PREVIEW', 'private-portfolio', 'cv_file_id', 'portfolio_url');
    foreach ($component->instance()->getTableRecords() as $record) {
        expect(array_keys($record->getAttributes()))->toEqualCanonicalizing(['id', 'company_id', 'job_id', 'amount', 'method', 'status', 'payment_reference', 'created_at']);
    }
    expect($order->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
});

it('rechecks the admin role and active state for the confirmation list', function (string $case) {
    $actor = jobManager(Role::Admin);
    DB::table('users')->where('id', $actor->id)->update($case === 'role' ? ['role' => 'staff'] : ['status' => 'locked']);
    expect(app(OrderPolicy::class)->viewAny($actor))->toBeFalse();
})->with(['role', 'locked']);

it('denies direct payment confirmation to employers and staff before accepting any form data', function (Role $role) {
    $fixture = applicationAccessFixture();
    $order = app(RequestApplicationAccess::class)->request($fixture['employer'], (string) $fixture['job']->id);
    $this->actingAs($role === Role::Employer ? $fixture['employer'] : jobManager(Role::Staff))
        ->post('/app/xac-nhan-thanh-toan/'.$order->id)->assertForbidden();
    expect($order->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
})->with([Role::Employer, Role::Staff]);
