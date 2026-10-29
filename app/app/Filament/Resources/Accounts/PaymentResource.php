<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Domain\Accounts\PurchaseBillService;
use App\Filament\Resources\Accounts\PaymentResource\Pages;
use App\Filament\Support\AttachmentsPanel;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PurchaseBill;
use App\Models\Masters\Party;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Payment requests. The payee is always the party's approved bank account — there is no field
 * to type a beneficiary account here.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $recordTitleAttribute = 'number';

    /** Found by the search box in the top bar (partial match, this mill only, and only for people who may open the list). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'reference'];
    }

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $slug = 'payments';

    protected static ?int $navigationSort = 20;

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'pending' => 'Waiting for approval', 'first_approved' => 'Waiting for 2nd approval', 'approved' => 'Released',
        'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'reversed' => 'Reversed',
    ];

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'approved' => 'success', 'rejected', 'cancelled', 'reversed' => 'danger', default => 'warning',
        };
    }

    /** @return array<string, string> outstanding posted bills of a party, oldest due first */
    public static function billOptions(?string $partyId): array
    {
        if ($partyId === null) {
            return [];
        }
        $service = app(PurchaseBillService::class);

        return PurchaseBill::query()->where('party_id', $partyId)->where('status', PurchaseBill::POSTED)->orderBy('due_date')->get()
            ->filter(fn (PurchaseBill $b) => $service->outstanding($b) > 0)
            ->mapWithKeys(fn (PurchaseBill $b) => [$b->id => sprintf('%s · due %s · %s outstanding',
                $b->number, $b->due_date->format('d M'), Units::paiseToRupees($service->outstanding($b)))])
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('party_id')->label('Pay to')->required()->searchable()->live()
                ->options(fn () => Party::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
            Select::make('mode')->options(Payment::MODES)->default('ibft')->required()->live(),
            Select::make('from_ledger_id')->label('From')->required()
                ->options(fn (Get $get) => LedgerAccount::query()->where('status', 'active')
                    ->where('kind', $get('mode') === 'cash' ? 'cash' : 'bank')->orderBy('name')->get()
                    ->mapWithKeys(fn (LedgerAccount $l) => [$l->id => $l->displayName()])),
            DatePicker::make('payment_date')->label('Date')->default(now())->required(),
            TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
            CheckboxList::make('bills')->label('Against bills (settled oldest first; any remainder is on account)')
                ->options(fn (Get $get) => self::billOptions($get('party_id')))->columnSpanFull(),
            Placeholder::make('payee')->label('Paid into')->columnSpanFull()
                ->visible(fn (Get $get) => $get('mode') !== 'cash')
                ->content('The party\'s approved bank account (changing it needs a second person\'s approval). The details are frozen with this request.'),
            Textarea::make('narration')->maxLength(500)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['party', 'fromLedger']))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('payment_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('party.name')->label('Party')->searchable(),
                TextColumn::make('mode')->formatStateUsing(fn (string $state) => Payment::MODES[$state] ?? $state),
                TextColumn::make('amount_paise')->label('Amount')->alignEnd()->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextColumn::make('payee')->label('Into')->state(fn (Payment $r) => $r->payee ? "{$r->payee['bank_code']} …{$r->payee['last4']}" : '—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
                TextColumn::make('reference')->label('Transaction / cheque')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->options(self::STATUS_LABELS)])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
                TextEntry::make('party.name')->label('Party'),
                TextEntry::make('amount_paise')->label('Amount')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state))->weight('bold'),
                TextEntry::make('mode')->formatStateUsing(fn (string $state) => Payment::MODES[$state] ?? $state),
                TextEntry::make('fromLedger.name')->label('From'),
                TextEntry::make('payment_date')->date('d M Y'),
                TextEntry::make('reference')->label('Transaction ID / cheque')->placeholder('—'),
                TextEntry::make('payee_view')->label('Paid into (frozen)')->columnSpanFull()
                    ->state(fn (Payment $r) => $r->payee ? "{$r->payee['holder']} · {$r->payee['bank_name']} · {$r->payee['bank_code']} · a/c …{$r->payee['last4']}" : 'Cash'),
                TextEntry::make('narration')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Against bills')->schema([
                RepeatableEntry::make('allocations')->hiddenLabel()->columns(2)->schema([
                    TextEntry::make('bill.number')->label('Bill'),
                    TextEntry::make('amount_paise')->label('Amount')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                ]),
            ]),
            AttachmentsPanel::section(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/request'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }
}
