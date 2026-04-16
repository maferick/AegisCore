<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PortalPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Reference\Map\Providers\MapServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    PortalPanelProvider::class,
    HorizonServiceProvider::class,
    MapServiceProvider::class,
];
