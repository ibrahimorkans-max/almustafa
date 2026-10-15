<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\AccountSwitcher;
use App\Domain\Auth\ActorContext;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceInfo;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\Entity;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Owner: choose the working entity (or all entities).
 * Switcher login mode: move to another login account of the same person.
 */
class SwitchContext extends Page
{
    protected static ?string $slug = 'switch';

    protected static ?string $title = 'Switch entity / account';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.switch-context';

    public ?string $otp = null;

    public function account(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }

    /** @return Collection<int, Entity> */
    public function entities(): Collection
    {
        return $this->account()->isOwner() ? Entity::query()->orderBy('code')->get() : new Collection;
    }

    public function workingEntityId(): ?string
    {
        return app(TenantContext::class)->entityId();
    }

    /** @return Collection<int, LoginAccount> */
    public function otherAccounts(): Collection
    {
        return app(AccountSwitcher::class)->switchableAccounts($this->account());
    }

    public function chooseEntity(string $entityId): void
    {
        $account = $this->account();
        abort_unless($account->isOwner(), 403);

        if ($entityId !== AuthContextBinder::ALL_ENTITIES) {
            abort_unless(Entity::query()->whereKey($entityId)->exists(), 404);
        }

        session()->put(EnsureWebAuthSession::WORKING_ENTITY_KEY, $entityId);
        app(AuditLogger::class)->record('auth.working_entity.changed', $account, meta: ['working_entity' => $entityId]);

        $this->redirect(Filament::getUrl());
    }

    public function switchAccount(string $targetId): void
    {
        $actor = app(ActorContext::class);
        $session = $actor->session();
        abort_if($session === null, 403);

        try {
            $new = app(AccountSwitcher::class)->switch(
                $this->account(),
                $session,
                $targetId,
                $this->otp ?: null,
                new DeviceInfo((string) request()->cookie(Login::DEVICE_COOKIE, $session->device->device_uid ?? ''), 'web', $session->device?->name),
                request()->ip(),
                request()->userAgent(),
            );
        } catch (AuthenticationFailed $e) {
            Notification::make()->title($e->publicMessage())->danger()->send();

            return;
        }

        $target = $new->loginAccount;
        Filament::auth()->login($target);
        session()->regenerate();
        session()->forget(EnsureWebAuthSession::WORKING_ENTITY_KEY);
        session()->put(EnsureWebAuthSession::SESSION_KEY, $new->id);
        session()->put('password_hash_'.Filament::getAuthGuard(), $target->getAuthPassword());

        $this->redirect(Filament::getUrl());
    }
}
