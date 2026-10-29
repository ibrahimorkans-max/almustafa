<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Saas\DataVisibility;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switches the customer's data visibility window on for this request (screens and API), after the
 * person and the mill are known. The product owner is never limited.
 */
final class ApplyDataVisibility
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof LoginAccount && ! $user->isPlatformAdmin()) {
            DataVisibility::applyFor(DataVisibility::customerOf(app(TenantContext::class)));
        }
        try {
            return $next($request);
        } finally {
            DataVisibility::stop();
        }
    }
}
