<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\Gate\ScaleResource\Pages;
use App\Filament\Resources\Gate\ScaleResource\RelationManagers\CertificatesRelationManager;
use App\Filament\Support\Units;
use App\Models\Gate\Scale;
use App\Support\Values\Weight;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class ScaleResource extends Resource
{
    protected static ?string $model = Scale::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Weighbridges';

    protected static ?string $slug = 'weighbridges';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            TextInput::make('code')->required()->maxLength(20)
                ->unique('scales', 'code', ignoreRecord: true, modifyRuleUsing: fn (Unique $r) => $r->where('entity_id', app(TenantContext::class)->entityId())),
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('capacity_kg')->label('Capacity (kg)')->required()->regex(Units::DECIMAL_3),
            TextInput::make('resolution_kg')->label('Smallest step (kg)')->required()->default('10')->regex(Units::DECIMAL_3),
            TextInput::make('location')->maxLength(120),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('name'),
                TextColumn::make('capacity_kg')->label('Capacity (t)')->formatStateUsing(fn (string $state) => Weight::kg($state)->in('ton')),
                TextColumn::make('resolution_kg')->label('Step (kg)'),
                TextColumn::make('certificates.valid_until')->label('Certificate valid until')->date()
                    ->state(fn (Scale $r) => $r->certificates()->where('type', 'stamping')->max('valid_until'))
                    ->color(fn ($state) => $state === null || now()->gt($state) ? 'danger' : (now()->addDays(30)->gt($state) ? 'warning' : 'success')),
                TextColumn::make('status')->badge(),
            ])
            ->actions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [CertificatesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScales::route('/'),
            'create' => Pages\CreateScale::route('/create'),
            'edit' => Pages\EditScale::route('/{record}/edit'),
        ];
    }
}
