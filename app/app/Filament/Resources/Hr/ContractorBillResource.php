<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Hr\ContractorBilling;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\ContractorBillResource\Pages;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\Units;
use App\Models\Hr\ContractorBill;
use App\Models\Hr\PieceWorkEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
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

/** Labour contractor bills built from the workers' attendance or piece work, approved by a second person. */
class ContractorBillResource extends Resource
{
    protected static ?string $model = ContractorBill::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Contractor bills';

    protected static ?string $modelLabel = 'contractor bill';

    protected static ?int $navigationSort = 33;

    public const STATUSES = ['draft' => 'Waiting for approval', 'approved' => 'Approved and posted', 'rejected' => 'Refused'];

    public static function getNavigationBadge(): ?string
    {
        $user = Filament::auth()->user();
        if (! $user instanceof LoginAccount || ! $user->hasPermission('hr.payroll.approve')) {
            return null;
        }
        $n = ContractorBill::query()->where('status', 'draft')->count();

        return $n > 0 ? (string) $n : null;
    }

    /** @return array<string, string> */
    public static function contractors(): array
    {
        return Party::query()->where('is_vendor', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Form $form): Form
    {
        $yesterday = fn () => now('Asia/Karachi')->subDay();

        return $form->columns(3)->schema([
            Select::make('party_id')->label('Contractor')->required()->searchable()->options(fn () => self::contractors())
                ->helperText('A vendor party; link its workers with "Assign workers" on the list.'),
            DatePicker::make('period_from')->label('From')->required()->maxDate($yesterday),
            DatePicker::make('period_to')->label('To')->required()->maxDate($yesterday)->afterOrEqual('period_from'),
            Select::make('basis')->options(ContractorBill::BASES)->default('man_days')->required()->live(),
            TextInput::make('rate')->label('Rate per man-day (Rs)')->regex(Units::DECIMAL_2)
                ->visible(fn (Get $get) => $get('basis') === 'man_days')->required(fn (Get $get) => $get('basis') === 'man_days'),
            Repeater::make('rates')->label('Rates per unit')->columns(2)->defaultItems(1)->columnSpan(2)
                ->visible(fn (Get $get) => $get('basis') === 'piece')->required(fn (Get $get) => $get('basis') === 'piece')
                ->schema([
                    Select::make('unit')->options(PieceWorkEntry::UNITS)->required()->distinct(),
                    TextInput::make('rate')->label('Rate (Rs)')->required()->regex(Units::DECIMAL_2),
                ]),
            TextInput::make('sales_tax')->label('Sales tax on services (%)')->default('0')->regex('/^\d{1,2}(\.\d{1,2})?$/')
                ->helperText('Provincial (PRA) when the contractor charges it'),
            TextInput::make('wht')->label('Income tax withheld (%)')->default('0')->regex('/^\d{1,2}(\.\d{1,2})?$/')
                ->helperText('s. 153(1)(b), on the amount before sales tax'),
            Textarea::make('note')->maxLength(500)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $rs = fn (?int $state) => Units::paiseToRupees($state);
        $can = fn (string $p): bool => Filament::auth()->user() instanceof LoginAccount && Filament::auth()->user()->hasPermission($p);
        $run = fn (callable $do, string $ok) => function (ContractorBill $record, array $data = []) use ($do, $ok): void {
            try {
                $do($record, $data);
                Notification::make()->title($ok)->success()->send();
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };
        $svc = fn () => app(ContractorBilling::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('party'))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('party.name')->label('Contractor')->searchable(),
                TextColumn::make('period')->label('Period')
                    ->state(fn (ContractorBill $r) => $r->period_from->format('d M').' – '.$r->period_to->format('d M Y')),
                TextColumn::make('basis')->formatStateUsing(fn (string $state) => ContractorBill::BASES[$state] ?? $state),
                TextColumn::make('gross_paise')->label('Gross')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('wht_paise')->label('Tax withheld')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('payable_paise')->label('Payable')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'warning', 'approved' => 'success', 'rejected' => 'danger', default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
                SelectFilter::make('party_id')->label('Contractor')->options(fn () => self::contractors()),
            ])
            ->actions([
                ViewAction::make(),
                Action::make('print')->icon('heroicon-o-printer')->color('gray')
                    ->visible(fn () => DocumentActions::allowed())
                    ->form(DocumentActions::form('contractor_bill'))
                    ->action(fn (ContractorBill $record, array $data) => DocumentActions::run('contractor_bill', $record->id, $data)),
                Action::make('approve')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (ContractorBill $r) => $r->status === 'draft' && $can('hr.payroll.approve'))
                    ->modalDescription('Posts the labour cost against the contractor and the tax withheld; pay it through Payments.')
                    ->form([Textarea::make('note')->maxLength(500)])
                    ->action($run(fn (ContractorBill $r, array $d) => $svc()->approve($r, $d['note'] ?? null), 'Bill approved and posted.')),
                Action::make('reject')->label('Refuse')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (ContractorBill $r) => $r->status === 'draft' && $can('hr.payroll.approve'))
                    ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                    ->action($run(fn (ContractorBill $r, array $d) => $svc()->reject($r, (string) $d['reason']), 'Bill refused.')),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $rs = fn (?int $state) => Units::paiseToRupees($state);

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('party.name')->label('Contractor'),
                TextEntry::make('period')->state(fn (ContractorBill $r) => $r->period_from->format('d M Y').' – '.$r->period_to->format('d M Y')),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state),
                TextEntry::make('gross_paise')->label('Gross')->formatStateUsing($rs),
                TextEntry::make('sales_tax_paise')->label('Sales tax')
                    ->formatStateUsing(fn (ContractorBill $record) => $rs($record->sales_tax_paise).' ('.Units::bpToPercent($record->sales_tax_bp).')'),
                TextEntry::make('wht_paise')->label('Income tax withheld')
                    ->formatStateUsing(fn (ContractorBill $record) => $rs($record->wht_paise).' ('.Units::bpToPercent($record->wht_bp).')'),
                TextEntry::make('payable_paise')->label('Payable to the contractor')->formatStateUsing($rs),
                TextEntry::make('note')->placeholder('—')->columnSpan(2),
                TextEntry::make('decision_note')->label('Decision note')->placeholder('—')->columnSpan(2),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('description')->columnSpan(2),
                    TextEntry::make('quantity')->formatStateUsing(fn ($record) => rtrim(rtrim((string) $record->quantity, '0'), '.').' '.$record->unit),
                    TextEntry::make('rate_paise')->label('Rate')->formatStateUsing($rs),
                    TextEntry::make('amount_paise')->label('Amount')->formatStateUsing($rs),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContractorBills::route('/'),
            'create' => Pages\CreateContractorBill::route('/create'),
            'view' => Pages\ViewContractorBill::route('/{record}'),
        ];
    }
}
