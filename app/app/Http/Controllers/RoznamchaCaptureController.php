<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\RoznamchaCapture;
use App\Domain\Shared\BusinessRuleViolation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /roznamcha/capture/{token} (signed, 30 minutes): the phone's camera page for a roznamcha page being
 * entered on a computer. It shows no data of the mill; it only takes one photo and sends it.
 */
final class RoznamchaCaptureController
{
    public function show(string $token): Response
    {
        $capture = RoznamchaCapture::find($token);

        return response()->view('roznamcha.capture', ['token' => $token, 'expired' => $capture === null, 'sent' => ($capture['file_id'] ?? null) !== null,
            'action' => request()->fullUrl()])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $token, RoznamchaCapture $capture): Response
    {
        $request->validate(['photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png', 'max:5120']]);
        try {
            $capture->receive($token, (string) $request->file('photo')?->get());
            $error = null;
        } catch (BusinessRuleViolation $e) {
            $error = $e->getMessage();
        }

        return response()->view('roznamcha.capture', ['token' => $token, 'expired' => false, 'sent' => $error === null, 'error' => $error,
            'action' => $request->fullUrl()])->header('Cache-Control', 'no-store');
    }
}
