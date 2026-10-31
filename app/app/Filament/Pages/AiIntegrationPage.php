<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Messaging\Integrations;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * AI assistant: the Anthropic API key the Assistant chatbot (and the AI reader of roznamcha pages) uses. The
 * product owner sets one for every mill (Platform); a mill's Owner or Super Admin may set the mill's own, which
 * is used instead. The key is checked with Anthropic before it is saved, kept encrypted, and never shown again
 * (only its last four characters).
 */
class AiIntegrationPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'AI assistant';

    protected static ?string $title = 'AI assistant (API key)';

    protected static ?string $slug = 'ai-assistant-key';

    protected static ?int $navigationSort = 62;

    protected static string $view = 'filament.pages.ai-integration';

    public static function getNavigationGroup(): ?string
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->isPlatformAdmin() ? 'Platform' : 'Administration';
    }

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ($me->isPlatformAdmin() || $me->hasPermission('settings.manage'));
    }

    /** 'platform' or the mill being worked in; null: no mill chosen. */
    public function scope(): ?string
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->isPlatformAdmin() ? 'platform' : app(TenantContext::class)->entityId();
    }

    /** @return array<string, mixed> what the page shows (never the key) */
    public function getState(): array
    {
        $scope = $this->scope();
        $effective = Integrations::ai($scope === 'platform' ? null : $scope);
        $own = $scope === null ? null : Integrations::aiSaved($scope);

        return [
            'scope' => $scope, 'own' => $own,
            'source' => $effective['source'], 'model' => $effective['model'],
            'provider' => Integrations::AI_PROVIDERS[$effective['provider']]['name'] ?? $effective['provider'],
            'model_label' => $effective['model'],
            'enabled' => (bool) config('ricemill.assistant.enabled'),
            'ocr_ai' => in_array(config('ricemill.ocr.engine'), ['claude', 'auto'], true),
        ];
    }

    protected function getHeaderActions(): array
    {
        $own = fn () => $this->scope() !== null ? Integrations::aiSaved((string) $this->scope()) : null;

        return [
            Action::make('saveKey')->label(fn () => $own() ? 'Change key or model' : 'Add API key')->icon('heroicon-o-key')
                ->visible(fn () => $this->scope() !== null)
                ->modalHeading('AI provider and API key')
                ->modalDescription('Any provider: Anthropic, OpenRouter (many models with one key), OpenAI, Google Gemini, Groq, DeepSeek, Mistral, or another OpenAI-compatible service. The key is checked with the provider before it is saved, kept encrypted, and never shown again.')
                ->fillForm(fn () => ['provider' => $own()['provider'] ?? 'anthropic', 'model' => $own()['model'] ?? 'claude-sonnet-5-5', 'base_url' => $own()['base_url'] ?? ''])
                ->form([
                    Select::make('provider')->label('Provider')->required()->native(false)->live()
                        ->options(array_map(fn (array $p) => $p['name'], Integrations::AI_PROVIDERS))
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('model', Integrations::AI_PROVIDERS[$state ?? 'anthropic']['model'] ?? '')),
                    TextInput::make('base_url')->label('The service\'s address (OpenAI-compatible)')->placeholder('https://api.example.com/v1')
                        ->visible(fn (Get $get) => $get('provider') === 'custom')->required(fn (Get $get) => $get('provider') === 'custom')->url()->maxLength(300),
                    TextInput::make('model')->label('Model')->required()->maxLength(120)
                        ->datalist(fn (Get $get) => Integrations::AI_PROVIDERS[$get('provider') ?? 'anthropic']['models'] ?? [])
                        ->helperText('Pick a suggestion or type any model the provider offers. To read roznamcha photos, choose one that reads pictures.'),
                    TextInput::make('api_key')->label('API key')->password()->revealable(false)->autocomplete('off')
                        ->placeholder(fn (Get $get) => $own() && ($own()['provider'] ?? null) === $get('provider') ? 'Saved (…'.$own()['key_end'].'): leave empty to keep it' : 'Paste the key')
                        ->helperText(fn (Get $get) => 'From '.(Integrations::AI_PROVIDERS[$get('provider') ?? 'anthropic']['keys'] ?? 'the provider').'.')
                        ->required(fn (Get $get) => $own() === null || ($own()['provider'] ?? null) !== $get('provider')),
                ])
                ->action(function (array $data): void {
                    try {
                        Integrations::saveAi((string) $this->scope(), $data);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title('Saved and checked: the AI assistant uses this key now.')->send();
                }),
            Action::make('testKey')->label('Test the connection')->icon('heroicon-o-signal')->color('gray')
                ->visible(fn () => $this->scope() !== null && $this->getState()['source'] !== null)
                ->action(function (): void {
                    $scope = $this->scope();
                    $ai = Integrations::ai($scope === 'platform' ? null : $scope);
                    try {
                        Integrations::checkAi($ai);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title('Connected: '.(Integrations::AI_PROVIDERS[$ai['provider']]['name'] ?? $ai['provider']).' answered ('.$ai['model'].').')->send();
                }),
            Action::make('removeKey')->label('Remove the key')->icon('heroicon-o-trash')->color('danger')
                ->visible(fn () => $own() !== null)
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->scope() === 'platform'
                    ? 'Mills without their own key then have no AI: the Assistant answers only short commands.'
                    : 'The mill then uses the product\'s key if there is one; otherwise the Assistant answers only short commands.')
                ->action(function (): void {
                    Integrations::removeAi((string) $this->scope());
                    Notification::make()->success()->title('Key removed.')->send();
                }),
        ];
    }
}
