<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\PayAdjustmentResource\Pages;

use App\Domain\Hr\Payroll\PayAdjustmentService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\PayAdjustmentResource;
use App\Filament\Support\Units;
use App\Models\Hr\Employee;
use App\Models\Hr\PayAdjustment;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPayAdjustments extends ListRecords
{
    protected static string $resource = PayAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        $month = fn () => TextInput::make('month')->label('Pay month')->required()->regex('/^\d{4}-(0[1-9]|1[0-2])$/')
            ->default(fn () => CarbonImmutable::now('Asia/Karachi')->format('Y-m'))->placeholder('2026-10');

        return [
            Action::make('request')->label('Add bonus or deduction')->icon('heroicon-o-plus')
                ->visible(fn () => PayAdjustmentResource::allows('hr.payroll.run'))
                ->form([
                    Select::make('employee_id')->label('Employee')->required()->searchable()
                        ->options(fn () => Employee::query()->with('person')->where('status', '!=', Employee::EXITED)->orderBy('code')->get()
                            ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} {$e->name()}"])->all()),
                    $month(),
                    Select::make('code')->label('Item')->required()->live()
                        ->options(collect(PayAdjustment::CODES)->map(fn (array $c) => $c[0])->all())
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            [$name, $type] = PayAdjustment::CODES[$state] ?? [null, null];
                            $set('name', $name);
                            $set('type', $type);
                        }),
                    Select::make('type')->options(PayAdjustment::TYPES)->required(),
                    TextInput::make('name')->label('Shown on payslip as')->required()->maxLength(120),
                    TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(500)
                        ->helperText('For a fine: the act or omission, and that the employee was heard (fines are capped by the payroll settings).'),
                ])
                ->action(function (array $data): void {
                    try {
                        app(PayAdjustmentService::class)->request(Employee::query()->findOrFail($data['employee_id']), $data['month'], $data['type'],
                            $data['code'], $data['name'], Units::rupeesToPaise((string) $data['amount']), $data['reason']);
                        Notification::make()->title('Recorded — waiting for approval.')->success()->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('festival')->label('Festival bonus for all')->icon('heroicon-o-gift')->color('gray')
                ->visible(fn () => PayAdjustmentResource::allows('hr.payroll.run'))
                ->form([
                    $month(),
                    TextInput::make('name')->label('Shown on payslip as')->required()->default('Eid bonus')->maxLength(120),
                    TextInput::make('share')->label('Share of monthly Basic (%)')->required()->numeric()->minValue(1)->maxValue(300)->default(100),
                    TextInput::make('min_service')->label('Least service (months)')->required()->integer()->minValue(0)->default(3),
                    Toggle::make('prorate')->label('Prorate for service under 12 months')->default(true),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(500)->default('Eid ul Fitr bonus'),
                ])
                ->action(function (array $data): void {
                    try {
                        $r = app(PayAdjustmentService::class)->festivalBonus($data['month'], $data['name'], (int) Units::percentToBp((string) $data['share']),
                            (int) $data['min_service'], (bool) $data['prorate'], $data['reason']);
                        Notification::make()->title("{$r['count']} bonuses requested, total Rs ".Units::paiseToRupees($r['total_paise']).' — approve the batch.')
                            ->body($r['skipped'] === [] ? null : 'Not included: '.implode(', ', $r['skipped']))->success()->persistent()->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
