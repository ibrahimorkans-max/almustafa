<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\ReimbursementResource\Pages;

use App\Domain\Accounts\ExpenseCategories;
use App\Domain\Hr\Reimbursements;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\ReimbursementResource;
use App\Filament\Support\Units;
use App\Models\Hr\Employee;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** HR enters a claim for an employee, with a receipt per line; a supervisor checks it next. */
class CreateReimbursement extends CreateRecord
{
    protected static string $resource = ReimbursementResource::class;

    protected static ?string $title = 'New staff claim';

    public function mount(): void
    {
        // The category list needs the standard categories on a mill's first claim.
        app(ExpenseCategories::class)->installDefaults();
        parent::mount();
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $lines = [];
        foreach (array_values((array) $data['lines']) as $l) {
            // A single FileUpload holds the file itself; inside a repeater it can still arrive keyed by upload id.
            $receipt = $l['receipt'] ?? null;
            if (is_array($receipt)) {
                $receipt = reset($receipt) ?: null;
            }
            $lines[] = [
                'category_id' => (string) $l['category_id'], 'spent_on' => (string) $l['spent_on'], 'description' => (string) $l['description'],
                'amount_paise' => Units::rupeesToPaise((string) $l['amount']),
                'receipt' => $receipt instanceof TemporaryUploadedFile ? (string) $receipt->get() : null,
            ];
        }
        try {
            return app(Reimbursements::class)->submit(Employee::query()->findOrFail($data['employee_id']), (string) $data['purpose'], $lines);
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.lines' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
