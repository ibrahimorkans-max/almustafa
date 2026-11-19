<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Messaging\Gateways\WhatsAppCloud;
use App\Domain\Messaging\MessageText;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The second check of a held sign-in (new network or device) when the person has no other device signed in to scan
 * the QR code: a 6-digit code sent to the mobile number on the login — by WhatsApp (the mill's connected sender), else
 * the SMS gateway (RICEMILL_SMS_URL). It works for that sign-in only, 10 minutes, 5 tries; only its keyed hash is kept.
 */
final class MobileCode
{
    private const MINUTES = 10;

    private const TRIES = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    /** The login's mobile number (+92…), when it has a valid one. */
    public function number(LoginAccount $account): ?string
    {
        return PakistanIds::normalizeMobile($account->person?->phone);
    }

    /** Whether a code can be sent: a mobile number on the login and WhatsApp or an SMS gateway set up. */
    public function available(LoginAccount $account): bool
    {
        return (bool) config('ricemill.auth.mobile_code', true) && $this->number($account) !== null
            && (WhatsAppCloud::configured($account->entity_id) || (string) config('ricemill.messaging.sms_url') !== '');
    }

    public function masked(LoginAccount $account): string
    {
        $n = (string) $this->number($account);

        return $n === '' ? '' : '0'.substr($n, 3, 3).'-•••'.substr($n, -4);
    }

    /** Sends the code for this sign-in. At most once a minute and five times an hour. */
    public function send(LoginAccount $account, AuthSession|string $session): void
    {
        $to = $this->number($account);
        if ($to === null || ! $this->available($account)) {
            throw new BusinessRuleViolation('A code cannot be sent to your mobile (no number on the login, or no WhatsApp/SMS set up).');
        }
        $key = 'mobile-code-send:'.$account->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new BusinessRuleViolation(sprintf('Too many codes. Try again in %d minutes.', (int) ceil(RateLimiter::availableIn($key) / 60)));
        }
        if (Cache::has($this->key($session).':recent')) {
            throw new BusinessRuleViolation('A code was sent less than a minute ago. Wait a moment, then send it again.');
        }
        RateLimiter::hit($key, 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $text = __(':code is your :product sign-in code. Never tell it to anybody.', ['code' => $code, 'product' => (string) config('ricemill.product.name')]);
        $sent = WhatsAppCloud::configured($account->entity_id)
            ? (new WhatsAppCloud($account->entity_id))->send($to, MessageText::forTemplate($text))['ok']
            : $this->sms($to, $text);
        if (! $sent) {
            throw new BusinessRuleViolation('The code could not be sent to your mobile. Try again, or use another way.');
        }
        Cache::put($this->key($session), ['hash' => $this->hash($account, $code), 'tries' => 0], now()->addMinutes(self::MINUTES));
        Cache::put($this->key($session).':recent', true, now()->addMinute());
        $this->audit->record('auth.mobile_code.sent', $account, meta: ['session_id' => $this->id($session), 'to' => $this->masked($account)]);
    }

    /** @throws BusinessRuleViolation when it is wrong, too old or tried too often */
    public function check(LoginAccount $account, AuthSession|string $session, string $code): void
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $stored = Cache::get($this->key($session));
        if (! is_array($stored)) {
            throw new BusinessRuleViolation('This code is too old. Send a new one.');
        }
        if (strlen($code) !== 6 || ! hash_equals((string) $stored['hash'], $this->hash($account, $code))) {
            $tries = (int) $stored['tries'] + 1;
            if ($tries >= self::TRIES) {
                Cache::forget($this->key($session));
                $this->audit->record('auth.mobile_code.wrong', $account, meta: ['attempt' => $tries]);

                throw new BusinessRuleViolation('Wrong code too many times. Send a new one.');
            }
            Cache::put($this->key($session), ['hash' => $stored['hash'], 'tries' => $tries], now()->addMinutes(self::MINUTES));
            $this->audit->record('auth.mobile_code.wrong', $account, meta: ['attempt' => $tries]);

            throw new BusinessRuleViolation(sprintf('Wrong code. %d tries left.', self::TRIES - $tries));
        }
        Cache::forget($this->key($session));
        $this->audit->record('auth.mobile_code.proven', $account, meta: ['session_id' => $this->id($session)]);
    }

    private function sms(string $to, string $text): bool
    {
        try {
            return Http::timeout(10)->withToken((string) config('ricemill.messaging.sms_token'))->acceptJson()
                ->post((string) config('ricemill.messaging.sms_url'), ['to' => $to, 'text' => MessageText::plain($text), 'sender' => (string) config('ricemill.messaging.sender')])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** The check the code is for: a held sign-in (its session), or a password-reset browser (a hash of its token). */
    private function id(AuthSession|string $session): string
    {
        return $session instanceof AuthSession ? $session->id : hash('sha256', $session);
    }

    private function key(AuthSession|string $session): string
    {
        return 'mobile-code:'.$this->id($session);
    }

    private function hash(LoginAccount $account, string $code): string
    {
        return hash_hmac('sha256', $account->id.'|mobile|'.$code, (string) config('app.key'));
    }
}
