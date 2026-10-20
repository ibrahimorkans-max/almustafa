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
    private const STEPS = ['lab_test', 'accept_lab', 'reject_lab', 'unloaded', 'settle', 'approve_settlement', 'slip', 'exit'];

    public function name(): string
    {
        return 'gate_step';
    }

    public function description(): string
    {
        return 'Next step of a vehicle inside the mill, in order: lab_test before any weighing (moisture %, broken %, green grain %, shell %, '
            .'shewad green %, red rice %, dandly %, grade; a result above its grace limit rejects the lot), accept_lab or reject_lab (with a note), '
            .'unloaded (optionally into a godown code), settle after the empty weight (waste Caat kg and Caat per maund in kg per maund), '
            .'approve_settlement (by someone other than the proposer), slip (issue the weighment slip) or exit (vehicle leaves).';
    }

    public function properties(): array
    {
        return [
            'vehicle' => ['type' => 'string', 'description' => 'Number plate or gate entry number.'],
            'step' => ['type' => 'string', 'enum' => self::STEPS],
            'moisture' => ['type' => 'number', 'description' => 'Moisture % (lab_test).'],
            'foreign_matter' => ['type' => 'number', 'description' => 'Foreign matter % (lab_test).'],
            'broken' => ['type' => 'number', 'description' => 'Broken % (lab_test).'],
            'green_grain' => ['type' => 'number', 'description' => 'Green grain % (lab_test).'],
            'shell' => ['type' => 'number', 'description' => 'Shell % (lab_test).'],
            'shewad_green' => ['type' => 'number', 'description' => 'Shewad green % (lab_test).'],
            'red_rice' => ['type' => 'number', 'description' => 'Red rice % (lab_test).'],
            'dandly' => ['type' => 'number', 'description' => 'Dandly % (lab_test).'],
            'waste_caat' => ['type' => 'number', 'description' => 'Waste Caat in kg (settle).'],
            'per_maund_caat' => ['type' => 'number', 'description' => 'Caat per Maund in kg per maund (settle).'],
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
            '/^(?<step>settle)\s+(?<vehicle>[\w\/\-]{4,30})\s+waste\s+(?<waste_caat>[\d.]+)(?:\s+per\s*maund\s+(?<per_maund_caat>[\d.]+))?$/i',
            '/^approve\s+settlement\s+(?<vehicle>[\w\/\-]{4,30})$/i',
        ];
    }

    public function examples(): array
    {
        return ['lab MNA-21-1234 moisture 13.5 broken 4 grade A', 'accept lab MNA-21-1234', 'unloaded MNA-21-1234 into G1',
            'settle MNA-21-1234 waste 240 per maund 0.5', 'approve settlement MNA-21-1234', 'slip MNA-21-1234', 'exit MNA-21-1234'];
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
            'settle' => 'Propose the settlement of '.$input['vehicle'].': Waste Caat '.($input['waste_caat'] ?? 0).' kg, Caat per Maund '.($input['per_maund_caat'] ?? 0).' kg per maund.',
            'approve_settlement' => 'Approve the Caat and price settlement of '.$input['vehicle'].'.',
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
                'results' => array_filter(['moisture' => $bp($input['moisture'] ?? null), 'broken' => $bp($input['broken'] ?? null),
                    'green_grain' => $bp($input['green_grain'] ?? null), 'shell' => $bp($input['shell'] ?? null),
                    'shewad_green' => $bp($input['shewad_green'] ?? null), 'red_rice' => $bp($input['red_rice'] ?? null),
                    'dandly' => $bp($input['dandly'] ?? null)], fn ($v) => $v !== null),
                'foreign_matter_bp' => $bp($input['foreign_matter'] ?? null), 'grade' => $input['grade'] ?? null, 'remarks' => $input['note'] ?? null,
            ], fn ($v) => $v !== null && $v !== [])) ? 'Lab test recorded' : '',
            'accept_lab' => $gate->decideLab($entry, true, $input['note'] ?? null) ? 'Lot accepted' : '',
            'reject_lab' => $gate->decideLab($entry, false, $input['note'] ?? 'Rejected after the lab test') ? 'Lot rejected' : '',
            'unloaded' => $gate->markUnloaded($entry, null, true, filled($input['godown'] ?? null) ? Godown::query()->where('code', strtoupper((string) $input['godown']))->first() : null) ? 'Marked unloaded' : '',
            'settle' => $gate->proposeSettlement($entry, (string) ($input['waste_caat'] ?? '0'), (string) ($input['per_maund_caat'] ?? '0'), $input['note'] ?? null) ? 'Settlement proposed' : '',
            'approve_settlement' => $gate->approveSettlement($entry, $input['note'] ?? null) ? 'Settlement approved' : '',
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
        // Without a step: lab figures mean a lab test, a waste Caat means a settlement, nothing else means approving it.
        $step = strtolower((string) ($input['step'] ?? match (true) {
            isset($input['moisture']) => 'lab_test',
            isset($input['waste_caat']) => 'settle',
            default => 'approve_settlement',
        }));

        return match ($step) {
            'accept' => 'accept_lab', 'reject' => 'reject_lab', 'unload' => 'unloaded',
            default => in_array($step, self::STEPS, true) ? $step : 'lab_test',
        };
    }
}
