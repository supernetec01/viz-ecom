<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        if (! app()->environment('local', 'testing')) {
            return;
        }

        foreach (Role::cases() as $role) {
            $user = User::firstOrNew(['email' => $role->value.'@viz-ecom.test']);
            if ($user->exists) {
                continue;
            }
            $user->name = $role->value;
            $user->role = $role;
            $user->status = UserStatus::Active;
            $user->password = config('seeding.sample_password') ?: Str::password(32);
            $user->save();
        }
    }
}
