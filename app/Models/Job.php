<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\JobStatus;
use App\Support\JobData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @property JobStatus $status
 * @property EmploymentType $employment_type
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $expires_at
 */
#[Fillable(['company_id', 'category_id', 'location_id', 'title', 'description', 'requirements', 'benefits', 'salary_min', 'salary_max', 'negotiable', 'experience_years', 'vacancies', 'employment_type', 'expires_at'])]
class Job extends Model
{
    use SoftDeletes;

    protected $attributes = ['status' => 'draft', 'negotiable' => false, 'experience_years' => 0, 'vacancies' => 1, 'employment_type' => 'full_time'];

    protected function casts(): array
    {
        return ['status' => JobStatus::class, 'employment_type' => EmploymentType::class, 'negotiable' => 'boolean', 'salary_min' => 'integer', 'salary_max' => 'integer', 'published_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $job): void {
            if ($job->exists && DB::table('jobs')->where('id', $job->getKey())->lockForUpdate()->value('status') !== $job->getRawOriginal('status')) {
                throw ValidationException::withMessages(['status' => __('ui.jobs.stale_state')]);
            }
            JobData::validate($job->getAttributes());
            $previous = $job->exists ? JobStatus::from($job->getRawOriginal('status')) : JobStatus::Draft;
            if ((! $job->exists && $job->status !== JobStatus::Draft) || ($job->exists && $job->isDirty('status') && ! $previous->canTransitionTo($job->status))) {
                throw ValidationException::withMessages(['status' => __('ui.jobs.invalid_transition')]);
            }
            if ($job->exists && $job->isDirty('status') && $job->status === JobStatus::Draft) {
                $job->published_at = null;
                $job->expires_at = null;
            }
            if ($job->status === JobStatus::Published) {
                $job->published_at ??= CarbonImmutable::now();
                if (! $job->expires_at || $job->expires_at->lte($job->published_at) || ($job->isDirty('status') && $job->expires_at->isPast())) {
                    throw ValidationException::withMessages(['expires_at' => __('ui.jobs.invalid_expiry')]);
                }
            }
            if (! $job->exists) {
                $base = Str::limit(Str::slug($job->title), 175, '') ?: 'viec-lam';
                // Serialize equal titles until insertion commits, so concurrent drafts get suffixes too.
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['job-slug:'.$base]);
                $slug = $base;
                for ($suffix = 2; static::withTrashed()->where('slug', $slug)->exists(); $suffix++) {
                    $slug = $base.'-'.$suffix;
                }
                $job->slug = $slug;
            }
        });
    }

    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => parent::save($options));
    }

    public function transitionTo(JobStatus $status): void
    {
        $this->status = $status;
        $this->save();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<JobCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
