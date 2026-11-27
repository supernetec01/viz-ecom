<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccessLimits
{
    /** Call after ApplicationPolicy, before audit/content. One row lock serializes all buckets per actor. */
    public function consume(User $actor, string $bucket, string $applicationId, int $companyId, Request $request): void
    {
        $limit = config('access-limits.'.$bucket);
        $seconds = config('access-limits.window_seconds');
        $cooldown = config('access-limits.alert_cooldown_seconds');
        if (! in_array($bucket, ['file', 'details'], true) || ! is_int($limit) || $limit < 1 || ! is_int($seconds) || $seconds < 1 || ! is_int($cooldown) || $cooldown < 1) {
            throw new InvalidArgumentException('Invalid access limit configuration.');
        }
        $retry = DB::transaction(function () use ($actor, $bucket, $applicationId, $companyId, $request, $limit, $seconds, $cooldown): int {
            DB::table('access_limit_states')->insertOrIgnore(['actor_id' => $actor->id]);
            $state = DB::table('access_limit_states')->where('actor_id', $actor->id)->lockForUpdate()->first();
            $now = CarbonImmutable::now('UTC');
            $startColumn = $bucket.'_started_at';
            $usedColumn = $bucket.'_used';
            $started = $state->$startColumn ? CarbonImmutable::parse($state->$startColumn) : null;
            if ($started === null || $now->greaterThanOrEqualTo($started->addSeconds($seconds))) {
                $started = $now;
                $state->$usedColumn = 0;
                DB::table('access_limit_states')->where('actor_id', $actor->id)->update([$startColumn => $now->format('Y-m-d H:i:s.uP'), $usedColumn => 0]);
            }
            if ($limit > $state->$usedColumn) {
                DB::table('access_limit_states')->where('actor_id', $actor->id)->increment($usedColumn);

                return 0;
            }
            if ($state->alerted_at === null || $now->greaterThanOrEqualTo(CarbonImmutable::parse($state->alerted_at)->addSeconds($cooldown))) {
                $current = User::query()->whereKey($actor->id)->firstOrFail(['id', 'role']);
                DB::table('access_alerts')->insert(['actor_id' => $actor->id, 'actor_role' => $current->role->value,
                    'company_id' => $companyId, 'bucket' => $bucket, 'limit' => $limit, 'created_at' => $now]);
                DB::table('access_limit_states')->where('actor_id', $actor->id)->update(['alerted_at' => $now]);
                foreach (User::query()->where('role', Role::Admin->value)->where('status', UserStatus::Active->value)->get(['id']) as $admin) {
                    $notification = Notification::make()->warning()->title(__('security.alert_title'))->body(__('security.alert_body'))
                        ->actions([Action::make('review')->label(__('security.alert_open'))->url(url('/app/canh-bao-truy-cap'))]);
                    $admin->notifyNow($notification->toDatabase());
                }
            }
            app(RecordAudit::class)->blocked($actor, $applicationId, $companyId, $bucket, $limit, $request);

            return max(1, (int) ceil($now->diffInSeconds($started->addSeconds($seconds))));
        });
        // Abort only after committing alert/audit, otherwise the transaction would roll them back.
        abort_if($retry > 0, 429, __('security.too_fast'), ['Retry-After' => (string) $retry]);
    }
}
