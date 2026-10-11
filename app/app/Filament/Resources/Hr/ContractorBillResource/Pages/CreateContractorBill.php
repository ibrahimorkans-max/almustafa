<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\ContractorBillResource\Pages;

use App\Domain\Hr\ContractorBilling;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\ContractorBillResource;
use App\Filament\Support\Units;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Payroll prepares a bill from the records; someone with hr.payroll.approve approves it. */
class CreateContractorBill extends CreateRecord
{
    protected static string $resource = ContractorBillResource::class;

    protected static ?string $title = 'Prepare contractor bill';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $rate = $data['basis'] === 'piece'
            ? collect((array) ($data['rates'] ?? []))->mapWithKeys(fn (array $r) => [(string) $r['unit'] => Units::rupeesToPaise((string) $r['rate'])])->all()
            : Units::rupeesToPaise((string) ($data['rate'] ?? '0'));
        try {
            return app(ContractorBilling::class)->prepare(
                Party::query()->findOrFail($data['party_id']),
                CarbonImmutable::parse($data['period_from'], 'Asia/Karachi'),
                CarbonImmutable::parse($data['period_to'], 'Asia/Karachi'),
                (string) $data['basis'],
                $rate,
                Units::percentToBp((string) ($data['sales_tax'] ?? '')) ?? 0,
                Units::percentToBp((string) ($data['wht'] ?? '')) ?? 0,
                filled($data['note'] ?? null) ? (string) $data['note'] : null,
            );
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.party_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
