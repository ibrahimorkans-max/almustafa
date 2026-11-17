<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\FeatureDescriptions;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A page's names (menu entry, menu group, title) in the chosen language. The English names stay in the class;
 * lang/<code>.json has the words. Resources use TranslatesResourceLabels.
 */
trait TranslatesLabels
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

    /**
     * Under the title: the page's own line, else what the screen is for (FeatureDescriptions). Shown on lists and
     * custom pages; a record's create / edit / view page keeps to its record.
     */
    public function getSubheading(): string | Htmlable | null
    {
        $own = parent::getSubheading();
        if (filled($own)) {
            return is_string($own) ? Translated::text($own) : $own;
        }
        if ($this instanceof \Filament\Resources\Pages\ListRecords) {
            return FeatureDescriptions::for(static::getResource());
        }

        return $this instanceof \Filament\Resources\Pages\Page ? null : FeatureDescriptions::for(static::class);
    }

    public function getTitle(): string | Htmlable
    {
        $title = parent::getTitle();

        return is_string($title) ? Translated::text($title) : $title;
    }
}
