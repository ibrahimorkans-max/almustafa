<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\ScaleResource\Pages;

use App\Filament\Resources\Gate\ScaleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScale extends CreateRecord
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = ScaleResource::class;
}
