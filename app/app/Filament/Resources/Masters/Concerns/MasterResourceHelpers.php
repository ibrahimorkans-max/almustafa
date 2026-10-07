<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\Concerns;

use App\Domain\Tenancy\TenantContext;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** Shared bits for master-data resources. */
trait MasterResourceHelpers
{
    public static function getNavigationGroup(): ?string
    {
        return 'Masters';
    }

    /** A code that is unique inside the current entity (explicit where, so it also holds on MySQL). */
    protected static function codeInput(string $table, int $max = 20): TextInput
    {
        return TextInput::make('code')
            ->required()
            ->maxLength($max)
            ->regex('/^[A-Za-z0-9._-]+$/')
            ->unique($table, 'code', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule
                ->where('entity_id', app(TenantContext::class)->entityId()));
    }

    protected static function versionColumn(): TextColumn
    {
        return TextColumn::make('version')->label('Ver.')->toggleable(isToggledHiddenByDefault: true);
    }

    protected static function updatedColumn(): TextColumn
    {
        return TextColumn::make('updated_at')->label('Updated')->since()->sortable()
            ->tooltip(fn (Model $record) => $record->getAttribute('updated_at')?->toDateTimeString());
    }
}
