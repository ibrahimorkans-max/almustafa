<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Security\InputGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Plain text only: no HTML or script code, no script links (see InputGuard). Every form's text boxes carry it. */
final class SafeText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (! is_string($item)) {
                continue;
            }
            if (($why = InputGuard::threat($item)) !== null) {
                $fail('The :attribute '.$why);

                return;
            }
        }
    }
}
