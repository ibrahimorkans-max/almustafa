<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pricing\MarkupSettingResource\Pages;

use App\Domain\Pricing\PricingService;
use App\Filament\Resources\Pricing\MarkupSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;

class ManageMarkupSettings extends ManageRecords
{
    protected static string $resource = MarkupSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Set markup')
                ->using(fn (array $data): Model => app(PricingService::class)->setMarkup((string) $data['category'], (int) $data['markup_bp'])),
        ];
    }
}
