<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Messaging\PartyStatement;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * "My account" for a party signed in with its own login: where its account stands, its vehicles, its
 * account entries and its bills — its own records only (PartyStatement).
 */
class PartyPortalPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'My account';

    protected static ?string $title = 'My account';

    protected static ?string $slug = 'my-account';

    protected static ?int $navigationSort = -5;

    protected static string $view = 'filament.pages.party-portal';

    public string $tab = 'vehicles';

    public static function canAccess(): bool
    {
        return self::party() !== null;
    }

    public static function party(): ?Party
    {
        $user = Filament::auth()->user();

        return PartyStatement::partyOf($user instanceof LoginAccount ? $user : null);
    }

    /** @return array<string, mixed> */
    public function statement(): array
    {
        $party = self::party();
        abort_if($party === null, 403);

        return app(PartyStatement::class)->for($party);
    }

    public function getHeading(): string
    {
        return self::party()->name ?? 'My account';
    }
}
