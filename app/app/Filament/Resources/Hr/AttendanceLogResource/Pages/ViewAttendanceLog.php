<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\AttendanceLogResource\Pages;

use App\Filament\Resources\Hr\AttendanceLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAttendanceLog extends ViewRecord
{
    protected static string $resource = AttendanceLogResource::class;
}
