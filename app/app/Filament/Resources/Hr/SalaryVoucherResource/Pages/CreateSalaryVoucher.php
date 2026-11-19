<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\SalaryVoucherResource\Pages;

use App\Domain\Hr\SalaryVouchers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\SalaryVoucherResource;
use App\Filament\Support\OffersPrintAfterCreate;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSalaryVoucher extends CreateRecord
{
    use \App\Filament\Concerns\TranslatesLabels;
    use OffersPrintAfterCreate;

    protected string $printType = 'salary_voucher';

    protected static string $resource = SalaryVoucherResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SalaryVouchers::class)->create($data);
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.gross' => __($e->getMessage())]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
