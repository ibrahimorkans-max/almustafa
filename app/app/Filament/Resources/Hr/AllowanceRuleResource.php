<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Filament\Resources\Hr\AllowanceRuleResource\Pages;
use App\Filament\Support\Units;
use App\Models\Hr\AllowanceRule;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Allowances paid by rule (conveyance, medical, meal per day, attendance allowance …). */
class AllowanceRuleResource extends Resource
{
    use \App\Filament\Concerns\TranslatesResourceLabels;

    protected static ?string $model = AllowanceRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Allowance rules';

    protected static ?int $navigationSort = 71;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            TextInput::make('code')->required()->maxLength(20)->regex('/^[A-Z][A-Z0-9_]{1,19}$/')->helperText(__('Shown on the payslip, e.g. CONV, MEAL')),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('method')->options(\App\Support\Locales::options(AllowanceRule::METHODS))->required()->live(),
            TextInput::make('amount_rupees')->label(__('Amount (Rs)'))->regex(Units::DECIMAL_2)
                ->visible(fn (Get $get) => $get('method') !== 'pct_basic')
                ->afterStateHydrated(fn (TextInput $component, ?AllowanceRule $record) => $component->state($record ? bcdiv((string) $record->amount_paise, '100', 2) : null)),
            TextInput::make('rate_percent')->label(__('Percent of Basic'))->numeric()->minValue(0)->maxValue(500)
                ->visible(fn (Get $get) => $get('method') === 'pct_basic')
                ->afterStateHydrated(fn (TextInput $component, ?AllowanceRule $record) => $component->state($record ? $record->rate_bp / 100 : null)),
            Toggle::make('prorate')->label(__('Prorate by paid days'))->default(true)->visible(fn (Get $get) => $get('method') === 'fixed'),
            TextInput::make('max_absent_days')->label(__('Most absences allowed'))->integer()->minValue(0)->visible(fn (Get $get) => $get('method') === 'attendance'),
            TextInput::make('max_late_days')->label(__('Most late days allowed'))->integer()->minValue(0)->visible(fn (Get $get) => $get('method') === 'attendance'),
            Select::make('departments')->multiple()->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id'))->helperText(__('Empty = everyone')),
            Select::make('employment_types')->multiple()->options(\App\Support\Locales::options(Employee::TYPES))->helperText(__('Empty = everyone')),
            DatePicker::make('effective_from')->required()->default(now('Asia/Karachi')->startOfMonth()),
            DatePicker::make('effective_to')->afterOrEqual('effective_from'),
            Select::make('status')->options(['active' => __('Active'), 'inactive' => __('Stopped')])->default('active')->required(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  form state (rupees / percent) → stored paisa / basis points
     * @return array<string, mixed>
     */
    public static function mutate(array $data): array
    {
        $pct = ($data['method'] ?? null) === 'pct_basic';
        $data['amount_paise'] = ! $pct && filled($data['amount_rupees'] ?? null) ? Units::rupeesToPaise((string) $data['amount_rupees']) : 0;
        $data['rate_bp'] = $pct && filled($data['rate_percent'] ?? null) ? (int) Units::percentToBp((string) $data['rate_percent']) : 0;
        $data['departments'] = array_values($data['departments'] ?? []) ?: null;
        $data['employment_types'] = array_values($data['employment_types'] ?? []) ?: null;
        unset($data['amount_rupees'], $data['rate_percent']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name'),
                TextColumn::make('method')->formatStateUsing(fn (string $state) => AllowanceRule::METHODS[$state] ?? $state),
                TextColumn::make('value')->label(__('Amount / rate'))->state(fn (AllowanceRule $r) => $r->method === 'pct_basic'
                    ? Units::bpToPercent($r->rate_bp) : Units::paiseToRupees($r->amount_paise)),
                TextColumn::make('effective_from')->date('d M Y')->label(__('From')),
                TextColumn::make('effective_to')->date('d M Y')->label(__('To'))->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('code')
            ->actions([
                EditAction::make()->mutateFormDataUsing(fn (array $data) => self::mutate($data)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAllowanceRules::route('/')];
    }
}
