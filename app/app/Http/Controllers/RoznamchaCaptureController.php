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
        // Said on the page itself (a phone shows no form errors after a redirect).
        $photo = $request->file('photo');
        $error = match (true) {
            $photo === null || ! $photo->isValid() => 'The photo did not arrive. Check the internet on the phone and send it again.',
            $photo->getSize() > 15 * 1024 * 1024 => 'The photo is too large. Take it again (it is made smaller on the phone before it is sent).',
            ! in_array($photo->getMimeType(), ['image/jpeg', 'image/png'], true) => in_array($photo->getMimeType(), ['image/heic', 'image/heif'], true)
                ? 'This iPhone saved the photo as HEIC. Take it again here (the page turns it into JPEG), or set Settings → Camera → Formats → Most Compatible.'
                : 'Send a photo (JPEG or PNG).',
            default => null,
        };
        if ($error === null) {
            try {
                $capture->receive($token, (string) $photo->get());
            } catch (BusinessRuleViolation $e) {
                $error = $e->getMessage();
            }
        }

        return response()->view('roznamcha.capture', ['token' => $token, 'expired' => false, 'sent' => $error === null, 'error' => $error,
            'action' => $request->fullUrl()])->header('Cache-Control', 'no-store');
    }
}
