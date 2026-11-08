<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Security\InputGuard;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Every value sent to the system, from any page, the phone app or a device, before anything reads it:
 *
 *  - invisible characters with no place in text are removed (InputGuard::clean) from everything but secrets;
 *  - HTML / script code is taken out (InputGuard::strip) of everything that is not a form field — searches, filters,
 *    the live boxes, the phone app's sync. Form fields keep what was typed so the form can say what is wrong
 *    (the SafeText rule on every text box) instead of changing it silently.
 *
 * Passwords, keys, tokens and signatures are left exactly as sent. Livewire's snapshot (signed) is never touched.
 * The raw body (what webhook signatures are checked against) is not changed.
 */
final class SanitizeInput extends TransformsRequest
{
    /** Names whose values are secrets or proofs and must arrive byte for byte. */
    private const SECRET = '/(?:^|[._\-])(?:password|password_confirmation|current_password|passwd|secret|token|signature|api_key|apikey|private_key|otp|code_challenge|code_verifier|nonce|hmac)$/i';

    /** Livewire paths that are Filament form state: validated by their fields (SafeText), so not stripped here. */
    private const FORM_STATE = '/^components\.\d+\.updates\.(?:data|mountedActionsData|mountedTableActionsData|mountedTableBulkActionData|mountedFormComponentActionsData|mountedInfolistActionsData)\./';

    protected function transform($key, $value)
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        $key = (string) $key;
        if (preg_match('/^components\.\d+\.snapshot$/', $key) === 1 || $key === '_token' || preg_match(self::SECRET, $key) === 1) {
            return $value;
        }
        if (preg_match(self::FORM_STATE, $key) === 1) {
            return InputGuard::clean($value);
        }

        return InputGuard::strip($value);
    }
}
