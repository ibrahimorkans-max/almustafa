<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\LeaveRequestResource\Pages;

use App\Filament\Resources\Hr\LeaveRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeaveRequests extends ListRecords
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Enter leave')];
    }
}
