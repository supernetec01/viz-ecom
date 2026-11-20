<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\UserStatus;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordLink;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property Role $role
 * @property UserStatus $status
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser
{
    protected $attributes = ['role' => 'employer', 'status' => 'active'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use TwoFactorAuthenticatable;

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordLink($token));
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === UserStatus::Active;
    }

    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged(['password', 'status'])) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
