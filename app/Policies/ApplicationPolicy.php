<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\CandidateFile;
use App\Models\Job;
use App\Models\User;
use App\Support\FileReadiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApplicationPolicy
{
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
