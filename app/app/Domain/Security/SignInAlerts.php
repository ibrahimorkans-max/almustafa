<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\LoginAccount;
use Filament\Notifications\Notification;

/**
 * Tells a person, and the mill's Super Admins, when their account signs in from a device it has never used
 * or from a city or country it has not signed in from in the last 90 days. The very first sign-in of
 * an account is not news. The person can then sign that session out from Security → Sessions.
 */
final class SignInAlerts
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SecurityNotifier $notifier,
    ) {}

    public function afterSignIn(LoginAccount $account, AuthSession $session, Device $device): void
    {
        $this->tenant->runAsSystem(function () use ($account, $session, $device): void {
            $earlier = AuthSession::query()->where('login_account_id', $account->id)->whereKeyNot($session->id);
            if (! (clone $earlier)->exists()) {
                return;
            }
            $what = [];
            if ($device->wasRecentlyCreated) {
                $what[] = 'a new device ('.($session->browser ?? 'unknown browser').($session->os !== null ? ' on '.$session->os : '').')';
            }
            $place = $session->placeLabel();
            if ($session->location_source === 'ip' && $place !== null) {
                $seen = (clone $earlier)->where('created_at', '>=', now()->subDays(90))
                    ->where('country', $session->country)->where('city', $session->city)->exists();
                if (! $seen) {
                    $what[] = "a new place ({$place})";
                }
            }
            if ($what === []) {
                return;
            }
            $when = now('Asia/Karachi')->format('d M Y H:i');
            $detail = implode(' and ', $what).", IP {$session->ip}, at {$when} PKT";
            Notification::make()->warning()->title('New sign-in to your account')
                ->body("Your account signed in from {$detail}. If this was not you, sign it out under Security → Sessions and change your password.")
                ->sendToDatabase($account);
            if (! $account->isOwner() && $account->role?->code !== 'SUPER_ADMIN') {
                $this->notifier->owners($account->entity_id, "New sign-in: {$account->username}", "{$account->username} signed in from {$detail}.", includeOwners: false);
            }
        });
    }
}
