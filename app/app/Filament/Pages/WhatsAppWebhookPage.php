<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Messaging\Integrations;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\WhatsAppEvent;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * WhatsApp webhook: what to type in Meta → WhatsApp → Configuration → Webhook (the callback URL and the
 * verify token, with copy buttons and the steps), the app secret that proves a call comes from Meta, and
 * what WhatsApp has sent back: delivery and read receipts of our messages and the messages people sent.
 *
 * The product owner sets it up for the product's number (Platform); a mill's Owner or Super Admin for the
 * mill's own number, when it connected one under Administration → Integrations.
 */
class WhatsAppWebhookPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'WhatsApp webhook';

    protected static ?string $title = 'WhatsApp webhook';

    protected static ?string $slug = 'whatsapp-webhook';

    protected static ?int $navigationSort = 61;

    protected static string $view = 'filament.pages.whatsapp-webhook';

    public static function getNavigationGroup(): ?string
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->isPlatformAdmin() ? 'Platform' : 'Administration';
    }

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        // One central connection: only the SaaS owner sets it (no mill connects its own).
        return $me instanceof LoginAccount && $me->isPlatformAdmin();
    }

    /** 'platform' (the product's number) or the mill being worked in; null: no mill chosen. */
    public function scope(): ?string
    {
        $me = Filament::auth()->user();
        if ($me instanceof LoginAccount && $me->isPlatformAdmin()) {
            return 'platform';
        }

        return app(TenantContext::class)->entityId();
    }

    /** @return array{url: string, verify_token: string, has_secret: bool}|null */
    public function getWebhook(): ?array
    {
        $scope = $this->scope();

        return $scope === null ? null : Integrations::whatsappWebhook($scope);
    }

    /** True when this mill sends from its own number (its own Meta app needs this webhook). */
    public function getOwnNumber(): bool
    {
        $scope = $this->scope();

        return $scope === 'platform' || ($scope !== null && Integrations::whatsappSaved($scope) !== null);
    }

    /** @return Collection<int, WhatsAppEvent> the latest receipts and messages */
    public function getEvents(): Collection
    {
        $scope = $this->scope();
        if ($scope === null) {
            return collect();
        }

        return app(TenantContext::class)->runAsSystem(fn () => WhatsAppEvent::query()->with('party')
            ->when($scope === 'platform', fn ($q) => $q->where('scope', 'platform'), fn ($q) => $q->where('entity_id', $scope))
            ->orderByDesc('occurred_at')->limit(50)->get());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('appSecret')->label(fn () => ($this->getWebhook()['has_secret'] ?? false) ? 'Change app secret' : 'Save app secret')
                ->icon('heroicon-o-key')->visible(fn () => $this->scope() !== null)
                ->modalHeading('The Meta app\'s secret')
                ->modalDescription('Meta for Developers → your app → App settings → Basic → App secret → Show. It proves that each message to the webhook really comes from Meta. Kept encrypted and never shown again.')
                ->form([TextInput::make('secret')->label('App secret')->password()->revealable(false)->required()->length(32)])
                ->action(function (array $data): void {
                    try {
                        Integrations::saveWhatsappWebhookSecret((string) $this->scope(), (string) $data['secret']);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title('App secret saved. WhatsApp\'s messages are accepted now.')->send();
                }),
            Action::make('renewToken')->label('New verify token')->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn () => $this->scope() !== null)
                ->requiresConfirmation()
                ->modalDescription('The current verify token stops working. Type the new one in Meta and press "Verify and save" again. (Messages keep arriving meanwhile; the token is only for the check.)')
                ->action(function (): void {
                    Integrations::renewWhatsappWebhookToken((string) $this->scope());
                    Notification::make()->success()->title('New verify token made: copy it into Meta.')->send();
                }),
        ];
    }
}
