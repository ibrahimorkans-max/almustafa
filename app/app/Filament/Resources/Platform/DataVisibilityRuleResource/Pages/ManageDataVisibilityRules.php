<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform\DataVisibilityRuleResource\Pages;

use App\Filament\Resources\Platform\DataVisibilityRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDataVisibilityRules extends ManageRecords
{
    protected static string $resource = DataVisibilityRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
