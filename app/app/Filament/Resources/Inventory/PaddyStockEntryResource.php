<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory;

use App\Domain\Gate\GateService;
use App\Domain\Inventory\PaddyStockEntries;
use App\Domain\Security\EntryLocation;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Resources\Inventory\PaddyStockEntryResource\Pages;
use App\Filament\Support\ProductSelect;
use App\Filament\Support\Units;
use App\Filament\Support\VarietySelect;
use App\Models\Gate\GateEntry;
use App\Models\Inventory\PaddyStockEntry;
use App\Models\Inventory\StockEntry;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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

/** Stock & production → Paddy stock entry: paddy received into a godown, by hand or against a gate token. */
class PaddyStockEntryResource extends Resource
{
    protected static ?string $model = PaddyStockEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Paddy stock entry';

    protected static ?string $modelLabel = 'paddy stock entry';

    protected static ?string $slug = 'paddy-stock-entries';

    protected static ?int $navigationSort = 15;

    /** @return array<string, string> inbound tokens of the last 60 days that can still be received into stock */
    public static function tokenOptions(): array
    {
        return GateEntry::query()->with('party')->where('direction', 'inbound')->where('status', '!=', 'cancelled')
            ->where('arrived_at', '>=', now()->subDays(60))
            ->whereNotIn('id', PaddyStockEntry::query()->whereNotNull('gate_entry_id')->where('status', '!=', PaddyStockEntry::CANCELLED)->select('gate_entry_id'))
            ->whereNotIn('id', StockEntry::query()->where('source_type', 'gate_entry')->where('movement', 'receipt')->select('source_id'))
            ->orderByDesc('arrived_at')->limit(300)->get()
            ->mapWithKeys(fn (GateEntry $e) => [$e->id => trim($e->tokenLabel().' · '.$e->vehicleLabel().' · '.($e->partyLabel() ?? '—').($e->jack_type ? ' · '.$e->jackLabel() : ''))])
            ->all();
    }

    /**
     * The service's input from the form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toInput(array $data): array
    {
        $party = filled($data['party_name'] ?? null) ? GateService::registeredParty((string) $data['party_name']) : null;

        return [
            'entry_date' => $data['entry_date'] ?? null,
            'gate_entry_id' => $data['gate_entry_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'paddy_variety_id' => $data['paddy_variety_id'] ?? null,
            'party_id' => $party?->id,
            'party_name' => $party === null ? ($data['party_name'] ?? null) : null,
            'godown_id' => $data['godown_id'] ?? null,
            'registration_no' => $data['registration_no'] ?? null,
            'sauda_id' => $data['sauda_id'] ?? null,
            'bag_count' => $data['bag_count'] ?? null,
            'gross_kg' => $data['gross_kg'] ?? null,
            'tare_kg' => filled($data['tare_kg'] ?? null) ? $data['tare_kg'] : '0',
            'deduction_override_kg' => ($data['override'] ?? false) ? ($data['deduction_override_kg'] ?? null) : null,
            'deduction_override_reason' => ($data['override'] ?? false) ? ($data['deduction_override_reason'] ?? null) : null,
            'rate_paise_per_maund' => filled($data['rate_rupees'] ?? null) ? Units::rupeesToPaise((string) $data['rate_rupees']) : null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /** The live figures under the form: net, deduction, accepted, amount (or what is still missing). */
    public static function figures(Get $get): string
    {
        if (! filled($get('gross_kg'))) {
            return 'Type the gross weight.';
        }
        try {
            $fields = ['entry_date', 'gate_entry_id', 'product_id', 'paddy_variety_id', 'party_name', 'godown_id', 'registration_no', 'sauda_id',
                'bag_count', 'gross_kg', 'tare_kg', 'override', 'deduction_override_kg', 'deduction_override_reason', 'rate_rupees', 'remarks'];
            $data = array_combine($fields, array_map(fn (string $f) => $get($f), $fields));
            $f = app(PaddyStockEntries::class)->calculate(self::toInput($data));
        } catch (BusinessRuleViolation $e) {
            return $e->getMessage();
        }
        $rules = collect($f['lines'])->map(fn (array $l) => ($l['rule'] ?? $l['method']).' '.$l['deduction_kg'].' kg')->implode(', ');

        return sprintf('Net %s kg · Deduction %s kg%s · Accepted %s kg (%s maund)%s', number_format((float) $f['net_kg'], 3), number_format((float) $f['deduction_kg'], 3),
            $rules === '' ? '' : ' ('.$rules.')', number_format((float) $f['accepted_kg'], 3), Units::kgToMaund($f['accepted_kg']),
            $f['amount_paise'] !== null ? ' · Amount Rs '.Units::paiseToRupees($f['amount_paise']) : '');
    }

    public static function form(Form $form): Form
    {
        $today = fn () => now('Asia/Karachi');

        return $form->columns(3)->schema([
            Section::make('Receipt')->columns(3)->schema([
                TextInput::make('number')->label('Stock entry number')->disabled()->dehydrated(false)->placeholder('Made when saved'),
                DatePicker::make('entry_date')->label('Entry date')->required()->default($today)->maxDate($today)->live(),
                Select::make('gate_entry_id')->label('Linked gate token (optional)')->searchable()->live()
                    ->options(fn () => self::tokenOptions())
                    ->helperText('Tokens already in stock (through their slip or another entry) are not offered.')
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $e = $state === null ? null : GateEntry::query()->with('party')->find($state);
                        if ($e === null) {
                            return;
                        }
                        $set('party_name', $e->party !== null ? $e->party->name.' ('.$e->party->code.')' : $e->party_name);
                        $set('registration_no', $e->no_plate ? null : $e->registration_no);
                        $set('sauda_id', $e->sauda_id);
                        $set('paddy_variety_id', $e->paddy_variety_id);
                        $set('bag_count', $e->bag_count);
                        $set('gross_kg', $e->gross_kg);
                        $set('tare_kg', $e->tare_kg);
                        if ($e->product_id !== null) {
                            $set('product_id', $e->product_id);
                        }
                    }),
                VarietySelect::make()->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $ids = $state === null ? [] : Product::query()->where('category', 'paddy')->where('status', 'active')
                            ->where('paddy_variety_id', $state)->limit(2)->pluck('id')->all();
                        if (count($ids) === 1) {
                            $set('product_id', $ids[0]);
                        }
                    }),
                ProductSelect::make('product_id', ['paddy'])->label('Paddy product')->required()->live(),
                Select::make('godown_id')->label('Warehouse / godown')->required()->options(fn () => RequisitionResource::godownOptions()),
                TextInput::make('party_name')->label('Supplier / party')->maxLength(160)->autocomplete('off')
                    ->datalist(fn () => GateEntryResource::partySuggestions())
                    ->helperText('A registered party, or any name as typed.'),
                TextInput::make('registration_no')->label('Vehicle number')->maxLength(20),
                Select::make('sauda_id')->label('Linked purchase sauda')->searchable()
                    ->options(fn () => Sauda::query()->where('direction', 'purchase')->whereIn('status', [Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY])
                        ->with('party')->get()->mapWithKeys(fn (Sauda $s) => [$s->id => $s->number.' — '.$s->party->name])->all()),
            ]),
            Section::make('Weights')->columns(3)->schema([
                TextInput::make('bag_count')->label('Number of bags')->integer()->minValue(0)->live(debounce: 500),
                TextInput::make('gross_kg')->label('Gross weight (kg)')->required()->regex(Units::DECIMAL_3)->live(debounce: 500),
                TextInput::make('tare_kg')->label('Tare weight (kg)')->regex(Units::DECIMAL_3)->default('0')->live(debounce: 500),
                Toggle::make('override')->label('Type the deduction by hand (with a reason)')->live()->columnSpanFull(),
                TextInput::make('deduction_override_kg')->label('Deduction weight (kg)')->regex(Units::DECIMAL_3)->live(debounce: 500)
                    ->visible(fn (Get $get) => (bool) $get('override'))->required(fn (Get $get) => (bool) $get('override')),
                TextInput::make('deduction_override_reason')->label('Reason')->minLength(5)->maxLength(300)->columnSpan(2)
                    ->visible(fn (Get $get) => (bool) $get('override'))->required(fn (Get $get) => (bool) $get('override')),
                TextInput::make('rate_rupees')->label('Rate (Rs per maund)')->regex(Units::DECIMAL_2)->live(debounce: 500),
                Placeholder::make('figures')->label('Net · deduction · accepted · amount')->columnSpan(2)->content(fn (Get $get) => self::figures($get)),
            ]),
            Textarea::make('remarks')->maxLength(1000)->columnSpanFull(),
            ...EntryLocation::fields(),
            Toggle::make('post')->label('Post to stock now')->default(true)
                ->helperText('Off: saved as a draft that changes no stock until posted.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'paddyVariety', 'godown', 'party', 'gateEntry']))
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('entry_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('token')->label('Token')->state(fn (PaddyStockEntry $r) => $r->gateEntry?->tokenLabel())->placeholder('manual')->fontFamily('mono'),
                TextColumn::make('product.name')->label('Paddy')->description(fn (PaddyStockEntry $r) => $r->paddyVariety?->name),
                TextColumn::make('party')->label('Supplier')->state(fn (PaddyStockEntry $r) => $r->partyLabel())->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search) => $query->orWhereRaw('LOWER(party_name) LIKE ?', ['%'.mb_strtolower($search).'%'])),
                TextColumn::make('godown.name')->label('Godown'),
                TextColumn::make('registration_no')->label('Vehicle')->fontFamily('mono')->placeholder('—')->searchable(),
                TextColumn::make('net_kg')->label('Net (kg)')->alignEnd()->numeric(3),
                TextColumn::make('deduction_kg')->label('Deduction (kg)')->alignEnd()->numeric(3)->toggleable(),
                TextColumn::make('accepted_kg')->label('Accepted (kg)')->alignEnd()->numeric(3)->weight('bold'),
                TextColumn::make('amount_paise')->label('Amount')->alignEnd()->formatStateUsing(fn (?int $state) => Units::paiseToRupees($state))->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => PaddyStockEntry::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        PaddyStockEntry::POSTED => 'success', PaddyStockEntry::CANCELLED => 'danger', default => 'warning',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(PaddyStockEntry::STATUS_LABELS),
                SelectFilter::make('godown_id')->label('Godown')->relationship('godown', 'name'),
                SelectFilter::make('paddy_variety_id')->label('Variety')->relationship('paddyVariety', 'name'),
                Filter::make('dates')->form([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '<=', $d))),
            ])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => PaddyStockEntry::STATUS_LABELS[$state] ?? $state),
                TextEntry::make('entry_date')->date('d M Y'),
                TextEntry::make('token')->label('Gate token')->state(fn (PaddyStockEntry $r) => $r->gateEntry?->tokenLabel())->placeholder('Manual entry')
                    ->url(fn (PaddyStockEntry $r) => $r->gate_entry_id ? GateEntryResource::getUrl('view', ['record' => $r->gate_entry_id]) : null),
                TextEntry::make('product.name')->label('Paddy product'),
                TextEntry::make('paddyVariety.name')->label('Variety')->placeholder('—'),
                TextEntry::make('party')->label('Supplier')->state(fn (PaddyStockEntry $r) => $r->partyLabel())->placeholder('—'),
                TextEntry::make('godown.name')->label('Godown'),
                TextEntry::make('registration_no')->label('Vehicle')->placeholder('—'),
                TextEntry::make('sauda.number')->label('Sauda')->placeholder('—'),
                TextEntry::make('bag_count')->label('Bags')->placeholder('—'),
                TextEntry::make('captured_location')->label('Entered at')
                    ->state(fn (PaddyStockEntry $r) => EntryLocation::label($r->captured_location))->url(fn (PaddyStockEntry $r) => EntryLocation::mapUrl($r->captured_location), shouldOpenInNewTab: true)
                    ->visible(fn (PaddyStockEntry $r) => $r->captured_location !== null)->color('primary'),
                TextEntry::make('rate_paise_per_maund')->label('Rate / maund')->formatStateUsing(fn (?int $state) => Units::paiseToRupees($state))->placeholder('—'),
            ]),
            InfoSection::make('Weights')->columns(6)->schema([
                TextEntry::make('gross_kg')->label('Gross (kg)'),
                TextEntry::make('tare_kg')->label('Tare (kg)'),
                TextEntry::make('net_kg')->label('Net (kg)')->weight('bold'),
                TextEntry::make('deduction_kg')->label('Deduction (kg)'),
                TextEntry::make('accepted_kg')->label('Accepted (kg)')->weight('bold'),
                TextEntry::make('amount_paise')->label('Amount')->formatStateUsing(fn (?int $state) => Units::paiseToRupees($state))->placeholder('—'),
                TextEntry::make('deduction_lines')->label('Deduction applied')->columnSpanFull()->placeholder('None')
                    ->state(fn (PaddyStockEntry $r) => collect($r->deduction_lines ?? [])->map(fn (array $l) => ($l['rule'] ?? $l['method']).': '.$l['deduction_kg'].' kg'
                        .(isset($l['reason']) ? ' — '.$l['reason'] : ''))->implode(' · ') ?: null),
            ]),
            TextEntry::make('remarks')->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaddyStockEntries::route('/'),
            'create' => Pages\CreatePaddyStockEntry::route('/create'),
            'view' => Pages\ViewPaddyStockEntry::route('/{record}'),
        ];
    }
}
