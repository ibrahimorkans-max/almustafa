<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * The mill's income-tax switch. On (as before): income tax is worked out and its settings show — withholding on
 * purchase bills (s. 153), payroll income tax (s. 149), tax withheld on contractor bills, the parties' filer status
 * and exemption, the withholding register. Off: none is worked out on anything new, and its settings are hidden.
 * Documents already posted keep the tax they were posted with.
 */
final class IncomeTax
{
    public const SETTING = 'tax.income_tax';

    public static function enabled(?string $entityId = null): bool
    {
        $entityId ??= app(TenantContext::class)->entityId();

        return app(SettingsService::class)->get(self::SETTING, $entityId) !== 'off';
    }

    /** Switched by the Owner / Super Admin (settings.manage); kept in the audit log. */
    public static function set(string $entityId, bool $on): void
    {
        $actor = app(ActorContext::class)->account();
        if (! ($actor?->hasPermission('settings.manage') ?? false)) {
            throw new BusinessRuleViolation('Only the Owner or a Super Admin switches income tax on or off.');
        }
        $before = self::enabled($entityId);
        app(SettingsService::class)->put(self::SETTING, $on ? 'on' : 'off', $entityId);
        app(AuditLogger::class)->record('tax.income_tax.'.($on ? 'on' : 'off'), null, ['on' => $before], ['on' => $on]);
    }

    /** The "Income tax: ON / OFF" button on the screens that hold income-tax settings. */
    public static function toggleAction(): Action
    {
        return Action::make('incomeTax')
            ->label(fn () => 'Income tax: '.(self::enabled() ? 'ON' : 'OFF'))
            ->icon(fn () => self::enabled() ? 'heroicon-o-check-circle' : 'heroicon-o-no-symbol')
            ->color(fn () => self::enabled() ? 'success' : 'gray')
            ->visible(fn () => app(TenantContext::class)->entityId() !== null && (app(ActorContext::class)->account()?->hasPermission('settings.manage') ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn () => self::enabled() ? 'Switch income tax off?' : 'Switch income tax on?')
            ->modalDescription(fn () => self::enabled()
                ? 'No income tax is worked out on new purchase bills, salaries or contractor bills, and its settings are hidden. Bills already posted keep their tax.'
                : 'Income tax is worked out again on new purchase bills, salaries and contractor bills, and its settings show.')
            ->action(function (): void {
                $on = ! self::enabled();
                self::set((string) app(TenantContext::class)->entityId(), $on);
                Notification::make()->success()->title('Income tax is '.($on ? 'ON' : 'OFF').'.')->send();
            });
    }
}
