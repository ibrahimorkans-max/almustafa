<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate;

use App\Domain\Gate\GatePasses;
use App\Filament\Resources\Gate\GatePassResource\Pages\ManageGatePasses;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GatePass;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Filament\Facades\Filament;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Gate & weighbridge → Gate passes: party, vehicle, number of bags and weight per bag, with a serial number and
 * a verification code (App\Domain\Gate\GatePasses). Sale passes print with the logo for selling.
 */
class GatePassResource extends Resource
{
    protected static ?string $model = GatePass::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $modelLabel = 'gate pass';

    protected static ?string $slug = 'gate-passes';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'serial';

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->hasPermission('gatepass.issue') || $user->hasPermission('gate.entry'));
    }

    public static function canCreate(): bool
    {
        return GatePasses::canIssue();
    }

    public static function canEdit(Model $record): bool
    {
        return false;   // append-only: cancel and issue a new one
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['serial', 'party_name', 'vehicle_no'];
    }

    /** @return Builder<GatePass> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['party', 'cancellation']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            ToggleButtons::make('purpose')->label('For')->options(['sale' => 'Sale', 'other' => 'Other'])->default('other')->inline()->required()
                ->helperText('Sale: printed with the logo for selling. Other: with the mill\'s logo (Print & branding).'),
            Select::make('gate_entry_id')->label('Vehicle at the gate (optional: fills in the details)')->searchable()->live()
                ->options(fn () => GateEntry::query()->with('party')->whereNotIn('status', ['exited', 'cancelled'])->orderByDesc('arrived_at')->limit(100)->get()
                    ->mapWithKeys(fn (GateEntry $e) => [$e->id => trim(($e->token_serial !== null || $e->token_no !== null ? 'Token '.$e->tokenLabel().' · ' : '')
                        .$e->vehicleLabel().($e->partyLabel() !== null ? ' · '.$e->partyLabel() : ''))]))
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $e = $state !== null ? GateEntry::query()->with(['party', 'product'])->find($state) : null;
                    if ($e === null) {
                        return;
                    }
                    $set('vehicle_no', $e->no_plate ? (string) $e->vehicle_description : $e->registration_no);
                    $set('party_id', $e->party_id);
                    $set('party_name', $e->partyLabel());
                    if ($e->bag_count) {
                        $set('bag_count', $e->bag_count);
                    }
                    $set('goods', $e->itemLabel() !== '—' ? $e->itemLabel() : null);
                }),
            Grid::make(2)->schema([
                Select::make('party_id')->label('Party (registered)')->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search) => Party::query()->where('status', 'active')
                        ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($search).'%']))
                        ->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Party::query()->find($value)?->name)
                    ->afterStateUpdated(fn (?string $state, Set $set) => $state !== null ? $set('party_name', Party::query()->find($state)?->name) : null),
                TextInput::make('party_name')->label('Party name (as printed)')->required()->maxLength(160)
                    ->helperText('Filled in from the party; or type any name.'),
                TextInput::make('vehicle_no')->label('Vehicle number')->required()->maxLength(30)->placeholder('MNA-21-1234')
                    ->extraInputAttributes(['style' => 'text-transform: uppercase']),
                TextInput::make('goods')->label('Goods (optional)')->maxLength(160)->placeholder('Rice, husk, bran…'),
                TextInput::make('bag_count')->label('Number of bags')->required()->integer()->minValue(1)->maxValue(100000)->live(onBlur: true),
                TextInput::make('kg_per_bag')->label('Weight per bag (kg)')->required()->regex('/^\d{1,6}(\.\d{1,3})?$/')
                    ->validationMessages(['regex' => 'Type the kg, e.g. 50 or 25.5'])->suffix('kg')->live(onBlur: true),
            ]),
            Placeholder::make('total')->label('Total weight')
                ->content(function (Get $get): string {
                    $bags = (int) $get('bag_count');
                    $per = (string) $get('kg_per_bag');
                    if ($bags < 1 || preg_match('/^\d{1,6}(\.\d{1,3})?$/', $per) !== 1) {
                        return 'Number of bags × weight per bag';
                    }
                    $total = (float) bcmul((string) $bags, $per, 3);

                    return number_format($bags).' × '.rtrim(rtrim($per, '0'), '.').' kg = '.number_format($total, 2).' kg ('.number_format($total / 40, 2).' maund)';
                }),
            TextInput::make('remarks')->label('Remarks (optional)')->maxLength(300),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('serial')->label('Serial no.')->fontFamily('mono')->searchable()->weight('bold'),
                TextColumn::make('created_at')->label('Issued')->dateTime('d M Y H:i', 'Asia/Karachi')->sortable(),
                TextColumn::make('party_name')->label('Party')->searchable(),
                TextColumn::make('vehicle_no')->label('Vehicle')->fontFamily('mono')->searchable(),
                TextColumn::make('bag_count')->label('Bags')->numeric()->alignEnd(),
                TextColumn::make('kg_per_bag')->label('Kg per bag')->alignEnd()->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.')),
                TextColumn::make('total_kg')->label('Total kg')->numeric(2)->alignEnd(),
                TextColumn::make('purpose')->label('For')->badge()->formatStateUsing(fn (string $state) => $state === 'sale' ? 'Sale' : 'Other')
                    ->color(fn (string $state) => $state === 'sale' ? 'info' : 'gray'),
                TextColumn::make('cancellation.reason')->label('Status')->badge()->default('Valid')
                    ->formatStateUsing(fn (GatePass $r) => $r->cancellation === null ? 'Valid' : 'Cancelled: '.$r->cancellation->reason)
                    ->color(fn (GatePass $r) => $r->cancellation === null ? 'success' : 'danger'),
            ])
            ->filters([
                SelectFilter::make('purpose')->label('For')->options(['sale' => 'Sale', 'other' => 'Other']),
            ])
            ->actions(ManageGatePasses::rowActions());
    }

    public static function getPages(): array
    {
        return ['index' => ManageGatePasses::route('/')];
    }
}
