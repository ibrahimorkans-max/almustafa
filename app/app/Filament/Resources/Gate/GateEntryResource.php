<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate;

use App\Domain\Gate\GateService;
use App\Domain\Gate\LabReport;
use App\Domain\Gate\LabStandards;
use App\Domain\Hr\ContractorVehicles;
use App\Domain\Security\EntryLocation;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource\Pages;
use App\Filament\Resources\Hr\ContractorBillResource;
use App\Filament\Resources\Hr\ContractorRateResource;
use App\Filament\Support\AttachmentsPanel;
use App\Filament\Support\HidesArchived;
use App\Filament\Support\Units;
use App\Filament\Support\VarietySelect;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\GatePriceVersion;
use App\Models\Gate\LabTest;
use App\Models\Gate\Weighment;
use App\Models\Hr\ContractorRate;
use App\Models\Masters\Party;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class GateEntryResource extends Resource
{
    use HidesArchived;

    protected static ?string $model = GateEntry::class;

    protected static ?string $recordTitleAttribute = 'number';

    /** Found by the search box in the top bar (partial match, this mill only, and only for people who may open the list). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'registration_no', 'party_name'];
    }

    /**
     * A token number (12 or 000012), a vehicle number typed with or without dashes, a gate number or a party name.
     *
     * @param  Builder<GateEntry>  $query
     */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $like = '%'.addcslashes(trim($search), '%_\\').'%';
        $plate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $search));
        $query->where(function (Builder $q) use ($like, $plate): void {
            $q->where('number', 'ilike', $like)->orWhere('party_name', 'ilike', $like)
                ->orWhereHas('party', fn (Builder $p) => $p->where('name', 'ilike', $like));
            if ($plate !== '') {
                $q->orWhere('registration_no', 'ilike', '%'.$plate.'%');
            }
            if ($plate !== '' && ctype_digit($plate) && strlen($plate) <= 9) {
                $q->orWhere('token_serial', (int) $plate);
            }
        });
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var GateEntry $record */
        return 'Token '.$record->tokenLabel().' · '.$record->vehicleLabel();
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var GateEntry $record */
        return array_filter([
            'Party' => $record->partyLabel(), 'Date' => $record->arrived_at->timezone('Asia/Karachi')->format('d M Y'), 'Status' => str_replace('_', ' ', $record->status),
        ]);
    }

    /** @return Builder<GateEntry> */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('party')->latest('arrived_at');
    }

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Vehicles at gate';

    protected static ?string $modelLabel = 'gate entry';

    protected static ?string $slug = 'gate';

    protected static ?int $navigationSort = 10;

    /** @var array<string, string> */
    public const FLAG_LABELS = [
        'unknown_vehicle' => 'Unknown vehicle', 'blocked_vehicle' => 'BLOCKED vehicle', 'sauda_not_open' => 'Sauda not open',
        'stability_unverified' => 'Weight not proven stable', 'no_photo' => 'No camera photo', 'scale_certificate_invalid' => 'Scale certificate invalid',
        'tare_deviation' => 'Tare differs from usual', 'manual_override' => 'Manual weight', 'manual_entry' => 'Weight typed by hand', 'no_slip_evidence' => 'Typed weight without slip evidence', 'out_of_order' => 'Steps out of order',
        'net_not_positive' => 'Net not positive', 'no_godown' => 'Not booked into stock (no godown)',
        'stock_by_entry' => 'In stock through a paddy stock entry',
        'partly_rejected' => 'Part of the paddy rejected at unloading', 'vehicle_corrected' => 'Vehicle number corrected',
        'plate_mismatch' => 'Plate read ≠ typed number', 'barrier_manual_open' => 'Barrier opened by hand',
        'quantity_out_of_tolerance' => 'Load outside order tolerance', 'bag_weight_mismatch' => 'Bags × size ≠ net',
        'dispatch_order_not_open' => 'Dispatch order not cleared',
        'live_screen' => 'Recorded on the Live weight screen', 'reweighed' => 'Weighed again',
        'held_quarantine' => 'Rejected lot held (quarantine)',
    ];

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            // A contractor token: the contractor, then one of its vehicles (fills the number), its rate shown.
            Select::make('contractor_party_id')->label('Labour contractor (contractor token)')->searchable()->live()
                ->options(fn () => ContractorBillResource::contractors())
                ->afterStateUpdated(fn (Set $set) => $set('contractor_vehicle_id', null))
                ->helperText('Optional. Same token series; the contractor is paid by the final weight.'),
            Select::make('contractor_vehicle_id')->label('Contractor vehicle')->searchable()->live()->dehydrated(false)
                ->visible(fn (Get $get) => filled($get('contractor_party_id')))
                ->options(fn (Get $get) => ContractorRateResource::vehicleOptions($get('contractor_party_id')))
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $v = $state ? Vehicle::query()->find($state) : null;
                    if ($v !== null) {
                        $set('no_plate', false);
                        $set('registration_no', $v->registration_no);
                        $set('vehicle_type', $v->vehicle_type);
                    }
                })
                ->helperText(fn (Get $get) => self::contractorRateHint($get('contractor_party_id'), $get('contractor_vehicle_id'), $get('contractor_work'))),
            Select::make('contractor_work')->label('Contractor work')->options(ContractorRate::WORK)->default('unloading')->live()
                ->visible(fn (Get $get) => filled($get('contractor_party_id')))->required(fn (Get $get) => filled($get('contractor_party_id'))),
            Toggle::make('no_plate')->label('No number plate (bail gari, donkey cart, bicycle, rehri…)')->live()->columnSpanFull(),
            TextInput::make('registration_no')->label('Vehicle registration no.')->maxLength(20)->placeholder('MNA-21-1234')
                ->required(fn (Get $get) => ! $get('no_plate'))->hidden(fn (Get $get) => (bool) $get('no_plate'))
                ->datalist(fn () => Vehicle::query()->orderBy('registration_no')->limit(500)->pluck('registration_no')->all()),
            Select::make('vehicle_type')->label('Kind of vehicle')->options(Vehicle::LABELS)->searchable()
                ->required(fn (Get $get) => (bool) $get('no_plate'))
                ->helperText('Leave empty for a registered vehicle to use its kind from the vehicle list.'),
            TextInput::make('vehicle_description')->label('Description (to know it by)')->maxLength(160)
                ->placeholder('e.g. Bail gari of Rasheed, Basti Malook, white bulls')
                ->visible(fn (Get $get) => (bool) $get('no_plate'))->required(fn (Get $get) => (bool) $get('no_plate'))->columnSpanFull(),
            Select::make('direction')->options(['inbound' => 'Inbound (paddy in)', 'outbound' => 'Outbound (dispatch)'])->default('inbound')->required()->live(),
            Select::make('dispatch_order_id')->label('Dispatch order')->searchable()
                ->visible(fn (Get $get) => $get('direction') === 'outbound')
                ->required(fn (Get $get) => $get('direction') === 'outbound')
                ->options(fn () => DispatchOrder::query()->where('status', DispatchOrder::OPEN)->with(['party', 'product'])->get()
                    ->mapWithKeys(fn (DispatchOrder $o) => [$o->id => "{$o->number} — {$o->party->name} · {$o->product->name} · {$o->quantity_kg} kg"])),
            Select::make('sauda_id')->label('Against sauda')->searchable()
                ->options(fn () => Sauda::query()->whereIn('status', [Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY])->with('party')->get()
                    ->mapWithKeys(fn (Sauda $s) => [$s->id => "{$s->number} — {$s->party->name} ({$s->remaining()->in('maund', 2)} maund left)"])),
            Radio::make('jack_type')->label('Type')->options(GateEntry::JACK_TYPES)->inline()->required()
                ->helperText('Printed on the token beside its number.'),
            TextInput::make('party_name')->label('Party name (if no sauda)')->maxLength(160)->autocomplete('off')
                ->placeholder('Type a name: registered parties are suggested')
                ->datalist(fn () => self::partySuggestions())
                ->helperText('Pick a suggestion for a registered party, or type any name: no party record is made for it.'),
            VarietySelect::make()->visible(fn (Get $get) => $get('direction') !== 'outbound'),
            TextInput::make('driver_name')->maxLength(120),
            TextInput::make('bag_count')->label('Number of bags')->integer()->minValue(0),
            ...EntryLocation::fields(),
        ]);
    }

    /** On the token: its bill, or the charge once the weight is final, or that it waits for the weight. */
    public static function contractorChargeHint(GateEntry $record): string
    {
        if ($record->contractorBill !== null) {
            return 'Billed: '.$record->contractorBill->number;
        }
        try {
            $line = app(ContractorVehicles::class)->line($record->contractor, $record);

            return 'Charge '.Units::paiseToRupees($line['amount_paise']).' ('.($line['source']['basis_label'] ?? $line['unit']).'), not billed yet';
        } catch (BusinessRuleViolation $e) {
            return $e->getMessage();
        }
    }

    /** The contractor's rate today for the vehicle chosen at the gate. */
    public static function contractorRateHint(?string $partyId, ?string $vehicleId, ?string $work): string
    {
        $party = $partyId ? Party::query()->find($partyId) : null;
        if ($party === null) {
            return '';
        }
        if (ContractorRateResource::vehicleOptions($partyId) === []) {
            return 'This contractor has no vehicles listed: type the number below, or add its vehicles under HR → Contractor rates.';
        }
        $vehicle = $vehicleId ? Vehicle::query()->find($vehicleId) : null;
        $rate = app(ContractorVehicles::class)->rateOn($party, $work ?: 'unloading', now('Asia/Karachi')->toDateString(), $vehicle?->id, $vehicle?->vehicle_type);

        return $rate === null ? 'No rate for this vehicle today: set it under HR → Contractor rates before billing.'
            : "Rate {$rate->code}: ".Units::paiseToRupees($rate->rate_paise).' '.mb_strtolower($rate->basisLabel()).' of the final weight.';
    }

    /** @return list<string> "Name (CODE)": the browser shows those containing what is typed (name or code) */
    public static function partySuggestions(): array
    {
        return Party::query()->where('status', 'active')->orderBy('name')->limit(1000)->get(['name', 'code'])
            ->map(fn (Party $p) => $p->name.' ('.$p->code.')')->all();
    }

    /** "50 bags, 2,500 kg: on the vehicle at the empty weight": what was rejected and how net was worked out. */
    public static function rejectionLabel(GateEntry $e): string
    {
        $what = implode(', ', array_filter([$e->rejected_bags > 0 ? $e->rejected_bags.' bags' : null, $e->rejected_kg !== null ? number_format((float) $e->rejected_kg, 0).' kg' : null]));

        return $what.($e->rejected_off_vehicle ? ': taken off and kept aside, subtracted from net' : ': on the vehicle at the empty weight, so net is the paddy unloaded');
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'exited' => 'success', 'cancelled', 'lab_rejected' => 'danger', 'arrived' => 'gray', default => 'warning',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('token_serial')->label('Token')->weight('bold')->fontFamily('mono')->sortable()
                    ->state(fn (GateEntry $record) => $record->tokenLabel())
                    ->description(fn (GateEntry $record) => $record->jackLabel())
                    ->searchable(query: fn (Builder $query, string $search) => ctype_digit($search) ? $query->orWhere('token_serial', (int) $search) : $query),
                TextColumn::make('number')->searchable(),
                TextColumn::make('registration_no')->label('Vehicle')->searchable(['registration_no', 'vehicle_description'])->fontFamily('mono')
                    ->formatStateUsing(fn (GateEntry $record) => $record->no_plate ? 'No plate' : $record->registration_no)
                    ->description(fn (GateEntry $record) => $record->no_plate ? Vehicle::typeLabel($record->vehicle_type).' · '.$record->vehicle_description
                        : ($record->vehicle_type ? Vehicle::typeLabel($record->vehicle_type) : null)),
                TextColumn::make('party_label')->label('Party')->placeholder('—')
                    ->state(fn (GateEntry $record) => $record->partyLabel())
                    ->description(fn (GateEntry $record) => $record->party_id === null && $record->party_name !== null ? 'not registered' : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->orWhereRaw('LOWER(party_name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                        ->orWhereIn('party_id', Party::query()->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])->select('id'))),
                TextColumn::make('contractor.name')->label('Contractor')->placeholder('—')->toggleable()
                    ->description(fn (GateEntry $record) => $record->contractor_party_id === null ? null : ($record->contractor_bill_id !== null ? 'Billed' : 'Not billed yet')),
                TextColumn::make('item')->label('Item')->state(fn (GateEntry $record) => $record->itemLabel())->toggleable(),
                TextColumn::make('sauda.number')->label('Sauda')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => static::statusColor($state)),
                TextColumn::make('net_kg')->label('Net (maund)')->formatStateUsing(fn (?string $state) => Units::kgToMaund($state))->alignEnd()->placeholder('—'),
                TextColumn::make('payable_kg')->label('Payable (maund)')->formatStateUsing(fn (?string $state) => Units::kgToMaund($state))->alignEnd()->placeholder('—'),
                TextColumn::make('flags')->badge()->color('danger')->separator(',')
                    ->formatStateUsing(fn (string $state) => self::FLAG_LABELS[$state] ?? $state)->placeholder(''),
                TextColumn::make('arrived_at')->label('Arrived')->dateTime('d M H:i')->sortable(),
            ])
            ->defaultSort('arrived_at', 'desc')
            ->filters([
                SelectFilter::make('open')->label('Show')->options(['open' => 'Inside the mill', 'all' => 'All'])->default('open')
                    ->query(fn (Builder $q, array $data) => ($data['value'] ?? 'open') === 'open' ? $q->whereNotIn('status', ['exited', 'cancelled']) : $q),
                SelectFilter::make('jack_type')->label('Type')->options(GateEntry::JACK_TYPES),
                SelectFilter::make('contractor')->label('Contractor')->options(fn () => ContractorBillResource::contractors())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('contractor_party_id', $data['value']) : $query),
                SelectFilter::make('contractor_billed')->label('Contractor bill')->options(['unbilled' => 'Not billed yet', 'billed' => 'Billed'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'unbilled' => $query->whereNotNull('contractor_party_id')->whereNull('contractor_bill_id'),
                        'billed' => $query->whereNotNull('contractor_bill_id'),
                        default => $query,
                    }),
                SelectFilter::make('status')->options(fn () => GateEntry::query()->distinct()->orderBy('status')->pluck('status', 'status')->all()),
                Filter::make('arrived')->form([DatePicker::make('from')->label('Arrived from'), DatePicker::make('until')->label('Arrived until')])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->where('arrived_at', '>=', Carbon::parse($d, 'Asia/Karachi')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->where('arrived_at', '<=', Carbon::parse($d, 'Asia/Karachi')->endOfDay()->utc()))),
            ])
            ->poll('15s')
            ->actions([ViewAction::make()])
            ->bulkActions([
                // One contractor unloaded (or loaded) many vehicles: link them all at once.
                BulkAction::make('assignContractor')->label('Assign labour contractor')->icon('heroicon-o-user-group')
                    ->visible(fn () => Filament::auth()->user()?->can('gate.entry') ?? false)
                    ->form([
                        Select::make('contractor_party_id')->label('Contractor')->required()->searchable()->options(fn () => ContractorBillResource::contractors()),
                        Select::make('work')->options(ContractorRate::WORK)->default('unloading')->required(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        try {
                            $n = app(ContractorVehicles::class)->assign($records->filter(fn ($r) => $r instanceof GateEntry), Party::query()->findOrFail($data['contractor_party_id']), (string) $data['work']);
                            Notification::make()->success()->title("{$n} vehicle(s) linked to the contractor.")->send();
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('token')->label('Token number')->state(fn (GateEntry $record) => $record->tokenLabel())->weight('bold')->fontFamily('mono'),
                TextEntry::make('jack_type')->label('Type')->state(fn (GateEntry $record) => $record->jackLabel())->placeholder('—'),
                TextEntry::make('token_date_shown')->label('Date')->state(fn (GateEntry $record) => $record->arrived_at->timezone('Asia/Karachi')->format('d-m-Y')),
                TextEntry::make('token_time_shown')->label('Time')->state(fn (GateEntry $record) => $record->arrived_at->timezone('Asia/Karachi')->format('h:i A')),
                TextEntry::make('number'),
                TextEntry::make('registration_no')->label('Vehicle')->fontFamily('mono')
                    ->formatStateUsing(fn (GateEntry $record) => $record->vehicleLabel()),
                TextEntry::make('vehicle_type')->label('Kind of vehicle')->formatStateUsing(fn (?string $state) => Vehicle::typeLabel($state))->placeholder('—'),
                TextEntry::make('status')->badge()->color(fn (string $state) => static::statusColor($state)),
                TextEntry::make('direction')->badge(),
                TextEntry::make('party_label')->label('Party')->placeholder('—')->state(fn (GateEntry $record) => $record->partyLabel())
                    ->helperText(fn (GateEntry $record) => $record->party_id === null && $record->party_name !== null ? 'Typed at the gate; not a registered party.' : null),
                TextEntry::make('sauda.number')->label('Sauda')->placeholder('—'),
                TextEntry::make('paddyVariety.name')->label('Paddy variety')->placeholder('—'),
                TextEntry::make('product.name')->label('Product / item')->placeholder('—')
                    ->state(fn (GateEntry $record) => $record->itemLabel()),
                TextEntry::make('bag_count')->label('Bags')->placeholder('—'),
                TextEntry::make('godown.name')->label('Unloaded into')->placeholder('—'),
                TextEntry::make('contractor.name')->label('Labour contractor')->placeholder('—')
                    ->helperText(fn (GateEntry $record) => $record->contractor_party_id === null ? null : self::contractorChargeHint($record)),
                TextEntry::make('rejected_bags')->label('Rejected while unloading')->visible(fn (GateEntry $record) => $record->rejected_bags > 0 || $record->rejected_kg !== null)
                    ->state(fn (GateEntry $record) => self::rejectionLabel($record))->helperText(fn (GateEntry $record) => $record->rejection_reason)->color('danger'),
                TextEntry::make('captured_location')->label('Entered at')
                    ->state(fn (GateEntry $r) => EntryLocation::label($r->captured_location))->url(fn (GateEntry $r) => EntryLocation::mapUrl($r->captured_location), shouldOpenInNewTab: true)
                    ->visible(fn (GateEntry $r) => $r->captured_location !== null)->color('primary'),
                TextEntry::make('flags')->badge()->color('danger')->columnSpanFull()
                    ->formatStateUsing(fn (string $state) => self::FLAG_LABELS[$state] ?? $state)->placeholder('No flags'),
            ]),
            Section::make('Weights')->columns(6)->schema([
                TextEntry::make('gross_kg')->label('Gross (kg)')->placeholder('—'),
                TextEntry::make('tare_kg')->label('Tare (kg)')->placeholder('—'),
                TextEntry::make('net_kg')->label('Net (kg)')->placeholder('—')->weight('bold'),
                TextEntry::make('deduction_kg')->label('Deduction (kg)')->placeholder('—'),
                TextEntry::make('payable_kg')->label('Payable (kg)')->placeholder('—')->weight('bold'),
                TextEntry::make('rate_cut_paise_per_maund')->label('Rate cut / maund')->formatStateUsing(fn (?int $state) => Units::paiseToRupees($state))->placeholder('—'),
            ]),
            Section::make('Weighments (from the indicator)')->schema([
                RepeatableEntry::make('weighments')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('captured_at')->label('When')->dateTime('d M H:i:s'),
                    TextEntry::make('kind')->badge(),
                    TextEntry::make('weight_kg')->label('kg')->fontFamily('mono'),
                    TextEntry::make('source')->badge()->color(fn (string $state) => $state === Weighment::SOURCE_MANUAL ? 'danger' : 'gray'),
                    TextEntry::make('flags')->badge()->color('warning')->formatStateUsing(fn (string $state) => self::FLAG_LABELS[$state] ?? $state)->placeholder('—'),
                    TextEntry::make('photo_sha256')->label('Photo')->formatStateUsing(fn (?string $state) => $state ? substr($state, 0, 12).'…' : '—'),
                ]),
            ]),
            Section::make('Lab report')->schema([
                TextEntry::make('lab_report')->hiddenLabel()->html()
                    ->state(function (GateEntry $record): string {
                        $report = LabReport::for($record);
                        if ($report['test'] === null) {
                            return '<span class="text-sm text-gray-500">No lab result yet.</span>';
                        }
                        $rows = array_map(fn (array $r) => '<li class="'.match ($r['verdict']) {
                            LabStandards::OVER => 'font-semibold text-danger-600', LabStandards::GRACE => 'text-warning-600', default => '',
                        }.'">'.e(LabStandards::describe($r)).'</li>', $report['rows']);
                        $decision = LabReport::decisionLabel($report);
                        $html = '<div class="space-y-2 text-sm"><ul class="space-y-1">'.implode('', $rows).'</ul>'
                            .'<div><span class="text-gray-500">Decision:</span> <strong class="'.($report['decision'] === 'rejected' ? 'text-danger-600' : '').'">'.e($decision).'</strong>'
                            .($report['test']->grade ? ' · grade '.e($report['test']->grade) : '').'</div>';
                        if ($report['rejected_reason']) {
                            $html .= '<div class="rounded-lg bg-danger-50 p-2 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400"><strong>Rejected because:</strong> '.e($report['rejected_reason']).'</div>';
                        }
                        if ($report['after_negotiation'] && $report['accepted_reason']) {
                            $html .= '<div><span class="text-gray-500">Taken in because:</span> '.e($report['accepted_reason']).'</div>';
                        }
                        if ($report['corrections'] > 0) {
                            $html .= '<div class="text-xs text-gray-500">Result corrected '.$report['corrections'].'× (see the timeline for each correction and its reason).</div>';
                        }

                        return $html.'</div>';
                    }),
            ])->visible(fn (GateEntry $record) => $record->direction === 'inbound'),
            Section::make('Negotiation (price versions)')->schema([
                RepeatableEntry::make('priceVersions')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('version')->label('Version'),
                    TextEntry::make('price_paise_per_maund')->label('Rs / maund')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                    TextEntry::make('status')->badge()->color(fn (string $state) => match ($state) {
                        GatePriceVersion::ACCEPTED => 'success', GatePriceVersion::REJECTED => 'danger', GatePriceVersion::PROPOSED => 'warning', default => 'gray',
                    }),
                    TextEntry::make('reason')->placeholder('—'),
                    TextEntry::make('proposer.username')->label('Proposed by')->placeholder('—'),
                    TextEntry::make('decider.username')->label('Decided by')->placeholder('—'),
                ]),
            ])->visible(fn (GateEntry $record) => $record->priceVersions()->exists())->collapsible(),
            Section::make('Caat and price settlement')->columns(4)->schema([
                TextEntry::make('s_waste')->label('Waste Caat')->state(fn (GateEntry $r) => self::settlement($r)['waste_caat_kg'] ?? null)->suffix(' kg')->placeholder('—'),
                TextEntry::make('s_per_maund')->label('Caat per Maund')->state(fn (GateEntry $r) => ($s = self::settlement($r)) ? $s['per_maund_caat_kg'].' kg ('.$s['per_maund_rate_kg'].' kg × maunds)' : null)->placeholder('—'),
                TextEntry::make('s_payable')->label('Payable weight')->weight('bold')->state(fn (GateEntry $r) => ($s = self::settlement($r)) ? $s['payable_kg'].' kg ('.Units::kgToMaund((string) $s['payable_kg']).' maund)' : null)->placeholder('—'),
                TextEntry::make('s_price')->label('Settled price')->state(fn (GateEntry $r) => ($s = self::settlement($r)) ? Units::paiseToRupees((int) $s['price_paise_per_maund']).' / maund' : null)->placeholder('—'),
                TextEntry::make('s_status')->label('Status')->badge()->state(fn (GateEntry $r) => match (true) {
                    app(GateService::class)->settlementApproved($r) => 'approved',
                    $r->events()->where('event', 'settlement_proposed')->exists() => 'waiting for approval',
                    default => 'not proposed',
                })->color(fn (string $state) => $state === 'approved' ? 'success' : 'warning'),
            ])->visible(fn (GateEntry $record) => $record->direction === 'inbound' && $record->tare_kg !== null),
            Section::make('Lab (older fixed results)')->collapsed()->schema([
                RepeatableEntry::make('labTests')->hiddenLabel()->columns(8)->schema([
                    TextEntry::make('tested_at')->label('When')->dateTime('d M H:i'),
                    ...collect(LabTest::PARAMETERS)->map(fn (string $col, string $param) => TextEntry::make($col)->label(ucfirst(str_replace('_', ' ', $param)))
                        ->formatStateUsing(fn (?int $state) => Units::bpToPercent($state))->placeholder('—'))->values()->all(),
                    TextEntry::make('grade')->placeholder('—'),
                ]),
            ]),
            Section::make('Gate hardware (plate reads, barrier)')->schema([
                RepeatableEntry::make('deviceEvents')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('occurred_at')->label('When')->dateTime('d M H:i:s'),
                    TextEntry::make('kind')->badge()->color(fn (string $state) => match ($state) {
                        'barrier_opened' => 'success', 'barrier_refused' => 'warning', 'barrier_manual' => 'danger', default => 'gray',
                    }),
                    TextEntry::make('side')->placeholder('—'),
                    TextEntry::make('plate')->placeholder('—')->fontFamily('mono'),
                    TextEntry::make('reason')->placeholder('—'),
                ]),
            ])->collapsible()->visible(fn (GateEntry $record) => $record->deviceEvents()->exists()),
            Section::make('Timeline')->schema([
                RepeatableEntry::make('events')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('occurred_at')->label('When')->dateTime('d M H:i:s'),
                    TextEntry::make('event')->badge(),
                    TextEntry::make('actor.username')->label('Who')->placeholder('device / system'),
                    TextEntry::make('anomaly')->color('danger')->placeholder('—')
                        ->state(fn (GateEvent $record) => $record->anomaly),
                ]),
            ])->collapsible(),
            AttachmentsPanel::section(),
        ]);
    }

    /** @return array<string, mixed>|null the approved settlement, else the latest proposal */
    public static function settlement(GateEntry $record): ?array
    {
        $event = app(GateService::class)->settlementApproved($record)
            ? GateEvent::query()->where('gate_entry_id', $record->id)->where('event', 'settlement_approved')->newestFirst()->first()
            : GateEvent::query()->where('gate_entry_id', $record->id)->where('event', 'settlement_proposed')->newestFirst()->first();

        return $event?->data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGateEntries::route('/'),
            'create' => Pages\CreateGateEntry::route('/arrive'),
            'view' => Pages\ViewGateEntry::route('/{record}'),
        ];
    }
}
