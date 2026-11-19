<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales;

use App\Domain\Reports\Balances;
use App\Domain\Sales\QuickBills;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Inventory\RequisitionResource;
use App\Filament\Resources\Sales\QuickBillResource\Pages;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Models\Sales\QuickBill;
use App\Models\Sales\QuickBillLine;
use App\Support\Locales;
use App\Support\Values\Money;
use App\Support\Values\Weight;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Quick bills (App\Domain\Sales\QuickBills): a sale (any size — a bike, by hand), a paddy purchase (after the gate entry
 * and lab), rice given for paddy, milling for labour, or weighing an outside vehicle — many lines, weights typed, rate
 * filled from the price list and changeable, charges, and paid now, in part or later. Listed paid / partly paid / unpaid.
 */
class QuickBillResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = QuickBill::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Sales & dispatch';

    protected static ?string $navigationLabel = 'Quick bills';

    protected static ?string $modelLabel = 'quick bill';

    protected static ?string $slug = 'quick-bills';

    protected static ?int $navigationSort = 5;

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'customer_name', 'vehicle_no'];
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && ($user->can('sales.view') || $user->can('ledger.view') || $user->can('dispatch.manage'));
    }

    public static function canCreate(): bool
    {
        return QuickBills::canCreate();
    }

    public const STATUS_LABELS = ['paid' => 'Paid', 'partial' => 'Partly paid', 'unpaid' => 'Unpaid'];

    public static function statusColor(string $s): string
    {
        return match ($s) {
            'paid' => 'success', 'partial' => 'warning', default => 'danger'
        };
    }

    /** @return array<string, string> */
    public static function cashBankOptions(): array
    {
        return LedgerAccount::query()->whereIn('kind', ['cash', 'bank'])->where('status', 'active')->orderBy('kind')->orderBy('name')
            ->get()->mapWithKeys(fn (LedgerAccount $l) => [$l->id => ($l->kind === 'cash' ? '💵 ' : '🏦 ').$l->name])->all();
    }

    /**
     * The line's net weight and amount as typed so far (shown live on the form).
     *
     * @return array{net_kg: string, amount_paise: int}
     */
    public static function lineTotals(callable $get): array
    {
        try {
            $calc = QuickBills::lineAmount(
                self::num($get('gross_kg')), self::num($get('tare_kg')), filled($get('bags')) ? (int) $get('bags') : null,
                self::num($get('deduction_per_bag_kg')), self::num($get('other_deduction_kg')), Money::rupees(self::num($get('rate'), 2))->paise,
            );
        } catch (Throwable) {
            return ['net_kg' => '0.000', 'amount_paise' => 0];
        }

        return $calc;
    }

    private static function num(mixed $v, int $scale = 3): string
    {
        $v = trim((string) $v);

        return preg_match('/^\d{1,12}(\.\d+)?$/', $v) ? bcadd($v, '0', $scale) : '0';
    }

    public static function form(Form $form): Form
    {
        $kind = fn (Get $get) => (string) $get('kind');

        return $form->columns(1)->schema([
            Section::make(__('Bill'))->columns(['default' => 1, 'md' => 3])->schema([
                ToggleButtons::make('kind')->label(__('What is it for?'))->required()->inline()->live()->default('sale')->columnSpanFull()
                    ->options(Locales::options(QuickBill::KINDS))
                    ->icons(['sale' => 'heroicon-o-shopping-cart', 'purchase' => 'heroicon-o-inbox-arrow-down', 'exchange' => 'heroicon-o-arrows-right-left',
                        'milling' => 'heroicon-o-cog-6-tooth', 'weighing' => 'heroicon-o-scale'])
                    ->afterStateUpdated(fn (Set $set) => $set('lines', [['direction' => null]])),
                DateTimePicker::make('billed_at')->label(__('Date and time'))->required()->seconds(false)->default(now('Asia/Karachi'))->timezone('Asia/Karachi')
                    ->maxDate(now('Asia/Karachi')->addHour()),
                Select::make('party_id')->label(__('Party'))->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search) => Party::query()->where(fn (Builder $q) => $q->where('name', Balances::like(), "%{$search}%")
                        ->orWhere('code', Balances::like(), "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->limit(30)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Party::query()->find($value)?->name)
                    ->helperText(__('Leave empty for a walk-in customer who pays now.')),
                TextInput::make('customer_name')->label(__('Walk-in customer name'))->maxLength(120)
                    ->visible(fn (Get $get) => blank($get('party_id')))->requiredWithout('party_id'),
                TextInput::make('customer_phone')->label(__('Phone'))->tel()->maxLength(20)->visible(fn (Get $get) => blank($get('party_id'))),
                Select::make('sauda_id')->label(__('Sauda (optional)'))->searchable()->live()
                    ->visible(fn (Get $get) => in_array($get('kind'), ['sale', 'purchase'], true))
                    ->options(fn (Get $get) => Sauda::query()->where('direction', $get('kind') === 'purchase' ? 'purchase' : 'sale')
                        ->whereIn('status', [Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY])->with(['party', 'product'])->latest()->limit(200)->get()
                        ->mapWithKeys(fn (Sauda $s) => [$s->id => "{$s->number} — {$s->party->name} · {$s->product->name} @ ".Money::paise((int) $s->rate_paise_per_maund)->format()]))
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                        $s = $state ? Sauda::query()->find($state) : null;
                        if ($s === null) {
                            return;
                        }
                        $set('party_id', $s->party_id);
                        $lines = (array) $get('lines');
                        $first = array_key_first($lines);
                        if ($first !== null) {
                            $set("lines.{$first}.product_id", $s->product_id);
                            $set("lines.{$first}.description", Product::query()->find($s->product_id)?->name);
                            $set("lines.{$first}.rate", Money::paise((int) $s->rate_paise_per_maund)->toDecimal());
                        }
                    }),
                Select::make('gate_entry_id')->label(__('Gate entry (paddy, after the lab)'))->searchable()->live()
                    ->visible(fn (Get $get) => in_array($get('kind'), ['purchase', 'exchange', 'milling'], true))
                    ->options(fn () => GateEntry::query()->where('direction', 'inbound')->where('arrived_at', '>=', now()->subDays(30))->latest('arrived_at')->limit(200)->get()
                        ->mapWithKeys(fn (GateEntry $g) => [$g->id => trim("{$g->number} · {$g->registration_no} · ".($g->party->name ?? $g->party_name).' · '.($g->net_kg ? Units::kgToMaund($g->net_kg).' md' : ''))]))
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                        $g = $state ? GateEntry::query()->find($state) : null;
                        if ($g === null) {
                            return;
                        }
                        if ($g->party_id) {
                            $set('party_id', $g->party_id);
                        } elseif ($g->party_name) {
                            $set('customer_name', $g->party_name);
                        }
                        $set('vehicle_no', $g->registration_no);
                        $set('driver_name', $g->driver_name);
                        $product = $g->product_id ? Product::query()->find($g->product_id) : null;
                        $rate = $product ? QuickBills::productDefaults($product)['rate'] : null;
                        if ($g->rate_cut_paise_per_maund && $rate !== null) {
                            $rate = Money::paise(Money::rupees($rate)->paise - (int) $g->rate_cut_paise_per_maund)->toDecimal();
                        }
                        $set('lines', [[
                            'direction' => 'in', 'product_id' => $g->product_id, 'description' => $product->name ?? $g->goods_description ?? __('Paddy'),
                            'godown_id' => $g->godown_id, 'bags' => $g->bag_count, 'gross_kg' => $g->gross_kg, 'tare_kg' => $g->tare_kg,
                            'other_deduction_kg' => $g->deduction_kg, 'rate' => $rate,
                        ]]);
                    }),
            ]),

            self::vehicleSection(),
            self::linesSection(),
            self::chargesSection(),
            self::paymentSection(),
        ]);
    }

    /** The vehicle (also on Dispatch orders → Direct). */
    public static function vehicleSection(): Section
    {
        return Section::make(__('Vehicle'))->columns(['default' => 1, 'md' => 3])->collapsible()->schema([
            Select::make('vehicle_type')->label(__('Vehicle'))->options(Locales::options(QuickBill::VEHICLES))->live(),
            TextInput::make('vehicle_no')->label(__('Vehicle number'))->maxLength(20)
                ->hidden(fn (Get $get) => $get('vehicle_type') === 'by_hand'),
            TextInput::make('driver_name')->label(__('Driver / carrier'))->maxLength(80),
            TextInput::make('first_weight_kg')->label(__('First weight (kg)'))->regex(Units::DECIMAL_3)
                ->visible(fn (Get $get) => $get('kind') === 'weighing')->required(fn (Get $get) => $get('kind') === 'weighing'),
            TextInput::make('second_weight_kg')->label(__('Second weight (kg)'))->regex(Units::DECIMAL_3)
                ->visible(fn (Get $get) => $get('kind') === 'weighing')->required(fn (Get $get) => $get('kind') === 'weighing'),
            Placeholder::make('weighing_net')->label(__('Net weight'))->visible(fn (Get $get) => $get('kind') === 'weighing')
                ->content(fn (Get $get) => ($a = self::num($get('first_weight_kg'))) && ($b = self::num($get('second_weight_kg')))
                    ? ltrim(bcsub(max($a, $b), min($a, $b), 3), '-').' kg ('.Units::kgToMaund(bcsub(max($a, $b), min($a, $b), 3)).' md)' : '—'),
        ]);
    }

    /** The lines: products, weights, deductions and rates (also on Dispatch orders → Direct). */
    public static function linesSection(): Section
    {
        return Section::make(fn (Get $get) => match ($get('kind')) {
            'milling' => __('Milling: the paddy milled and the labour per maund'),
            'weighing' => __('Weighing fee'),
            'exchange' => __('Paddy taken (in) and rice given (out)'),
            'purchase' => __('Paddy bought'),
            default => __('Products sold'),
        })->schema([
            Repeater::make('lines')->hiddenLabel()->minItems(1)->defaultItems(1)->reorderable(false)->live()
                ->addActionLabel(__('Add another product'))
                ->columns(['default' => 2, 'md' => 6])
                ->itemLabel(function (array $state) {
                    $d = $state['description'] ?? null;

                    return $d ? (($state['direction'] ?? null) === 'in' ? '⬇ ' : (($state['direction'] ?? null) === 'out' ? '⬆ ' : '')).$d : null;
                })
                ->schema([
                    Select::make('direction')->label(__('Goes'))->required()->live()
                        ->options(['in' => __('In — the mill takes it'), 'out' => __('Out — the mill gives it')])
                        ->visible(fn (Get $get) => $get('../../kind') === 'exchange')->columnSpan(2),
                    Select::make('product_id')->label(__('Product'))->searchable()->live()->columnSpan(2)
                        ->options(fn () => Product::query()->where('status', 'active')->orderBy('name')->get()
                            ->mapWithKeys(fn (Product $p) => [$p->id => $p->name.($p->variety ? " ({$p->variety})" : '')]))
                        ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                            $p = $state ? Product::query()->find($state) : null;
                            if ($p === null) {
                                return;
                            }
                            $d = QuickBills::productDefaults($p);
                            $set('description', $d['description']);
                            $set('variety', $d['variety']);
                            if (in_array($get('../../kind'), ['sale', 'purchase', 'exchange'], true)
                                    && (blank($get('../../sauda_id')) || Sauda::query()->whereKey($get('../../sauda_id'))->value('product_id') !== $state)) {
                                $set('rate', $d['rate']);
                            }
                        }),
                    TextInput::make('description')->label(__('Item (as on the bill)'))->required()->maxLength(150)->columnSpan(2),
                    TextInput::make('variety')->label(__('Variety'))->maxLength(80),
                    Select::make('godown_id')->label(__('Godown'))->options(fn () => RequisitionResource::godownOptions())
                        ->hidden(fn (Get $get) => in_array($get('../../kind'), ['milling', 'weighing'], true)),
                    TextInput::make('bags')->label(__('Bags'))->integer()->minValue(0)->live(onBlur: true),
                    TextInput::make('gross_kg')->label(__('Weight (kg)'))->required()->regex(Units::DECIMAL_3)->live(onBlur: true),
                    TextInput::make('tare_kg')->label(__('Empty / bardana (kg)'))->regex(Units::DECIMAL_3)->default('0')->live(onBlur: true),
                    TextInput::make('deduction_per_bag_kg')->label(__('Deduction per bag (kg)'))->regex(Units::DECIMAL_3)->default('0')->live(onBlur: true),
                    TextInput::make('other_deduction_kg')->label(__('Other deduction (kg)'))->regex(Units::DECIMAL_3)->default('0')->live(onBlur: true)
                        ->helperText(__('Moisture, dust, lab cut — typed by hand.')),
                    TextInput::make('rate')->label(fn (Get $get) => $get('../../kind') === 'milling' ? __('Labour per maund (Rs)') : __('Rate per maund (Rs)'))
                        ->regex(Units::DECIMAL_2)->live(onBlur: true)->prefix('Rs'),
                    Placeholder::make('line_total')->label(__('Net weight · amount'))->columnSpan(2)
                        ->content(function (Get $get) {
                            $t = self::lineTotals($get);

                            return new HtmlString('<b dir="ltr">'.e(Units::kgToMaund($t['net_kg'])).' md</b> ('.e($t['net_kg']).' kg) · <b dir="ltr">'
                                .e(Money::paise($t['amount_paise'])->format()).'</b>');
                        }),
                ]),
        ]);
    }

    /** Vehicle rent, labour and other charges. */
    public static function chargesSection(): Section
    {
        return Section::make(__('Charges: vehicle rent, labour, other'))->collapsible()->schema([
            Repeater::make('charges')->hiddenLabel()->defaultItems(0)->reorderable(false)->live()->columns(3)
                ->addActionLabel(__('Add a charge'))
                ->schema([
                    TextInput::make('label')->label(__('Charge'))->required()->maxLength(100)->datalist([__('Vehicle rent'), __('Labour (mazdoori)'), __('Loading'), __('Bardana / bags'), __('Commission')]),
                    Select::make('effect')->label(__('On the bill'))->required()->default('add')
                        ->options(['add' => __('Add — the party pays it'), 'less' => __('Less — taken off the bill')]),
                    TextInput::make('amount')->label(__('Amount (Rs)'))->required()->regex(Units::DECIMAL_2)->prefix('Rs')->live(onBlur: true),
                ]),
        ]);
    }

    /** The total and the money paid now. */
    public static function paymentSection(): Section
    {
        return Section::make(__('Payment'))->columns(['default' => 1, 'md' => 4])->schema([
            Placeholder::make('bill_total')->label(__('Bill total'))->columnSpanFull()->content(function (Get $get) {
                $net = 0;
                foreach ((array) $get('lines') as $l) {
                    $amount = self::lineTotals(fn ($k) => $l[$k] ?? null)['amount_paise'];
                    $dir = $l['direction'] ?? match ($get('kind')) {
                        'purchase' => 'in', default => 'out'
                    };
                    $net += $dir === 'in' ? -$amount : $amount;
                }
                foreach ((array) $get('charges') as $c) {
                    try {
                        $a = Money::rupees(self::num($c['amount'] ?? null, 2))->paise;
                    } catch (Throwable) {
                        $a = 0;
                    }
                    $net += ($c['effect'] ?? 'add') === 'less' ? -$a : $a;
                }
                $who = $net >= 0 ? __('the party pays the mill') : __('the mill pays the party');

                return new HtmlString('<span style="font-size:1.6rem;font-weight:700" dir="ltr">'.e(Money::paise(abs($net))->format()).'</span> <span class="text-sm text-gray-500">— '.e($who).'</span>');
            }),
            TextInput::make('payment.amount')->label(__('Paid now (Rs)'))->regex(Units::DECIMAL_2)->prefix('Rs')
                ->helperText(__('Empty = unpaid (on credit). Part of it = partly paid.')),
            Select::make('payment.mode')->label(__('How'))->options(Locales::options(QuickBill::PAYMENT_MODES))->default('cash'),
            Select::make('payment.ledger_id')->label(__('Into / from'))->options(fn () => self::cashBankOptions())
                ->default(fn () => LedgerAccount::query()->where('system_key', 'cash')->value('id')),
            TextInput::make('payment.reference')->label(__('Cheque / transaction no.'))->maxLength(60),
            Textarea::make('note')->label(__('Note'))->maxLength(2000)->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('party'))
            ->columns([
                TextColumn::make('number')->label(__('Bill no.'))->searchable()->sortable(),
                TextColumn::make('billed_at')->label(__('Date and time'))->dateTime('d M Y, h:i A', 'Asia/Karachi')->sortable(),
                TextColumn::make('kind')->label(__('Type'))->badge()->formatStateUsing(fn (string $state) => __(QuickBill::KINDS[$state] ?? $state)),
                TextColumn::make('customer')->label(__('Party / customer'))->state(fn (QuickBill $r) => $r->customer())
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($w) => $w->where('customer_name', Balances::like(), "%{$search}%")
                        ->orWhereHas('party', fn ($p) => $p->where('name', Balances::like(), "%{$search}%")))),
                TextColumn::make('vehicle_no')->label(__('Vehicle'))->placeholder('—')
                    ->formatStateUsing(fn (?string $state, QuickBill $r) => $state ?? __(QuickBill::VEHICLES[$r->vehicle_type] ?? '—')),
                TextColumn::make('net_paise')->label(__('Amount'))->alignEnd()->formatStateUsing(fn (int $state) => Units::paiseToRupees(abs($state)))
                    ->description(fn (QuickBill $r) => $r->net_paise < 0 ? __('mill pays') : null),
                TextColumn::make('pay_status')->label(__('Payment'))->badge()->state(fn (QuickBill $r) => $r->status === QuickBill::CANCELLED ? 'cancelled' : $r->paymentStatus())
                    ->color(fn (string $state) => $state === 'cancelled' ? 'gray' : self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => $state === 'cancelled' ? __('Cancelled') : __(self::STATUS_LABELS[$state])),
                TextColumn::make('balance')->label(__('Balance'))->alignEnd()->state(fn (QuickBill $r) => $r->balancePaise())
                    ->formatStateUsing(fn (int $state) => $state > 0 ? Units::paiseToRupees($state) : '—'),
            ])
            ->defaultSort('billed_at', 'desc')
            ->filters([
                SelectFilter::make('payment')->label(__('Payment'))->options(Locales::options(self::STATUS_LABELS))
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'paid' => $query->whereRaw('paid_paise >= abs(net_paise)'),
                        'partial' => $query->where('paid_paise', '>', 0)->whereRaw('paid_paise < abs(net_paise)'),
                        'unpaid' => $query->where('paid_paise', 0)->where('net_paise', '!=', 0),
                        default => $query,
                    }),
                SelectFilter::make('kind')->label(__('Type'))->options(Locales::options(QuickBill::KINDS)),
                SelectFilter::make('party_id')->label(__('Party'))->relationship('party', 'name')->searchable(),
                Filter::make('today')->label(__('Today'))->query(fn (Builder $query) => $query->where('billed_at', '>=', now('Asia/Karachi')->startOfDay()->utc())),
            ])
            ->actions([ViewAction::make(), PrintButton::row('quick_bill')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make()->columns(4)->schema([
                TextEntry::make('number')->label(__('Bill no.')),
                TextEntry::make('billed_at')->label(__('Date and time'))->dateTime('d M Y, h:i A', 'Asia/Karachi'),
                TextEntry::make('kind')->label(__('Type'))->formatStateUsing(fn (string $state) => __(QuickBill::KINDS[$state] ?? $state)),
                TextEntry::make('pay_status')->label(__('Payment'))->badge()->state(fn (QuickBill $r) => $r->paymentStatus())
                    ->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => __(self::STATUS_LABELS[$state])),
                TextEntry::make('customer')->label(__('Party / customer'))->state(fn (QuickBill $r) => $r->customer().($r->customer_phone ? " · {$r->customer_phone}" : '')),
                TextEntry::make('vehicle')->label(__('Vehicle'))->state(fn (QuickBill $r) => trim(__(QuickBill::VEHICLES[$r->vehicle_type] ?? '').' '.($r->vehicle_no ?? '')) ?: '—'),
                TextEntry::make('driver_name')->label(__('Driver / carrier'))->placeholder('—'),
                TextEntry::make('sauda.number')->label(__('Sauda'))->placeholder('—')
                    ->formatStateUsing(fn (?string $state, QuickBill $r) => $state.($r->sauda_kg ? ' · '.Units::kgToMaund((string) $r->sauda_kg).' md '.__('delivered') : '')),
                TextEntry::make('cancel_reason')->label(__('Cancelled'))->color('danger')->columnSpanFull()
                    ->visible(fn (QuickBill $r) => $r->status === QuickBill::CANCELLED)
                    ->formatStateUsing(fn (?string $state, QuickBill $r) => $r->cancelled_at?->timezone('Asia/Karachi')->format('d M Y, h:i A').' — '.$state),
                TextEntry::make('weights')->label(__('Weights'))->visible(fn (QuickBill $r) => $r->kind === 'weighing')
                    ->state(fn (QuickBill $r) => "{$r->first_weight_kg} kg / {$r->second_weight_kg} kg → ".ltrim(bcsub((string) $r->first_weight_kg, (string) $r->second_weight_kg, 3), '-').' kg'),
            ]),
            RepeatableEntry::make('lines')->label(__('Lines'))->columns(6)->schema([
                TextEntry::make('description')->label(__('Item'))->formatStateUsing(fn (string $state, QuickBillLine $l) => ($l->direction === 'in' ? '⬇ ' : ($l->direction === 'out' ? '⬆ ' : '')).$state.($l->variety ? " ({$l->variety})" : ''))->columnSpan(2),
                TextEntry::make('weights')->label(__('Weight'))->state(fn (QuickBillLine $l) => "{$l->gross_kg} − {$l->tare_kg} − ".$l->deductionKg().' = '.$l->net_kg.' kg'
                    .($l->bags ? " · {$l->bags} ".__('bags') : ''))->columnSpan(2),
                TextEntry::make('rate_paise_per_maund')->label(__('Rate / maund'))->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextEntry::make('amount_paise')->label(__('Amount'))->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
            ]),
            InfoSection::make(__('Totals'))->columns(4)->schema([
                TextEntry::make('charges_add_paise')->label(__('Charges added'))->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextEntry::make('charges_less_paise')->label(__('Taken off'))->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextEntry::make('net_paise')->label(__('Bill total'))->weight('bold')->formatStateUsing(fn (int $state) => Units::paiseToRupees(abs($state)).($state < 0 ? ' ('.__('mill pays').')' : '')),
                TextEntry::make('paid_paise')->label(__('Paid'))->formatStateUsing(fn (int $state, QuickBill $r) => Units::paiseToRupees($state).' · '.__('Balance').' '.Units::paiseToRupees($r->balancePaise())),
                TextEntry::make('note')->label(__('Note'))->placeholder('—')->columnSpanFull(),
            ]),
            RepeatableEntry::make('payments')->label(__('Payments'))->columns(4)->visible(fn (QuickBill $r) => $r->payments()->exists())->schema([
                TextEntry::make('paid_at')->label(__('When'))->dateTime('d M Y, h:i A', 'Asia/Karachi'),
                TextEntry::make('mode')->label(__('How'))->formatStateUsing(fn (string $state) => __(QuickBill::PAYMENT_MODES[$state] ?? $state)),
                TextEntry::make('reference')->label(__('Cheque / transaction no.'))->placeholder('—'),
                TextEntry::make('amount_paise')->label(__('Amount'))->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuickBills::route('/'),
            'create' => Pages\CreateQuickBill::route('/create'),
            'view' => Pages\ViewQuickBill::route('/{record}'),
        ];
    }
}
