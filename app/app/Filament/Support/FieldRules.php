<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Rules\SafeText;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Rules every form field in the system carries unless it says otherwise (a field's own ->maxLength() etc. replaces
 * the default; its own rules are added to these):
 *
 *  - text boxes: plain text only (SafeText: no HTML or script code), at most 255 characters (a web address 2048);
 *    passwords are exempt from SafeText, since any character may be in a password;
 *  - long text boxes: plain text only, at most 10,000 characters;
 *  - tags: each tag plain text, at most 100 characters;
 *  - by the field's name: an e-mail must be an e-mail address, a phone number only digits, spaces, + - ( ),
 *    a CNIC 13 digits (dashes optional).
 */
final class FieldRules
{
    public const PHONE = '/^\+?[\p{Nd}][\p{Nd}\s\-()]{5,19}$/u';

    public const CNIC = '/^\p{Nd}{5}-?\p{Nd}{7}-?\p{Nd}$/u';

    public static function register(): void
    {
        TextInput::configureUsing(function (TextInput $input): void {
            $input
                // Not on number boxes: Filament makes a length there "max_digits", which refuses 12.5 and -3.
                ->maxLength(fn (TextInput $c): ?int => $c->isNumeric() || $c->getType() === 'number' ? null : ($c->getType() === 'url' ? 2048 : 255))
                ->rule(new SafeText, fn (TextInput $c): bool => ! $c->isPassword())
                ->rule('email', fn (TextInput $c): bool => ! $c->isPassword() && self::is($c->getName(), 'email'))
                ->rule('regex:'.self::PHONE, fn (TextInput $c): bool => self::is($c->getName(), 'phone|mobile|cell|whatsapp_number|contact_phone'))
                ->rule('regex:'.self::CNIC, fn (TextInput $c): bool => self::is($c->getName(), 'cnic'));
        });

        Textarea::configureUsing(fn (Textarea $area) => $area->maxLength(10000)->rule(new SafeText));

        TagsInput::configureUsing(fn (TagsInput $tags) => $tags->nestedRecursiveRules(['max:100', new SafeText]));
    }

    /** The field's own name (the last part of its state path) ends in one of these words: "phone", "leader_phone"… */
    private static function is(string $name, string $words): bool
    {
        $last = (string) last(explode('.', $name));

        return preg_match('/(?:^|_)(?:'.$words.')$/i', $last) === 1;
    }
}
