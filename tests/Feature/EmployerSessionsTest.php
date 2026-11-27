<?php

use App\Enums\Role;
use App\Models\User;
use App\Services\EmployerSessions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

uses(RefreshDatabase::class);

beforeEach(function () {
    request()->setLaravelSession(app('session.store'));
});

function employerBrowserRequest($test, ?string $cookie, string $method, string $uri, array $data = [], ?string $recaller = null)
{
    Auth::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    Cookie::unqueue(Auth::guard('web')->getRecallerName());
    Auth::forgetGuards();
    $test->withUnencryptedCookies([config('session.cookie') => $cookie, Auth::guard('web')->getRecallerName() => $recaller]);

    return $test->$method($uri, $data);
}

it('rejects the oldest employer session after a third independent browser logs in', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $cookies = [];
    for ($i = 0; $i < 3; $i++) {
        $response = employerBrowserRequest($this, null, 'post', '/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/app');
        $cookies[] = $response->getCookie(config('session.cookie'), false)->getValue();
    }
    employerBrowserRequest($this, $cookies[0], 'get', '/khu-vuc/employer')->assertRedirect('/app/login');
    employerBrowserRequest($this, $cookies[1], 'get', '/khu-vuc/employer')->assertOk();
    employerBrowserRequest($this, $cookies[2], 'get', '/khu-vuc/employer')->assertOk();
});

it('blocks an evicted session before panel livewire detail viewer and signed file handlers', function (string $uri, string $method) {
    if ($uri === 'livewire') {
        $uri = app(HandleRequests::class)->getUpdateUri();
    }
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $cookies = [];
    for ($i = 0; $i < 3; $i++) {
        $response = employerBrowserRequest($this, null, 'post', '/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/app');
        $cookies[] = $response->getCookie(config('session.cookie'), false)->getValue();
    }
    employerBrowserRequest($this, $cookies[0], $method, $uri)->assertRedirect('/app/login')->assertDontSee('PRIVATE_CONTACT');
    expect(DB::table('audit_logs')->where('action', '<>', 'auth.login')->count())->toBe(0);
})->with([
    ['/app', 'get'], ['/app/ho-so', 'get'], ['/app/ho-so/01EXAMPLE', 'get'],
    ['/app/ho-so/01EXAMPLE/tep/1/download', 'get'], ['/app/ho-so/01EXAMPLE/xem/1', 'get'],
    ['/app/ho-so/01EXAMPLE', 'post'], ['livewire', 'post'],
]);

it('returns a generic 401 for an evicted JSON request', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    DB::table('employer_sessions')->where('user_id', $user->id)->update(['revoked_at' => now()]);
    $this->getJson('/app/ho-so/01EXAMPLE')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
});

it('requires legacy employer sessions without a registry to sign in again', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession([EmployerSessions::KEY => null])->get('/app')->assertRedirect('/app/login');
});

it('does not accept another accounts registry identifier', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $id = session(EmployerSessions::KEY);
    $this->actingAs(User::factory()->create())->withSession([EmployerSessions::KEY => $id])->get('/app')->assertRedirect('/app/login');
});

it('expires an idle slot at the exact configured lifetime boundary', function () {
    $this->freezeTime();
    config(['session.lifetime' => 10]);
    $this->actingAs(User::factory()->create());
    $this->travel(10)->minutes();
    $this->get('/app')->assertRedirect('/app/login');
});

it('uses login order instead of last activity when choosing the victim', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $first = employerBrowserRequest($this, null, 'post', '/login', ['email' => $user->email, 'password' => 'password'])->getCookie(config('session.cookie'), false)->getValue();
    $second = employerBrowserRequest($this, null, 'post', '/login', ['email' => $user->email, 'password' => 'password'])->getCookie(config('session.cookie'), false)->getValue();
    employerBrowserRequest($this, $first, 'get', '/app')->assertOk();
    employerBrowserRequest($this, null, 'post', '/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/app');
    employerBrowserRequest($this, $first, 'get', '/app')->assertRedirect('/app/login');
    employerBrowserRequest($this, $second, 'get', '/app')->assertOk();
});

it('does not impose the employer limit on admin or staff accounts', function (Role $role) {
    $user = User::factory()->create(['role' => $role]);
    for ($i = 0; $i < 3; $i++) {
        app(EmployerSessions::class)->register($user, request());
    }
    expect(DB::table('employer_sessions')->count())->toBe(0);
})->with([Role::Admin, Role::Staff]);

it('counts accounts independently', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    for ($i = 0; $i < 3; $i++) {
        session()->forget(EmployerSessions::KEY);
        app(EmployerSessions::class)->register($first, request());
    }
    session()->forget(EmployerSessions::KEY);
    app(EmployerSessions::class)->register($second, request());
    expect(DB::table('employer_sessions')->where('user_id', $first->id)->whereNull('revoked_at')->count())->toBe(2);
    expect(DB::table('employer_sessions')->where('user_id', $second->id)->whereNull('revoked_at')->count())->toBe(1);
});

it('invalidates old remember cookies on eviction but preserves active slots and the newest remember cookie', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $name = Auth::guard('web')->getRecallerName();
    $cookies = [];
    $recallers = [];
    for ($i = 0; $i < 3; $i++) {
        $response = employerBrowserRequest($this, null, 'post', '/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])->assertRedirect('/app');
        $cookies[] = $response->getCookie(config('session.cookie'), false)->getValue();
        $recallers[] = $response->getCookie($name, false)->getValue();
    }
    employerBrowserRequest($this, null, 'get', '/app', [], $recallers[0])->assertRedirect('/app/login');
    employerBrowserRequest($this, $cookies[1], 'get', '/app')->assertOk();
    employerBrowserRequest($this, $cookies[2], 'get', '/app')->assertOk();
    employerBrowserRequest($this, null, 'get', '/app', [], $recallers[2])->assertOk();
    expect(DB::table('employer_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(2);
});

it('does not allocate a slot before the employer MFA challenge succeeds', function () {
    $user = User::factory()->create(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['t36-recovery-code'])), 'two_factor_confirmed_at' => now()]);
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    expect(DB::table('employer_sessions')->count())->toBe(0);
    $this->post('/two-factor-challenge', ['recovery_code' => 't36-recovery-code'])->assertRedirect('/app');
    expect(DB::table('employer_sessions')->whereNull('revoked_at')->count())->toBe(1);
    $this->get('/app')->assertOk();
});

it('revokes the registry on logout and cannot resurrect it by restoring a session payload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $id = session(EmployerSessions::KEY);
    $this->post('/logout')->assertRedirect();
    expect(DB::table('employer_sessions')->where('id', $id)->value('revoked_at'))->not->toBeNull();
    Auth::guard('web')->setUser($user);
    $this->withSession([EmployerSessions::KEY => $id])->get('/app')->assertRedirect('/app/login');
});

it('rejects a session when the password hash was changed even by a quiet write', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('new-password')]);
    $this->get('/app')->assertRedirect('/app/login');
});

it('fails closed if the registry is unavailable without leaking technical errors', function () {
    config(['app.debug' => false]);
    $this->actingAs(User::factory()->create());
    $this->mock(EmployerSessions::class)->shouldReceive('valid')->andThrow(new RuntimeException('PRIVATE_REGISTRY_FAILURE'));
    $this->get('/app')->assertStatus(503)->assertDontSee('PRIVATE_REGISTRY_FAILURE');
});

it('rolls back login audit and does not authenticate if registering the session fails', function () {
    config(['app.debug' => false]);
    $user = User::factory()->create();
    $this->mock(EmployerSessions::class)->shouldReceive('register')->andThrow(new RuntimeException('PRIVATE_REGISTER_FAILURE'));
    app(EmployerSessions::class)->shouldReceive('revoke')->once();
    $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])->assertStatus(503)->assertDontSee('PRIVATE_REGISTER_FAILURE');
    $this->assertGuest();
    expect(DB::table('audit_logs')->count())->toBe(0);
    expect($user->fresh()->last_login_at)->toBeNull();
});

it('replaces its own slot when reauthenticating in the same browser', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $old = session(EmployerSessions::KEY);
    app(EmployerSessions::class)->register($user, request());
    expect(session(EmployerSessions::KEY))->not->toBe($old);
    expect(DB::table('employer_sessions')->whereNull('revoked_at')->count())->toBe(1);
});

it('serializes competing logins with a PostgreSQL user row lock', function () {
    config(['database.connections.session_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('session_probe');
    $id = $probe->table('users')->insertGetId(User::factory()->make()->getAttributes());
    DB::beginTransaction();
    try {
        app(EmployerSessions::class)->register(User::findOrFail($id), request());
        $probe->statement("SET lock_timeout = '100ms'");
        try {
            $probe->table('users')->where('id', $id)->lockForUpdate()->first();
            test()->fail('The competing login must wait on the same account lock');
        } catch (QueryException $exception) {
            expect($exception->getCode())->toBe('55P03');
        }
        expect(DB::table('employer_sessions')->where('user_id', $id)->whereNull('revoked_at')->count())->toBe(1);
    } finally {
        DB::rollBack();
        $probe->table('users')->where('id', $id)->delete();
        DB::purge('session_probe');
    }
});
