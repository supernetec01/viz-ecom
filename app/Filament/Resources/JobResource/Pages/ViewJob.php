<?php

namespace App\Filament\Resources\JobResource\Pages;

use App\Filament\Resources\JobResource;
use App\Models\Job;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<Job> */
class ViewJob extends ViewRecord
{
    protected static string $resource = JobResource::class;

    protected function getHeaderActions(): array
    {
        return [JobResource::submissionAction(), EditAction::make()];
    }
}
