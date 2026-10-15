<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\IpAllowlistEntry;
use App\Models\LoginAccount;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An account with allowlist entries may only be used from those networks.
 * Owner accounts additionally require an allowlist when configured.
 */
final class IpAllowlistPolicy
{
    public function allows(LoginAccount $account, ?string $ip): bool
    {
        $cidrs = IpAllowlistEntry::query()
            ->withoutGlobalScopes()
            ->where('login_account_id', $account->id)
            ->pluck('cidr')
            ->all();

        if ($cidrs === []) {
            return ! ($account->isOwner() && config('ricemill.security.super_admin_require_ip_allowlist'));
        }

        return $ip !== null && IpUtils::checkIp($ip, $cidrs);
    }
}
