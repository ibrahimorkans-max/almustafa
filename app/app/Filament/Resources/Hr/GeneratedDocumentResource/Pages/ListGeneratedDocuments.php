<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\GeneratedDocumentResource\Pages;

use App\Filament\Resources\Hr\GeneratedDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListGeneratedDocuments extends ListRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = GeneratedDocumentResource::class;
}
