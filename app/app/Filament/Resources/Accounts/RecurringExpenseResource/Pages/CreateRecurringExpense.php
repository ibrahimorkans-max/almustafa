<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RecurringExpenseResource\Pages;

use App\Domain\Accounts\Expenses;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\RecurringExpenseResource;
use App\Filament\Support\Units;
use App\Models\Accounts\RecurringExpense;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateRecurringExpense extends CreateRecord
{
    protected static string $resource = RecurringExpenseResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        return self::save(null, $data);
    }

    /** @param  array<string, mixed>  $data */
    public static function save(?RecurringExpense $record, array $data): Model
    {
        $data['amount_paise'] = Units::rupeesToPaise((string) $data['amount']);
        $data['pay_from_ledger_id'] = $data['via'] !== 'party' ? ($data['pay_from_ledger_id'] ?? null) : null;
        $data['party_id'] = $data['via'] === 'party' ? ($data['party_id'] ?? null) : null;
        try {
            return app(Expenses::class)->saveTemplate($record, $data);
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.name' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
