<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\SaudaResource\Pages;

use App\Domain\Procurement\SaudaService;
use App\Filament\Resources\Procurement\SaudaResource;
use App\Filament\Support\OffersPrintAfterCreate;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSauda extends CreateRecord
{
    use OffersPrintAfterCreate;

    protected string $printType = 'sauda';

    protected static string $resource = SaudaResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaudaService::class)->create(SaudaResource::toTerms($data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
