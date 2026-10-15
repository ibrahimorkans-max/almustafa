<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\ActorContext;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Pages\SwitchContext;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An Owner in "All firms" mode reads across firms, but every new record belongs to exactly one
 * firm. Instead of letting a create form open and fail on save, send them to choose the firm first.
 */
final class RequireWorkingEntityForCreate
{
    public const MESSAGE = 'Choose the working entity first (Switch entity). You are viewing all entities, and a new record must belong to one entity.';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = (string) $request->route()?->getName();
        if ($request->isMethod('GET') && str_ends_with($route, '.create')
            && $this->tenant->entityId() === null && ($this->actor->account()?->isOwner() ?? false)) {
            Notification::make()->title(self::MESSAGE)->warning()->persistent()->send();

            return redirect(SwitchContext::getUrl());
        }

        return $next($request);
    }
}
