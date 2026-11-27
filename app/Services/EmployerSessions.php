<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Server-side revocation registry; authentication remains Laravel's session cookie. */
class EmployerSessions
{
    public const KEY = 'employer_session_id';

    public function register(User $user, Request $request): void
    {
        DB::transaction(function () use ($user, $request): void {
            $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($current->role !== Role::Employer) {
                $request->session()->forget(self::KEY);

                return;
            }
            abort_unless($current->status === UserStatus::Active && hash_equals($current->password, $user->password), 403);
            $rows = DB::table('employer_sessions')->where('user_id', $current->id)->whereNull('revoked_at');
            $rows->clone()->where(function ($query) use ($current) {
                $query->where('last_seen_at', '<=', now()->subMinutes((int) config('session.lifetime')))
                    ->orWhere('credential_stamp', '<>', hash('sha256', $current->password));
            })->update(['revoked_at' => now()]);
            // Re-authentication in the same browser replaces its own slot.
            $rows->clone()->where('id', $request->session()->get(self::KEY))->update(['revoked_at' => now()]);
            $id = DB::table('employer_sessions')->insertGetId([
                'user_id' => $current->id, 'credential_stamp' => hash('sha256', $current->password),
                'created_at' => now(), 'last_seen_at' => now(),
            ]);
            $victims = $rows->clone()->orderByDesc('id')->skip(2)->pluck('id');
            if ($victims->isNotEmpty()) {
                DB::table('employer_sessions')->whereIn('id', $victims)->update(['revoked_at' => now()]);
                // A Laravel remember cookie must not resurrect an evicted browser.
                $current->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
                $user->setRememberToken($current->getRememberToken());
                $guard = Auth::guard('web');
                $cookie = Cookie::queued($guard->getRecallerName());
                if ($cookie) {
                    Cookie::queue($cookie->withValue($user->id.'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->password)));
                }
            }
            $request->session()->put(self::KEY, $id);
        });
    }

    public function valid(User $user, Request $request): bool
    {
        $id = $request->session()->get(self::KEY);
        if (! is_int($id)) {
            return false;
        }

        return DB::transaction(function () use ($user, $id): bool {
            $row = DB::table('employer_sessions')->where('id', $id)->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $row || $row->revoked_at || ! hash_equals($row->credential_stamp, hash('sha256', $user->password))
                || Carbon::parse($row->last_seen_at)->lte(now()->subMinutes((int) config('session.lifetime')))) {
                return false;
            }
            DB::table('employer_sessions')->where('id', $id)->update(['last_seen_at' => now()]);

            return true;
        });
    }

    public function revoke(?User $user, Request $request): void
    {
        if ($user && $request->hasSession()) {
            DB::table('employer_sessions')->where('user_id', $user->id)->where('id', $request->session()->get(self::KEY))
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $request->session()->forget(self::KEY);
        }
    }
}
