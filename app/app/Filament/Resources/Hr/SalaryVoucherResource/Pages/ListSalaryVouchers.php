<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\SalaryVoucherResource\Pages;

use App\Filament\Resources\Hr\SalaryVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalaryVouchers extends ListRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = SalaryVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New salary voucher'))->icon('heroicon-o-plus')];
    }
}
