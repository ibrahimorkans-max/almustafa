<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\ExpenseResource\Pages;

use App\Domain\Accounts\ExpenseCategories;
use App\Domain\Accounts\Expenses;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\ExpenseResource;
use App\Filament\Support\Units;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\LedgerAccount;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected static ?string $title = 'New expense or income';

    public function mount(): void
    {
        app(ExpenseCategories::class)->installDefaults();
        parent::mount();
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $receipt = $data['receipt'] ?? null;
        if (is_array($receipt)) {
            $receipt = reset($receipt) ?: null;
        }
        try {
            return app(Expenses::class)->record(
                ExpenseCategory::query()->findOrFail($data['expense_category_id']),
                CarbonImmutable::parse($data['entry_date'], 'Asia/Karachi'),
                Units::rupeesToPaise((string) $data['amount']),
                (string) $data['description'],
                $data['via'] === 'cash' ? LedgerAccount::query()->find($data['cash_ledger_id'] ?? null) : null,
                $data['via'] === 'party' ? Party::query()->find($data['party_id'] ?? null) : null,
                $receipt instanceof TemporaryUploadedFile ? (string) $receipt->get() : null,
                (bool) ($data['post'] ?? false),
                filled($data['gate_entry_id'] ?? null) ? GateEntry::query()->find($data['gate_entry_id']) : null,
                (bool) ($data['capitalize_to_stock'] ?? false),
            );
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.amount' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
