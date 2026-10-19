<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyWages;

use App\Domain\DailyWages\DailyWages;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\DailyWages\DailyWageSheetResource\Pages;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\DailyWages\DailyWageCategory;
use App\Models\DailyWages\DailyWageGroup;
use App\Models\DailyWages\DailyWageSheet;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily wages: one sheet per day of work of a labour group (or one worker). The pay is worked out
 * from the workers, the quantity and the rate, booked as a direct expense and paid in cash, from the
 * bank, or later. No registration of the workers is needed.
 */
class DailyWageSheetResource extends Resource
{
    protected static ?string $model = DailyWageSheet::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Daily wages';

    protected static ?string $navigationLabel = 'Daily wages';

    protected static ?string $modelLabel = 'daily wage sheet';

    protected static ?string $slug = 'daily-wages';

    protected static ?int $navigationSort = 1;

    public const PAY = ['draft' => 'Save as draft (book later)', 'cash' => 'Paid now in cash', 'bank' => 'Paid now from the bank', 'later' => 'Book now, pay later'];

    public static function canPay(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('wages.daily.pay');
    }

    /** @return array<string, string> */
    public static function payFromOptions(): array
    {
        return LedgerAccount::query()->whereIn('kind', ['cash', 'bank'])->where('status', 'active')->orderBy('kind')->orderBy('name')
            ->get()->mapWithKeys(fn (LedgerAccount $l) => [$l->id => $l->name])->all();
    }

    public static function form(Form $form): Form
    {
        $total = function (Get $get): string {
            $category = DailyWageCategory::query()->find($get('category_id'));
            if ($category === null || ! is_numeric($get('rate')) || ! is_numeric($get('workers')) || ! is_numeric($get('quantity'))) {
                return '—';
            }
            $gross = DailyWages::gross($category, (int) $get('workers'), (string) $get('quantity'), Units::rupeesToPaise((string) $get('rate')));
            $net = $gross + Units::rupeesToPaise((string) ($get('extra') ?: '0')) - Units::rupeesToPaise((string) ($get('deduction') ?: '0'));

            return Money::paise($gross)->format().' wages'.(filled($get('extra')) || filled($get('deduction')) ? ' → '.Money::paise($net)->format().' to pay' : '');
        };
        $unit = fn (Get $get): string => (string) (DailyWageCategory::query()->whereKey($get('category_id'))->value('unit') ?? 'day');

        return $form->columns(2)->schema([
            DatePicker::make('work_date')->label('Work date')->required()->default(now('Asia/Karachi')->toDateString())->maxDate(now('Asia/Karachi')),
            Radio::make('who')->label('Who worked')->options(['group' => 'A labour group', 'single' => 'One worker'])->default('group')->inline()->live(),
            Select::make('group_id')->label('Labour group')->searchable()->live()->required(fn (Get $get) => $get('who') === 'group')
                ->visible(fn (Get $get) => $get('who') !== 'single')
                ->options(fn () => DailyWageGroup::query()->where('status', 'active')->orderBy('name')->get()->mapWithKeys(fn (DailyWageGroup $g) => [$g->id => $g->label()])->all())
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $group = DailyWageGroup::query()->with('defaultCategory')->find($state);
                    if ($group?->usual_size) {
                        $set('workers', $group->usual_size);
                    }
                    if ($group?->defaultCategory !== null) {
                        $set('category_id', $group->default_category_id);
                        $set('rate', bcdiv((string) $group->defaultCategory->rate_paise, '100', 2));
                    }
                }),
            TextInput::make('worker_name')->label('Worker\'s name')->maxLength(120)->required(fn (Get $get) => $get('who') === 'single')
                ->visible(fn (Get $get) => $get('who') === 'single'),
            Select::make('category_id')->label('Work')->required()->searchable()->live()
                ->options(fn () => DailyWageCategory::query()->where('status', 'active')->orderBy('name')->get()
                    ->mapWithKeys(fn (DailyWageCategory $c) => [$c->id => $c->name.' — '.Money::paise($c->rate_paise)->format().' '.$c->rateLabel()])->all())
                ->afterStateUpdated(fn (?string $state, Set $set) => $set('rate', ($c = DailyWageCategory::query()->find($state)) ? bcdiv((string) $c->rate_paise, '100', 2) : null)),
            TextInput::make('workers')->label('Number of workers')->numeric()->integer()->minValue(1)->maxValue(500)->required()->default(1)->live(onBlur: true),
            TextInput::make('quantity')->label(fn (Get $get) => DailyWageCategory::UNITS[$unit($get)][0] ?? 'Quantity')->required()->default('1')
                ->regex('/^\d{1,6}(\.\d{1,3})?$/')->live(onBlur: true)
                ->helperText(fn (Get $get) => (DailyWageCategory::UNITS[$unit($get)][2] ?? true) ? 'Paid to each worker.' : 'For the whole work of the group.'),
            TextInput::make('rate')->label(fn (Get $get) => 'Rate (Rs '.(DailyWageCategory::UNITS[$unit($get)][1] ?? '').')')->required()->regex(Units::DECIMAL_2)->live(onBlur: true),
            TextInput::make('extra')->label('Extra (Rs): overtime, bonus')->regex(Units::DECIMAL_2)->live(onBlur: true),
            TextInput::make('deduction')->label('Less (Rs): advance already given, food')->regex(Units::DECIMAL_2)->live(onBlur: true),
            Placeholder::make('total')->label('To pay')->content($total),
            Textarea::make('worker_names')->label('Workers\' names (optional)')->rows(2)->maxLength(2000),
            Textarea::make('description')->label('What was done (optional)')->rows(2)->maxLength(500),
            Select::make('pay')->label('Payment')->options(fn () => self::canPay() ? self::PAY : ['draft' => self::PAY['draft']])->default('draft')->required()->live(),
            Select::make('paid_from')->label('Paid from')
                ->options(fn (Get $get) => LedgerAccount::query()->where('kind', $get('pay') === 'bank' ? 'bank' : 'cash')->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (Get $get) => in_array($get('pay'), ['cash', 'bank'], true))->required(fn (Get $get) => in_array($get('pay'), ['cash', 'bank'], true)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['group', 'category']))
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('work_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('who')->label('Group / worker')->state(fn (DailyWageSheet $s) => $s->who())
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q->whereRaw('LOWER(worker_name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                        ->orWhereHas('group', fn (Builder $g) => $g->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])->orWhereRaw('LOWER(leader_name) LIKE ?', ['%'.mb_strtolower($search).'%'])))),
                TextColumn::make('category.name')->label('Work'),
                TextColumn::make('workers')->alignEnd(),
                TextColumn::make('quantity')->label('Qty')->alignEnd()->formatStateUsing(fn ($state, DailyWageSheet $s) => rtrim(rtrim((string) $state, '0'), '.').' '.$s->unit),
                TextColumn::make('net_paise')->label('To pay (Rs)')->alignEnd()->formatStateUsing(fn (int $state) => Money::paise($state)->grouped())
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state) => Money::paise((int) $state)->format())),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => DailyWageSheet::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        DailyWageSheet::PAID => 'success', DailyWageSheet::POSTED => 'warning', DailyWageSheet::CANCELLED => 'gray', default => 'info'
                    }),
            ])
            ->defaultSort('work_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(DailyWageSheet::STATUSES),
                SelectFilter::make('group_id')->label('Labour group')->options(fn () => DailyWageGroup::query()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('category_id')->label('Work')->options(fn () => DailyWageCategory::query()->orderBy('name')->pluck('name', 'id')),
                Filter::make('period')->form([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->where('work_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->where('work_date', '<=', $d))),
            ])
            ->actions([
                ViewAction::make(),
                PrintButton::row('daily_wage', fn (DailyWageSheet $s) => $s->status !== DailyWageSheet::CANCELLED),
                self::postAction(Action::class),
                self::payAction(Action::class),
            ]);
    }

    /**
     * "Book" for a draft: paid now from cash / bank, or owed until paid.
     *
     * @template T of \Filament\Actions\Action|Action
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function postAction(string $class): mixed
    {
        return $class::make('post')->label('Book')->icon('heroicon-o-check')->color('success')
            ->visible(fn (DailyWageSheet $record) => $record->status === DailyWageSheet::DRAFT && self::canPay())
            ->form([
                Select::make('paid_from')->label('Paid from')->options(fn () => ['later' => 'Not paid yet (book as owed)'] + self::payFromOptions())->required(),
            ])
            ->action(function (DailyWageSheet $record, array $data): void {
                try {
                    $s = app(DailyWages::class)->post($record, $data['paid_from'] === 'later' ? null : LedgerAccount::query()->find($data['paid_from']));
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                PrintButton::saved('daily_wage', $s, $s->status === DailyWageSheet::PAID ? "{$s->number} booked and paid." : "{$s->number} booked; to be paid.")->send();
            });
    }

    /**
     * "Pay" for a sheet booked as owed.
     *
     * @template T of \Filament\Actions\Action|Action
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function payAction(string $class): mixed
    {
        return $class::make('pay')->label('Pay')->icon('heroicon-o-banknotes')->color('warning')
            ->visible(fn (DailyWageSheet $record) => $record->status === DailyWageSheet::POSTED && self::canPay())
            ->form([Select::make('paid_from')->label('Paid from')->options(fn () => self::payFromOptions())->required()])
            ->action(function (DailyWageSheet $record, array $data): void {
                try {
                    $s = app(DailyWages::class)->pay($record, LedgerAccount::query()->findOrFail($data['paid_from']));
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                PrintButton::saved('daily_wage', $s, "{$s->number} paid: ".Money::paise($s->net_paise)->format())->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyWageSheets::route('/'),
            'create' => Pages\CreateDailyWageSheet::route('/create'),
            'view' => Pages\ViewDailyWageSheet::route('/{record}'),
        ];
    }
}
