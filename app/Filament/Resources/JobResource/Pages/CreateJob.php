<?php

namespace App\Filament\Resources\JobResource\Pages;

use App\Filament\Resources\JobResource;
use App\Models\Job;
use App\Models\User;
use App\Services\ManageJobs;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

/** @extends CreateRecord<Job> */
class CreateJob extends CreateRecord
{
    protected static string $resource = JobResource::class;

    protected function handleRecordCreation(array $data): Job
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        try {
            return app(ManageJobs::class)->create($user, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($errors, $field) => ['data.'.$field => $errors])->all());
        }
    }
}
