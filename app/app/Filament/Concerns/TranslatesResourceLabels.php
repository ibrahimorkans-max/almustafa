<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

/**
 * A module's names (menu entry, menu group, record name) in the chosen language; its pages' titles follow.
 */
trait TranslatesResourceLabels
{
    public static function getNavigationLabel(): string
    {
        return Translated::text(parent::getNavigationLabel());
    }

    public static function getNavigationGroup(): ?string
    {
        $group = parent::getNavigationGroup();

        return $group === null ? null : Translated::text($group);
    }

    public static function getModelLabel(): string
    {
        return Translated::text(parent::getModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        return Translated::text(parent::getPluralModelLabel());
    }
}
