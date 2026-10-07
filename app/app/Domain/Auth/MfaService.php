<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Models\LoginAccount;
use App\Models\MfaRecoveryCode;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) with replay protection and one-time recovery codes.
 */
final class MfaService
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly AuditLogger $audit,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(LoginAccount $account, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('ricemill.auth.mfa_issuer'), $account->username, $secret);
    }

    public function qrSvg(string $otpauthUrl): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return $writer->writeString($otpauthUrl);
    }

    /**
     * Confirms enrollment with a first valid code and returns fresh recovery codes (shown once).
     *
     * @return list<string>|null null when the code is wrong
     */
    public function confirmEnrollment(LoginAccount $account, string $secret, string $code): ?array
    {
        $timestep = $this->google2fa->verifyKeyNewer($secret, $this->clean($code), 0, 1);
        if (! is_int($timestep)) {
            return null;
        }

        return DB::transaction(function () use ($account, $secret, $timestep): array {
            $account->mfa_secret = $secret;
            $account->mfa_confirmed_at = now();
            $account->mfa_last_timestep = (int) $timestep;
            $account->save();

            $codes = $this->regenerateRecoveryCodes($account);
            $this->audit->record('auth.mfa.enrolled', $account);

            return $codes;
        });
    }

    /** Verifies a TOTP code, rejecting reuse of the same or an older time step. */
    public function verifyCode(LoginAccount $account, string $code): bool
    {
        if (! $account->hasMfa()) {
            return false;
        }

        // Pass 0 (not null) when no code was used yet: with null, Google2FA returns `true`
        // instead of the matched time step, which would disable replay protection.
        $timestep = $this->google2fa->verifyKeyNewer(
            (string) $account->mfa_secret,
            $this->clean($code),
            $account->mfa_last_timestep ?? 0,
            1,
        );

        if (! is_int($timestep)) {
            return false;
        }

        $account->forceFill(['mfa_last_timestep' => (int) $timestep])->save();

        return true;
    }

    public function verifyRecoveryCode(LoginAccount $account, string $code): bool
    {
        $hash = $this->hashRecoveryCode($code);

        return DB::transaction(function () use ($account, $hash): bool {
            $row = MfaRecoveryCode::query()
                ->where('login_account_id', $account->id)
                ->where('code_hash', $hash)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false;
            }

            $row->forceFill(['used_at' => now()])->save();
            $this->audit->record('auth.mfa.recovery_code_used', $account);

            return true;
        });
    }

    /** Accepts either a 6-digit TOTP code or a recovery code. */
    public function verifyAny(LoginAccount $account, string $code): bool
    {
        $clean = $this->clean($code);

        return preg_match('/^\d{6}$/', $clean) === 1
            ? $this->verifyCode($account, $clean)
            : $this->verifyRecoveryCode($account, $code);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(LoginAccount $account): array
    {
        MfaRecoveryCode::query()->where('login_account_id', $account->id)->whereNull('used_at')
            ->update(['used_at' => now()]);

        $codes = [];
        for ($i = 0; $i < (int) config('ricemill.auth.recovery_codes'); $i++) {
            $code = Str::upper(Str::random(5).'-'.Str::random(5));
            $codes[] = $code;
            MfaRecoveryCode::query()->create([
                'entity_id' => $account->entity_id,
                'login_account_id' => $account->id,
                'code_hash' => $this->hashRecoveryCode($code),
            ]);
        }

        return $codes;
    }

    /** Admin reset (e.g. lost phone). The user must enroll again at next login. */
    public function reset(LoginAccount $account, string $reason): void
    {
        DB::transaction(function () use ($account, $reason): void {
            $account->mfa_secret = null;
            $account->mfa_confirmed_at = null;
            $account->mfa_last_timestep = null;
            $account->save();
            MfaRecoveryCode::query()->where('login_account_id', $account->id)->whereNull('used_at')->update(['used_at' => now()]);
            $this->audit->record('auth.mfa.reset', $account, meta: ['reason' => $reason]);
        });
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', Str::upper(trim($code)), (string) config('app.key'));
    }

    private function clean(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }
}
