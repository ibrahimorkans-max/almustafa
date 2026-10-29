<?php

declare(strict_types=1);

namespace App\Filament\Pages\Platform;

use App\Domain\Messaging\Gateways\FirebasePush;
use App\Domain\Messaging\Gateways\WhatsAppCloud;
use App\Domain\Messaging\Integrations;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Platform → Integrations (product owner): the product's WhatsApp (used by every mill that has not
 * connected its own) and the product's Firebase project (notifications on party phones and browsers).
 * Each is checked before it is saved, kept encrypted in the server's private storage, and never shown
 * again; "Test" proves it works.
 */
class PlatformIntegrationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'Integrations: WhatsApp and Firebase';

    protected static ?string $slug = 'platform/integrations';

    protected static ?int $navigationSort = 29;

    protected static string $view = 'filament.pages.platform.integrations';

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->isPlatformAdmin();
    }

    /** @return array<string, mixed> what the page shows (no secret) */
    public function getState(): array
    {
        $fb = Integrations::firebase();
        $wa = Integrations::whatsapp(null);

        return [
            'whatsapp' => WhatsAppCloud::configured(null) ? $wa['source'] : null,
            'whatsapp_saved' => Integrations::whatsappSaved('platform'),
            'firebase' => FirebasePush::configured() ? $fb['source'] : null,
            'firebase_project' => $fb['key']['project_id'] ?? null,
            'firebase_web' => FirebasePush::webReady(),
        ];
    }

    protected function getHeaderActions(): array
    {
        $saved = fn () => Integrations::whatsappSaved('platform');

        return [
            Action::make('whatsapp')->label('Connect WhatsApp')->icon('heroicon-o-chat-bubble-left-right')
                ->modalHeading('The product\'s WhatsApp Business number')
                ->modalDescription('Used by every mill that has not connected its own. From Meta → WhatsApp Manager → API setup. Checked with WhatsApp before saving.')
                ->fillForm(fn () => array_merge(['template' => 'mill_update', 'language' => 'en'], array_diff_key($saved() ?? [], ['access_token' => true])))
                ->form([
                    TextInput::make('phone_number_id')->label('Phone number ID')->required()->regex('/^\d{6,30}$/'),
                    TextInput::make('access_token')->label('Access token')->password()->revealable(false)
                        ->placeholder(fn () => $saved() ? 'Saved: leave empty to keep it' : '')->required(fn () => $saved() === null),
                    TextInput::make('template')->label('Message template name')->required()->maxLength(100),
                    TextInput::make('language')->label('Template language code')->required()->maxLength(10),
                ])
                ->action(function (array $data): void {
                    try {
                        $s = Integrations::saveWhatsapp('platform', $data);
                        Notification::make()->success()->title('WhatsApp connected: '.$s['business_number'])->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    }
                }),
            Action::make('firebase')->label('Connect Firebase')->icon('heroicon-o-bell-alert')
                ->modalHeading('The product\'s Firebase project')
                ->modalDescription('Firebase console → Project settings: the service account key (Service accounts → Generate new private key), the web app\'s settings (General → Your apps) and the Web Push key (Cloud Messaging). Checked with Google before saving.')
                ->fillForm(fn () => ['web' => Integrations::firebase()['web'], 'vapid_key' => Integrations::firebase()['vapid_key']])
                ->form([
                    FileUpload::make('key')->label('Service account key (.json)')->storeFiles(false)->acceptedFileTypes(['application/json', 'text/plain'])->maxSize(64)
                        ->helperText(fn () => Integrations::firebase()['source'] === 'product' ? 'Saved: leave empty to keep it.' : 'Required.'),
                    Section::make('Web app (for notifications in browsers)')->columns(2)->schema([
                        TextInput::make('web.apiKey')->label('API key'),
                        TextInput::make('web.authDomain')->label('Auth domain'),
                        TextInput::make('web.projectId')->label('Project ID'),
                        TextInput::make('web.messagingSenderId')->label('Messaging sender ID'),
                        TextInput::make('web.appId')->label('App ID'),
                        TextInput::make('vapid_key')->label('Web Push key (VAPID)'),
                    ]),
                ])
                ->action(function (array $data): void {
                    $file = $data['key'] ?? null;
                    if (is_array($file)) {
                        $file = reset($file) ?: null;
                    }
                    try {
                        Integrations::saveFirebase($file instanceof TemporaryUploadedFile ? (string) $file->get() : null, (array) ($data['web'] ?? []), (string) ($data['vapid_key'] ?? ''));
                        $check = app(FirebasePush::class)->check();
                        $check === null
                            ? Notification::make()->success()->title('Firebase connected; Google accepted the service account.')->send()
                            : Notification::make()->danger()->title('Saved, but Google refused it: '.$check)->persistent()->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    }
                }),
            Action::make('testFirebase')->label('Test Firebase')->icon('heroicon-o-check-badge')->color('gray')
                ->visible(fn () => FirebasePush::configured())
                ->action(function (): void {
                    $check = app(FirebasePush::class)->check();
                    $check === null
                        ? Notification::make()->success()->title('Google accepted the service account: notifications can be sent.')->send()
                        : Notification::make()->danger()->title($check)->persistent()->send();
                }),
            Action::make('disconnect')->label('Disconnect…')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn () => $saved() !== null || Integrations::firebase()['source'] === 'product')
                ->form([Radio::make('what')->options(['whatsapp' => 'The product\'s WhatsApp', 'firebase' => 'Firebase'])->required()])
                ->requiresConfirmation()->modalDescription('The saved settings are deleted. The server\'s .env settings, if any, apply again.')
                ->action(function (array $data): void {
                    $data['what'] === 'whatsapp' ? Integrations::removeWhatsapp('platform') : Integrations::removeFirebase();
                    Notification::make()->success()->title('Disconnected.')->send();
                }),
        ];
    }
}
