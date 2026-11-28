<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\AccessPaymentUnavailable;
use App\Models\AccessGrant;
use App\Models\Company;
use App\Models\Job;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RequestApplicationAccess
{
    public function allowed(User $user): bool
    {
        return User::query()->whereKey($user->id)->where('role', Role::Employer->value)->where('status', UserStatus::Active->value)->exists();
    }

    /** @return Builder<Job> */
    public function jobs(User $user): Builder
    {
        abort_unless($this->allowed($user), 403);

        return Job::query()->whereIn('status', ['published', 'paused', 'expired', 'closed'])
            ->whereHas('company', fn (Builder $companies) => $companies->whereHas('employers', fn (Builder $users) => $users->where('users.id', $user->id)))
            ->select(['jobs.id', 'jobs.company_id', 'jobs.title', 'jobs.status'])
            ->selectSub(Company::query()->select('name')->whereColumn('companies.id', 'jobs.company_id')->limit(1), 'company_name')
            ->selectSub(AccessGrant::query()->effective()->whereColumn('access_grants.company_id', 'jobs.company_id')
                ->whereColumn('access_grants.job_id', 'jobs.id')->selectRaw('true')->limit(1), 'access_active');
    }

    public function request(User $user, string $jobId): ?Order
    {
        abort_unless(filter_var($jobId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);

        return DB::transaction(function () use ($user, $jobId): ?Order {
            abort_unless(User::query()->whereKey($user->id)->where('role', Role::Employer->value)->where('status', UserStatus::Active->value)->sharedLock()->first(['id']) !== null, 403);
            // Future T38 must lock the job before the order too.
            $job = $this->jobs($user)->whereKey($jobId)->lockForUpdate()->first();
            abort_unless($job !== null, 403);
            abort_unless(Company::query()->whereKey($job->company_id)->sharedLock()->first(['id'])
                && DB::table('company_user')->where('company_id', $job->company_id)->where('user_id', $user->id)->sharedLock()->first(), 403);
            // The SELECT projection can predate a lock wait. Re-read after locking.
            if (AccessGrant::query()->effective()->where('company_id', $job->company_id)->where('job_id', $job->id)->exists()) {
                return null;
            }
            $pending = Order::query()->where('company_id', $job->company_id)->where('job_id', $job->id)
                ->where('type', 'access_grant')->where('status', 'pending')->lockForUpdate()->first();
            if ($pending) {
                return $pending;
            }
            // An old unscoped pending order cannot safely be assigned a job by guessing.
            if (Order::query()->where('company_id', $job->company_id)->where('type', 'access_grant')->where('status', 'pending')->whereNull('job_id')->exists()) {
                throw new AccessPaymentUnavailable(legacyOrder: true);
            }
            $settings = app(AccessPaymentSettings::class)->snapshot();
            $order = new Order;
            $order->forceFill(['company_id' => $job->company_id, 'job_id' => $job->id, 'type' => 'access_grant',
                'amount' => $settings['amount'], 'status' => 'pending', 'method' => 'bank_transfer',
                'duration_days' => $settings['duration_days'],
                'payment_reference' => 'VE'.Str::ulid(), 'payment_instructions' => $settings['instructions']])->save();

            return $order;
        });
    }

    public function pending(User $user, string $id): Order
    {
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);
        abort_unless($this->allowed($user), 403);
        $order = Order::query()->whereKey($id)->where('type', 'access_grant')->where('status', 'pending')
            ->whereIn('job_id', $this->jobs($user)->select('jobs.id'))->with(['company:id,name', 'job:id,title'])->first();
        abort_unless($order !== null && is_array($order->payment_instructions)
            && count($order->payment_instructions) === 3 && array_diff(['bank', 'account', 'holder'], array_keys($order->payment_instructions)) === []
            && $order->payment_reference, 403);
        foreach ($order->payment_instructions as $value) {
            abort_unless(is_string($value) && trim($value) !== '', 403);
        }

        return $order;
    }
}
