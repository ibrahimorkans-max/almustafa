<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuthSessionResource\Pages;

use App\Domain\Auth\ActorContext;
use App\Domain\Auth\SessionService;
use App\Domain\Security\GeoIp;
use App\Filament\Pages\SignInAttempts;
use App\Filament\Resources\AuthSessionResource;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListAuthSessions extends ListRecords
{
    protected static string $resource = AuthSessionResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return app(GeoIp::class)->available() ? 'IP locations: DB-IP (db-ip.com), CC BY 4.0.' : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('others')->label('Sign out all my other sessions')->icon('heroicon-o-arrow-right-start-on-rectangle')->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Every other browser and phone signed in to your account is signed out now. This one stays.')
                ->action(function (): void {
                    /** @var LoginAccount $me */
                    $me = Filament::auth()->user();
                    $n = app(SessionService::class)->revokeAllFor($me, 'revoked_by_user', app(ActorContext::class)->session()?->id);
                    Notification::make()->title($n === 0 ? 'No other sessions were open.' : "{$n} other session(s) signed out.")->success()->send();
                }),
            Action::make('attempts')->label('Failed sign-ins')->icon('heroicon-o-shield-exclamation')->color('gray')
                ->url(fn () => SignInAttempts::getUrl())->visible(fn () => SignInAttempts::canAccess()),
        ];
    }
}
