<?php

use App\Enums\Role;
use App\Filament\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('app')));

function managementUser(Role $role): User
{
    return User::factory()->create(['role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

it('denies employer access to company management', function () {
    $this->actingAs(User::factory()->create())->get('/app/cong-ty')->assertForbidden();
});

it('lets staff create an assigned company and edit it without verifying', function () {
    $staff = managementUser(Role::Staff);
    $otherStaff = managementUser(Role::Staff);
    $this->actingAs($staff);
    Livewire::test(CreateCompany::class)->fillForm(['name' => 'Alpha', 'type' => 'direct'])
        ->set('data.staff', [$otherStaff->id])->set('data.verified_at', now()->toDateTimeString())
        ->call('create')->assertHasNoFormErrors();
    $company = Company::where('name', 'Alpha')->sole();
    expect($company->staff()->whereKey($staff->id)->exists())->toBeTrue()->and($company->isVerified())->toBeFalse();
    expect($company->staff()->pluck('users.id')->all())->toBe([$staff->id])->and($company->verified_at)->toBeNull();
    Livewire::test(EditCompany::class, ['record' => $company->id])->fillForm(['name' => 'Updated'])->call('save')->assertHasNoFormErrors();
    expect($company->fresh()->name)->toBe('Updated');
    Livewire::test(ListCompanies::class)->assertActionHidden(TestAction::make('verify')->table($company));
});

it('scopes search filters and edit URLs to assigned companies', function () {
    $staff = managementUser(Role::Staff);
    $own = Company::create(['name' => 'Alpha', 'type' => 'agency']);
    $other = Company::create(['name' => 'Other']);
    $own->staff()->attach($staff);
    $this->actingAs($staff);
    Livewire::test(ListCompanies::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other])
        ->searchTable('Alpha')->filterTable('type', 'agency')->assertCanSeeTableRecords([$own]);
    $this->get('/app/cong-ty')->assertOk()->assertDontSee('ui-avatars.com')->assertSee('data:image/svg+xml;base64,', false);
    $this->get('/app/cong-ty/'.$other->id.'/edit')->assertNotFound();
});

it('allows only admin to verify a company', function () {
    $this->actingAs(managementUser(Role::Admin));
    $company = Company::create(['name' => 'Verify']);
    Livewire::test(ListCompanies::class)->callAction(TestAction::make('verify')->table($company))->assertHasNoActionErrors();
    expect($company->fresh()->isVerified())->toBeTrue();
});

it('saves validated company channels through the form', function () {
    $this->actingAs(managementUser(Role::Admin));
    Livewire::test(CreateCompany::class)->fillForm([
        'name' => 'Channel Company', 'type' => 'agency',
        'channels' => [
            ['type' => 'shopee', 'display_name' => 'Shop', 'url' => 'https://shopee.vn/shop'],
            ['type' => 'website', 'display_name' => 'Website', 'url' => 'https://new-company.example'],
        ],
    ])->call('create')->assertHasNoFormErrors();
    $company = Company::where('name', 'Channel Company')->sole();
    expect($company->channels()->count())->toBe(2);
});

it('rejects invalid channel links without creating a company', function () {
    $this->actingAs(managementUser(Role::Admin));
    Livewire::test(CreateCompany::class)->fillForm([
        'name' => 'Invalid Company', 'type' => 'direct',
        'channels' => [['type' => 'shopee', 'display_name' => 'Shop', 'url' => 'https://evil.test/shop']],
    ])->call('create')->assertHasFormErrors();
    $this->assertDatabaseMissing('companies', ['name' => 'Invalid Company']);
});
