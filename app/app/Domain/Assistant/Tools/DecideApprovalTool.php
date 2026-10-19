<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Approvals\ApprovalService;
use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;

/** Approves or rejects a waiting request by its short reference (from pending_approvals). */
final class DecideApprovalTool extends Tool
{
    public function name(): string
    {
        return 'decide_approval';
    }

    public function description(): string
    {
        return 'Approve or reject a waiting request by its reference (the first 8 characters shown by pending_approvals). Rejecting needs a reason.';
    }

    public function properties(): array
    {
        return [
            'ref' => ['type' => 'string', 'description' => 'Request reference, e.g. 01a2b3c4.'],
            'decision' => ['type' => 'string', 'enum' => ['approve', 'reject']],
            'note' => ['type' => 'string', 'description' => 'Note; required to reject.'],
        ];
    }

    public function required(): array
    {
        return ['ref', 'decision'];
    }

    public function permission(): string
    {
        return 'approval.decide';
    }

    public function writes(): bool
    {
        return true;
    }

    public function patterns(): array
    {
        return ['/^(?<decision>approve|reject)\s+(?<ref>[0-9a-f]{6,36})(?:\s+(?:because|:|-)\s*(?<note>.+))?$/i'];
    }

    public function examples(): array
    {
        return ['approve 01a2b3c4', 'reject 01a2b3c4 because not needed'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        $r = $this->find((string) ($input['ref'] ?? ''));

        return ucfirst(strtolower((string) $input['decision'])).': '.($r === null ? 'request '.$input['ref'] : $r->summary).(filled($input['note'] ?? null) ? ' — "'.$input['note'].'"' : '').'.';
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $request = $this->find((string) $input['ref']);
        if ($request === null) {
            return ToolResult::fail("No waiting request starts with {$input['ref']}.");
        }
        $service = app(ApprovalService::class);
        if (strtolower((string) $input['decision']) === 'reject') {
            if (blank($input['note'] ?? null)) {
                return ToolResult::fail('Say why you reject it.');
            }
            $service->reject($request, $me, (string) $input['note']);

            return new ToolResult("Rejected: {$request->summary}.");
        }
        $done = $service->approve($request, $me, $input['note'] ?? null);

        return new ToolResult($done->status === ApprovalRequest::STATUS_FAILED ? "Approved, but it could not be applied: {$done->error}" : "Approved and applied: {$request->summary}.");
    }

    private function find(string $ref): ?ApprovalRequest
    {
        $ref = strtolower(trim($ref));
        if (strlen($ref) < 6) {
            return null;
        }
        $matches = ApprovalRequest::query()->where('status', ApprovalRequest::STATUS_PENDING)->get()->filter(fn (ApprovalRequest $r) => str_starts_with($r->id, $ref));

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
