<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\AttendanceLogResource\Pages;

use App\Filament\Resources\Hr\AttendanceLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendanceLogs extends ListRecords
{
    protected static string $resource = AttendanceLogResource::class;
}
