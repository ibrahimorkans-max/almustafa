<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Hr\SalaryVouchers;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Hr\SalaryVoucherResource\Pages;
use App\Filament\Resources\Sales\QuickBillResource;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\Advance;
use App\Models\Hr\Employee;
use App\Models\Hr\SalaryVoucher;
use App\Support\Locales;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Salary vouchers (App\Domain\Hr\SalaryVouchers): an employee's salary for a month — or a bonus / other payment —
 * paid on the spot: the salary filled from the employee's salary, an advance instalment and other deductions taken
 * off, paid in cash, by cheque or bank, and printed for the employee's signature.
 */
class SalaryVoucherResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = SalaryVoucher::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Salary vouchers';

    protected static ?string $modelLabel = 'salary voucher';

    protected static ?string $slug = 'hr/salary-vouchers';

    protected static ?int $navigationSort = 35;

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can(SalaryVouchers::RIGHT) ?? false;
    }

    public static function canCreate(): bool
    {
        return SalaryVouchers::allowed();
    }

    /** Fills the salary, the advance instalment and the warning for the chosen employee and month. */
    public static function fill(Get $get, Set $set): void
    {
        $employee = filled($get('employee_id')) ? Employee::query()->find($get('employee_id')) : null;
        if ($employee === null || ! preg_match('/^\d{4}-\d{2}$/', (string) $get('month'))) {
            return;
        }
        $s = SalaryVouchers::suggestion($employee, (string) $get('month'), filled($get('days')) ? (string) $get('days') : null);
        if ($get('kind') === 'salary') {
            $set('gross', $s['salary_paise'] > 0 ? Money::paise($s['salary_paise'])->toDecimal() : null);
        }
        $set('advance_id', $s['advance_id']);
        $set('advance', $s['instalment_paise'] > 0 ? Money::paise($s['instalment_paise'])->toDecimal() : null);
    }

    private static function paise(mixed $v): int
    {
        try {
            return blank($v) ? 0 : Money::rupees((string) $v)->paise;
        } catch (Throwable) {
            return 0;
        }
    }

    public static function form(Form $form): Form
    {
        $months = collect(range(0, 13))->mapWithKeys(fn (int $i) => [($m = now('Asia/Karachi')->startOfMonth()->subMonths($i))->format('Y-m') => $m->format('F Y')])->all();

        return $form->columns(1)->schema([
            Section::make(__('Employee and month'))->columns(['default' => 1, 'md' => 4])->schema([
                Select::make('employee_id')->label(__('Employee'))->required()->searchable()->live()->columnSpan(2)
                    ->options(fn () => Employee::query()->with('person')->whereIn('status', ['active', 'on_notice'])->orderBy('code')->get()
                        ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} — {$e->name()}"]))
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fill($get, $set)),
                Select::make('kind')->label(__('Paying'))->required()->default('salary')->live()
                    ->options(Locales::options(SalaryVoucher::KINDS)),
                Select::make('month')->label(__('Month'))->options($months)->default(now('Asia/Karachi')->subMonth()->format('Y-m'))->live()
                    ->required(fn (Get $get) => $get('kind') === 'salary')
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fill($get, $set)),
                Placeholder::make('salary_info')->label(__('Salary on record'))->columnSpanFull()
                    ->content(function (Get $get) {
                        $employee = filled($get('employee_id')) ? Employee::query()->find($get('employee_id')) : null;
                        if ($employee === null || blank($get('month'))) {
                            return '—';
                        }
                        $s = SalaryVouchers::suggestion($employee, (string) $get('month'));
                        $info = match ($s['basis']) {
                            null => __('No salary set for this employee yet: type the amount (or set it on the employee).'),
                            'daily' => __('Daily wage :amount — type the days worked.', ['amount' => Money::paise((int) $s['daily_paise'])->format()]),
                            default => __('Monthly salary :amount', ['amount' => Money::paise($s['salary_paise'])->format()]),
                        };
                        if ($s['paid'] && $get('kind') === 'salary') {
                            $info .= ' — '.__('ALREADY PAID for this month.');
                        }

                        return $info;
                    }),
                TextInput::make('days')->label(__('Days worked'))->numeric()->minValue(0)->maxValue(31)->live(onBlur: true)
                    ->visible(function (Get $get) {
                        $employee = filled($get('employee_id')) ? Employee::query()->find($get('employee_id')) : null;

                        return $employee !== null && filled($get('month')) && SalaryVouchers::suggestion($employee, (string) $get('month'))['basis'] === 'daily';
                    })
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fill($get, $set)),
            ]),
            Section::make(__('Amount'))->columns(['default' => 1, 'md' => 4])->schema([
                TextInput::make('gross')->label(__('Salary / amount (Rs)'))->required()->regex(Units::DECIMAL_2)->prefix('Rs')->live(onBlur: true),
                Select::make('advance_id')->label(__('Advance / loan'))->live()
                    ->options(fn (Get $get) => filled($get('employee_id')) ? Advance::query()->where('employee_id', $get('employee_id'))
                        ->where('status', Advance::DISBURSED)->get()->filter(fn (Advance $a) => $a->balancePaise() > 0)
                        ->mapWithKeys(fn (Advance $a) => [$a->id => "{$a->number} · ".__('balance').' '.Money::paise($a->balancePaise())->format()])->all() : []),
                TextInput::make('advance')->label(__('Instalment taken off (Rs)'))->regex(Units::DECIMAL_2)->prefix('Rs')->live(onBlur: true)
                    ->requiredWith('advance_id'),
                TextInput::make('other_deduction')->label(__('Other deduction (Rs)'))->regex(Units::DECIMAL_2)->prefix('Rs')->live(onBlur: true),
                TextInput::make('other_deduction_note')->label(__('Deduction for'))->maxLength(120)->placeholder(__('fine, canteen, absence …'))->columnSpan(2),
                Placeholder::make('net')->label(__('Net paid'))->columnSpan(2)
                    ->content(fn (Get $get) => new HtmlString('<span style="font-size:1.5rem;font-weight:700" dir="ltr">'
                        .e(Money::paise(self::paise($get('gross')) - self::paise($get('advance')) - self::paise($get('other_deduction')))->format()).'</span>')),
            ]),
            Section::make(__('Payment'))->columns(['default' => 1, 'md' => 4])->schema([
                Select::make('mode')->label(__('How'))->required()->default('cash')->options(Locales::options(SalaryVoucher::MODES)),
                Select::make('ledger_id')->label(__('Paid from'))->required()->options(fn () => QuickBillResource::cashBankOptions())
                    ->default(fn () => LedgerAccount::query()->where('system_key', 'cash')->value('id')),
                TextInput::make('reference')->label(__('Cheque / transaction no.'))->maxLength(60),
                DateTimePicker::make('paid_at')->label(__('Date and time'))->required()->seconds(false)->default(now('Asia/Karachi'))->timezone('Asia/Karachi'),
                Textarea::make('note')->label(__('Note'))->maxLength(1000)->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee.person'))
            ->columns([
                TextColumn::make('number')->label(__('Voucher no.'))->searchable()->sortable(),
                TextColumn::make('paid_at')->label(__('Date and time'))->dateTime('d M Y, h:i A', 'Asia/Karachi')->sortable(),
                TextColumn::make('employee')->label(__('Employee'))->state(fn (SalaryVoucher $r) => "{$r->employee->code} — {$r->employee->name()}"),
                TextColumn::make('kind')->label(__('Paying'))->badge()->formatStateUsing(fn (string $state, SalaryVoucher $r) => __(SalaryVoucher::KINDS[$state] ?? $state)
                    .($r->month ? ' '.CarbonImmutable::createFromFormat('!Y-m', $r->month)->format('M Y') : '')),
                TextColumn::make('gross_paise')->label(__('Salary'))->alignEnd()->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextColumn::make('deductions')->label(__('Deductions'))->alignEnd()->state(fn (SalaryVoucher $r) => $r->advance_paise + $r->other_deduction_paise)
                    ->formatStateUsing(fn (int $state) => $state > 0 ? Units::paiseToRupees($state) : '—'),
                TextColumn::make('net_paise')->label(__('Net paid'))->alignEnd()->weight('bold')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextColumn::make('mode')->label(__('How'))->formatStateUsing(fn (string $state) => __(SalaryVoucher::MODES[$state] ?? $state)),
            ])
            ->defaultSort('paid_at', 'desc')
            ->filters([
                SelectFilter::make('employee_id')->label(__('Employee'))->searchable()
                    ->options(fn () => Employee::query()->with('person')->orderBy('code')->get()->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} — {$e->name()}"])->all()),
                SelectFilter::make('month')->label(__('Month'))->options(fn () => SalaryVoucher::query()->whereNotNull('month')->distinct()->orderByDesc('month')->pluck('month', 'month')->all()),
                SelectFilter::make('kind')->label(__('Paying'))->options(Locales::options(SalaryVoucher::KINDS)),
            ])
            ->actions([ViewAction::make(), PrintButton::row('salary_voucher')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make()->columns(4)->schema([
                TextEntry::make('number')->label(__('Voucher no.')),
                TextEntry::make('paid_at')->label(__('Date and time'))->dateTime('d M Y, h:i A', 'Asia/Karachi'),
                TextEntry::make('employee')->label(__('Employee'))->state(fn (SalaryVoucher $r) => "{$r->employee->code} — {$r->employee->name()}"),
                TextEntry::make('kind')->label(__('Paying'))->formatStateUsing(fn (string $state, SalaryVoucher $r) => __(SalaryVoucher::KINDS[$state] ?? $state)
                    .($r->month ? ' '.CarbonImmutable::createFromFormat('!Y-m', $r->month)->format('F Y') : '').($r->days ? " ({$r->days} ".__('days').')' : '')),
                TextEntry::make('gross_paise')->label(__('Salary / amount'))->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextEntry::make('advance_paise')->label(__('Advance instalment'))->formatStateUsing(fn (int $state, SalaryVoucher $r) => Units::paiseToRupees($state).($r->advance ? " ({$r->advance->number})" : '')),
                TextEntry::make('other_deduction_paise')->label(__('Other deduction'))->formatStateUsing(fn (int $state, SalaryVoucher $r) => Units::paiseToRupees($state).($r->other_deduction_note ? " — {$r->other_deduction_note}" : '')),
                TextEntry::make('net_paise')->label(__('Net paid'))->weight('bold')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextEntry::make('mode')->label(__('How'))->formatStateUsing(fn (string $state, SalaryVoucher $r) => __(SalaryVoucher::MODES[$state] ?? $state).($r->reference ? " · {$r->reference}" : '')),
                TextEntry::make('ledger.name')->label(__('Paid from')),
                TextEntry::make('note')->label(__('Note'))->placeholder('—')->columnSpan(2),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalaryVouchers::route('/'),
            'create' => Pages\CreateSalaryVoucher::route('/create'),
            'view' => Pages\ViewSalaryVoucher::route('/{record}'),
        ];
    }
}
