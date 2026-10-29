<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RecurringExpenseResource\Pages;

use App\Filament\Resources\Accounts\ExpenseResource;
use App\Filament\Resources\Accounts\RecurringExpenseResource;
use App\Models\Accounts\RecurringExpense;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRecurringExpense extends EditRecord
{
    protected static string $resource = RecurringExpenseResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + ['amount' => bcdiv((string) $data['amount_paise'], '100', 2), 'via' => ExpenseResource::viaFor($data['pay_from_ledger_id'] ?? null, $data['party_id'] ?? null)];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return CreateRecurringExpense::save($record instanceof RecurringExpense ? $record : null, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
