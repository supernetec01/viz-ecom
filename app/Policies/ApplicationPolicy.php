<?php

namespace App\Policies;

use App\Models\CandidateFile;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApplicationPolicy
{
    public function fileApplicationIds(User $actor, int $fileId): array
    {
        Gate::forUser($actor)->authorize('viewAny', CandidateFile::class);

        return DB::table('applications')->where('cv_file_id', $fileId)->orWhere('portfolio_file_id', $fileId)->pluck('id')->all();
    }

    /** System maintenance only; returns metadata IDs, never candidate identity data. */
    public function unattachedFileMetadataIds(): array
    {
        return DB::table('files')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('applications')
            ->whereColumn('applications.cv_file_id', 'files.id')->orWhereColumn('applications.portfolio_file_id', 'files.id'))
            ->pluck('id')->all();
    }

    // Guest submission only. Reading candidate records remains denied until T30.
    public function assertMaySubmit(Job $job, ?string $phone = null): void
    {
        abort_unless($job->canReceiveApplications(), 403);
        if ($phone !== null && DB::table('applications')->where('job_id', $job->id)
            ->where('phone_normalized', $phone)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['phone' => __('applications.duplicate')]);
        }
    }
}
