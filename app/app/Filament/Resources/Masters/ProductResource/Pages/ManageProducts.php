<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\ProductResource\Pages;

use App\Filament\Resources\Masters\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProducts extends ManageRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
