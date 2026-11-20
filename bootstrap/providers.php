<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\FortifyServiceProvider;

return [
    FortifyServiceProvider::class,
    AppServiceProvider::class,
    AppPanelProvider::class,
];
