<?php

use App\Enums\Role;
use App\Filament\Resources\AccessGrantResource\Pages\ListAccessGrants;
use App\Models\AccessGrant;
use App\Models\Order;
use App\Services\AccessGrantLifecycle;
use App\Services\AccessPaymentSettings;
use App\Services\ConfirmAccessOrder;
use App\Services\RecordAudit;
use App\Support\GrantDuration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ApplicationPolicyFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false, 'access-payments.min_days' => 1, 'access-payments.max_days' => 366,
        'access-payments.duration_days' => 7, 'access-payments.amount_vnd' => '123000', 'access-payments.bank_name' => 'T39 TEST BANK',
        'access-payments.account_number' => '000000000001', 'access-payments.account_holder' => 'T39 TEST HOLDER']);
    $this->travelTo(CarbonImmutable::parse('2026-11-28T16:30:00Z'));
});

function t39Renew(array $f, AccessGrant $grant, array $input = []): Order
{
    return app(AccessGrantLifecycle::class)->renew($f['admin'], (string) $grant->id, array_replace(['method' => 'bank_transfer'], $input));
}

function t39Confirm(array $f, Order $order): AccessGrant
{
    $start = app(AccessGrantLifecycle::class)->start($order, CarbonImmutable::now('UTC'));
    $end = app(GrantDuration::class)->suggested($order, $start) ?? $start->addDays(7);

    return app(ConfirmAccessOrder::class)->confirm($f['admin'], (string) $order->id,
        ['received_amount' => $order->amount, 'transaction_reference' => 'T39-'.Str::ulid(),
            'ends_at' => $end->setTimezone(GrantDuration::TIMEZONE)->format('Y-m-d\TH:i:s')], Request::create('/'))['grant'];
}

function t39RevokeInput(array $f, AccessGrant $grant, array $extra = []): array
{
    $lifecycle = app(AccessGrantLifecycle::class);

    return array_replace(['reason' => 'company_request', 'preview_signature' => $lifecycle->previewSignature($f['admin'], $grant, $lifecycle->affected($grant))], $extra);
}

it('denies the grant management list to employers and staff', function (Role $role) {
    $this->actingAs(jobManager($role))->get('/app/quyen-xem-ho-so')->assertForbidden();
})->with([Role::Employer, Role::Staff]);

it('denies direct revoke and renewal requests from nonadmins before processing inputs', function (Role $role, string $action) {
    $fixture = applicationAccessFixture();
    $grant = applicationAccessGrant($fixture);
    $before = $grant->fresh()->getAttributes();
    $this->actingAs($role === Role::Employer ? $fixture['employer'] : jobManager(Role::Staff))
        ->post('/app/quyen-xem-ho-so/'.$grant->id.'/'.$action)->assertForbidden();
    expect($grant->fresh()->getAttributes())->toBe($before);
    expect(DB::table('audit_logs')->count())->toBe(0);
})->with([Role::Employer, Role::Staff])->with(['thu-hoi', 'gia-han']);

it('denies the lifecycle preview to nonadmins and rejects a stale demoted admin on submit', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $this->actingAs($f['employer'])->get('/app/quyen-xem-ho-so/'.$grant->id)->assertForbidden();
    $input = t39RevokeInput($f, $grant);
    DB::table('users')->where('id', $f['admin']->id)->update(['role' => 'staff']);
    $this->actingAs($f['admin'])->postJson('/app/quyen-xem-ho-so/'.$grant->id.'/thu-hoi', $input)->assertForbidden();
    expect($grant->fresh()->status)->toBe('active');
});

it('shows only grant metadata and the affected intervals before revocation', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $this->actingAs($f['admin'])->get('/app/quyen-xem-ho-so')->assertOk();
    $response = $this->get('/app/quyen-xem-ho-so/'.$grant->id)->assertOk()->assertSee(__('grants.confirm_revoke'))->assertSee('preview_signature');
    $response->assertDontSee('PRIVATE_CONTACT')->assertDontSee('0912345678')->assertDontSee('PRIVATE_CV')->assertDontSee('CANDIDATE_VISIBLE_NAME');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    Livewire::test(ListAccessGrants::class)->assertCanSeeTableRecords([$grant]);
});

it('renews an active grant with a new paid order and an exactly adjacent half open interval', function () {
    $f = applicationAccessFixture();
    $prior = applicationAccessGrant($f);
    $oldGrant = $prior->fresh()->getAttributes();
    $oldOrder = $prior->order->getAttributes();
    $order = t39Renew($f, $prior);
    config(['access-payments.duration_days' => 99, 'access-payments.amount_vnd' => '999999']);
    expect($order->duration_days)->toBe(7);
    expect($order->amount)->toBe(123000);
    $this->actingAs($f['admin'])->get('/app/xac-nhan-thanh-toan/'.$order->id)->assertOk()
        ->assertSee(app(GrantDuration::class)->suggested($order, $prior->ends_at)->setTimezone(GrantDuration::TIMEZONE)->format('Y-m-d\TH:i:s'));
    $new = t39Confirm($f, $order);
    expect($new->previous_grant_id)->toBe($prior->id);
    expect($new->starts_at->equalTo($prior->ends_at))->toBeTrue();
    expect($prior->fresh()->getAttributes())->toBe($oldGrant);
    expect($prior->order->fresh()->getAttributes())->toBe($oldOrder);
    $this->travelTo($prior->ends_at->subSecond());
    expect($prior->isEffective())->toBeTrue();
    expect($new->isEffective())->toBeFalse();
    $this->travelTo($prior->ends_at);
    expect($prior->isEffective())->toBeFalse();
    expect($new->isEffective())->toBeTrue();
    $this->travelTo($prior->ends_at->addSecond());
    expect($new->isEffective())->toBeTrue();
    $audit = DB::table('audit_logs')->where('action', 'access_grant.created')->first();
    expect(json_decode($audit->metadata, true)['previous_grant_id'])->toBe($prior->id);
});

it('starts renewal of an expired grant at confirmation time without backdating', function () {
    $f = applicationAccessFixture();
    $prior = applicationAccessGrant($f, ['starts_at' => now()->subDays(4), 'ends_at' => now()->subDay()]);
    $new = t39Confirm($f, t39Renew($f, $prior));
    expect($new->starts_at->equalTo(CarbonImmutable::now('UTC')))->toBeTrue();
    expect($new->previous_grant_id)->toBe($prior->id);
});

it('ignores a revoked last grant and creates two consecutive renewal links', function () {
    $f = applicationAccessFixture();
    $prior = applicationAccessGrant($f);
    $revoked = applicationAccessGrant($f, ['status' => 'revoked', 'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(30)]);
    $next = t39Confirm($f, t39Renew($f, $revoked));
    $last = t39Confirm($f, t39Renew($f, $prior));
    expect($next->previous_grant_id)->toBe($prior->id);
    expect($last->previous_grant_id)->toBe($next->id);
    expect($next->starts_at->equalTo($prior->ends_at))->toBeTrue();
    expect($last->starts_at->equalTo($next->ends_at))->toBeTrue();
    expect($revoked->fresh()->status)->toBe('revoked');
});

it('refuses renewal when every grant was revoked including after creating a pending order', function (bool $afterOrder) {
    $f = applicationAccessFixture();
    $prior = applicationAccessGrant($f);
    $order = $afterOrder ? t39Renew($f, $prior) : null;
    app(AccessGrantLifecycle::class)->revoke($f['admin'], (string) $prior->id, t39RevokeInput($f, $prior), Request::create('/'));
    if ($afterOrder) {
        $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$order->id, ['received_amount' => 123000, 'transaction_reference' => 'REF', 'ends_at' => '2026-12-10T23:59:59'])->assertConflict();
        expect($order->fresh()->status)->toBe('pending');
    } else {
        $this->actingAs($f['admin'])->post('/app/quyen-xem-ho-so/'.$prior->id.'/gia-han', ['method' => 'bank_transfer'])->assertConflict();
        expect(Order::count())->toBe(1);
    }
})->with([false, true]);

it('reuses one pending renewal order without changing its snapshot', function () {
    $f = applicationAccessFixture();
    $prior = applicationAccessGrant($f);
    $one = t39Renew($f, $prior);
    config(['access-payments.amount_vnd' => 999999]);
    $two = t39Renew($f, $prior);
    expect($two->id)->toBe($one->id);
    expect($two->amount)->toBe(123000);
    expect(Order::where('status', 'pending')->count())->toBe(1);
    expect(fn () => $one->forceFill(['is_renewal' => false])->saveQuietly())->toThrow(ValidationException::class);
});

it('requires a reason for zero amount renewal and supports gift and trial without a bank reference', function (string $method) {
    $f = applicationAccessFixture();
    $prior = applicationAccessGrant($f);
    $this->actingAs($f['admin'])->postJson('/app/quyen-xem-ho-so/'.$prior->id.'/gia-han', ['method' => $method])->assertUnprocessable();
    expect(Order::where('status', 'pending')->count())->toBe(0);
    $order = t39Renew($f, $prior, ['method' => $method, 'reason' => 'Demo approved', 'duration_days' => 3]);
    expect($order->amount)->toBe(0);
    expect($order->duration_days)->toBe(3);
    $new = t39Confirm($f, $order);
    expect($order->fresh()->status)->toBe('paid');
    expect($new->previous_grant_id)->toBe($prior->id);
})->with(['gift', 'free_trial']);

it('revokes only current and scheduled grants for one job while keeping every paid order unchanged', function () {
    $f = applicationAccessFixture();
    $active = applicationAccessGrant($f);
    $future = t39Confirm($f, t39Renew($f, $active));
    $expired = applicationAccessGrant($f, ['starts_at' => now()->subDays(5), 'ends_at' => now()->subDays(2)]);
    $otherJob = publishManagedJob(managedJob($f['admin']));
    DB::table('jobs')->where('id', $otherJob->id)->update(['company_id' => $f['job']->company_id]);
    $unrelated = applicationAccessGrant(array_replace($f, ['job' => $otherJob->fresh()]));
    $orders = Order::orderBy('id')->get()->toArray();
    $input = t39RevokeInput($f, $active);
    $this->actingAs($f['admin'])->postJson('/app/quyen-xem-ho-so/'.$active->id.'/thu-hoi', $input)->assertOk()->assertJsonPath('revoked', 2);
    expect($active->fresh()->status)->toBe('revoked');
    expect($future->fresh()->status)->toBe('revoked');
    expect($expired->fresh()->status)->toBe('active');
    expect($unrelated->fresh()->status)->toBe('active');
    expect(Order::orderBy('id')->get()->toArray())->toBe($orders);
    $audits = DB::table('audit_logs')->where('action', 'access_grant.revoked')->get();
    expect($audits)->toHaveCount(2);
    foreach ($audits as $audit) {
        expect($audit->actor_id)->toBe($f['admin']->id);
        expect(array_keys(json_decode($audit->metadata, true)))->toEqualCanonicalizing(['grant_id', 'job_id', 'order_id', 'reason']);
    }
    expect(json_encode($audits))->not->toContain('0912345678', 'PRIVATE_CONTACT', 'PRIVATE_CV', 'PRIVATE_INTERNAL');
    // Replaying an old preview never adds duplicate revocation events.
    $this->postJson('/app/quyen-xem-ho-so/'.$active->id.'/thu-hoi', $input)->assertConflict();
    expect(DB::table('audit_logs')->where('action', 'access_grant.revoked')->count())->toBe(2);
    expect(fn () => $active->fresh()->forceFill(['status' => 'active'])->saveQuietly())->toThrow(ValidationException::class);
});

it('rejects missing or unapproved revoke reasons and never logs arbitrary contact text', function (mixed $reason) {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $this->actingAs($f['admin'])->postJson('/app/quyen-xem-ho-so/'.$grant->id.'/thu-hoi', t39RevokeInput($f, $grant, ['reason' => $reason]))->assertUnprocessable();
    expect($grant->fresh()->status)->toBe('active');
    expect(DB::table('audit_logs')->count())->toBe(0);
})->with([null, '', 'PRIVATE_CONTACT@example.test']);

it('refuses an unseen newly scheduled grant or a preview from another administrator', function (bool $anotherAdmin) {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $input = t39RevokeInput($f, $grant);
    if ($anotherAdmin) {
        $actor = jobManager(Role::Admin);
    } else {
        t39Confirm($f, t39Renew($f, $grant));
        $actor = $f['admin'];
    }
    $this->actingAs($actor)->postJson('/app/quyen-xem-ho-so/'.$grant->id.'/thu-hoi', $input)->assertConflict();
    expect(AccessGrant::where('status', 'revoked')->count())->toBe(0);
})->with([false, true]);

it('rolls back all revocations when the audit writer fails', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $future = t39Confirm($f, t39Renew($f, $grant));
    $writer = app(RecordAudit::class);
    $calls = 0;
    $this->mock(RecordAudit::class)->shouldReceive('grantRevoked')->twice()->andReturnUsing(function (...$arguments) use ($writer, &$calls): void {
        if (++$calls === 2) {
            throw new RuntimeException('sensitive detail');
        }
        $writer->grantRevoked(...$arguments);
    });
    $this->actingAs($f['admin'])->postJson('/app/quyen-xem-ho-so/'.$grant->id.'/thu-hoi', t39RevokeInput($f, $grant))->assertStatus(503)->assertDontSee('sensitive detail');
    expect($grant->fresh()->status)->toBe('active');
    expect($future->fresh()->status)->toBe('active');
    expect(DB::table('audit_logs')->where('action', 'access_grant.revoked')->count())->toBe(0);
});

it('denies the very next detail inline and download request in the same employer session after real admin revocation', function () {
    Storage::fake('candidate-files');
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $bytes = file_get_contents(__DIR__.'/../Fixtures/pdf/generated/clean.pdf');
    $path = 'cv/'.$f['job']->company_id.'/'.$f['job']->id.'/'.$f['applicationId'].'/'.Str::uuid().'.pdf';
    Storage::disk('candidate-files')->put($path, $bytes);
    DB::table('files')->where('id', $f['fileId'])->update(['path' => $path, 'preview_path' => $path, 'sha256' => hash('sha256', $bytes), 'scanned_sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)]);
    $urls = [];
    foreach (['inline', 'download'] as $mode) {
        $urls[] = URL::temporarySignedRoute('applications.file', now()->addMinutes(5), ['id' => $f['applicationId'], 'file' => $f['fileId'], 'mode' => $mode, 'actor' => $f['employer']->id]);
    }
    $this->actingAs($f['employer'])->get('/app/ho-so/'.$f['applicationId'])->assertOk();
    foreach ($urls as $url) {
        $this->get($url)->assertOk();
    }
    // Separate admin operation: do not replace or log out the employer session.
    app(AccessGrantLifecycle::class)->revoke($f['admin'], (string) $grant->id, t39RevokeInput($f, $grant), Request::create('/'));
    $this->get('/app/ho-so/'.$f['applicationId'])->assertForbidden()->assertDontSee('PRIVATE_CONTACT');
    foreach ($urls as $url) {
        $this->get($url)->assertForbidden()->assertDontSee('%PDF');
    }
});

it('keeps a zero amount renewal without duration empty until the admin selects an expiry', function () {
    $f = applicationAccessFixture();
    $prior = applicationAccessGrant($f);
    $order = t39Renew($f, $prior, ['method' => 'gift', 'reason' => 'Approved demo']);
    expect($order->duration_days)->toBeNull();
    $this->actingAs($f['admin'])->get('/app/xac-nhan-thanh-toan/'.$order->id)->assertOk();
    $this->postJson('/app/xac-nhan-thanh-toan/'.$order->id, ['received_amount' => 0])->assertUnprocessable();
    expect($order->fresh()->status)->toBe('pending');
    expect(AccessGrant::count())->toBe(1);
});

it('ignores client supplied duration price and bank on a bank renewal', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $order = t39Renew($f, $grant, ['duration_days' => ['forged'], 'amount' => 1, 'payment_instructions' => ['bank' => 'forged']]);
    expect($order->duration_days)->toBe(7);
    expect($order->amount)->toBe(123000);
    expect($order->payment_instructions['bank'])->toBe('T39 TEST BANK');
});

it('blocks changing an existing interval and a cross company or backward history link', function (string $case) {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    if ($case === 'period') {
        expect(fn () => $grant->forceFill(['ends_at' => $grant->ends_at->addDay()])->saveQuietly())->toThrow(ValidationException::class);
    } else {
        $other = applicationAccessFixture();
        $prior = $case === 'company' ? applicationAccessGrant($other) : $grant;
        $order = new Order;
        $order->forceFill(['company_id' => $f['job']->company_id, 'job_id' => $f['job']->id, 'is_renewal' => true, 'type' => 'access_grant', 'amount' => 0,
            'method' => 'gift', 'reason' => 'Demo', 'status' => 'paid', 'confirmed_by' => $f['admin']->id, 'confirmed_at' => now()])->save();
        $new = new AccessGrant;
        $new->forceFill(['company_id' => $f['job']->company_id, 'job_id' => $f['job']->id, 'order_id' => $order->id,
            'created_by' => $f['admin']->id, 'previous_grant_id' => $prior->id, 'starts_at' => now(), 'ends_at' => now()->addDays(10)]);
        expect(fn () => $new->save())->toThrow(ValidationException::class);
    }
})->with(['period', 'company', 'backward']);

it('rejects a genuinely overlapping new purchase while preserving the existing right', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $settings = app(AccessPaymentSettings::class)->snapshot();
    $order = new Order;
    $order->forceFill(['company_id' => $f['job']->company_id, 'job_id' => $f['job']->id, 'type' => 'access_grant', 'amount' => $settings['amount'],
        'duration_days' => $settings['duration_days'], 'payment_instructions' => $settings['instructions']])->save();
    $this->actingAs($f['admin'])->postJson('/app/xac-nhan-thanh-toan/'.$order->id,
        ['received_amount' => 123000, 'transaction_reference' => 'REAL-OVERLAP', 'ends_at' => '2026-12-05T23:59:59'])->assertConflict();
    expect($order->fresh()->status)->toBe('pending');
    expect($grant->isEffective())->toBeTrue();
    expect(AccessGrant::count())->toBe(1);
});

it('preserves old rows through the lifecycle migration round trip and refuses losing renewal history', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $migration = require database_path('migrations/2026_11_28_000024_add_grant_lifecycle_history.php');
    $migration->down();
    $migration->up();
    expect($grant->fresh()->previous_grant_id)->toBeNull();
    expect($grant->order->fresh()->is_renewal)->toBeFalse();
    t39Renew($f, $grant);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});
