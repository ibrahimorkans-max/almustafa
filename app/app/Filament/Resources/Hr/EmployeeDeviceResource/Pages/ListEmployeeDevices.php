<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\EmployeeDeviceResource\Pages;

use App\Filament\Resources\Hr\EmployeeDeviceResource;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeDevices extends ListRecords
{
    protected static string $resource = EmployeeDeviceResource::class;
}
