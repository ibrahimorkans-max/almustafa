<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Approvals\ApprovalService;
use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Filament\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;

/** Requests waiting for approval, and whether this person may decide each. */
final class PendingApprovalsTool extends Tool
{
    public function name(): string
    {
        return 'pending_approvals';
    }

    public function description(): string
    {
        return 'Requests waiting for approval (new devices, payments, bank accounts, reversals, rates…), with a short reference to approve or reject them.';
    }

    public function permission(): string
    {
        return 'approval.view';
    }

    public function patterns(): array
    {
        return ['/\b(approvals?|pending|requests?|manzoori)\b/i'];
    }

    public function examples(): array
    {
        return ['pending approvals'];
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $service = app(ApprovalService::class);
        $rows = ApprovalRequest::query()->where('status', ApprovalRequest::STATUS_PENDING)->orderBy('created_at')->limit(30)->get()
            ->map(fn (ApprovalRequest $r) => [substr($r->id, 0, 8), $r->summary, $r->created_at->timezone('Asia/Karachi')->format('d M H:i'),
                $service->canDecide($me, $r) ? 'you may decide' : 'not you'])->all();

        return $rows === [] ? new ToolResult('Nothing is waiting for approval.')
            : new ToolResult(count($rows).' request(s) waiting. Say "approve <ref>" or "reject <ref> because …".',
                [['label' => 'Approvals', 'url' => ApprovalRequestResource::getUrl()]], ['columns' => ['Ref', 'Request', 'Raised', 'Decision'], 'rows' => $rows]);
    }
}
