<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Domain\Gate\GateService;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Support\Values\PakistanIds;

/** The steps of a vehicle visit after weighing: lab test and decision, unloaded, slip, exit. */
final class GateStepTool extends Tool
{
    private const STEPS = ['lab_test', 'accept_lab', 'reject_lab', 'unloaded', 'slip', 'exit'];

    public function name(): string
    {
        return 'gate_step';
    }

    public function description(): string
    {
        return 'Next step of a vehicle inside the mill: lab_test (moisture %, foreign matter %, broken %, grade), accept_lab or reject_lab (with a note), '
            .'unloaded (optionally into a godown code), slip (issue the weighment slip) or exit (vehicle leaves).';
    }

    public function properties(): array
    {
        return [
            'vehicle' => ['type' => 'string', 'description' => 'Number plate or gate entry number.'],
            'step' => ['type' => 'string', 'enum' => self::STEPS],
            'moisture' => ['type' => 'number', 'description' => 'Moisture % (lab_test).'],
            'foreign_matter' => ['type' => 'number', 'description' => 'Foreign matter % (lab_test).'],
            'broken' => ['type' => 'number', 'description' => 'Broken % (lab_test).'],
            'grade' => ['type' => 'string'],
            'godown' => ['type' => 'string', 'description' => 'Godown code for unloaded, e.g. G1.'],
            'note' => ['type' => 'string'],
        ];
    }

    public function required(): array
    {
        return ['vehicle'];
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
        return [
            '/^lab\s+(?<vehicle>[\w\/\-]{4,30})\s+moisture\s+(?<moisture>[\d.]+)(?:\s+(?:fm|foreign)\s+(?<foreign_matter>[\d.]+))?(?:\s+broken\s+(?<broken>[\d.]+))?(?:\s+grade\s+(?<grade>\w+))?$/i',
            '/^(?<step>accept|reject)\s+lab\s+(?<vehicle>[\w\/\-]{4,30})(?:\s*[:\-]\s*(?<note>.+))?$/i',
            '/^(?<step>unloaded|unload|slip|exit)\s+(?<vehicle>[\w\/\-]{4,30})(?:\s+(?:in|into)\s+(?<godown>\w+))?$/i',
        ];
    }

    public function examples(): array
    {
        return ['lab MNA-21-1234 moisture 16.5 fm 2 grade A', 'accept lab MNA-21-1234', 'unloaded MNA-21-1234 into G1', 'slip MNA-21-1234', 'exit MNA-21-1234'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        $step = $this->step($input);

        return match ($step) {
            'lab_test' => 'Record the lab test of '.$input['vehicle'].': moisture '.($input['moisture'] ?? '—').' %'
                .(isset($input['foreign_matter']) ? ', foreign matter '.$input['foreign_matter'].' %' : '').(isset($input['grade']) ? ', grade '.$input['grade'] : '').'.',
            'accept_lab' => 'Accept the lot of '.$input['vehicle'].' after the lab test.',
            'reject_lab' => 'Reject the lot of '.$input['vehicle'].(filled($input['note'] ?? null) ? ' — "'.$input['note'].'"' : '').'.',
            'unloaded' => 'Mark '.$input['vehicle'].' as unloaded'.(filled($input['godown'] ?? null) ? ' into '.$input['godown'] : '').'.',
            'slip' => 'Issue the weighment slip of '.$input['vehicle'].'.',
            default => 'Let '.$input['vehicle'].' exit the mill.',
        };
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $ref = trim((string) $input['vehicle']);
        $entry = GateEntry::query()->whereNotIn('status', ['exited', 'cancelled'])
            ->where(fn ($q) => $q->where('number', $ref)->orWhere('registration_no', PakistanIds::normalizeVehicleNumber($ref)))->latest('arrived_at')->first();
        if ($entry === null) {
            return ToolResult::fail("No vehicle {$ref} inside the mill.");
        }
        $gate = app(GateService::class);
        $bp = fn ($v) => $v === null || $v === '' ? null : (int) round(((float) $v) * 100);
        $done = match ($this->step($input)) {
            'lab_test' => $gate->recordLabTest($entry, array_filter([
                'moisture_bp' => $bp($input['moisture'] ?? null), 'foreign_matter_bp' => $bp($input['foreign_matter'] ?? null),
                'broken_bp' => $bp($input['broken'] ?? null), 'grade' => $input['grade'] ?? null, 'remarks' => $input['note'] ?? null,
            ], fn ($v) => $v !== null)) ? 'Lab test recorded' : '',
            'accept_lab' => $gate->decideLab($entry, true, $input['note'] ?? null) ? 'Lot accepted' : '',
            'reject_lab' => $gate->decideLab($entry, false, $input['note'] ?? 'Rejected after the lab test') ? 'Lot rejected' : '',
            'unloaded' => $gate->markUnloaded($entry, null, true, filled($input['godown'] ?? null) ? Godown::query()->where('code', strtoupper((string) $input['godown']))->first() : null) ? 'Marked unloaded' : '',
            'slip' => $gate->issueSlip($entry) ? 'Slip issued' : '',
            default => $gate->exit($entry) ? 'Exit recorded' : '',
        };
        $entry->refresh();

        return new ToolResult("{$done} for {$entry->registration_no} ({$entry->number}). Status now: ".str_replace('_', ' ', $entry->status).'.',
            [['label' => 'Open gate entry', 'url' => GateEntryResource::getUrl('view', ['record' => $entry])]]);
    }

    /** @param  array<string, mixed>  $input */
    private function step(array $input): string
    {
        $step = strtolower((string) ($input['step'] ?? (isset($input['moisture']) ? 'lab_test' : '')));

        return match ($step) {
            'accept' => 'accept_lab', 'reject' => 'reject_lab', 'unload' => 'unloaded',
            default => in_array($step, self::STEPS, true) ? $step : 'lab_test',
        };
    }
}
