<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Mail\FirstSignInVerificationMail;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The codes sent by e-mail for a check on one sign-in (a new person's first sign-in, a sign-in from a new network or
 * device, a password reset): a 6-digit code, typed first on the screen that asked for it, and then a 2-digit number,
 * picked from four numbers that screen shows (the screen moves on by itself). A wrong number cancels both: a new
 * e-mail is needed. So a forwarded or stolen e-mail is of no use anywhere else. Works for a few minutes and a few tries;
 * at most one e-mail a minute and five an hour. Only their keyed hashes are kept.
 */
final class EmailCode
{
    public const TRIES = 5;

    public const FIRST_SIGN_IN = 'first_sign_in';

    public const NEW_NETWORK = 'new_network';

    /** A sign-in from a device not approved yet (NewNetwork::holdDevice). */
    public const NEW_DEVICE = 'new_device';

    public const PASSWORD_RESET = 'password_reset';

    /** A signed-in person confirming the e-mail the codes go to. */
    public const CONFIRM_EMAIL = 'confirm_email';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Sends a code to the login's own e-mail — the only address any code goes to. A login without one yet (a new
     * person at the first sign-in, or one made before e-mails were kept) gives it here once (`$firstEmail`): it is
     * stored then, and from then on only an administrator changes it (Logins).
     *
     * @param  array<string, mixed>  $signInInfo  where the sign-in came from, shown in the e-mail
     */
    public function send(LoginAccount $account, ?string $firstEmail, ?string $sessionId, string $purpose, array $signInInfo = []): void
    {
        $stored = $account->getAttributes()['email'] ?? null;
        $email = filled($stored) ? (string) $stored : mb_strtolower(trim((string) $firstEmail));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            throw new BusinessRuleViolation('Enter a valid e-mail address.');
        }
        $alsoWrite = filled($stored) ? [] : ['email' => $email, 'email_verified_at' => null];
        $key = 'first-sign-in-mail:'.$account->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new BusinessRuleViolation(sprintf('Too many e-mails. Try again in %d minutes.', (int) ceil(RateLimiter::availableIn($key) / 60)));
        }
        if ($account->email_verify_sent_at !== null && $account->email_verify_sent_at->gt(now()->subMinute())) {
            throw new BusinessRuleViolation('An e-mail was sent less than a minute ago. Wait a moment, then send it again.');
        }
        RateLimiter::hit($key, 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // The 2-digit number, and three other numbers shown with it on the screen (the order changes every time).
        $match = random_int(10, 99);
        $choices = [$match];
        while (count($choices) < 4) {
            $n = random_int(10, 99);
            if (! in_array($n, $choices, true)) {
                $choices[] = $n;
            }
        }
        shuffle($choices);
        $this->write($account, $alsoWrite + ['email_verify_token_hash' => $this->hash($account, $code), 'email_verify_sent_at' => now(),
            'email_verify_session_id' => $sessionId, 'email_verify_attempts' => 0,
            'email_match_hash' => $this->hash($account, 'match|'.$match), 'email_match_choices' => json_encode($choices), 'email_code_ok_at' => null]);
        Mail::to($email)->send(new FirstSignInVerificationMail($account->fresh() ?? $account, $code, $this->minutes(), $purpose, $signInInfo, $purpose === self::CONFIRM_EMAIL ? null : $match));
        $this->audit->record('auth.email_code.sent', $account, meta: ['purpose' => $purpose, 'email' => FirstSignIn::masked($email)]);
    }

    /**
     * The 6-digit code. With `$numberNext` (every sign-in check) it is only the first of the two: the 2-digit number is
     * picked next (pick()); without it (confirming a new security e-mail) the address is confirmed now.
     *
     * @throws BusinessRuleViolation when it is wrong, too old, for another sign-in or tried too often
     */
    public function check(LoginAccount $account, string $code, ?string $sessionId, bool $numberNext = false): void
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $hash = $account->email_verify_token_hash;
        $sentAt = $account->email_verify_sent_at;
        if ($hash === null || $sentAt === null || $sentAt->lt(now()->subMinutes($this->minutes()))) {
            throw new BusinessRuleViolation('This code is too old. Send a new e-mail.');
        }
        $forSession = $account->getAttributes()['email_verify_session_id'] ?? null;
        if ($forSession !== null && $forSession !== $sessionId) {
            throw new BusinessRuleViolation('This code was sent for another sign-in. Send a new e-mail from this screen.');
        }
        if (strlen($code) !== 6 || ! hash_equals($hash, $this->hash($account, $code))) {
            $tries = (int) ($account->getAttributes()['email_verify_attempts'] ?? 0) + 1;
            $left = self::TRIES - $tries;
            $this->write($account, $left > 0 ? ['email_verify_attempts' => $tries] : ['email_verify_attempts' => $tries, 'email_verify_token_hash' => null]);
            $this->audit->record('auth.email_code.wrong', $account, meta: ['attempt' => $tries]);

            throw new BusinessRuleViolation($left > 0 ? sprintf('Wrong code. %d tries left.', $left) : 'Wrong code too many times. Send a new e-mail.');
        }
        if ($numberNext && filled($account->getAttributes()['email_match_hash'] ?? null)) {
            $this->write($account, ['email_verify_token_hash' => null, 'email_verify_attempts' => 0, 'email_code_ok_at' => now()]);
            $this->audit->record('auth.email_code.code_ok', $account, meta: ['number_next' => true]);

            return;
        }
        // The code reached the address: it is confirmed.
        $this->write($account, ['email_verify_token_hash' => null, 'email_verify_attempts' => 0, 'email_verified_at' => $account->email_verified_at ?? now(),
            'email_match_hash' => null, 'email_match_choices' => null, 'email_code_ok_at' => null]);
    }

    /** Whether the 6-digit code of this sign-in was right and its 2-digit number is to be picked now. */
    public function numberPending(LoginAccount $account, ?string $sessionId): bool
    {
        $okAt = $account->email_code_ok_at;
        $forSession = $account->getAttributes()['email_verify_session_id'] ?? null;

        return $okAt !== null && $okAt->gt(now()->subMinutes($this->minutes())) && filled($account->getAttributes()['email_match_hash'] ?? null)
            && ($forSession === null || $forSession === $sessionId);
    }

    /** @return list<int> the four numbers shown on the screen (the e-mail's among them) */
    public function choices(LoginAccount $account): array
    {
        $raw = $account->getAttributes()['email_match_choices'] ?? null;
        $list = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($list) ? array_values(array_map('intval', $list)) : [];
    }

    /**
     * The number picked on the screen. Right: the e-mail is confirmed and the check is passed. Wrong: both codes are
     * cancelled (a new e-mail is needed) — guessing gets no second try.
     *
     * @throws BusinessRuleViolation
     */
    public function pick(LoginAccount $account, int $number, ?string $sessionId): void
    {
        if (! $this->numberPending($account, $sessionId)) {
            throw new BusinessRuleViolation('Type the 6-digit code from the e-mail first (or send a new e-mail: it is too old).');
        }
        $right = hash_equals((string) $account->getAttributes()['email_match_hash'], $this->hash($account, 'match|'.$number))
            && in_array($number, $this->choices($account), true);
        if (! $right) {
            $this->write($account, ['email_verify_token_hash' => null, 'email_match_hash' => null, 'email_match_choices' => null, 'email_code_ok_at' => null]);
            $this->audit->record('auth.email_code.wrong_number', $account, meta: ['picked' => $number]);

            throw new BusinessRuleViolation('Wrong number: the codes of this e-mail are cancelled. Send a new e-mail.');
        }
        $this->write($account, ['email_match_hash' => null, 'email_match_choices' => null, 'email_code_ok_at' => null,
            'email_verified_at' => $account->email_verified_at ?? now()]);
        $this->audit->record('auth.email_code.number_ok', $account);
    }

    public function minutes(): int
    {
        return max(1, (int) config('ricemill.auth.email_verify_minutes', 15));
    }

    /** Keyed with the app's key: a copied database alone does not give the code away. */
    private function hash(LoginAccount $account, string $code): string
    {
        return hash_hmac('sha256', $account->id.'|'.$code, (string) config('app.key'));
    }

    /**
     * Only these columns (never the whole account), so it cannot clash with a change made elsewhere.
     *
     * @param  array<string, mixed>  $values
     */
    private function write(LoginAccount $account, array $values): void
    {
        $this->tenant->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($account->getKey())->toBase()->update($values));
        $account->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }
}
