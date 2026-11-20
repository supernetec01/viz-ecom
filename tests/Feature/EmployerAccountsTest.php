<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\EmployerAccountsRelationManager;
use App\Models\Company;
use App\Models\User;
use App\Notifications\EmployerInvitation;
use App\Services\EmployerAccounts;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);
beforeEach(fn () => Notification::fake());

function employerManager(): array
{
    $staff = User::factory()->create(['role' => Role::Staff, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    $company = Company::create(['name' => 'Managed company']);
    $company->staff()->attach($staff);

    return [$staff, $company];
}

it('denies managing accounts of another staff company with 403', function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $staff = User::factory()->create(['role' => Role::Staff, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    $other = Company::create(['name' => 'Other company']);
    $this->actingAs($staff);
    Livewire::test(EmployerAccountsRelationManager::class, ['ownerRecord' => $other, 'pageClass' => EditCompany::class])->assertForbidden();
});

it('creates the account through Filament for the owner company and sends an invitation', function () {
    [$staff, $company] = employerManager();
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $this->actingAs($staff);
    Livewire::test(EmployerAccountsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class])
        ->callAction(TestAction::make('inviteEmployer')->table(), data: ['name' => 'HR', 'email' => 'HR@COMPANY.EXAMPLE'])->assertHasNoActionErrors();
    $user = User::where('email', 'hr@company.example')->sole();
    expect($user->role)->toBe(Role::Employer)->and($user->companies()->pluck('companies.id')->all())->toBe([$company->id]);
    Notification::assertSentTo($user, EmployerInvitation::class);
});

it('never puts the generated password in an invitation', function () {
    [$staff, $company] = employerManager();
    Str::createRandomStringsUsing(fn (int $length) => str_repeat('p', $length));
    try {
        $user = app(EmployerAccounts::class)->create($staff, $company, ['name' => 'HR', 'email' => 'hr@company.example', 'role' => 'admin']);
    } finally {
        Str::createRandomStringsNormally();
    }
    expect(Hash::check(str_repeat('p', 64), $user->password))->toBeTrue()->and($user->role)->toBe(Role::Employer);
    $invitation = Notification::sent($user, EmployerInvitation::class)->sole();
    $body = (string) $invitation->toMail($user)->render();
    expect($body)->not->toContain(str_repeat('p', 64))->not->toContain($user->password)
        ->and(Password::tokenExists($user, $invitation->token))->toBeTrue();
});

it('locks login revokes sessions and reset links and supports unlocking', function () {
    [$staff, $company] = employerManager();
    $service = app(EmployerAccounts::class);
    $user = $service->create($staff, $company, ['name' => 'HR', 'email' => 'hr@company.example']);
    $user->forceFill(['password' => 'Known-Test-Password-2026!'])->save();
    $token = Password::createToken($user);
    DB::table('sessions')->insert(['id' => 'employer-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $service->setStatus($staff, $company, $user, UserStatus::Locked);
    $this->assertDatabaseMissing('sessions', ['id' => 'employer-device']);
    expect(Password::tokenExists($user, $token))->toBeFalse();
    Auth::logout();
    $this->postJson('/login', ['email' => $user->email, 'password' => 'Known-Test-Password-2026!'])->assertUnprocessable();
    $service->setStatus($staff, $company, $user, UserStatus::Active);
    $this->post('/login', ['email' => $user->email, 'password' => 'Known-Test-Password-2026!'])->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);
});

it('resends an invitation with a new token and invalidates the old link', function () {
    [$staff, $company] = employerManager();
    $service = app(EmployerAccounts::class);
    $user = $service->create($staff, $company, ['name' => 'HR', 'email' => 'hr@company.example']);
    $first = Notification::sent($user, EmployerInvitation::class)->sole();
    $service->resend($staff, $company, $user);
    expect(Notification::sent($user, EmployerInvitation::class))->toHaveCount(2)
        ->and(Password::tokenExists($user, $first->token))->toBeFalse();
});

it('does not let staff lock a shared account linked to an unmanaged company', function () {
    [$staff, $company] = employerManager();
    $service = app(EmployerAccounts::class);
    $user = $service->create($staff, $company, ['name' => 'HR', 'email' => 'hr@company.example']);
    Company::create(['name' => 'Other company'])->employers()->attach($user);
    try {
        $service->setStatus($staff, $company, $user, UserStatus::Locked);
        $this->fail('Expected forbidden');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

it('rejects an existing privileged email instead of reassigning its account', function () {
    [$staff, $company] = employerManager();
    $admin = User::factory()->create(['role' => Role::Admin, 'email' => 'admin@company.example']);
    expect(fn () => app(EmployerAccounts::class)->create($staff, $company, ['name' => 'HR', 'email' => 'ADMIN@COMPANY.EXAMPLE']))->toThrow(ValidationException::class);
    expect($admin->fresh()->role)->toBe(Role::Admin)->and($company->employers()->count())->toBe(0);
});

it('does not expose public registration', function () {
    $this->get('/register')->assertNotFound();
});

it('locks and unlocks employer accounts through Filament actions', function () {
    [$staff, $company] = employerManager();
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $user = app(EmployerAccounts::class)->create($staff, $company, ['name' => 'HR', 'email' => 'hr@company.example']);
    $this->actingAs($staff);
    $manager = Livewire::test(EmployerAccountsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class]);
    $manager->callAction(TestAction::make('lock')->table($user))->assertHasNoActionErrors();
    expect($user->fresh()->status)->toBe(UserStatus::Locked);
    $manager->callAction(TestAction::make('unlock')->table($user))->assertHasNoActionErrors();
    expect($user->fresh()->status)->toBe(UserStatus::Active);
});
