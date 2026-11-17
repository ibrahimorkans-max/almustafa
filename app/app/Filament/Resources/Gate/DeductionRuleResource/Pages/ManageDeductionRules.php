<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\DeductionRuleResource\Pages;

use App\Filament\Resources\Gate\DeductionRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDeductionRules extends ManageRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = DeductionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
