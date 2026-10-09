<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\LeaveTypeResource\Pages;

use App\Filament\Resources\Hr\LeaveTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLeaveTypes extends ManageRecords
{
    protected static string $resource = LeaveTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
