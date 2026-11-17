<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform\BankEmailTemplateResource\Pages;

use App\Filament\Resources\Platform\BankEmailTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBankEmailTemplates extends ManageRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = BankEmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
