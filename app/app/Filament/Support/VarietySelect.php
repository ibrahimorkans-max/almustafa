<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\Masters\PaddyVarietyResource;
use App\Models\Masters\PaddyVariety;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;

/**
 * The paddy variety picker: searchable "CODE — Name" of active varieties, with "+" to add a new
 * variety on the spot (for someone with 'master.manage'). The new variety is chosen at once.
 */
final class VarietySelect
{
    public static function make(string $name = 'paddy_variety_id'): Select
    {
        $select = Select::make($name)->label('Paddy variety')->searchable()
            ->options(fn () => PaddyVariety::options())
            ->getOptionLabelUsing(function ($value): ?string {
                $v = is_string($value) && $value !== '' ? PaddyVariety::query()->find($value) : null;

                return $v === null ? null : $v->code.' — '.$v->name.($v->status === 'active' ? '' : ' (inactive)');
            });

        return Filament::auth()->user()?->can('create', PaddyVariety::class)
            ? $select->createOptionForm(PaddyVarietyResource::fields())
                ->createOptionModalHeading('Add a paddy variety')
                ->createOptionUsing(fn (array $data) => PaddyVarietyResource::store($data)->id)
            : $select;
    }
}
