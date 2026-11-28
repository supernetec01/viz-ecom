<?php

use App\Enums\Role;
use App\Filament\Resources\BoostOrderResource\Pages\ListRecordsPage;
use App\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Models\AccessGrant;
use App\Models\JobBoost;
use App\Models\Order;
use App\Models\Plan;
use App\Services\ManageJobs;
use App\Support\ApplicationRestriction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/BoostFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false, 'access-payments.bank_name' => 'T40 TEST BANK', 'access-payments.account_number' => '000000000001',
        'access-payments.account_holder' => 'T40 TEST HOLDER', 'access-payments.amount_vnd' => null, 'access-payments.duration_days' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-11-28T16:30:00Z'));
});

it('denies boost management and plan catalog pages to employers', function (string $path) {
    $this->actingAs(jobManager(Role::Employer))->get($path)->assertForbidden();
})->with(['/app/goi-day-tin', '/app/day-tin']);

it('denies plan creation to staff', function () {
    $this->actingAs(jobManager(Role::Staff))->get('/app/goi-day-tin/create')->assertForbidden();
});

it('denies creating an order for another company job before inspecting the selected plan', function (Role $role) {
    $job = publishManagedJob(managedJob(jobManager(Role::Admin)));
    $this->actingAs(jobManager($role))->post('/app/day-tin/'.$job->id, ['plan_id' => 999999])->assertForbidden();
    expect(Order::count())->toBe(0);
})->with([Role::Employer, Role::Staff]);

it('lets an admin create and stop selling plans while staff can only view', function () {
    $admin = jobManager(Role::Admin);
    $this->actingAs($admin);
    Livewire::test(CreatePlan::class)
        ->fillForm(['name' => 'Gói 7 ngày', 'duration_days' => 7, 'priority' => 20, 'price' => 500000, 'is_active' => true])->call('create')->assertHasNoFormErrors();
    $plan = Plan::sole();
    Livewire::test(EditPlan::class, ['record' => $plan->id])
        ->fillForm(['is_active' => false])->call('save')->assertHasNoFormErrors();
    expect($plan->fresh()->is_active)->toBeFalse();
    $this->actingAs(jobManager(Role::Staff))->get('/app/goi-day-tin')->assertOk();
    $this->get('/app/goi-day-tin/'.$plan->id.'/edit')->assertForbidden();
});

it('creates a scoped pending order with a server snapshot and ignores forged financial fields', function (Role $role) {
    $f = boostFixture();
    $actor = $role === Role::Admin ? $f['admin'] : $f['staff'];
    $this->actingAs($actor)->get('/app/day-tin/'.$f['job']->id)->assertOk()->assertSee('225.000');
    $this->post('/app/day-tin/'.$f['job']->id, ['plan_id' => $f['plan']->id, 'method' => 'bank_transfer', 'amount' => 1,
        'company_id' => 999999, 'boost_days' => 999, 'boost_priority' => 999, 'status' => 'paid'])->assertRedirect();
    $order = Order::sole();
    expect($order->company_id)->toBe($f['job']->company_id);
    expect($order->amount)->toBe(225000);
    expect($order->boost_days)->toBe(3);
    expect($order->boost_priority)->toBe(10);
    expect($order->status)->toBe('pending');
    expect($order->requested_by)->toBe($actor->id);
    expect(JobBoost::count())->toBe(0);
    $this->get('/app/don-day-tin/'.$order->id)->assertOk()->assertSee('T40 TEST BANK');
})->with([Role::Admin, Role::Staff]);

it('confirms once with exact 24 hour duration and two safe audit events in one transaction', function () {
    $f = boostFixture();
    $order = boostPending($f);
    $this->actingAs($f['admin'])->postJson('/app/don-day-tin/'.$order->id.'/xac-nhan', boostPayment(['transaction_reference' => ' b o o s t 001 ']))->assertOk()->assertJsonPath('already_confirmed', false);
    $boost = JobBoost::sole();
    expect($boost->starts_at->equalTo(CarbonImmutable::now('UTC')))->toBeTrue();
    expect($boost->ends_at->diffInSeconds($boost->starts_at, true))->toEqual(3 * 86400);
    expect($order->fresh()->transaction_reference)->toBe('BOOST001');
    expect(DB::table('audit_logs')->pluck('action')->all())->toBe(['order.paid_confirmed', 'job_boost.created']);
    $this->postJson('/app/don-day-tin/'.$order->id.'/xac-nhan', [])->assertOk()->assertJsonPath('already_confirmed', true)->assertJsonPath('boost_id', $boost->id);
    expect(JobBoost::count())->toBe(1);
    expect(DB::table('audit_logs')->count())->toBe(2);
    $this->get('/app/don-day-tin/'.$order->id)->assertOk()->assertSee('01/12/2026 23:30:00');
});

it('keeps old order and boost snapshots when the plan changes or stops selling', function () {
    $f = boostFixture();
    $order = boostPending($f);
    $f['plan']->update(['name' => 'Changed', 'duration_days' => 7, 'price' => 500000, 'priority' => 50, 'is_active' => false]);
    $boost = boostConfirm($f, $order)['boost'];
    expect($boost->priority)->toBe(10);
    expect($order->fresh()->boost_days)->toBe(3);
    expect($order->fresh()->amount)->toBe(225000);
    expect($order->fresh()->boost_plan_name)->toBe('Gói thử 3 ngày');
    expect(fn () => $order->fresh()->forceFill(['boost_priority' => 99])->saveQuietly())->toThrow(ValidationException::class);
    expect(fn () => $boost->forceFill(['ends_at' => now()->addDays(6)])->saveQuietly())->toThrow(ValidationException::class);
    expect(fn () => $order->fresh()->forceFill(['status' => 'cancelled'])->saveQuietly())->toThrow(ValidationException::class);
});

it('reuses one pending order per job without replacing its plan snapshot', function () {
    $f = boostFixture();
    $one = boostPending($f);
    $two = boostPending($f, ['plan_id' => 999999]);
    expect($one->id)->toBe($two->id);
    expect(Order::where('type', 'job_boost')->where('status', 'pending')->count())->toBe(1);
});

it('refuses a disabled plan for a new order and missing bank configuration without partial data', function (string $case) {
    $f = boostFixture();
    if ($case === 'disabled') {
        $f['plan']->update(['is_active' => false]);
    } else {
        config(['access-payments.bank_name' => null]);
    }
    $this->actingAs($f['staff'])->post('/app/day-tin/'.$f['job']->id, ['plan_id' => $f['plan']->id, 'method' => 'bank_transfer'])->assertStatus($case === 'disabled' ? 409 : 503);
    expect(Order::count())->toBe(0);
})->with(['disabled', 'bank']);

it('denies foreign order reads and rechecks staff assignment at submit and list query time', function () {
    $f = boostFixture();
    $order = boostPending($f);
    $this->actingAs(jobManager(Role::Staff))->get('/app/don-day-tin/'.$order->id)->assertForbidden();
    $this->actingAs($f['staff']);
    $f['job']->company->staff()->detach($f['staff']);
    $this->post('/app/day-tin/'.$f['job']->id, ['plan_id' => $f['plan']->id, 'method' => 'bank_transfer'])->assertForbidden();
    Livewire::test(ListRecordsPage::class)->assertCanNotSeeTableRecords([$order]);
});

it('rejects nonreceiving job states at order creation', function (string $state) {
    $f = boostFixture();
    DB::table('jobs')->where('id', $f['job']->id)->update(['status' => $state]);
    $this->actingAs($f['staff'])->post('/app/day-tin/'.$f['job']->id, ['plan_id' => $f['plan']->id, 'method' => 'bank_transfer'])->assertForbidden();
    expect(Order::count())->toBe(0);
})->with(['draft', 'pending', 'expired', 'closed']);

it('rejects insufficient remaining job time both at request and delayed confirmation without cutting days', function (bool $atConfirmation) {
    $f = boostFixture();
    $order = $atConfirmation ? boostPending($f) : null;
    DB::table('jobs')->where('id', $f['job']->id)->update(['expires_at' => now()->addDays(3)->subSecond()]);
    $this->actingAs($atConfirmation ? $f['admin'] : $f['staff']);
    $response = $atConfirmation ? $this->postJson('/app/don-day-tin/'.$order->id.'/xac-nhan', boostPayment())
        : $this->post('/app/day-tin/'.$f['job']->id, ['plan_id' => $f['plan']->id, 'method' => 'bank_transfer']);
    $response->assertConflict();
    expect(JobBoost::count())->toBe(0);
    if ($order) {
        expect($order->fresh()->status)->toBe('pending');
    } else {
        expect(Order::count())->toBe(0);
    }
})->with([false, true]);

it('accepts a boost ending exactly at job expiry and uses half open boundaries without a scheduler', function () {
    $f = boostFixture();
    DB::table('jobs')->where('id', $f['job']->id)->update(['expires_at' => now()->addDays(3)]);
    $boost = boostConfirm($f, boostPending($f))['boost'];
    expect(JobBoost::effective()->whereKey($boost->id)->exists())->toBeTrue();
    $this->travelTo($boost->starts_at->subSecond());
    expect(JobBoost::effective()->whereKey($boost->id)->exists())->toBeFalse();
    $this->travelTo($boost->ends_at->subSecond());
    expect(JobBoost::effective()->whereKey($boost->id)->exists())->toBeTrue();
    $this->travelTo($boost->ends_at);
    expect(JobBoost::effective()->whereKey($boost->id)->exists())->toBeFalse();
});

it('allows overlapping boosts and pausing resuming and duplicating never extend or copy them', function () {
    $f = boostFixture();
    $one = boostConfirm($f, boostPending($f))['boost'];
    $two = boostConfirm($f, boostPending($f))['boost'];
    expect(JobBoost::effective()->count())->toBe(2);
    $before = JobBoost::orderBy('id')->get()->toArray();
    app(ManageJobs::class)->changeState($f['staff'], $f['job'], 'pause');
    $this->travel(1)->hours();
    app(ManageJobs::class)->changeState($f['staff'], $f['job']->fresh(), 'resume');
    expect(JobBoost::orderBy('id')->get()->toArray())->toBe($before);
    $copy = app(ManageJobs::class)->duplicate($f['staff'], $f['job']->fresh());
    expect(JobBoost::where('job_id', $copy->id)->count())->toBe(0);
    expect($one->id)->not->toBe($two->id);
});

it('allows assigning a boost to a paused job with the clock running', function () {
    $f = boostFixture();
    app(ManageJobs::class)->changeState($f['staff'], $f['job'], 'pause');
    $boost = boostConfirm($f, boostPending($f))['boost'];
    expect($boost->ends_at->equalTo(CarbonImmutable::now('UTC')->addDays(3)))->toBeTrue();
});

it('requires a reason for gift and trial and confirms zero amounts without a bank reference', function (string $method) {
    $f = boostFixture();
    $this->actingAs($f['staff'])->postJson('/app/day-tin/'.$f['job']->id, ['plan_id' => $f['plan']->id, 'method' => $method])->assertUnprocessable();
    $order = boostPending($f, ['method' => $method, 'reason' => 'Được duyệt thử nghiệm']);
    expect($order->amount)->toBe(0);
    boostConfirm($f, $order);
    expect($order->fresh()->status)->toBe('paid');
})->with(['gift', 'free_trial']);

it('keeps pending on mismatched invalid or zero received amounts', function (mixed $amount) {
    $f = boostFixture();
    $order = boostPending($f);
    $this->actingAs($f['admin'])->postJson('/app/don-day-tin/'.$order->id.'/xac-nhan', boostPayment(['received_amount' => $amount]))
        ->assertStatus(is_int($amount) && $amount > 0 ? 409 : 422);
    expect($order->fresh()->status)->toBe('pending');
    expect(JobBoost::count())->toBe(0);
})->with([224999, 225001, 0, -1, true, 'not a number']);

it('rejects missing bank references and arbitrary starts or ends', function (array $input) {
    $f = boostFixture();
    $order = boostPending($f);
    $this->actingAs($f['admin'])->postJson('/app/don-day-tin/'.$order->id.'/xac-nhan', boostPayment($input))->assertUnprocessable();
    expect($order->fresh()->status)->toBe('pending');
})->with([[['transaction_reference' => ' ']], [['starts_at' => '2020-01-01']], [['ends_at' => '2030-01-01']]]);

it('rejects a normalized duplicate transaction reference across boost orders', function () {
    $f = boostFixture();
    $one = boostPending($f);
    $this->actingAs($f['admin'])->postJson('/app/don-day-tin/'.$one->id.'/xac-nhan', boostPayment(['transaction_reference' => 'BOOST REF']))->assertOk();
    $two = boostPending($f);
    $this->postJson('/app/don-day-tin/'.$two->id.'/xac-nhan', boostPayment(['transaction_reference' => " b o o s t\u{00A0}r e f "]))->assertConflict();
    expect($two->fresh()->status)->toBe('pending');
    expect(JobBoost::count())->toBe(1);
});

it('rolls back paid boost and the first audit if the second audit fails', function () {
    $f = boostFixture();
    $order = boostPending($f);
    DB::unprepared("CREATE FUNCTION pg_temp.t40_reject_second_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action = 'job_boost.created' THEN RAISE EXCEPTION 'secret detail'; END IF; RETURN NEW; END $$");
    DB::unprepared('CREATE TRIGGER t40_reject_second_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION pg_temp.t40_reject_second_audit()');
    try {
        $this->actingAs($f['admin'])->postJson('/app/don-day-tin/'.$order->id.'/xac-nhan', boostPayment())->assertStatus(503)->assertDontSee('secret detail');
    } finally {
        DB::unprepared('DROP TRIGGER t40_reject_second_audit ON audit_logs');
        DB::unprepared('DROP FUNCTION pg_temp.t40_reject_second_audit()');
    }
    expect($order->fresh()->status)->toBe('pending');
    expect(JobBoost::count())->toBe(0);
    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('checks restriction and deletion again at payment time', function (string $case) {
    $f = boostFixture();
    $order = boostPending($f);
    match ($case) {
        'company' => $f['job']->company->delete(), 'job' => $f['job']->delete(),
        default => app()->instance(ApplicationRestriction::class, new class
        {
            public function blocked(int $company, int $job): bool
            {
                return true;
            }
        }),
    };
    $this->actingAs($f['admin'])->postJson('/app/don-day-tin/'.$order->id.'/xac-nhan', boostPayment())->assertConflict();
    expect(JobBoost::count())->toBe(0);
})->with(['company', 'job', 'restricted']);

it('rejects invalid plan values in both model validation and database checks', function (string $field, mixed $value) {
    $f = boostFixture();
    expect(fn () => $f['plan']->fill([$field => $value])->save())->toThrow(ValidationException::class);
    if (! is_bool($value)) {
        expect(fn () => DB::transaction(fn () => DB::table('plans')->where('id', $f['plan']->id)->update([$field => $value])))
            ->toThrow(QueryException::class);
    }
})->with([['duration_days', 0], ['duration_days', 367], ['priority', 0], ['priority', 1000001], ['price', 0], ['price', -1], ['price', true]]);

it('enforces boost dates scope one order per boost and used plan retention in PostgreSQL', function (string $case) {
    $f = boostFixture();
    $order = boostPending($f);
    $boost = boostConfirm($f, $order)['boost'];
    expect(fn () => DB::transaction(function () use ($boost, $f, $case): void {
        if ($case === 'dates') {
            DB::table('job_boosts')->where('id', $boost->id)->update(['ends_at' => $boost->starts_at]);
        } elseif ($case === 'scope') {
            $other = boostFixture();
            DB::table('job_boosts')->where('id', $boost->id)->update(['company_id' => $other['job']->company_id]);
        } elseif ($case === 'duplicate') {
            $row = $boost->fresh()->getAttributes();
            unset($row['id']);
            DB::table('job_boosts')->insert($row);
        } else {
            DB::table('plans')->where('id', $f['plan']->id)->delete();
        }
    }))->toThrow(QueryException::class);
    expect($order->fresh()->status)->toBe('paid');
    expect(JobBoost::count())->toBe(1);
})->with(['dates', 'scope', 'duplicate', 'used_plan']);

it('does not allow paying an access order with the same normalized bank transaction used for a boost', function () {
    $f = boostFixture();
    $order = boostPending($f);
    $this->actingAs($f['admin'])->postJson('/app/don-day-tin/'.$order->id.'/xac-nhan', boostPayment(['transaction_reference' => 'SAME REF']))->assertOk();
    $access = new Order;
    $access->forceFill(['company_id' => $f['job']->company_id, 'job_id' => $f['job']->id, 'type' => 'access_grant',
        'amount' => 225000, 'payment_instructions' => $order->payment_instructions])->save();
    $this->postJson('/app/xac-nhan-thanh-toan/'.$access->id, ['received_amount' => 225000, 'transaction_reference' => ' s a m e ref ', 'ends_at' => now('Asia/Ho_Chi_Minh')->addDays(3)->format('Y-m-d\TH:i:s')])->assertConflict();
    expect($access->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(0);
});

it('refuses paid orders missing a boost without silently creating one', function () {
    $f = boostFixture();
    $order = boostPending($f);
    DB::table('orders')->where('id', $order->id)->update(['status' => 'paid', 'confirmed_by' => $f['admin']->id, 'confirmed_at' => now()]);
    $this->actingAs($f['admin'])->postJson('/app/don-day-tin/'.$order->id.'/xac-nhan', boostPayment())->assertConflict();
    expect(JobBoost::count())->toBe(0);
});

it('rechecks admin privileges at confirmation and hides confirmation controls from staff', function () {
    $f = boostFixture();
    $order = boostPending($f);
    $this->actingAs($f['staff'])->get('/app/don-day-tin/'.$order->id)->assertOk()->assertDontSee('name="received_amount"', false);
    DB::table('users')->where('id', $f['admin']->id)->update(['role' => 'staff']);
    $this->actingAs($f['admin'])->postJson('/app/don-day-tin/'.$order->id.'/xac-nhan', boostPayment())->assertForbidden();
    expect($order->fresh()->status)->toBe('pending');
});

it('scopes all three Filament lists to the assigned company and exposes no candidate fields', function () {
    $f = boostFixture();
    $order = boostPending($f);
    $boost = boostConfirm($f, $order)['boost'];
    $other = boostFixture();
    $foreignOrder = boostPending($other);
    $foreignBoost = boostConfirm($other, $foreignOrder)['boost'];
    $this->actingAs($f['staff']);
    foreach ([
        [App\Filament\Resources\BoostJobResource\Pages\ListRecordsPage::class, $f['job'], $other['job']],
        [ListRecordsPage::class, $order, $foreignOrder],
        [App\Filament\Resources\JobBoostResource\Pages\ListRecordsPage::class, $boost, $foreignBoost],
    ] as [$page, $own, $foreign]) {
        $component = Livewire::test($page)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign]);
        expect(json_encode([$component->snapshot, $component->effects]))->not->toContain('phone_normalized', 'portfolio_url', 'cv_file_id', 'original_name');
    }
});

it('does not change public ordering before T41 and keeps financial pages no store and noindex', function () {
    $f = boostFixture();
    $old = $f['job'];
    $this->travel(1)->minutes();
    $newer = publishManagedJob(managedJob($f['staff']));
    boostConfirm($f, boostPending($f));
    $html = $this->get('/viec-lam')->assertOk()->getContent();
    // Both fixture titles are identical, so compare their public links.
    expect(strpos($html, '-'.$newer->id.'"'))->toBeLessThan(strpos($html, '-'.$old->id.'"'));
    $response = $this->actingAs($f['admin'])->get('/app/don-day-tin/'.Order::sole()->id)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex');
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain('T40 TEST HOLDER', 'T40 TEST BANK', 'received_amount');
});

it('preserves old jobs through the new migration round trip and refuses dropping plan history', function () {
    $staff = jobManager(Role::Staff);
    $job = publishManagedJob(managedJob($staff));
    $migration = require database_path('migrations/2026_11_28_000025_create_plans_and_job_boosts.php');
    $migration->down();
    $migration->up();
    expect($job->fresh()->company_id)->toBe($job->company_id);
    Plan::create(['name' => 'Gói 3 ngày', 'duration_days' => 3, 'priority' => 1, 'price' => 100000]);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

it('denies payment confirmation to staff and employers before reading a foreign order', function (Role $role) {
    $this->actingAs(jobManager($role))->post('/app/don-day-tin/999999/xac-nhan', [])->assertForbidden();
})->with([Role::Employer, Role::Staff]);
