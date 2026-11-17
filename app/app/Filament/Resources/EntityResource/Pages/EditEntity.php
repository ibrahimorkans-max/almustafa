<?php

declare(strict_types=1);

namespace App\Filament\Resources\EntityResource\Pages;

use App\Filament\Resources\EntityResource;
use Filament\Resources\Pages\EditRecord;

class EditEntity extends EditRecord
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = EntityResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
