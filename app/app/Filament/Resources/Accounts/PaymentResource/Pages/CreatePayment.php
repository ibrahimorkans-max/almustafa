<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\PaymentResource\Pages;

use App\Domain\Accounts\PaymentService;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\PaymentResource;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\PurchaseBill;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    protected static ?string $title = 'Request payment';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $amount = Units::rupeesToPaise((string) $data['amount']);

        // Settle the selected bills oldest-due first, up to the amount.
        $allocations = [];
        $left = $amount;
        $bills = PurchaseBill::query()->whereIn('id', (array) ($data['bills'] ?? []))->orderBy('due_date')->get();
        foreach ($bills as $bill) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, app(PurchaseBillService::class)->outstanding($bill));
            if ($take > 0) {
                $allocations[$bill->id] = $take;
                $left -= $take;
            }
        }

        try {
            return app(PaymentService::class)->request(
                Party::query()->findOrFail($data['party_id']),
                (string) $data['mode'],
                LedgerAccount::query()->findOrFail($data['from_ledger_id']),
                $amount,
                CarbonImmutable::parse($data['payment_date'], 'Asia/Karachi'),
                $allocations,
                $data['narration'] ?? null,
            );
        } catch (BusinessRuleViolation|ApprovalDenied $e) {
            throw ValidationException::withMessages(['data.amount' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
