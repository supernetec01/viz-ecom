<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

uses(RefreshDatabase::class);
beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('app')));

it('rejects locked accounts with correct credentials', function () {
    $user = User::factory()->create(['status' => UserStatus::Locked]);
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
});

it('evicts an open session on the next request after locking', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    DB::table('users')->where('id', $user->id)->update(['status' => 'locked']);
    $this->get('/app')->assertRedirect('/app/login');
    $this->assertGuest();
});

it('throttles login after five failures', function () {
    $user = User::factory()->create();
    $login = Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'wrong']);
    for ($i = 0; $i < 5; $i++) {
        $login->call('authenticate')->assertHasFormErrors(['email']);
    }
    $login->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate');
    $this->assertGuest();
});

it('requires MFA enrollment for privileged roles', function (Role $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))->get('/app')
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
})->with([Role::Admin, Role::Staff]);

it('authenticates active employers and supports logout', function () {
    $user = User::factory()->create();
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($user);
    $this->post('/app/logout')->assertRedirect();
    $this->assertGuest();
});

it('encrypts MFA secrets and excludes them from serialized users', function () {
    $user = User::factory()->create();
    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    expect($user->fresh()->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP')
        ->and(DB::table('users')->where('id', $user->id)->value('app_authentication_secret'))->not->toBe('JBSWY3DPEHPK3PXP')
        ->and($user->fresh()->toArray())->not->toHaveKey('app_authentication_secret');
});

it('requires a valid second factor to complete login and rejects code replay', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    $provider = AppAuthentication::make()->codeWindow(1);
    $secret = $provider->generateSecret();
    $user->saveAppAuthenticationSecret($secret);
    $login = Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate');
    $this->assertGuest();
    $code = $provider->getCurrentCode($user);
    $login->set('data.multiFactor.app.code', $code === '000000' ? '111111' : '000000')->call('authenticate');
    $this->assertGuest();
    $login->set('data.multiFactor.app.code', $code)->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($user);
    expect($provider->verifyCode($code, $secret, shouldPreventCodeReuse: true))->toBeFalse();
});

it('consumes a recovery code once', function () {
    $user = User::factory()->create();
    $provider = AppAuthentication::make()->recoverable();
    $provider->saveRecoveryCodes($user, ['test-recovery-code', 'another-recovery-code']);
    expect($provider->verifyRecoveryCode('test-recovery-code', $user))->toBeTrue()
        ->and($provider->verifyRecoveryCode('test-recovery-code', $user->fresh()))->toBeFalse();
});

it('consumes reset tokens once and invalidates existing sessions', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $token = Password::createToken($user);
    $credentials = ['email' => $user->email, 'token' => $token, 'password' => 'New-Password-2026!', 'password_confirmation' => 'New-Password-2026!'];
    $reset = fn (User $user, string $password) => $user->forceFill(['password' => $password])->save();
    expect(Password::reset($credentials, $reset))->toBe(Password::PASSWORD_RESET)
        ->and(Password::reset($credentials, $reset))->toBe(Password::INVALID_TOKEN)
        ->and(Hash::check('New-Password-2026!', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
});

it('expires reset links after 24 hours', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $this->travel(24)->hours();
    $this->travel(1)->seconds();
    expect(Password::tokenExists($user, $token))->toBeFalse();
});
