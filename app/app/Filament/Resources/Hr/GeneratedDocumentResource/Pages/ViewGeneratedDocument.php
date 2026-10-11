<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\GeneratedDocumentResource\Pages;

use App\Domain\Documents\Engine\DocumentService;
use App\Filament\Resources\Hr\GeneratedDocumentResource;
use App\Models\Documents\GeneratedDocument;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property GeneratedDocument $record
 */
class ViewGeneratedDocument extends ViewRecord
{
    protected static string $resource = GeneratedDocumentResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(DocumentService::class)->log($this->record, 'view');
    }

    public function getTitle(): string
    {
        return $this->record->displayNumber();
    }
}
