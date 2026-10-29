<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Hr\ContractorBilling;
use App\Domain\Hr\ContractorVehicles;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\PartyStatementPage;
use App\Filament\Resources\Accounts\ExpenseResource;
use App\Filament\Resources\Hr\ContractorBillResource\Pages;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\ContractorBill;
use App\Models\Hr\PieceWorkEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
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

    protected static ?string $recordTitleAttribute = 'number';

    /** Found by the search box in the top bar (partial match, this mill only, and only for people who may open the list). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

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

    /**
     * Labour contractors (with their CTR code) first, then other vendors: choosing a vendor marks it as
     * a labour contractor when it is saved.
     *
     * @return array<string, string>
     */
    public static function contractors(): array
    {
        return Party::query()->where(fn ($q) => $q->where('is_contractor', true)->orWhere('is_vendor', true))
            ->orderByDesc('is_contractor')->orderBy('name')->get(['id', 'name', 'is_contractor', 'contractor_code'])
            ->mapWithKeys(fn (Party $p) => [$p->id => $p->is_contractor ? "{$p->name} ({$p->contractor_code})" : "{$p->name} — vendor"])->all();
    }

    /** How many of the contractor's vehicles the period would bill, and for how much (from the rate setup). */
    public static function vehiclesPreview(?string $partyId, ?string $from, ?string $to): string
    {
        $party = $partyId ? Party::query()->find($partyId) : null;
        if ($party === null || ! $from || ! $to) {
            return 'Choose the contractor and the period.';
        }
        $vehicles = app(ContractorVehicles::class);
        $entries = $vehicles->unbilled($party, CarbonImmutable::parse($from, 'Asia/Karachi'), CarbonImmutable::parse($to, 'Asia/Karachi'));
        $waiting = $vehicles->awaitingWeight($party, CarbonImmutable::parse($from, 'Asia/Karachi'), CarbonImmutable::parse($to, 'Asia/Karachi'));
        $note = $waiting > 0 ? " {$waiting} more still waiting for their final weight (billed later)." : '';
        if ($entries->isEmpty()) {
            return 'No unbilled tokens with a final weight in the period.'.$note;
        }
        try {
            ['lines' => $lines, 'skipped' => $skipped] = $vehicles->billable($party, $entries);
        } catch (BusinessRuleViolation $e) {
            return $entries->count().' token(s). '.$e->getMessage();
        }
        $lines = collect($lines);
        $kg = $lines->reduce(fn (string $sum, array $l) => bcadd($sum, (string) ($l['source']['billable_kg'] ?? '0'), 3), '0');
        $missing = $skipped === [] ? '' : ' NO RATE on their day (left out until a rate from that day is set): '.implode(', ', $skipped).'.';

        return $lines->count().' token(s), '.number_format((float) $kg).' kg billable, '.Units::paiseToRupees((int) $lines->sum('amount_paise')).' before tax.'.$missing.$note;
    }

    public static function form(Form $form): Form
    {
        $yesterday = fn () => now('Asia/Karachi')->subDay();

        return $form->columns(3)->schema([
            Select::make('party_id')->label('Contractor')->required()->searchable()->options(fn () => self::contractors())
                ->helperText('A vendor party; link its workers with "Assign workers" on the list.'),
            DatePicker::make('period_from')->label('From')->required()->live()->maxDate(fn (Get $get) => $get('basis') === 'vehicles' ? now('Asia/Karachi') : $yesterday()),
            DatePicker::make('period_to')->label('To')->required()->live()->afterOrEqual('period_from')
                ->maxDate(fn (Get $get) => $get('basis') === 'vehicles' ? now('Asia/Karachi') : $yesterday())
                ->helperText(fn (Get $get) => $get('basis') === 'vehicles' ? 'Up to today: tokens with their final weight.' : 'Days already over.'),
            Select::make('basis')->options(ContractorBill::BASES)->default('vehicles')->required()->live(),
            Placeholder::make('vehicles_preview')->label('Vehicles to bill')->columnSpan(2)
                ->visible(fn (Get $get) => $get('basis') === 'vehicles')
                ->content(fn (Get $get) => self::vehiclesPreview($get('party_id'), $get('period_from'), $get('period_to'))),
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
            TextInput::make('deduction')->label('Deductions (Rs)')->default('0')->regex(Units::DECIMAL_2)->live(onBlur: true)
                ->helperText('Shortage, damage, advance recovered … taken off what is paid'),
            TextInput::make('deduction_note')->label('Deduction for')->maxLength(300)->columnSpan(2)
                ->required(fn (Get $get) => filled($get('deduction')) && bccomp((string) $get('deduction'), '0', 2) > 0),
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
                TextColumn::make('paid_paise')->label('Paid')->alignEnd()->formatStateUsing($rs)->toggleable(),
                TextColumn::make('balance')->label('Balance')->alignEnd()->state(fn (ContractorBill $r) => $r->status === 'approved' ? $rs($r->balancePaise()) : null)->placeholder('—')
                    ->description(fn (ContractorBill $r) => $r->status === 'approved' ? ContractorBill::PAYMENT_STATUSES[$r->paymentStatus()] : null),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'warning', 'approved' => 'success', 'rejected' => 'danger', default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
                SelectFilter::make('party_id')->label('Contractor')->options(fn () => self::contractors()),
                SelectFilter::make('payment')->label('Payment')->options(ContractorBill::PAYMENT_STATUSES)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'unpaid' => $query->where('status', 'approved')->where('paid_paise', 0),
                        'partial' => $query->where('status', 'approved')->where('paid_paise', '>', 0)->whereColumn('paid_paise', '<', 'payable_paise'),
                        'paid' => $query->where('status', 'approved')->whereColumn('paid_paise', '>=', 'payable_paise'),
                        default => $query,
                    }),
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
                Action::make('pay')->label('Pay')->icon('heroicon-o-banknotes')->color('primary')
                    ->visible(fn (ContractorBill $r) => $r->status === 'approved' && $r->balancePaise() > 0 && $can('payment.create'))
                    ->modalDescription(fn (ContractorBill $r) => 'Still due: '.$rs($r->balancePaise()).'. Pay all or part of it.')
                    ->form(fn (ContractorBill $record) => [
                        TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2)->default(bcdiv((string) $record->balancePaise(), '100', 2)),
                        Select::make('account_id')->label('Paid from')->required()->options(fn () => ExpenseResource::cashLedgers()),
                        DatePicker::make('paid_on')->label('Date')->required()->default(now('Asia/Karachi')->toDateString())->maxDate(now('Asia/Karachi')),
                        TextInput::make('reference')->label('Cheque / transfer reference')->maxLength(120),
                    ])
                    ->action($run(fn (ContractorBill $r, array $d) => $svc()->pay($r, Units::rupeesToPaise((string) $d['amount']),
                        LedgerAccount::query()->findOrFail($d['account_id']), CarbonImmutable::parse($d['paid_on'], 'Asia/Karachi'), $d['reference'] ?? null), 'Payment recorded and posted.')),
                Action::make('statement')->label('Statement')->icon('heroicon-o-document-text')->color('gray')
                    ->url(fn (ContractorBill $r) => PartyStatementPage::getUrl(['party' => $r->party_id, 'show' => 'contractor'])),
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
                TextEntry::make('deduction_paise')->label('Deductions')
                    ->formatStateUsing(fn (ContractorBill $record) => $rs($record->deduction_paise).($record->deduction_note ? " ({$record->deduction_note})" : '')),
                TextEntry::make('payable_paise')->label('Payable to the contractor')->formatStateUsing($rs),
                TextEntry::make('paid_paise')->label('Paid')->formatStateUsing($rs),
                TextEntry::make('balance')->label('Balance')->state(fn (ContractorBill $record) => $rs($record->balancePaise()).' · '.ContractorBill::PAYMENT_STATUSES[$record->paymentStatus()]),
                TextEntry::make('note')->placeholder('—')->columnSpan(2),
                TextEntry::make('decision_note')->label('Decision note')->placeholder('—')->columnSpan(2),
            ]),
            Section::make('Payments')->visible(fn (ContractorBill $record) => $record->payments()->exists())->schema([
                RepeatableEntry::make('payments')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('paid_on')->label('Date')->date('d M Y'),
                    TextEntry::make('amount_paise')->label('Amount')->formatStateUsing($rs),
                    TextEntry::make('account.name')->label('From'),
                    TextEntry::make('reference')->placeholder('—'),
                ]),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('description')->columnSpan(2)
                        ->helperText(fn ($record) => isset($record->source['billable_kg']) ? sprintf('Gross %s · tare %s · net %s · billable %s kg · %s %s',
                            $record->source['gross_kg'] ?? '—', $record->source['tare_kg'] ?? '—', $record->source['net_kg'] ?? '—', $record->source['billable_kg'],
                            $record->source['rate_code'] ?? '', $record->source['basis_label'] ?? '') : null),
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
