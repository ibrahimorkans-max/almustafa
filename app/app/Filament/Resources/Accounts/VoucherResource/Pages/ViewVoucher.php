<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\VoucherResource\Pages;

use App\Filament\Resources\Accounts\VoucherResource;
use App\Filament\Support\RequestsVoucherReversal;
use App\Models\Accounts\Voucher;
use Filament\Resources\Pages\ViewRecord;

class ViewVoucher extends ViewRecord
{
    protected static string $resource = VoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RequestsVoucherReversal::action(function (): ?string {
                /** @var Voucher $v */
                $v = $this->record;

                return $v->isReversal() || Voucher::query()->where('reverses_id', $v->id)->exists() ? null : $v->id;
            }),
        ];
    }
}
