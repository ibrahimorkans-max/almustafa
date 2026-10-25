<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\RoleTemplates;
use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Switches screen protection (public/js/screen-guard.js) on or off. The Owner sets it for all mills
 * and may enforce it, so no mill can turn it off; a mill's Super Admin sets it for that mill.
 */
class ScreenProtection extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-eye-slash';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'Screen protection';

    protected static ?string $title = 'Screen protection';

    protected static ?string $slug = 'screen-protection';

    protected static ?int $navigationSort = 60;

    protected static string $view = 'filament.pages.screen-protection';

    /** The all-mills choice: on, off or enforced. */
    public string $all = 'on';

    /** This mill's choice: inherit, on or off. */
    public string $mill = 'inherit';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->isOwner() || $user->role?->code === RoleTemplates::SUPER_ADMIN);
    }

    /** The all-mills value applies to every customer: the platform admin's. */
    public function isOwner(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    public function millId(): ?string
    {
        return app(TenantContext::class)->entityId();
    }

    /** @return array{all: string, mill: string|null, enforced: bool, on: bool} */
    public function state(): array
    {
        return app(SettingsService::class)->screenGuard($this->millId());
    }

    public function switchUrl(): string
    {
        return SwitchContext::getUrl();
    }

    public function mount(): void
    {
        $state = $this->state();
        $this->all = $state['all'];
        $this->mill = $state['mill'] ?? 'inherit';
    }

    public function saveAll(): void
    {
        abort_unless($this->isOwner(), 403);
        $this->validate(['all' => ['required', 'in:on,off,enforced']]);
        $before = $this->state()['all'];
        app(SettingsService::class)->put(SettingsService::SCREEN_GUARD, $this->all, null);
        app(AuditLogger::class)->record('security.screen_guard_changed', null, meta: ['scope' => 'all', 'from' => $before, 'to' => $this->all]);
        Notification::make()->title('Saved for all mills. It applies from the next page opened.')->success()->send();
    }

    public function saveMill(): void
    {
        abort_unless(static::canAccess() && $this->millId() !== null, 403);
        $this->validate(['mill' => ['required', 'in:inherit,on,off']]);
        $state = $this->state();
        if ($state['enforced'] && $this->mill === 'off') {
            Notification::make()->title('The Owner has made screen protection compulsory for every mill.')->danger()->send();

            return;
        }
        $settings = app(SettingsService::class);
        $this->mill === 'inherit'
            ? $settings->forget(SettingsService::SCREEN_GUARD, (string) $this->millId())
            : $settings->put(SettingsService::SCREEN_GUARD, $this->mill, $this->millId());
        app(AuditLogger::class)->record('security.screen_guard_changed', null, meta: ['scope' => 'mill', 'from' => $state['mill'] ?? 'inherit', 'to' => $this->mill]);
        Notification::make()->title('Saved for this mill. It applies from the next page opened.')->success()->send();
    }
}
