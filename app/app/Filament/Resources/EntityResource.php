<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EntityResource\Pages;
use App\Models\Entity;
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
                    ->helperText('Short code used in document numbers, e.g. KNL1.'),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('legal_name')->maxLength(255),
                TextInput::make('gstin')->label('GSTIN')->maxLength(15)
                    ->regex('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/i')
                    ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper($state) : null)
                    ->helperText('Stored encrypted.'),
                TextInput::make('pan')->label('PAN')->maxLength(10)
                    ->regex('/^[A-Z]{5}\d{4}[A-Z]$/i')
                    ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper($state) : null)
                    ->helperText('Stored encrypted.'),
                TextInput::make('state_code')->label('GST state code')->length(2)->numeric(),
                Textarea::make('address')->columnSpanFull()->rows(2),
                Select::make('status')->options([
                    Entity::STATUS_ACTIVE => 'Active',
                    Entity::STATUS_SUSPENDED => 'Suspended',
                ])->default(Entity::STATUS_ACTIVE)->required()->selectablePlaceholder(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('state_code')->label('State'),
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
