<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditVerifier;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AuditIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\LoginAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AuditController extends Controller
{
    public function index(AuditIndexRequest $request): AnonymousResourceCollection
    {
        $logs = AuditLog::query()
            ->when($request->input('action'), fn ($q, $v) => $q->where('action', $v))
            ->when($request->input('subject_type'), fn ($q, $v) => $q->where('subject_type', $v))
            ->when($request->input('subject_id'), fn ($q, $v) => $q->where('subject_id', $v))
            ->when($request->input('actor_login_account_id'), fn ($q, $v) => $q->where('actor_login_account_id', $v))
            ->when($request->input('from'), fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->where('occurred_at', '<=', $v))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->input('per_page', 50));

        return AuditLogResource::collection($logs);
    }

    public function verify(Request $request, AuditVerifier $verifier, TenantContext $tenant): JsonResponse
    {
        $this->authorize('audit.verify');

        /** @var LoginAccount $account */
        $account = $request->user();
        // Owner may verify every chain (`?all=1`), everyone else their entity's chain.
        $result = $account->isOwner() && $request->boolean('all')
            ? $verifier->verify()
            : $verifier->verifyEntity($tenant->requireEntityId());

        return response()->json($result->toArray(), $result->ok() ? 200 : 409);
    }
}
