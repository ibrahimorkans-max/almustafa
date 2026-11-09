<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Accounts\NetworkScanner;
use App\Domain\Messaging\Gateways\FirebasePush;
use App\Domain\Messaging\Gateways\WhatsAppCloud;
use App\Domain\Messaging\Integrations;
use App\Domain\Messaging\SenderIdentities;
use App\Domain\Messaging\WhatsAppFiles;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\Usage\ServiceUsage;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Administration → Connected services (a mill's Owner or Super Admin), read only: the outside services the SaaS
 * owner connected for every mill, and this mill's sender identity on each — WhatsApp, SMS, e-mail — with AI and push
 * notifications and the last activity. No credentials are asked for or shown here; only the SaaS owner connects,
 * changes or assigns them. The mill keeps its own wording for files on WhatsApp, its test message and its scanner.
 */
class IntegrationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Connected services';

    protected static ?string $title = 'Connected SaaS services';

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

        $last = $entityId === null ? null : ServiceUsage::query()->where('entity_id', $entityId)->latest('occurred_at')->first();

        return [
            'mill_chosen' => $entityId !== null,
            'whatsapp' => SenderIdentities::assigned('whatsapp', $entityId), 'whatsapp_problem' => SenderIdentities::problem('whatsapp', $entityId),
            'whatsapp_connected' => WhatsAppCloud::configured($entityId), 'whatsapp_name' => SenderIdentities::nameFor($entityId),
            'sms' => SenderIdentities::assigned('sms', $entityId), 'email' => SenderIdentities::assigned('email', $entityId),
            'mail_from' => SenderIdentities::mailFrom($entityId),
            'ai' => filled(Integrations::ai($entityId)['api_key']),
            'last' => $last,
            'number' => $effective['business_number'],
            'push' => FirebasePush::configured(),
            'push_web' => FirebasePush::webReady(),
            'scanner' => NetworkScanner::address($entityId),
            'scanner_possible' => NetworkScanner::available(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fileMessage')->label('WhatsApp file message')->icon('heroicon-o-paper-clip')->color('gray')
                ->visible(fn () => $this->entityId() !== null)
                ->modalHeading('The message sent with a file on WhatsApp')
                ->modalDescription(fn () => 'Used by "Send on WhatsApp" on slips, invoices, bills, vouchers, gate passes and statements; it can still be changed before each sending. Words in braces are filled in: '
                    .implode(', ', array_map(fn ($k, $v) => $k.' = '.$v, array_keys(WhatsAppFiles::PLACEHOLDERS), WhatsAppFiles::PLACEHOLDERS)).'.')
                ->fillForm(fn () => ['text' => app(WhatsAppFiles::class)->template($this->entityId())])
                ->form([
                    Textarea::make('text')->label('Message')->required()->rows(3)->maxLength(700)
                        ->helperText('Example: Dear {party}, please find your {document} {number} attached. {mill}'),
                ])
                ->action(function (array $data): void {
                    try {
                        app(WhatsAppFiles::class)->saveTemplate((string) $this->entityId(), (string) $data['text']);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Saved. Files sent on WhatsApp now carry this message.')
                        ->body(app(WhatsAppFiles::class)->message($this->entityId(), 'Muhammad Aslam', 'weighment slip', 'AMR/G/2026-27/0001'))->send();
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
        ];
    }
}
