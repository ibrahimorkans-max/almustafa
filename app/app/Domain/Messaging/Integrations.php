<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
    public const WHATSAPP_KEYS = ['phone_number_id', 'access_token', 'template', 'language', 'api_version', 'business_number'];

    // ------------------------------------------------------------------ WhatsApp

    /**
     * The WhatsApp settings for a mill: its own, the product owner's, or the server's (.env).
     *
     * @return array{phone_number_id: string, access_token: string, template: string, language: string, api_version: string, source: string, business_number: string|null}
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
                ];
            }
        }

        return [
            'phone_number_id' => (string) ($env['phone_number_id'] ?? ''), 'access_token' => (string) ($env['access_token'] ?? ''),
            'template' => (string) ($env['template'] ?? 'mill_update'), 'language' => (string) ($env['language'] ?? 'en'),
            'api_version' => (string) ($env['api_version'] ?? 'v21.0'), 'source' => 'server', 'business_number' => null,
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
