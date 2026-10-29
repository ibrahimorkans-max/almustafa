<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Banking\EmailConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sign-in with Google or Microsoft for reading a mailbox (OAuth 2.0, authorisation code with PKCE).
 * Only the read-only mail permission is asked for. The client id and secret are the product owner's,
 * set in .env (never in the database or on screen):
 *
 *   BANK_EMAIL_GOOGLE_CLIENT_ID / BANK_EMAIL_GOOGLE_CLIENT_SECRET
 *   BANK_EMAIL_MICROSOFT_CLIENT_ID / BANK_EMAIL_MICROSOFT_CLIENT_SECRET / BANK_EMAIL_MICROSOFT_TENANT
 *
 * The tokens come back to /admin/bank-email/callback and are kept encrypted on the connection.
 */
final class MailOAuth
{
    public const PROVIDERS = [
        'google' => [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
        ],
        'microsoft' => [
            'authorize' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
            'token' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            'scope' => 'offline_access https://graph.microsoft.com/Mail.Read',
        ],
    ];

    public static function configured(string $provider): bool
    {
        return filled(config("ricemill.bank_email.{$provider}.client_id")) && filled(config("ricemill.bank_email.{$provider}.client_secret"));
    }

    /** The provider's sign-in page for this connection; the state ties the answer to it. */
    public function authorizeUrl(EmailConnection $connection): string
    {
        $p = $this->provider($connection->provider);
        $state = Str::random(40);
        $verifier = Str::random(64);
        $connection->forceFill(['oauth_state' => $state, 'credentials' => ['pkce' => $verifier], 'status' => 'pending'])->save();

        return $p['authorize'].'?'.http_build_query([
            'client_id' => config("ricemill.bank_email.{$connection->provider}.client_id"),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => $p['scope'],
            'state' => $state,
            'access_type' => 'offline',     // Google: a refresh token
            'prompt' => 'consent',
            'login_hint' => $connection->email,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /** The code from the provider, exchanged for tokens kept on the connection. */
    public function complete(EmailConnection $connection, string $code): void
    {
        $p = $this->provider($connection->provider);
        $verifier = (string) ($connection->credentials['pkce'] ?? '');
        $response = Http::asForm()->timeout(20)->post($p['token'], [
            'client_id' => config("ricemill.bank_email.{$connection->provider}.client_id"),
            'client_secret' => config("ricemill.bank_email.{$connection->provider}.client_secret"),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::redirectUri(),
            'code_verifier' => $verifier,
        ]);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new BusinessRuleViolation('The mailbox sign-in was not accepted ('.($response->json('error') ?? $response->status()).').');
        }
        $connection->forceFill([
            'credentials' => self::tokens((array) $response->json(), null),
            'oauth_state' => null, 'status' => 'connected', 'last_error' => null,
        ])->save();
    }

    /** A current access token, refreshed when it is about to run out. */
    public function accessToken(EmailConnection $connection): string
    {
        $c = (array) $connection->credentials;
        if (isset($c['access_token'], $c['expires_at']) && (int) $c['expires_at'] > time() + 60) {
            return (string) $c['access_token'];
        }
        if (! filled($c['refresh_token'] ?? null)) {
            throw new BusinessRuleViolation('The mailbox needs to be signed in again.');
        }
        $p = $this->provider($connection->provider);
        $response = Http::asForm()->timeout(20)->post($p['token'], [
            'client_id' => config("ricemill.bank_email.{$connection->provider}.client_id"),
            'client_secret' => config("ricemill.bank_email.{$connection->provider}.client_secret"),
            'refresh_token' => $c['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new BusinessRuleViolation('The mailbox sign-in has expired; connect it again.');
        }
        $connection->forceFill(['credentials' => self::tokens((array) $response->json(), (string) $c['refresh_token'])])->save();

        return (string) $response->json('access_token');
    }

    public static function redirectUri(): string
    {
        return (string) (config('ricemill.bank_email.redirect_uri') ?: route('filament.admin.bank-email.callback'));
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private static function tokens(array $json, ?string $previousRefresh): array
    {
        return [
            'access_token' => (string) $json['access_token'],
            'refresh_token' => (string) ($json['refresh_token'] ?? $previousRefresh ?? ''),
            'expires_at' => time() + (int) ($json['expires_in'] ?? 3600),
        ];
    }

    /** @return array{authorize: string, token: string, scope: string} */
    private function provider(string $provider): array
    {
        if (! isset(self::PROVIDERS[$provider]) || ! self::configured($provider)) {
            throw new BusinessRuleViolation('Sign-in with this e-mail provider is not set up. The product owner adds it.');
        }
        $tenant = (string) (config('ricemill.bank_email.microsoft.tenant') ?: 'common');

        return array_map(fn (string $v) => str_replace('{tenant}', $tenant, $v), self::PROVIDERS[$provider]);
    }
}
