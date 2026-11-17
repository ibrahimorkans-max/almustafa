<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\FirstSignIn;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\FirstSignInPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The button in a new user's verification e-mail (a signed link that works once). Opened in the browser that
 * signed in, it goes straight back to the first sign-in; opened on another device (a phone), it says so, and the
 * computer's page moves on by itself.
 */
class FirstSignInVerifyController extends Controller
{
    public function __invoke(Request $request, string $account, string $token, FirstSignIn $firstSignIn): Response
    {
        try {
            $verified = $firstSignIn->verify($account, $token);
        } catch (BusinessRuleViolation $e) {
            return response()->view('auth.email-verified', ['ok' => false, 'message' => __($e->getMessage())], 410);
        }
        if (Auth::guard('web')->id() === $verified->id) {
            return redirect()->to(FirstSignInPage::getUrl());
        }

        return response()->view('auth.email-verified', ['ok' => true, 'message' => __('Your e-mail address is confirmed. Go back to the screen where you signed in: it continues by itself.')]);
    }
}
