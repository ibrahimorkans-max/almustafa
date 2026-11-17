<?php

declare(strict_types=1);

namespace App\Filament\Resources\Claims\ClaimResource\Pages;

use App\Filament\Resources\Claims\ClaimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClaims extends ListRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = ClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Raise claim'))];
    }
}
