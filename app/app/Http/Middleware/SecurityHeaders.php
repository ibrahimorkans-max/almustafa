<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers. API responses get a locked-down CSP; the Filament
 * panel needs inline scripts/styles (Livewire/Alpine), so it gets a CSP that
 * still blocks framing, plugins and foreign base URIs/forms.
 */
final class SecurityHeaders
{
    /**
     * What a page may load and talk to: this site only, plus Google's Firebase for party notifications
     * (its script from gstatic, its calls to *.googleapis.com). Scripts and styles may be inline and Alpine
     * may evaluate expressions (Filament and Livewire need both), but nothing is loaded from any other
     * site, nothing is sent to any other site, and the page cannot be framed, embedded or re-based. A
     * second line of defence should a script ever slip in.
     */
    public const WEB_POLICY = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.gstatic.com; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; "
        ."font-src 'self' data:; "
        ."connect-src 'self' https://*.googleapis.com https://www.gstatic.com; "
        ."worker-src 'self' blob:; manifest-src 'self'; media-src 'self' blob:; frame-src 'none'; "
        ."frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        // Never tell a visitor which PHP version runs here (also set expose_php = Off in php.ini).
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->is('api/*')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
            $headers->set('Cache-Control', 'no-store');
        } else {
            $headers->set('Content-Security-Policy', self::WEB_POLICY);
        }

        if ($request->isSecure() && config('ricemill.security.hsts')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
