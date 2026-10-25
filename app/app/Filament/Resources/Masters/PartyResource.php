<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters;

use App\Filament\Resources\Masters\Concerns\MasterResourceHelpers;
use App\Filament\Resources\Masters\PartyResource\Pages;
use App\Filament\Resources\Masters\PartyResource\RelationManagers\BankAccountsRelationManager;
use App\Filament\Support\PartyLogin;
use App\Models\Masters\Party;
use App\Support\BlindIndex;
use App\Support\Values\Money;
use App\Support\Values\PakistanIds;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
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
                TextInput::make('phone')->tel()->maxLength(16)->placeholder('0300-1234567')
                    ->rule(fn () => fn (string $a, mixed $v, Closure $fail) => $v && ! PakistanIds::normalizeMobile((string) $v) ? $fail('Enter a Pakistani mobile number, e.g. 0300-1234567.') : null),
                TextInput::make('email')->email()->maxLength(160),
                Textarea::make('address')->rows(2)->maxLength(500)->columnSpan(2),
                TextInput::make('city')->maxLength(80),
                Select::make('province')->options(array_combine(PakistanIds::PROVINCES, PakistanIds::PROVINCES))->default('Punjab'),
                Toggle::make('messages_on')->label('Send messages to this party')->default(true)
                    ->helperText('Money entries, vehicles unloaded and weighed, login details (each kind can be switched off for the mill in Party messages).'),
                Toggle::make('make_login')->label('Make a login for this party and send the details')->dehydrated(false)
                    ->helperText('The party then sees its own vehicles, bills, payments and balance. Needs a mobile number.')
                    ->visible(fn (string $operation) => $operation === 'create' && PartyLogin::allowed()),
            ]),
            Section::make('Tax & credit')->columns(3)->schema([
                TextInput::make('ntn')->label('NTN')->maxLength(20)->placeholder('1234567-8')
                    ->formatStateUsing(fn (?string $state) => PakistanIds::formatNtn($state))
                    ->rule(fn () => fn (string $a, mixed $v, Closure $fail) => $v && ! PakistanIds::isValidNtn((string) PakistanIds::digits((string) $v))
                        ? $fail('The NTN must be 7 digits and the check digit (1234567-8), or a 13-digit CNIC.') : null)
                    ->helperText('Printed on invoices. Stored encrypted.'),
                TextInput::make('strn')->label('STRN (sales tax)')->maxLength(20)
                    ->rule(fn (?Party $record) => function (string $a, mixed $value, Closure $fail) use ($record): void {
                        $strn = PakistanIds::digits((string) $value);
                        if ($strn === null) {
                            return;
                        }
                        if (! PakistanIds::isValidStrn($strn)) {
                            $fail('The STRN must have 13 digits.');

                            return;
                        }
                        $taken = Party::query()->withArchived()->where('strn_index', BlindIndex::of($strn, 'strn'))
                            ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))->exists();
                        if ($taken) {
                            $fail('Another party already has this STRN.');
                        }
                    })
                    ->helperText('Empty = not registered for sales tax (further tax may apply on sales). Stored encrypted.'),
                TextInput::make('cnic')->label('CNIC')->maxLength(15)->placeholder('35202-1234567-1')
                    ->formatStateUsing(fn (?string $state) => PakistanIds::formatCnic($state))
                    ->rule(fn () => fn (string $a, mixed $v, Closure $fail) => $v && ! PakistanIds::isValidCnic((string) PakistanIds::digits((string) $v))
                        ? $fail('The CNIC must have 13 digits (35202-1234567-1).') : null)
                    ->helperText('For individuals (farmers, arhtis). Stored encrypted.')
                    // The CNIC stays hidden in the form for users who may not see it.
                    ->visible(fn () => Filament::auth()->user()?->can('bank.view') ?? false),
                Select::make('atl_status')->label('FBR Active Taxpayer List')->default('unknown')->required()
                    ->options(['active' => 'Active (filer)', 'inactive' => 'Not on the list (non-filer)', 'unknown' => 'Not checked yet (treated as non-filer)'])
                    ->helperText('Check the NTN / CNIC on FBR\'s ATL; the withholding rate depends on it.'),
                DatePicker::make('atl_checked_on')->label('ATL checked on')->maxDate(now()),
                TextInput::make('credit_limit_rupees')->label('Credit limit (Rs)')->regex('/^\d{1,13}(\.\d{1,2})?$/')
                    ->afterStateHydrated(fn (TextInput $c, ?Party $record) => $c->state($record?->credit_limit_paise !== null ? Money::paise($record->credit_limit_paise)->toDecimal() : null))
                    ->dehydrated(false),
                TextInput::make('credit_days')->integer()->minValue(0)->maxValue(365),
                Toggle::make('wht_exempt')->label('No withholding tax u/s 153')
                    ->helperText('Only on the tax adviser\'s instruction, e.g. a grower selling their own produce or an exemption certificate.')
                    ->visible(fn () => auth()->user()?->can('ledger.post') ?? false),
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
                TextColumn::make('tax_registration')->label('Sales tax')->badge()
                    ->color(fn (string $state) => $state === 'registered' ? 'success' : 'gray'),
                TextColumn::make('atl_status')->label('ATL')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success', 'inactive' => 'danger', default => 'warning'
                    }),
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
