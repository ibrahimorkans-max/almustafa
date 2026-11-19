<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\SalaryVoucherResource\Pages;

use App\Filament\Resources\Hr\SalaryVoucherResource;
use App\Filament\Support\PrintButton;
use Filament\Resources\Pages\ViewRecord;

class ViewSalaryVoucher extends ViewRecord
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = SalaryVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [PrintButton::header('salary_voucher')];
    }
}
