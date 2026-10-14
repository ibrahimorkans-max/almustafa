<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EntityResource\Pages;
use App\Models\Entity;
use App\Support\Values\PakistanIds;
use Filament\Facades\Filament;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EntityResource extends Resource
{
    protected static ?string $model = Entity::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Entities (firms / mills)';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->columns(2)->schema([
                TextInput::make('code')->required()->maxLength(20)->alphaDash()
                    ->unique(ignoreRecord: true)->disabledOn('edit')
                    ->helperText('Short code used in document numbers, e.g. AMR.'),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('legal_name')->maxLength(255),
                TextInput::make('ntn')->label('NTN')->maxLength(20)
                    ->rule(fn () => fn (string $attribute, mixed $value, \Closure $fail) => PakistanIds::isValidNtn((string) PakistanIds::digits((string) $value)) ? null : $fail('NTN: 7 digits and the check digit (1234567-8), or the CNIC for an individual.'))
                    ->dehydrateStateUsing(fn (?string $state) => PakistanIds::digits($state))
                    ->formatStateUsing(fn (?string $state) => PakistanIds::formatNtn($state))
                    ->helperText('Printed on every invoice. Stored encrypted.'),
                TextInput::make('strn')->label('STRN (sales tax registration)')->maxLength(20)
                    ->rule(fn () => fn (string $attribute, mixed $value, \Closure $fail) => PakistanIds::isValidStrn((string) PakistanIds::digits((string) $value)) ? null : $fail('STRN: 13 digits.'))
                    ->dehydrateStateUsing(fn (?string $state) => PakistanIds::digits($state))
                    ->helperText('Leave empty if the mill is not registered for sales tax: invoices then print as "Invoice" without tax lines. Stored encrypted.'),
                TextInput::make('phone')->maxLength(40)->helperText('As printed on slips and invoices, e.g. 0300-6834588.'),
                Select::make('province')->options(array_combine(PakistanIds::PROVINCES, PakistanIds::PROVINCES)),
                TextInput::make('city')->maxLength(80),
                TextInput::make('postal_code')->label('Postal code')->length(5)->numeric(),
                Textarea::make('address')->columnSpanFull()->rows(2),
                Select::make('status')->options([
                    Entity::STATUS_ACTIVE => 'Active',
                    Entity::STATUS_SUSPENDED => 'Suspended',
                ])->default(Entity::STATUS_ACTIVE)->required()->selectablePlaceholder(false)
                    // Suspending a firm is for whoever manages all firms, not the mill's own owner.
                    ->disabled(fn () => ! (Filament::auth()->user()?->hasPermission('entity.manage') ?? false)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('city'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === Entity::STATUS_ACTIVE ? 'success' : 'danger'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('code')
            ->actions([EditAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEntities::route('/'),
            'create' => Pages\CreateEntity::route('/create'),
            'edit' => Pages\EditEntity::route('/{record}/edit'),
        ];
    }
}
