<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyWages\DailyWageGroupResource\Pages;

use App\Filament\Resources\DailyWages\DailyWageGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDailyWageGroups extends ManageRecords
{
    protected static string $resource = DailyWageGroupResource::class;

    protected ?string $subheading = 'Workers who come together under one leader (mukadam). Only the leader is noted; the workers are not registered.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New labour group')];
    }
}
