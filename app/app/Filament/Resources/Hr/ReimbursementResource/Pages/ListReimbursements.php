<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\ReimbursementResource\Pages;

use App\Filament\Resources\Hr\ReimbursementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReimbursements extends ListRecords
{
    protected static string $resource = ReimbursementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New claim')];
    }
}
