<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyWages\DailyWageCategoryResource\Pages;

use App\Filament\Resources\DailyWages\DailyWageCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDailyWageCategories extends ManageRecords
{
    protected static string $resource = DailyWageCategoryResource::class;

    protected ?string $subheading = 'The kinds of casual work and their rate (per person per day or hour, or per bag, maund, ton, trip, piece). The rate is filled in on new wage sheets.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New work category')];
    }
}
