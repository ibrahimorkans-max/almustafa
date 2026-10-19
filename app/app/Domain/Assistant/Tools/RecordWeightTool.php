<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Domain\Gate\LiveCapture;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Scale;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;

/** Records the weight on the weighbridge now for the named vehicle (as the Live weight screen does). */
final class RecordWeightTool extends Tool
{
    public function name(): string
    {
        return 'record_weight';
    }

    public function description(): string
    {
        return 'Record the stable weight on the weighbridge right now as the gross or tare (whichever it needs next) of the vehicle inside the mill with this number plate.';
    }

    public function properties(): array
    {
        return [
            'registration_no' => ['type' => 'string', 'description' => 'Number plate of the vehicle on the platform.'],
            'weighbridge' => ['type' => 'string', 'description' => 'Weighbridge code, e.g. WB1; default the first one.'],
        ];
    }

    public function required(): array
    {
        return ['registration_no'];
    }

    public function permission(): string
    {
        return 'weighment.capture';
    }

    public function writes(): bool
    {
        return true;
    }

    public function patterns(): array
    {
        return ['/\b(?:weigh|weight for|record weight|wazan)\b\s+(?<registration_no>[A-Za-z]{2,4}[\s\-]?\d{0,2}[\s\-]?\d{1,5})(?:\s+on\s+(?<weighbridge>\w+))?/i'];
    }

    public function examples(): array
    {
        return ['weigh MNA-21-1234'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        return 'Record the weighbridge reading now for '.PakistanIds::normalizeVehicleNumber((string) ($input['registration_no'] ?? '')).'.';
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $plate = PakistanIds::normalizeVehicleNumber((string) $input['registration_no']);
        $entry = GateEntry::query()->where('registration_no', $plate)->whereNotIn('status', ['exited', 'cancelled'])->latest('arrived_at')->first();
        if ($entry === null) {
            return ToolResult::fail("Vehicle {$plate} is not inside the mill.");
        }
        $scale = Scale::query()->where('status', 'active')
            ->when(filled($input['weighbridge'] ?? null), fn ($q) => $q->where('code', strtoupper((string) $input['weighbridge'])))
            ->orderBy('code')->first();
        if ($scale === null) {
            return ToolResult::fail('No such weighbridge.');
        }
        $w = app(LiveCapture::class)->record($entry, $scale);

        return new ToolResult(ucfirst($w->kind).' '.number_format((float) $w->weight_kg, 0)." kg recorded for {$plate} on {$scale->code}. It goes to the weight checks.");
    }
}
