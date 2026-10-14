<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Auth\PasskeyService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\Passkey;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * The signed-in person's passkeys: add one on this device (fingerprint, face or device PIN), see
 * where each was last used, remove one (for example a sold phone). Removing the last passkey leaves
 * password and code sign-in as before.
 */
class MyPasskeys extends Page
{
    protected static ?string $slug = 'passkeys';

    protected static ?string $title = 'My passkeys';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.my-passkeys';

    public string $name = '';

    public static function canAccess(): bool
    {
        return Filament::auth()->user() instanceof LoginAccount;
    }

    /** @return Collection<int, Passkey> */
    public function getPasskeys(): Collection
    {
        return app(TenantContext::class)->runAsSystem(fn () => Passkey::query()->where('authenticatable_id', $this->me()->id)->orderBy('created_at')->get());
    }

    public function registrationOptions(): string
    {
        return app(PasskeyService::class)->registrationOptions($this->me());
    }

    public function storePasskey(string $credential, string $name): void
    {
        try {
            app(PasskeyService::class)->register($this->me(), mb_substr($credential, 0, 20_000), $name);
            $this->name = '';
            Notification::make()->title('Passkey added. Next time choose "Sign in with a passkey".')->success()->send();
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function remove(string $id): void
    {
        $passkey = $this->getPasskeys()->firstWhere('id', $id);
        if ($passkey === null) {
            return;
        }
        app(PasskeyService::class)->remove($passkey, $this->me());
        Notification::make()->title("Passkey \"{$passkey->name}\" removed.")->success()->send();
    }

    private function me(): LoginAccount
    {
        $me = Filament::auth()->user();
        abort_unless($me instanceof LoginAccount, 403);

        return $me;
    }
}
