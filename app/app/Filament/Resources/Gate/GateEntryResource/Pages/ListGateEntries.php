<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\GateEntryResource\Pages;

use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Support\QuickLabTest;
use App\Filament\Widgets\LiveWeightBoard;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGateEntries extends ListRecords
{
    protected static string $resource = GateEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Vehicle arrived'), QuickLabTest::make()];
    }

    /** The weighbridge reading above the list, so the operator sees the weight while working. */
    protected function getHeaderWidgets(): array
    {
        return LiveWeightBoard::canView() ? [LiveWeightBoard::class] : [];
    }
}
