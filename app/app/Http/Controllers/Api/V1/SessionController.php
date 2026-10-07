<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\SessionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var LoginAccount $account */
        $account = $request->user();
        $all = $request->boolean('all');
        if ($all) {
            $this->authorize('session.view_all');
        }

        $sessions = AuthSession::query()
            ->with('device')
            ->when(! $all, fn ($q) => $q->where('login_account_id', $account->id))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->limit(200)
            ->get();

        return SessionResource::collection($sessions);
    }

    public function destroy(Request $request, string $session, SessionService $sessions): JsonResponse
    {
        /** @var LoginAccount $account */
        $account = $request->user();
        $target = AuthSession::query()->findOrFail($session);

        $this->authorize('revoke', $target);
        $sessions->revoke($target, $target->login_account_id === $account->id ? 'revoked_by_user' : 'revoked_by_admin');

        return response()->json(['status' => 'ok']);
    }
}
