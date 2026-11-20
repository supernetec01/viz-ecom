<?php

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('denies reading and updating an unassigned company', function () {
    $staff = User::factory()->create(['role' => Role::Staff]);
    $staff->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $other = Company::create(['name' => 'Other company']);
    $this->actingAs($staff)->get('/noi-bo/cong-ty/'.$other->id)->assertForbidden();
    expect(Gate::forUser($staff)->denies('update', $other))->toBeTrue();
});

it('limits staff and employers to linked companies and lets admin see all', function () {
    $staff = User::factory()->create(['role' => Role::Staff]);
    $employer = User::factory()->create();
    $admin = User::factory()->create(['role' => Role::Admin]);
    $own = Company::create(['name' => 'Assigned']);
    $other = Company::create(['name' => 'Other']);
    $own->staff()->attach($staff);
    $own->employers()->attach($employer);
    expect(Company::visibleTo($staff)->pluck('id')->all())->toBe([$own->id])
        ->and(Company::visibleTo($employer)->pluck('id')->all())->toBe([$own->id])
        ->and(Company::visibleTo($admin)->count())->toBe(2)
        ->and(Gate::forUser($staff)->allows('update', $own))->toBeTrue()
        ->and(Gate::forUser($employer)->denies('update', $own))->toBeTrue();
    $staff->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($staff)->get('/noi-bo/cong-ty/'.$own->id)->assertOk()->assertJsonPath('name', 'Assigned');
    $this->get('/noi-bo/cong-ty/'.$other->id)->assertForbidden();
    $this->actingAs($employer)->get('/noi-bo/cong-ty/'.$other->id)->assertForbidden();
});

it('revokes visibility immediately when assignment is removed or the account is locked', function () {
    $staff = User::factory()->create(['role' => Role::Staff]);
    $company = Company::create(['name' => 'Assigned']);
    $company->staff()->attach($staff);
    expect(Gate::forUser($staff)->allows('view', $company))->toBeTrue();
    $company->staff()->detach($staff);
    expect(Gate::forUser($staff)->denies('view', $company))->toBeTrue();
    $company->staff()->attach($staff);
    $staff->forceFill(['status' => 'locked'])->save();
    expect(Company::visibleTo($staff)->count())->toBe(0);
});
