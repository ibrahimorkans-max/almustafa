<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\LiveWeightBoard;
use Filament\Pages\Page;

/** Full-screen view of every weighbridge's live reading, for the gate office or a wall screen. */
class LiveWeightPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Live weight';

    protected static ?string $title = 'Live weight';

    protected static ?string $slug = 'live-weight';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.live-weight';

    public static function canAccess(): bool
    {
        return LiveWeightBoard::canView();
    }

    protected function getHeaderWidgets(): array
    {
        return [LiveWeightBoard::class];
    }
}
