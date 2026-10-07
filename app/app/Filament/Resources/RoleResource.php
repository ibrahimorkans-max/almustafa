<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Access\PermissionCatalog;
use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Roles & permissions';

    protected static ?int $navigationSort = 30;

    /** @return array<string, array<string, string>> module => [code => label] */
    public static function permissionOptionsByModule(): array
    {
        $grouped = [];
        foreach (PermissionCatalog::PERMISSIONS as $code => $def) {
            $label = $def[1].' ('.$code.')'.(($def[2] ?? false) ? ' — confidential' : '');
            $grouped[ucfirst($def[0])][$code] = $label;
        }

        return $grouped;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code'),
                TextColumn::make('entity.code')->label('Entity')->placeholder('Global'),
                IconColumn::make('mfa_required')->label('2FA required')->boolean(),
                TextColumn::make('session_max_minutes')->label('Max session (min)'),
                TextColumn::make('permissions_count')->label('Permissions')->counts('permissions'),
            ])
            ->defaultSort('name')
            ->actions([ViewAction::make()])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('name'),
                TextEntry::make('code'),
                TextEntry::make('mfa_required')->label('2FA required')->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No'),
                TextEntry::make('session_max_minutes')->label('Max session (minutes)'),
            ]),
            Section::make('Granted permissions')->schema(
                collect(static::permissionOptionsByModule())->map(fn (array $options, string $module) => TextEntry::make('perm_'.$module)
                    ->label($module)
                    ->state(fn (Role $record) => array_values(array_intersect_key($options, array_flip($record->permissionCodes()))) ?: ['—'])
                    ->listWithLineBreaks()
                    ->bulleted()
                )->values()->all()
            )->columns(2),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'view' => Pages\ViewRole::route('/{record}'),
        ];
    }
}
