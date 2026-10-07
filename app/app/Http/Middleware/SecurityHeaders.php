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
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->is('api/*')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
            $headers->set('Cache-Control', 'no-store');
        } else {
            $headers->set('Content-Security-Policy', "frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
        }

        if ($request->isSecure() && config('ricemill.security.hsts')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
