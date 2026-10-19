<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Domain\Gate\GateService;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Models\LoginAccount;
use App\Models\Procurement\Sauda;
use App\Support\Values\PakistanIds;

/** Registers a vehicle arriving at the gate (paddy against a sauda, or any visit). */
final class RegisterArrivalTool extends Tool
{
    public function name(): string
    {
        return 'register_vehicle_arrival';
    }

    public function description(): string
    {
        return 'Register a vehicle arriving at the gate: number plate, optionally the purchase sauda number it delivers against and the number of bags.';
    }

    public function properties(): array
    {
        return [
            'registration_no' => ['type' => 'string', 'description' => 'Number plate, e.g. MNA-21-1234.'],
            'sauda' => ['type' => 'string', 'description' => 'Sauda number (or its last digits), if it delivers paddy against one.'],
            'bags' => ['type' => 'integer', 'description' => 'Number of bags, if known.'],
        ];
    }

    public function required(): array
    {
        return ['registration_no'];
    }

    public function permission(): string
    {
        return 'gate.entry';
    }

    public function writes(): bool
    {
        return true;
    }

    public function patterns(): array
    {
        return ['/\b(?:arrive|arrived|arrival|register|aayi|aaya)\b\s+(?<registration_no>[A-Za-z]{2,4}[\s\-]?\d{0,2}[\s\-]?\d{1,5})(?:.*\bsauda\s+(?<sauda>[\w\/\-]+))?(?:.*?\b(?<bags>\d{1,5})\s*bags?)?/i'];
    }

    public function examples(): array
    {
        return ['arrived MNA-21-1234 sauda 0001 400 bags'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        return 'Register the arrival of '.PakistanIds::normalizeVehicleNumber((string) ($input['registration_no'] ?? ''))
            .(filled($input['sauda'] ?? null) ? ' against sauda '.$input['sauda'] : '')
            .(filled($input['bags'] ?? null) ? ' with '.(int) $input['bags'].' bags' : '').'.';
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $data = ['registration_no' => (string) $input['registration_no']];
        if (filled($input['sauda'] ?? null)) {
            $ref = trim((string) $input['sauda']);
            $sauda = Sauda::query()->whereNull('archived_at')->where('direction', 'purchase')->where(fn ($q) => $q->where('number', $ref)->orWhere('number', 'like', '%'.$ref))
                ->orderByDesc('created_at')->first();
            if ($sauda === null) {
                return ToolResult::fail("No purchase sauda {$ref} found.");
            }
            $data['sauda_id'] = $sauda->id;
        }
        if (filled($input['bags'] ?? null)) {
            $data['bag_count'] = (int) $input['bags'];
        }
        $entry = app(GateService::class)->arrive($data);

        return new ToolResult("Arrival registered: {$entry->registration_no}, gate entry {$entry->number}".($entry->flags ? ' (flags: '.implode(', ', $entry->flags).')' : '').'.',
            [['label' => 'Open gate entry', 'url' => GateEntryResource::getUrl('view', ['record' => $entry])]]);
    }
}
