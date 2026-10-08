<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\LedgerAccountResource\Pages;

use App\Filament\Resources\Accounts\LedgerAccountResource;
use App\Models\Accounts\AccountGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLedgerAccounts extends ManageRecords
{
    protected static string $resource = LedgerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateFormDataUsing(function (array $data): array {
                $data['entity_id'] = AccountGroup::query()->whereKey($data['group_id'])->value('entity_id');

                return $data;
            }),
        ];
    }
}
