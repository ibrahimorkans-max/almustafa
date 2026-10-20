<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate;

use App\Domain\Gate\GateService;
use App\Domain\Gate\LabStandards;
use App\Filament\Resources\Gate\GateEntryResource\Pages;
use App\Filament\Support\AttachmentsPanel;
use App\Filament\Support\HidesArchived;
use App\Filament\Support\Units;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\GatePriceVersion;
use App\Models\Gate\LabTest;
use App\Models\Gate\Weighment;
use App\Models\Masters\Party;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use Illuminate\Database\Eloquent\Builder;

class GateEntryResource extends Resource
{
    use HidesArchived;

    protected static ?string $model = GateEntry::class;

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
        'tare_deviation' => 'Tare differs from usual', 'manual_override' => 'Manual weight (approved)', 'out_of_order' => 'Steps out of order',
        'net_not_positive' => 'Net not positive', 'no_godown' => 'Not booked into stock (no godown)',
        'plate_mismatch' => 'Plate read ≠ typed number', 'barrier_manual_open' => 'Barrier opened by hand',
        'quantity_out_of_tolerance' => 'Load outside order tolerance', 'bag_weight_mismatch' => 'Bags × size ≠ net',
        'dispatch_order_not_open' => 'Dispatch order not cleared',
        'live_screen' => 'Recorded on the Live weight screen', 'reweighed' => 'Weighed again',
        'held_quarantine' => 'Rejected lot held (quarantine)',
    ];

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
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
            Select::make('party_id')->label('Party (if no sauda)')->searchable()->preload()
                ->options(fn () => Party::query()->orderBy('name')->pluck('name', 'id')),
            TextInput::make('driver_name')->maxLength(120),
            TextInput::make('bag_count')->label('Number of bags')->integer()->minValue(0),
        ]);
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
                TextColumn::make('number')->searchable(),
                TextColumn::make('registration_no')->label('Vehicle')->searchable(['registration_no', 'vehicle_description'])->fontFamily('mono')
                    ->formatStateUsing(fn (GateEntry $record) => $record->no_plate ? 'No plate' : $record->registration_no)
                    ->description(fn (GateEntry $record) => $record->no_plate ? Vehicle::typeLabel($record->vehicle_type).' · '.$record->vehicle_description
                        : ($record->vehicle_type ? Vehicle::typeLabel($record->vehicle_type) : null)),
                TextColumn::make('party.name')->label('Party')->placeholder('—'),
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
            ])
            ->poll('15s')
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('registration_no')->label('Vehicle')->fontFamily('mono')
                    ->formatStateUsing(fn (GateEntry $record) => $record->vehicleLabel()),
                TextEntry::make('vehicle_type')->label('Kind of vehicle')->formatStateUsing(fn (?string $state) => Vehicle::typeLabel($state))->placeholder('—'),
                TextEntry::make('status')->badge()->color(fn (string $state) => static::statusColor($state)),
                TextEntry::make('direction')->badge(),
                TextEntry::make('party.name')->label('Party')->placeholder('—'),
                TextEntry::make('sauda.number')->label('Sauda')->placeholder('—'),
                TextEntry::make('product.name')->label('Product')->placeholder('—'),
                TextEntry::make('bag_count')->label('Bags')->placeholder('—'),
                TextEntry::make('godown.name')->label('Unloaded into')->placeholder('—'),
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
            Section::make('Lab results against the limits')->schema([
                TextEntry::make('lab_evaluation')->hiddenLabel()->html()
                    ->state(function (GateEntry $record): string {
                        $test = $record->labTests()->orderByDesc('tested_at')->first();
                        $rows = (array) ($test->evaluation ?? []);
                        if ($rows === []) {
                            return '<span class="text-sm text-gray-500">No result for the configured lab tests yet.</span>';
                        }

                        return '<ul class="space-y-1 text-sm">'.implode('', array_map(fn (array $r) => '<li class="'.match ($r['verdict']) {
                            LabStandards::OVER => 'font-semibold text-danger-600', LabStandards::GRACE => 'text-warning-600', default => '',
                        }.'">'.e(LabStandards::describe($r)).'</li>', $rows)).'</ul>';
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
