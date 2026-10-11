<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\PayrollRunResource\Pages;

use App\Domain\Hr\Payroll\PayrollService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\PayrollRunResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPayrollRuns extends ListRecords
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->label('Start month payroll')->icon('heroicon-o-plus')
                ->visible(fn () => PayrollRunResource::allows('hr.payroll.run'))
                ->form([
                    TextInput::make('month')->required()->regex('/^\d{4}-(0[1-9]|1[0-2])$/')->placeholder('2026-10')
                        ->default(fn () => CarbonImmutable::now('Asia/Karachi')->format('Y-m')),
                    Textarea::make('note')->maxLength(500),
                ])
                ->action(function (array $data): void {
                    try {
                        $run = app(PayrollService::class)->open($data['month'], $data['note'] ?? null);
                        $this->redirect(PayrollRunResource::getUrl('view', ['record' => $run]));
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
