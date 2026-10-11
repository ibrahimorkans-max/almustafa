<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\AllowanceRuleResource\Pages;

use App\Filament\Resources\Hr\AllowanceRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAllowanceRules extends ManageRecords
{
    protected static string $resource = AllowanceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateFormDataUsing(fn (array $data) => AllowanceRuleResource::mutate($data))];
    }
}
