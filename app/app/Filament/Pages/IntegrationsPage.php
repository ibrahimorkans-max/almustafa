<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Accounts\NetworkScanner;
use App\Domain\Messaging\Gateways\FirebasePush;
use App\Domain\Messaging\Gateways\WhatsAppCloud;
use App\Domain\Messaging\Integrations;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Administration → Integrations (a mill's Owner or Super Admin): connect the mill's own WhatsApp
 * Business number, so messages to its parties come from it; test it; disconnect. Without its own, the
 * product's WhatsApp (or the server's) is used. Push notifications (Firebase) belong to the product and
 * are shown here only as on or off.
 */
class IntegrationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'Integrations: WhatsApp and notifications';

    protected static ?string $slug = 'integrations';

    protected static ?int $navigationSort = 60;

    protected static string $view = 'filament.pages.integrations';

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ! $me->isPlatformAdmin() && $me->hasPermission('settings.manage');
    }

    public function entityId(): ?string
    {
        return app(TenantContext::class)->entityId();
    }

    /** @return array<string, mixed> what the page shows (no secret) */
    public function getState(): array
    {
        $entityId = $this->entityId();
        $effective = Integrations::whatsapp($entityId);

        return [
            'mill_chosen' => $entityId !== null,
            'own' => $entityId === null ? null : Integrations::whatsappSaved($entityId),
            'source' => WhatsAppCloud::configured($entityId) ? $effective['source'] : null,
            'number' => $effective['business_number'],
            'push' => FirebasePush::configured(),
            'push_web' => FirebasePush::webReady(),
            'scanner' => NetworkScanner::address($entityId),
            'scanner_possible' => NetworkScanner::available(),
        ];
    }

    protected function getHeaderActions(): array
    {
        $own = fn () => $this->entityId() !== null ? Integrations::whatsappSaved((string) $this->entityId()) : null;

        return [
            Action::make('connectWhatsapp')->label(fn () => $own() ? 'Change WhatsApp' : 'Connect WhatsApp')->icon('heroicon-o-chat-bubble-left-right')
                ->visible(fn () => $this->entityId() !== null)
                ->modalHeading('Connect the mill\'s WhatsApp Business number')
                ->modalDescription('From Meta → WhatsApp Manager → API setup: the phone number ID and a permanent access token (a system user token). The template must be approved in WhatsApp Manager. The settings are checked with WhatsApp before they are saved, and kept encrypted.')
                ->fillForm(fn () => array_merge(['template' => 'mill_update', 'language' => 'en'], array_diff_key($own() ?? [], ['access_token' => true])))
                ->form([
                    TextInput::make('phone_number_id')->label('Phone number ID')->required()->regex('/^\d{6,30}$/'),
                    TextInput::make('access_token')->label('Access token')->password()->revealable(false)
                        ->placeholder(fn () => $own() ? 'Saved: leave empty to keep it' : '')->required(fn () => $own() === null),
                    TextInput::make('template')->label('Message template name')->required()->maxLength(100),
                    TextInput::make('language')->label('Template language code')->required()->maxLength(10)->placeholder('en, ur'),
                ])
                ->action(function (array $data): void {
                    try {
                        $s = Integrations::saveWhatsapp((string) $this->entityId(), $data);
                        Notification::make()->success()->title('WhatsApp connected: '.$s['business_number'])->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    }
                }),
            Action::make('testWhatsapp')->label('Send a test message')->icon('heroicon-o-paper-airplane')->color('gray')
                ->visible(fn () => WhatsAppCloud::configured($this->entityId()))
                ->form([TextInput::make('to')->label('To (mobile number)')->required()->tel()->placeholder('03001234567')])
                ->action(function (array $data): void {
                    $to = preg_replace('/\D/', '', (string) $data['to']) ?? '';
                    $to = str_starts_with($to, '0') ? '92'.substr($to, 1) : $to;
                    $result = (new WhatsAppCloud($this->entityId()))->send($to, 'This is a test message from your mill\'s system.');
                    $result['ok']
                        ? Notification::make()->success()->title('Sent. Check the phone.')->send()
                        : Notification::make()->danger()->title((string) $result['error'])->persistent()->send();
                }),
            Action::make('scanner')->label('Scanner')->icon('heroicon-o-printer')->color('gray')
                ->visible(fn () => $this->entityId() !== null && NetworkScanner::available())
                ->modalHeading('The mill\'s network scanner')
                ->modalDescription('For roznamcha pages: the scanner (or printer with a scanner) on the mill\'s network that understands AirScan / eSCL (most HP, Canon, Epson and Brother models do). Give its address, e.g. http://192.168.1.50. Empty: no scanner.')
                ->fillForm(fn () => ['address' => NetworkScanner::address($this->entityId())])
                ->form([TextInput::make('address')->label('Scanner address')->maxLength(200)->placeholder('http://192.168.1.50')])
                ->action(function (array $data): void {
                    try {
                        $address = filled($data['address'] ?? null) ? NetworkScanner::validate((string) $data['address']) : null;
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    app(SettingsService::class)->put(SettingsService::SCANNER_URL, $address, (string) $this->entityId());
                    Notification::make()->success()->title($address === null ? 'Scanner removed.' : 'Scanner saved: '.$address)->send();
                }),
            Action::make('disconnectWhatsapp')->label('Disconnect WhatsApp')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn () => $own() !== null)
                ->requiresConfirmation()->modalDescription('The saved number and token are deleted. Messages then use the product\'s WhatsApp, or wait to be sent by hand.')
                ->action(function (): void {
                    Integrations::removeWhatsapp((string) $this->entityId());
                    Notification::make()->success()->title('Disconnected.')->send();
                }),
        ];
    }
}
