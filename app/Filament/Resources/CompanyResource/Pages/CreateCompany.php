<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Enums\Role;
use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use Filament\Resources\Pages\CreateRecord;

/** @extends CreateRecord<Company> */
class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    protected function afterCreate(): void
    {
        $user = auth()->user();
        if ($user?->role === Role::Staff && $this->record instanceof Company) {
            $this->record->staff()->syncWithoutDetaching([$user->id]);
        }
    }
}
