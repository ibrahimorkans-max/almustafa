<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Gateways;

use App\Models\PartyPushToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Firebase Cloud Messaging (HTTP v1): a notification to each phone or browser a party turned
 * notifications on for. The service account key signs a short-lived Google access token (RS256, kept
 * 50 minutes); a token Firebase no longer knows (the app was removed, permission withdrawn) is deleted.
 */
final class FirebasePush
{
    public static function configured(): bool
    {
        $path = (string) config('ricemill.messaging.firebase.credentials');

        return $path !== '' && is_file(self::path($path));
    }

    /** Web app settings and the push key are set, so a party can turn notifications on in the browser. */
    public static function webReady(): bool
    {
        $web = (array) config('ricemill.messaging.firebase.web');

        return self::configured() && ($web['apiKey'] ?? '') !== '' && ($web['messagingSenderId'] ?? '') !== ''
            && (string) config('ricemill.messaging.firebase.vapid_key') !== '';
    }

    /**
     * Sends to every device of the party.
     *
     * @return array{sent: int, failed: int, removed: int, error: string|null}
     */
    public function sendToParty(string $partyId, string $title, string $body): array
    {
        $tokens = PartyPushToken::query()->where('party_id', $partyId)->get();
        $result = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'error' => null];
        if ($tokens->isEmpty()) {
            return $result;
        }
        try {
            [$projectId, $access] = $this->accessToken();
        } catch (Throwable $e) {
            return ['sent' => 0, 'failed' => $tokens->count(), 'removed' => 0, 'error' => 'Firebase: '.mb_substr($e->getMessage(), 0, 200)];
        }
        foreach ($tokens as $device) {
            $response = Http::timeout(15)->withToken($access)->acceptJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", ['message' => [
                    'token' => $device->token,
                    'notification' => ['title' => $title, 'body' => mb_substr($body, 0, 1000)],
                    'webpush' => ['fcm_options' => ['link' => url('/admin/my-account')]],
                ]]);
            if ($response->successful()) {
                $result['sent']++;
                $device->forceFill(['last_used_at' => now()])->save();
            } elseif (in_array($response->status(), [404, 410], true) || $response->json('error.status') === 'NOT_FOUND'
                || str_contains((string) $response->body(), 'UNREGISTERED')) {
                $device->delete();
                $result['removed']++;
            } else {
                $result['failed']++;
                $result['error'] = 'Firebase: '.mb_substr((string) ($response->json('error.message') ?? 'HTTP '.$response->status()), 0, 200);
            }
        }

        return $result;
    }

    /** @return array{0: string, 1: string} project id, access token */
    private function accessToken(): array
    {
        $key = json_decode((string) file_get_contents(self::path((string) config('ricemill.messaging.firebase.credentials'))), true);
        if (! is_array($key) || empty($key['client_email']) || empty($key['private_key']) || empty($key['project_id'])) {
            throw new RuntimeException('the service account key file is not a Firebase service account key.');
        }
        $token = Cache::remember('firebase.access.'.md5((string) $key['client_email']), now()->addMinutes(50), function () use ($key): string {
            $now = time();
            $segments = [
                self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: ''),
                self::b64(json_encode([
                    'iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => $key['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
                ]) ?: ''),
            ];
            $signature = '';
            if (! openssl_sign(implode('.', $segments), $signature, (string) $key['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('the private key in the service account key could not sign.');
            }
            $response = Http::asForm()->timeout(15)->post($key['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments).'.'.self::b64($signature),
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('Google refused the service account ('.($response->json('error_description') ?? 'HTTP '.$response->status()).').');
            }

            return $response->json('access_token');
        });

        return [(string) $key['project_id'], $token];
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** A relative path is under storage/app/private. */
    private static function path(string $path): string
    {
        return preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) ? $path : storage_path('app/private/'.ltrim($path, '/\\'));
    }
}
