<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Models\Plan;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @extends EditRecord<Plan> */
class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Gate::authorize('update', $record);
        $record->fill($data)->save();

        return $record;
    }
}
