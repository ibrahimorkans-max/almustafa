<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Search → all results: everything the top-bar search finds, grouped by kind of record, for words typed
 * here or carried over from the top bar ("All results"). The same rules as the top bar: partial match,
 * this mill only, and only the kinds of record the person may open.
 */
class SearchResultsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $title = 'Search';

    protected static ?string $slug = 'search';

    protected static string $view = 'filament.pages.search-results';

    #[Url]
    public string $q = '';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return Filament::auth()->user() instanceof LoginAccount;
    }

    public function getResults(): ?GlobalSearchResults
    {
        $q = trim($this->q);
        if (mb_strlen($q) < 2) {
            return null;
        }

        return Filament::getGlobalSearchProvider()->getResults(mb_substr($q, 0, 100));
    }
}
