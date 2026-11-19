<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\QuickBillResource\Pages;

use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Sales\QuickBills;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Sales\QuickBillResource;
use App\Filament\Support\OffersPrintAfterCreate;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateQuickBill extends CreateRecord
{
    use \App\Filament\Concerns\TranslatesLabels;
    use OffersPrintAfterCreate;

    protected string $printType = 'quick_bill';

    protected static string $resource = QuickBillResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(QuickBills::class)->create($data);
        } catch (BusinessRuleViolation|ApprovalDenied $e) {
            throw ValidationException::withMessages(['data.lines' => __($e->getMessage())]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
