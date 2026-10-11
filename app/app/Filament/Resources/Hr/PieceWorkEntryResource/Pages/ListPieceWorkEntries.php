<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\PieceWorkEntryResource\Pages;

use App\Filament\Resources\Hr\PieceWorkEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListPieceWorkEntries extends ListRecords
{
    protected static string $resource = PieceWorkEntryResource::class;
}
