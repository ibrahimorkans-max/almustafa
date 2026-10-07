<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\AccountSwitcher;
use App\Domain\Auth\DeviceInfo;
use App\Domain\Auth\LoginService;
use App\Domain\Auth\SessionService;
use App\Domain\Auth\TokenService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RefreshRequest;
use App\Http\Requests\Api\SwitchAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token authentication for the PWA, the Flutter app and the gate agent.
 * Every request after login sends `Authorization: Bearer <access>` and `X-Device-Id`.
 *
 * Services are method-injected: Laravel caches controller instances on the route, and
 * these services depend on request-scoped state (tenant + actor context).
 */
final class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginService $login, TokenService $tokens, AuditLogger $audit): JsonResponse
    {
        $account = $login->verifyPassword($request->string('username')->toString(), $request->string('password')->toString(), $request->ip());

        $mfaPassed = false;
        switch ($login->mfaStep($account)) {
            case LoginService::MFA_ENROLL:
                $audit->record('auth.login.blocked', $account, meta: ['reason' => 'mfa_enrollment_required']);

                return response()->json([
                    'error' => 'mfa_enrollment_required',
                    'message' => 'Two-factor authentication must be set up in the web admin before using the app.',
                ], 403);
            case LoginService::MFA_CHALLENGE:
                if (! $request->filled('otp')) {
                    return response()->json(['status' => 'mfa_required']);
                }
                $login->verifySecondFactor($account, $request->string('otp')->toString());
                $mfaPassed = true;
                break;
        }

        $session = $login->completeLogin($account, AuthSession::CHANNEL_API, $request->device(), $request->ip(), $request->userAgent(), $mfaPassed);
        $pair = $tokens->issue($session, $session->device, $account);

        return response()->json(['status' => 'ok', ...$pair->toArray(), 'account' => new AccountResource($account)]);
    }

    public function refresh(RefreshRequest $request, TokenService $tokens): JsonResponse
    {
        $pair = $tokens->refresh($request->string('refresh_token')->toString(), $request->deviceUid());

        return response()->json(['status' => 'ok', ...$pair->toArray()]);
    }

    public function logout(Request $request, SessionService $sessions): JsonResponse
    {
        $sessions->revoke($this->currentSession($request), 'logout');

        return response()->json(['status' => 'ok']);
    }

    public function me(Request $request): AccountResource
    {
        return new AccountResource($request->user());
    }

    public function switchAccount(SwitchAccountRequest $request, AccountSwitcher $switcher, TokenService $tokens): JsonResponse
    {
        /** @var LoginAccount $current */
        $current = $request->user();
        $session = $this->currentSession($request);
        $device = $session->device;

        $newSession = $switcher->switch(
            $current,
            $session,
            $request->string('login_account_id')->toString(),
            $request->input('otp'),
            new DeviceInfo($device->device_uid, $device->platform, $device->name),
            $request->ip(),
            $request->userAgent(),
        );

        $target = $newSession->loginAccount()->with(['role', 'person', 'entity'])->firstOrFail();
        $pair = $tokens->issue($newSession, $newSession->device, $target);

        return response()->json(['status' => 'ok', ...$pair->toArray(), 'account' => new AccountResource($target)]);
    }

    private function currentSession(Request $request): AuthSession
    {
        /** @var AuthSession */
        return $request->attributes->get('auth_session');
    }
}
