<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Domain\Accounts\PaperRecords;
use App\Filament\Resources\Accounts\PaperRecordResource\Pages;
use App\Filament\Support\AttachmentsPanel;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\PaperRecord;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\Vehicle;
use App\Support\Values\PakistanIds;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Accounts → Old records (from paper): everything from the mill's paper registers before it used the software —
 * opening balances, opening stock, past purchases, sales, payments, receipts, expenses, journal entries, old
 * weighbridge slips and any other register entry (App\Domain\Accounts\PaperRecords). Entered one at a time or many
 * at once from a spreadsheet; each keeps its register, page and a photo of the page.
 */
class PaperRecordResource extends Resource
{
    protected static ?string $model = PaperRecord::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Old records (from paper)';

    protected static ?string $modelLabel = 'old record';

    protected static ?string $slug = 'accounts/old-records';

    protected static ?int $navigationSort = 95;

    /** @param  list<string>  $kinds */
    private static function for(array $kinds): \Closure
    {
        return fn (Get $get) => in_array($get('kind'), $kinds, true);
    }

    /** @return array<string, string> ledgers (not parties) for a choice, with their group */
    public static function ledgers(?array $natures = null, ?array $kinds = null): array
    {
        return LedgerAccount::query()->with('group')->where('status', 'active')->whereNull('party_id')
            ->when($natures, fn (Builder $q) => $q->whereHas('group', fn (Builder $g) => $g->whereIn('nature', $natures)))
            ->when($kinds, fn (Builder $q) => $q->whereIn('kind', $kinds))
            ->orderBy('name')->get()->mapWithKeys(fn (LedgerAccount $l) => [$l->id => $l->name.' — '.($l->group->name ?? '')])->all();
    }

    /** The form of one old record: the fields shown follow its kind. */
    public static function entryForm(): array
    {
        $money = ['opening_party', 'opening_ledger', 'opening_stock', 'purchase', 'sale', 'payment', 'receipt', 'expense', 'journal'];

        return [
            Select::make('kind')->label('What is it?')->options(PaperRecords::KINDS)->required()->live()->native(false),
            Grid::make(3)->schema([
                DatePicker::make('date')->label('Date on the paper')->required()->native(false)->displayFormat('d/m/Y')->maxDate(now('Asia/Karachi')),
                TextInput::make('book_ref')->label('Register and page')->placeholder('Roznamcha 2024, page 37')->maxLength(160),
                TextInput::make('paper_number')->label('Number on the paper')->placeholder('Bill / slip / receipt no.')->maxLength(60),
            ]),
            Select::make('party_id')->label('Party')->searchable()->native(false)
                ->visible(self::for([...PaperRecords::PARTY_KINDS, 'weighment', 'note']))
                ->required(self::for(PaperRecords::PARTY_KINDS))
                ->getSearchResultsUsing(fn (string $search) => Party::query()->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"))
                    ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => Party::query()->withArchived()->whereKey($value)->value('name'))
                ->createOptionForm([
                    TextInput::make('name')->required()->maxLength(150),
                    Radio::make('type')->options(['supplier' => 'Supplier / farmer', 'customer' => 'Customer'])->default('supplier')->required()->inline(),
                    Grid::make(2)->schema([
                        TextInput::make('phone')->tel()->maxLength(20),
                        TextInput::make('cnic')->label('CNIC')->placeholder('36302-1234567-1')->maxLength(15),
                        TextInput::make('address')->maxLength(300),
                        TextInput::make('city')->maxLength(80),
                    ]),
                ])
                ->createOptionUsing(fn (array $data) => Party::query()->create(['name' => $data['name'], 'phone' => $data['phone'] ?? null,
                    'cnic' => $data['cnic'] ?? null, 'address' => $data['address'] ?? null, 'city' => $data['city'] ?? null,
                    'is_vendor' => $data['type'] === 'supplier', 'is_customer' => $data['type'] === 'customer'])->id)
                ->live()
                ->helperText('Not in the list? Add it with the + button.'),
            Fieldset::make('Party details on the paper')
                ->visible(fn (Get $get) => filled($get('party_id')))
                ->columns(4)
                ->schema([
                    Placeholder::make('party_now')->hiddenLabel()->columnSpanFull()
                        ->content(fn (Get $get) => self::partySummary($get('party_id'))),
                    TextInput::make('party_phone')->label('Phone')->tel()->maxLength(20),
                    TextInput::make('party_cnic')->label('CNIC')->placeholder('36302-1234567-1')->maxLength(15),
                    TextInput::make('party_address')->label('Address')->maxLength(300),
                    TextInput::make('party_city')->label('City')->maxLength(80),
                ]),
            Radio::make('side')->label('Balance')->required()->inline()
                ->visible(self::for(['opening_party', 'opening_ledger']))
                ->options(fn (Get $get) => $get('kind') === 'opening_party'
                    ? ['receivable' => 'The party owed the mill (lena)', 'payable' => 'The mill owed the party (dena)']
                    : ['dr' => 'Debit (cash, bank, assets, money owed to the mill)', 'cr' => 'Credit (loans, capital, money the mill owes)']),
            Select::make('account_id')->native(false)->searchable()
                ->label(fn (Get $get) => match ($get('kind')) {
                    'opening_ledger' => 'Account', 'expense' => 'Expense account', 'journal' => 'Account debited',
                    'purchase' => 'Purchase account (blank: Purchase – Paddy)', default => 'Sales account (blank: Sales – Rice / By-products)',
                })
                ->visible(self::for(['opening_ledger', 'expense', 'journal', 'purchase', 'sale']))
                ->required(self::for(['opening_ledger', 'expense', 'journal']))
                ->options(fn (Get $get) => match ($get('kind')) {
                    'expense' => self::ledgers(['expense']), 'purchase' => self::ledgers(['expense']), 'sale' => self::ledgers(['income']),
                    'opening_ledger' => array_diff_key(self::ledgers(), [LedgerAccount::query()->where('system_key', 'opening_balance')->value('id') => true]),
                    default => self::ledgers() + LedgerAccount::query()->whereNotNull('party_id')->orderBy('name')->pluck('name', 'id')->all(),
                }),
            Select::make('credit_account_id')->label('Account credited')->native(false)->searchable()->required(self::for(['journal']))
                ->visible(self::for(['journal']))
                ->options(fn () => self::ledgers() + LedgerAccount::query()->whereNotNull('party_id')->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('cash_bank_id')->label(fn (Get $get) => $get('kind') === 'receipt' ? 'Received into' : 'Paid from')->native(false)
                ->visible(self::for(['payment', 'receipt', 'expense']))
                ->options(fn () => self::ledgers(null, ['cash', 'bank']))
                ->default(fn () => LedgerAccount::query()->where('system_key', 'cash')->value('id'))
                ->helperText('A bank not in the list: add it under Accounts → Bank accounts.'),
            Fieldset::make('Payment details')
                ->visible(self::for(PaperRecords::PAYMENT_KINDS))
                ->columns(3)
                ->schema([
                    Select::make('payment_mode')->label('Paid by')->options(PaperRecords::PAYMENT_MODES)->default('cash')->native(false)->live(),
                    TextInput::make('reference')->maxLength(60)
                        ->label(fn (Get $get) => in_array($get('payment_mode'), ['cheque', 'pay_order'], true) ? 'Cheque / pay order no.' : 'Transaction ID / slip no.')
                        ->required(fn (Get $get) => in_array($get('payment_mode'), ['cheque', 'pay_order', 'ibft', 'rtgs', 'raast', 'mobile'], true)),
                    DatePicker::make('cheque_date')->label('Cheque date')->native(false)->displayFormat('d/m/Y')
                        ->visible(fn (Get $get) => in_array($get('payment_mode'), ['cheque', 'pay_order'], true)),
                    TextInput::make('party_bank')->label("Party's bank / account")->placeholder('HBL Lodhran, a/c …1234')->maxLength(120)
                        ->visible(self::for(['payment', 'receipt'])),
                    TextInput::make('handled_by')->maxLength(120)
                        ->label(fn (Get $get) => $get('kind') === 'receipt' ? 'Received by' : 'Paid by (person)'),
                ]),
            Grid::make(3)->visible(self::for(['opening_stock', 'purchase', 'sale', 'weighment']))->schema([
                Select::make('product_id')->label('Product')->native(false)->searchable()
                    ->required(self::for(['opening_stock']))
                    ->options(fn () => Product::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('godown_id')->label('Godown')->native(false)
                    ->visible(self::for(['opening_stock', 'purchase', 'sale']))
                    ->required(self::for(['opening_stock']))
                    ->options(fn () => Godown::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText(fn (Get $get) => $get('kind') === 'opening_stock' ? null : 'With a godown and weight, the stock moves too.'),
                TextInput::make('quantity_kg')->label('Weight (kg)')->numeric()->minValue(0)
                    ->visible(self::for(['opening_stock', 'purchase', 'sale']))->required(self::for(['opening_stock'])),
            ]),
            TextInput::make('rate_per_maund')->label('Rate per maund (40 kg), Rs')->numeric()->minValue(0)
                ->visible(self::for(['purchase', 'sale']))->helperText('With the weight, works out the amount when it is left blank.'),
            TextInput::make('amount')->numeric()->minValue(0)->prefix('Rs')
                ->label(fn (Get $get) => $get('kind') === 'opening_stock' ? 'Value of the stock (optional)' : 'Amount')
                ->visible(self::for($money))
                ->required(self::for(['opening_party', 'opening_ledger', 'payment', 'receipt', 'expense', 'journal'])),
            Fieldset::make('Vehicle details')
                ->visible(self::for(PaperRecords::VEHICLE_KINDS))
                ->columns(3)
                ->schema([
                    TextInput::make('vehicle')->label('Vehicle number')->placeholder('MNA-21-1234')->required(self::for(['weighment']))->maxLength(30),
                    Select::make('vehicle_type')->label('Type')->native(false)
                        ->options(array_combine(Vehicle::TYPES, array_map(fn (string $t) => Vehicle::typeLabel($t), Vehicle::TYPES))),
                    TextInput::make('bilty_no')->label('Bilty / gate pass no.')->maxLength(40),
                    TextInput::make('driver_name')->label('Driver name')->maxLength(120),
                    TextInput::make('driver_phone')->label('Driver phone')->tel()->maxLength(20),
                    TextInput::make('bags')->label('Bags')->integer()->minValue(0),
                ]),
            Grid::make(2)->visible(self::for(['weighment']))->schema([
                TextInput::make('gross_kg')->label('Gross weight (kg)')->numeric()->minValue(0)->required(self::for(['weighment'])),
                TextInput::make('tare_kg')->label('Tare weight (kg)')->numeric()->minValue(0)->required(self::for(['weighment'])),
            ]),
            Textarea::make('description')->rows(2)->maxLength(5000)
                ->label(fn (Get $get) => $get('kind') === 'note' ? 'What the register says' : 'Details (as written)')
                ->required(self::for(['note', 'journal'])),
            FileUpload::make('photo')->label('Photo of the page (optional)')->storeFiles(false)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(20480),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('record_date', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['party', 'voucher']))
            ->columns([
                TextColumn::make('record_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state) => PaperRecords::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => match (true) {
                        str_starts_with($state, 'opening') => 'info', in_array($state, PaperRecords::KEPT_KINDS, true) => 'gray', default => 'primary',
                    }),
                TextColumn::make('paper_number')->label('Paper no.')->placeholder('—')->searchable(),
                TextColumn::make('party.name')->label('Party')->placeholder('—')->searchable(),
                TextColumn::make('amount_paise')->label('Amount')->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : Units::paiseToRupees($state)),
                TextColumn::make('quantity_kg')->label('Kg')->alignEnd()->placeholder('—')->numeric(3),
                TextColumn::make('vehicle')->label('Vehicle')->placeholder('—')
                    ->state(fn (PaperRecord $r) => $r->details['vehicle'] ?? null)
                    ->description(fn (PaperRecord $r) => $r->details['driver_name'] ?? null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('details->vehicle', 'ilike', "%{$search}%")
                        ->orWhere('details->driver_name', 'ilike', "%{$search}%")),
                TextColumn::make('payment_mode')->label('Paid by')->placeholder('—')->toggleable()
                    ->state(fn (PaperRecord $r) => isset($r->details['payment_mode']) ? PaperRecords::PAYMENT_MODES[$r->details['payment_mode']] ?? null : null)
                    ->description(fn (PaperRecord $r) => $r->details['reference'] ?? null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('details->reference', 'ilike', "%{$search}%")),
                TextColumn::make('voucher.number')->label('Voucher')->placeholder('—'),
                TextColumn::make('book_ref')->label('Register / page')->placeholder('—')->toggleable()->searchable(),
                TextColumn::make('description')->limit(40)->placeholder('—')->toggleable(isToggledHiddenByDefault: true)->searchable(),
                TextColumn::make('cancelled_at')->label('Status')->badge()
                    ->state(fn (PaperRecord $r) => $r->isCancelled() ? 'Cancelled' : 'Entered')
                    ->color(fn (string $state) => $state === 'Cancelled' ? 'danger' : 'success'),
                TextColumn::make('import_batch')->label('Import')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')->options(PaperRecords::KINDS)->multiple(),
                SelectFilter::make('party_id')->label('Party')->relationship('party', 'name')->searchable(),
                TernaryFilter::make('cancelled')->label('Cancelled')->nullable()->attribute('cancelled_at')
                    ->trueLabel('Cancelled only')->falseLabel('Entered only'),
                Filter::make('dates')->form([
                    DatePicker::make('from')->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('until')->native(false)->displayFormat('d/m/Y'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('record_date', '>=', $d))
                    ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('record_date', '<=', $d))),
                SelectFilter::make('import_batch')->label('Import')
                    ->options(fn () => PaperRecord::query()->whereNotNull('import_batch')->distinct()->orderByDesc('import_batch')->limit(50)->pluck('import_batch', 'import_batch')->all()),
            ])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('kind')->formatStateUsing(fn (string $state) => PaperRecords::KINDS[$state] ?? $state)->badge(),
                TextEntry::make('record_date')->label('Date on the paper')->date('d M Y'),
                TextEntry::make('paper_number')->label('Number on the paper')->placeholder('—'),
                TextEntry::make('book_ref')->label('Register and page')->placeholder('—'),
                TextEntry::make('party.name')->label('Party')->placeholder('—'),
                TextEntry::make('amount_paise')->label('Amount')->placeholder('—')->weight('bold')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : Units::paiseToRupees($state)),
                TextEntry::make('quantity_kg')->label('Weight (kg)')->placeholder('—')->numeric(3),
                TextEntry::make('voucher.number')->label('Posted as voucher')->placeholder('Not posted (kept as written)'),
                TextEntry::make('description')->label('Details')->placeholder('—')->columnSpanFull(),
                TextEntry::make('details')->label('Entries in the books')->columnSpanFull()->placeholder('—')->html()
                    ->state(fn (PaperRecord $r) => self::detailsHtml($r, 'other')),
                TextEntry::make('party_details')->label('Party details')->columnSpanFull()->html()
                    ->visible(fn (PaperRecord $r) => filled($r->details['party'] ?? null))->state(fn (PaperRecord $r) => self::detailsHtml($r, 'party')),
                TextEntry::make('vehicle_details')->label('Vehicle details')->columnSpanFull()->html()
                    ->visible(fn (PaperRecord $r) => self::detailsHtml($r, 'vehicle') !== null)->state(fn (PaperRecord $r) => self::detailsHtml($r, 'vehicle')),
                TextEntry::make('payment_details')->label('Payment details')->columnSpanFull()->html()
                    ->visible(fn (PaperRecord $r) => self::detailsHtml($r, 'payment') !== null)->state(fn (PaperRecord $r) => self::detailsHtml($r, 'payment')),
                TextEntry::make('enteredBy.username')->label('Entered by')->placeholder('—'),
                TextEntry::make('created_at')->label('Entered on')->dateTime('d M Y H:i', 'Asia/Karachi'),
                TextEntry::make('import_batch')->label('Import')->placeholder('Entered by hand'),
                TextEntry::make('cancelled_at')->label('Cancelled')->placeholder('—')->color('danger')
                    ->formatStateUsing(fn (PaperRecord $r) => $r->cancelled_at?->timezone('Asia/Karachi')->format('d M Y H:i').' — '.$r->cancel_reason),
            ]),
            AttachmentsPanel::section(),
        ]);
    }

    /** What the system already knows of a party, shown above the details from the paper. */
    public static function partySummary(?string $partyId): string
    {
        $p = filled($partyId) ? Party::query()->withArchived()->find($partyId) : null;
        if ($p === null) {
            return '';
        }
        $known = array_filter([$p->code, PakistanIds::formatMobile($p->phone) ?? $p->phone, $p->cnic ? 'CNIC ✓' : null, $p->address, $p->city]);

        return 'In the system: '.implode(' · ', $known).'. Fill below only what is written on the paper and missing here; nothing already saved is changed.';
    }

    /** The details of one group, as on the paper: party, vehicle, payment or the rest. */
    private static function detailsHtml(PaperRecord $r, string $group): ?string
    {
        $d = $r->details ?? [];
        $groups = [
            'party' => ['name' => 'Name', 'code' => 'Code', 'phone' => 'Phone', 'cnic' => 'CNIC', 'address' => 'Address', 'city' => 'City'],
            'vehicle' => ['vehicle' => 'Vehicle', 'vehicle_type' => 'Type', 'driver_name' => 'Driver', 'driver_phone' => 'Driver phone', 'bags' => 'Bags',
                'bilty_no' => 'Bilty / gate pass', 'gross_kg' => 'Gross (kg)', 'tare_kg' => 'Tare (kg)', 'net_kg' => 'Net (kg)', 'product' => 'Product'],
            'payment' => ['payment_mode' => 'Paid by', 'reference' => 'Cheque / transaction no.', 'cheque_date' => 'Cheque date', 'party_bank' => "Party's bank",
                'handled_by' => 'Paid / received by'],
            'other' => ['rate_per_maund' => 'Rate per maund (Rs)', 'side' => 'Balance'],
        ];
        $source = $group === 'party' ? ($d['party'] ?? []) : $d;
        $sides = ['receivable' => 'Party owed the mill', 'payable' => 'Mill owed the party', 'dr' => 'Debit', 'cr' => 'Credit'];
        $parts = [];
        foreach ($groups[$group] as $key => $label) {
            if (filled($source[$key] ?? null)) {
                $value = match ($key) {
                    'side' => $sides[$source[$key]] ?? $source[$key],
                    'payment_mode' => PaperRecords::PAYMENT_MODES[$source[$key]] ?? $source[$key],
                    'vehicle_type' => Vehicle::typeLabel($source[$key]),
                    'cheque_date' => CarbonImmutable::parse($source[$key])->format('d M Y'),
                    default => (string) $source[$key],
                };
                $parts[] = '<b>'.e($label).':</b> '.e($value);
            }
        }
        if ($group === 'other') {
            foreach ($d['ledgers'] ?? [] as [$name, $dr, $cr]) {
                $parts[] = e($name).': '.($dr > 0 ? 'Dr '.e(Units::paiseToRupees((int) $dr)) : 'Cr '.e(Units::paiseToRupees((int) $cr)));
            }
        }

        return $parts === [] ? null : implode(' &nbsp;·&nbsp; ', $parts);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaperRecords::route('/'),
            'view' => Pages\ViewPaperRecord::route('/{record}'),
        ];
    }
}
