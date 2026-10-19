<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyWages\DailyWageSheetResource\Pages;

use App\Domain\DailyWages\DailyWages;
use App\Filament\Resources\DailyWages\DailyWageSheetResource;
use App\Models\DailyWages\DailyWageSheet;
use App\Support\Values\Money;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDailyWageSheets extends ListRecords
{
    protected static string $resource = DailyWageSheetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Enter daily wages')->icon('heroicon-o-plus')];
    }

    public function getSubheading(): string
    {
        $today = now('Asia/Karachi');
        $sum = fn (string $from) => (int) DailyWageSheet::query()->whereIn('status', [DailyWageSheet::POSTED, DailyWageSheet::PAID])
            ->where('work_date', '>=', $from)->sum('net_paise');

        return 'Today '.Money::paise($sum($today->toDateString()))->format()
            .' · This month '.Money::paise($sum($today->copy()->startOfMonth()->toDateString()))->format()
            .' · Still owed to groups '.Money::paise(app(DailyWages::class)->owed())->format()
            .'. Casual workers need no registration: enter the group (or one worker), the work, the number of workers and the rate.';
    }
}
