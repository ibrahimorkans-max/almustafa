<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/** Switches the language: for this session, the signed-in person (every device) and this browser's sign-in page. */
class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(Locales::isValid($locale), 404);

        $request->session()->put(Locales::SESSION, $locale);
        $user = Auth::guard('web')->user();
        if ($user instanceof LoginAccount && ($user->getAttributes()['locale'] ?? null) !== $locale) {
            // Only this column: never an edit conflict with a change made to the account elsewhere.
            // This page is outside the panel (no mill chosen yet): only the signed-in person's own row is written.
            app(TenantContext::class)->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($user->getKey())
                ->toBase()->update(['locale' => $locale]));
            $user->setRawAttributes(['locale' => $locale] + $user->getAttributes(), true);
        }
        Cookie::queue(Cookie::forever((string) config('languages.cookie'), $locale, httpOnly: true));

        // Back to the page the person was on, never to another site.
        $back = url()->previous();

        return redirect()->to(str_starts_with($back, url('/')) ? $back : url('/admin'));
    }
}
