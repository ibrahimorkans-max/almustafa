<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\LoginAccount;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language of this request: the one chosen in this session, else the signed-in person's own, else the one
 * remembered on this browser, else the system default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;
        if ($request->hasSession()) {
            $locale = $request->session()->get(Locales::SESSION);
        }
        if (! Locales::isValid($locale)) {
            $user = $request->hasSession() ? Auth::guard('web')->user() : null;
            // Read as stored: a login loaded with only some columns (e.g. a party portal sign-in) has no locale.
            $locale = $user instanceof LoginAccount ? ($user->getAttributes()['locale'] ?? null) : null;
        }
        if (! Locales::isValid($locale)) {
            $locale = $request->cookie((string) config('languages.cookie'));
        }
        if (Locales::isValid($locale)) {
            app()->setLocale((string) $locale);
        }

        return $next($request);
    }
}
