<?php

namespace App\Filament\Resources\JobResource\Pages;

use App\Filament\Resources\JobResource;
use App\Models\Job;
use App\Models\User;
use App\Services\ManageJobs;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** @extends EditRecord<Job> */
class EditJob extends EditRecord
{
    protected static string $resource = JobResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()->label(__('ui.jobs.preview'))];
    }

    protected function handleRecordUpdate(Model $record, array $data): Job
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $record instanceof Job, 403);
        try {
            return app(ManageJobs::class)->update($user, $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($errors, $field) => ['data.'.$field => $errors])->all());
        }
    }
}
