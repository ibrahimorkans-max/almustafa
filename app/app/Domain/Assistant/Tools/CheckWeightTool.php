<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Domain\Gate\WeighmentChecks;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Weighment;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;

/** Marks the latest weight of a vehicle OK, or queries it. */
final class CheckWeightTool extends Tool
{
    public function name(): string
    {
        return 'check_weight';
    }

    public function description(): string
    {
        return 'Weight check: mark the latest recorded weight (or the gross / tare) of a vehicle as OK, or query it with a note saying what is wrong.';
    }

    public function properties(): array
    {
        return [
            'vehicle' => ['type' => 'string', 'description' => 'Number plate or gate entry number.'],
            'decision' => ['type' => 'string', 'enum' => ['ok', 'query']],
            'kind' => ['type' => 'string', 'enum' => ['gross', 'tare'], 'description' => 'Which weight; default the latest.'],
            'note' => ['type' => 'string', 'description' => 'Required for a query.'],
        ];
    }

    public function required(): array
    {
        return ['vehicle', 'decision'];
    }

    public function permission(): string
    {
        return 'weighment.review';
    }

    public function writes(): bool
    {
        return true;
    }

    public function patterns(): array
    {
        return ['/\b(?<decision>ok|query)\b\s+(?:weight|wazan)\s+(?:of\s+)?(?<vehicle>[\w\/\-]{4,30})(?:\s+(?<kind>gross|tare))?(?:\s*[:\-]\s*(?<note>.+))?$/i'];
    }

    public function examples(): array
    {
        return ['ok weight MNA-21-1234', 'query weight MNA-21-1234 tare: looked too heavy'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        return (strtolower((string) ($input['decision'] ?? 'ok')) === 'query' ? 'Query' : 'Mark OK').' the '.($input['kind'] ?? 'latest').' weight of '.$input['vehicle']
            .(filled($input['note'] ?? null) ? ' — "'.$input['note'].'"' : '').'.';
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $ref = trim((string) $input['vehicle']);
        $entry = GateEntry::query()->whereNull('archived_at')->where(fn ($q) => $q->where('number', $ref)->orWhere('registration_no', PakistanIds::normalizeVehicleNumber($ref)))->latest('arrived_at')->first();
        if ($entry === null) {
            return ToolResult::fail("No gate entry for {$ref}.");
        }
        $w = Weighment::query()->where('gate_entry_id', $entry->id)
            ->when(filled($input['kind'] ?? null), fn ($q) => $q->where('kind', strtolower((string) $input['kind'])))
            ->orderByDesc('captured_at')->first();
        if ($w === null) {
            return ToolResult::fail("{$entry->registration_no} has no weight recorded yet.");
        }
        $check = app(WeighmentChecks::class)->decide($w, strtolower((string) $input['decision']) === 'query' ? 'query' : 'ok', $input['note'] ?? null);

        return new ToolResult(ucfirst($w->kind).' '.number_format((float) $w->weight_kg, 0)." kg of {$entry->registration_no}: ".($check->decision === 'ok' ? 'marked OK.' : 'queried.'));
    }
}
