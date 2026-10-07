<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\ActorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Records client IP and user agent for the audit log. */
final class CaptureClientContext
{
    public function __construct(private readonly ActorContext $actor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->actor->setClient($request->ip(), $request->userAgent());

        return $next($request);
    }
}
