<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use Filament\Resources\Pages\EditRecord;

/** @extends EditRecord<Company> */
class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;
}
