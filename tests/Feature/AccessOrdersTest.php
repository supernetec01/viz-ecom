<?php

use App\Enums\Role;
use App\Filament\Resources\AccessRequestResource\Pages\ListAccessRequests;
use App\Models\AccessGrant;
use App\Models\Order;
use App\Services\RequestApplicationAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ApplicationPolicyFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false, 'access-payments.min_days' => 1, 'access-payments.max_days' => 366, 'access-payments.duration_days' => 7, 'access-payments.amount_vnd' => '123000', 'access-payments.bank_name' => 'TEST BANK ONLY',
        'access-payments.account_number' => '000000000001', 'access-payments.account_holder' => 'TEST HOLDER ONLY']);
});

it('denies access order creation to staff and admin with 403', function (Role $role) {
    $f = applicationAccessFixture();
    $this->actingAs(jobManager($role))->post('/app/mo-ho-so/'.$f['job']->id)->assertForbidden();
    $this->get('/app/mo-ho-so')->assertForbidden();
    expect(DB::table('orders')->count())->toBe(0);
})->with([Role::Staff, Role::Admin]);

it('returns the same 403 for missing foreign or unpublished jobs without creating orders', function (string $case) {
    $f = applicationAccessFixture();
    $id = $f['job']->id;
    if ($case === 'foreign') {
        $id = applicationAccessFixture()['job']->id;
    } elseif ($case === 'missing') {
        $id = 999999999;
    } else {
        DB::table('jobs')->where('id', $id)->update(['status' => $case]);
    }
    $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$id)->assertForbidden()->assertDontSee('PRIVATE_CONTACT');
    expect(DB::table('orders')->count())->toBe(0);
})->with(['foreign', 'missing', 'draft', 'pending']);

it('requires authentication to create an access order', function () {
    $this->post('/app/mo-ho-so/1')->assertRedirect('/app/login');
    expect(DB::table('orders')->count())->toBe(0);
});

it('denies order creation for deleted jobs companies and removed memberships', function (string $case) {
    $f = applicationAccessFixture();
    $this->actingAs($f['employer']);
    if ($case === 'membership') {
        $f['job']->company->employers()->detach($f['employer']);
    } else {
        DB::table($case === 'company' ? 'companies' : 'jobs')->where('id', $case === 'company' ? $f['job']->company_id : $f['job']->id)->update(['deleted_at' => now()]);
    }
    $this->post('/app/mo-ho-so/'.$f['job']->id)->assertForbidden();
    expect(DB::table('orders')->count())->toBe(0);
})->with(['job', 'company', 'membership']);

it('rechecks the actor role and status inside the order service', function (string $case) {
    $f = applicationAccessFixture();
    DB::table('users')->where('id', $f['employer']->id)->update($case === 'locked' ? ['status' => 'locked'] : ['role' => 'staff']);
    try {
        app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
        test()->fail('Stale actor must be denied');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    expect(DB::table('orders')->count())->toBe(0);
})->with(['locked', 'role']);

it('creates only a pending per job order for every approved state even with no applications', function (string $status) {
    $f = applicationAccessFixture();
    DB::table('applications')->where('id', $f['applicationId'])->delete();
    DB::table('jobs')->where('id', $f['job']->id)->update(['status' => $status]);
    $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$f['job']->id)->assertRedirect();
    $order = Order::firstOrFail();
    expect($order->only(['company_id', 'job_id', 'type', 'status', 'method', 'amount', 'confirmed_by', 'confirmed_at']))->toBe([
        'company_id' => $f['job']->company_id, 'job_id' => $f['job']->id, 'type' => 'access_grant', 'status' => 'pending',
        'method' => 'bank_transfer', 'amount' => 123000, 'confirmed_by' => null, 'confirmed_at' => null,
    ]);
    expect($order->payment_reference)->toMatch('/^VE[0-9A-HJKMNP-TV-Z]{26}$/');
    expect($order->payment_instructions['account'])->toBe('000000000001');
    expect(DB::table('access_grants')->count())->toBe(0);
    $this->get('/app/don-mo-ho-so/'.$order->id)->assertOk()->assertSee('TEST BANK ONLY')->assertSee('123.000 VND')
        ->assertSee($order->payment_reference)->assertSee($f['job']->title)->assertSee('Managed company')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertDontSee('PRIVATE_CONTACT')->assertDontSee('0912345678')->assertDontSee('PRIVATE_CV')->assertDontSee('private-portfolio');
})->with(['published', 'paused', 'expired', 'closed']);

it('ignores forged company amount status reference and grant fields from the browser', function () {
    $f = applicationAccessFixture();
    $foreign = applicationAccessFixture();
    $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$f['job']->id, ['company_id' => $foreign['job']->company_id,
        'amount' => 0, 'status' => 'paid', 'type' => 'job_boost', 'method' => 'gift', 'payment_reference' => 'FORGED',
        'payment_instructions' => ['account' => 'FORGED'], 'ends_at' => now()->addYears(10)->toIso8601String()])->assertRedirect();
    $order = Order::firstOrFail();
    expect($order->company_id)->toBe($f['job']->company_id);
    expect($order->amount)->toBe(123000);
    expect($order->status)->toBe('pending');
    expect($order->type)->toBe('access_grant');
    expect($order->payment_reference)->not->toBe('FORGED');
    expect($order->payment_instructions['account'])->toBe('000000000001');
    expect(DB::table('access_grants')->count())->toBe(0);
});

it('reuses one pending order across retries and different accounts of the same company without repricing', function () {
    $f = applicationAccessFixture();
    $other = jobManager(Role::Employer);
    $f['job']->company->employers()->attach($other);
    $first = $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$f['job']->id)->assertRedirect();
    $order = Order::firstOrFail();
    config(['access-payments.amount_vnd' => '999000', 'access-payments.bank_name' => 'CHANGED BANK']);
    $this->actingAs($other)->post('/app/mo-ho-so/'.$f['job']->id)->assertRedirect($first->headers->get('Location'));
    config(['access-payments.amount_vnd' => null, 'access-payments.bank_name' => null]);
    $this->post('/app/mo-ho-so/'.$f['job']->id)->assertRedirect($first->headers->get('Location'));
    $this->get('/app/don-mo-ho-so/'.$order->id)->assertOk()->assertSee('TEST BANK ONLY')->assertDontSee('CHANGED BANK');
    expect(Order::count())->toBe(1);
    expect($order->fresh()->amount)->toBe(123000);
});

it('does not create an additional order while a grant is currently effective', function () {
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$f['job']->id)->assertRedirect('/app/mo-ho-so');
    expect(Order::count())->toBe(1);
    expect(Order::where('status', 'pending')->count())->toBe(0);
    $this->get('/app/mo-ho-so')->assertOk()->assertSee(__('orders.already_unlocked'));
});

it('allows a new pending order when a grant is expired revoked or not started yet', function (string $case) {
    $f = applicationAccessFixture();
    applicationAccessGrant($f, match ($case) {
        'revoked' => ['status' => 'revoked'],
        'expired' => ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()],
        default => ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)],
    });
    $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$f['job']->id)->assertRedirect();
    expect(Order::where('status', 'pending')->count())->toBe(1);
    expect(AccessGrant::count())->toBe(1);
})->with(['expired', 'revoked', 'future']);

it('fails closed without complete valid price and bank configuration', function (string $key, mixed $value) {
    config(['access-payments.'.$key => $value]);
    $f = applicationAccessFixture();
    $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$f['job']->id)->assertStatus(503)->assertSee(__('orders.unconfigured'));
    expect(Order::count())->toBe(0);
})->with([
    ['amount_vnd', null], ['amount_vnd', 0], ['amount_vnd', -1], ['amount_vnd', true], ['amount_vnd', '10.5'],
    ['amount_vnd', '9999999999999999999999999'], ['bank_name', null], ['bank_name', '   '],
    ['account_number', null], ['account_holder', null], ['bank_name', "BANK\nINJECTED"],
]);

it('denies receipt reads to foreign companies admin staff and missing orders identically', function (string $case) {
    $f = applicationAccessFixture();
    $order = app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
    $actor = match ($case) {
        'admin' => $f['admin'], 'staff' => jobManager(Role::Staff),
        'foreign' => applicationAccessFixture()['employer'], default => $f['employer'],
    };
    $this->actingAs($actor)->get('/app/don-mo-ho-so/'.($case === 'missing' ? 99999999 : $order->id))->assertForbidden()
        ->assertDontSee('TEST BANK ONLY')->assertDontSee('000000000001');
})->with(['foreign', 'admin', 'staff', 'missing']);

it('scopes the purchase list and Livewire payload without loading candidate fields', function () {
    $f = applicationAccessFixture();
    $foreign = applicationAccessFixture();
    $this->actingAs($f['employer'])->get('/app/mo-ho-so')->assertOk()->assertSee(__('orders.request_access'))
        ->assertSee('action="'.route('access-orders.store', ['job' => $f['job']->id]).'"', false)
        ->assertDontSee('PRIVATE_CONTACT')->assertDontSee('0912345678')->assertDontSee('PRIVATE_CV');
    $component = Livewire::test(ListAccessRequests::class)->assertCanSeeTableRecords([$f['job']->id])->assertCanNotSeeTableRecords([$foreign['job']->id]);
    $component->call('$refresh');
    $json = json_encode([$component->snapshot, $component->effects], JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('PRIVATE_CONTACT', '0912345678', 'PRIVATE_CV', 'private-portfolio', 'cv_file_id', 'portfolio_url', 'phone_normalized');
    foreach ($component->instance()->getTableRecords() as $row) {
        expect(array_keys($row->getAttributes()))->toEqualCanonicalizing(['id', 'company_id', 'title', 'status', 'company_name', 'access_active']);
    }
    expect(Order::count())->toBe(0);
});

it('blocks creating a new order when an old unscoped pending order needs reconciliation', function () {
    $f = applicationAccessFixture();
    $legacy = new Order;
    $legacy->forceFill(['company_id' => $f['job']->company_id, 'type' => 'access_grant', 'amount' => 50000])->save();
    $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$f['job']->id)->assertStatus(503)->assertSee(__('orders.legacy_pending'));
    expect(Order::count())->toBe(1);
});

it('enforces a unique pending scope at the database and allows a replacement after cancellation', function () {
    $f = applicationAccessFixture();
    $order = app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
    $row = $order->getAttributes();
    unset($row['id'], $row['payment_reference']);
    try {
        DB::transaction(fn () => DB::table('orders')->insert($row));
        test()->fail('Duplicate pending scope must fail');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23505');
    }
    $order->status = 'cancelled';
    $order->save();
    $replacement = app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
    expect($replacement->id)->not->toBe($order->id);
    expect(Order::where('status', 'pending')->count())->toBe(1);
});

it('makes a competing pending insertion wait on the unique company job scope', function () {
    $f = applicationAccessFixture();
    $order = app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
    $row = $order->getAttributes();
    unset($row['id'], $row['payment_reference']);
    config(['database.connections.order_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('order_probe');
    try {
        $probe->statement("SET lock_timeout = '100ms'");
        try {
            $probe->table('orders')->insert($row);
            test()->fail('Competing insertion must wait');
        } catch (QueryException $exception) {
            expect($exception->getCode())->toBe('55P03');
        }
    } finally {
        DB::purge('order_probe');
    }
    expect(Order::count())->toBe(1);
});

it('prevents changing the saved job price bank or reference through ordinary and quiet model writes', function (string $field, mixed $value) {
    $f = applicationAccessFixture();
    $order = app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
    $order->$field = $value;
    expect(fn () => $order->saveQuietly())->toThrow(ValidationException::class);
})->with([
    ['job_id', null], ['amount', 1], ['payment_reference', 'FORGED'], ['payment_instructions', ['bank' => 'FORGED']],
]);

it('enforces the order job company binding in PostgreSQL', function () {
    $f = applicationAccessFixture();
    $foreign = applicationAccessFixture();
    DB::table('orders')->insert(['company_id' => $foreign['job']->company_id, 'job_id' => $f['job']->id, 'type' => 'access_grant',
        'amount' => 1, 'status' => 'pending', 'method' => 'bank_transfer']);
})->throws(QueryException::class);

it('backfills only known old grant scopes without losing legacy records', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $lifecycle = require database_path('migrations/2026_11_28_000024_add_grant_lifecycle_history.php');
    $lifecycle->down();
    $confirmation = require database_path('migrations/2026_11_28_000023_add_payment_confirmation_integrity.php');
    $confirmation->down();
    $migration = require database_path('migrations/2026_11_27_000022_scope_access_orders_to_jobs.php');
    $migration->down();
    expect(Schema::hasColumn('orders', 'job_id'))->toBeFalse();
    $migration->up();
    $confirmation->up();
    $lifecycle->up();
    expect(Order::findOrFail($grant->order_id)->job_id)->toBe($f['job']->id);
    expect($grant->fresh()->isEffective())->toBeTrue();
    expect(DB::table('applications')->where('id', $f['applicationId'])->exists())->toBeTrue();
});

it('refuses a destructive rollback while saved payment instructions are present', function () {
    $f = applicationAccessFixture();
    app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
    $migration = require database_path('migrations/2026_11_27_000022_scope_access_orders_to_jobs.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(Schema::hasColumn('orders', 'payment_instructions'))->toBeTrue();
    expect(Order::count())->toBe(1);
});

it('keeps different job scopes separate within the same company', function () {
    $f = applicationAccessFixture();
    $second = publishManagedJob(managedJob($f['admin']));
    DB::table('jobs')->where('id', $second->id)->update(['company_id' => $f['job']->company_id]);
    $firstOrder = app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
    $secondOrder = app(RequestApplicationAccess::class)->request($f['employer'], (string) $second->id);
    expect($firstOrder->id)->not->toBe($secondOrder->id);
    expect(Order::where('status', 'pending')->count())->toBe(2);
});

it('does not show receipt instructions after membership or company or job eligibility changes', function (string $case) {
    $f = applicationAccessFixture();
    $order = app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
    $this->actingAs($f['employer']);
    if ($case === 'membership') {
        $f['job']->company->employers()->detach($f['employer']);
    } elseif ($case === 'draft') {
        DB::table('jobs')->where('id', $f['job']->id)->update(['status' => 'draft', 'published_at' => null]);
    } else {
        DB::table($case === 'company' ? 'companies' : 'jobs')->where('id', $case === 'company' ? $f['job']->company_id : $f['job']->id)->update(['deleted_at' => now()]);
    }
    $this->get('/app/don-mo-ho-so/'.$order->id)->assertForbidden()->assertDontSee('TEST BANK ONLY');
})->with(['membership', 'company', 'job', 'draft']);

it('escapes configured bank text on the receipt instead of executing HTML', function () {
    config(['access-payments.bank_name' => '<b>TEST BANK</b>']);
    $f = applicationAccessFixture();
    $order = app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
    $this->actingAs($f['employer'])->get('/app/don-mo-ho-so/'.$order->id)->assertOk()->assertSee('&lt;b&gt;TEST BANK&lt;/b&gt;', false)->assertDontSee('<b>TEST BANK</b>', false);
});

it('does not allow a scoped paid order to grant a different job in the same company', function () {
    $f = applicationAccessFixture();
    $otherJob = publishManagedJob(managedJob($f['admin']));
    DB::table('jobs')->where('id', $otherJob->id)->update(['company_id' => $f['job']->company_id]);
    $order = app(RequestApplicationAccess::class)->request($f['employer'], (string) $f['job']->id);
    $order->forceFill(['status' => 'paid', 'transaction_reference' => 'T37-SCOPE-TEST', 'confirmed_by' => $f['admin']->id, 'confirmed_at' => now()])->save();
    $grant = new AccessGrant;
    $grant->forceFill(['company_id' => $f['job']->company_id, 'job_id' => $otherJob->id, 'order_id' => $order->id,
        'created_by' => $f['admin']->id, 'starts_at' => now(), 'ends_at' => now()->addDay()]);
    expect(fn () => $grant->save())->toThrow(ValidationException::class);
    expect(AccessGrant::count())->toBe(0);
});

it('fails the effective grant check if a scoped order was tampered to another job in the database', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $otherJob = publishManagedJob(managedJob($f['admin']));
    DB::table('jobs')->where('id', $otherJob->id)->update(['company_id' => $f['job']->company_id]);
    DB::table('orders')->where('id', $grant->order_id)->update(['job_id' => $otherJob->id]);
    expect($grant->isEffective())->toBeFalse();
});

it('shows the purchase header link on the employer summary without exposing applicant contacts', function () {
    $f = applicationAccessFixture();
    $this->actingAs($f['employer'])->get('/app/ho-so')->assertOk()->assertSee('/app/mo-ho-so', false)
        ->assertDontSee('PRIVATE_CONTACT')->assertDontSee('0912345678')->assertDontSee('PRIVATE_CV');
});

it('rechecks a grant acquired after the projected job result instead of trusting that stale result', function () {
    $f = applicationAccessFixture();
    $inserted = false;
    DB::listen(function ($query) use ($f, &$inserted) {
        if (! $inserted && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"jobs"') && str_contains($query->sql, 'for update')) {
            $inserted = true;
            applicationAccessGrant($f);
        }
    });
    $this->actingAs($f['employer'])->post('/app/mo-ho-so/'.$f['job']->id)->assertRedirect('/app/mo-ho-so');
    expect($inserted)->toBeTrue();
    expect(Order::where('status', 'pending')->count())->toBe(0);
});

it('rejects integer overflow IDs with 403 before querying PostgreSQL', function (string $uri, string $method) {
    $f = applicationAccessFixture();
    $this->actingAs($f['employer'])->$method($uri)->assertForbidden();
    expect(Order::count())->toBe(0);
})->with([
    ['/app/mo-ho-so/9999999999999999999999999', 'post'],
    ['/app/don-mo-ho-so/9999999999999999999999999', 'get'],
]);
