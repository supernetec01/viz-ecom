<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('casts valid roles and states', function (Role $role, UserStatus $status) {
    $user = User::factory()->create(['role' => $role, 'status' => $status]);
    expect($user->fresh()->role)->toBe($role)->and($user->fresh()->status)->toBe($status);
})->with(Role::cases())->with(UserStatus::cases());

it('rejects invalid enum values on the model', function (string $field) {
    $user = new User;
    $user->{$field} = 'invalid';
})->with(['role', 'status'])->throws(ValueError::class);

it('rejects invalid values at the database boundary', function (string $field) {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update([$field => 'invalid']);
})->with(['role', 'status'])->throws(QueryException::class);

it('seeds one account per role without duplicates or plaintext passwords', function () {
    config(['seeding.sample_password' => 'Only-For-Testing-2026']);
    $this->seed();
    $this->seed();
    expect(User::count())->toBe(3);
    foreach (Role::cases() as $role) {
        $user = User::where('role', $role)->sole();
        expect($user->status)->toBe(UserStatus::Active)
            ->and(Hash::check('Only-For-Testing-2026', $user->password))->toBeTrue();
    }
});

it('does not create sample accounts in production', function () {
    app()->instance('env', 'production');
    app(DatabaseSeeder::class)->run();
    expect(User::count())->toBe(0);
});
