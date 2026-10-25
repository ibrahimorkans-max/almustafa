<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Hr\Payroll\PayrollSettings;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\HrScope;
use App\Filament\Support\Units;
use App\Models\Hr\HrSetting;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Payroll rates and statutory figures in force, each with its source and date. A change is a new
 * dated value; months already paid keep the value they used. Unconfirmed defaults are marked.
 */
class PayrollSettingsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Payroll settings';

    protected static ?string $title = 'Payroll settings and statutory rates';

    protected static ?string $slug = 'hr/payroll-settings';

    protected static ?int $navigationSort = 72;

    protected static string $view = 'filament.pages.payroll-settings';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->hasPermission('hr.payroll.run') || $user->hasPermission('hr.payroll.approve'));
    }

    /** @return list<array{key: string, label: string, value: string, default: bool, from: string|null, note: string|null}> */
    public function rows(): array
    {
        $settings = app(PayrollSettings::class);
        $today = CarbonImmutable::now('Asia/Karachi');
        $rows = [];
        foreach (PayrollSettings::DEFAULTS as $key => [, $label]) {
            $current = HrSetting::query()->where('key', $key)->where('effective_from', '<=', $today->toDateString())
                ->orderByDesc('effective_from')->orderByDesc('created_at')->first();
            $rows[] = [
                'key' => $key, 'label' => $label, 'value' => self::display($key, $settings->get($key, $today)),
                'default' => $current === null, 'from' => $current?->effective_from->format('d M Y'), 'note' => $current?->note,
            ];
        }

        return $rows;
    }

    public static function display(string $key, mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            str_ends_with($key, '_paise') => Units::paiseToRupees((int) $value),
            str_ends_with($key, '_bp') => Units::bpToPercent((int) $value),
            $key === 'income_tax_slabs' => implode('; ', array_map(fn (array $s) => sprintf('from %s: %s + %s',
                Units::paiseToRupees($s[0]), Units::paiseToRupees($s[1]), Units::bpToPercent($s[2])), (array) $value)),
            default => (string) $value,
        };
    }

    public function changeAction(): Action
    {
        return Action::make('change')->label('Change')->size('sm')
            ->visible(fn () => Filament::auth()->user()?->can('hr.payroll.approve') ?? false)
            ->modalHeading(fn (array $arguments) => PayrollSettings::DEFAULTS[$arguments['key']][1] ?? 'Change')
            ->form(function (array $arguments): array {
                $key = (string) $arguments['key'];
                $default = PayrollSettings::DEFAULTS[$key][0];
                $value = match (true) {
                    is_bool($default) => Toggle::make('value')->label('Yes'),
                    $key === 'income_tax_slabs' => Repeater::make('value')->label('Slabs (taxable income per year)')->columns(3)->minItems(1)
                        ->schema([
                            TextInput::make('from')->label('From (Rs)')->required()->regex(Units::DECIMAL_2),
                            TextInput::make('fixed')->label('Fixed tax (Rs)')->required()->regex(Units::DECIMAL_2),
                            TextInput::make('rate')->label('Rate on the part above (%)')->required()->numeric()->minValue(0)->maxValue(100),
                        ]),
                    str_ends_with($key, '_paise') => TextInput::make('value')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
                    str_ends_with($key, '_bp') => TextInput::make('value')->label('Percent')->required()->numeric()->minValue(0)->maxValue(1000),
                    default => TextInput::make('value')->required()->integer()->minValue(0),
                };

                return [
                    $value,
                    DatePicker::make('from')->label('In force from')->required()->default(now('Asia/Karachi')->startOfMonth()),
                    Textarea::make('note')->label('Source (notification, Finance Act …)')->required()->minLength(3)->maxLength(500),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $key = (string) $arguments['key'];
                $value = match (true) {
                    is_bool(PayrollSettings::DEFAULTS[$key][0]) => (bool) $data['value'],
                    $key === 'income_tax_slabs' => array_values(array_map(fn (array $s) => [
                        Units::rupeesToPaise((string) $s['from']), Units::rupeesToPaise((string) $s['fixed']), (int) Units::percentToBp((string) $s['rate']),
                    ], (array) $data['value'])),
                    str_ends_with($key, '_paise') => Units::rupeesToPaise((string) $data['value']),
                    str_ends_with($key, '_bp') => (int) Units::percentToBp((string) $data['value']),
                    default => (int) $data['value'],
                };
                try {
                    app(PayrollSettings::class)->set($key, $value, CarbonImmutable::parse($data['from'], 'Asia/Karachi'), (string) $data['note']);
                    Notification::make()->title('New value recorded.')->success()->send();
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
