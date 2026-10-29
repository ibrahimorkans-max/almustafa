<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Hr\ContractorVehicles;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Resources\Hr\ContractorRateResource\Pages;
use App\Filament\Support\Units;
use App\Models\Gate\GateEntry;
use App\Models\Hr\ContractorRate;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Masters\Vehicle;
use Filament\Actions\MountableAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * HR → Contractor rates: what each labour contractor is paid for the weight one of its vehicles unloads,
 * loads or carries: per maund, per kg or per a custom weight (Rs 50 per 100 kg). Rates are versions with
 * effective dates; two active versions of one contractor, vehicle and work never overlap (a new one closes
 * the open one before it). A rate used on a bill keeps its amount: add a new version instead.
 * Contractor bills on "vehicles" use these rates: billable weight ÷ rate weight × rate.
 */
class ContractorRateResource extends Resource
{
    protected static ?string $model = ContractorRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Contractor rates';

    protected static ?string $modelLabel = 'contractor rate';

    protected static ?string $slug = 'hr/contractor-rates';

    protected static ?int $navigationSort = 32;

    protected static ?string $recordTitleAttribute = 'code';

    public static function canViewAny(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ($me->hasPermission('hr.payroll.run') || $me->hasPermission('hr.payroll.approve'));
    }

    public static function canCreate(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->hasPermission('hr.payroll.run');
    }

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return array<string, string> the contractor's own vehicles */
    public static function vehicleOptions(?string $partyId): array
    {
        return $partyId === null ? [] : Vehicle::query()->where('contractor_party_id', $partyId)->orderBy('registration_no')->get()
            ->mapWithKeys(fn (Vehicle $v) => [$v->id => $v->registration_no.' · '.Vehicle::typeLabel($v->vehicle_type)])->all();
    }

    public static function form(Form $form): Form
    {
        $used = fn (?ContractorRate $record) => $record !== null && $record->usedOnBills();

        return $form->columns(3)->schema([
            Select::make('party_id')->label('Contractor')->required()->searchable()->live()->options(fn () => ContractorBillResource::contractors())
                ->afterStateUpdated(fn (callable $set) => $set('vehicle_id', null))
                ->disabled($used)->helperText('A vendor chosen here becomes a labour contractor (with a CTR code).'),
            Select::make('vehicle_id')->label('Contractor vehicle')->required()->searchable()->live()
                ->options(fn (Get $get) => self::vehicleOptions($get('party_id')))
                ->disabled($used)
                ->helperText(fn (Get $get) => filled($get('party_id')) && self::vehicleOptions($get('party_id')) === []
                    ? 'This contractor has no vehicles yet: add one with "+".' : 'Only this contractor\'s vehicles.')
                ->createOptionForm([
                    TextInput::make('registration_no')->label('Registration number')->required()->maxLength(20),
                    Select::make('vehicle_type')->label('Kind of vehicle')->options(Vehicle::LABELS)->default('truck')->required(),
                ])
                ->createOptionUsing(function (array $data, Get $get): string {
                    $party = Party::query()->findOrFail($get('party_id'));

                    return app(ContractorVehicles::class)->addVehicle($party, (string) $data['registration_no'], (string) $data['vehicle_type'])->id;
                }),
            Select::make('work')->label('Work / service')->options(ContractorRate::WORK)->default('unloading')->required()->disabled($used),
            Select::make('basis')->label('Rate per')->options(ContractorRate::BASES)->default('maund')->required()->live()->disabled($used)
                ->helperText('Charge = billable weight ÷ rate weight × rate.'),
            TextInput::make('basis_qty')->label('Custom weight')->regex(Units::DECIMAL_3)->disabled($used)
                ->visible(fn (Get $get) => $get('basis') === 'custom')->required(fn (Get $get) => $get('basis') === 'custom'),
            Select::make('basis_unit')->label('Unit')->options(ContractorRate::CUSTOM_UNITS)->default('kg')->disabled($used)
                ->visible(fn (Get $get) => $get('basis') === 'custom')->required(fn (Get $get) => $get('basis') === 'custom'),
            TextInput::make('rate_paise')->label('Rate (Rs)')->required()->regex(Units::DECIMAL_2)->disabled($used)
                ->formatStateUsing(fn ($state) => $state === null ? null : bcdiv((string) $state, '100', 2))
                ->dehydrateStateUsing(fn ($state) => Units::rupeesToPaise((string) $state)),
            DatePicker::make('effective_from')->label('Effective from')->required()->default(fn () => now('Asia/Karachi')->toDateString()),
            DatePicker::make('effective_to')->label('Effective to')->afterOrEqual('effective_from')
                ->helperText('Empty: until a new rate replaces it.'),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            TextInput::make('note')->label('Remarks')->maxLength(300)->columnSpan(2),
            Placeholder::make('used')->label('')->columnSpanFull()->visible($used)
                ->content('This rate is on a bill: its amount and weight cannot change. To change the rate, add a new rate from the new date; this one closes the day before.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['party', 'vehicle']))
            ->columns([
                TextColumn::make('code')->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('party.name')->label('Contractor')->searchable()->sortable()
                    ->description(fn (ContractorRate $r) => $r->party->contractor_code),
                TextColumn::make('vehicle.registration_no')->label('Vehicle')->fontFamily('mono')->searchable()
                    ->state(fn (ContractorRate $r) => $r->vehicle->registration_no ?? ($r->vehicle_type ? 'Any '.Vehicle::typeLabel($r->vehicle_type) : 'Any vehicle'))
                    ->description(fn (ContractorRate $r) => $r->vehicle ? Vehicle::typeLabel($r->vehicle->vehicle_type) : null),
                TextColumn::make('work')->formatStateUsing(fn (string $state) => ContractorRate::WORK[$state] ?? $state),
                TextColumn::make('rate_paise')->label('Rate')->alignEnd()->fontFamily('mono')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state))
                    ->description(fn (ContractorRate $r) => $r->basisLabel()),
                TextColumn::make('effective_from')->label('From')->date('d M Y')->sortable(),
                TextColumn::make('effective_to')->label('To')->date('d M Y')->placeholder('Open'),
                TextColumn::make('unbilled')->label('Unbilled tokens')->alignEnd()
                    ->state(fn (ContractorRate $r) => GateEntry::query()->where('contractor_party_id', $r->party_id)->whereNull('contractor_bill_id')
                        ->where('status', '!=', 'cancelled')->when($r->vehicle_id, fn ($q) => $q->where('vehicle_id', $r->vehicle_id))->count()),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('effective_from', 'desc')
            ->filters([
                SelectFilter::make('party_id')->label('Contractor')->options(fn () => ContractorBillResource::contractors()),
                SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive']),
            ])
            ->actions([
                Action::make('vehicles')->label('Tokens')->icon('heroicon-o-truck')->color('gray')
                    ->url(fn (ContractorRate $r) => GateEntryResource::getUrl('index', ['tableFilters' => ['open' => ['value' => 'all'], 'contractor' => ['value' => $r->party_id]]])),
                EditAction::make()->using(fn (ContractorRate $record, array $data, EditAction $action) => self::guarded(fn () => tap($record)->update($data), $action)),
            ]);
    }

    /**
     * Saves, turning a refused rate (overlap, used on a bill …) into a message on the form instead of an error page.
     *
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     */
    public static function guarded(callable $save, MountableAction $action): mixed
    {
        try {
            return $save();
        } catch (BusinessRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            $action->halt();
        }

        return null;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageContractorRates::route('/')];
    }
}
