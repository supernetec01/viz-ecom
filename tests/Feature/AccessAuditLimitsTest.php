<?php

use App\Enums\Role;
use App\Models\User;
use App\Policies\AccessAlertPolicy;
use App\Services\AccessLimits;
use App\Services\RecordAudit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ApplicationPolicyFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false]);
});

it('denies the security alert list to employers and staff', function (Role $role) {
    $this->actingAs(jobManager($role))->get('/app/canh-bao-truy-cap')->assertForbidden();
})->with([Role::Employer, Role::Staff]);

it('returns 429 without candidate data after the detail budget is exhausted', function () {
    config(['app.debug' => false, 'access-limits.details' => 2]);
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $this->actingAs($f['employer']);
    $url = '/app/ho-so/'.$f['applicationId'];
    $this->get($url)->assertOk();
    $this->get($url)->assertOk();
    $this->getJson($url)->assertStatus(429)->assertHeader('Retry-After')->assertDontSee('0912345678')->assertDontSee('PRIVATE_CONTACT');
});

it('shares the file budget across inline and download for every role and writes one audit for each access or block', function (Role $role) {
    config(['access-limits.file' => 2]);
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $actor = match ($role) {
        Role::Admin => $f['admin'], Role::Employer => $f['employer'], default => jobManager(Role::Staff)
    };
    if ($role === Role::Staff) {
        $f['job']->company->staff()->attach($actor);
    }
    $path = 'cv/'.$f['job']->company_id.'/'.$f['job']->id.'/'.$f['applicationId'].'/'.Str::uuid().'.pdf';
    Storage::fake('candidate-files');
    $bytes = file_get_contents(__DIR__.'/../Fixtures/pdf/generated/clean.pdf');
    Storage::disk('candidate-files')->put($path, $bytes);
    DB::table('files')->where('id', $f['fileId'])->update(['path' => $path, 'preview_path' => $path, 'sha256' => hash('sha256', $bytes), 'scanned_sha256' => hash('sha256', $bytes)]);
    $this->actingAs($actor);
    foreach (['inline', 'download', 'download'] as $index => $mode) {
        $url = URL::temporarySignedRoute('applications.file', now()->addMinutes(5), ['id' => $f['applicationId'], 'file' => $f['fileId'], 'mode' => $mode, 'actor' => $actor->id]);
        $response = $this->get($url);
        if ($index < 2) {
            $response->assertOk();
            expect($response->streamedContent())->toBe($bytes);
        } else {
            $response->assertStatus(429)->assertHeader('Retry-After')->assertDontSee('%PDF');
        }
    }
    expect(DB::table('audit_logs')->count())->toBe(3);
    expect(DB::table('audit_logs')->where('action', 'application.access_blocked')->count())->toBe(1);
    expect(DB::table('access_alerts')->count())->toBe(1);
})->with([Role::Admin, Role::Staff, Role::Employer]);

it('uses a sixty second window with an exact boundary and isolated account budgets', function () {
    $this->freezeTime();
    config(['access-limits.details' => 1]);
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $url = '/app/ho-so/'.$f['applicationId'];
    $this->actingAs($f['employer'])->get($url)->assertOk();
    $this->get($url)->assertStatus(429)->assertHeader('Retry-After', '60');
    $this->travel(59)->seconds();
    $this->get($url)->assertStatus(429)->assertHeader('Retry-After', '1');
    $this->actingAs($f['admin'])->get($url)->assertOk();
    $this->actingAs($f['employer']);
    $this->travel(1)->seconds();
    $this->get($url)->assertOk();
});

it('deduplicates admin alerts across buckets for fifteen minutes and excludes locked or nonadmin recipients', function () {
    $this->freezeTime();
    config(['access-limits.details' => 1, 'access-limits.file' => 1]);
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $locked = jobManager(Role::Admin);
    $locked->forceFill(['status' => 'locked'])->save();
    $staff = jobManager(Role::Staff);
    $limits = app(AccessLimits::class);
    foreach (['details', 'file'] as $bucket) {
        $limits->consume($f['employer'], $bucket, $f['applicationId'], $f['job']->company_id, request());
        try {
            $limits->consume($f['employer'], $bucket, $f['applicationId'], $f['job']->company_id, request());
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(429);
        }
    }
    expect(DB::table('access_alerts')->count())->toBe(1);
    expect(DB::table('notifications')->where('notifiable_id', $f['admin']->id)->count())->toBe(1);
    expect(DB::table('notifications')->whereIn('notifiable_id', [$locked->id, $staff->id, $f['employer']->id])->count())->toBe(0);
    $payload = json_encode(DB::table('notifications')->pluck('data')->all());
    expect($payload)->not->toContain('0912345678', 'PRIVATE_CONTACT', 'CANDIDATE_VISIBLE_NAME', $f['applicationId']);
    $this->travel(15)->minutes();
    $limits->consume($f['employer'], 'details', $f['applicationId'], $f['job']->company_id, request());
    try {
        $limits->consume($f['employer'], 'details', $f['applicationId'], $f['job']->company_id, request());
    } catch (HttpException) {
    }
    expect(DB::table('access_alerts')->count())->toBe(2);
});

it('does not consume budgets for requests rejected by policy', function () {
    $f = applicationAccessFixture();
    $this->actingAs($f['employer'])->get('/app/ho-so/'.$f['applicationId'])->assertForbidden();
    expect(DB::table('access_limit_states')->count())->toBe(0);
});

it('fails closed when rate limit persistence fails rather than allowing a file or detail', function () {
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $this->mock(AccessLimits::class)->shouldReceive('consume')->andThrow(new RuntimeException('PRIVATE_STATE_FAILURE'));
    $this->actingAs($f['employer'])->get('/app/ho-so/'.$f['applicationId'])->assertStatus(503)->assertDontSee('0912345678')->assertDontSee('PRIVATE_STATE_FAILURE');
});

it('rolls back alerts if blocked audit writing fails and still returns no candidate content', function () {
    config(['access-limits.details' => 1]);
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $this->actingAs($f['employer'])->get('/app/ho-so/'.$f['applicationId'])->assertOk();
    $this->mock(RecordAudit::class)->shouldReceive('blocked')->andThrow(new RuntimeException('PRIVATE_AUDIT_FAILURE'));
    $this->get('/app/ho-so/'.$f['applicationId'])->assertStatus(503)->assertDontSee('0912345678');
    expect(DB::table('access_alerts')->count())->toBe(0);
});

it('shows only safe alert metadata to current admins and denies a stale admin role', function () {
    $admin = jobManager(Role::Admin);
    DB::table('access_alerts')->insert(['actor_id' => $admin->id, 'actor_role' => 'admin', 'company_id' => 123, 'bucket' => 'file', 'limit' => 10, 'created_at' => now()]);
    $this->actingAs($admin)->get('/app/canh-bao-truy-cap')->assertOk()->assertSee(__('security.file'))->assertDontSee('phone_normalized');
    DB::table('users')->where('id', $admin->id)->update(['role' => 'employer']);
    expect(app(AccessAlertPolicy::class)->viewAny($admin))->toBeFalse();
    Livewire::flushState();
});

it('locks one account state across database connections instead of allowing a concurrent increment', function () {
    $actor = jobManager(Role::Employer);
    app(AccessLimits::class)->consume($actor, 'details', (string) Str::ulid(), 1, request());
    config(['database.connections.limit_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('limit_probe');
    try {
        $probe->statement("SET lock_timeout = '100ms'");
        try {
            // The first competing request also attempts insertOrIgnore. PostgreSQL
            // must wait on this uncommitted unique actor key, rather than create a second budget.
            $probe->table('access_limit_states')->insertOrIgnore(['actor_id' => $actor->id]);
            test()->fail('Counter must be locked');
        } catch (QueryException $exception) {
            expect($exception->getCode())->toBe('55P03');
        }
        expect(DB::table('access_limit_states')->where('actor_id', $actor->id)->value('details_used'))->toBe(1);
    } finally {
        DB::purge('limit_probe');
    }
});

it('records a successful login once without credentials or contact data', function () {
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);
    $rows = DB::table('audit_logs')->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->action)->toBe('auth.login')->and($rows[0]->company_id)->toBeNull();
    expect(json_encode($rows))->not->toContain($user->email, 'password', $user->name);
});

it('does not complete authentication or leave a remember cookie when login audit fails', function (bool $remember) {
    $user = User::factory()->create();
    $this->mock(RecordAudit::class)->shouldReceive('login')->andThrow(new RuntimeException('PRIVATE_LOGIN_FAILURE'));
    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => $remember])->assertStatus(503)->assertDontSee('PRIVATE_LOGIN_FAILURE');
    $this->assertGuest();
    expect(session()->has(Auth::guard('web')->getName()))->toBeFalse();
    expect(DB::table('audit_logs')->count())->toBe(0);
    expect($user->fresh()->last_login_at)->toBeNull();
    $recaller = Auth::guard('web')->getRecallerName();
    expect(array_values(array_filter($response->headers->getCookies(), fn ($cookie) => $cookie->getName() === $recaller && $cookie->getExpiresTime() > time())))->toBe([]);
})->with([false, true]);

it('does not record successful login for rejected credentials', function () {
    $user = User::factory()->create();
    $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('denies grant audit hooks to nonadmins or locked admins', function (string $case) {
    $actor = jobManager($case === 'employer' ? Role::Employer : Role::Admin);
    if ($case === 'locked') {
        DB::table('users')->where('id', $actor->id)->update(['status' => 'locked']);
    }
    expect(fn () => app(RecordAudit::class)->grantChange($actor, 'access_grant.issued', 1, (string) Str::ulid(), request()))
        ->toThrow(HttpException::class);
    expect(DB::table('audit_logs')->count())->toBe(0);
})->with(['employer', 'locked']);

it('records grant issue and revoke hooks once per operation with the actual actor and no notes', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $audit = app(RecordAudit::class);
    $operation = (string) Str::ulid();
    $audit->grantChange($f['admin'], 'access_grant.issued', $grant->id, $operation, request());
    $audit->grantChange($f['admin'], 'access_grant.issued', $grant->id, $operation, request());
    DB::table('access_grants')->where('id', $grant->id)->update(['status' => 'revoked']);
    $revoker = jobManager(Role::Admin);
    $audit->grantChange($revoker, 'access_grant.revoked', $grant->id, (string) Str::ulid(), request());
    expect(DB::table('audit_logs')->count())->toBe(2);
    expect(DB::table('audit_logs')->where('action', 'access_grant.revoked')->value('actor_id'))->toBe($revoker->id);
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain('PRIVATE_CONTACT', 'PRIVATE_INTERNAL_NOTES', '0912345678');
});

it('keeps mutation routes for audit absent after the security alert page is added', function () {
    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toMatch('~audit|canh-bao-truy-cap/\{~');
    }
});

it('only records login after the MFA challenge succeeds', function () {
    $user = jobManager(Role::Admin);
    $user->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(['t35-recovery-code']))])->save();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->assertGuest();
    expect(DB::table('audit_logs')->count())->toBe(0);
    $this->post('/two-factor-challenge', ['recovery_code' => 't35-recovery-code'])->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);
    expect(DB::table('audit_logs')->where('action', 'auth.login')->count())->toBe(1);
});

it('rolls back the caller grant mutation if its audit hook fails', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT t35_reject_revoke CHECK (action <> 'access_grant.revoked')");
    expect(fn () => DB::transaction(function () use ($f, $grant) {
        DB::table('access_grants')->where('id', $grant->id)->update(['status' => 'revoked']);
        app(RecordAudit::class)->grantChange($f['admin'], 'access_grant.revoked', $grant->id, (string) Str::ulid(), request());
    }))->toThrow(QueryException::class);
    expect($grant->fresh()->status)->toBe('active');
    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('rejects reuse of a grant operation for a different actor', function () {
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f);
    $operation = (string) Str::ulid();
    app(RecordAudit::class)->grantChange($f['admin'], 'access_grant.issued', $grant->id, $operation, request());
    expect(fn () => app(RecordAudit::class)->grantChange(jobManager(Role::Admin), 'access_grant.issued', $grant->id, $operation, request()))->toThrow(InvalidArgumentException::class);
    expect(DB::table('audit_logs')->count())->toBe(1);
});
