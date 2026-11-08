<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\LoginAccount;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Everyone's picture in the top bar: their initials on their role's colours, drawn here as an SVG image. (Filament's
 * own avatars come from ui-avatars.com, which the app's security policy rightly does not load, so none showed.)
 */
final class RoleAvatar implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = $record instanceof LoginAccount ? (string) ($record->person->full_name ?? $record->username) : (string) ($record->getAttribute('name') ?? '?');
        $look = RoleLook::of($record instanceof LoginAccount ? $record : null);

        return self::svg($name, $look['from'], $look['to']);
    }

    /** A round picture with up to two initials. */
    public static function svg(string $name, string $from, string $to): string
    {
        $words = array_values(array_filter(preg_split('/[\s._-]+/u', trim($name)) ?: []));
        $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));
        $initials = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">'
            .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'.$from.'"/><stop offset="1" stop-color="'.$to.'"/></linearGradient></defs>'
            .'<circle cx="64" cy="64" r="64" fill="url(#g)"/>'
            .'<circle cx="64" cy="64" r="60" fill="none" stroke="#ffffff" stroke-opacity=".35" stroke-width="3"/>'
            .'<text x="64" y="66" text-anchor="middle" dominant-baseline="middle" font-family="Inter, Arial, sans-serif" font-size="52" font-weight="700" fill="#ffffff">'.$initials.'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
