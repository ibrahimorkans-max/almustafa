<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\WeighmentCheckResource\Pages;

use App\Filament\Resources\Gate\WeighmentCheckResource;
use Filament\Resources\Pages\ListRecords;

class ListWeighmentChecks extends ListRecords
{
    protected static string $resource = WeighmentCheckResource::class;

    protected ?string $subheading = 'Every recorded weight, how it was taken and its evidence. Mark each one OK, or query it with a note. Nobody checks a weight they recorded.';
}
