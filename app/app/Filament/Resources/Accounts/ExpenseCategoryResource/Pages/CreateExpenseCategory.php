<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\ExpenseCategoryResource\Pages;

use App\Domain\Accounts\ExpenseCategories;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\ExpenseCategoryResource;
use App\Models\Accounts\LedgerAccount;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateExpenseCategory extends CreateRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ExpenseCategories::class)->create((string) $data['code'], (string) $data['name'], (string) $data['kind'],
                LedgerAccount::query()->findOrFail($data['ledger_account_id']), (bool) ($data['claimable'] ?? false));
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.code' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
