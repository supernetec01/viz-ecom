<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\CandidateFile;
use App\Models\Job;
use App\Models\User;
use App\Support\ApplicationRestriction;
use App\Support\FileReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ApplicationPolicy
{
    public function viewAny(?User $actor): bool
    {
        return $this->activeActor($actor)?->role === Role::Employer;
    }

    private function summaryNameExpression(): string
    {
        return "replace(application_summary_name(applications.full_name), '[contact]', ?)";
    }

    /** @return Builder<Application> */
    public function employerSummaryQuery(User $actor): Builder
    {
        $current = $this->activeActor($actor);
        abort_unless($current && $current->role === Role::Employer, 403);

        return $this->scopedApplications($current)->join('jobs', 'jobs.id', '=', 'applications.job_id')
            ->select(['applications.id', 'applications.job_id', 'applications.submitted_at', 'jobs.title as job_title'])
            ->selectRaw($this->summaryNameExpression().' AS full_name', [__('applications.contact_hidden')]);
    }

    public function employerSummaryJobs(User $actor): array
    {
        return $this->employerSummaryQuery($actor)->reorder()->select(['jobs.id', 'jobs.title'])->distinct()->orderBy('jobs.title')
            ->get()->pluck('title', 'id')->all();
    }

    /** @param Builder<Application> $query @return Builder<Application> */
    public function searchSummaries(Builder $query, string $search): Builder
    {
        Validator::make(['search' => $search], ['search' => ['string', 'max:200', 'regex:/\A[^\x00-\x1F\x7F]*\z/u']], attributes: ['search' => __('applications.summary_search')])->validate();
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        return $query->where(fn (Builder $where) => $where->whereRaw($this->summaryNameExpression().' ILIKE ?', [__('applications.contact_hidden'), $pattern])
            ->orWhere('jobs.title', 'ilike', $pattern));
    }

    /** @param Builder<Application> $query @return Builder<Application> */
    public function filterSummaryJob(Builder $query, mixed $jobId): Builder
    {
        Validator::make(['job' => $jobId], ['job' => ['nullable', 'integer', 'min:1']], attributes: ['job' => __('applications.summary_job')])->validate();

        return $jobId === null || $jobId === '' ? $query : $query->where('applications.job_id', $jobId);
    }

    /** @param Builder<Application> $query @return Builder<Application> */
    public function filterSummaryDates(Builder $query, array $dates): Builder
    {
        Validator::make($dates, ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', ...(! empty($dates['from']) ? ['after_or_equal:from'] : [])]],
            attributes: ['from' => __('applications.summary_from'), 'until' => __('applications.summary_until')])->validate();
        $zone = config('app.display_timezone');
        if (! empty($dates['from'])) {
            $query->where('applications.submitted_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $dates['from'], $zone)->utc());
        }
        if (! empty($dates['until'])) {
            $query->where('applications.submitted_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $dates['until'], $zone)->addDay()->utc());
        }

        return $query;
    }

    public function viewSummary(?User $actor, string $id): bool
    {
        return $this->context($this->activeActor($actor), $id) !== null;
    }

    public function viewDetails(?User $actor, string $id): bool
    {
        $current = $this->activeActor($actor);
        $application = $this->context($current, $id);
        if (! $current || ! $application) {
            return false;
        }

        if ($current->role !== Role::Admin && app(ApplicationRestriction::class)->blocked($application->company_id, $application->job_id)) {
            return false;
        }

        return $current->role !== Role::Employer || AccessGrant::query()->effective()
            ->where('company_id', $application->company_id)->where('job_id', $application->job_id)->exists();
    }

    public function download(?User $actor, string $id, int $fileId): bool
    {
        if ($fileId < 1 || ! $this->viewDetails($actor, $id)) {
            return false;
        }
        $attached = Application::query()->whereKey($id)->where(fn (Builder $query) => $query
            ->where('cv_file_id', $fileId)->orWhere('portfolio_file_id', $fileId))->exists();
        if (! $attached) {
            return false;
        }
        $file = CandidateFile::query()->whereKey($fileId)->first(['id', 'scan_status', 'preview_status']);

        return $file && FileReadiness::for($file)['can_download'];
    }

    public function detailRecord(User $actor, string $id, bool $lock = false): Application
    {
        abort_unless($this->viewDetails($actor, $id), 403);
        $query = $this->scopedApplications($this->activeActor($actor))->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $record = $query->first(['id', 'company_id', 'job_id', 'full_name', 'phone_normalized', 'email', 'portfolio_url',
            'status', 'internal_notes', 'submitted_at', 'edit_version', 'note_updated_by', 'note_updated_role', 'note_updated_at']);
        abort_unless($record !== null, 403);
        Gate::forUser($actor)->authorize('viewDetails', [Application::class, $id]);

        return $record;
    }

    public function saveDetailRecord(User $actor, string $id, array $values): void
    {
        $this->detailRecord($actor, $id, true)->forceFill($values)->save();
    }

    private function activeActor(?User $actor): ?User
    {
        return $actor ? User::query()->whereKey($actor->getKey())->where('status', UserStatus::Active->value)
            ->first(['id', 'role', 'status']) : null;
    }

    private function context(?User $actor, string $id): ?Application
    {
        if (! $actor || ! preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/D', $id)) {
            return null;
        }

        return $this->scopedApplications($actor)->whereKey($id)->first(['applications.id', 'applications.company_id', 'applications.job_id']);
    }

    /** @return Builder<Application> */
    private function scopedApplications(User $current): Builder
    {
        $query = Application::query()->whereHas('company');

        return match ($current->role) {
            Role::Admin => $query,
            Role::Staff => $query->whereIn('applications.company_id', $current->assignedCompanies()->select('companies.id')),
            Role::Employer => $query->whereIn('applications.company_id', $current->companies()->select('companies.id')),
        };
    }

    public function fileApplicationIds(User $actor, int $fileId): array
    {
        Gate::forUser($actor)->authorize('viewAny', CandidateFile::class);

        return DB::table('applications')->whereNull('deleted_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('companies')->whereColumn('companies.id', 'applications.company_id')->whereNull('companies.deleted_at'))
            ->where(fn ($query) => $query->where('cv_file_id', $fileId)->orWhere('portfolio_file_id', $fileId))->pluck('id')->all();
    }

    /** System maintenance only; returns metadata IDs, never candidate identity data. */
    public function unattachedFileMetadataIds(): array
    {
        return DB::table('files')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('applications')
            ->whereColumn('applications.cv_file_id', 'files.id')->orWhereColumn('applications.portfolio_file_id', 'files.id'))
            ->pluck('id')->all();
    }

    // Guest submission; all application reads above are scoped by the current actor.
    public function assertMaySubmit(Job $job, ?string $phone = null): void
    {
        abort_unless($job->canReceiveApplications(), 403);
        if ($phone !== null && DB::table('applications')->where('job_id', $job->id)
            ->where('phone_normalized', $phone)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['phone' => __('applications.duplicate')]);
        }
    }
}
