<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\PeriodLockResource\Pages;

use App\Domain\Accounts\PeriodLocks;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\PeriodLockResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPeriodLocks extends ListRecords
{
    protected static string $resource = PeriodLockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lock')->label('Lock a month')->icon('heroicon-o-lock-closed')
                ->visible(fn () => Filament::auth()->user()?->can('period.lock') ?? false)
                ->modalDescription('No voucher can be posted into a locked month. Reopening needs a second person.')
                ->form([TextInput::make('period')->label('Month (YYYY-MM)')->required()->default(now('Asia/Kolkata')->subMonthNoOverflow()->format('Y-m'))])
                ->action(function (array $data): void {
                    try {
                        app(PeriodLocks::class)->lock($data['period']);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title("{$data['period']} locked")->success()->send();
                }),
        ];
    }
}
