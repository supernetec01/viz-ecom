<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

function confirmedMfaUser(): User
{
    return User::factory()->create(['role' => Role::Admin, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['test-recovery-code', 'other-recovery-code'])), 'two_factor_confirmed_at' => now()]);
}

it('serves Fortify login and redirects the panel login to it', function () {
    $this->get('/login')->assertOk()->assertSee('Đăng nhập');
    $this->get('/app/login')->assertRedirect('/login');
});

it('rejects locked accounts with correct credentials', function () {
    $user = User::factory()->create(['status' => UserStatus::Locked]);
    $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
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
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
    }
    $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
    $this->assertGuest();
});

it('requires confirmed MFA enrollment for privileged roles', function (Role $role) {
    $user = User::factory()->create(['role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]);
    $this->actingAs($user)->get('/app')->assertRedirect('/bao-mat');
})->with([Role::Admin, Role::Staff]);

it('lets employers sign in without mandatory MFA and supports logout', function () {
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);
    $this->get('/app')->assertOk();
    $this->post('/logout')->assertRedirect();
    $this->assertGuest();
});

it('confirms enrollment using an authenticator code and hides encrypted secrets', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/user/two-factor-authentication')->assertRedirect();
    $user->refresh();
    expect($user->hasEnabledTwoFactorAuthentication())->toBeFalse();
    $secret = decrypt($user->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);
    $this->post('/user/confirmed-two-factor-authentication', ['code' => $code])->assertRedirect();
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and($user->two_factor_secret)->not->toBe($secret)
        ->and($user->fresh()->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes']);
});

it('requires a valid second factor and rejects replay', function () {
    $user = confirmedMfaUser();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->assertGuest();
    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
    $this->post('/two-factor-challenge', ['code' => $code === '000000' ? '111111' : '000000'])
        ->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
    $this->assertGuest();
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);
    Auth::logout();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->postJson('/two-factor-challenge', ['code' => $code])->assertUnprocessable();
    $this->assertGuest();
});

it('consumes recovery codes once', function () {
    $user = confirmedMfaUser();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['recovery_code' => 'test-recovery-code'])->assertRedirect('/app');
    Auth::logout();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->postJson('/two-factor-challenge', ['recovery_code' => 'test-recovery-code'])->assertUnprocessable();
    $this->assertGuest();
});

it('rejects a pending MFA challenge after locking or changing the password', function (string $field, string $value) {
    $user = confirmedMfaUser();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $user->forceFill([$field => $value])->save();
    $this->post('/two-factor-challenge', ['recovery_code' => 'test-recovery-code'])->assertRedirect('/login');
    $this->assertGuest();
})->with([['status', 'locked'], ['password', 'Changed-Password-2026!']]);

it('prevents privileged users from disabling MFA', function () {
    $this->actingAs(confirmedMfaUser())->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/user/two-factor-authentication')->assertForbidden();
});

it('sends reset links through a fake notification channel', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
    Notification::assertSentTo($user, ResetPasswordLink::class);
});

it('consumes reset links once through Fortify and invalidates existing sessions', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $token = Password::createToken($user);
    $credentials = ['email' => $user->email, 'token' => $token, 'password' => 'New-Password-2026!', 'password_confirmation' => 'New-Password-2026!'];
    $this->post('/reset-password', $credentials)->assertSessionHasNoErrors();
    $this->post('/reset-password', $credentials)->assertSessionHasErrors('email');
    expect(Hash::check('New-Password-2026!', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
});

it('expires reset links after 24 hours', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $this->travel(24)->hours();
    $this->travel(1)->seconds();
    expect(Password::tokenExists($user, $token))->toBeFalse();
});
