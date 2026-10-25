<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate agents (platform "agent") must sign every authenticated request in addition to the
 * bearer token, so a leaked token alone cannot drive the weighbridge API:
 *
 *   X-Timestamp: unix seconds (±300 s)
 *   X-Nonce:     random, single use
 *   X-Signature: hex HMAC-SHA256(device secret, "{ts}\n{nonce}\n{METHOD}\n{path?query}\n{sha256(body)}")
 */
final class VerifyAgentSignature
{
    public const WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->attributes->get('auth_device');
        if (! $device instanceof Device || $device->platform !== 'agent') {
            return $next($request);
        }

        $refused = self::check($request, $device);

        return $refused === null ? $next($request) : $this->refuse($refused);
    }

    /**
     * The request carries a valid, fresh, never-used signature by this agent's secret. Null when it does;
     * else signature_missing_or_expired | signature_invalid | nonce_reused. Also used at sign-in, where
     * only a signed agent is excused from "Only inside the mill" (its ID alone is not a secret).
     */
    public static function check(Request $request, Device $device): ?string
    {
        $timestamp = (string) $request->header('X-Timestamp', '');
        $nonce = (string) $request->header('X-Nonce', '');
        $signature = strtolower((string) $request->header('X-Signature', ''));
        $secret = (string) $device->signing_secret;

        if ($secret === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WINDOW_SECONDS
            || preg_match('/^[A-Za-z0-9-]{16,64}$/', $nonce) !== 1) {
            return 'signature_missing_or_expired';
        }

        $expected = hash_hmac('sha256', self::canonical($timestamp, $nonce, $request->method(), $request->getRequestUri(), $request->getContent()), $secret);
        if (! hash_equals($expected, $signature)) {
            return 'signature_invalid';
        }

        // Single use within the window (replay protection).
        if (! Cache::add('agent-nonce:'.$device->id.':'.$nonce, 1, self::WINDOW_SECONDS * 2)) {
            return 'nonce_reused';
        }

        return null;
    }

    public static function canonical(string $timestamp, string $nonce, string $method, string $pathWithQuery, string $body): string
    {
        return implode("\n", [$timestamp, $nonce, strtoupper($method), $pathWithQuery, hash('sha256', $body)]);
    }

    private function refuse(string $code): Response
    {
        return response()->json(['error' => $code, 'message' => 'Agent request signature check failed.'], 401);
    }
}
