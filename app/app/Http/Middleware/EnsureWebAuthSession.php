<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\IpAllowlistPolicy;
use App\Domain\Auth\SessionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after Filament's Authenticate middleware on every panel request:
 *  - the web session must map to a live auth_sessions row (revocation, absolute + idle expiry);
 *  - IP allowlist is re-checked;
 *  - Super Admin's working entity is applied;
 *  - accounts that must enroll 2FA or change their password are confined to those pages.
 */
final class EnsureWebAuthSession
{
    public const SESSION_KEY = 'auth_session_id';

    public const WORKING_ENTITY_KEY = 'working_entity_id';

    /** Route names reachable while 2FA enrollment / password change is pending. */
    private const ALWAYS_ALLOWED = [
        'filament.admin.auth.logout',
        'filament.admin.pages.two-factor',
        'filament.admin.pages.change-password',
    ];

    public function __construct(
        private readonly SessionService $sessions,
        private readonly AuthContextBinder $binder,
        private readonly IpAllowlistPolicy $ipAllowlist,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = Filament::auth()->user();
        if (! $account instanceof LoginAccount) {
            return $next($request);
        }

        $sessionId = $request->session()->get(self::SESSION_KEY);
        $session = $sessionId === null ? null
            : $this->tenant->runAsSystem(fn () => AuthSession::query()->find($sessionId));
        $ownSession = $session !== null && $session->login_account_id === $account->id;

        // Establish context first, so even the sign-out path below writes inside the right entity.
        $working = $account->isSuperAdmin() ? $request->session()->get(self::WORKING_ENTITY_KEY) : null;
        $this->binder->bind($account, $ownSession ? $session : null, $ownSession ? $session->device_id : null, $working);

        $idle = (int) config('ricemill.auth.web_idle_minutes');
        if (! $ownSession || ! $this->sessions->isUsable($session, $idle)) {
            return $this->endSession($request, $ownSession ? $session : null, 'expired_or_revoked');
        }

        if (! $this->ipAllowlist->allows($account, $request->ip())) {
            $this->sessions->revoke($session, 'ip_not_allowed');

            return $this->endSession($request, null, 'ip_not_allowed');
        }

        $this->sessions->touch($session);

        $route = $request->route()?->getName();
        $livewire = $request->is('livewire/*');
        if (! $livewire && ! in_array($route, self::ALWAYS_ALLOWED, true)) {
            if ($account->mfaRequired() && ! $account->hasMfa()) {
                return redirect()->route('filament.admin.pages.two-factor');
            }
            if ($account->must_change_password) {
                return redirect()->route('filament.admin.pages.change-password');
            }
        }

        return $next($request);
    }

    private function endSession(Request $request, ?AuthSession $session, string $reason): Response
    {
        if ($session !== null && $session->revoked_at === null) {
            $this->sessions->revoke($session, $reason);
        }

        Filament::auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(Filament::getLoginUrl());
    }
}
