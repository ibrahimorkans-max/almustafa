<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Hr\Payroll\AdvanceService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\AdvanceResource\Pages;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\Advance;
use App\Models\Hr\Employee;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Salary advances and staff loans: request, approval by a second person, disbursement, recovery. */
class AdvanceResource extends Resource
{
    protected static ?string $model = Advance::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Advances and loans';

    protected static ?string $modelLabel = 'advance';

    protected static ?int $navigationSort = 30;

    public static function getNavigationBadge(): ?string
    {
        $n = Advance::query()->where('status', Advance::REQUESTED)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('employee_id')->label('Employee')->required()->searchable()
                ->options(fn () => Employee::query()->with('person')->where('status', '!=', Employee::EXITED)->orderBy('code')->get()
                    ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} {$e->name()}"])->all()),
            Select::make('kind')->options(Advance::KINDS)->default('advance')->required(),
            TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
            TextInput::make('installment')->label('Monthly recovery (Rs)')->required()->regex(Units::DECIMAL_2),
            TextInput::make('start_month')->label('First recovery month')->required()->regex('/^\d{4}-(0[1-9]|1[0-2])$/')
                ->default(fn () => CarbonImmutable::now('Asia/Karachi')->format('Y-m'))->placeholder('2026-10'),
            Textarea::make('reason')->required()->maxLength(500)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $rs = fn (?int $state) => Units::paiseToRupees($state);
        $can = fn (string $p): bool => Filament::auth()->user() instanceof LoginAccount && Filament::auth()->user()->hasPermission($p);
        $run = fn (callable $do, string $ok) => function (Advance $record, array $data = []) use ($do, $ok): void {
            try {
                $do($record, $data);
                Notification::make()->title($ok)->success()->send();
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };
        $svc = fn () => app(AdvanceService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.person'])->withSum('recoveries', 'amount_paise'))
            ->columns([
                TextColumn::make('number')->searchable()->placeholder('—'),
                TextColumn::make('employee.code')->label('Employee')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Name'),
                TextColumn::make('kind')->formatStateUsing(fn (string $state) => Advance::KINDS[$state] ?? $state),
                TextColumn::make('amount_paise')->label('Amount')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('installment_paise')->label('Per month')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('balance')->label('Balance')->alignEnd()
                    ->state(fn (Advance $r) => in_array($r->status, [Advance::DISBURSED, Advance::CLOSED], true)
                        ? $rs($r->amount_paise - (int) $r->getAttribute('recoveries_sum_amount_paise')) : '—'),
                TextColumn::make('start_month')->label('From'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    Advance::REQUESTED => 'warning', Advance::DISBURSED => 'info', Advance::CLOSED => 'success', Advance::REJECTED => 'danger', default => 'gray',
                }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->options(array_combine(
                [Advance::REQUESTED, Advance::APPROVED, Advance::DISBURSED, Advance::CLOSED, Advance::REJECTED],
                ['Waiting for approval', 'Approved (to pay out)', 'Being recovered', 'Closed', 'Refused'],
            ))])
            ->actions([
                ViewAction::make(),
                Action::make('voucher')->icon('heroicon-o-printer')->color('gray')
                    ->visible(fn (Advance $r) => in_array($r->status, [Advance::DISBURSED, Advance::CLOSED], true) && DocumentActions::allowed())
                    ->form(DocumentActions::form('advance_voucher'))
                    ->action(fn (Advance $record, array $data) => DocumentActions::run('advance_voucher', $record->id, $data)),
                Action::make('approve')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Advance $r) => $r->status === Advance::REQUESTED && $can('hr.payroll.approve'))
                    ->form([Textarea::make('note')->maxLength(500)])
                    ->action($run(fn (Advance $r, array $d) => $svc()->approve($r, $d['note'] ?? null), 'Advance approved.')),
                Action::make('reject')->label('Refuse')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Advance $r) => $r->status === Advance::REQUESTED && $can('hr.payroll.approve'))
                    ->form([Textarea::make('note')->label('Reason')->required()->minLength(5)->maxLength(500)])
                    ->action($run(fn (Advance $r, array $d) => $svc()->reject($r, $d['note']), 'Advance refused.')),
                Action::make('disburse')->label('Pay out')->icon('heroicon-o-banknotes')->color('primary')
                    ->visible(fn (Advance $r) => $r->status === Advance::APPROVED && $can('hr.payroll.run'))
                    ->form([
                        Select::make('from')->label('Paid from')->required()
                            ->options(fn () => LedgerAccount::query()->whereIn('kind', ['cash', 'bank'])->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                        DatePicker::make('date')->required()->default(now('Asia/Karachi'))->maxDate(now('Asia/Karachi')),
                    ])
                    ->action($run(fn (Advance $r, array $d) => $svc()->disburse($r, LedgerAccount::query()->findOrFail($d['from']), CarbonImmutable::parse($d['date'], 'Asia/Karachi')), 'Paid out and posted.')),
                Action::make('repay')->label('Cash repayment')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                    ->visible(fn (Advance $r) => $r->status === Advance::DISBURSED && $can('hr.payroll.run'))
                    ->form([
                        TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
                        Select::make('into')->label('Received into')->required()
                            ->options(fn () => LedgerAccount::query()->whereIn('kind', ['cash', 'bank'])->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                        DatePicker::make('date')->required()->default(now('Asia/Karachi'))->maxDate(now('Asia/Karachi')),
                        Textarea::make('note')->required()->maxLength(500),
                    ])
                    ->action($run(fn (Advance $r, array $d) => $svc()->repay($r, Units::rupeesToPaise((string) $d['amount']),
                        LedgerAccount::query()->findOrFail($d['into']), CarbonImmutable::parse($d['date'], 'Asia/Karachi'), $d['note']), 'Repayment recorded.')),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $rs = fn (?int $state) => Units::paiseToRupees($state);

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('employee.code')->label('Employee')
                    ->formatStateUsing(fn (Advance $record) => "{$record->employee->code} {$record->employee->name()}"),
                TextEntry::make('kind')->formatStateUsing(fn (string $state) => Advance::KINDS[$state] ?? $state),
                TextEntry::make('status')->badge(),
                TextEntry::make('amount_paise')->label('Amount')->formatStateUsing($rs),
                TextEntry::make('installment_paise')->label('Monthly recovery')->formatStateUsing($rs),
                TextEntry::make('balance')->label('Balance')->state(fn (Advance $r) => $rs($r->balancePaise())),
                TextEntry::make('start_month')->label('First recovery'),
                TextEntry::make('reason')->columnSpan(2),
                TextEntry::make('decision_note')->label('Decision note')->placeholder('—')->columnSpan(2),
            ]),
            Section::make('Recovery schedule')->schema([
                RepeatableEntry::make('schedule')->hiddenLabel()->columns(4)
                    ->state(fn (Advance $r) => in_array($r->status, [Advance::DISBURSED, Advance::CLOSED], true) ? app(AdvanceService::class)->schedule($r) : [])
                    ->schema([
                        TextEntry::make('month'),
                        TextEntry::make('planned_paise')->label('Planned')->formatStateUsing($rs),
                        TextEntry::make('recovered_paise')->label('Recovered')->formatStateUsing($rs),
                        TextEntry::make('balance_after_paise')->label('Balance after')->formatStateUsing($rs),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdvances::route('/'),
            'create' => Pages\CreateAdvance::route('/create'),
            'view' => Pages\ViewAdvance::route('/{record}'),
        ];
    }
}
