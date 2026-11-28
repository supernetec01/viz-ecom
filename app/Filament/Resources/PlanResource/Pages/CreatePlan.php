<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Models\Plan;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @extends CreateRecord<Plan> */
class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        Gate::authorize('create', Plan::class);

        return Plan::create($data);
    }
}
