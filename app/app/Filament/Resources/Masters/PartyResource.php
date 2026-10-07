<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters;

use App\Filament\Resources\Masters\Concerns\MasterResourceHelpers;
use App\Filament\Resources\Masters\PartyResource\Pages;
use App\Filament\Resources\Masters\PartyResource\RelationManagers\BankAccountsRelationManager;
use App\Models\Masters\Party;
use App\Support\BlindIndex;
use App\Support\Values\IndianIds;
use App\Support\Values\Money;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartyResource extends Resource
{
    use MasterResourceHelpers;

    protected static ?string $model = Party::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Parties';

    protected static ?int $navigationSort = 10;

    /** @var array<string, string> */
    public const ROLE_OPTIONS = [
        'is_customer' => 'Customer', 'is_vendor' => 'Vendor', 'is_broker' => 'Broker / agent',
        'is_farmer' => 'Farmer', 'is_transporter' => 'Transporter',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Party')->columns(3)->schema([
                static::codeInput('parties', 30),
                TextInput::make('name')->required()->maxLength(160),
                TextInput::make('legal_name')->maxLength(200),
                CheckboxList::make('roles')->label('Acts as')->options(self::ROLE_OPTIONS)->columns(5)->columnSpanFull()
                    ->required()
                    ->afterStateHydrated(fn (CheckboxList $component, ?Party $record) => $component->state(
                        $record ? array_values(array_filter(Party::ROLES, fn (string $r) => (bool) $record->getAttribute($r))) : [],
                    ))
                    ->dehydrated(false),
            ]),
            Section::make('Contact')->columns(3)->schema([
                TextInput::make('contact_person')->maxLength(120),
                TextInput::make('phone')->tel()->maxLength(16)
                    ->rule(fn () => fn (string $a, mixed $v, Closure $fail) => $v && ! IndianIds::normalizeMobile((string) $v) ? $fail('Enter a 10-digit Indian mobile number.') : null),
                TextInput::make('email')->email()->maxLength(160),
                Textarea::make('address')->rows(2)->maxLength(500)->columnSpan(2),
                TextInput::make('city')->maxLength(80),
                TextInput::make('state_code')->label('State code')->regex('/^\d{2}$/')->helperText('Filled from GSTIN if empty.'),
                TextInput::make('pincode')->regex('/^[1-9]\d{5}$/'),
            ]),
            Section::make('Tax & credit')->columns(3)->schema([
                Select::make('gst_registration_type')->label('GST registration')->live()->required()->default('unregistered')
                    ->options(['regular' => 'Regular', 'composition' => 'Composition', 'unregistered' => 'Unregistered', 'consumer' => 'Consumer']),
                TextInput::make('gstin')->label('GSTIN')->maxLength(15)
                    ->required(fn (Get $get) => in_array($get('gst_registration_type'), ['regular', 'composition'], true))
                    ->rule(fn (?Party $record) => function (string $a, mixed $value, Closure $fail) use ($record): void {
                        $gstin = IndianIds::normalizeGstin((string) $value);
                        if ($gstin === null) {
                            return;
                        }
                        if (! IndianIds::isValidGstin($gstin)) {
                            $fail('The GSTIN is not valid (format or check digit).');

                            return;
                        }
                        $taken = Party::query()->withArchived()->where('gstin_index', BlindIndex::of($gstin, 'gstin'))
                            ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))->exists();
                        if ($taken) {
                            $fail('Another party already has this GSTIN.');
                        }
                    })
                    ->helperText('Stored encrypted.'),
                TextInput::make('pan')->label('PAN')->maxLength(10)
                    ->rule(fn () => fn (string $a, mixed $v, Closure $fail) => $v && ! IndianIds::isValidPan(strtoupper((string) $v)) ? $fail('The PAN is not valid.') : null)
                    ->helperText('Taken from GSTIN if empty. Stored encrypted.')
                    // PAN stays hidden in the form for users who may not see it.
                    ->visible(fn () => Filament::auth()->user()?->can('bank.view') ?? false),
                TextInput::make('credit_limit_rupees')->label('Credit limit (₹)')->regex('/^\d{1,13}(\.\d{1,2})?$/')
                    ->afterStateHydrated(fn (TextInput $c, ?Party $record) => $c->state($record?->credit_limit_paise !== null ? Money::paise($record->credit_limit_paise)->toDecimal() : null))
                    ->dehydrated(false),
                TextInput::make('credit_days')->integer()->minValue(0)->maxValue(365),
                Select::make('status')->options(['active' => 'Active', 'blocked' => 'Blocked'])->default('active')->required(),
                Textarea::make('notes')->rows(2)->maxLength(2000)->columnSpanFull(),
            ]),
        ]);
    }

    /**
     * Turns the form-only fields (role checkboxes, rupee amount) into model attributes.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw  full form state
     * @return array<string, mixed>
     */
    public static function mutate(array $data, array $raw): array
    {
        foreach (Party::ROLES as $role) {
            $data[$role] = in_array($role, (array) ($raw['roles'] ?? []), true);
        }
        $rupees = $raw['credit_limit_rupees'] ?? null;
        $data['credit_limit_paise'] = $rupees === null || $rupees === '' ? null : Money::rupees((string) $rupees)->paise;

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable()->description(fn (Party $r) => implode(' · ', $r->roleLabels())),
                TextColumn::make('city')->placeholder('—'),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('gst_registration_type')->label('GST')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'danger'),
                static::updatedColumn(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')->options(self::ROLE_OPTIONS)
                    ->query(fn (Builder $q, array $data) => $data['value'] ? $q->where($data['value'], true) : $q),
                SelectFilter::make('status')->options(['active' => 'Active', 'blocked' => 'Blocked']),
            ])
            ->searchable()
            ->actions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [BankAccountsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParties::route('/'),
            'create' => Pages\CreateParty::route('/create'),
            'edit' => Pages\EditParty::route('/{record}/edit'),
        ];
    }
}
