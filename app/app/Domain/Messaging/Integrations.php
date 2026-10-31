<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Assistant\AiGateway;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * WhatsApp and Firebase connected from inside the system (Administration → Integrations for a mill,
 * Platform → Integrations for the product owner), instead of only in the server's .env file.
 *
 *  WhatsApp (Meta Cloud API): a mill may connect its own business number; otherwise the product owner's
 *  connection is used; otherwise the .env settings, as before.
 *  Firebase (push notifications to party phones and browsers): the product's own project, set by the
 *  product owner; otherwise the .env settings.
 *
 * Secrets never go into the database or onto a screen: each connection is one file under
 * storage/app/private/integrations, encrypted with the application key. A saved token is shown only as
 * "saved"; leaving the field empty keeps it.
 */
final class Integrations
{
    private const DIR = 'integrations';

    /** The settings a WhatsApp connection has (access_token is the secret). */
    public const WHATSAPP_KEYS = ['phone_number_id', 'access_token', 'template', 'language', 'api_version', 'business_number', 'doc_template'];

    // ------------------------------------------------------------------ WhatsApp

    /**
     * The WhatsApp settings for a mill: its own, the product owner's, or the server's (.env).
     *
     * @return array{phone_number_id: string, access_token: string, template: string, language: string, api_version: string, source: string, business_number: string|null, doc_template: string}
     */
    public static function whatsapp(?string $entityId): array
    {
        $env = (array) config('ricemill.messaging.whatsapp_cloud');
        foreach ([$entityId !== null ? 'whatsapp-'.$entityId : null, 'whatsapp-platform'] as $file) {
            $saved = $file === null ? null : self::read($file);
            if ($saved !== null && filled($saved['phone_number_id'] ?? null) && filled($saved['access_token'] ?? null)) {
                return [
                    'phone_number_id' => (string) $saved['phone_number_id'], 'access_token' => (string) $saved['access_token'],
                    'template' => (string) ($saved['template'] ?? $env['template'] ?? 'mill_update'), 'language' => (string) ($saved['language'] ?? $env['language'] ?? 'en'),
                    'api_version' => (string) ($saved['api_version'] ?? $env['api_version'] ?? 'v21.0'),
                    'source' => $file === 'whatsapp-platform' ? 'product' : 'mill', 'business_number' => $saved['business_number'] ?? null,
                    'doc_template' => (string) ($saved['doc_template'] ?? ''),
                ];
            }
        }

        return [
            'phone_number_id' => (string) ($env['phone_number_id'] ?? ''), 'access_token' => (string) ($env['access_token'] ?? ''),
            'template' => (string) ($env['template'] ?? 'mill_update'), 'language' => (string) ($env['language'] ?? 'en'),
            'api_version' => (string) ($env['api_version'] ?? 'v21.0'), 'source' => 'server', 'business_number' => null,
            'doc_template' => (string) ($env['doc_template'] ?? ''),
        ];
    }

    /**
     * The saved (not effective) WhatsApp settings of a mill (its id) or of the product ('platform'), token hidden.
     *
     * @return array<string, mixed>|null
     */
    public static function whatsappSaved(string $scope): ?array
    {
        $saved = self::read('whatsapp-'.$scope);

        return $saved === null ? null : ['access_token' => filled($saved['access_token'] ?? null) ? '(saved)' : ''] + $saved;
    }

    /**
     * Saves a WhatsApp connection after checking it with Meta (the number it belongs to is kept to show).
     * An empty token keeps the saved one.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function saveWhatsapp(string $scope, array $input): array
    {
        $old = self::read('whatsapp-'.$scope) ?? [];
        $settings = [
            'phone_number_id' => trim((string) ($input['phone_number_id'] ?? '')),
            'access_token' => filled($input['access_token'] ?? null) ? trim((string) $input['access_token']) : (string) ($old['access_token'] ?? ''),
            'template' => trim((string) ($input['template'] ?? '')) ?: 'mill_update',
            'language' => trim((string) ($input['language'] ?? '')) ?: 'en',
            'api_version' => trim((string) ($input['api_version'] ?? '')) ?: (string) config('ricemill.messaging.whatsapp_cloud.api_version', 'v21.0'),
            'doc_template' => trim((string) ($input['doc_template'] ?? $old['doc_template'] ?? '')),
        ];
        if (! preg_match('/^\d{6,30}$/', $settings['phone_number_id']) || $settings['access_token'] === '') {
            throw new BusinessRuleViolation('Give the phone number ID (digits, from WhatsApp Manager → API setup) and the access token.');
        }
        $settings['business_number'] = self::checkWhatsapp($settings);
        self::write('whatsapp-'.$scope, $settings);
        app(AuditLogger::class)->record('integration.whatsapp.connected', null, null, null, ['scope' => $scope, 'number' => $settings['business_number']]);

        return $settings;
    }

    /**
     * Asks Meta which number the ID and token belong to; refuses a wrong pair.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function checkWhatsapp(array $settings): string
    {
        try {
            $response = Http::timeout(15)->withToken((string) $settings['access_token'])->acceptJson()
                ->get('https://graph.facebook.com/'.$settings['api_version'].'/'.$settings['phone_number_id'], ['fields' => 'display_phone_number,verified_name']);
        } catch (Throwable $e) {
            throw new BusinessRuleViolation('WhatsApp could not be reached: '.mb_substr($e->getMessage(), 0, 150));
        }
        if (! $response->successful()) {
            throw new BusinessRuleViolation('WhatsApp refused these settings: '.mb_substr((string) ($response->json('error.message') ?? 'HTTP '.$response->status()), 0, 200));
        }

        return trim(($response->json('verified_name') ? $response->json('verified_name').' · ' : '').(string) $response->json('display_phone_number'));
    }

    public static function removeWhatsapp(string $scope): void
    {
        Storage::disk('local')->delete(self::path('whatsapp-'.$scope));
        app(AuditLogger::class)->record('integration.whatsapp.disconnected', null, null, null, ['scope' => $scope]);
    }

    // ------------------------------------------------------------------ AI (any provider's API key)

    /**
     * The AI providers offered: name, address, a suggested model and other models to choose from. Every one but
     * Anthropic speaks the OpenAI "chat completions" format (App\Domain\Assistant\AiGateway). "custom" is any
     * other OpenAI-compatible service, at the address given.
     */
    public const AI_PROVIDERS = [
        'anthropic' => ['name' => 'Anthropic (Claude)', 'url' => 'https://api.anthropic.com/v1/messages', 'model' => 'claude-sonnet-5-5',
            'models' => ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'], 'keys' => 'console.anthropic.com → API keys (starts with sk-ant-)'],
        'openrouter' => ['name' => 'OpenRouter (many models with one key)', 'url' => 'https://openrouter.ai/api/v1/chat/completions', 'model' => 'anthropic/claude-sonnet-5.5',
            'models' => ['anthropic/claude-sonnet-5.5', 'openai/gpt-5', 'google/gemini-2.5-pro', 'google/gemini-2.5-flash', 'meta-llama/llama-4-maverick', 'deepseek/deepseek-chat', 'qwen/qwen3-235b-a22b'],
            'keys' => 'openrouter.ai → Keys (starts with sk-or-)'],
        'openai' => ['name' => 'OpenAI (ChatGPT)', 'url' => 'https://api.openai.com/v1/chat/completions', 'model' => 'gpt-5',
            'models' => ['gpt-5', 'gpt-5-mini', 'gpt-4.1', 'gpt-4o'], 'keys' => 'platform.openai.com → API keys (starts with sk-)'],
        'gemini' => ['name' => 'Google Gemini', 'url' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'model' => 'gemini-2.5-flash',
            'models' => ['gemini-2.5-flash', 'gemini-2.5-pro'], 'keys' => 'aistudio.google.com → Get API key'],
        'groq' => ['name' => 'Groq', 'url' => 'https://api.groq.com/openai/v1/chat/completions', 'model' => 'llama-3.3-70b-versatile',
            'models' => ['llama-3.3-70b-versatile', 'meta-llama/llama-4-maverick-17b-128e-instruct'], 'keys' => 'console.groq.com → API keys (starts with gsk_)'],
        'deepseek' => ['name' => 'DeepSeek', 'url' => 'https://api.deepseek.com/chat/completions', 'model' => 'deepseek-chat',
            'models' => ['deepseek-chat', 'deepseek-reasoner'], 'keys' => 'platform.deepseek.com → API keys'],
        'mistral' => ['name' => 'Mistral', 'url' => 'https://api.mistral.ai/v1/chat/completions', 'model' => 'mistral-large-latest',
            'models' => ['mistral-large-latest', 'mistral-medium-latest', 'pixtral-large-latest'], 'keys' => 'console.mistral.ai → API keys'],
        'custom' => ['name' => 'Other (OpenAI-compatible address)', 'url' => '', 'model' => '',
            'models' => [], 'keys' => 'the service\'s own console'],
    ];

    /**
     * The AI settings for a mill (the Assistant chatbot and the AI reader of roznamcha pages): its own, the product
     * owner's, or the server's (.env, Anthropic).
     *
     * @return array{provider: string, api_key: string, model: string, api_url: string, source: string|null}
     */
    public static function ai(?string $entityId): array
    {
        $env = (array) config('ricemill.assistant');
        foreach ([$entityId !== null ? 'ai-'.$entityId : null, 'ai-platform'] as $file) {
            $saved = $file === null ? null : self::read($file);
            if ($saved !== null && filled($saved['api_key'] ?? null)) {
                $provider = isset(self::AI_PROVIDERS[$saved['provider'] ?? '']) ? (string) $saved['provider'] : 'anthropic';

                return ['provider' => $provider, 'api_key' => (string) $saved['api_key'],
                    'model' => (string) ($saved['model'] ?? self::AI_PROVIDERS[$provider]['model']),
                    'api_url' => self::aiUrl($provider, (string) ($saved['base_url'] ?? ''), (string) ($env['api_url'] ?? '')),
                    'source' => $file === 'ai-platform' ? 'product' : 'mill'];
            }
        }

        return ['provider' => 'anthropic', 'api_key' => (string) ($env['api_key'] ?? ''), 'model' => (string) ($env['model'] ?? 'claude-sonnet-5-5'),
            'api_url' => (string) ($env['api_url'] ?? self::AI_PROVIDERS['anthropic']['url']), 'source' => filled($env['api_key'] ?? null) ? 'server' : null];
    }

    /** The address to call: the provider's, or the custom one (its /chat/completions). */
    private static function aiUrl(string $provider, string $baseUrl, string $envAnthropicUrl): string
    {
        if ($provider === 'custom') {
            $base = rtrim($baseUrl, '/');

            return str_ends_with($base, '/chat/completions') ? $base : $base.'/chat/completions';
        }
        if ($provider === 'anthropic' && $envAnthropicUrl !== '') {
            return $envAnthropicUrl;
        }

        return self::AI_PROVIDERS[$provider]['url'];
    }

    /**
     * The saved settings of a mill (its id) or of the product ('platform'): only the key's last four characters show.
     *
     * @return array{provider: string, model: string, base_url: string, key_end: string, saved_at: string|null}|null
     */
    public static function aiSaved(string $scope): ?array
    {
        $saved = self::read('ai-'.$scope);

        return $saved === null || blank($saved['api_key'] ?? null) ? null
            : ['provider' => isset(self::AI_PROVIDERS[$saved['provider'] ?? '']) ? (string) $saved['provider'] : 'anthropic',
                'model' => (string) ($saved['model'] ?? ''), 'base_url' => (string) ($saved['base_url'] ?? ''),
                'key_end' => substr((string) $saved['api_key'], -4), 'saved_at' => $saved['saved_at'] ?? null];
    }

    /**
     * Saves a provider's API key and model after a tiny test call with them (a wrong key, model or address is
     * refused). An empty key keeps the saved one when the provider stays the same (to change only the model).
     *
     * @param  array<string, mixed>  $input
     */
    public static function saveAi(string $scope, array $input): void
    {
        $old = self::read('ai-'.$scope) ?? [];
        $provider = (string) ($input['provider'] ?? 'anthropic');
        if (! isset(self::AI_PROVIDERS[$provider])) {
            throw new BusinessRuleViolation('Choose the AI provider.');
        }
        $sameProvider = ($old['provider'] ?? 'anthropic') === $provider;
        $key = filled($input['api_key'] ?? null) ? trim((string) $input['api_key']) : ($sameProvider ? (string) ($old['api_key'] ?? '') : '');
        $model = trim((string) ($input['model'] ?? '')) ?: self::AI_PROVIDERS[$provider]['model'];
        $baseUrl = trim((string) ($input['base_url'] ?? ''));
        if ($key === '' || preg_match('/^\S{16,400}$/', $key) !== 1) {
            throw new BusinessRuleViolation('Paste the API key from '.self::AI_PROVIDERS[$provider]['keys'].'.');
        }
        if ($provider === 'anthropic' && ! str_starts_with($key, 'sk-ant-')) {
            throw new BusinessRuleViolation('That is not an Anthropic API key. It starts with "sk-ant-" (console.anthropic.com → API keys → Create key).');
        }
        if (preg_match('/^[A-Za-z0-9._:\/@-]{2,120}$/', $model) !== 1) {
            throw new BusinessRuleViolation('Give the model\'s name as the provider writes it, e.g. '.(self::AI_PROVIDERS[$provider]['model'] ?: 'gpt-4o').'.');
        }
        if ($provider === 'custom') {
            $parts = parse_url($baseUrl);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || blank($parts['host'] ?? null)) {
                throw new BusinessRuleViolation('Give the service\'s https address, e.g. https://api.example.com/v1.');
            }
        }
        self::checkAi(['provider' => $provider, 'api_key' => $key, 'model' => $model, 'api_url' => self::aiUrl($provider, $baseUrl, '')]);
        self::write('ai-'.$scope, ['provider' => $provider, 'api_key' => $key, 'model' => $model, 'base_url' => $provider === 'custom' ? $baseUrl : '',
            'saved_at' => now()->toIso8601String()]);
        app(AuditLogger::class)->record('integration.ai.connected', null, null, null, ['scope' => $scope, 'provider' => $provider, 'model' => $model]);
    }

    /**
     * A one-word question to the provider: a refused key or model, or no connection, is said in words.
     *
     * @param  array{provider: string, api_key: string, model: string, api_url: string}  $ai
     */
    public static function checkAi(array $ai): void
    {
        try {
            $response = app(AiGateway::class)->send($ai, null, [['role' => 'user', 'content' => 'Say OK.']], [], 5, 30);
        } catch (Throwable $e) {
            throw new BusinessRuleViolation('The AI provider could not be reached: '.mb_substr($e->getMessage(), 0, 150));
        }
        if (! $response->successful()) {
            $why = AiGateway::error($response);
            throw new BusinessRuleViolation(match ($response->status()) {
                401 => 'The provider refused the key (wrong, or deleted). Copy it again from '.self::AI_PROVIDERS[$ai['provider']]['keys'].'.',
                402 => 'The key works but the account has no credit: add credit with the provider.',
                403 => 'This key may not use that model, or the account has no credit: '.$why,
                404 => 'The provider does not know that model or address: '.$why,
                429 => 'The key works but is over its limit right now; try again in a minute.',
                default => 'The provider answered: '.$why,
            });
        }
    }

    public static function removeAi(string $scope): void
    {
        Storage::disk('local')->delete(self::path('ai-'.$scope));
        app(AuditLogger::class)->record('integration.ai.disconnected', null, null, null, ['scope' => $scope]);
    }

    // ------------------------------------------------------------------ WhatsApp webhook

    /**
     * What to type in Meta → WhatsApp → Configuration → Webhook: the callback URL and the verify token of
     * the product's number ('platform') or of a mill's own number (its id). The verify token is made the
     * first time it is asked for and kept; the app secret (to check that a call really comes from Meta)
     * is never shown again once saved.
     *
     * @return array{url: string, verify_token: string, has_secret: bool}
     */
    public static function whatsappWebhook(string $scope): array
    {
        $saved = self::read('whatsapp-webhook-'.$scope) ?? [];
        if (blank($saved['verify_token'] ?? null)) {
            $saved['verify_token'] = Str::random(40);
            self::write('whatsapp-webhook-'.$scope, $saved);
        }

        return ['url' => route('whatsapp.webhook', ['scope' => $scope]), 'verify_token' => (string) $saved['verify_token'],
            'has_secret' => filled($saved['app_secret'] ?? null)];
    }

    /** The saved verify token (never made here: an unknown scope has none). */
    public static function whatsappWebhookToken(string $scope): ?string
    {
        $token = self::read('whatsapp-webhook-'.$scope)['verify_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function whatsappWebhookSecret(string $scope): ?string
    {
        $secret = self::read('whatsapp-webhook-'.$scope)['app_secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /** The Meta app's secret (App settings → Basic → App secret): 32 letters and digits. */
    public static function saveWhatsappWebhookSecret(string $scope, string $secret): void
    {
        $secret = trim($secret);
        if (preg_match('/^[A-Fa-f0-9]{32}$/', $secret) !== 1) {
            throw new BusinessRuleViolation('That is not an app secret. In Meta for Developers open the app → App settings → Basic → App secret → Show, and copy the 32 characters.');
        }
        $saved = self::read('whatsapp-webhook-'.$scope) ?? [];
        self::write('whatsapp-webhook-'.$scope, ['verify_token' => $saved['verify_token'] ?? Str::random(40), 'app_secret' => strtolower($secret)]);
        app(AuditLogger::class)->record('integration.whatsapp.webhook_secret', null, null, null, ['scope' => $scope]);
    }

    /** A new verify token (the old one stops working; type the new one in Meta and verify again). */
    public static function renewWhatsappWebhookToken(string $scope): string
    {
        $saved = self::read('whatsapp-webhook-'.$scope) ?? [];
        $saved['verify_token'] = Str::random(40);
        self::write('whatsapp-webhook-'.$scope, $saved);
        app(AuditLogger::class)->record('integration.whatsapp.webhook_token_renewed', null, null, null, ['scope' => $scope]);

        return $saved['verify_token'];
    }

    // ------------------------------------------------------------------ Firebase

    /**
     * The product's Firebase project: the uploaded settings, or the server's (.env).
     *
     * @return array{key: array<string, mixed>|null, web: array<string, string>, vapid_key: string, source: string}
     */
    public static function firebase(): array
    {
        $saved = self::read('firebase');
        if ($saved !== null && is_array($saved['key'] ?? null)) {
            return ['key' => $saved['key'], 'web' => (array) ($saved['web'] ?? []), 'vapid_key' => (string) ($saved['vapid_key'] ?? ''), 'source' => 'product'];
        }
        $path = (string) config('ricemill.messaging.firebase.credentials');
        // A relative path in .env is under storage/app/private (as before).
        $file = $path === '' ? null : (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) === 1 ? $path : storage_path('app/private/'.ltrim($path, '/\\')));
        $key = $file !== null && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return ['key' => is_array($key) ? $key : null, 'web' => array_map('strval', (array) config('ricemill.messaging.firebase.web')),
            'vapid_key' => (string) config('ricemill.messaging.firebase.vapid_key'), 'source' => 'server'];
    }

    /**
     * Saves the Firebase project: the service account key (JSON, from Firebase → Project settings → Service
     * accounts; empty keeps the saved one), the web app settings and the web push key.
     *
     * @param  array<string, mixed>  $web
     */
    public static function saveFirebase(?string $serviceAccountJson, array $web, string $vapidKey): void
    {
        $old = self::read('firebase') ?? [];
        $key = $old['key'] ?? null;
        if ($serviceAccountJson !== null && trim($serviceAccountJson) !== '') {
            $key = json_decode($serviceAccountJson, true);
            if (! is_array($key) || ($key['type'] ?? null) !== 'service_account' || empty($key['project_id']) || empty($key['client_email']) || empty($key['private_key'])) {
                throw new BusinessRuleViolation('That is not a Firebase service account key (Project settings → Service accounts → Generate new private key).');
            }
        }
        if (! is_array($key)) {
            throw new BusinessRuleViolation('Upload the service account key.');
        }
        $web = array_map(fn ($v) => trim((string) $v), array_intersect_key($web, array_flip(['apiKey', 'authDomain', 'projectId', 'messagingSenderId', 'appId'])));
        self::write('firebase', ['key' => $key, 'web' => $web, 'vapid_key' => trim($vapidKey)]);
        app(AuditLogger::class)->record('integration.firebase.connected', null, null, null, ['project' => $key['project_id']]);
    }

    public static function removeFirebase(): void
    {
        Storage::disk('local')->delete(self::path('firebase'));
        app(AuditLogger::class)->record('integration.firebase.disconnected');
    }

    // ------------------------------------------------------------------ files

    /** @return array<string, mixed>|null */
    private static function read(string $name): ?array
    {
        $path = self::path($name);
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString((string) Storage::disk('local')->get($path)), true);
        } catch (Throwable) {
            return null; // made with another application key
        }

        return is_array($data) ? $data : null;
    }

    /** @param  array<string, mixed>  $data */
    private static function write(string $name, array $data): void
    {
        Storage::disk('local')->put(self::path($name), Crypt::encryptString((string) json_encode($data)));
    }

    private static function path(string $name): string
    {
        return self::DIR.'/'.preg_replace('/[^A-Za-z0-9-]/', '', $name).'.json';
    }
}
